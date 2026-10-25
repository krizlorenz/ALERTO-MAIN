<?php
// ALERTO Password Recovery Service

require_once __DIR__ . '/alerto-db.php';
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Load private mail configuration
function get_mail_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $candidates = [
        __DIR__ . '/private/alerto-mail-config.php',
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

// CSRF token
if (!function_exists('get_csrf_token')) {
    function get_csrf_token() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// CSRF token verification
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Client IP
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}

// Password reset throttle check
function check_password_reset_throttling($pdo, $email, $ip) {
    $windowMinutes = 15;
    $maxPerEmail = 3;
    $maxPerIp = 10;

    try {

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

        error_log("Throttling check error: " . $e->getMessage());
        return ['allowed' => true];
    }
}

// Record reset throttle attempt
function record_password_reset_attempt($pdo, $email, $ip) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_throttles (email, ip_address, requested_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$email, $ip]);


        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM password_reset_throttles WHERE requested_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        }
    } catch (Exception $e) {
        error_log("Failed to record reset throttle attempt: " . $e->getMessage());
    }
}

// Password reset request
function request_password_reset($pdo, $email, $csrfToken) {
    $email = trim($email ?? '');
    $ip = get_client_ip();

    // CSRF check
    if (!verify_csrf_token($csrfToken)) {
        return [
            'success' => false,
            'message' => 'Invalid or expired session token. Please refresh the page and try again.'
        ];
    }

    // Input validation
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

    // Rate limiting
    $throttle = check_password_reset_throttling($pdo, $email, $ip);
    if (!$throttle['allowed']) {
        return [
            'success' => false,
            'message' => $throttle['message']
        ];
    }


    record_password_reset_attempt($pdo, $email, $ip);


    $genericSuccessMessage = 'If an account is associated with this email address, instructions to reset your password have been sent.';

    try {
        // Lookup user
        $stmt = $pdo->prepare("
            SELECT id, email, full_name, role, status 
            FROM users 
            WHERE LOWER(email) = LOWER(?) 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$user || (isset($user['status']) && $user['status'] === 'banned')) {

            usleep(random_int(100000, 250000));
            return [
                'success' => true,
                'message' => $genericSuccessMessage
            ];
        }

        // Generate token
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        // Invalidate previous tokens
        $stmtInv = $pdo->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE (user_id = ? OR LOWER(email) = LOWER(?)) AND used_at IS NULL
        ");
        $stmtInv->execute([$user['id'], $user['email']]);

        // Store new token
        $stmtIns = $pdo->prepare("
            INSERT INTO password_resets (user_id, email, token_hash, created_at, expires_at) 
            VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))
        ");
        $stmtIns->execute([$user['id'], $user['email'], $tokenHash]);

        // Build reset URL
        $config = get_mail_config();
        $appUrl = rtrim($config['app']['url'] ?? 'http://localhost/ALERTO-MAIN', '/\\');
        $resetUrl = $appUrl . '/reset-password.php?token=' . urlencode($plainToken);

        // Send reset email
        $sendResult = send_password_reset_email($user['email'], $user['full_name'], $resetUrl, $config);

        if (!$sendResult['success']) {

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

// Send password reset email via PHPMailer
function send_password_reset_email($recipientEmail, $recipientName, $resetUrl, $config = null) {
    if ($config === null) {
        $config = get_mail_config();
    }

    $smtp = $config['smtp'] ?? [];
    $password = $smtp['password'] ?? '';


    if (empty($password)) {
        return [
            'success' => false,
            'error'   => 'SMTP password is not configured in private configuration.'
        ];
    }

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
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


        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ];

        $fromEmail = $smtp['from_email'] ?? 'alerto.coeasc.csu@gmail.com';
        $fromName  = $smtp['from_name'] ?? 'ALERTO';


        $domain = substr(strrchr($fromEmail, "@"), 1) ?: 'gmail.com';
        $mail->Hostname = $domain;

        $pdoInstance = $GLOBALS['pdo'] ?? null;
        $sysSettings = ($pdoInstance && function_exists('get_system_settings')) ? get_system_settings($pdoInstance) : [];
        $supportEmail = $sysSettings['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
        $supportPhone = $sysSettings['contact_phone'] ?? '09556678451';

        $mail->setFrom($fromEmail, $fromName);
        if (!empty($supportEmail) && filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($supportEmail, 'ALERTO Support');
        }
        $mail->addAddress($recipientEmail, $recipientName ?: 'Student/Staff');

        $mail->isHTML(true);
        $mail->Subject = 'ALERTO - Password Reset Request';

        $safeName         = htmlspecialchars($recipientName ?: 'User', ENT_QUOTES, 'UTF-8');
        $safeUrl          = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $safeSupportEmail = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');
        $safeSupportPhone = htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8');

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
                  <p style='color: #fbcfe8; margin: 4px 0 0; font-size: 13px;'> COEA Student Council &bull; CSU-Carig</p>
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
                  <p style='font-size: 13px; line-height: 1.5; color: #64748b; margin-top: 10px;'>
                    <strong>Need Help?</strong> Contact the COEASC DRRM Department at <a href='mailto:{$safeSupportEmail}' style='color: #700d23; font-weight: 600;'>{$safeSupportEmail}</a> or phone <strong>{$safeSupportPhone}</strong>.
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
                       . "Support: {$supportEmail} | {$supportPhone}\n\n"
                       . "ALERTO CSU-COEA Student Council";

        $mail->send();
        return ['success' => true];

    } catch (PHPMailerException $e) {

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

// Validate password reset token
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

        $stmt = $pdo->prepare("
            SELECT r.id, r.user_id, r.email, r.expires_at, (r.expires_at <= NOW()) as is_expired, r.used_at,
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

        if ((int)$entry['is_expired'] === 1) {
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

// Atomically consume token and update password
function consume_token_and_reset_password($pdo, $plainToken, $newPassword, $confirmPassword, $csrfToken) {
    // CSRF check
    if (!verify_csrf_token($csrfToken)) {
        return [
            'success' => false,
            'message' => 'Invalid or expired session token. Please refresh the page and try again.'
        ];
    }

    // Validate passwords
    $newPassword = (string)$newPassword;
    $confirmPassword = (string)$confirmPassword;

    if (empty($newPassword) || empty($confirmPassword)) {
        return [
            'success' => false,
            'message' => 'Please fill in both password fields.'
        ];
    }

    if (strlen($newPassword) < 8) {
        return [
            'success' => false,
            'message' => 'Password must be at least 8 characters long.'
        ];
    }

    if ($newPassword !== $confirmPassword) {
        return [
            'success' => false,
            'message' => 'Passwords do not match. Please verify and try again.'
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

        // Lock token row
        $stmt = $pdo->prepare("
            SELECT r.id, r.user_id, r.email, r.expires_at, (r.expires_at <= NOW()) as is_expired, r.used_at, u.id as u_id, u.full_name, u.email as user_email, u.student_id, u.role, u.status 
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

        if ((int)$row['is_expired'] === 1) {
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

        // Mark token as used
        $stmtUse = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
        $stmtUse->execute([$row['id']]);

        // Invalidate other pending tokens
        $stmtInv = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $stmtInv->execute([$row['user_id']]);

        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update user password
        $stmtUser = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmtUser->execute([$hashedPassword, $row['user_id']]);

        // Clear login security record for this user/IP
        require_once __DIR__ . '/login-security.php';
        $resetIp = get_server_observed_ip();
        $userHash = compute_login_identifier_hash_for_user($row);
        $cleared = clear_login_security_for_user_ip($pdo, (int)$row['user_id'], $resetIp, $userHash);

        if (!$cleared) {
            $pdo->rollBack();
            error_log("Password reset failed: clear_login_security_for_user_ip failed for user ID {$row['user_id']}");
            return [
                'success' => false,
                'message' => 'An error occurred while updating your password. Please try again.'
            ];
        }

        $pdo->commit();


        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$row['user_id']) {
            unset($_SESSION['user_id'], $_SESSION['auth_password_hash'], $_SESSION['role'], $_SESSION['full_name'], $_SESSION['email']);
        }

        // Send confirmation email
        $recipientEmail = !empty($row['user_email']) ? $row['user_email'] : $row['email'];
        $recipientName  = $row['full_name'] ?? '';
        send_password_change_confirmation($pdo, $row['id'], $row['user_id'], $recipientEmail, $recipientName);

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

// Post-reset security confirmation email
function send_password_change_confirmation($pdo, $resetId, $userId, $recipientEmail, $recipientName = '') {

    if (!function_exists('dispatch_alerto_email')) {
        require_once __DIR__ . '/notification-service.php';
    }


    if (function_exists('has_notification_been_sent') && has_notification_been_sent($pdo, 'password_changed_confirmation', $resetId, 'changed', $recipientEmail)) {
        return false;
    }

    $config = get_mail_config();
    $appUrl = rtrim($config['app']['url'] ?? 'http://localhost/ALERTO-MAIN', '/');
    $forgotPasswordUrl = $appUrl . '/user-login.php?action=forgot-password';

    $sysSettings = get_system_settings($pdo);
    $supportEmail = $sysSettings['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
    $supportPhone = $sysSettings['contact_phone'] ?? '09556678451';


    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $changeTimeFormatted = $now->format('F j, Y, g:i:s A') . ' (Asia/Manila, GMT+8)';

    $subject = 'ALERTO - Your password was changed';
    $safeName = htmlspecialchars($recipientName ?: 'Student/Staff', ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8');
    $safeSupportEmail = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');
    $safeSupportPhone = htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8');
    $safeForgotUrl = htmlspecialchars($forgotPasswordUrl, ENT_QUOTES, 'UTF-8');

    $bodyHtml = "
      <p style='margin-top: 0; font-size: 15px;'>Hello <strong>{$safeName}</strong>,</p>
      <p style='line-height: 1.6;'>
        This is a security confirmation that the password for your ALERTO account (<strong>{$safeEmail}</strong>) was successfully changed on:
      </p>
      <div style='background: #f1f5f9; border-left: 4px solid #700d23; padding: 12px 16px; margin: 16px 0; font-family: monospace; font-size: 13px; color: #0f172a;'>
        <strong>Time:</strong> {$changeTimeFormatted}
      </div>
      <p style='line-height: 1.6;'>
        All existing active sessions across other browsers and devices have been invalidated. You must log in using your new password.
      </p>
      <div style='background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin: 20px 0;'>
        <p style='margin: 0 0 8px; font-weight: 700; color: #991b1b; font-size: 14px;'>Did you not make this change?</p>
        <p style='margin: 0; font-size: 13px; line-height: 1.5; color: #7f1d1d;'>
          If you did not perform this password change, your account may be compromised. Please immediately secure your account by submitting a fresh password reset request and securing your personal email account credentials.
        </p>
      </div>
      <p style='font-size: 13px; line-height: 1.5; color: #64748b;'>
        <strong>Support Contact:</strong> If you need assistance, please contact the CSU-COEA Student Council DRRM committee at 
        <a href='mailto:{$safeSupportEmail}' style='color: #700d23; font-weight: 600;'>{$safeSupportEmail}</a> or phone <strong>{$safeSupportPhone}</strong>.
      </p>
      <p style='font-size: 12px; line-height: 1.5; color: #94a3b8; margin-top: 14px;'>
        <em>Note: Clicking the button below opens the Forgot Password request form. It will not change your password or lock your account.</em>
      </p>
    ";

    if (function_exists('build_alerto_email_html')) {
        $htmlContent = build_alerto_email_html(
            'Security Alert: Password Changed',
            'Security Notice',
            '#700d23',
            $bodyHtml,
            'Secure your account',
            $forgotPasswordUrl
        );
    } else {
        $htmlContent = "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><title>{$subject}</title></head>
<body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f6f7f9; margin: 0; padding: 24px; color: #1e293b;'>
  <div style='max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.06);'>
    <div style='background: linear-gradient(135deg, #700d23 0%, #4a0917 100%); padding: 26px 30px; text-align: center;'>
      <h1 style='color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 0.5px;'>ALERTO</h1>
      <p style='color: #fbcfe8; margin: 4px 0 0; font-size: 13px; font-weight: 500;'>COEA Student Council &bull; CSU-Carig</p>
    </div>
    <div style='padding: 32px;'>
      <h2 style='margin-top: 0; color: #0f172a; font-size: 18px; font-weight: 700;'>Security Alert: Password Changed</h2>
      {$bodyHtml}
      <div style='text-align: center; margin: 28px 0;'>
        <a href='{$safeForgotUrl}' style='display: inline-block; background: #700d23; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: 600; font-size: 14px;'>
          Secure your account &rarr;
        </a>
      </div>
      <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;'>
      <p style='font-size: 12px; line-height: 1.5; color: #64748b; margin: 0;'>
        <strong>Note:</strong> Clicking this link takes you to the Forgot Password page where you can request a new reset if unauthorized. It will never directly change your password or lock your account.
      </p>
    </div>
    <div style='background: #f8fafc; padding: 16px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8;'>
      &copy; " . date('Y') . " ALERTO &bull; College of Engineering & Architecture Student Council &bull; CSU-Carig
    </div>
  </div>
</body>
</html>";
    }

    $plainTextContent = "Hello {$recipientName},\n\n"
        . "This is a security confirmation that the password for your ALERTO account ({$recipientEmail}) was successfully changed on:\n"
        . "Time: {$changeTimeFormatted}\n\n"
        . "All existing active sessions across other browsers and devices have been invalidated. You must log in using your new password.\n\n"
        . "DID YOU NOT MAKE THIS CHANGE?\n"
        . "If you did not perform this password change, your account may be compromised. Please immediately secure your account by submitting a fresh password reset request and securing your personal email account credentials.\n\n"
        . "Secure your account: {$forgotPasswordUrl}\n\n"
        . "SUPPORT CONTACT:\n"
        . "Email: {$supportEmail}\n"
        . "Phone: {$supportPhone}\n\n"
        . "Note: Clicking the link above navigates to the Forgot Password request page. It does not automatically change passwords or lock your account.\n\n"
        . "ALERTO CSU-COEA Student Council";

    try {
        $dispatch = dispatch_alerto_email($recipientEmail, $recipientName, $subject, $htmlContent, $plainTextContent, $config);

        $status = ($dispatch['success'] ?? false) ? 'sent' : 'failed';
        $errorMsg = $dispatch['error'] ?? null;

        if (function_exists('record_notification_log')) {
            record_notification_log($pdo, 'password_changed_confirmation', $resetId, 'changed', $recipientEmail, $subject, $status, $errorMsg, 1);
        }

        return ($status === 'sent');
    } catch (Exception $e) {
        error_log("Failed to send password changed confirmation: " . $e->getMessage());
        if (function_exists('record_notification_log')) {
            record_notification_log($pdo, 'password_changed_confirmation', $resetId, 'changed', $recipientEmail, $subject, 'failed', $e->getMessage(), 1);
        }
        return false;
    }
}