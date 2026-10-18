<?php
// C:\laragon\www\ALERTO-MAIN\alerto-db.php
date_default_timezone_set('Asia/Manila');

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

    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

/**
 * Retrieve system settings (contact info, emergency intake state)
 */
function get_system_settings($pdo, $refresh = false) {
    static $cached = null;
    if ($cached !== null && !$refresh) {
        return $cached;
    }

    $defaults = [
        'contact_email'              => 'alerto.coeasc.csu@gmail.com',
        'contact_phone'              => '09556678451',
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
        ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = CURRENT_TIMESTAMP
    ");
    $result = $stmt->execute([$key, $value]);
    if ($result) {
        get_system_settings($pdo, true);
    }
    return $result;
}

/**
 * Generate or retrieve CSRF token.
 */
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

/**
 * Verify submitted CSRF token.
 */
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

/**
 * Validate that an active session belongs to a valid, active user whose password
 * has not been reset since login. If invalid, the session is destroyed and user redirected.
 */
function validate_active_session($pdo, $allowedRoles = []) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $loginUrl = (!empty($allowedRoles) && !in_array('student', $allowedRoles)) ? 'admin-login.php' : 'user-login.php';

    if (!isset($_SESSION['user_id'])) {
        header("Location: {$loginUrl}");
        exit;
    }

    if (!empty($allowedRoles) && (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles))) {
        header("Location: {$loginUrl}");
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, role, status, password, full_name, student_id, email, program, year_level, contact_number FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Invalidate session if user doesn't exist, is banned, or password changed since login
        if (!$user || (isset($user['status']) && $user['status'] === 'banned') ||
            !isset($_SESSION['auth_password_hash']) || $_SESSION['auth_password_hash'] !== $user['password']) {
            $_SESSION = array();
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            header("Location: {$loginUrl}");
            exit;
        }

        return $user;
    } catch (Exception $e) {
        error_log("validate_active_session error: " . $e->getMessage());
        return null;
    }
}
?>