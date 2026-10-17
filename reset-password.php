<?php
session_start();
require_once 'alerto-db.php';
require_once 'password-recovery.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errorMessage = '';
$successMessage = '';
$isValidToken = false;
$userEmail = '';

// Handle Password Reset Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    $result = consume_token_and_reset_password($pdo, $token, $newPassword, $confirmPassword, $csrfToken);

    if ($result['success']) {
        $successMessage = $result['message'];
        $isValidToken = false; // Form will be hidden, show success state & login button
    } else {
        $errorMessage = $result['message'];
        // Re-validate token to know whether to keep showing form or show invalid link error
        $validation = validate_reset_token($pdo, $token);
        $isValidToken = $validation['valid'];
        if ($isValidToken) {
            $userEmail = $validation['entry']['email'];
        }
    }
} else {
    // Initial GET request - validate token
    if (empty($token)) {
        $errorMessage = "Missing or invalid password reset link. Please request a new one.";
        $isValidToken = false;
    } else {
        $validation = validate_reset_token($pdo, $token);
        if ($validation['valid']) {
            $isValidToken = true;
            $userEmail = $validation['entry']['email'];
        } else {
            $isValidToken = false;
            $errorMessage = $validation['message'];
        }
    }
}

$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Plus Jakarta Sans, Inter & JetBrains Mono -->
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
    
    <!-- Top Navigation Header (ALERTO Logo & CSU-COEA on top) -->
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

    <!-- Main Center Container (Centered Portal Card matching login page UI) -->
    <main class="login-main-container">
      <div class="login-portal-card">
        
        <!-- Card Header -->
        <div class="portal-card-header">
          <div class="portal-avatar-circle" aria-hidden="true">
            <img src="icons/lock.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="#700d23" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
          </div>
          <div class="portal-title-block">
            <h2>Reset Password</h2>
            <p>Enter your new password to update your account credentials.</p>
          </div>
        </div>

        <!-- Error & Success Alert Banners -->
        <?php if (!empty($errorMessage)): ?>
          <div class="alert-banner alert-error" role="alert" style="margin-bottom: 18px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="12"></line>
              <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <div><?= htmlspecialchars($errorMessage) ?></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
          <div class="alert-banner alert-success" role="alert" style="margin-bottom: 18px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <div><?= htmlspecialchars($successMessage) ?></div>
          </div>

          <div style="margin-top: 20px;">
            <a href="user-login.php" class="portal-submit-btn" style="text-decoration: none; display: block; text-align: center;">
              Log in with new password
            </a>
          </div>
        <?php elseif (!$isValidToken): ?>
          <div style="margin-top: 16px; text-align: center;">
            <a href="user-login.php?action=forgot-password" class="forgot-link" style="font-weight: 700;">
              &larr; Request a new reset link
            </a>
          </div>
        <?php else: ?>

          <!-- Set Password Form (Exact same UI as login page) -->
          <form class="login-form-body" method="POST" action="reset-password.php" onsubmit="return validatePasswords();">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <!-- Field 1: New Password -->
            <div class="input-group">
              <label for="newPassword" class="input-label">New Password</label>
              <div class="input-container">
                <span class="input-icon-slot" aria-hidden="true">
                  <img src="icons/lock.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </span>
                <input type="password" id="newPassword" name="new_password" class="portal-text-input has-eye" placeholder="Enter new password" required minlength="8" autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'newPassword')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
              <small class="input-hint">Password requirements: At least 8 characters</small>
            </div>

            <!-- Field 2: Confirm New Password -->
            <div class="input-group">
              <label for="confirmPassword" class="input-label">Confirm New Password</label>
              <div class="input-container">
                <span class="input-icon-slot" aria-hidden="true">
                  <img src="icons/lock.svg" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg class="svg-fallback" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </span>
                <input type="password" id="confirmPassword" name="confirm_password" class="portal-text-input has-eye" placeholder="Re-enter new password" required minlength="8" autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'confirmPassword')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Submit Button (Exact login page crimson button) -->
            <button type="submit" class="portal-submit-btn" id="resetSubmitBtn" style="margin-top: 6px;">
              Set Password
            </button>

            <!-- Centered Card Footer Options -->
            <div class="login-card-footer-stack">
              <div class="register-switch-link">
                Remember your password? <a href="user-login.php">Log In</a>
              </div>
            </div>

          </form>

        <?php endif; ?>

      </div>
    </main>

    <!-- Bottom Wavy Crimson Red Curve (Wave footer from login page) -->
    <div class="login-wave-footer" aria-hidden="true">
      <svg viewBox="0 0 1440 120" preserveAspectRatio="none">
        <path d="M0,45 C320,120 440,15 720,70 C960,115 1200,30 1440,85 L1440,120 L0,120 Z"></path>
      </svg>
    </div>

  </div>

  <script>
    // Toggle Password Visibility with Eye Icon (matches login page)
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

    // Client-side quick check
    function validatePasswords() {
      const p1 = document.getElementById('newPassword').value;
      const p2 = document.getElementById('confirmPassword').value;

      if (p1.length < 8) {
        alert('Password must be at least 8 characters long.');
        return false;
      }
      if (p1 !== p2) {
        alert('Passwords do not match.');
        return false;
      }
      return true;
    }
  </script>

</body>
</html>
