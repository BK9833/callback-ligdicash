-- ============================================================
-- schema.sql — Base de données LigdiCash Callback
-- À exécuter via phpMyAdmin (InfinityFree) ou MySQL CLI
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Transactions de paiement ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payment_transactions` (
    `id`             INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    `invoice_token`  VARCHAR(255)     NOT NULL,
    `user_id`        VARCHAR(255)     NOT NULL,
    `plan`           VARCHAR(50)      NOT NULL,
    `local_order_id` VARCHAR(255)     NOT NULL,
    `status`         ENUM('completed','pending','nocompleted') NOT NULL DEFAULT 'pending',
    `provider`       VARCHAR(50)      NOT NULL DEFAULT 'ligdicash',
    `operator_name`  VARCHAR(100)     DEFAULT NULL,
    `transaction_id` VARCHAR(255)     DEFAULT NULL,
    `amount`         INT UNSIGNED     NOT NULL DEFAULT 0,
    `response_code`  VARCHAR(10)      DEFAULT NULL,
    `response_text`  VARCHAR(255)     DEFAULT NULL,
    `customer`       VARCHAR(50)      DEFAULT NULL,
    `external_id`    VARCHAR(255)     DEFAULT NULL,
    `request_id`     VARCHAR(255)     DEFAULT NULL,
    `raw_payload`    TEXT             DEFAULT NULL,
    `processed_at`   DATETIME         DEFAULT NULL,
    `duration_days`  INT UNSIGNED     DEFAULT NULL,
    `start_date`     DATETIME         DEFAULT NULL,
    `end_date`       DATETIME         DEFAULT NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE  KEY `uq_invoice_token` (`invoice_token`),
    INDEX   `idx_user_id`          (`user_id`),
    INDEX   `idx_status`           (`status`),
    INDEX   `idx_created_at`       (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Abonnements (1 ligne par user, ON DUPLICATE KEY UPDATE) ───────────────
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id`                  INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    `user_id`             VARCHAR(255)     NOT NULL,
    `plan`                VARCHAR(50)      NOT NULL,
    `status`              ENUM('active','pending','expired','cancelled') NOT NULL DEFAULT 'pending',
    `start_date`          DATETIME         NOT NULL,
    `end_date`            DATETIME         NOT NULL,
    `demo_used`           INT UNSIGNED     NOT NULL DEFAULT 0,
    `last_order_id`       VARCHAR(255)     DEFAULT NULL,
    `last_invoice_token`  VARCHAR(255)     DEFAULT NULL,
    `payment_method`      VARCHAR(100)     DEFAULT NULL,
    `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE  KEY `uq_user_id`  (`user_id`),
    INDEX   `idx_status`      (`status`),
    INDEX   `idx_end_date`    (`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tokens FCM (1 token par user) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fcm_tokens` (
    `id`         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `user_id`    VARCHAR(255)  NOT NULL,
    `token`      TEXT          NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_id`  (`user_id`),
    INDEX      `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
