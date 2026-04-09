<?php
/**
 * CallbackHandler.php — Traitement callback LigdiCash
 *
 * LigdiCash envoie 2 requêtes POST à chaque paiement :
 *   1. Content-Type: application/x-www-form-urlencoded
 *   2. Content-Type: application/json
 *
 * L'idempotence (via invoice_token) évite le double traitement.
 */
declare(strict_types=1);

class CallbackHandler
{
    public static function handle(): never
    {
        // GET → test de disponibilité
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            jsonResponse(['status' => 'ok', 'message' => 'Callback LigdiCash actif']);
        }

        // ── 1. Lecture body ────────────────────────────────────────────────
        $raw = (string)file_get_contents('php://input');
        error_log('[CB] RAW body : ' . substr($raw, 0, 500));

        // ── 2. Parsing : JSON d'abord, puis form-urlencoded, puis $_POST ───
        $data = null;

        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                // Essai form-urlencoded dans le body
                parse_str($raw, $parsed);
                if (!empty($parsed)) {
                    $data = $parsed;
                }
            }
        }

        if (empty($data) && !empty($_POST)) {
            $data = $_POST;
        }

        if (empty($data)) {
            error_log('[CB] Body vide ou non parsable');
            jsonResponse(['error' => 'Corps vide ou invalide'], 400);
        }

        error_log('[CB] Payload parsé : ' . json_encode($data));

        // ── 3. Vérification signature ──────────────────────────────────────
        if (!SignatureVerifier::verify($data)) {
            jsonResponse(['error' => 'Signature invalide'], 401);
        }

        // ── 4. Extraction des champs obligatoires ─────────────────────────
        $invoiceToken  = trim((string)($data['token']          ?? ''));
        $status        = strtolower(trim((string)($data['status'] ?? '')));
        $amount        = (int)($data['amount'] ?? $data['montant'] ?? 0);
        $operatorName  = (string)($data['operator_name']  ?? '');
        $transactionId = (string)($data['transaction_id'] ?? '');
        $responseCode  = (string)($data['response_code']  ?? '');
        $responseText  = (string)($data['response_text']  ?? '');
        $customer      = (string)($data['customer']        ?? '');
        $externalId    = (string)($data['external_id']     ?? '');
        $requestId     = (string)($data['request_id']      ?? '');
        $date          = (string)($data['date']            ?? '');

        // ── 5. Extraction custom_data → user_id + plan + order_id ─────────
        $custom  = self::parseCustomData($data['custom_data'] ?? []);
        $userId  = trim((string)($custom['user_id']   ?? ''));
        $plan    = trim((string)($custom['plan']      ?? 'mensuel'));
        $orderId = trim((string)($custom['order_id']  ?? $invoiceToken));

        // Validation des champs critiques
        if ($invoiceToken === '') {
            error_log('[CB] token manquant');
            jsonResponse(['error' => 'Champ "token" manquant'], 422);
        }
        if ($userId === '') {
            error_log('[CB] user_id manquant dans custom_data');
            jsonResponse(['error' => 'user_id manquant dans custom_data'], 422);
        }

        // ── 6. Idempotence ─────────────────────────────────────────────────
        if (DatabaseClient::transactionExists($invoiceToken)) {
            error_log("[CB] Token déjà traité : $invoiceToken");
            jsonResponse(['message' => 'deja_traite']);
        }

        // ── 7. Durée du plan ───────────────────────────────────────────────
        $durationDays = PLAN_DURATIONS[$plan] ?? PLAN_DURATIONS['mensuel'];
        $now          = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $endDate      = $now->modify("+{$durationDays} days");

        // Extra commun à tous les statuts
        $extra = [
            'responseCode' => $responseCode,
            'responseText' => $responseText,
            'customer'     => $customer,
            'externalId'   => $externalId,
            'requestId'    => $requestId,
            'processedAt'  => $date,
            'durationDays' => $durationDays,
        ];

        // ── 8. Traitement selon statut ─────────────────────────────────────

        if ($status === 'completed') {

            $extra['startDate'] = $now->format('Y-m-d H:i:s');
            $extra['endDate']   = $endDate->format('Y-m-d H:i:s');

            DatabaseClient::recordTransaction(
                $invoiceToken, $userId, $plan, $orderId,
                'completed', $data, $operatorName, $transactionId, $amount, $extra
            );

            DatabaseClient::activateSubscription(
                $userId, $plan, $invoiceToken, $orderId,
                $operatorName ?: null, $now, $endDate
            );

            // Notification FCM (non bloquant)
            FcmClient::notifyUser(
                $userId,
                'Abonnement activé !',
                "Votre plan $plan est actif jusqu'au " . $endDate->format('d/m/Y') . '.',
                ['status' => 'completed', 'plan' => $plan, 'end_date' => $endDate->format('Y-m-d')]
            );

            error_log("[CB] SUCCESS user=$userId plan=$plan montant={$amount}F token=$invoiceToken");
            jsonResponse(['message' => 'abonnement_active', 'end_date' => $endDate->format('Y-m-d')]);

        } elseif ($status === 'pending') {

            DatabaseClient::recordTransaction(
                $invoiceToken, $userId, $plan, $orderId,
                'pending', $data, null, null, $amount, $extra
            );

            FcmClient::notifyUser(
                $userId,
                'Paiement en attente',
                'Votre paiement est en cours de traitement…',
                ['status' => 'pending', 'plan' => $plan]
            );

            error_log("[CB] PENDING user=$userId token=$invoiceToken");
            jsonResponse(['message' => 'paiement_en_attente']);

        } else {
            // nocompleted ou autre
            DatabaseClient::recordTransaction(
                $invoiceToken, $userId, $plan, $orderId,
                'nocompleted', $data, null, null, $amount, $extra
            );

            DatabaseClient::clearPendingSubscription($userId, $invoiceToken);

            FcmClient::notifyUser(
                $userId,
                'Paiement échoué',
                'Votre paiement n\'a pas abouti. Veuillez réessayer.',
                ['status' => 'nocompleted', 'plan' => $plan]
            );

            error_log("[CB] FAILED user=$userId status=$status token=$invoiceToken");
            jsonResponse(['message' => 'paiement_echoue']);
        }
    }

    // ── Parsing custom_data ────────────────────────────────────────────────
    // LigdiCash envoie un tableau d'objets :
    //   [ { "keyof_customdata": "user_id", "valueof_customdata": "123" }, … ]
    // ou parfois directement { "user_id": "123", "plan": "mensuel" }

    private static function parseCustomData(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        // Format liste (officiel LigdiCash)
        if (array_is_list($raw)) {
            $result = [];
            foreach ($raw as $item) {
                if (is_array($item) && isset($item['keyof_customdata'])) {
                    $result[(string)$item['keyof_customdata']] = (string)($item['valueof_customdata'] ?? '');
                }
            }
            return $result;
        }

        // Format objet clé/valeur direct
        return $raw;
    }
}
