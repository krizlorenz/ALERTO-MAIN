# ALERTO Database & System Settings Setup Guide

This document describes the setup, migration, and configuration instructions for deploying ALERTO to other development laptops or production hosting environments.

## 1. Prerequisites
- **PHP**: 8.0+ with `pdo_mysql`, `mbstring`, `openssl`, and `filter` extensions enabled.
- **MySQL / MariaDB**: MySQL 5.7+ or MariaDB 10.3+.
- **Database Name**: Default `alerto-database` (configured in `alerto-db.php`).

## 2. Automated Migration Scripts (Non-Destructive & Repeatable)
All migrations in ALERTO are additive and idempotent. They use `CREATE TABLE IF NOT EXISTS` and `ON DUPLICATE KEY UPDATE` to preserve existing production/development data.

Run the migrations in order via terminal:
```bash
# 1. System Settings (Contact Hotline, Email, and Request Intake Controls)
php migrate-system-settings.php

# 2. Notification Audit & Deduplication Log
php migrate-notifications.php

# 3. Student Registration / Resubmission Versioning
php migrate-submission-versioning.php

# 4. Password Recovery & Rate Limiting Throttles
php migrate-password-resets.php
```

## 3. `system_settings` Table Schema
```sql
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Initial Seed Defaults (Seeded Only When Absent)
| `setting_key` | Initial Value | Description |
|---|---|---|
| `contact_email` | `alerto.coeasc.csu@gmail.com` | Official DRRM council email displayed on portal banners, footers, and email Reply-To. |
| `contact_phone` | `09556678451` | Official DRRM hotline number for student phone/SMS emergency coordination. |
| `request_assistance_enabled` | `1` | Master disaster intake switch (`1` = ON/Active, `0` = OFF/Stand Down). |

Manual SQL seed query (safe to re-run; will never overwrite existing settings):
```sql
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
VALUES 
  ('contact_email', 'alerto.coeasc.csu@gmail.com'),
  ('contact_phone', '09556678451'),
  ('request_assistance_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
```

## 4. Mail & Notification Configuration
SMTP credentials and private notification configuration are managed externally in `C:\laragon\config\alerto-mail-config.php` (or custom server path specified in `get_mail_config()`).
- SMTP sending credentials and admin alert recipients are kept strictly separate from system contact settings.
- Dynamic support contacts (`contact_email` and `contact_phone`) are injected dynamically into outgoing emails as support contact information and email Reply-To.
