<?php
/**
 * DatabaseClient.php — Client MySQL via PDO (Singleton)
 * Compatible InfinityFree (MySQL 5.x / 8.x)
 */
declare(strict_types=1);

class DatabaseClient
{
    private static ?PDO $pdo = null;

    // ── Connexion ──────────────────────────────────────────────────────────

    public static function getPdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        date_default_timezone_set('UTC');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            error_log('[DB] Connexion échouée : ' . $e->getMessage());
            throw new RuntimeException('Connexion base de données échouée.');
        }

        return self::$pdo;
    }

    // ── Transactions ───────────────────────────────────────────────────────

    /**
     * Vérifie si un invoice_token a déjà été traité (idempotence).
     */
    public static function transactionExists(string $invoiceToken): bool
    {
        $stmt = self::getPdo()->prepare(
            'SELECT COUNT(*) FROM payment_transactions WHERE invoice_token = ?'
        );
        $stmt->execute([$invoiceToken]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Insère ou met à jour une transaction (ON DUPLICATE KEY UPDATE).
     */
    public static function recordTransaction(
        string  $invoiceToken,
        string  $userId,
        string  $plan,
        string  $localOrderId,
        string  $status,          // completed | pending | nocompleted
        array   $payload,         // payload brut complet
        ?string $operatorName  = null,
        ?string $transactionId = null,
        int     $amount         = 0,
        array   $extra          = []
    ): void {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $startDate = isset($extra['startDate'])
            ? (new DateTimeImmutable($extra['startDate']))->format('Y-m-d H:i:s')
            : null;
        $endDate = isset($extra['endDate'])
            ? (new DateTimeImmutable($extra['endDate']))->format('Y-m-d H:i:s')
            : null;
        $processedAt = !empty($extra['processedAt'])
            ? (new DateTimeImmutable($extra['processedAt']))->format('Y-m-d H:i:s')
            : null;

        $stmt = self::getPdo()->prepare('
            INSERT INTO payment_transactions
                (invoice_token, user_id, plan, local_order_id, status,
                 provider, operator_name, transaction_id, amount,
                 response_code, response_text, customer, external_id,
                 request_id, raw_payload, processed_at, duration_days,
                 start_date, end_date, created_at)
            VALUES
                (:invoice_token, :user_id, :plan, :local_order_id, :status,
                 :provider, :operator_name, :transaction_id, :amount,
                 :response_code, :response_text, :customer, :external_id,
                 :request_id, :raw_payload, :processed_at, :duration_days,
                 :start_date, :end_date, :created_at)
            ON DUPLICATE KEY UPDATE
                status         = VALUES(status),
                operator_name  = VALUES(operator_name),
                transaction_id = VALUES(transaction_id),
                amount         = VALUES(amount),
                response_code  = VALUES(response_code),
                response_text  = VALUES(response_text),
                processed_at   = VALUES(processed_at),
                duration_days  = VALUES(duration_days),
                start_date     = VALUES(start_date),
                end_date       = VALUES(end_date),
                raw_payload    = VALUES(raw_payload)
        ');

        $stmt->execute([
            ':invoice_token'  => $invoiceToken,
            ':user_id'        => $userId,
            ':plan'           => $plan,
            ':local_order_id' => $localOrderId,
            ':status'         => $status,
            ':provider'       => 'ligdicash',
            ':operator_name'  => $operatorName,
            ':transaction_id' => $transactionId,
            ':amount'         => $amount,
            ':response_code'  => $extra['responseCode']  ?? null,
            ':response_text'  => $extra['responseText']  ?? null,
            ':customer'       => $extra['customer']       ?? null,
            ':external_id'    => $extra['externalId']     ?? null,
            ':request_id'     => $extra['requestId']      ?? null,
            ':raw_payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':processed_at'   => $processedAt,
            ':duration_days'  => $extra['durationDays']   ?? null,
            ':start_date'     => $startDate,
            ':end_date'       => $endDate,
            ':created_at'     => $now,
        ]);

        error_log("[DB] Transaction enregistrée : $invoiceToken (status=$status, plan=$plan, user=$userId)");
    }

    // ── Abonnements ────────────────────────────────────────────────────────

    /**
     * Active ou renouvelle un abonnement (ON DUPLICATE KEY UPDATE sur user_id).
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
        $stmt = self::getPdo()->prepare('
            INSERT INTO subscriptions
                (user_id, plan, status, start_date, end_date,
                 demo_used, last_order_id, last_invoice_token,
                 payment_method, created_at, updated_at)
            VALUES
                (:user_id, :plan, "active", :start_date, :end_date,
                 0, :last_order_id, :last_invoice_token,
                 :payment_method, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                plan               = VALUES(plan),
                status             = "active",
                start_date         = VALUES(start_date),
                end_date           = VALUES(end_date),
                last_order_id      = VALUES(last_order_id),
                last_invoice_token = VALUES(last_invoice_token),
                payment_method     = VALUES(payment_method),
                updated_at         = NOW()
        ');

        $stmt->execute([
            ':user_id'            => $userId,
            ':plan'               => $plan,
            ':start_date'         => $startDate->format('Y-m-d H:i:s'),
            ':end_date'           => $endDate->format('Y-m-d H:i:s'),
            ':last_order_id'      => $localOrderId,
            ':last_invoice_token' => $invoiceToken,
            ':payment_method'     => $paymentMethod ?? 'ligdicash',
        ]);

        error_log("[DB] Abonnement activé : $userId (plan=$plan, fin={$endDate->format('Y-m-d')})");
    }

    /**
     * Expire un abonnement pending si le paiement échoue.
     */
    public static function clearPendingSubscription(string $userId, string $invoiceToken): void
    {
        try {
            $stmt = self::getPdo()->prepare('
                UPDATE subscriptions
                SET    status = "expired", updated_at = NOW()
                WHERE  user_id = ? AND last_invoice_token = ? AND status = "pending"
            ');
            $stmt->execute([$userId, $invoiceToken]);
        } catch (Throwable $e) {
            error_log('[DB] clearPending erreur : ' . $e->getMessage());
        }
    }

    // ── Tokens FCM ─────────────────────────────────────────────────────────

    public static function getFcmTokensForUser(string $userId): array
    {
        $stmt = self::getPdo()->prepare(
            'SELECT token FROM fcm_tokens WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function saveFcmToken(string $userId, string $token): void
    {
        $stmt = self::getPdo()->prepare('
            INSERT INTO fcm_tokens (user_id, token, created_at, updated_at)
            VALUES (:user_id, :token, NOW(), NOW())
            ON DUPLICATE KEY UPDATE token = VALUES(token), updated_at = NOW()
        ');
        $stmt->execute([':user_id' => $userId, ':token' => $token]);
        error_log("[FCM] Token enregistré pour user=$userId");
    }
}
