<?php
/**
 * ALERTO Notification Service
 * Dispatches transactional email notifications for:
 * 1. Student verification submissions -> Configured admin recipients
 * 2. Admin verification/rejection/ban status changes -> Student registered email
 * 3. Student assistance requests -> Configured admin recipients
 * 4. Admin assistance request status changes (approved/rejected/in_progress/completed) -> Student registered email
 *
 * Designed with:
 * - Reused PHPMailer configuration with strict TLS verification.
 * - Configurable admin recipients in private config.
 * - Testing recipient override support.
 * - Database deduplication to prevent duplicates from page refreshes.
 * - Resilient failure handling: mail issues never roll back or break web actions.
 * - Privacy protection: never leaks passwords, tokens, documents, or precise locations.
 */

require_once __DIR__ . '/alerto-db.php';
require_once __DIR__ . '/password-recovery.php'; // Reuses get_mail_config()

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Check if a notification for this exact event, reference, state, submission version, and recipient has already been sent.
 */
function has_notification_been_sent($pdo, $eventType, $referenceId, $transitionState, $recipientEmail = null, $submissionVersion = null) {
    try {
        $sql = "SELECT id FROM email_notifications WHERE event_type = ? AND reference_id = ? AND transition_state = ? AND status = 'sent'";
        $params = [$eventType, (int)$referenceId, (string)$transitionState];

        if ($submissionVersion !== null) {
            $sql .= " AND submission_version = ?";
            $params[] = (int)$submissionVersion;
        }

        if ($recipientEmail !== null) {
            $config = get_mail_config();
            $override = trim($config['notifications']['recipient_override'] ?? $config['recipient_override'] ?? '');
            $targetRecipient = (!empty($override) && filter_var($override, FILTER_VALIDATE_EMAIL)) ? $override : $recipientEmail;

            $sql .= " AND LOWER(recipient_email) = LOWER(?)";
            $params[] = trim($targetRecipient);
        }

        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Notification dedup check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Record a notification attempt (sent or failed) in the database with persistent submission version.
 */
function record_notification_log($pdo, $eventType, $referenceId, $transitionState, $recipientEmail, $subject, $status, $errorMessage = null, $submissionVersion = 1) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO email_notifications (event_type, reference_id, transition_state, submission_version, recipient_email, subject, status, error_message, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $eventType,
            (int)$referenceId,
            (string)$transitionState,
            (int)$submissionVersion,
            $recipientEmail,
            $subject,
            $status,
            $errorMessage
        ]);
    } catch (Exception $e) {
        error_log("Failed to record email_notification log: " . $e->getMessage());
    }
}

/**
 * Low-level dispatch helper using PHPMailer and private configuration.
 */
function dispatch_alerto_email($recipientEmail, $recipientName, $subject, $htmlContent, $plainTextContent, $config = null) {
    if ($config === null) {
        $config = get_mail_config();
    }

    $smtp = $config['smtp'] ?? [];
    $password = $smtp['password'] ?? '';

    // Check for test recipient override
    $override = trim($config['notifications']['recipient_override'] ?? $config['recipient_override'] ?? '');
    $effectiveRecipientEmail = $recipientEmail;
    $effectiveRecipientName = $recipientName;
    $isTestOverride = false;

    if (!empty($override) && filter_var($override, FILTER_VALIDATE_EMAIL)) {
        $effectiveRecipientEmail = $override;
        $effectiveRecipientName = "[TEST] " . $recipientName;
        $isTestOverride = true;

        $overrideBannerHtml = "
            <div style='background: #fef3c7; border: 1px solid #f59e0b; padding: 10px 14px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; color: #92400e;'>
                <strong>[ALERTO TEST OVERRIDE ACTIVE]</strong><br>
                Originally addressed to: <code>" . htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8') . "</code> (" . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ")
            </div>
        ";
        $htmlContent = str_replace('<!-- TEST_OVERRIDE_BANNER -->', $overrideBannerHtml, $htmlContent);

        $overrideBannerText = "[ALERTO TEST OVERRIDE ACTIVE]\n"
                            . "Originally addressed to: {$recipientEmail} ({$recipientName})\n"
                            . "--------------------------------------------------------\n\n";
        $plainTextContent = $overrideBannerText . $plainTextContent;
    } else {
        $htmlContent = str_replace('<!-- TEST_OVERRIDE_BANNER -->', '', $htmlContent);
    }

    // Optional dry-run support for test execution without sending real emails
    if (!empty($config['dry_run']) || !empty($config['notifications']['dry_run']) || !empty($GLOBALS['alerto_dry_run']) || (defined('ALERTO_DRY_RUN') && ALERTO_DRY_RUN)) {
        return [
            'success'             => true,
            'override_used'       => $isTestOverride,
            'effective_recipient' => $effectiveRecipientEmail,
            'message_id'          => '<dry-run-' . bin2hex(random_bytes(8)) . '@gmail.com>',
        ];
    }

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

        // Enforce RFC 5322 compliant FQDN for Message-ID and HELO
        $fromEmail = $smtp['from_email'] ?? 'alerto.coeasc.csu@gmail.com';
        $fromName  = $smtp['from_name'] ?? 'ALERTO';
        $domain = substr(strrchr($fromEmail, "@"), 1) ?: 'gmail.com';
        $mail->Hostname = $domain;

        // Strictly enforce TLS verification - NEVER disabled
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ];

        $mail->setFrom($fromEmail, $fromName);

        // Dynamic Support Reply-To
        $pdoInstance = $GLOBALS['pdo'] ?? null;
        $sysSettings = ($pdoInstance && function_exists('get_system_settings')) ? get_system_settings($pdoInstance) : [];
        $supportEmail = $sysSettings['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
        if (!empty($supportEmail) && filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($supportEmail, 'ALERTO Support');
        }

        $mail->addAddress($effectiveRecipientEmail, $effectiveRecipientName ?: 'ALERTO User');

        // Branded HTML email with plain-text alternative (multipart/alternative)
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlContent;
        $mail->AltBody = $plainTextContent;

        $mail->send();
        $messageId = $mail->getLastMessageID();
        return [
            'success'             => true,
            'override_used'       => $isTestOverride,
            'effective_recipient' => $effectiveRecipientEmail,
            'message_id'          => $messageId,
        ];

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

/**
 * Standard ALERTO Email Wrapper Template
 */
function build_alerto_email_html($heading, $statusBadgeText, $statusBadgeColor, $bodyHtml, $ctaText, $ctaUrl, $supportEmail = null, $supportPhone = null) {
    if ($supportEmail === null || $supportPhone === null) {
        $pdoInstance = $GLOBALS['pdo'] ?? null;
        $sys = ($pdoInstance && function_exists('get_system_settings')) ? get_system_settings($pdoInstance) : [];
        if ($supportEmail === null) {
            $supportEmail = $sys['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
        }
        if ($supportPhone === null) {
            $supportPhone = $sys['contact_phone'] ?? '09556678451';
        }
    }

    $safeHeading      = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeBadge        = htmlspecialchars($statusBadgeText, ENT_QUOTES, 'UTF-8');
    $safeCtaText      = htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8');
    $safeCtaUrl       = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
    $safeSupportEmail = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');
    $safeSupportPhone = htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8');

    return "<!DOCTYPE html>
<html>
<head>
  <meta charset='UTF-8'>
  <title>{$safeHeading}</title>
</head>
<body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f6f7f9; margin: 0; padding: 24px; color: #1e293b;'>
  <div style='max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.06);'>
    <!-- Header -->
    <div style='background: linear-gradient(135deg, #700d23 0%, #4a0917 100%); padding: 26px 30px; text-align: center;'>
      <h1 style='color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 0.5px;'>ALERTO</h1>
      <p style='color: #fbcfe8; margin: 4px 0 0; font-size: 13px; font-weight: 500;'>COEA Student Council &bull; CSU-Carig</p>
    </div>
    
    <!-- Content Area -->
    <div style='padding: 32px;'>
      <!-- TEST_OVERRIDE_BANNER -->
      
      <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
        <tr>
          <td style='vertical-align: middle;'>
            <h2 style='margin: 0; color: #0f172a; font-size: 18px; font-weight: 700;'>{$safeHeading}</h2>
          </td>
          " . (!empty($safeBadge) ? "<td style='text-align: right; vertical-align: middle;'><span style='display: inline-block; background: {$statusBadgeColor}; color: #ffffff; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;'>{$safeBadge}</span></td>" : "") . "
        </tr>
      </table>

          <div style='font-size: 14px; line-height: 1.6; color: #334155; margin-bottom: 24px;'>
            {$bodyHtml}
          </div>

          " . (!empty($safeCtaText) && !empty($safeCtaUrl) ? "
          <div style='text-align: center; margin: 28px 0;'>
            <a href='{$safeCtaUrl}' style='display: inline-block; background: #700d23; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: 600; font-size: 14px; box-shadow: 0 2px 4px rgba(112, 13, 35, 0.25);'>
              {$safeCtaText} &rarr;
            </a>
          </div>
          " : "") . "

          <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;'>
          <p style='font-size: 12px; line-height: 1.5; color: #64748b; margin: 0 0 6px 0;'>
            <strong>Support Contact:</strong> If you have questions or require assistance, please contact the COEASC DRRM Department at <a href='mailto:{$safeSupportEmail}' style='color: #700d23; font-weight: 600;'>{$safeSupportEmail}</a> or phone <strong>{$safeSupportPhone}</strong>.
          </p>
          <p style='font-size: 11px; line-height: 1.4; color: #94a3b8; margin: 0;'>
            <em>Note: This is an automated notification from the ALERTO Disaster Risk Reduction and Management (DRRM) system.</em>
          </p>
        </div>

        <!-- Footer -->
        <div style='background: #f8fafc; padding: 16px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8;'>
          &copy; " . date('Y') . " ALERTO &bull; College of Engineering & Architecture Student Council &bull; CSU-Carig
        </div>
      </div>
    </body>
    </html>
    ";
}

/**
 * 1. Student submits registration/verification -> Notify configured admin recipients.
 */
function notify_admin_student_registered($pdo, $studentUserId) {
    try {
        $stmt = $pdo->prepare("SELECT id, full_name, student_id, program, year_level, email, status, verification_version FROM users WHERE id = ?");
        $stmt->execute([(int)$studentUserId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            return false;
        }

        $submissionVersion = (int)($student['verification_version'] ?? 1);

        $config = get_mail_config();
        $adminRecipients = $config['notifications']['admin_recipients'] ?? $config['admin_recipients'] ?? [];
        if (empty($adminRecipients) || !is_array($adminRecipients)) {
            error_log("No admin_recipients configured for student registration alert.");
            return false;
        }

        $appUrl = rtrim($config['app']['url'] ?? 'http://localhost/ALERTO-MAIN', '/\\');
        $verifyUrl = $appUrl . '/admin-verify.php';

        $safeName     = htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8');
        $safeId       = htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8');
        $safeProgram  = htmlspecialchars($student['program'] . ' (' . $student['year_level'] . ')', ENT_QUOTES, 'UTF-8');
        $safeEmail    = htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8');

        $isResubmission = ($submissionVersion > 1);
        $heading = $isResubmission ? "Student Verification Resubmitted" : "New Student Verification Submitted";
        $subject = $isResubmission 
            ? "ALERTO - Student Verification Resubmitted: {$student['student_id']} ({$student['full_name']})"
            : "ALERTO - New Student Verification: {$student['student_id']} ({$student['full_name']})";

        $bodyHtml = "
            <p>" . ($isResubmission ? "A student has resubmitted their updated credentials and documents for identity verification:" : "A student has submitted their account credentials and documents for identity verification:") . "</p>
            <table style='width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;'>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600; width: 35%;'>Student Name:</td>
                <td style='padding: 8px 0; color: #0f172a; font-weight: 600;'>{$safeName}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Student ID:</td>
                <td style='padding: 8px 0; color: #0f172a;'>{$safeId}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Program & Level:</td>
                <td style='padding: 8px 0; color: #0f172a;'>{$safeProgram}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Registered Email:</td>
                <td style='padding: 8px 0; color: #0f172a;'>{$safeEmail}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Submission Version:</td>
                <td style='padding: 8px 0; color: #0f172a;'>v{$submissionVersion}</td>
              </tr>
              <tr>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Current Status:</td>
                <td style='padding: 8px 0; color: #d97706; font-weight: 600;'>Pending Verification</td>
              </tr>
            </table>
            <p>Please log in to the Administrative Verification portal to review the applicant's submission.</p>
        ";

        $plainText = ($isResubmission ? "ALERTO - Student Verification Resubmitted" : "ALERTO - New Student Verification Submitted") . "\n\n"
                   . "Student Name: {$student['full_name']}\n"
                   . "Student ID: {$student['student_id']}\n"
                   . "Program: {$student['program']} ({$student['year_level']})\n"
                   . "Email: {$student['email']}\n"
                   . "Submission Version: v{$submissionVersion}\n"
                   . "Status: Pending Verification\n\n"
                   . "Please review at: {$verifyUrl}\n\n"
                   . "ALERTO CSU-COEA Student Council";

        $htmlContent = build_alerto_email_html($heading, 'Pending', '#d97706', $bodyHtml, 'Open Verification Portal', $verifyUrl);

        // Send to configured admin recipients with per-submission and per-recipient deduplication
        $allSuccess = true;
        foreach ($adminRecipients as $adminEmail) {
            $adminEmail = trim($adminEmail);
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Deduplicate per submission version and recipient
            if (has_notification_been_sent($pdo, 'student_registration', $student['id'], 'unverified', $adminEmail, $submissionVersion)) {
                continue;
            }

            $res = dispatch_alerto_email($adminEmail, 'ALERTO Administrator', $subject, $htmlContent, $plainText, $config);
            $effectiveRecipient = $res['effective_recipient'] ?? $adminEmail;

            if ($res['success']) {
                $logMsg = !empty($res['message_id']) ? "Message-ID: " . $res['message_id'] : null;
                record_notification_log($pdo, 'student_registration', $student['id'], 'unverified', $effectiveRecipient, $subject, 'sent', $logMsg, $submissionVersion);
            } else {
                $allSuccess = false;
                record_notification_log($pdo, 'student_registration', $student['id'], 'unverified', $effectiveRecipient, $subject, 'failed', $res['error'] ?? 'Unknown error', $submissionVersion);
            }

            // If test override is active, one email is sufficient
            if (!empty($res['override_used'])) {
                break;
            }
        }

        return $allSuccess;

    } catch (Exception $e) {
        error_log("notify_admin_student_registered error: " . $e->getMessage());
        return false;
    }
}

/**
 * 2. Admin verifies/rejects/bans a student -> Notify that student's registered email.
 */
function notify_student_verification_status($pdo, $studentUserId, $newStatus, $oldStatus = null, $bypassDedup = false) {
    try {
        $validStatuses = ['verified', 'rejected', 'banned'];
        if (!in_array($newStatus, $validStatuses)) {
            return false;
        }

        // Only genuine transitions (unless explicit manual bypass)
        if (!$bypassDedup && $oldStatus !== null && strtolower(trim($oldStatus)) === strtolower(trim($newStatus))) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT id, full_name, student_id, email, verification_version FROM users WHERE id = ?");
        $stmt->execute([(int)$studentUserId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student || empty($student['email'])) {
            return false;
        }

        $submissionVersion = (int)($student['verification_version'] ?? 1);

        // Deduplication per submission version and recipient (unless explicit manual bypass)
        if (!$bypassDedup && has_notification_been_sent($pdo, 'student_verification_status', $student['id'], $newStatus, $student['email'], $submissionVersion)) {
            return ['success' => true, 'dedup_skipped' => true];
        }

        $config = get_mail_config();
        $appUrl = rtrim($config['app']['url'] ?? 'http://localhost/ALERTO-MAIN', '/\\');

        $sysSettings = function_exists('get_system_settings') ? get_system_settings($pdo) : [];
        $supportEmail = $sysSettings['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
        $supportPhone = $sysSettings['contact_phone'] ?? '09556678451';

        $safeName = htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8');
        $safeId   = htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8');

        if ($newStatus === 'verified') {
            $subject = "ALERTO - Your Account Has Been Verified";
            $heading = "Account Verification Approved";
            $badge   = "Verified";
            $color   = "#1e6b37";
            $ctaText = "Log In to Your Account";
            $ctaUrl  = $appUrl . '/user-login.php';
            $bodyHtml = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>We are pleased to inform you that your student verification (ID: <strong>{$safeId}</strong>) has been approved by the COEASC Administration.</p>
                <p>You can now sign in to access your student dashboard, view official advisories, and submit assistance requests whenever emergency support is needed.</p>
            ";
            $plainText = "Hello {$student['full_name']},\n\nYour ALERTO account ({$student['student_id']}) has been verified. You can now log in at: {$ctaUrl}\n\nSupport: {$supportEmail} | {$supportPhone}\nALERTO CSU-COEA Student Council";

        } elseif ($newStatus === 'rejected') {
            $subject = "ALERTO - Student Verification Update";
            $heading = "Verification Could Not Be Approved";
            $badge   = "Rejected";
            $color   = "#dc2626";
            $ctaText = "Review and Resubmit";
            $ctaUrl  = $appUrl . '/user-sign-in.php';
            $bodyHtml = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>Your student verification application (ID: <strong>{$safeId}</strong>) could not be approved at this time due to incomplete or unverifiable identification credentials.</p>
                <p>You may log in to the registration page to review your details and resubmit valid documentation.</p>
            ";
            $plainText = "Hello {$student['full_name']},\n\nYour ALERTO student verification ({$student['student_id']}) was rejected. You may resubmit credentials at: {$ctaUrl}\n\nSupport: {$supportEmail} | {$supportPhone}\nALERTO CSU-COEA Student Council";

        } elseif ($newStatus === 'banned') {
            $subject = "ALERTO - Account Status Update";
            $heading = "Account Deactivated";
            $badge   = "Deactivated";
            $color   = "#475569";
            $ctaText = "Contact Support";
            $ctaUrl  = $appUrl . '/user-login.php';
            $bodyHtml = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>Your ALERTO account associated with Student ID <strong>{$safeId}</strong> has been deactivated by an administrator.</p>
                <p>If you believe this action was made in error or need assistance, please contact the CSU-COEA Student Council office.</p>
            ";
            $plainText = "Hello {$student['full_name']},\n\nYour ALERTO account ({$student['student_id']}) has been deactivated. Please contact the administrator.\n\nSupport: {$supportEmail} | {$supportPhone}\nALERTO CSU-COEA Student Council";
        }

        $htmlContent = build_alerto_email_html($heading, $badge, $color, $bodyHtml, $ctaText, $ctaUrl, $supportEmail, $supportPhone);

        $res = dispatch_alerto_email($student['email'], $student['full_name'], $subject, $htmlContent, $plainText, $config);

        $effectiveRecipient = $res['effective_recipient'] ?? $student['email'];
        if ($res['success']) {
            $logMsg = !empty($res['message_id']) ? "Message-ID: " . $res['message_id'] : null;
            record_notification_log($pdo, 'student_verification_status', $student['id'], $newStatus, $effectiveRecipient, $subject, 'sent', $logMsg, $submissionVersion);
            return $res;
        } else {
            record_notification_log($pdo, 'student_verification_status', $student['id'], $newStatus, $effectiveRecipient, $subject, 'failed', $res['error'] ?? 'Unknown error', $submissionVersion);
            return $res;
        }

    } catch (Exception $e) {
        error_log("notify_student_verification_status error: " . $e->getMessage());
        return false;
    }
}

/**
 * 3. Student submits assistance request -> Notify configured admin recipients.
 */
function notify_admin_assistance_request($pdo, $requestId) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.request_code, r.resources, r.status, r.submitted_at AS created_at,
                   u.full_name, u.student_id, u.email, u.program, u.year_level
            FROM assistance_requests r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([(int)$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            error_log("notify_admin_assistance_request error: Request record ID {$requestId} not found.");
            record_notification_log($pdo, 'assistance_request_submitted', (int)$requestId, 'pending', 'admins', 'ALERTO - New Assistance Request', 'failed', 'Request record not found');
            return false;
        }

        // Deduplication
        if (has_notification_been_sent($pdo, 'assistance_request_submitted', $req['id'], 'pending')) {
            return true;
        }

        $config = get_mail_config();
        $adminRecipients = $config['notifications']['admin_recipients'] ?? $config['admin_recipients'] ?? [];
        if (empty($adminRecipients) || !is_array($adminRecipients)) {
            $msg = "No admin_recipients configured for assistance request alert.";
            error_log($msg);
            record_notification_log($pdo, 'assistance_request_submitted', $req['id'], 'pending', 'admins', 'ALERTO - New Assistance Request', 'failed', $msg);
            return false;
        }

        $appUrl = rtrim($config['app']['url'] ?? 'http://localhost/ALERTO-MAIN', '/\\');
        $adminRequestUrl = $appUrl . '/admin-request.php';

        $safeCode    = htmlspecialchars($req['request_code'], ENT_QUOTES, 'UTF-8');
        $safeName    = htmlspecialchars($req['full_name'], ENT_QUOTES, 'UTF-8');
        $safeId      = htmlspecialchars($req['student_id'], ENT_QUOTES, 'UTF-8');
        $safeProgram = htmlspecialchars($req['program'] . ' (' . $req['year_level'] . ')', ENT_QUOTES, 'UTF-8');
        $safeType    = htmlspecialchars($req['resources'] ?: 'Emergency Assistance', ENT_QUOTES, 'UTF-8');

        $heading = "New Assistance Request Received";
        $subject = "ALERTO - New Assistance Request: {$req['request_code']} ({$req['full_name']})";

        $bodyHtml = "
            <p>A new student assistance request has been submitted and requires administration review:</p>
            <table style='width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;'>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600; width: 35%;'>Request Reference:</td>
                <td style='padding: 8px 0; color: #700d23; font-weight: 700;'>{$safeCode}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Student Name:</td>
                <td style='padding: 8px 0; color: #0f172a; font-weight: 600;'>{$safeName}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Student ID:</td>
                <td style='padding: 8px 0; color: #0f172a;'>{$safeId}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Program:</td>
                <td style='padding: 8px 0; color: #0f172a;'>{$safeProgram}</td>
              </tr>
              <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Resources Requested:</td>
                <td style='padding: 8px 0; color: #0f172a;'>{$safeType}</td>
              </tr>
              <tr>
                <td style='padding: 8px 0; color: #64748b; font-weight: 600;'>Initial Status:</td>
                <td style='padding: 8px 0; color: #d97706; font-weight: 600;'>Pending Review</td>
              </tr>
            </table>
            <p>Please log in to the Request Management portal to coordinate emergency resources and dispatch.</p>
        ";

        $plainText = "ALERTO - New Assistance Request Received\n\n"
                   . "Request Code: {$req['request_code']}\n"
                   . "Student: {$req['full_name']} ({$req['student_id']})\n"
                   . "Program: {$req['program']} ({$req['year_level']})\n"
                   . "Resources: {$req['resources']}\n"
                   . "Status: Pending Review\n\n"
                   . "Manage at: {$adminRequestUrl}\n\n"
                   . "ALERTO CSU-COEA Student Council";

        $htmlContent = build_alerto_email_html($heading, 'Pending', '#d97706', $bodyHtml, 'Open Request Management', $adminRequestUrl);

        $allSuccess = true;
        foreach ($adminRecipients as $adminEmail) {
            $adminEmail = trim($adminEmail);
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $res = dispatch_alerto_email($adminEmail, 'ALERTO Administrator', $subject, $htmlContent, $plainText, $config);
            $effectiveRecipient = $res['effective_recipient'] ?? $adminEmail;
            if ($res['success']) {
                $logMsg = !empty($res['message_id']) ? "Message-ID: " . $res['message_id'] : null;
                record_notification_log($pdo, 'assistance_request_submitted', $req['id'], 'pending', $effectiveRecipient, $subject, 'sent', $logMsg);
            } else {
                $allSuccess = false;
                record_notification_log($pdo, 'assistance_request_submitted', $req['id'], 'pending', $effectiveRecipient, $subject, 'failed', $res['error'] ?? 'Unknown error');
            }

            if (!empty($res['override_used'])) {
                break;
            }
        }

        return $allSuccess;

    } catch (Exception $e) {
        error_log("notify_admin_assistance_request error: " . $e->getMessage());
        try {
            record_notification_log(
                $pdo,
                'assistance_request_submitted',
                (int)$requestId,
                'pending',
                'admins',
                'ALERTO - New Assistance Request',
                'failed',
                'Pre-send error: ' . $e->getMessage()
            );
        } catch (Exception $logEx) {
            error_log("Failed to record pre-send failure log: " . $logEx->getMessage());
        }
        return false;
    }
}

/**
 * 4. Admin changes request to approved/rejected/in_progress/completed -> Notify student's registered email.
 */
function notify_student_request_status($pdo, $requestId, $newStatus, $oldStatus = null) {
    try {
        $allowedStatuses = ['approved', 'rejected', 'in_progress', 'completed'];
        if (!in_array($newStatus, $allowedStatuses)) {
            return false;
        }

        // Genuine transition only
        if ($oldStatus !== null && strtolower(trim($oldStatus)) === strtolower(trim($newStatus))) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT r.id, r.request_code, r.resources, r.status,
                   u.full_name, u.email, u.student_id
            FROM assistance_requests r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([(int)$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req || empty($req['email'])) {
            return false;
        }

        // Deduplication
        if (has_notification_been_sent($pdo, 'assistance_request_status', $req['id'], $newStatus)) {
            return true;
        }

        $config = get_mail_config();
        $appUrl = rtrim($config['app']['url'] ?? 'http://localhost/ALERTO-MAIN', '/\\');
        $studentPortalUrl = $appUrl . '/user-homepage.php';

        $sysSettings = function_exists('get_system_settings') ? get_system_settings($pdo) : [];
        $supportEmail = $sysSettings['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
        $supportPhone = $sysSettings['contact_phone'] ?? '09556678451';

        $safeName = htmlspecialchars($req['full_name'], ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($req['request_code'], ENT_QUOTES, 'UTF-8');
        $safeType = htmlspecialchars($req['resources'] ?: 'Assistance Request', ENT_QUOTES, 'UTF-8');

        if ($newStatus === 'approved') {
            $subject = "ALERTO - Assistance Request Approved ({$req['request_code']})";
            $heading = "Assistance Request Approved";
            $badge   = "Approved";
            $color   = "#2563eb";
            $bodyHtml = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>Your assistance request <strong>{$safeCode}</strong> ({$safeType}) has been <strong>approved</strong> by the COEASC DRRM Department.</p>
                <p>Your request has been queued for dispatch and relief operations. Please keep your communication line active.</p>
            ";
            $plainText = "Hello {$req['full_name']},\n\nYour assistance request {$req['request_code']} has been approved. View status: {$studentPortalUrl}\n\nSupport: {$supportEmail} | {$supportPhone}\nALERTO CSU-COEA Student Council";

        } elseif ($newStatus === 'in_progress') {
            $subject = "ALERTO - Assistance Request In Progress ({$req['request_code']})";
            $heading = "Dispatch In Progress";
            $badge   = "In Progress";
            $color   = "#d97706";
            $bodyHtml = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>Your assistance request <strong>{$safeCode}</strong> is now <strong>in progress</strong>.</p>
                <p>Emergency response personnel and resources are currently being deployed to assist you. Please stay in a safe location.</p>
            ";
            $plainText = "Hello {$req['full_name']},\n\nYour assistance request {$req['request_code']} is in progress (dispatched). View status: {$studentPortalUrl}\n\nSupport: {$supportEmail} | {$supportPhone}\nALERTO CSU-COEA Student Council";

        } elseif ($newStatus === 'completed') {
            $subject = "ALERTO - Assistance Request Completed ({$req['request_code']})";
            $heading = "Assistance Request Completed";
            $badge   = "Completed";
            $color   = "#1e6b37";
            $bodyHtml = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>Your assistance request <strong>{$safeCode}</strong> ({$safeType}) has been marked as <strong>completed</strong>.</p>
                <p>We hope you and your companions are safe. You can view your record or submit another request if further support is required.</p>
            ";
            $plainText = "Hello {$req['full_name']},\n\nYour assistance request {$req['request_code']} has been marked as completed.\n\nSupport: {$supportEmail} | {$supportPhone}\nALERTO CSU-COEA Student Council";

        } elseif ($newStatus === 'rejected') {
            $subject = "ALERTO - Assistance Request Update ({$req['request_code']})";
            $heading = "Assistance Request Declined";
            $badge   = "Declined";
            $color   = "#dc2626";
            $bodyHtml = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>We regret to inform you that your assistance request <strong>{$safeCode}</strong> could not be fulfilled at this time.</p>
                <p>Please check your student dashboard or contact the COEASC Student Council hotline if you require urgent alternative assistance.</p>
            ";
            $plainText = "Hello {$req['full_name']},\n\nYour assistance request {$req['request_code']} could not be approved at this time.\n\nSupport: {$supportEmail} | {$supportPhone}\nALERTO CSU-COEA Student Council";
        }

        $htmlContent = build_alerto_email_html($heading, $badge, $color, $bodyHtml, 'View Status in Dashboard', $studentPortalUrl, $supportEmail, $supportPhone);

        $res = dispatch_alerto_email($req['email'], $req['full_name'], $subject, $htmlContent, $plainText, $config);

        if ($res['success']) {
            record_notification_log($pdo, 'assistance_request_status', $req['id'], $newStatus, $req['email'], $subject, 'sent');
            return true;
        } else {
            record_notification_log($pdo, 'assistance_request_status', $req['id'], $newStatus, $req['email'], $subject, 'failed', $res['error'] ?? 'Unknown error');
            return false;
        }

    } catch (Exception $e) {
        error_log("notify_student_request_status error: " . $e->getMessage());
        return false;
    }
}
