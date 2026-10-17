<?php
session_start();
require_once 'alerto-db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Forgot Password Action (AJAX / JSON)
    if (isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
        header('Content-Type: application/json; charset=utf-8');

        $email = trim($_POST['email'] ?? $_POST['reset_verification'] ?? '');

        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Please enter your registered email address.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        try {
            // Find user matching email address
            $stmt = $pdo->prepare("SELECT id, student_id, email, full_name, status, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'No account found with that email address.']);
                exit;
            }

            if (isset($user['status']) && $user['status'] === 'banned') {
                echo json_encode(['success' => false, 'message' => 'This account has been deactivated. Please contact an administrator.']);
                exit;
            }

            // Generate secure token (64 hex characters)
            $token = bin2hex(random_bytes(32));

            // Invalidate any existing tokens for this email
            $del = $pdo->prepare("DELETE FROM password_resets WHERE LOWER(email) = LOWER(?)");
            $del->execute([$email]);

            // Store new token in database (1-hour validity using MySQL time)
            $ins = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
            $ins->execute([$user['email'], $token]);

            // Construct password reset link to Photo 2 page (reset-password.php)
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $resetUrl = $scheme . '://' . $host . $dir . '/reset-password.php?token=' . urlencode($token);

            // Send Email
            $to = $user['email'];
            $subject = "ALERTO - Password Reset Request";
            $message = "Hello " . htmlspecialchars($user['full_name'] ?: 'User') . ",\n\n"
                     . "We received a request to reset or update your ALERTO account password.\n\n"
                     . "Please click the link below to set your new password:\n"
                     . $resetUrl . "\n\n"
                     . "This link will expire in 1 hour. If you did not make this request, you can safely ignore this email.\n\n"
                     . "Best regards,\n"
                     . "ALERTO CSU-COEA Student Council";
            $headers = "From: ALERTO Support <no-reply@alerto.csu.edu.ph>\r\n"
                     . "Reply-To: no-reply@alerto.csu.edu.ph\r\n"
                     . "X-Mailer: PHP/" . phpversion();

            @mail($to, $subject, $message, $headers);

            echo json_encode([
                'success' => true,
                'message' => 'Instructions to reset your password have been sent to your email address.',
                'dev_url' => $resetUrl
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
            exit;
        }
    }

    $identifier = trim($_POST['identifier'] ?? $_POST['student_id'] ?? $_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $error = "Please enter your Student ID or Email and password.";
    }

    if (empty($error)) {
        $isEmail = (strpos($identifier, '@') !== false);

        if ($isEmail) {
            // ADMIN LOGIN (Email format)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $userRole = strtolower(trim($user['role'] ?? ''));

                if (in_array($userRole, ['admin', 'superadmin'])) {
                    $_SESSION['user_id']        = $user['id'];
                    $_SESSION['role']           = $user['role'];
                    $_SESSION['full_name']      = $user['full_name'];
                    $_SESSION['email']          = $user['email'];
                    $_SESSION['student_id']     = $user['student_id'] ?? '';
                    $_SESSION['program']        = $user['program'] ?? '';
                    $_SESSION['year_level']     = $user['year_level'] ?? '';
                    $_SESSION['contact_number'] = $user['contact_number'] ?? '';
                    $_SESSION['status']         = $user['status'] ?? 'approved';

                    header("Location: admin-homepage.php");
                    exit;
                } else {
                    $error = "This email is not registered as an administrative account. For student access, please enter your Student ID.";
                }
            } else {
                $error = "Invalid admin credentials. Please verify your email and password.";
            }
        } else {
            // STUDENT / USER LOGIN (Student ID format XX-XXXXX)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Student account status check
                if ($user['status'] === 'unverified' || $user['status'] === 'pending') {
                    $error = "Your student registration is still pending admin approval.";
                } elseif ($user['status'] === 'rejected') {
                    $error = "Your registration application was rejected. Please contact support.";
                } elseif ($user['status'] === 'banned') {
                    $error = "Your account has been deactivated. Please contact an administrator.";
                } else {
                    $_SESSION['user_id']        = $user['id'];
                    $_SESSION['role']           = 'student';
                    $_SESSION['full_name']      = $user['full_name'];
                    $_SESSION['student_id']     = $user['student_id'];
                    $_SESSION['email']          = $user['email'];
                    $_SESSION['program']        = $user['program'];
                    $_SESSION['year_level']     = $user['year_level'];
                    $_SESSION['contact_number'] = $user['contact_number'];
                    $_SESSION['status']         = $user['status'];

                    header("Location: user-homepage.php");
                    exit;
                }
            } else {
                $error = "Invalid student credentials. Please verify your Student ID and password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Account Log In | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Plus Jakarta Sans, Inter & JetBrains Mono (3-Font System) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/auth.css?v=<?= time() ?>">
</head>
<body>

  <div class="login-page-wrapper student-theme">
    
    <!-- Top Navigation Header -->
    <header class="login-top-bar">
      <div class="login-top-bar-inner">
        <a href="user-login.php" class="login-brand-lockup">
          <img src="logo/main_logo.png" alt="ALERTO Logo" class="login-brand-logo">
          <div class="login-brand-texts">
            <span class="login-brand-title">ALERTO</span>
            <span class="login-brand-sub">CSU-COEA</span>
          </div>
        </a>
      </div>
    </header>

    <!-- Main Center Split Container -->
    <div class="login-main-container">
      <div class="login-split-grid">
        
        <!-- Left Hero Content -->
        <div class="login-hero-content">
          <span class="login-eyebrow-tag">Account Log In</span>
          
          <h1 class="login-hero-heading">
            Welcome to
            <span class="brand-highlight">ALERTO</span>
          </h1>

          <p class="login-hero-paragraph">
            ALERTO is a web-based platform that lets affected students request assistance, follow official advisories, and stay informed — while giving the COEA Student Council a clear, centralized way to review, prioritize, and respond.
          </p>

          <!-- Feature Icons -->
          <div class="login-features-row">
            
            <!-- Request Assistance -->
            <div class="feature-item-col">
              <div class="feature-icon-circle" aria-hidden="true">
                <img src="icons/user-login-icons/request-assistance.png" alt="Request Assistance">
              </div>
              <span class="feature-item-label">Request<br>Assistance</span>
            </div>

            <!-- Relief Resources -->
            <div class="feature-item-col">
              <div class="feature-icon-circle" aria-hidden="true">
                <img src="icons/user-login-icons/relief-resources.png" alt="Relief Resources">
              </div>
              <span class="feature-item-label">Relief<br>Resources</span>
            </div>

            <!-- Community Support -->
            <div class="feature-item-col">
              <div class="feature-icon-circle" aria-hidden="true">
                <img src="icons/user-login-icons/community-support.png" alt="Community Support">
              </div>
              <span class="feature-item-label">Community<br>Support</span>
            </div>

          </div>
        </div>

        <!-- Right Login Card -->
        <div>
          <div class="login-portal-card">
            
            <!-- Card Header -->
            <div class="portal-card-header">
              <div class="portal-avatar-circle" aria-hidden="true">
                <img src="icons/user_admin_login_icon/user-login.png" alt="Log In">
              </div>
              <div class="portal-title-block">
                <h2>Account Log In</h2>
                <p>Enter your student ID or Admin credentials to access your account.</p>
              </div>
            </div>

            <!-- Error & Success Message Containers -->
            <?php if (!empty($error)): ?>
              <div class="alert-banner alert-error" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="8" x2="12" y2="12"></line>
                  <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <div><?= htmlspecialchars($error) ?></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
              <div class="alert-banner alert-success" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <div><?= htmlspecialchars($success) ?></div>
              </div>
            <?php endif; ?>

            <!-- Log In Form -->
            <form class="login-form-body" id="unifiedLoginForm" action="user-login.php" method="POST">
              
              <!-- Field 1: Identifier (Student ID or Email) -->
              <div class="input-group">
                <label for="userIdentifier" class="input-label">Student ID or Email Address</label>
                <div class="input-container">
                  <span class="input-icon-slot" aria-hidden="true">
                    <img src="icons/id-badge.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                      <line x1="7" y1="8" x2="17" y2="8"></line>
                      <line x1="7" y1="12" x2="13" y2="12"></line>
                      <line x1="7" y1="16" x2="10" y2="16"></line>
                    </svg>
                  </span>
                  <input type="text" id="userIdentifier" name="identifier" class="portal-text-input" placeholder="Enter Student ID (e.g. 24-XXXXX) or Admin Email" required autocomplete="username" value="<?= htmlspecialchars($_POST['identifier'] ?? $_POST['student_id'] ?? $_POST['email'] ?? '') ?>">
                </div>
                <small class="input-hint">Students: Enter Student ID (e.g. 24-XXXXX) &bull; Admins: Enter registered Email</small>
              </div>

              <!-- Field 2: Password -->
              <div class="input-group">
                <label for="userPassword" class="input-label">Password</label>
                <div class="input-container">
                  <span class="input-icon-slot" aria-hidden="true">
                    <img src="icons/lock.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                      <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                  </span>
                  <input type="password" id="userPassword" name="password" class="portal-text-input has-eye" placeholder="Enter your password" required autocomplete="current-password">
                  <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'userPassword')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                      <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Options Row: Remember Me & Forgot Password -->
              <div class="login-options-row">
                <label class="remember-label">
                  <input type="checkbox" id="userRememberMe" name="rememberMe">
                  <span>Remember me</span>
                </label>
                <a href="javascript:void(0)" onclick="openForgotPasswordModal()" class="forgot-link" id="forgotPasswordLink">Forgot password?</a>
              </div>

              <!-- Submit Button -->
              <button type="submit" class="portal-submit-btn" id="loginSubmitBtn">
                Log in
              </button>

              <!-- Centered Card Footer Options -->
              <div class="login-card-footer-stack">
                <div class="register-switch-link">
                  First time logging in? <a href="user-sign-in.php">Create Account</a>
                </div>
              </div>

            </form>

          </div>
        </div>

      </div>
    </div>

    <!-- Bottom Wavy Crimson Red Curve -->
    <div class="login-wave-footer" aria-hidden="true">
      <svg viewBox="0 0 1440 120" preserveAspectRatio="none">
        <path d="M0,45 C320,120 440,15 720,70 C960,115 1200,30 1440,85 L1440,120 L0,120 Z"></path>
      </svg>
    </div>

  </div>

  <!-- Forgot Password Modal Dialog -->
  <dialog id="forgotPasswordModal" class="auth-modal" aria-labelledby="forgotPasswordTitle">
    <div class="modal-header-banner">
      <div class="modal-header-info">
        <div class="modal-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
          </svg>
        </div>
        <div>
          <h3 id="forgotPasswordTitle" class="modal-header-title">Reset Account Password</h3>
          <p class="modal-header-sub">Enter your registered email address to reset or update your password.</p>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeForgotPasswordModal()" aria-label="Close modal">&times;</button>
    </div>

    <form id="forgotPasswordForm" method="POST" action="user-login.php" onsubmit="handleForgotPasswordSubmit(event)">
      <input type="hidden" name="action" value="forgot_password">
      <input type="hidden" name="ajax" value="1">

      <div class="modal-body-content">
        <!-- Live status notification inside modal -->
        <div id="resetStatusBanner" class="modal-status-banner"></div>

        <!-- Reset Field: Registered Email Only (Photo 2 style: No placeholder) -->
        <div class="input-group">
          <label for="resetEmail" class="input-label">Email *</label>
          <div class="input-container">
            <span class="input-icon-slot" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
              </svg>
            </span>
            <input type="email" id="resetEmail" name="email" class="portal-text-input" required autocomplete="email">
          </div>
        </div>

        <!-- Temporary Button to Access Set Password Page -->
        <div class="modal-dev-preview-box">
          <a href="reset-password.php" class="modal-dev-preview-btn">
            <span>Set Password Page &rarr;</span>
          </a>
        </div>

      </div>

      <div class="modal-footer-actions">
        <button type="button" class="modal-cancel-btn" onclick="closeForgotPasswordModal()">Cancel</button>
        <button type="submit" class="modal-submit-btn" id="resetSubmitBtn">
          <span>Submit</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </button>
      </div>
    </form>
  </dialog>

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


    // Close when clicking modal backdrop
    // Modal Open & Close Functions
    function openForgotPasswordModal() {
      const modal = document.getElementById('forgotPasswordModal');
      if (!modal) return;
      const statusBanner = document.getElementById('resetStatusBanner');
      if (statusBanner) {
        statusBanner.style.display = 'none';
        statusBanner.innerHTML = '';
      }

      // Prepopulate email if user typed an email in main login form
      const loginIdentifier = document.getElementById('userIdentifier');
      const resetEmail = document.getElementById('resetEmail');
      if (loginIdentifier && resetEmail && loginIdentifier.value.includes('@') && !resetEmail.value.trim()) {
        resetEmail.value = loginIdentifier.value.trim();
      }

      modal.showModal();
    }

    function closeForgotPasswordModal() {
      const modal = document.getElementById('forgotPasswordModal');
      if (modal) modal.close();
    }

    // Close when clicking modal backdrop
    const forgotModalElem = document.getElementById('forgotPasswordModal');
    if (forgotModalElem) {
      forgotModalElem.addEventListener('click', (e) => {
        const rect = forgotModalElem.getBoundingClientRect();
        const isInDialog = (rect.top <= e.clientY && e.clientY <= rect.top + rect.height
          && rect.left <= e.clientX && e.clientX <= rect.left + rect.width);
        if (!isInDialog) {
          forgotModalElem.close();
        }
      });
    }

    // Handle Forgot Password Form Submission with Live Feedback
    async function handleForgotPasswordSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('forgotPasswordForm');
      const submitBtn = document.getElementById('resetSubmitBtn');
      const banner = document.getElementById('resetStatusBanner');
      const emailInput = document.getElementById('resetEmail');
      if (!form || !submitBtn || !banner || !emailInput) return;

      const email = emailInput.value.trim();
      if (!email) {
        showResetBanner('Please enter your registered email address.', 'error');
        return;
      }

      submitBtn.disabled = true;
      const originalBtnHtml = submitBtn.innerHTML;
      submitBtn.innerHTML = '<span>Sending...</span>';

      try {
        const formData = new FormData(form);
        const res = await fetch('user-login.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: formData
        });

        const data = await res.json();
        if (data.success) {
          showResetBanner(data.message || 'Instructions to reset your password have been sent to your email address.', 'success');
          submitBtn.innerHTML = '<span>Sent!</span>';
          submitBtn.style.background = '#1e6b37';
        } else {
          showResetBanner(data.message || 'Failed to send password reset link. Please check your email.', 'error');
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnHtml;
        }
      } catch (err) {
        showResetBanner('An unexpected error occurred. Please try again.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnHtml;
      }
    }

    function showResetBanner(message, type) {
      const banner = document.getElementById('resetStatusBanner');
      if (!banner) return;
      banner.style.display = 'block';
      banner.className = `modal-status-banner is-${type}`;
      banner.innerHTML = message;
    }

    // Auto-open modal if URL has ?action=forgot-password or ?forgot_password=1
    window.addEventListener('DOMContentLoaded', () => {
      const params = new URLSearchParams(window.location.search);
      if (params.get('action') === 'forgot-password' || params.get('forgot_password') === '1') {
        openForgotPasswordModal();
      }
    });
  </script>
</body>
</html>
