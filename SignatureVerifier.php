<?php
/**
 * SignatureVerifier.php — Vérification signature LigdiCash
 *
 * LigdiCash signe optionnellement les callbacks avec :
 * hash = sha256(api_key + token + auth_token)
 *
 * Si LIGDICASH_API_KEY ou LIGDICASH_API_TOKEN sont vides,
 * ou si le mode DEBUG est activé, la vérification est ignorée.
 */
declare(strict_types=1);

// Définition du mode de test (à mettre à false en production)
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true); 
}

class SignatureVerifier
{
    /**
     * Vérifie l'authenticité de la requête LigdiCash
     * * @param array $data Le payload reçu (JSON décodé)
     * @return bool
     */
    public static function verify(array $data): bool
    {
        // 1. Passage forcé pour les tests ou si les clés sont manquantes
        if (APP_DEBUG === true || LIGDICASH_API_KEY === '' || LIGDICASH_API_TOKEN === '') {
            error_log('[SIG] Vérification de signature ignorée (Mode TEST ou Clés vides)');
            return true;
        }

        // 2. Récupération de la signature envoyée par LigdiCash
        $received = $data['hash'] ?? '';
        if ($received === '') {
            error_log('[SIG] Champ "hash" absent du payload envoyé par LigdiCash');
            return false;
        }

        // 3. Récupération du token de la transaction
        $token = $data['token'] ?? '';
        if ($token === '') {
            error_log('[SIG] Champ "token" absent, impossible de vérifier la signature');
            return false;
        }

        // 4. Calcul de la signature attendue
        // Format : sha256(api_key + token + auth_token)
        $expected = hash('sha256', LIGDICASH_API_KEY . $token . LIGDICASH_API_TOKEN);

        // 5. Comparaison sécurisée
        $ok = hash_equals($expected, $received);
        
        if (!$ok) {
            error_log("[SIG] ÉCHEC : Signature invalide. Reçue: $received | Attendue: $expected");
        } else {
            error_log("[SIG] SUCCÈS : Signature valide pour token=$token");
        }

        return $ok;
    }
}
