<?php
session_start();
require_once 'alerto-db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['student_id'] ?? $_POST['email'] ?? $_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $error = "Please enter your Student ID or Email and password.";
    } elseif (strpos($identifier, '@') === false) {
        if (!preg_match('/^\d{2}-\d{5}$/', $identifier)) {
            $error = "Student ID must be in the exact format XX-XXXXX (e.g., 24-00909).";
        }
    }

    if (empty($error)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (student_id = ? OR email = ?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'unverified') {
                $error = "Your account registration is still pending admin approval.";
            } elseif ($user['status'] === 'rejected') {
                $error = "Your registration application was rejected. Please contact support.";
            } elseif ($user['status'] === 'banned') {
                $error = "Your account has been deactivated. Please contact an administrator.";
            } else {
                $_SESSION['user_id']        = $user['id'];
                $_SESSION['role']           = $user['role'];
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
            $error = "Invalid credentials. Please verify your Student ID/Email and password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Portal Sign In | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Poppins & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">

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
          <img src="logo/user-main-logo.png" alt="ALERTO Logo" class="login-brand-logo">
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
          <span class="login-eyebrow-tag">Student Portal</span>
          
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
                <img src="icons/user_admin_login_icon/user-login.png" alt="Student Portal">
              </div>
              <div class="portal-title-block">
                <h2>Student Portal</h2>
                <p>For enrolled CSU-COEA students requiring assistance.</p>
              </div>
            </div>

            <!-- Error & Success Message Containers -->
            <?php if (!empty($error)): ?>
              <div class="alert-banner alert-error" role="alert" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: var(--radius-sm, 8px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: var(--status-rejected-bg, #fdf0f2); color: var(--status-rejected-text, #9c2438); border: 1.5px solid var(--status-rejected-border, #f8c9d1); margin: 0 0 16px 0; line-height: 1.45;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px; flex-shrink: 0; color: #b82b43; margin-top: 1px;">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="8" x2="12" y2="12"></line>
                  <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <div><?= htmlspecialchars($error) ?></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
              <div class="alert-banner alert-success" role="alert" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: var(--radius-sm, 8px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: var(--status-approved-bg, #edf7f0); color: var(--status-approved-text, #1e6b37); border: 1.5px solid var(--status-approved-border, #c2e7cd); margin: 0 0 16px 0; line-height: 1.45;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px; flex-shrink: 0; color: #238545; margin-top: 1px;">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <div><?= htmlspecialchars($success) ?></div>
              </div>
            <?php endif; ?>

            <!-- Sign In Form -->
            <form class="login-form-body" id="studentLoginForm" action="user-login.php" method="POST">
              
              <!-- Field 1: Email or Student ID -->
              <div class="input-group">
                <label for="studentUserEmail" class="input-label">Student ID or Email Address</label>
                <div class="input-container">
                  <span class="input-icon-slot" aria-hidden="true">
                    <img src="icons/id-badge.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                      <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                      <line x1="7" y1="8" x2="17" y2="8"></line>
                      <line x1="7" y1="12" x2="13" y2="12"></line>
                      <line x1="7" y1="16" x2="10" y2="16"></line>
                    </svg>
                  </span>
                  <input type="text" id="studentUserEmail" name="student_id" class="portal-text-input" placeholder="e.g. 24-00909" pattern="\d{2}-\d{5}" maxlength="8" title="Format must be XX-XXXXX (e.g., 24-00909)" required autocomplete="username" value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>">
                </div>
                <small class="input-hint" style="display: block; font-size: var(--fs-2xs, 0.75rem); color: var(--admin-muted, #718096); margin-top: 4px;">Format must be XX-XXXXX (e.g., 24-00909)</small>
              </div>

              <!-- Field 2: Password -->
              <div class="input-group">
                <label for="studentUserPassword" class="input-label">Password</label>
                <div class="input-container">
                  <span class="input-icon-slot" aria-hidden="true">
                    <img src="icons/lock.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                      <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                      <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                  </span>
                  <input type="password" id="studentUserPassword" name="password" class="portal-text-input has-eye" placeholder="Enter your password" required autocomplete="current-password">
                  <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'studentUserPassword')">
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
                  <input type="checkbox" id="studentRememberMe" name="rememberMe">
                  <span>Remember me</span>
                </label>
                <a href="javascript:void(0)" onclick="alert('Password reset instructions will be sent to your email address.')" class="forgot-link">Forgot password?</a>
              </div>

              <!-- Submit Button -->
              <button type="submit" class="portal-submit-btn" id="studentLoginSubmitBtn">
                Log in
              </button>

              <!-- Centered Card Footer Options -->
              <div class="login-card-footer-stack">
                <div class="register-switch-link">
                  First time logging in? <a href="user-sign-in.php">Sign in</a>
                </div>
                <div class="role-switch-centered">
                  <a href="admin-login.php" class="role-switch-pill admin-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <span>Login as Admin &rarr;</span>
                  </a>
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

    // Relax pattern and maxlength if user enters an email containing '@'
    const loginInput = document.getElementById('studentUserEmail');
    if (loginInput) {
      loginInput.addEventListener('input', function() {
        if (this.value.includes('@')) {
          this.removeAttribute('maxlength');
          this.removeAttribute('pattern');
          this.removeAttribute('title');
        } else {
          this.setAttribute('maxlength', '8');
          this.setAttribute('pattern', '\\d{2}-\\d{5}');
          this.setAttribute('title', 'Format must be XX-XXXXX (e.g., 24-00909)');
        }
      });
    }
  </script>
</body>
</html>
