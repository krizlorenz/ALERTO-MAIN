<?php
/**
 * ALERTO Shared Password Recovery Service
 * Consolidates token generation, storage (hashed only), PHPMailer dispatch,
 * persistent rate limiting, CSRF protection, and atomic password updates.
 */

require_once __DIR__ . '/alerto-db.php';
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Load private mail configuration from outside web root/repository.
 */
function get_mail_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $candidates = [
        'C:/laragon/config/alerto-mail-config.php',
        'C:/laragon/alerto-mail-config.php',
        dirname(__DIR__, 2) . '/config/alerto-mail-config.php',
        dirname(__DIR__, 2) . '/alerto-mail-config.php',
    ];

    foreach ($candidates as $filePath) {
        if (file_exists($filePath)) {
            $loaded = include $filePath;
            if (is_array($loaded)) {
                $config = $loaded;
                return $config;
            }
        }
    }

    // Default configuration placeholder
    $config = [
        'smtp' => [
            'host'       => 'smtp.gmail.com',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => 'alerto.coeasc.csu@gmail.com',
            'password'   => '',
            'from_email' => 'alerto.coeasc.csu@gmail.com',
            'from_name'  => 'ALERTO',
        ],
        'app' => [
            'url' => 'http://localhost/ALERTO-MAIN',
        ],
    ];

    return $config;
}

/**
 * Generate or retrieve CSRF token.
 */
function get_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify submitted CSRF token.
 */
function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get client IP address safely.
 */
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}

/**
 * Check persistent rate limits for password reset requests (by email and IP).
 * Email limit: max 3 requests per 15 minutes.
 * IP limit: max 10 requests per 15 minutes.
 */
function check_password_reset_throttling($pdo, $email, $ip) {
    $windowMinutes = 15;
    $maxPerEmail = 3;
    $maxPerIp = 10;

    try {
        // Check IP throttling
        $stmtIp = $pdo->prepare("
            SELECT COUNT(*) FROM password_reset_throttles 
            WHERE ip_address = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmtIp->execute([$ip, $windowMinutes]);
        $ipCount = (int)$stmtIp->fetchColumn();

        if ($ipCount >= $maxPerIp) {
            return [
                'allowed' => false,
                'message' => 'Too many password reset requests from this network. Please wait 15 minutes before trying again.'
            ];
        }

        // Check Email throttling
        if (!empty($email)) {
            $stmtEmail = $pdo->prepare("
                SELECT COUNT(*) FROM password_reset_throttles 
                WHERE LOWER(email) = LOWER(?) AND requested_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmtEmail->execute([$email, $windowMinutes]);
            $emailCount = (int)$stmtEmail->fetchColumn();

            if ($emailCount >= $maxPerEmail) {
                return [
                    'allowed' => false,
                    'message' => 'Too many password reset requests for this email. Please wait 15 minutes before trying again.'
                ];
            }
        }

        return ['allowed' => true];
    } catch (Exception $e) {
        // If throttle table check fails, allow with warning to prevent lockout
        error_log("Throttling check error: " . $e->getMessage());
        return ['allowed' => true];
    }
}

/**
 * Record a password reset request attempt for persistent rate limiting.
 */
function record_password_reset_attempt($pdo, $email, $ip) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_throttles (email, ip_address, requested_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$email, $ip]);

        // Periodic cleanup of records older than 24 hours (1 in 50 chance)
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM password_reset_throttles WHERE requested_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        }
    } catch (Exception $e) {
        error_log("Failed to record reset throttle attempt: " . $e->getMessage());
    }
}

/**
 * Process a password recovery request.
 * - Validates input and CSRF
 * - Applies persistent rate limits
 * - Generates single-use 30-minute token stored only as SHA-256 hash
 * - Invalidates earlier tokens
 * - Sends email via PHPMailer using configured APP_URL
 * - Handles SMTP errors safely without falsely claiming confirmed delivery
 * - Returns uniform generic public response for existing/unknown accounts
 */
function request_password_reset($pdo, $email, $csrfToken) {
    $email = trim($email ?? '');
    $ip = get_client_ip();

    // 1. CSRF Verification
    if (!verify_csrf_token($csrfToken)) {
        return [
            'success' => false,
            'message' => 'Invalid or expired session token. Please refresh the page and try again.'
        ];
    }

    // 2. Input validation
    if (empty($email)) {
        return [
            'success' => false,
            'message' => 'Please enter your registered email address.'
        ];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Please enter a valid email address.'
        ];
    }

    // 3. Persistent rate limiting check
    $throttle = check_password_reset_throttling($pdo, $email, $ip);
    if (!$throttle['allowed']) {
        return [
            'success' => false,
            'message' => $throttle['message']
        ];
    }

    // Record this attempt immediately
    record_password_reset_attempt($pdo, $email, $ip);

    // Uniform generic success response to protect privacy (existing vs non-existing accounts)
    $genericSuccessMessage = 'If an account is associated with this email address, instructions to reset your password have been sent.';

    try {
        // 4. Lookup user (supports students, admins, superadmins)
        $stmt = $pdo->prepare("
            SELECT id, email, full_name, role, status 
            FROM users 
            WHERE LOWER(email) = LOWER(?) 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // If no user found or user is deactivated/banned, do not send email but return generic message
        if (!$user || (isset($user['status']) && $user['status'] === 'banned')) {
            // Simulated delay to mitigate timing attacks
            usleep(random_int(100000, 250000));
            return [
                'success' => true,
                'message' => $genericSuccessMessage
            ];
        }

        // 5. Generate secure random 32-byte token (64 hex characters)
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        // 6. Invalidate all previous active tokens for this user
        $stmtInv = $pdo->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE (user_id = ? OR LOWER(email) = LOWER(?)) AND used_at IS NULL
        ");
        $stmtInv->execute([$user['id'], $user['email']]);

        // 7. Store new token hash with exactly 30 minutes validity
        $stmtIns = $pdo->prepare("
            INSERT INTO password_resets (user_id, email, token_hash, created_at, expires_at) 
            VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))
        ");
        $stmtIns->execute([$user['id'], $user['email'], $tokenHash]);

        // 8. Build reset URL using configured APP_URL (never relying on untrusted HTTP_HOST)
        $config = get_mail_config();
        $appUrl = rtrim($config['app']['url'] ?? 'http://localhost/ALERTO-MAIN', '/\\');
        $resetUrl = $appUrl . '/reset-password.php?token=' . urlencode($plainToken);

        // 9. Send email via PHPMailer
        $sendResult = send_password_reset_email($user['email'], $user['full_name'], $resetUrl, $config);

        if (!$sendResult['success']) {
            // SMTP failure: do NOT claim confirmed delivery!
            error_log("Password reset email dispatch failed for user ID {$user['id']}: " . $sendResult['error']);
            return [
                'success' => false,
                'message' => 'Unable to send password reset email at this time. Please verify mail server configuration or contact the administrator.'
            ];
        }

        return [
            'success' => true,
            'message' => $genericSuccessMessage
        ];

    } catch (Exception $e) {
        error_log("Password recovery exception: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'A system error occurred while processing your request. Please try again later.'
        ];
    }
}

/**
 * Dispatch password reset email via PHPMailer.
 * Uses strict TLS verification (never disabled).
 * Credentials are read from private config and never logged or printed.
 */
function send_password_reset_email($recipientEmail, $recipientName, $resetUrl, $config = null) {
    if ($config === null) {
        $config = get_mail_config();
    }

    $smtp = $config['smtp'] ?? [];
    $password = $smtp['password'] ?? '';

    // If password is not configured yet, fail safely without attempting connection
    if (empty($password)) {
        return [
            'success' => false,
            'error'   => 'SMTP password is not configured in private configuration.'
        ];
    }

    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return [
            'success' => false,
            'error'   => 'PHPMailer library is not loaded.'
        ];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['username'] ?? 'alerto.coeasc.csu@gmail.com';
        $mail->Password   = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($smtp['port'] ?? 587);
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        // Strictly enforce TLS verification - NEVER disabled
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ];

        $fromEmail = $smtp['from_email'] ?? 'alerto.coeasc.csu@gmail.com';
        $fromName  = $smtp['from_name'] ?? 'ALERTO';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipientEmail, $recipientName ?: 'Student/Staff');

        $mail->isHTML(true);
        $mail->Subject = 'ALERTO - Password Reset Request';

        $safeName = htmlspecialchars($recipientName ?: 'User', ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
              <meta charset='UTF-8'>
              <title>Reset Your Password</title>
            </head>
            <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f6f7f9; margin: 0; padding: 24px; color: #1e293b;'>
              <div style='max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05);'>
                <div style='background: linear-gradient(135deg, #700d23 0%, #4a0917 100%); padding: 28px; text-align: center;'>
                  <h1 style='color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: 0.5px;'>ALERTO</h1>
                  <p style='color: #fbcfe8; margin: 4px 0 0; font-size: 13px;'>CSU-Carig Student Council &bull; COEA</p>
                </div>
                <div style='padding: 32px;'>
                  <h2 style='margin-top: 0; color: #0f172a; font-size: 18px;'>Password Reset Request</h2>
                  <p style='font-size: 14px; line-height: 1.6; color: #334155;'>
                    Hello <strong>{$safeName}</strong>,
                  </p>
                  <p style='font-size: 14px; line-height: 1.6; color: #334155;'>
                    We received a request to set or update the password for your ALERTO account. Click the button below to proceed:
                  </p>
                  <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$safeUrl}' style='display: inline-block; background: #700d23; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: 600; font-size: 14px; box-shadow: 0 2px 4px rgba(112, 13, 35, 0.3);'>
                      Reset Password
                    </a>
                  </div>
                  <p style='font-size: 13px; line-height: 1.5; color: #64748b;'>
                    <strong>Security Notice:</strong> This link is single-use and will expire in <strong>30 minutes</strong>. If you did not make this request, you can safely ignore this email; your existing password will remain unchanged.
                  </p>
                  <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;'>
                  <p style='font-size: 11px; line-height: 1.4; color: #94a3b8; word-break: break-all;'>
                    If the button above does not work, copy and paste this link into your browser:<br>
                    <a href='{$safeUrl}' style='color: #700d23;'>{$safeUrl}</a>
                  </p>
                </div>
                <div style='background: #f8fafc; padding: 16px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b;'>
                  &copy; " . date('Y') . " ALERTO CSU-COEA Student Council. All rights reserved.
                </div>
              </div>
            </body>
            </html>
        ";

        $mail->AltBody = "Hello {$recipientName},\n\n"
                       . "We received a request to set or update your ALERTO account password.\n\n"
                       . "Please visit the following link to set your password:\n"
                       . $resetUrl . "\n\n"
                       . "This link is single-use and will expire in 30 minutes.\n"
                       . "If you did not make this request, please ignore this email.\n\n"
                       . "ALERTO CSU-COEA Student Council";

        $mail->send();
        return ['success' => true];

    } catch (PHPMailerException $e) {
        // Safe error description without credential leakage
        return [
            'success' => false,
            'error'   => 'PHPMailer Error: ' . $mail->ErrorInfo
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error'   => 'General Mail Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Validate a password reset token from URL or form.
 * Looks up token by SHA-256 hash.
 * Rejects missing, invalid, expired, or previously consumed tokens.
 */
function validate_reset_token($pdo, $plainToken) {
    $plainToken = trim($plainToken ?? '');

    if (empty($plainToken) || strlen($plainToken) < 16) {
        return [
            'valid'   => false,
            'message' => 'Missing or invalid password reset link. Please request a new one.'
        ];
    }

    $tokenHash = hash('sha256', $plainToken);

    try {
        // Check for active token
        $stmt = $pdo->prepare("
            SELECT r.id, r.user_id, r.email, r.expires_at, r.used_at,
                   u.full_name, u.role, u.status, u.student_id
            FROM password_resets r
            JOIN users u ON r.user_id = u.id
            WHERE r.token_hash = ?
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            return [
                'valid'   => false,
                'message' => 'This password reset link is invalid or does not exist. Please request a new one.'
            ];
        }

        if (!empty($entry['used_at'])) {
            return [
                'valid'   => false,
                'message' => 'This password reset link has already been used. Please request a new one.'
            ];
        }

        if (strtotime($entry['expires_at']) <= time()) {
            return [
                'valid'   => false,
                'message' => 'This password reset link has expired (valid for 30 minutes). Please request a new one.'
            ];
        }

        if (isset($entry['status']) && $entry['status'] === 'banned') {
            return [
                'valid'   => false,
                'message' => 'This account has been deactivated. Please contact an administrator.'
            ];
        }

        return [
            'valid' => true,
            'entry' => $entry
        ];

    } catch (Exception $e) {
        error_log("Token validation database error: " . $e->getMessage());
        return [
            'valid'   => false,
            'message' => 'A system error occurred while validating your reset link. Please try again.'
        ];
    }
}

/**
 * Atomically consume token and update the user's password.
 * - Enforces minimum 8 characters and password confirmation
 * - Atomically marks token as used (single-use)
 * - Updates user's password with password_hash(PASSWORD_BCRYPT)
 * - Preserves user's role and account status
 * - No automatic login
 */
function consume_token_and_reset_password($pdo, $plainToken, $newPassword, $confirmPassword, $csrfToken) {
    // 1. Verify CSRF
    if (!verify_csrf_token($csrfToken)) {
        return [
            'success' => false,
            'message' => 'Invalid or expired session token. Please refresh the page and try again.'
        ];
    }

    // 2. Validate passwords server-side
    $newPassword = (string)$newPassword;
    $confirmPassword = (string)$confirmPassword;

    if (empty($newPassword) || empty($confirmPassword)) {
        return [
            'success' => false,
            'message' => 'Please fill in both password fields.'
        ];
    }

    if ($newPassword !== $confirmPassword) {
        return [
            'success' => false,
            'message' => 'Passwords do not match. Please verify and try again.'
        ];
    }

    if (strlen($newPassword) < 8) {
        return [
            'success' => false,
            'message' => 'Password must be at least 8 characters long.'
        ];
    }

    $plainToken = trim($plainToken ?? '');
    if (empty($plainToken)) {
        return [
            'success' => false,
            'message' => 'Missing password reset token. Please request a new link.'
        ];
    }

    $tokenHash = hash('sha256', $plainToken);

    try {
        $pdo->beginTransaction();

        // 3. Select and lock the token row
        $stmt = $pdo->prepare("
            SELECT r.id, r.user_id, r.email, r.expires_at, r.used_at, u.id as u_id, u.role, u.status 
            FROM password_resets r
            JOIN users u ON r.user_id = u.id
            WHERE r.token_hash = ?
            FOR UPDATE
        ");
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'This password reset link is invalid or does not exist.'
            ];
        }

        if (!empty($row['used_at'])) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'This password reset link has already been used. Please request a new one.'
            ];
        }

        if (strtotime($row['expires_at']) <= time()) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'This password reset link has expired (valid for 30 minutes). Please request a new one.'
            ];
        }

        if (isset($row['status']) && $row['status'] === 'banned') {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'This account has been deactivated. Please contact an administrator.'
            ];
        }

        // 4. Mark token as used
        $stmtUse = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
        $stmtUse->execute([$row['id']]);

        // 5. Invalidate all other pending tokens for this user
        $stmtInv = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $stmtInv->execute([$row['user_id']]);

        // 6. Hash new password securely
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        // 7. Update user password (strictly preserving role and account status!)
        $stmtUser = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmtUser->execute([$hashedPassword, $row['user_id']]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Your password has been successfully set! You can now log in with your new password.'
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Password reset transaction error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while updating your password. Please try again.'
        ];
    }
}
