<?php
/**
 * SignatureVerifier.php — Vérification signature LigdiCash
 *
 * LigdiCash signe optionnellement les callbacks avec :
 *   hash = sha256(api_key + token + auth_token)
 *
 * Si LIGDICASH_API_KEY ou LIGDICASH_API_TOKEN sont vides,
 * la vérification est ignorée (mode dev).
 */
declare(strict_types=1);

class SignatureVerifier
{
    public static function verify(array $data): bool
    {
        // Pas de clés configurées → on laisse passer
        if (LIGDICASH_API_KEY === '' || LIGDICASH_API_TOKEN === '') {
            error_log('[SIG] Vérification ignorée (clés non configurées)');
            return true;
        }

        $received = $data['hash'] ?? '';
        if ($received === '') {
            error_log('[SIG] Champ "hash" absent du payload');
            return false;
        }

        $token    = $data['token'] ?? '';
        $expected = hash('sha256', LIGDICASH_API_KEY . $token . LIGDICASH_API_TOKEN);

        $ok = hash_equals($expected, $received);
        if (!$ok) {
            error_log("[SIG] Signature invalide pour token=$token");
        }
        return $ok;
    }
}
