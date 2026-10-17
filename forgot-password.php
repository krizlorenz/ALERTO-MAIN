<?php
session_start();
require_once 'alerto-db.php';

$successMessage = '';
$errorMessage = '';
$devResetUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $errorMessage = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address.";
    } else {
        try {
            // Check if user exists in database
            $stmt = $pdo->prepare("SELECT id, email, full_name, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate a secure 64-character token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Invalidate any existing tokens for this email
                $del = $pdo->prepare("DELETE FROM password_resets WHERE LOWER(email) = LOWER(?)");
                $del->execute([$email]);

                // Store token in database using MySQL time to prevent timezone mismatch
                $ins = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                $ins->execute([$user['email'], $token]);

                // Build absolute reset link
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $resetUrl = $scheme . '://' . $host . $dir . '/reset-password.php?token=' . urlencode($token);
                $devResetUrl = $resetUrl;

                // Send email
                $to = $user['email'];
                $subject = "ALERTO - Create or Update Password";
                $message = "Hello " . htmlspecialchars($user['full_name'] ?: 'User') . ",\n\n"
                         . "You requested instructions to create or update your ALERTO account password.\n\n"
                         . "Please click the link below to set your password:\n"
                         . $resetUrl . "\n\n"
                         . "This verification link is valid for 1 hour. If you did not make this request, you can safely ignore this email.\n\n"
                         . "Best regards,\n"
                         . "ALERTO CSU-COEA Student Council";
                $headers = "From: ALERTO Support <no-reply@alerto.csu.edu.ph>\r\n"
                         . "Reply-To: no-reply@alerto.csu.edu.ph\r\n"
                         . "X-Mailer: PHP/" . phpversion();

                @mail($to, $subject, $message, $headers);
            }

            // Always display standard generic success notice to protect user privacy
            $successMessage = "Instructions to create or update your password have been sent to your email address.";
        } catch (Exception $e) {
            $errorMessage = "An unexpected error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create or Update Password | ALERTO - CSU-Carig</title>

  <!-- Google Fonts: Inter & Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/auth.css?v=<?= time() ?>">
</head>
<body>

  <div class="cisco-auth-wrapper">
    
    <!-- Left Hero Column (Photo 1 exact layout) -->
    <div class="cisco-hero-panel">
      
      <!-- Decorative Vector Art with Wavy Contour Loop & Shapes -->
      <svg class="cisco-hero-art" viewBox="0 0 700 800" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Floating colorful accents -->
        <!-- Pink Capsule / Pill -->
        <rect x="180" y="160" width="46" height="46" rx="23" fill="#ec4899" />
        <path d="M180 160 H203 A23 23 0 0 1 226 183 V206 H180 Z" fill="#f43f5e" />
        
        <!-- Blue Dot -->
        <circle cx="150" cy="210" r="14" fill="#38bdf8" />

        <!-- Small Cyan Pill -->
        <rect x="95" y="650" width="24" height="24" rx="6" fill="#06b6d4" />

        <!-- Orange / Gold Hex Flower Badge -->
        <g transform="translate(30, 670)">
          <path d="M26 4L42 13L46 31L35 44L17 44L6 31L10 13Z" fill="#f59e0b" />
          <circle cx="26" cy="26" r="8" fill="#d97706" />
        </g>

        <!-- Wavy Outline Contour Loop -->
        <path d="M -50 250 
                 C 20 230, 40 270, 70 250 
                 C 110 230, 110 320, 150 310 
                 C 190 300, 180 390, 200 420 
                 C 215 450, 170 510, 160 550 
                 C 150 580, 120 570, 90 610 
                 C 60 650, -10 600, -50 620" 
              stroke="url(#contourGradient)" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
        
        <defs>
          <linearGradient id="contourGradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#38bdf8" />
            <stop offset="35%" stop-color="#3b82f6" />
            <stop offset="70%" stop-color="#ec4899" />
            <stop offset="100%" stop-color="#f59e0b" />
          </linearGradient>
        </defs>
      </svg>

      <!-- Main Content -->
      <div class="cisco-hero-content">
        <div class="cisco-brand-lockup">
          <img src="logo/main_logo.png" alt="ALERTO Logo" class="cisco-brand-logo" onerror="this.style.display='none'">
          <div>
            <div class="cisco-brand-title">ALERTO</div>
            <div class="cisco-brand-sub">CSU - Carig Campus &bull; COEA</div>
          </div>
        </div>

        <h1 class="cisco-hero-heading">
          Build Your Resilience<br>With ALERTO
        </h1>

        <p class="cisco-hero-desc">
          A centralized web platform for students to request assistance, follow official advisories, and stay informed — empowering our campus community.
        </p>
      </div>

    </div>

    <!-- Right Form Column (Photo 1 exact layout) -->
    <div class="cisco-form-panel">
      
      <!-- Top Navigation: Language Selector -->
      <div class="cisco-top-nav">
        <div class="cisco-lang-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="2" y1="12" x2="22" y2="12"></line>
            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
          </svg>
          <span>English (English)</span>
          <span class="cisco-lang-dot"></span>
        </div>
      </div>

      <!-- Center Form Container -->
      <div class="cisco-form-center">
        
        <!-- Back to Login Navigation Button -->
        <a href="user-login.php" class="cisco-back-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
          </svg>
          <span>Go back</span>
        </a>

        <!-- Titles exactly from Photo 1 -->
        <h2 class="cisco-page-title">Create or Update Password</h2>
        <p class="cisco-page-subtitle">Enter your email address for instructions to create or update your password.</p>

        <!-- Status Alerts -->
        <?php if (!empty($errorMessage)): ?>
          <div class="alert-banner alert-error" style="margin-bottom: 20px;">
            <span><?= htmlspecialchars($errorMessage) ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
          <div class="alert-banner alert-success" style="margin-bottom: 20px;">
            <span><?= htmlspecialchars($successMessage) ?></span>
          </div>

          <?php if (!empty($devResetUrl)): ?>
            <!-- Local Development Simulation Helper -->
            <div class="cisco-dev-alert">
              <strong>Email Verification Link (Local Testing):</strong><br>
              A password reset link was generated. Click below to proceed to the Set Password page:<br>
              <a href="<?= htmlspecialchars($devResetUrl) ?>" style="margin-top: 6px; display: inline-block;">
                &rarr; <?= htmlspecialchars($devResetUrl) ?>
              </a>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Form without placeholder as requested -->
        <form method="POST" action="forgot-password.php">
          <div class="cisco-form-group">
            <label for="email" class="cisco-label">Email *</label>
            <input type="email" id="email" name="email" class="cisco-input" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autofocus>
          </div>

          <button type="submit" class="cisco-submit-btn">Submit</button>
        </form>

      </div>

      <!-- Empty footer spacer for balanced layout -->
      <div></div>

    </div>

  </div>

</body>
</html>
