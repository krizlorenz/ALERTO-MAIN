<?php
/**
 * Additive Migration: email_notifications table for ALERTO
 * Safely creates logging & deduplication table without touching existing tables.
 */
require_once __DIR__ . '/alerto-db.php';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS email_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(50) NOT NULL,
        reference_id INT NOT NULL,
        transition_state VARCHAR(50) NOT NULL,
        recipient_email VARCHAR(150) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        status ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
        error_message TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dedup (event_type, reference_id, transition_state),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "[SUCCESS] email_notifications table is ready.\n";
} catch (PDOException $e) {
    echo "[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
