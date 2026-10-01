<?php
// C:\laragon\www\ALERTO-MAIN\alerto-db.php

$host = '127.0.0.1';
$dbname = 'alerto-database';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

/**
 * Retrieve system settings (contact info, emergency intake state)
 */
function get_system_settings($pdo) {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'contact_email' => 'alertoCOEA@gmail.com',
        'contact_phone' => '09556678451',
        'request_assistance_enabled' => '1',
    ];

    try {
        $stmt = $pdo->query("SELECT `setting_key`, `setting_value` FROM `system_settings`");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $cached = array_merge($defaults, $rows);
    } catch (Exception $e) {
        $cached = $defaults;
    }

    return $cached;
}

/**
 * Update or insert a system setting
 */
function update_system_setting($pdo, $key, $value) {
    $stmt = $pdo->prepare("
        INSERT INTO `system_settings` (`setting_key`, `setting_value`) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)
    ");
    return $stmt->execute([$key, $value]);
}
?>