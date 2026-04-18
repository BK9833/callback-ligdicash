<?php
/**
 * index.php — Routeur principal LigdiCash Callback
 *
 * Routes :
 *   POST /pay/create          → proxy sécurisé : Flutter → Ligdicash (crée facture)
 *   GET  /pay/status          → proxy sécurisé : Flutter → Ligdicash (statut)
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
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Nettoyage du path
$path = str_replace('/index.php', '', $path);
$path = rtrim($path, '/') ?: '/';

$t0 = microtime(true);

match (true) {
    // ── Proxy sécurisé Flutter → Ligdicash ────────────────────────
    // Les clés API restent sur le serveur (.env / config.php)
    // Le Flutter n'a pas connaissance des clés

    $method === 'POST' && $path === '/pay/create'
        => payCreateHandler(),

    $method === 'GET' && $path === '/pay/status'
        => payStatusHandler(),

    // ── Callback principal LigdiCash ───────────────────────────────
    in_array($method, ['POST', 'GET']) && $path === '/callback/ligdicash'
        => CallbackHandler::handle(),

    // ── Enregistrement token FCM depuis Flutter ────────────────────
    $method === 'POST' && $path === '/fcm/register'
        => fcmRegisterHandler(),

    // ── Pages de retour navigateur ─────────────────────────────────
    $method === 'GET' && $path === '/payment/return'
        => returnPage(),

    $method === 'GET' && $path === '/payment/cancel'
        => cancelPage(),

    // ── Healthcheck ────────────────────────────────────────────────
    $method === 'GET' && $path === '/health'
        => healthHandler(),

    // ── Racine ─────────────────────────────────────────────────────
    $method === 'GET' && $path === '/'
        => jsonResponse(['service' => 'LigdiCash Callback', 'status' => 'running']),

    default => jsonResponse(['error' => 'Route introuvable', 'path' => $path], 404),
};

// Log durée
$ms = round((microtime(true) - $t0) * 1000);
error_log(sprintf('[ROUTER] %s %s → HTTP %d — %dms', $method, $path, http_response_code(), $ms));


// ═══════════════════════════════════════════════════════════════════════════
// PROXY /pay/create
// Reçoit depuis Flutter : { userId, userName, userEmail, plan, description }
// Contacte Ligdicash avec les clés du serveur
// Retourne au Flutter  : { success, paymentUrl, invoiceToken }
//                     ou { success: false, error }
// ═══════════════════════════════════════════════════════════════════════════
function payCreateHandler(): never
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        jsonResponse(['success' => false, 'error' => 'Corps JSON invalide'], 400);
    }

    $userId      = trim((string) ($body['userId']      ?? ''));
    $userName    = trim((string) ($body['userName']    ?? ''));
    $userEmail   = trim((string) ($body['userEmail']   ?? ''));
    $plan        = strtolower(trim((string) ($body['plan'] ?? 'mensuel')));
    $description = trim((string) ($body['description'] ?? ''));

    if ($userId === '' || $userEmail === '') {
        jsonResponse(['success' => false, 'error' => 'userId et userEmail requis'], 422);
    }

    // Tarifs par plan (synchronisé avec kPlanPrices dans ligdicash_service.dart)
    $planPrices = [
        'hebdomadaire' => 300,
        'mensuel'      => 800,
        'trimestriel'  => 2500,
        'annuel'       => 10000,
    ];

    $planLabels = [
        'hebdomadaire' => 'Hebdomadaire (7 jours)',
        'mensuel'      => 'Mensuel (30 jours)',
        'trimestriel'  => 'Trimestriel (90 jours)',
        'semestriel'   => 'Semestriel (180 jours)',
        'annuel'       => 'Annuel (365 jours)',
    ];

    if (!isset($planPrices[$plan])) {
        jsonResponse(['success' => false, 'error' => "Plan inconnu : \"$plan\""], 422);
    }

    $amount      = $planPrices[$plan];
    $label       = $planLabels[$plan] ?? $plan;
    $description = $description !== '' ? $description : $label;
    $externalId  = 'MMR-' . round(microtime(true) * 1000);

    // Découpage prénom / nom
    $nameParts = explode(' ', $userName, 2);
    $firstName = $nameParts[0] ?? '';
    $lastName  = $nameParts[1] ?? '';

    // ✅ Clés API lues depuis config.php (variables d'environnement Render)
    // Jamais exposées dans l'APK Flutter
    $serverBase  = defined('SERVER_BASE_URL')      ? SERVER_BASE_URL      : 'https://callback-ligdicash-00.onrender.com';
    $callbackUrl = $serverBase . '/callback/ligdicash';
    $returnUrl   = $serverBase . '/payment/return';
    $cancelUrl   = $serverBase . '/payment/cancel';

    $ligdicashBody = [
        'commande' => [
            'invoice' => [
                'items' => [[
                    'name'        => "Abonnement $label MM Registre",
                    'description' => $description,
                    'quantity'    => 1,
                    'unit_price'  => $amount,
                    'total_price' => $amount,
                ]],
                'total_amount'       => $amount,
                'devise'             => 'XOF',
                'description'        => $description,
                'customer'           => '',
                'customer_firstname' => $firstName,
                'customer_lastname'  => $lastName,
                'customer_email'     => $userEmail,
                'external_id'        => '',
                'otp'                => '',
            ],
            'store' => [
                'name'        => 'MM Registre',
                'website_url' => $returnUrl,
            ],
            'actions' => [
                'cancel_url'   => $cancelUrl,
                'return_url'   => $returnUrl,
                'callback_url' => $callbackUrl,
            ],
            'custom_data' => [
                'user_id'        => $userId,
                'plan'           => $plan,
                'transaction_id' => $externalId,
            ],
        ],
    ];

    $response = ligdicashPost(
        '/redirect/checkout-invoice/create',
        $ligdicashBody
    );

    if ($response === null) {
        jsonResponse(['success' => false, 'error' => 'Erreur réseau vers Ligdicash'], 502);
    }

    $code = $response['response_code'] ?? '';

    if ($code === '00') {
        $paymentUrl   = $response['response_text'] ?? null;
        $invoiceToken = $response['token']         ?? null;

        if ($paymentUrl === null || $invoiceToken === null) {
            jsonResponse(['success' => false, 'error' => 'URL ou token manquant'], 502);
        }

        jsonResponse([
            'success'      => true,
            'paymentUrl'   => $paymentUrl,
            'invoiceToken' => $invoiceToken,
        ]);
    }

    $errMsg = $response['response_text'] ?? '';
    $wiki   = $response['wiki']          ?? '';
    jsonResponse([
        'success' => false,
        'error'   => "Ligdicash Code{$code}"
                   . ($errMsg !== '' ? " : $errMsg" : '')
                   . ($wiki   !== '' ? " — $wiki"   : ''),
    ], 502);
}


// ═══════════════════════════════════════════════════════════════════════════
// PROXY /pay/status
// Reçoit depuis Flutter : ?token=invoiceToken
// Retourne au Flutter  : { status, operatorName, transactionId }
// ═══════════════════════════════════════════════════════════════════════════
function payStatusHandler(): never
{
    $token = trim((string) ($_GET['token'] ?? ''));

    if ($token === '') {
        jsonResponse(['status' => 'pending', 'error' => 'token manquant'], 400);
    }

    $response = ligdicashGet(
        '/redirect/checkout-invoice/confirm/?invoiceToken=' . urlencode($token)
    );

    if ($response === null) {
        jsonResponse(['status' => 'pending', 'error' => 'Erreur réseau vers Ligdicash']);
    }

    $code = $response['response_code'] ?? '';

    if ($code === '00') {
        $rawStatus = $response['status']  ?? $response['statut'] ?? 'pending';
        jsonResponse([
            'status'        => strtolower(trim($rawStatus)),
            'operatorName'  => $response['operator_name']  ?? null,
            'transactionId' => $response['transaction_id'] ?? null,
        ]);
    }

    jsonResponse(['status' => 'pending']);
}


// ═══════════════════════════════════════════════════════════════════════════
// HELPERS HTTP vers Ligdicash (utilisent les clés du serveur)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Appel POST vers l'API Ligdicash.
 * Les clés sont lues depuis LIGDICASH_API_KEY / LIGDICASH_API_TOKEN
 * définis dans config.php (variables d'environnement Render).
 */
function ligdicashPost(string $endpoint, array $body): ?array
{
    $url     = 'https://app.ligdicash.com/pay/v01' . $endpoint;
    $apiKey  = defined('LIGDICASH_API_KEY')   ? LIGDICASH_API_KEY   : '';
    $apiToken= defined('LIGDICASH_API_TOKEN')  ? LIGDICASH_API_TOKEN : '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Apikey: '        . $apiKey,
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('[ligdicashPost] cURL error : ' . $err);
        return null;
    }

    error_log('[ligdicashPost] ' . $endpoint . ' → ' . $raw);
    return json_decode((string) $raw, true) ?? null;
}

/**
 * Appel GET vers l'API Ligdicash.
 */
function ligdicashGet(string $endpoint): ?array
{
    $url      = 'https://app.ligdicash.com/pay/v01' . $endpoint;
    $apiKey   = defined('LIGDICASH_API_KEY')   ? LIGDICASH_API_KEY   : '';
    $apiToken = defined('LIGDICASH_API_TOKEN')  ? LIGDICASH_API_TOKEN : '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Apikey: '        . $apiKey,
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('[ligdicashGet] cURL error : ' . $err);
        return null;
    }

    error_log('[ligdicashGet] ' . $endpoint . ' → ' . $raw);
    return json_decode((string) $raw, true) ?? null;
}


// ═══════════════════════════════════════════════════════════════════════════
// HANDLER FCM register (inchangé)
// ═══════════════════════════════════════════════════════════════════════════
function fcmRegisterHandler(): never
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        jsonResponse(['error' => 'Corps JSON invalide'], 400);
    }

    $userId = trim((string) ($body['user_id'] ?? ''));
    $token  = trim((string) ($body['token']   ?? ''));

    if ($userId === '' || $token === '') {
        jsonResponse(['error' => 'user_id et token sont requis'], 422);
    }

    DatabaseClient::saveFcmToken($userId, $token);
    jsonResponse(['message' => 'token_fcm_enregistre']);
}
