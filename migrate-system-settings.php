<?php
// migrate-system-settings.php - Additive, repeatable schema migration for ALERTO system settings
// Preserves all existing settings; seeds defaults only when absent.
require_once __DIR__ . '/alerto-db.php';

try {
    // 1. Create system_settings table if it does not already exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_settings` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `setting_key` VARCHAR(64) NOT NULL,
          `setting_value` TEXT NOT NULL,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `uniq_setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Seed defaults ONLY if they do not exist (preserve existing values)
    $defaults = [
        'contact_email'              => 'alerto.coeasc.csu@gmail.com',
        'contact_phone'              => '09556678451',
        'request_assistance_enabled' => '1',
    ];

    $seedStmt = $pdo->prepare("
        INSERT INTO `system_settings` (`setting_key`, `setting_value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`
    ");

    foreach ($defaults as $key => $val) {
        $seedStmt->execute([$key, $val]);
    }

    echo "[SUCCESS] Migration completed successfully. Table `system_settings` is ready with default values seeded.\n";
} catch (Exception $e) {
    echo "[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
