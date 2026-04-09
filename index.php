<?php
/**
 * index.php — Routeur principal LigdiCash Callback
 *
 * Routes :
 *   POST /callback/ligdicash  → traitement paiement LigdiCash
 *   POST /fcm/register        → enregistrement token FCM Flutter
 *   GET  /payment/return      → page retour paiement réussi
 *   GET  /payment/cancel      → page annulation
 *   GET  /health              → healthcheck Render
 */
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/SignatureVerifier.php';
require_once __DIR__ . '/DatabaseClient.php';
require_once __DIR__ . '/FcmClient.php';
require_once __DIR__ . '/CallbackHandler.php';

// ── CORS ───────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Apikey');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Routeur ────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/', '/') ?: '/';

$t0 = microtime(true);

match (true) {
    // Callback principal LigdiCash (POST + GET pour test navigateur)
    in_array($method, ['POST', 'GET']) && $path === '/callback/ligdicash'
        => CallbackHandler::handle(),

    // Enregistrement token FCM depuis Flutter
    $method === 'POST' && $path === '/fcm/register'
        => fcmRegisterHandler(),

    // Pages de retour navigateur (après redirection LigdiCash)
    $method === 'GET' && $path === '/payment/return'
        => returnPage(),

    $method === 'GET' && $path === '/payment/cancel'
        => cancelPage(),

    // Healthcheck Render (vérifie connexion DB)
    $method === 'GET' && $path === '/health'
        => healthHandler(),

    // Route racine
    $method === 'GET' && $path === '/'
        => jsonResponse(['service' => 'LigdiCash Callback', 'status' => 'running']),

    default => jsonResponse(['error' => 'Route introuvable', 'path' => $path], 404),
};

// Log de chaque requête avec durée
$ms = round((microtime(true) - $t0) * 1000);
error_log(sprintf('[ROUTER] %s %s → HTTP %d — %dms', $method, $path, http_response_code(), $ms));

// ── Handler FCM register ───────────────────────────────────────────────────
function fcmRegisterHandler(): never
{
    $body = json_decode((string)file_get_contents('php://input'), true);

    if (!is_array($body)) {
        jsonResponse(['error' => 'Corps JSON invalide'], 400);
    }

    $userId = trim((string)($body['user_id'] ?? ''));
    $token  = trim((string)($body['token']   ?? ''));

    if ($userId === '' || $token === '') {
        jsonResponse(['error' => 'user_id et token sont requis'], 422);
    }

    DatabaseClient::saveFcmToken($userId, $token);
    jsonResponse(['message' => 'token_fcm_enregistre']);
}
