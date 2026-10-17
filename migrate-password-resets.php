<?php
// migrate-password-resets.php - Additive schema migration for ALERTO password recovery
require_once __DIR__ . '/alerto-db.php';

try {
    // 1. Create password_resets table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_resets` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `email` VARCHAR(150) NOT NULL,
          `token_hash` VARCHAR(64) NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `expires_at` DATETIME NOT NULL,
          `used_at` DATETIME NULL DEFAULT NULL,
          INDEX `idx_token_hash` (`token_hash`),
          INDEX `idx_user_email` (`email`),
          INDEX `idx_expires_at` (`expires_at`),
          INDEX `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Create password_reset_throttles table for persistent IP and Email rate limiting
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_reset_throttles` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `email` VARCHAR(150) NOT NULL,
          `ip_address` VARCHAR(45) NOT NULL,
          `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_email_time` (`email`, `requested_at`),
          INDEX `idx_ip_time` (`ip_address`, `requested_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    echo "Migration completed successfully. Tables `password_resets` and `password_reset_throttles` are ready.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
