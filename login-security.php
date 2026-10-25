<?php
// ALERTO Login Security Service

require_once __DIR__ . '/alerto-db.php';

// Normalize login identifier
function normalize_login_identifier($identifier) {
    $identifier = trim((string)$identifier);
    if (strpos($identifier, '@') !== false) {
        return 'email:' . strtolower($identifier);
    }
    return 'student:' . strtolower($identifier);
}

// SHA-256 identifier hash
function compute_login_identifier_hash($identifier) {
    return hash('sha256', normalize_login_identifier($identifier));
}

// Canonical identifier from user record
function canonical_identifier_for_user($user) {
    if (!is_array($user)) {
        return '';
    }
    $role = strtolower(trim($user['role'] ?? ''));
    if ($role === 'student' && !empty($user['student_id'])) {
        return 'student:' . strtolower(trim($user['student_id']));
    }
    if (!empty($user['email'])) {
        return 'email:' . strtolower(trim($user['email']));
    }
    if (!empty($user['student_id'])) {
        return 'student:' . strtolower(trim($user['student_id']));
    }
    return '';
}

// SHA-256 identifier hash for user record
function compute_login_identifier_hash_for_user($user) {
    $canonical = canonical_identifier_for_user($user);
    return !empty($canonical) ? hash('sha256', $canonical) : null;
}

// Canonical client IP from REMOTE_ADDR only
function get_server_observed_ip() {
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rawIp = trim((string)$rawIp);
    $binary = @inet_pton($rawIp);
    if ($binary !== false) {
        return inet_ntop($binary);
    }
    return '127.0.0.1';
}

// Cooldown duration label
function format_cooldown_duration_label($level) {
    if ($level === 1) {
        return '3-minute';
    }
    if ($level === 2) {
        return '30-minute';
    }
    return '24-hour';
}

// User-facing cooldown error message
function get_cooldown_error_message($level, $secondsRemaining) {
    if ($secondsRemaining <= 0) {
        return '';
    }

    if ($level === 1) {
        $minutes = (int)ceil($secondsRemaining / 60);
        if ($minutes <= 1) {
            return "Too many attempts. Please try again in 1 minute.";
        }
        return "Too many attempts. Please try again in {$minutes} minutes.";
    }

    if ($level === 2) {
        if ($secondsRemaining <= 60) {
            $bucket = 1;
        } elseif ($secondsRemaining <= 180) {
            $bucket = 3;
        } elseif ($secondsRemaining <= 300) {
            $bucket = 5;
        } elseif ($secondsRemaining <= 600) {
            $bucket = 10;
        } elseif ($secondsRemaining <= 1200) {
            $bucket = 20;
        } else {
            $bucket = 30;
        }

        if ($bucket === 1) {
            return "Too many attempts. Please try again in 1 minute.";
        }
        return "Too many attempts. Please try again in {$bucket} minutes.";
    }

    return "Too many attempts. Please try again later.";
}

// Acquire advisory lock for identifier/IP pair
function acquire_login_lock($pdo, $identifierHash, $ipAddress, $timeout = 10) {
    try {
        $lockKey = 'alerto_login_' . hash('sha256', $identifierHash . ':' . $ipAddress);
        $lockName = substr($lockKey, 0, 64);
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, ?)");
        $stmt->execute([$lockName, (int)$timeout]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log("acquire_login_lock error: " . $e->getMessage());
        return false;
    }
}

// Release advisory lock
function release_login_lock($pdo, $identifierHash, $ipAddress) {
    try {
        $lockKey = 'alerto_login_' . hash('sha256', $identifierHash . ':' . $ipAddress);
        $lockName = substr($lockKey, 0, 64);
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lockName]);
    } catch (Throwable $e) {
        error_log("release_login_lock error: " . $e->getMessage());
    }
}

// Login cooldown check
function check_login_cooldown($pdo, $identifierHash, $ipAddress, $nowTime = null) {
    $currentTime = ($nowTime !== null) ? (int)$nowTime : time();

    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, failed_attempts, cooldown_level, cooldown_until, cooldown_sequence, alert_status
            FROM login_security
            WHERE identifier_hash = ? AND ip_address = ?
            LIMIT 1
        ");
        $stmt->execute([$identifierHash, $ipAddress]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['cooldown_until'])) {
            return [
                'is_blocked' => false,
            ];
        }

        $cooldownUntilTs = strtotime($row['cooldown_until']);
        $secondsRemaining = $cooldownUntilTs - $currentTime;

        if ($secondsRemaining > 0) {
            $level = (int)$row['cooldown_level'];
            $errorMessage = get_cooldown_error_message($level, $secondsRemaining);
            return [
                'is_blocked'        => true,
                'error'             => $errorMessage,
                'level'             => $level,
                'seconds_remaining' => $secondsRemaining,
                'cooldown_sequence' => (int)$row['cooldown_sequence'],
            ];
        }

        return [
            'is_blocked' => false,
        ];
    } catch (Throwable $e) {
        error_log("check_login_cooldown database error: " . $e->getMessage());
        return [
            'is_blocked' => true,
            'error'      => 'Sign-in is temporarily unavailable. Please try again in a few moments.',
            'db_error'   => true,
        ];
    }
}

// Record failed login attempt and handle escalation
function record_login_failure_db($pdo, $identifierHash, $ipAddress, $user = null, $nowTime = null) {
    $currentTime = ($nowTime !== null) ? (int)$nowTime : time();
    $userId = ($user && !empty($user['id'])) ? (int)$user['id'] : null;

    try {
        $pdo->beginTransaction();

        // Ensure row exists
        $stmtIns = $pdo->prepare("
            INSERT INTO login_security (
                identifier_hash, ip_address, user_id, failed_attempts,
                cooldown_level, cooldown_until, cooldown_sequence, alert_status,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, 0, 0, NULL, 0, 'none', NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
        $stmtIns->execute([$identifierHash, $ipAddress, $userId]);

        // Lock row
        $stmtSel = $pdo->prepare("
            SELECT id, user_id, failed_attempts, cooldown_level, cooldown_until, cooldown_sequence, alert_status
            FROM login_security
            WHERE identifier_hash = ? AND ip_address = ?
            FOR UPDATE
        ");
        $stmtSel->execute([$identifierHash, $ipAddress]);
        $row = $stmtSel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return [
                'error'            => 'Sign-in is temporarily unavailable. Please try again in a few moments.',
                'cooldown_started' => false,
                'db_error'         => true,
            ];
        }

        // Return early if cooldown is still active
        if (!empty($row['cooldown_until'])) {
            $secRem = strtotime($row['cooldown_until']) - $currentTime;
            if ($secRem > 0) {
                $pdo->commit();
                $err = get_cooldown_error_message((int)$row['cooldown_level'], $secRem);
                return [
                    'error'            => $err,
                    'cooldown_started' => false,
                ];
            }
        }

        $currentLevel    = (int)$row['cooldown_level'];
        $attempts        = (int)$row['failed_attempts'] + 1;
        $cooldownStarted = false;
        $newLevel        = $currentLevel;
        $newSequence     = (int)$row['cooldown_sequence'];
        $durationSeconds = 0;
        $durationLabel   = '';

        if ($currentLevel === 0) {
            if ($attempts >= 5) {
                $cooldownStarted = true;
                $newLevel        = 1;
                $durationSeconds = 180; // 3 minutes
                $durationLabel   = '3-minute';
            }
        } else {
            if ($attempts >= 3) {
                $cooldownStarted = true;
                if ($currentLevel === 1) {
                    $newLevel        = 2;
                    $durationSeconds = 1800; // 30 minutes
                    $durationLabel   = '30-minute';
                } else {
                    $newLevel        = 3; // Capped at 3
                    $durationSeconds = 86400; // 24 hours
                    $durationLabel   = '24-hour';
                }
            }
        }

        if ($cooldownStarted) {
            $attempts         = 0;
            $newSequence++;
            $cooldownUntilVal = date('Y-m-d H:i:s', $currentTime + $durationSeconds);
            $newAlertStatus   = 'none';
        } else {
            $cooldownUntilVal = null;
            $newAlertStatus   = $row['alert_status'];
        }

        $targetUserId = ($userId !== null) ? $userId : ($row['user_id'] ? (int)$row['user_id'] : null);

        $stmtUpd = $pdo->prepare("
            UPDATE login_security
            SET failed_attempts   = ?,
                cooldown_level    = ?,
                cooldown_until    = ?,
                cooldown_sequence = ?,
                alert_status      = ?,
                user_id           = ?,
                updated_at        = NOW()
            WHERE id = ?
        ");
        $stmtUpd->execute([
            $attempts,
            $newLevel,
            $cooldownUntilVal,
            $newSequence,
            $newAlertStatus,
            $targetUserId,
            $row['id'],
        ]);

        $pdo->commit();

        if ($cooldownStarted) {
            $cooldownError = get_cooldown_error_message($newLevel, $durationSeconds);
            $alertPending = null;

            if ($user && !empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                $alertPending = [
                    'row_id'        => $row['id'],
                    'sequence'      => $newSequence,
                    'user'          => $user,
                    'durationLabel' => $durationLabel,
                ];
            }

            return [
                'error'            => $cooldownError,
                'cooldown_started' => true,
                'level'            => $newLevel,
                'duration_seconds' => $durationSeconds,
                'alert_pending'    => $alertPending,
            ];
        }

        return [
            'error'            => 'Incorrect Student ID/email or password.',
            'cooldown_started' => false,
            'failed_attempts'  => $attempts,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("record_login_failure_db error: " . $e->getMessage());
        return [
            'error'            => 'Sign-in is temporarily unavailable. Please try again in a few moments.',
            'cooldown_started' => false,
            'db_error'         => true,
        ];
    }
}

// Cooldown email alert — must run outside any DB transaction
function dispatch_cooldown_alert_claimed($pdo, $alertInfo) {
    if (empty($alertInfo) || empty($alertInfo['row_id']) || empty($alertInfo['sequence']) || empty($alertInfo['user'])) {
        return false;
    }

    $rowId = (int)$alertInfo['row_id'];
    $sequence = (int)$alertInfo['sequence'];
    $user = $alertInfo['user'];
    $durationLabel = $alertInfo['durationLabel'] ?? '3-minute';

    try {
        $stmtClaim = $pdo->prepare("
            UPDATE login_security
            SET alert_status = 'claimed'
            WHERE id = ? AND cooldown_sequence = ? AND alert_status = 'none'
        ");
        $stmtClaim->execute([$rowId, $sequence]);

        if ($stmtClaim->rowCount() === 1) {
            require_once __DIR__ . '/notification-service.php';
            $finalAlertStatus = 'failed';
            try {
                if (function_exists('send_login_cooldown_alert')) {
                    $dispatch = send_login_cooldown_alert($pdo, $user, $durationLabel);
                    if (!empty($dispatch['success'])) {
                        $finalAlertStatus = 'sent';
                    }
                }
            } catch (Throwable $mailEx) {
                error_log("Login cooldown alert dispatch exception: " . $mailEx->getMessage());
                $finalAlertStatus = 'failed';
            }


            $stmtStatus = $pdo->prepare("
                UPDATE login_security
                SET alert_status = ?
                WHERE id = ? AND cooldown_sequence = ? AND alert_status = 'claimed'
            ");
            $stmtStatus->execute([$finalAlertStatus, $rowId, $sequence]);
            return ($finalAlertStatus === 'sent');
        }
        return false;
    } catch (Throwable $e) {
        error_log("dispatch_cooldown_alert_claimed error: " . $e->getMessage());
        return false;
    }
}

// Record login failure and dispatch alert
function record_login_failure($pdo, $identifierHash, $ipAddress, $user = null, $nowTime = null) {
    $res = record_login_failure_db($pdo, $identifierHash, $ipAddress, $user, $nowTime);
    if (!empty($res['alert_pending'])) {
        dispatch_cooldown_alert_claimed($pdo, $res['alert_pending']);
    }
    return $res;
}

// Concurrency-safe login verification and processing
function verify_and_process_login($pdo, $identifier, $password, $nowTime = null) {
    $identifierHash = compute_login_identifier_hash($identifier);
    $ipAddress = get_server_observed_ip();

    $lockAcquired = acquire_login_lock($pdo, $identifierHash, $ipAddress, 10);
    if (!$lockAcquired) {
        return [
            'success'  => false,
            'error'    => 'Sign-in is temporarily unavailable. Please try again in a few moments.',
            'db_error' => true,
        ];
    }

    $result = null;
    $alertToSend = null;

    try {
        // Check cooldown under lock
        $cooldownCheck = check_login_cooldown($pdo, $identifierHash, $ipAddress, $nowTime);
        if ($cooldownCheck['is_blocked']) {
            $result = [
                'success' => false,
                'error'   => $cooldownCheck['error'],
            ];
            return $result;
        }

        // Query user record
        $isEmail = (strpos($identifier, '@') !== false);
        if ($isEmail) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
        }

        // Verify password
        $passwordValid = ($user && password_verify($password, $user['password']));

        if ($passwordValid) {
            if ($isEmail) {
                $userRole = strtolower(trim($user['role'] ?? ''));
                if (in_array($userRole, ['admin', 'superadmin'])) {
                    $cleared = clear_login_security_record($pdo, $identifierHash, $ipAddress);
                    if (!$cleared) {
                        error_log("verify_and_process_login: clear_login_security_record failed for admin user ID {$user['id']}");
                        $result = [
                            'success'  => false,
                            'error'    => 'Sign-in is temporarily unavailable. Please try again in a few moments.',
                        ];
                        return $result;
                    }
                    $result = [
                        'success'  => true,
                        'user'     => $user,
                        'redirect' => 'admin-homepage.php',
                    ];
                    return $result;
                } else {
                    // Valid password but not an admin account — not counted as failure
                    $result = [
                        'success' => false,
                        'error'   => 'This email is not registered as an administrative account. For student access, please enter your Student ID.',
                    ];
                    return $result;
                }
            } else {
                // Student account status check
                if ($user['status'] === 'unverified' || $user['status'] === 'pending') {
                    $result = [
                        'success' => false,
                        'error'   => 'Your student registration is still pending admin approval.',
                    ];
                    return $result;
                } elseif ($user['status'] === 'rejected') {
                    $result = [
                        'success' => false,
                        'error'   => 'Your registration application was rejected. Please contact support.',
                    ];
                    return $result;
                } elseif ($user['status'] === 'banned') {
                    $result = [
                        'success' => false,
                        'error'   => 'Your account has been deactivated. Please contact an administrator.',
                    ];
                    return $result;
                } else {
                    $cleared = clear_login_security_record($pdo, $identifierHash, $ipAddress);
                    if (!$cleared) {
                        error_log("verify_and_process_login: clear_login_security_record failed for student user ID {$user['id']}");
                        $result = [
                            'success'  => false,
                            'error'    => 'Sign-in is temporarily unavailable. Please try again in a few moments.',
                        ];
                        return $result;
                    }
                    $result = [
                        'success'  => true,
                        'user'     => $user,
                        'redirect' => 'user-homepage.php',
                    ];
                    return $result;
                }
            }
        }

        // Password wrong or user not found
        $failResult = record_login_failure_db($pdo, $identifierHash, $ipAddress, $user ?: null, $nowTime);
        if (!empty($failResult['alert_pending'])) {
            $alertToSend = $failResult['alert_pending'];
        }

        $result = [
            'success' => false,
            'error'   => $failResult['error'],
        ];
        return $result;

    } catch (Throwable $e) {
        error_log("verify_and_process_login error: " . $e->getMessage());
        $result = [
            'success'  => false,
            'error'    => 'Sign-in is temporarily unavailable. Please try again in a few moments.',
            'db_error' => true,
        ];
        return $result;
    } finally {
        // Lock always released before email dispatch
        release_login_lock($pdo, $identifierHash, $ipAddress);

        // Outside database lock: dispatch alert if needed
        if ($alertToSend !== null) {
            dispatch_cooldown_alert_claimed($pdo, $alertToSend);
        }
    }
}

// Clear login security record on successful login
function clear_login_security_record($pdo, $identifierHash, $ipAddress) {
    try {
        $stmt = $pdo->prepare("DELETE FROM login_security WHERE identifier_hash = ? AND ip_address = ?");
        return $stmt->execute([$identifierHash, $ipAddress]);
    } catch (Throwable $e) {
        error_log("clear_login_security_record error: " . $e->getMessage());
        return false;
    }
}

// Clear cooldown after password reset
function clear_login_security_for_user_ip($pdo, $userId, $ipAddress, $identifierHash = null) {

    try {
        if (!empty($identifierHash)) {
            $stmt = $pdo->prepare("DELETE FROM login_security WHERE (user_id = ? OR identifier_hash = ?) AND ip_address = ?");
            $success = $stmt->execute([(int)$userId, $identifierHash, $ipAddress]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM login_security WHERE user_id = ? AND ip_address = ?");
            $success = $stmt->execute([(int)$userId, $ipAddress]);
        }
        return ($success !== false);
    } catch (Throwable $e) {
        error_log("clear_login_security_for_user_ip error: " . $e->getMessage());
        return false;
    }
}
