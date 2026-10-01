<?php
session_start();
require_once 'alerto-db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: admin-login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name      = trim($_POST['full_name'] ?? '');
    $student_id     = trim($_POST['student_id'] ?? '');
    $program        = trim($_POST['program'] ?? '');
    $year_level     = trim($_POST['year_level'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirm        = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($student_id) || empty($program) || empty($year_level) || empty($contact_number) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!preg_match('/^\d{2}-\d{5}$/', $student_id)) {
        $error = "Student ID must be in the exact format XX-XXXXX (e.g., 24-00909).";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR email = ?");
        $stmt->execute([$student_id, $email]);

        if ($stmt->fetch()) {
            $error = "An account with this Student ID or Email already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmtIns = $pdo->prepare("
                INSERT INTO users (role, full_name, student_id, program, year_level, contact_number, email, password, status)
                VALUES ('admin', ?, ?, ?, ?, ?, ?, ?, 'verified')
            ");
            $stmtIns->execute([$full_name, $student_id, $program, $year_level, $contact_number, $email, $hashed]);
            $success = "Admin account for " . htmlspecialchars($full_name) . " has been created successfully.";
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

  <!-- Google Fonts: Poppins & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap"
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
            <img src="logo/user-main-logo.png" alt="ALERTO Admin Logo">
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
        <div role="alert" style="
          margin-bottom: 18px;
          padding: 12px 16px;
          background: rgba(220,38,38,0.1);
          border: 1px solid rgba(220,38,38,0.35);
          border-radius: 10px;
          color: #dc2626;
          font-size: 0.875rem;
          font-weight: 500;
          display: flex;
          align-items: center;
          gap: 8px;
        ">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div role="status" style="
          margin-bottom: 18px;
          padding: 12px 16px;
          background: rgba(22,163,74,0.1);
          border: 1px solid rgba(22,163,74,0.35);
          border-radius: 10px;
          color: #16a34a;
          font-size: 0.875rem;
          font-weight: 500;
          display: flex;
          align-items: center;
          gap: 8px;
        ">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
          </svg>
          <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <form class="auth-form" id="adminRegisterForm" method="POST" action="admin-add-sign-in.php">

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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round" style="display:none;">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                  <circle cx="12" cy="7" r="4"></circle>
                </svg>
              </span>
              <input type="text" id="regFullName" name="full_name" class="auth-input"
                placeholder="e.g. Kriz Bonifacio" required autocomplete="name"
                value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
          </div>

          <!-- 2. Student ID Number -->
          <div class="form-field-group">
            <label for="regStudentId" class="form-label">
              <span>Student ID Number</span>
              <span class="form-label-tag">Institutional ID</span>
            </label>
            <div class="input-with-icon">
              <span class="input-icon-slot" aria-hidden="true">
                <img src="icons/id-badge.svg" alt=""
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round" style="display:none;">
                  <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                  <line x1="7" y1="8" x2="17" y2="8"></line>
                  <line x1="7" y1="12" x2="13" y2="12"></line>
                  <line x1="7" y1="16" x2="10" y2="16"></line>
                </svg>
              </span>
              <input type="text" id="regStudentId" name="student_id" class="auth-input"
                placeholder="e.g. 24-00909" required autocomplete="username"
                pattern="\d{2}-\d{5}" maxlength="8"
                title="Format must be XX-XXXXX (e.g., 24-00909)"
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
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" style="display:none;">
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
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" style="display:none;">
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                  <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                </svg>
              </span>
              <input type="tel" id="adminContact" name="contact_number" class="auth-input" placeholder="e.g. 0912 345 6789" required autocomplete="tel">
            </div>
          </div>

          <!-- 4. Institutional Email -->
          <div class="form-field-group">
            <label for="regEmail" class="form-label">
              <span>Email Address</span>
              <span class="form-label-tag">CSU Domain</span>
            </label>
            <div class="input-with-icon">
              <span class="input-icon-slot" aria-hidden="true">
                <img src="icons/mail.svg" alt=""
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round" style="display:none;">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                  <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
              </span>
              <input type="email" id="regEmail" name="email" class="auth-input"
                placeholder="e.g. kriz@gmail.com" required autocomplete="email"
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
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" style="display:none;">
                    <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </span>
                <input type="password" id="regPassword" name="password" class="auth-input has-eye"
                  placeholder="Create password" required autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'regPassword')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
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
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" style="display:none;">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                  </svg>
                </span>
                <input type="password" id="regRepeatPassword" name="confirm_password" class="auth-input has-eye"
                  placeholder="Confirm password" required autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'regRepeatPassword')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
            </div>

          </div>

          <!-- Quick Fill Option -->
          <div class="auth-extras-row" style="justify-content: flex-end;">
            <button type="button" class="quick-fill-btn" onclick="quickFillRegisterProfile()">
              <img src="icons/bolt.svg" alt="" style="width:12px;height:12px;"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                style="display:none;">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
              </svg>
              <span>Fill Sample DRRM Head</span>
            </button>
          </div>

          <!-- Submit Button -->
          <button type="submit" class="auth-submit-btn" id="regSubmitBtn">
            <span>Create Admin Account</span>
            <img src="icons/arrow-right.svg" alt="" style="width:16px;height:16px;"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" style="display:none;">
              <line x1="5" y1="12" x2="19" y2="12"></line>
              <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
          </button>

        </form>

        <!-- Footer link back to Sign In -->
        <div class="auth-footer-block">
          <div class="security-badge">
            <img src="icons/shield-check.svg" alt="" style="width:14px;height:14px;"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" style="display:none;">
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

    // Quick Fill Register Profile
    function quickFillRegisterProfile() {
      document.getElementById('regFullName').value = 'Kriz Bonifacio';
      document.getElementById('regStudentId').value = '24-00909';
      document.getElementById('regProgram').value = 'BS in Civil Engineering';
      document.getElementById('regYearLevel').value = '4th Year';
      document.getElementById('regEmail').value = 'kriz@gmail.com';
      document.getElementById('regPassword').value = 'alertoDRRM2026';
      document.getElementById('regRepeatPassword').value = 'alertoDRRM2026';
    }
  </script>
</body>

</html>
