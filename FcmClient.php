<?php
/**
 * FcmClient.php — Notifications Firebase Cloud Messaging (FCM v1)
 *
 * Les tokens FCM sont stockés dans la table MySQL `fcm_tokens`.
 * Côté Flutter, enregistrer le token via : POST /fcm/register
 *   Body JSON : { "user_id": "...", "token": "..." }
 *
 * FCM est non bloquant : toute erreur est loguée mais ne fait
 * pas échouer le callback LigdiCash.
 */
declare(strict_types=1);

class FcmClient
{
    private static ?string $cachedAccessToken = null;
    private static int     $tokenExpires      = 0;

    /**
     * Envoie une notification à tous les appareils d'un utilisateur.
     */
    public static function notifyUser(
        string $userId,
        string $title,
        string $body,
        array  $data = []
    ): void {
        // FCM désactivé si les credentials Firebase ne sont pas configurés
        if (FIREBASE_PROJECT_ID === '' || FIREBASE_CLIENT_EMAIL === '' || FIREBASE_PRIVATE_KEY === '') {
            error_log('[FCM] Credentials non configurés — notification ignorée');
            return;
        }

        try {
            $tokens = DatabaseClient::getFcmTokensForUser($userId);

            if (empty($tokens)) {
                error_log("[FCM] Aucun token pour userId=$userId");
                return;
            }

            $dataStr = array_map('strval', array_merge(['userId' => $userId], $data));

            foreach ($tokens as $token) {
                if (trim($token) === '') continue;
                self::sendToToken($token, $title, $body, $dataStr);
                error_log('[FCM] Notification envoyée → ' . substr($token, 0, 20) . '…');
            }
        } catch (Throwable $e) {
            // Non bloquant
            error_log('[FCM] Erreur (non bloquant) : ' . $e->getMessage());
        }
    }

    // ── Envoi FCM v1 ──────────────────────────────────────────────────────

    private static function sendToToken(
        string $token,
        string $title,
        string $body,
        array  $data
    ): void {
        $url = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            FIREBASE_PROJECT_ID
        );

        $payload = [
            'message' => [
                'token'        => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => $data,
                'android'      => [
                    'priority'     => 'high',
                    'notification' => [
                        'channel_id' => 'subscription_updates',
                        'sound'      => 'default',
                    ],
                ],
                'apns' => [
                    'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
                ],
            ],
        ];

        $accessToken = self::getAccessToken();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            error_log("[FCM] Erreur HTTP $code : $res");
            throw new RuntimeException("FCM HTTP $code : $res");
        }
    }

    // ── JWT Google OAuth2 ─────────────────────────────────────────────────

    private static function getAccessToken(): string
    {
        if (self::$cachedAccessToken !== null && time() < self::$tokenExpires - 60) {
            return self::$cachedAccessToken;
        }

        $now    = time();
        $header = base64url_encode((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = base64url_encode((string)json_encode([
            'iss'   => FIREBASE_CLIENT_EMAIL,
            'sub'   => FIREBASE_CLIENT_EMAIL,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ]));

        $unsigned = $header . '.' . $claims;
        openssl_sign($unsigned, $signature, FIREBASE_PRIVATE_KEY, OPENSSL_ALGO_SHA256);
        $jwt = $unsigned . '.' . base64url_encode($signature);

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

        if (!isset($res['access_token'])) {
            throw new RuntimeException('Firebase token error : ' . json_encode($res));
        }

        self::$cachedAccessToken = $res['access_token'];
        self::$tokenExpires      = $now + (int)($res['expires_in'] ?? 3600);

        return self::$cachedAccessToken;
    }
}
