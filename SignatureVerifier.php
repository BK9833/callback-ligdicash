<?php
/**
 * SignatureVerifier.php — Vérification signature LigdiCash
 *
 * ⚠️  VÉRIFICATION DÉSACTIVÉE — MODE TEST UNIQUEMENT
 * Remettre la vérification en production.
 */
declare(strict_types=1);

class SignatureVerifier
{
    public static function verify(array $data): bool
    {
        error_log('[SIG] Vérification ignorée — mode test');
        return true;
    }
}
