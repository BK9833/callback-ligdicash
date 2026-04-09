<?php
/**
 * DatabaseClient.php — Client Firestore REST API
 *
 * Remplace PDO/MySQL. Utilise les mêmes credentials Firebase que FcmClient.
 *
 * Collections Firestore :
 *   payment_transactions  — doc ID = invoice_token
 *   subscriptions         — doc ID = user_id  (1 doc par user, upsert)
 *   fcm_tokens            — doc ID = user_id  (1 doc par user, upsert)
 */
declare(strict_types=1);

class DatabaseClient
{
    // ── Auth (token OAuth2 partagé avec FcmClient) ─────────────────────────

    private static ?string $accessToken = null;
    private static int     $tokenExpiry  = 0;

    private static function getAccessToken(): string
    {
        if (self::$accessToken !== null && time() < self::$tokenExpiry - 60) {
            return self::$accessToken;
        }

        $now    = time();
        $header = self::b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64u(json_encode([
            'iss'   => FIREBASE_CLIENT_EMAIL,
            'sub'   => FIREBASE_CLIENT_EMAIL,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/datastore',
                'https://www.googleapis.com/auth/firebase.messaging',
            ]),
        ]));

        $unsigned = $header . '.' . $claims;
        openssl_sign($unsigned, $sig, FIREBASE_PRIVATE_KEY, OPENSSL_ALGO_SHA256);
        $jwt = $unsigned . '.' . self::b64u($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        if (empty($res['access_token'])) {
            throw new RuntimeException('[DB] OAuth2 échoué : ' . json_encode($res));
        }

        self::$accessToken = $res['access_token'];
        self::$tokenExpiry = $now + (int)($res['expires_in'] ?? 3600);

        return self::$accessToken;
    }

    // ── Helpers REST ───────────────────────────────────────────────────────

    /** URL de base Firestore REST pour ce projet */
    private static function base(): string
    {
        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents',
            FIREBASE_PROJECT_ID
        );
    }

    /** Requête HTTP vers Firestore */
    private static function request(
        string  $method,
        string  $url,
        ?array  $body = null,
        array   $query = []
    ): array {
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $token = self::getAccessToken();
        $ch    = curl_init($url);
        $opts  = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true) ?? [];

        if ($code >= 400 && $code !== 404) {
            $msg = $data['error']['message'] ?? $raw;
            throw new RuntimeException("[DB] Firestore HTTP $code : $msg");
        }

        return ['code' => $code, 'data' => $data];
    }

    /** GET d'un document — null si inexistant */
    private static function getDoc(string $collection, string $docId): ?array
    {
        $url = self::base() . "/$collection/" . rawurlencode($docId);
        $res = self::request('GET', $url);
        return ($res['code'] === 404) ? null : ($res['data'] ?? null);
    }

    /** PATCH (create or update) d'un document Firestore */
    private static function setDoc(string $collection, string $docId, array $fields): void
    {
        $url  = self::base() . "/$collection/" . rawurlencode($docId);
        $body = ['fields' => self::toFirestore($fields)];

        // updateMask pour ne mettre à jour que les champs fournis
        $mask = array_keys($fields);
        self::request('PATCH', $url, $body, array_map(
            fn($f) => ['updateMask.fieldPaths' => $f],
            $mask
        ));
    }

    /**
     * PATCH avec updateMask explicite (liste de champs à écraser).
     * Permet l'upsert partiel sans toucher aux champs absents.
     */
    private static function patchDoc(string $collection, string $docId, array $fields): void
    {
        $url   = self::base() . "/$collection/" . rawurlencode($docId);
        $body  = ['fields' => self::toFirestore($fields)];
        $query = [];
        foreach (array_keys($fields) as $f) {
            $query[] = 'updateMask.fieldPaths=' . urlencode($f);
        }
        $qs = implode('&', $query);

        $token = self::getAccessToken();
        $ch    = curl_init($url . '?' . $qs);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            $err = json_decode($raw, true)['error']['message'] ?? $raw;
            throw new RuntimeException("[DB] patchDoc $collection/$docId HTTP $code : $err");
        }
    }

    // ── Conversions Firestore ↔ PHP ────────────────────────────────────────

    /** Convertit un tableau PHP plat en champs Firestore */
    private static function toFirestore(array $data): array
    {
        $fields = [];
        foreach ($data as $k => $v) {
            if ($v === null) {
                $fields[$k] = ['nullValue' => null];
            } elseif (is_bool($v)) {
                $fields[$k] = ['booleanValue' => $v];
            } elseif (is_int($v)) {
                $fields[$k] = ['integerValue' => (string)$v];
            } elseif (is_float($v)) {
                $fields[$k] = ['doubleValue' => $v];
            } elseif (is_array($v)) {
                $fields[$k] = ['stringValue' => json_encode($v, JSON_UNESCAPED_UNICODE)];
            } else {
                $fields[$k] = ['stringValue' => (string)$v];
            }
        }
        return $fields;
    }

    /** Extrait les valeurs PHP d'un document Firestore */
    private static function fromFirestore(array $doc): array
    {
        $out = [];
        foreach ($doc['fields'] ?? [] as $k => $fv) {
            $out[$k] = match (true) {
                isset($fv['stringValue'])  => $fv['stringValue'],
                isset($fv['integerValue']) => (int)$fv['integerValue'],
                isset($fv['doubleValue'])  => (float)$fv['doubleValue'],
                isset($fv['booleanValue']) => $fv['booleanValue'],
                isset($fv['nullValue'])    => null,
                default                    => null,
            };
        }
        return $out;
    }

    private static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // API publique — même signature que l'ancienne version MySQL
    // ═══════════════════════════════════════════════════════════════════════

    // ── Transactions ───────────────────────────────────────────────────────

    /**
     * Vérifie si un invoice_token a déjà été traité (idempotence).
     */
    public static function transactionExists(string $invoiceToken): bool
    {
        $doc = self::getDoc('payment_transactions', $invoiceToken);
        return $doc !== null;
    }

    /**
     * Insère ou met à jour une transaction.
     */
    public static function recordTransaction(
        string  $invoiceToken,
        string  $userId,
        string  $plan,
        string  $localOrderId,
        string  $status,
        array   $payload,
        ?string $operatorName  = null,
        ?string $transactionId = null,
        int     $amount         = 0,
        array   $extra          = []
    ): void {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $fields = [
            'invoice_token'  => $invoiceToken,
            'user_id'        => $userId,
            'plan'           => $plan,
            'local_order_id' => $localOrderId,
            'status'         => $status,
            'provider'       => 'ligdicash',
            'operator_name'  => $operatorName  ?? '',
            'transaction_id' => $transactionId ?? '',
            'amount'         => $amount,
            'response_code'  => $extra['responseCode'] ?? '',
            'response_text'  => $extra['responseText'] ?? '',
            'customer'       => $extra['customer']      ?? '',
            'external_id'    => $extra['externalId']    ?? '',
            'request_id'     => $extra['requestId']     ?? '',
            'raw_payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'processed_at'   => $extra['processedAt']   ?? '',
            'duration_days'  => $extra['durationDays']  ?? 0,
            'start_date'     => $extra['startDate']     ?? '',
            'end_date'       => $extra['endDate']       ?? '',
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        self::patchDoc('payment_transactions', $invoiceToken, $fields);
        error_log("[DB] Transaction enregistrée : $invoiceToken (status=$status, plan=$plan, user=$userId)");
    }

    // ── Abonnements ────────────────────────────────────────────────────────

    /**
     * Active ou renouvelle un abonnement (upsert sur user_id).
     */
    public static function activateSubscription(
        string            $userId,
        string            $plan,
        string            $invoiceToken,
        string            $localOrderId,
        ?string           $paymentMethod,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate
    ): void {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $fields = [
            'user_id'             => $userId,
            'plan'                => $plan,
            'status'              => 'active',
            'start_date'          => $startDate->format('Y-m-d H:i:s'),
            'end_date'            => $endDate->format('Y-m-d H:i:s'),
            'last_order_id'       => $localOrderId,
            'last_invoice_token'  => $invoiceToken,
            'payment_method'      => $paymentMethod ?? 'ligdicash',
            'updated_at'          => $now,
        ];

        // Vérifie si le doc existe pour préserver created_at et demo_used
        $existing = self::getDoc('subscriptions', $userId);
        if ($existing === null) {
            $fields['created_at'] = $now;
            $fields['demo_used']  = 0;
        }

        self::patchDoc('subscriptions', $userId, $fields);
        error_log("[DB] Abonnement activé : $userId (plan=$plan, fin={$endDate->format('Y-m-d')})");
    }

    /**
     * Passe un abonnement pending en expired si le paiement échoue.
     */
    public static function clearPendingSubscription(string $userId, string $invoiceToken): void
    {
        try {
            $doc = self::getDoc('subscriptions', $userId);
            if ($doc === null) return;

            $data = self::fromFirestore($doc);
            if (($data['status'] ?? '') === 'pending' && ($data['last_invoice_token'] ?? '') === $invoiceToken) {
                self::patchDoc('subscriptions', $userId, [
                    'status'     => 'expired',
                    'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ]);
            }
        } catch (Throwable $e) {
            error_log('[DB] clearPending erreur : ' . $e->getMessage());
        }
    }

    // ── Tokens FCM ─────────────────────────────────────────────────────────

    public static function getFcmTokensForUser(string $userId): array
    {
        $doc = self::getDoc('fcm_tokens', $userId);
        if ($doc === null) return [];

        $data = self::fromFirestore($doc);
        $token = $data['token'] ?? '';
        return $token !== '' ? [$token] : [];
    }

    public static function saveFcmToken(string $userId, string $token): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        self::patchDoc('fcm_tokens', $userId, [
            'user_id'    => $userId,
            'token'      => $token,
            'updated_at' => $now,
        ]);
        error_log("[FCM] Token enregistré pour user=$userId");
    }

    // ── Healthcheck ────────────────────────────────────────────────────────

    /**
     * Teste la connectivité Firestore (lecture d'un doc fictif).
     * Retourne true si l'API répond (même 404 = OK).
     */
    public static function ping(): bool
    {
        try {
            self::getDoc('_health', 'ping');
            return true;
        } catch (Throwable $e) {
            error_log('[DB] ping échoué : ' . $e->getMessage());
            return false;
        }
    }
}
