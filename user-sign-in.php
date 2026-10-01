<?php
session_start();
require_once 'alerto-db.php';

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
    $confirm_pass   = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($student_id) || empty($program) || empty($year_level) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!preg_match('/^\d{2}-\d{5}$/', $student_id)) {
        $error = "Student ID must be in the exact format XX-XXXXX (e.g., 24-00909).";
    } elseif ($password !== $confirm_pass) {
        $error = "Passwords do not match.";
    } elseif (!isset($_FILES['id_selfie']) || !isset($_FILES['assessment_form'])) {
        $error = "Please upload both your ID selfie and Certificate of Registration (COR).";
    } else {
        $stmt = $pdo->prepare("SELECT id, status FROM users WHERE student_id = ? OR email = ?");
        $stmt->execute([$student_id, $email]);
        $existingUser = $stmt->fetch();
        
        if ($existingUser) {
            // Allow resubmission if the previous account was rejected
            if ($existingUser['status'] === 'rejected') {
                $target_dir = "uploads/documents/";
                $id_selfie_name = time() . "_selfie_" . basename($_FILES["id_selfie"]["name"]);
                $cor_name       = time() . "_cor_" . basename($_FILES["assessment_form"]["name"]);

                $target_selfie = $target_dir . $id_selfie_name;
                $target_cor    = $target_dir . $cor_name;

                if (move_uploaded_file($_FILES["id_selfie"]["tmp_name"], $target_selfie) && 
                    move_uploaded_file($_FILES["assessment_form"]["tmp_name"], $target_cor)) {
                    
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                    // Update user details and reset status to unverified
                    $updateUser = $pdo->prepare("
                        UPDATE users 
                        SET full_name = ?, program = ?, year_level = ?, contact_number = ?, password = ?, status = 'unverified', created_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateUser->execute([$full_name, $program, $year_level, $contact_number, $hashed_password, $existingUser['id']]);

                    // Update or insert new verification documents
                    $checkDoc = $pdo->prepare("SELECT id FROM documents WHERE user_id = ?");
                    $checkDoc->execute([$existingUser['id']]);
                    if ($checkDoc->fetch()) {
                        $updateDoc = $pdo->prepare("UPDATE documents SET id_selfie_path = ?, assessment_form_path = ? WHERE user_id = ?");
                        $updateDoc->execute([$target_selfie, $target_cor, $existingUser['id']]);
                    } else {
                        $insertDoc = $pdo->prepare("INSERT INTO documents (user_id, id_selfie_path, assessment_form_path) VALUES (?, ?, ?)");
                        $insertDoc->execute([$existingUser['id'], $target_selfie, $target_cor]);
                    }

                    $success = "Resubmission successful! Your updated profile is pending verification.";
                } else {
                    $error = "Failed to upload verification documents. Please try again.";
                }
            } else {
                $error = "An account with this Student ID or Email already exists and is active or pending review.";
            }
        } else {
            $target_dir = "uploads/documents/";
            $id_selfie_name = time() . "_selfie_" . basename($_FILES["id_selfie"]["name"]);
            $cor_name       = time() . "_cor_" . basename($_FILES["assessment_form"]["name"]);

            $target_selfie = $target_dir . $id_selfie_name;
            $target_cor    = $target_dir . $cor_name;

            if (move_uploaded_file($_FILES["id_selfie"]["tmp_name"], $target_selfie) && 
                move_uploaded_file($_FILES["assessment_form"]["tmp_name"], $target_cor)) {
                
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                $stmtUser = $pdo->prepare("
                    INSERT INTO users (role, full_name, student_id, program, year_level, contact_number, email, password, status) 
                    VALUES ('student', ?, ?, ?, ?, ?, ?, ?, 'unverified')
                ");
                $stmtUser->execute([$full_name, $student_id, $program, $year_level, $contact_number, $email, $hashed_password]);
                $user_id = $pdo->lastInsertId();

                $stmtDoc = $pdo->prepare("
                    INSERT INTO documents (user_id, id_selfie_path, assessment_form_path) 
                    VALUES (?, ?, ?)
                ");
                $stmtDoc->execute([$user_id, $target_selfie, $target_cor]);

                $success = "Registration successful! Your account is pending verification by an administrator.";
            } else {
                $error = "Failed to upload verification documents. Please try again.";
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
  <title>Student Profile Registration | ALERTO - CSU-Carig Student Council</title>

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

  <main class="auth-page student-theme">

    <!-- Centered Registration Card Container -->
    <div class="register-card-container">

      <!-- Card Top Crimson Red Header -->
      <div class="register-card-header">
        <div class="auth-header-top-row">
          <div class="auth-live-status">
            <span class="live-pulse-dot" aria-hidden="true"></span>
            <span>Student Verification</span>
          </div>
          <span class="auth-badge-terminal">CSU-CARIG • COEA</span>
        </div>

        <div class="auth-title-lockup">
          <div class="auth-logo-box">
            <img src="logo/user-main-logo.png" alt="ALERTO Student Logo">
          </div>
          <div class="auth-text-meta">
            <h1>Create Student Profile</h1>
            <p>CSU-COEA Disaster Relief & Assistance Access</p>
          </div>
        </div>
      </div>

      <!-- Registration Form Body -->
      <div class="register-card-body">

        <?php if (!empty($error)): ?>
          <div class="alert-banner alert-error" role="alert" style="display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: var(--radius-sm, 8px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: var(--status-rejected-bg, #fdf0f2); color: var(--status-rejected-text, #9c2438); border: 1.5px solid var(--status-rejected-border, #f8c9d1); line-height: 1.45;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px; flex-shrink: 0; color: #b82b43; margin-top: 1px;">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="12"></line>
              <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <div><?= htmlspecialchars($error) ?></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
          <div class="alert-banner alert-success" role="alert" style="display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: var(--radius-sm, 8px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: var(--status-approved-bg, #edf7f0); color: var(--status-approved-text, #1e6b37); border: 1.5px solid var(--status-approved-border, #c2e7cd); line-height: 1.45;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px; flex-shrink: 0; color: #238545; margin-top: 1px;">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <div><?= htmlspecialchars($success) ?></div>
          </div>
        <?php endif; ?>

        <form class="auth-form" id="studentRegisterForm" action="user-sign-in.php" method="POST" enctype="multipart/form-data">

          <!-- 1. Full Name -->
          <div class="form-field-group">
            <label for="studentFullName" class="form-label">
              <span>Full Name</span>
              <span class="form-label-tag">Enrolled Student</span>
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
              <input type="text" id="studentFullName" name="full_name" class="auth-input" placeholder="e.g. Kriz Bonifacio" required
                autocomplete="name">
            </div>
          </div>

          <!-- 2. Student ID Number -->
          <div class="form-field-group">
            <label for="studentIdNumber" class="form-label">
              <span>Student ID Number</span>
              <span class="form-label-tag">CSU ID Card</span>
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
              <input type="text" id="studentIdNumber" name="student_id" class="auth-input" placeholder="e.g. 24-00909" pattern="\d{2}-\d{5}" maxlength="8" title="Format must be XX-XXXXX (e.g., 24-00909)" required
                autocomplete="username">
            </div>
            <small class="input-hint" style="display:block; font-size: var(--fs-2xs, 0.75rem); color: var(--admin-muted, #718096); margin-top: 4px;">Format must be XX-XXXXX (e.g., 24-00909)</small>
          </div>

          <!-- 3. Program & Year Level -->
          <div class="form-dual-grid">

            <!-- Program -->
            <div class="form-field-group">
              <label for="studentProgram" class="form-label">
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
                <select id="studentProgram" name="program" class="auth-select" required>
                  <option value="" disabled selected>Select Program</option>
                  <option value="BS in Civil Engineering">BS in Civil Engineering</option>
                  <option value="BS in Electrical Engineering">BS in Electrical Engineering</option>
                  <option value="BS in Geodetic Engineering">BS in Geodetic Engineering</option>
                  <option value="BS in Computer Engineering">BS in Computer Engineering</option>
                  <option value="BS in Chemical Engineering">BS in Chemical Engineering</option>
                  <option value="BS in Architecture">BS in Architecture</option>
                  <option value="BS in Electronics Engineering">BS in Electronics Engineering</option>
                  <option value="BS in Agricultural and Biosystems Engineering">BS in Agricultural and Biosystems
                    Engineering</option>
                </select>
              </div>
            </div>

            <!-- Year Level -->
            <div class="form-field-group">
              <label for="studentYearLevel" class="form-label">
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
                <select id="studentYearLevel" name="year_level" class="auth-select" required>
                  <option value="" disabled selected>Select Year</option>
                  <option value="1st Year">1st Year</option>
                  <option value="2nd Year">2nd Year</option>
                  <option value="3rd Year">3rd Year</option>
                  <option value="4th Year">4th Year</option>
                  <option value="5th Year">5th Year</option>
                </select>
              </div>
            </div>

          </div>

          <!-- 4. Contact Number -->
          <div class="form-field-group">
            <label for="studentContact" class="form-label">
              <span>Contact Number</span>
              <span class="form-label-tag">Mobile / Phone</span>
            </label>
            <div class="input-with-icon">
              <span class="input-icon-slot" aria-hidden="true">
                <img src="icons/phone.svg" alt=""
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round" style="display:none;">
                  <path
                    d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z">
                  </path>
                </svg>
              </span>
              <input type="tel" id="studentContact" name="contact_number" class="auth-input" placeholder="e.g. 0912 345 6789" required
                autocomplete="tel">
            </div>
          </div>

          <!-- 5. Email Address -->
          <div class="form-field-group">
            <label for="studentEmail" class="form-label">
              <span>Email Address</span>
              <span class="form-label-tag">email</span>
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
              <input type="email" id="studentEmail" name="email" class="auth-input" placeholder="e.g. kriz.bonifacio@gmail.com"
                required autocomplete="email">
            </div>
          </div>

          <!-- Verification Documents -->
          <div class="form-dual-grid">

            <!-- School ID / Valid ID Selfie -->
            <div class="form-field-group">
              <label class="form-label">
                <span>Selfie with School ID/Government ID</span>
                <span class="form-label-tag">JPG, PNG</span>
              </label>
              <div class="upload-card-box" onclick="document.getElementById('validIdFileInput').click()">
                <input type="file" id="validIdFileInput" name="id_selfie" class="hidden-file-input" accept="image/*,.pdf" required
                  onchange="handleFileSelected(this, 'validIdBadge', 'validIdText')">
                <div class="upload-card-icon" aria-hidden="true">
                  <img src="icons/camera.svg" alt=""
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" style="display:none;">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                    <circle cx="12" cy="13" r="4"></circle>
                  </svg>
                </div>
                <span class="upload-main-text" id="validIdText">Upload Selfie with ID</span>
                <span class="upload-sub-text">Click to select image file</span>
                <span class="file-selected-badge" id="validIdBadge">File attached &check;</span>
              </div>
            </div>

            <!-- Assessment Form (COR) -->
            <div class="form-field-group">
              <label class="form-label">
                <span>Assessment Form (COR)</span>
                <span class="form-label-tag">PDF, JPG</span>
              </label>
              <div class="upload-card-box" onclick="document.getElementById('assessmentFileInput').click()">
                <input type="file" id="assessmentFileInput" name="assessment_form" class="hidden-file-input" accept=".pdf,image/*" required
                  onchange="handleFileSelected(this, 'corBadge', 'corText')">
                <div class="upload-card-icon" aria-hidden="true">
                  <img src="icons/document.svg" alt=""
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" style="display:none;">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                  </svg>
                </div>
                <span class="upload-main-text" id="corText">Upload Assessment Form</span>
                <span class="upload-sub-text">Certificate of Registration</span>
                <span class="file-selected-badge" id="corBadge">File attached &check;</span>
              </div>
            </div>

          </div>

          <!-- 6. Password Fields -->
          <div class="form-dual-grid">

            <!-- Create Password -->
            <div class="form-field-group">
              <label for="studentPassword" class="form-label">
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
                <input type="password" id="studentPassword" name="password" class="auth-input has-eye" placeholder="Create password"
                  required autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'studentPassword')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Repeat Password -->
            <div class="form-field-group">
              <label for="studentRepeatPassword" class="form-label">
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
                <input type="password" id="studentRepeatPassword" name="confirm_password" class="auth-input has-eye"
                  placeholder="Confirm password" required autocomplete="new-password">
                <button type="button" class="password-eye-toggle-btn" aria-label="Show password" onclick="togglePasswordEye(this, 'studentRepeatPassword')">
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
            <button type="button" class="quick-fill-btn" onclick="quickFillStudentProfile()">
              <img src="icons/bolt.svg" alt="" style="width:12px;height:12px;"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                style="display:none;">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
              </svg>
              <span>Fill Sample Student</span>
            </button>
          </div>

          <!-- Submit Button -->
          <button type="submit" class="auth-submit-btn" id="studentRegSubmitBtn">
            <span>Sign in</span>
            <img src="icons/arrow-right.svg" alt="" style="width:16px;height:16px;"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" style="display:none;">
              <line x1="5" y1="12" x2="19" y2="12"></line>
              <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
          </button>

        </form>

        <!-- Footer link back to Student Sign In -->
        <div class="auth-footer-block">
          <div class="security-badge">
            <img src="icons/shield-check.svg" alt="" style="width:14px;height:14px;"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" style="display:none;">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
            <span>CSU-COEA Student Verification & Relief Coordination Standard</span>
          </div>

          <a href="user-login.php" class="auth-back-link">
            <span>Already registered? <strong>Sign In to your account &rarr;</strong></span>
          </a>
        </div>

      </div>

    </div>

  </main>

  <script>
    // Handle File Pickers
    function handleFileSelected(input, badgeId, textId) {
      if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        document.getElementById(textId).textContent = fileName.length > 22 ? fileName.substring(0, 20) + '...' : fileName;
        document.getElementById(badgeId).style.display = 'inline-block';
      }
    }

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

    // Quick Fill Student Profile
    function quickFillStudentProfile() {
      document.getElementById('studentFullName').value = 'Kriz Bonifacio';
      document.getElementById('studentIdNumber').value = '24-00909';
      document.getElementById('studentProgram').value = 'BS in Civil Engineering';
      document.getElementById('studentYearLevel').value = '3rd Year';
      document.getElementById('studentContact').value = '0917 890 1234';
      document.getElementById('studentEmail').value = 'kriz@gmail.com';
      document.getElementById('studentPassword').value = 'studentPass2026';
      document.getElementById('studentRepeatPassword').value = 'studentPass2026';
    }
  </script>
</body>

</html>