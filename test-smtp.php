<?php
/**
 * ALERTO CLI-Only SMTP Verification Tool
 * Safe diagnostic script to verify PHPMailer & Gmail SMTP settings.
 * Does NOT send real emails unless credentials are configured and a test recipient is provided.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access Denied: This script can only be executed via the Command Line Interface (CLI).\n");
}

require_once __DIR__ . '/password-recovery.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

echo "========================================================\n";
echo "   ALERTO - CLI-ONLY SMTP CONFIGURATION CHECKER\n";
echo "========================================================\n";

$config = get_mail_config();
$smtp = $config['smtp'] ?? [];
$app = $config['app'] ?? [];

$host = $smtp['host'] ?? 'smtp.gmail.com';
$port = $smtp['port'] ?? 587;
$username = $smtp['username'] ?? 'alerto.coeasc.csu@gmail.com';
$password = $smtp['password'] ?? '';
$fromEmail = $smtp['from_email'] ?? 'alerto.coeasc.csu@gmail.com';
$fromName = $smtp['from_name'] ?? 'ALERTO';
$appUrl = $app['url'] ?? 'http://localhost/ALERTO-MAIN';

echo "SMTP Host       : {$host}\n";
echo "SMTP Port       : {$port} (STARTTLS)\n";
echo "SMTP Username   : {$username}\n";
echo "From Email      : {$fromEmail}\n";
echo "From Name       : {$fromName}\n";
echo "Configured APP_URL: {$appUrl}\n";
echo "TLS Verification: ENABLED (Strict verify_peer = true)\n";

$isConfigured = !empty(trim($password));
echo "Password Status : " . ($isConfigured ? "[CONFIGURED]" : "[EMPTY PLACEHOLDER]") . "\n";
echo "Config Location : C:\\laragon\\config\\alerto-mail-config.php\n";
echo "--------------------------------------------------------\n";

if (!$isConfigured) {
    echo "\n[INFO] SMTP App Password is currently empty.\n";
    echo "To configure your Gmail App Password:\n";
    echo "  1. Open C:\\laragon\\config\\alerto-mail-config.php in your text editor.\n";
    echo "  2. Enter your 16-character Google App Password into 'password' => '...'\n";
    echo "  3. Save the file.\n";
    echo "\nOnce configured, run:\n";
    echo "  php test-smtp.php your-email@example.com\n\n";
    exit(0);
}

$recipient = trim($argv[1] ?? '');

if (empty($recipient)) {
    echo "\n[WAITING] No test recipient provided.\n";
    echo "As requested, NO real emails are sent until you supply a test recipient.\n";
    echo "Usage:\n";
    echo "  php test-smtp.php <recipient-email>\n";
    echo "Example:\n";
    echo "  php test-smtp.php test@example.com\n\n";
    exit(0);
}

if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    echo "\n[ERROR] '{$recipient}' is not a valid email address.\n\n";
    exit(1);
}

echo "\nAttempting SMTP connection and test delivery to: {$recipient}...\n";

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $username;
    $mail->Password   = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$port;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;

    // Strict TLS verification
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ];

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipient);
    $mail->Subject = 'ALERTO - CLI SMTP Test Message';
    $mail->isHTML(true);

    $mail->Body = "
        <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
            <h2 style='color: #700d23;'>ALERTO SMTP Test Successful</h2>
            <p>This is a verification message sent from the ALERTO CLI diagnostic tool.</p>
            <p><strong>Configured APP_URL:</strong> " . htmlspecialchars($appUrl) . "</p>
            <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
        </div>
    ";
    $mail->AltBody = "ALERTO SMTP Test Successful.\nTimestamp: " . date('Y-m-d H:i:s');

    $mail->send();
    echo "[SUCCESS] Test email successfully sent to {$recipient}!\n";
    echo "SMTP handshake, STARTTLS encryption, and authentication succeeded.\n\n";
    exit(0);

} catch (PHPMailerException $e) {
    echo "[FAILED] PHPMailer Error: " . $mail->ErrorInfo . "\n\n";
    exit(1);
} catch (Exception $e) {
    echo "[FAILED] Mail Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
