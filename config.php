<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'bandroyal');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

function initDB(): void {
    // Create DB if not exists
    $root = new PDO(
        'mysql:host='.DB_HOST.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $root->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = getDB();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `events` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `nom`         VARCHAR(255) NOT NULL,
            `type`        VARCHAR(50)  NOT NULL DEFAULT 'autre',
            `date`        DATE         NOT NULL,
            `lieu`        VARCHAR(255) DEFAULT '',
            `description` TEXT,
            `image_path`  VARCHAR(500) DEFAULT '',
            `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `event_musicians` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `event_id`   INT          NOT NULL,
            `nom`        VARCHAR(255) NOT NULL,
            `instrument` VARCHAR(100) DEFAULT '',
            FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `transactions` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `type`        ENUM('cred','deb') NOT NULL,
            `description` VARCHAR(500) NOT NULL,
            `date`        DATE         NOT NULL,
            `montant`     DECIMAL(12,2) DEFAULT 0,
            `event_id`    INT          DEFAULT NULL,
            `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `transaction_details` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_id` INT          NOT NULL,
            `nom`            VARCHAR(255) NOT NULL,
            `montant`        DECIMAL(12,2) DEFAULT 0,
            `motif`          VARCHAR(500) DEFAULT '—',
            FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
