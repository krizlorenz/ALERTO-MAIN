<?php
session_start();
require_once 'alerto-db.php';

$currentAdmin = validate_active_session($pdo, ['superadmin']);
if (!$currentAdmin || $currentAdmin['role'] !== 'superadmin') {
    http_response_code(403);
    exit('Superadmin access required.');
}

$error = '';
$success = '';

// Superadmin-only account lookup.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lookup_student') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['state' => 'error', 'message' => 'Session expired. Refresh this page.']);
        exit;
    }
    $lookupId = trim($_POST['student_id'] ?? '');
    if (!preg_match('/^[0-9]{2}-[0-9]{5}$/', $lookupId)) {
        http_response_code(422);
        echo json_encode(['state' => 'error', 'message' => 'Enter a valid Student ID: XX-XXXXX.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('SELECT id, role, status, full_name, student_id, program, year_level, contact_number, email FROM users WHERE student_id = ? LIMIT 1');
        $stmt->execute([$lookupId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            echo json_encode(['state' => 'new']);
        } elseif ($account['role'] !== 'student' || $account['status'] === 'banned') {
            echo json_encode(['state' => 'blocked', 'message' => 'This ID belongs to an admin, superadmin, or banned account and cannot be promoted.']);
        } else {
            unset($account['id'], $account['role'], $account['status']);
            echo json_encode(['state' => 'student', 'profile' => $account]);
        }
    } catch (PDOException $e) {
        error_log('Admin account lookup failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['state' => 'error', 'message' => 'Unable to check this ID. Try again.']);
    }
    exit;
}

// Recheck the database on submission; browser lookup is advisory only.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name      = trim($_POST['full_name'] ?? '');
    $student_id     = trim($_POST['student_id'] ?? '');
    $program        = trim($_POST['program'] ?? '');
    $year_level     = trim($_POST['year_level'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirm        = $_POST['confirm_password'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Session expired. Refresh this page and try again.';
    } elseif (!preg_match('/^[0-9]{2}-[0-9]{5}$/', $student_id)) {
        $error = 'Student ID must use XX-XXXXX format.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id, student_id, email, role, status FROM users WHERE student_id = ? OR email = ? FOR UPDATE');
            $stmt->execute([$student_id, $email]);
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $student = null;
            foreach ($matches as $account) {
                if ($account['student_id'] === $student_id) {
                    $student = $account;
                }
            }

            if ($student !== null) {
                if ($student['role'] !== 'student') {
                    throw new RuntimeException('This account is already an admin or superadmin.');
                }
                if ($student['status'] === 'banned') {
                    throw new RuntimeException('A banned student account cannot be promoted.');
                }
                if (count($matches) !== 1 || strcasecmp($student['email'], $email) !== 0) {
                    throw new RuntimeException('The Student ID and registered email must belong to the same student. Accounts cannot be merged.');
                }
                $update = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ? AND role = 'student'");
                $update->execute([$student['id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The account changed during processing. Please try again.');
                }
                $success = 'Student account promoted to admin. Tell this user to log out, then log in using their existing email and password. All existing account data is retained.';
            } else {
                if ($matches) {
                    throw new RuntimeException('This email belongs to another account. Check the Student ID and email.');
                }
                if ($full_name === '' || $program === '' || $year_level === '') {
                    throw new RuntimeException('Please fill in all required profile fields.');
                }
                if (!preg_match('/^[0-9]{11}$/', $contact_number)) {
                    throw new RuntimeException('Contact number must contain exactly 11 digits.');
                }
                if (strlen($password) < 8) {
                    throw new RuntimeException('New admin accounts require a password of at least 8 characters.');
                }
                if ($password !== $confirm) {
                    throw new RuntimeException('Passwords do not match.');
                }
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $insert = $pdo->prepare("INSERT INTO users (role, full_name, student_id, program, year_level, contact_number, email, password, status) VALUES ('admin', ?, ?, ?, ?, ?, ?, ?, 'verified')");
                $insert->execute([$full_name, $student_id, $program, $year_level, $contact_number, $email, $hashed]);
                $success = 'New admin account created successfully.';
            }
                $pdo->commit();

            // Notify the existing student after successful promotion.
            if ($student !== null) {
                try {
                    require_once __DIR__ . '/notification-service.php';

                    $recipientStmt = $pdo->prepare(
                        'SELECT full_name, email FROM users WHERE id = ?'
                    );
                    $recipientStmt->execute([$student['id']]);
                    $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$recipient) {
                        throw new RuntimeException('Promotion email recipient not found.');
                    }

                    $config = get_mail_config();
                    $appUrl = rtrim($config['app']['url'] ?? '', '/');

                    if (!filter_var($appUrl, FILTER_VALIDATE_URL)) {
                        throw new RuntimeException('Invalid app URL in mail configuration.');
                    }

                    $loginUrl = $appUrl . '/admin-login.php';
                    $safeName = htmlspecialchars(
                        $recipient['full_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    $subject = 'ALERTO - Your Account Is Now an Admin';

                    $bodyHtml = "
                        <p>Hello <strong>{$safeName}</strong>,</p>
                        <p>Your ALERTO account has been promoted to
                        <strong>Administrator</strong>.</p>
                        <p>You now have access to the ALERTO admin dashboard.</p>
                        <p>Please log out, then sign in through the Admin
                        Login page using your <strong>existing email and
                        password</strong>.</p>
                        <p>Your password and existing account data
                        have been retained.</p>
                    ";

                    $htmlContent = build_alerto_email_html(
                        'Administrator Access Granted',
                        'Admin',
                        '#1e6b37',
                        $bodyHtml,
                        'Open Admin Login',
                        $loginUrl
                    );

                    $plainText = "Hello {$recipient['full_name']},\n\n"
                        . "Your ALERTO account has been promoted to Administrator.\n\n"
                        . "You now have access to the ALERTO admin dashboard.\n\n"
                        . "Please log out, then sign in through the Admin Login page "
                        . "using your existing email and password.\n\n"
                        . "Your password and existing account data have been retained.\n\n"
                        . "Admin Login: {$loginUrl}";

                    $mailResult = dispatch_alerto_email(
                        $recipient['email'],
                        $recipient['full_name'],
                        $subject,
                        $htmlContent,
                        $plainText,
                        $config
                    );

                    if (empty($mailResult['success'])) {
                        throw new RuntimeException(
                            $mailResult['error'] ?? 'Email could not be sent.'
                        );
                    }

                    $success .= ' Promotion notification email sent.';
                } catch (Throwable $mailError) {
                    error_log(
                        'Admin promotion email failed: ' . $mailError->getMessage()
                    );

                    $success .= ' The promotion succeeded, but the notification email could not be sent.';
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $success = '';
            error_log('Admin creation/promotion failed: ' . $e->getMessage());
            $error = 'Unable to save the admin account. Check for duplicate details and try again.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $success = '';
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add New Admin | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Plus Jakarta Sans, Inter & JetBrains Mono (3-Font System) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
    rel="stylesheet">

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/auth.css?v=<?= time() ?>">
</head>

<body>

  <main class="auth-page">

    <!-- Centered Registration Card Container -->
    <div class="register-card-container">

      <!-- Card Top Crimson Red Header -->
      <div class="register-card-header">
        <div class="auth-header-top-row">
          <div class="auth-live-status">
            <span class="live-pulse-dot" aria-hidden="true"></span>
            <span>DRRM Head Setup</span>
          </div>
          <span class="auth-badge-terminal">CSU-CARIG • COEA</span>
        </div>

        <div class="auth-title-lockup">
          <div class="auth-logo-box">
            <img src="logo/main_logo.png" alt="ALERTO Admin Logo">
          </div>
          <div class="auth-text-meta">
            <h1>Create New Admin Profile</h1>
            <p>COEA-SC DRRM Community Head</p>
          </div>
        </div>
      </div>

      <!-- Registration Form Body -->
      <div class="register-card-body">

        <!-- PHP Error / Success Messages -->
        <?php if (!empty($error)): ?>
        <div class="alert-banner alert-error" role="alert">
          <svg class="alert-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div class="alert-banner alert-success" role="status">
          <svg class="alert-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
          </svg>
          <span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

        <form class="auth-form" id="adminRegisterForm" method="POST" action="admin-add-sign-in.php" onsubmit="return validateAdminPasswords();">
          <input type="hidden" id="adminCsrfToken" name="csrf_token"
                 value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <p id="adminLookupMessage" role="status" aria-live="polite" style="color: #700d23;"></p>

          <!-- 1. Full Name -->
          <div class="form-field-group">
            <label for="regFullName" class="form-label">
              <span>Full Name</span>
              <span class="form-label-tag">Officer in Charge</span>
            </label>
            <div class="input-with-icon">
              <span class="input-icon-slot" aria-hidden="true">
                <img src="icons/user.svg" alt=""
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                  <circle cx="12" cy="7" r="4"></circle>
                </svg>
              </span>
              <input type="text" id="regFullName" name="full_name" class="auth-input"
                placeholder="e.g. Juan Dela Cruz" required autocomplete="name"
                value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
          </div>

          <!-- 2. Student ID Number -->
          <div class="form-field-group">
            <label for="regStudentId" class="form-label">
              <span>Student ID Number</span>
              <span class="form-label-tag">CSU ID Card</span>
            </label>
            <div class="input-with-icon">
              <span class="input-icon-slot" aria-hidden="true">
                <img src="icons/id-badge.svg" alt=""
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round">
                  <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                  <line x1="7" y1="8" x2="17" y2="8"></line>
                  <line x1="7" y1="12" x2="13" y2="12"></line>
                  <line x1="7" y1="16" x2="10" y2="16"></line>
                </svg>
              </span>
              <input type="text" id="regStudentId" name="student_id" class="auth-input"
                placeholder="e.g. 24-XXXXX" required autocomplete="username"
                pattern="\d{2}-\d{5}" maxlength="8"
                title="Format must be XX-XXXXX (e.g., 24-XXXXX)"
                value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>">
            </div>
          </div>

          <!-- 3. Program & Year Level -->
          <div class="form-dual-grid">

            <!-- Program -->
            <div class="form-field-group">
              <label for="regProgram" class="form-label">
                <span>Academic Program</span>
              </label>
              <div class="input-with-icon">
                <span class="input-icon-slot" aria-hidden="true">
                  <img src="icons/cap.svg" alt=""
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                    <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                  </svg>
                </span>
                <select id="regProgram" name="program" class="auth-select" required>
                  <option value="" disabled <?php echo empty($_POST['program']) ? 'selected' : ''; ?>>Select Program</option>
                  <option value="BS in Civil Engineering" <?php echo (($_POST['program'] ?? '') === 'BS in Civil Engineering') ? 'selected' : ''; ?>>BS in Civil Engineering</option>
                  <option value="BS in Electrical Engineering" <?php echo (($_POST['program'] ?? '') === 'BS in Electrical Engineering') ? 'selected' : ''; ?>>BS in Electrical Engineering</option>
                  <option value="BS in Geodetic Engineering" <?php echo (($_POST['program'] ?? '') === 'BS in Geodetic Engineering') ? 'selected' : ''; ?>>BS in Geodetic Engineering</option>
                  <option value="BS in Computer Engineering" <?php echo (($_POST['program'] ?? '') === 'BS in Computer Engineering') ? 'selected' : ''; ?>>BS in Computer Engineering</option>
                  <option value="BS in Chemical Engineering" <?php echo (($_POST['program'] ?? '') === 'BS in Chemical Engineering') ? 'selected' : ''; ?>>BS in Chemical Engineering</option>
                  <option value="BS in Architecture" <?php echo (($_POST['program'] ?? '') === 'BS in Architecture') ? 'selected' : ''; ?>>BS in Architecture</option>
                  <option value="BS in Electronics Engineering" <?php echo (($_POST['program'] ?? '') === 'BS in Electronics Engineering') ? 'selected' : ''; ?>>BS in Electronics Engineering</option>
                  <option value="BS in Agricultural and Biosystems Engineering" <?php echo (($_POST['program'] ?? '') === 'BS in Agricultural and Biosystems Engineering') ? 'selected' : ''; ?>>BS in Agricultural and Biosystems Engineering</option>
                </select>
              </div>
            </div>

            <!-- Year Level -->
            <div class="form-field-group">
              <label for="regYearLevel" class="form-label">
                <span>Year Level</span>
              </label>
              <div class="input-with-icon">
                <span class="input-icon-slot" aria-hidden="true">
                  <img src="icons/star.svg" alt=""
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <polygon
                      points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2">
                    </polygon>
                  </svg>
                </span>
                <select id="regYearLevel" name="year_level" class="auth-select" required>
                  <option value="" disabled <?php echo empty($_POST['year_level']) ? 'selected' : ''; ?>>Select Year</option>
                  <option value="1st Year" <?php echo (($_POST['year_level'] ?? '') === '1st Year') ? 'selected' : ''; ?>>1st Year</option>
                  <option value="2nd Year" <?php echo (($_POST['year_level'] ?? '') === '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
                  <option value="3rd Year" <?php echo (($_POST['year_level'] ?? '') === '3rd Year') ? 'selected' : ''; ?>>3rd Year</option>
                  <option value="4th Year" <?php echo (($_POST['year_level'] ?? '') === '4th Year') ? 'selected' : ''; ?>>4th Year</option>
                  <option value="5th Year" <?php echo (($_POST['year_level'] ?? '') === '5th Year') ? 'selected' : ''; ?>>5th Year</option>
                </select>
              </div>
            </div>

          </div>

          <!-- Contact Number -->
          <div class="form-field-group">
            <label for="adminContact" class="form-label">
              <span>Contact Number</span>
              <span class="form-label-tag">Mobile / Phone</span>
            </label>
            <div class="input-with-icon">
              <span class="input-icon-slot" aria-hidden="true">
                <img src="icons/phone.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                </svg>
              </span>
              <input type="tel" id="adminContact" name="contact_number"
                  class="auth-input" placeholder="09XXXXXXXXX"
                  required autocomplete="tel" inputmode="numeric"
                  minlength="11" maxlength="11" pattern="[0-9]{11}"
                  title="Enter exactly 11 digits"
                  oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">
            </div>
          </div>

          <!-- 4. Institutional Email -->
          <div class="form-field-group">
            <label for="regEmail" class="form-label">
              <span>Email Address</span>
              <span class="form-label-tag">email</span>
            </label>
            <div class="input-with-icon">
              <span class="input-icon-slot" aria-hidden="true">
                <img src="icons/mail.svg" alt=""
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                  <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
              </span>
              <input type="email" id="regEmail" name="email" class="auth-input"
                placeholder="e.g. student@gmail.com" required autocomplete="email"
                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
          </div>

          <!-- 5. Password Fields -->
          <div class="form-dual-grid">

            <!-- Create Password -->
            <div class="form-field-group">
              <label for="regPassword" class="form-label">
                <span>Create Password</span>
              </label>
              <div class="input-with-icon">
                <span class="input-icon-slot" aria-hidden="true">
                  <img src="icons/lock.svg" alt=""
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </span>
                <input type="password" id="regPassword" name="password" class="auth-input has-eye"
                  placeholder="Create password" required minlength="8" autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'regPassword')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
              <small class="input-hint">Password requirements: At least 8 characters</small>
            </div>

            <!-- Repeat Password -->
            <div class="form-field-group">
              <label for="regRepeatPassword" class="form-label">
                <span>Repeat Password</span>
              </label>
              <div class="input-with-icon">
                <span class="input-icon-slot" aria-hidden="true">
                  <img src="icons/shield-lock.svg" alt=""
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                  </svg>
                </span>
                <input type="password" id="regRepeatPassword" name="confirm_password" class="auth-input has-eye"
                  placeholder="Confirm password" required minlength="8" autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'regRepeatPassword')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
            </div>

          </div>

          <!-- Submit Button -->
          <button type="submit" class="auth-submit-btn" id="regSubmitBtn">
            <span>Create Admin Account</span>
            <img src="icons/arrow-right.svg" alt="" class="icon-16"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
            <svg class="icon-16 svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round">
              <line x1="5" y1="12" x2="19" y2="12"></line>
              <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
          </button>

        </form>

        <!-- Footer link back to Sign In -->
        <div class="auth-footer-block">
          <div class="security-badge">
            <img src="icons/shield-check.svg" alt="" class="icon-14"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
            <svg class="icon-14 svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
            <span>COEA-SC DRRM Operations Security Standard</span>
          </div>

          <a href="admin-homepage.php" class="auth-back-link">
            <span>Back to Dashboard &rarr;</span>
          </a>
        </div>

      </div>

    </div>

  </main>

  <script>
    // Toggle Password Visibility with Eye Icon
    function togglePasswordEye(btn, inputId) {
      const input = document.getElementById(inputId);
      if (!input) return;
      const isPwd = input.type === 'password';
      input.type = isPwd ? 'text' : 'password';
      btn.setAttribute('aria-label', isPwd ? 'Hide password' : 'Show password');
      btn.innerHTML = isPwd
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    }

    const adminForm = document.getElementById('adminRegisterForm');
    const studentIdInput = document.getElementById('regStudentId');
    const passwordInput = document.getElementById('regPassword');
    const confirmInput = document.getElementById('regRepeatPassword');
    const submitButton = document.getElementById('regSubmitBtn');
    const lookupMessage = document.getElementById('adminLookupMessage');
    const profileIds = ['regFullName', 'regProgram', 'regYearLevel', 'adminContact', 'regEmail'];
    let lookupState = 'unchecked';
    let checkedId = '';
    let lookupVersion = 0;
    let lookupTimer;
    let populatedStudent = false;

    function setPromotionFields(isStudent) {
      for (const input of [passwordInput, confirmInput]) {
        input.disabled = isStudent;
        input.required = !isStudent;
        if (isStudent) input.value = '';
        const eye = input.parentElement.querySelector('.password-eye-toggle-btn');
        if (eye) eye.disabled = isStudent;
      }
      for (const id of profileIds) {
        const field = document.getElementById(id);
        if (field.tagName === 'SELECT') {
          field.disabled = isStudent;
        } else {
          field.readOnly = isStudent;
        }
      }
    }

    function invalidateLookup() {
      lookupVersion++;
      lookupState = 'unchecked';
      checkedId = '';
      submitButton.disabled = true;
      setPromotionFields(false);
      if (populatedStudent) {
        for (const id of profileIds) document.getElementById(id).value = '';
        populatedStudent = false;
      }
      lookupMessage.textContent = 'Enter a complete Student ID to check the account.';
    }

    async function checkStudentId() {
      const id = studentIdInput.value.trim();
      if (!/^[0-9]{2}-[0-9]{5}$/.test(id)) return;
      const version = ++lookupVersion;
      lookupState = 'checking';
      submitButton.disabled = true;
      lookupMessage.textContent = 'Checking Student ID...';
      const body = new URLSearchParams({
        action: 'lookup_student',
        student_id: id,
        csrf_token: document.getElementById('adminCsrfToken').value
      });
      try {
        const response = await fetch(adminForm.action, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store', body
        });
        const result = await response.json();
        if (version !== lookupVersion || id !== studentIdInput.value.trim()) return;
        if (!response.ok || !['new', 'student', 'blocked'].includes(result.state)) {
          throw new Error(result.message || 'Unable to check this ID.');
        }
        lookupState = result.state;
        checkedId = id;
        if (result.state === 'student') {
          const mapping = {regFullName: 'full_name', regProgram: 'program', regYearLevel: 'year_level', adminContact: 'contact_number', regEmail: 'email'};
          for (const [fieldId, key] of Object.entries(mapping)) {
            document.getElementById(fieldId).value = result.profile[key] || '';
          }
          populatedStudent = true;
          setPromotionFields(true);
          lookupMessage.textContent = 'Existing student account found. Only the role will change. Tell this user to use their existing email and password after promotion.';
          submitButton.disabled = false;
        } else if (result.state === 'new') {
          setPromotionFields(false);
          lookupMessage.textContent = 'New account: complete the profile and set a password of at least 8 characters.';
          submitButton.disabled = false;
        } else {
          setPromotionFields(false);
          lookupMessage.textContent = result.message;
        }
      } catch (error) {
        if (version !== lookupVersion) return;
        lookupState = 'error';
        submitButton.disabled = true;
        lookupMessage.textContent = 'Account lookup failed. Check your connection, then edit or leave the Student ID field to retry.';
      }
    }

    studentIdInput.addEventListener('input', () => {
      clearTimeout(lookupTimer);
      invalidateLookup();
      lookupTimer = setTimeout(checkStudentId, 300);
    });
    studentIdInput.addEventListener('change', () => {
      clearTimeout(lookupTimer);
      invalidateLookup();
      checkStudentId();
    });

    function validateAdminPasswords() {
      if (checkedId !== studentIdInput.value.trim() || !['new', 'student'].includes(lookupState)) {
        alert('Wait for a successful Student ID check before submitting.');
        return false;
      }
      if (lookupState === 'student') return true;
      if (passwordInput.value.length < 8) {
        alert('Password must be at least 8 characters long.');
        return false;
      }
      if (passwordInput.value !== confirmInput.value) {
        alert('Passwords do not match.');
        return false;
      }
      return true;
    }

    invalidateLookup();
    checkStudentId();
  </script>
</body>

</html>
