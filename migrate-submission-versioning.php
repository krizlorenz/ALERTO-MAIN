<?php
/**
 * Additive migration:
 * 1. Add verification_version to users table
 * 2. Add submission_version and index to email_notifications table
 */
require_once 'C:/laragon/www/ALERTO-MAIN/alerto-db.php';

try {
    echo "Running additive migrations...\n";

    // 1. Check users table
    $stmtUserCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'verification_version'")->fetch();
    if (!$stmtUserCol) {
        $pdo->exec("ALTER TABLE users ADD COLUMN verification_version INT NOT NULL DEFAULT 1 AFTER status");
        echo "[APPLIED] Added verification_version column to users table.\n";
    } else {
        echo "[EXISTS] verification_version column already present in users table.\n";
    }

    // 2. Check email_notifications table
    $stmtNotifCol = $pdo->query("SHOW COLUMNS FROM email_notifications LIKE 'submission_version'")->fetch();
    if (!$stmtNotifCol) {
        $pdo->exec("ALTER TABLE email_notifications ADD COLUMN submission_version INT NOT NULL DEFAULT 1 AFTER transition_state");
        $pdo->exec("ALTER TABLE email_notifications ADD INDEX idx_submission_dedup (event_type, reference_id, transition_state, submission_version, recipient_email)");
        echo "[APPLIED] Added submission_version column and index to email_notifications table.\n";
    } else {
        echo "[EXISTS] submission_version column already present in email_notifications table.\n";
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
