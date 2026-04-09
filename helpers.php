<?php
/**
 * helpers.php — Fonctions utilitaires globales
 */
declare(strict_types=1);

// ── Réponse JSON ───────────────────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Encodage base64url (pour JWT Firebase) ─────────────────────────────────
function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ── Page retour paiement réussi ────────────────────────────────────────────
function returnPage(): never
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Paiement confirmé</title>
        <style>
            body { font-family: sans-serif; display: flex; align-items: center;
                   justify-content: center; min-height: 100vh; margin: 0;
                   background: #f0fdf4; }
            .card { background: #fff; border-radius: 12px; padding: 2rem 2.5rem;
                    text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
            .icon { font-size: 3rem; }
            h1 { color: #16a34a; font-size: 1.4rem; margin: .5rem 0; }
            p  { color: #6b7280; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">✅</div>
            <h1>Paiement confirmé !</h1>
            <p>Votre abonnement a été activé avec succès.<br>Vous pouvez fermer cette page.</p>
        </div>
    </body>
    </html>
    HTML;
    exit;
}

// ── Page annulation ────────────────────────────────────────────────────────
function cancelPage(): never
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Paiement annulé</title>
        <style>
            body { font-family: sans-serif; display: flex; align-items: center;
                   justify-content: center; min-height: 100vh; margin: 0;
                   background: #fff7ed; }
            .card { background: #fff; border-radius: 12px; padding: 2rem 2.5rem;
                    text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
            .icon { font-size: 3rem; }
            h1 { color: #ea580c; font-size: 1.4rem; margin: .5rem 0; }
            p  { color: #6b7280; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">❌</div>
            <h1>Paiement annulé</h1>
            <p>Votre paiement a été annulé.<br>Aucun montant n'a été débité.</p>
        </div>
    </body>
    </html>
    HTML;
    exit;
}

// ── Healthcheck Render ─────────────────────────────────────────────────────
function healthHandler(): never
{
    try {
        DatabaseClient::getPdo(); // teste la connexion DB
        jsonResponse(['status' => 'ok', 'db' => 'connected', 'ts' => date('c')]);
    } catch (Throwable) {
        jsonResponse(['status' => 'degraded', 'db' => 'unreachable', 'ts' => date('c')], 503);
    }
}
