<?php
/**
 * config.php — Variables d'environnement + constantes globales
 */
declare(strict_types=1);

// Chargement .env si disponible (dev local)
if (class_exists(\Dotenv\Dotenv::class) && file_exists(__DIR__ . '/.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

// Lecture sécurisée d'une variable d'environnement
function env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v !== false && $v !== null && $v !== '') ? (string)$v : $default;
}

// ── LigdiCash ──────────────────────────────────────────────────────────────
defined('LIGDICASH_API_KEY')   || define('LIGDICASH_API_KEY',   env('LIGDICASH_API_KEY'));
defined('LIGDICASH_API_TOKEN') || define('LIGDICASH_API_TOKEN', env('LIGDICASH_API_TOKEN'));

// ── MySQL ──────────────────────────────────────────────────────────────────
defined('DB_HOST')     || define('DB_HOST',     env('DB_HOST',     'sql310.infinityfree.com'));
defined('DB_PORT')     || define('DB_PORT',     env('DB_PORT',     '3306'));
defined('DB_NAME')     || define('DB_NAME',     env('DB_NAME'));
defined('DB_USER')     || define('DB_USER',     env('DB_USER'));
defined('DB_PASSWORD') || define('DB_PASSWORD', env('DB_PASSWORD'));
defined('DB_CHARSET')  || define('DB_CHARSET',  'utf8mb4');

// ── Firebase FCM ───────────────────────────────────────────────────────────
defined('FIREBASE_PROJECT_ID')   || define('FIREBASE_PROJECT_ID',   env('FIREBASE_PROJECT_ID'));
defined('FIREBASE_CLIENT_EMAIL') || define('FIREBASE_CLIENT_EMAIL', env('FIREBASE_CLIENT_EMAIL'));
defined('FIREBASE_PRIVATE_KEY')  || define('FIREBASE_PRIVATE_KEY',
    str_replace('\n', "\n", env('FIREBASE_PRIVATE_KEY'))
);

// ── Durées des plans (jours) ───────────────────────────────────────────────
const PLAN_DURATIONS = [
    'hebdomadaire' => 7,
    'mensuel'      => 30,
    'trimestriel'  => 90,
    'semestriel'   => 180,
    'annuel'       => 365,
];
