<?php
session_start();
require_once 'alerto-db.php';

// Process logout if action=logout is passed
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = array();
    session_destroy();
    header("Location: user-login.php");
    exit;
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'admin', 'superadmin'])) {
    header("Location: user-login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: user-login.php");
    exit;
}

// Retrieve dynamic system settings (contacts & request intake switch)
$sysSettings = get_system_settings($pdo);
$contactPhone = $sysSettings['contact_phone'] ?? '09556678451';
$contactEmail = $sysSettings['contact_email'] ?? 'alertoCOEA@gmail.com';
$isAssistanceEnabled = ($sysSettings['request_assistance_enabled'] ?? '1') === '1';

// Handle request cancellation from user dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_request' && isset($_POST['request_id'])) {
    $req_id = intval($_POST['request_id']);
    $stmtCancel = $pdo->prepare("UPDATE assistance_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND LOWER(status) IN ('pending', 'approved')");
    $stmtCancel->execute([$req_id, $user['id']]);
    header("Location: user-homepage.php?cancelled=1");
    exit;
}

// Fetch active or completed assistance request from database
// Exclude cancelled and rejected so the student is free to file a new request or view current state
$stmtReq = $pdo->prepare("
    SELECT * FROM assistance_requests 
    WHERE user_id = ? AND LOWER(status) NOT IN ('cancelled', 'rejected') 
    ORDER BY id DESC LIMIT 1
");
$stmtReq->execute([$user['id']]);
$activeRequest = $stmtReq->fetch(PDO::FETCH_ASSOC);

$initials = implode('', array_map(fn($n) => strtoupper($n[0] ?? ''), array_slice(explode(' ', $user['full_name']), 0, 2)));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Student Portal | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Poppins & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap"
    rel="stylesheet">

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/landing.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/student.css?v=<?= time() ?>">
</head>

<body>

  <!-- Top Sticky Navbar (Unified format with Admin page) -->
  <header class="navbar">
    <div class="navbar-inner">
      <a href="user-homepage.php" class="brand" aria-label="ALERTO Student Home">
        <div class="logo-container">
          <img src="logo/user-main-logo.png" alt="ALERTO User Logo" class="logo-img">
        </div>
        <div class="brand-text">
          <span class="brand-name">ALERTO</span>
          <span class="brand-sub">CSU-COEA Student Council</span>
        </div>
      </a>

      <!-- Desktop Navigation Links -->
      <nav class="main-nav" id="mainNav" aria-label="Main Navigation">
        <a href="user-homepage.php" class="active">Home</a>
        <?php if ($isAssistanceEnabled || $activeRequest): ?>
          <a href="user-request.php">Request Assistance</a>
        <?php else: ?>
          <span style="color: rgba(255,255,255,0.45); font-size: var(--fs-sm); font-weight: 600; padding: 6px 10px; cursor: not-allowed;" title="Intake currently closed">Request Assistance (Closed)</span>
        <?php endif; ?>
        <a href="#contactSection">COEASC DRRM Contacts</a>
        <a href="user-homepage.php?action=logout" class="nav-logout-btn">Logout</a>
      </nav>

      <!-- Mobile Menu Toggle Button -->
      <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobileNavMenu">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
  </header>

  <!-- Mobile Navigation Dropdown Menu -->
  <div class="mobile-nav-menu" id="mobileNavMenu" aria-label="Mobile Navigation Menu">
    <ul>
      <li>
        <a href="user-homepage.php" class="active">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
          </svg>
          <span>Home</span>
        </a>
      </li>
      <li>
        <?php if ($isAssistanceEnabled || $activeRequest): ?>
          <a href="user-request.php">
            <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
            <span>Request Assistance</span>
          </a>
        <?php else: ?>
          <a href="javascript:void(0)" onclick="alert('Notice: Assistance request submission is currently closed. The COEASC DRRM Department enables requests during active post-disaster response.');" style="opacity: 0.6;">
            <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
            <span>Request Assistance (Closed)</span>
          </a>
        <?php endif; ?>
      </li>
      <li>
        <a href="#contactSection">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
          </svg>
          <span>COEASC DRRM Contacts</span>
        </a>
      </li>
      <li class="mobile-nav-divider" role="separator"></li>
      <li>
        <a href="user-homepage.php?action=logout" class="mobile-nav-logout-btn">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
          <span>Logout</span>
        </a>
      </li>
    </ul>
  </div>

  <main>
    <!-- Hero Section (Matches Admin format without Council Ops overview stats) -->
    <section class="hero" aria-labelledby="hero-title">

      <!-- Hero Background Media -->
      <div class="hero-bg-media">
        <img src="images/coea.png" alt="CSU COEA Building" class="hero-bg-img" onerror="this.style.display='none';">
        <div class="hero-gradient-overlay" aria-hidden="true"></div>
      </div>

      <!-- Hero Content Container -->
      <div class="hero-container">
        <div class="hero-content">
          <span class="hero-pill">ALERTO: Always Ready. Always Here.</span>
          <h1 class="hero-title" id="hero-title">
            Student Relief Portal,
            <span class="hero-title-highlight">when it matters most.</span>
          </h1>
          <p class="hero-copy">
            Submit and track disaster relief requests, communicate directly with the COEASC DRRM Department, and receive verified student council assistance.
          </p>

          <!-- Quick Action Button in Hero -->
          <div class="hero-cta-group">
            <?php if ($isAssistanceEnabled): ?>
              <button type="button" class="hero-cta-btn request-btn" onclick="handleRequestAssistanceClick()" style="border: none; cursor: pointer;">
                <span>+ Request Assistance Now</span>
              </button>
            <?php else: ?>
              <div style="display: flex; flex-direction: column; gap: 6px;">
                <button type="button" class="hero-cta-btn request-btn" disabled style="border: none; cursor: not-allowed; background: #94a3b8; color: #f8fafc; box-shadow: none; opacity: 0.8;" title="Assistance request intake is currently closed">
                  <span>+ Request Assistance (Currently Closed)</span>
                </button>
                <span style="font-size: var(--fs-2xs); color: #78656a; font-weight: 600;">
                  ℹ️ Request intake is enabled by council admins during post-disaster events.
                </span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </section>

    <!-- Student Dashboard Main Section -->
    <section class="student-portal-section" style="padding: var(--space-8) 0 var(--space-12);">
      <div class="container" style="display: flex; flex-direction: column; gap: var(--space-6);">

        <!-- Action Status Alerts -->
        <?php if (isset($_GET['cancelled'])): ?>
          <div class="alert-banner alert-warning" role="alert" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 18px; border-radius: var(--radius-sm, 10px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: #fff8f0; color: #9a4800; border: 1.5px solid #fed7aa; box-shadow: 0 4px 12px rgba(154, 72, 0, 0.08);">
            <div style="display: flex; align-items: center; gap: 10px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px; flex-shrink: 0; color: #ea580c;">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
              </svg>
              <span>Your assistance request has been successfully cancelled.</span>
            </div>
            <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.2rem; color: #9a4800; cursor: pointer; padding: 0 4px;">&times;</button>
          </div>
        <?php elseif (isset($_GET['requested'])): ?>
          <div class="alert-banner alert-success" role="alert" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 18px; border-radius: var(--radius-sm, 10px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: #edf7f0; color: #1e6b37; border: 1.5px solid #c2e7cd; box-shadow: 0 4px 12px rgba(30, 107, 55, 0.08);">
            <div style="display: flex; align-items: center; gap: 10px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width: 20px; height: 20px; flex-shrink: 0; color: #1e6b37;">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
              <span>Your assistance request has been submitted to the COEASC DRRM Department. You can track live category approval below.</span>
            </div>
            <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.2rem; color: #1e6b37; cursor: pointer; padding: 0 4px;">&times;</button>
          </div>
        <?php elseif (isset($_GET['updated'])): ?>
          <div class="alert-banner alert-success" role="alert" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 18px; border-radius: var(--radius-sm, 10px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: #edf7f0; color: #1e6b37; border: 1.5px solid #c2e7cd; box-shadow: 0 4px 12px rgba(30, 107, 55, 0.08);">
            <div style="display: flex; align-items: center; gap: 10px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width: 20px; height: 20px; flex-shrink: 0; color: #1e6b37;">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
              <span>Your assistance request details have been updated successfully.</span>
            </div>
            <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.2rem; color: #1e6b37; cursor: pointer; padding: 0 4px;">&times;</button>
          </div>
        <?php endif; ?>

        <!-- Disaster Intake Inactive Notice (Shown when intake is OFF and student has no active request) -->
        <?php if (!$isAssistanceEnabled && !$activeRequest): ?>
          <div style="display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-radius: var(--radius-sm); font-size: var(--fs-xs); background: #f8fafc; color: #475569; border: 1.5px solid #e2e8f0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #64748b; flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <div>
              <strong>Assistance Request Intake Inactive:</strong> The COEASC DRRM Department activates assistance submissions during or immediately after a disaster event affecting students. If you need direct council assistance, please contact the numbers below.
            </div>
          </div>
        <?php endif; ?>

        <!-- Quick Access COEASC DRRM Contact Strip -->
        <div class="quick-hotline-strip" role="region" aria-label="COEASC DRRM Department Quick Contact Bar">
          <div class="hotline-meta">
            <div class="hotline-icon">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
            </div>
            <div>
              <div style="font-family: var(--heading-font); font-weight: 800; font-size: var(--fs-sm); line-height: 1.2;">COEASC DRRM Department Contacts</div>
              <div style="font-size: var(--fs-2xs); opacity: 0.9; margin-top: 2px;">Direct contact with the Student Council DRRM Department for relief coordination &amp; inquiries</div>
            </div>
          </div>
          <div class="hotline-actions">
            <a href="tel:<?= htmlspecialchars($contactPhone) ?>" class="hotline-btn-pill phone-btn" aria-label="Call COEASC DRRM Contact <?= htmlspecialchars($contactPhone) ?>">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
              <span><?= htmlspecialchars($contactPhone) ?></span>
            </a>
            <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" class="hotline-btn-pill email-btn" aria-label="Email COEASC DRRM Department">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
              <span><?= htmlspecialchars($contactEmail) ?></span>
            </a>
          </div>
        </div>

        <!-- Identity Verification Card (GCash-style CSU profile badge) -->
        <section class="gcash-profile-card" aria-label="Student Identity Verification Details">
          <div class="gcash-card-inner">

            <!-- Left Info Lockup -->
            <div class="gcash-student-info-col">
              <div class="gcash-avatar-box">
                <span><?= htmlspecialchars($initials) ?></span>
              </div>
              <div class="gcash-text-meta">
                <h2>
                  <span><?= htmlspecialchars($user['full_name']) ?></span>
                  <span id="nameVerifiedCheck">
                    <svg style="width: 20px; height: 20px; fill: #38d39f;" viewBox="0 0 24 24">
                      <path
                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                    </svg>
                  </span>
                </h2>
                <div class="gcash-sub-meta">
                  <span>Student ID: <strong><?= htmlspecialchars($user['student_id']) ?></strong></span>
                  <span>&bull;</span>
                  <span><?= htmlspecialchars($user['program']) ?></span>
                  <span>&bull;</span>
                  <span><?= htmlspecialchars($user['year_level']) ?></span>
                </div>
                <div style="font-size: var(--fs-2xs); color: rgba(255,255,255,0.8); margin-top: 3px;">
                  <?= htmlspecialchars($user['email']) ?>
                </div>
              </div>
            </div>

            <!-- Right Verification Decision Badge -->
            <div class="gcash-status-badge-container">
              <span class="verification-decision-label">Council Verification Status</span>
              <div id="verificationBadgeContainer">
                <span class="verification-badge approved" id="currentVerificationPill">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;">
                    <polyline points="20 6 9 17 4 12"></polyline>
                  </svg>
                  <span>Approved (Verified)</span>
                </span>
              </div>
            </div>

          </div>
        </section>

        <!-- Dynamic Locked Banner (Shown if student is unverified or pending) -->
        <div class="locked-overlay-card" id="verificationLockedBanner" style="display: none;">
          <div class="locked-icon-text">
            <div class="locked-icon-badge">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" style="width: 20px; height: 20px;">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              </svg>
            </div>
            <div>
              <div class="locked-title" id="lockedBannerTitle">Verification Required</div>
              <div class="locked-desc" id="lockedBannerDesc">
                Your student profile must be Approved by the COEA Student Council before assistance requests can be
                submitted or tracked.
              </div>
            </div>
          </div>
          <a href="user-sign-in.php" class="unlock-action-btn">Re-upload Documents &rarr;</a>
        </div>

        <!-- Request Status Tracker (Green only when approved by admin according to category) -->
        <section class="tracker-section-card" id="requestsTrackSection"
          aria-label="Assistance Request Progress Tracker">

          <div class="tracker-card-header">
            <div>
              <h3 style="display: flex; align-items: center; gap: 8px;">
                <span>Request Status Update</span>
                <?php if ($activeRequest): 
                  $headerStatus = strtolower(trim($activeRequest['status'] ?? 'pending'));
                  if ($headerStatus === 'approved'): ?>
                    <span style="font-size: var(--fs-2xs); background: #edf7f0; color: #1e6b37; border: 1px solid #c2e7cd; padding: 2px 10px; border-radius: var(--radius-pill); font-weight: 700;">Approved by Admin</span>
                  <?php elseif ($headerStatus === 'pending'): ?>
                    <span style="font-size: var(--fs-2xs); background: #fdf5ea; color: #9c6008; border: 1px solid #f7ddb2; padding: 2px 10px; border-radius: var(--radius-pill); font-weight: 700;">Pending Review</span>
                  <?php elseif ($headerStatus === 'in_progress'): ?>
                    <span style="font-size: var(--fs-2xs); background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; padding: 2px 10px; border-radius: var(--radius-pill); font-weight: 700;">Dispatched</span>
                  <?php elseif (in_array($headerStatus, ['completed', 'archived'])): ?>
                    <span style="font-size: var(--fs-2xs); background: #edf7f0; color: #1e6b37; border: 1px solid #c2e7cd; padding: 2px 10px; border-radius: var(--radius-pill); font-weight: 700;">Completed</span>
                  <?php endif; ?>
                <?php endif; ?>
              </h3>
              <p>Live progress breakdown for your active disaster relief request and category approval.</p>
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
              <?php 
                $isArchivedOrCompleted = $activeRequest && in_array(strtolower(trim($activeRequest['status'] ?? '')), ['completed', 'archived']);
              ?>
              <?php if (!$activeRequest || $isArchivedOrCompleted): ?>
                <?php if ($isAssistanceEnabled): ?>
                  <button type="button" class="unlock-action-btn" onclick="handleRequestAssistanceClick()"
                    style="background: #700d23; box-shadow: 0 4px 12px rgba(112, 13, 35, 0.2); border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Request Assistance</span>
                  </button>
                <?php else: ?>
                  <button type="button" class="unlock-action-btn" disabled
                    style="background: #94a3b8; color: #f8fafc; box-shadow: none; border: none; cursor: not-allowed; opacity: 0.75; display: inline-flex; align-items: center; gap: 6px;"
                    title="Assistance request intake is currently closed by the council">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Intake Closed</span>
                  </button>
                <?php endif; ?>
              <?php else: 
                $reqStatusLower = strtolower(trim($activeRequest['status'] ?? ''));
              ?>
                <a href="user-request.php" class="unlock-action-btn"
                  style="background: #700d23; box-shadow: 0 4px 12px rgba(112, 13, 35, 0.2);">
                  <span>Update Request</span>
                </a>
                <?php if (in_array($reqStatusLower, ['pending', 'approved'])): ?>
                  <button type="button" class="cancel-action-btn" onclick="handleDashboardCancelRequest(<?= $activeRequest['id'] ?>)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="10"></circle>
                      <line x1="15" y1="9" x2="9" y2="15"></line>
                      <line x1="9" y1="9" x2="15" y2="15"></line>
                    </svg>
                    <span>Cancel Request</span>
                  </button>
                <?php endif; ?>
                <span class="request-id-tag" id="activeRequestIdTag"><?= htmlspecialchars($activeRequest['request_code'] ?? 'REQ-' . str_pad($activeRequest['id'], 5, '0', STR_PAD_LEFT)) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$activeRequest): ?>
            <!-- Empty State when no active request exists -->
            <div style="text-align: center; padding: 48px 24px; background: #faf7f8; border-radius: var(--radius-md); border: 1.5px dashed rgba(112, 13, 35, 0.2);">
              <div style="width: 56px; height: 56px; border-radius: 50%; background: #fdebed; color: #700d23; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
              </div>
              <h4 style="color: #700d23; margin-bottom: 6px; font-family: var(--heading-font); font-size: var(--fs-md);">No Active Relief Requests</h4>
              <p style="color: var(--admin-muted); font-size: var(--fs-xs); margin-bottom: 22px; max-width: 480px; margin-left: auto; margin-right: auto;">
                <?= $isAssistanceEnabled ? 'You currently have no pending or active disaster relief requests submitted to the council.' : 'Assistance submission is currently stands down. Council admins activate submissions during active disaster response.' ?>
              </p>
              <?php if ($isAssistanceEnabled): ?>
                <button type="button" class="unlock-action-btn" onclick="handleRequestAssistanceClick()"
                  style="background: #700d23; box-shadow: 0 4px 12px rgba(112, 13, 35, 0.2); border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                  <span>Request Assistance Now</span>
                </button>
              <?php else: ?>
                <button type="button" class="unlock-action-btn" disabled
                  style="background: #94a3b8; color: #f8fafc; box-shadow: none; border: none; cursor: not-allowed; opacity: 0.75; display: inline-flex; align-items: center; gap: 8px;">
                  <span>Request Intake Currently Closed</span>
                </button>
              <?php endif; ?>
            </div>
          <?php else: 
            $status = strtolower(trim($activeRequest['status'] ?? 'pending'));
            if ($status === 'archived') {
                $status = 'completed';
            }

            // Step 1: Pending Request (Amber if pending; completed green if approved or higher)
            $s1Class = ($status === 'pending') ? 'step-pending' : 'step-completed';

            // Step 2: Approved (GREEN ONLY when approved by admin or higher; Idle if pending)
            if ($status === 'approved') {
                $s2Class = 'step-approved';
                $s2Text = 'Verified & Approved';
            } elseif (in_array($status, ['in_progress', 'completed'])) {
                $s2Class = 'step-completed';
                $s2Text = 'Verified & Approved';
            } else {
                $s2Class = 'step-idle';
                $s2Text = 'Pending Review';
            }

            // Step 3: In Progress (Teal if in_progress; Green if completed; Idle otherwise)
            if ($status === 'in_progress') {
                $s3Class = 'step-progress';
                $s3Text = 'Dispatched';
            } elseif ($status === 'completed') {
                $s3Class = 'step-completed';
                $s3Text = 'Dispatched';
            } else {
                $s3Class = 'step-idle';
                $s3Text = 'Awaiting Dispatch';
            }

            // Step 4: Completed (Green if completed; Idle otherwise)
            if ($status === 'completed') {
                $s4Class = 'step-completed';
                $s4Text = 'Delivered';
            } else {
                $s4Class = 'step-idle';
                $s4Text = 'Pending Delivery';
            }

            $submittedTimestamp = $activeRequest['submitted_at'] ?? $activeRequest['created_at'] ?? null;
            $formattedDate = $submittedTimestamp ? date('M j, H:i', strtotime($submittedTimestamp)) : 'Recent';

            // Parse categories from resources field
            $rawResources = $activeRequest['resources'] ?? '';
            $categories = array_filter(array_map('trim', explode(',', $rawResources)));
            if (empty($categories)) {
                $categories = [$rawResources ?: 'General Assistance'];
            }
          ?>
            <!-- Stepped Progress Bar (Green only on Admin Approval) -->
            <div class="stepper-track-container">
              <div class="stepper-steps-wrapper">

                <!-- Step 1: Pending Request -->
                <div class="stepper-step <?= $s1Class ?>" id="step1">
                  <div class="step-node-circle">
                    <img src="icons/user_dashboard_logos/pending-request.png" alt="Pending Request">
                  </div>
                  <div class="step-text-title">Pending Request</div>
                  <div class="step-text-time"><?= $formattedDate ?></div>
                </div>

                <!-- Step 2: Approved (TURNS GREEN ON ADMIN APPROVAL) -->
                <div class="stepper-step <?= $s2Class ?>" id="step2">
                  <div class="step-node-circle">
                    <img src="icons/user_dashboard_logos/approved.png" alt="Approved">
                  </div>
                  <div class="step-text-title">Approved</div>
                  <div class="step-text-time"><?= $s2Text ?></div>
                </div>

                <!-- Step 3: In Progress -->
                <div class="stepper-step <?= $s3Class ?>" id="step3">
                  <div class="step-node-circle">
                    <img src="icons/user_dashboard_logos/in-progress.png" alt="In Progress">
                  </div>
                  <div class="step-text-title">In Progress</div>
                  <div class="step-text-time"><?= $s3Text ?></div>
                </div>

                <!-- Step 4: Completed -->
                <div class="stepper-step <?= $s4Class ?>" id="step4">
                  <div class="step-node-circle">
                    <img src="icons/user_dashboard_logos/completed.png" alt="Completed">
                  </div>
                  <div class="step-text-title">Completed</div>
                  <div class="step-text-time"><?= $s4Text ?></div>
                </div>

              </div>
            </div>

            <!-- Category Approval Breakdown Badges -->
            <div class="category-breakdown-box">
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <span style="font-size: var(--fs-xs); font-weight: 700; color: var(--admin-text); display: flex; align-items: center; gap: 6px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                  <span>Requested Assistance Categories &amp; Approval Status:</span>
                </span>
                <span style="font-size: var(--fs-2xs);">
                  <?php if ($status === 'approved'): ?>
                    <strong style="color: #1e6b37;">✓ All categories approved by Admin</strong>
                  <?php elseif ($status === 'in_progress'): ?>
                    <strong style="color: #0284c7;">⚡ In Transit by DRRM Responders</strong>
                  <?php elseif ($status === 'completed'): ?>
                    <strong style="color: #1e6b37;">✓ Aid Delivered to Student</strong>
                  <?php else: ?>
                    <strong style="color: #9c6008;">⏳ Awaiting Admin Category Review</strong>
                  <?php endif; ?>
                </span>
              </div>

              <!-- Badges turning green only when approved -->
              <div class="category-pill-list">
                <?php foreach ($categories as $cat): ?>
                  <?php if ($status === 'approved'): ?>
                    <span class="category-status-pill approved" title="Approved by Council Admin">
                      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      <span><?= htmlspecialchars($cat) ?></span>
                      <span style="font-size: 0.68rem; opacity: 0.85; margin-left: 2px;">• Approved by Admin</span>
                    </span>
                  <?php elseif ($status === 'in_progress'): ?>
                    <span class="category-status-pill in_progress" title="Category in Progress">
                      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                      <span><?= htmlspecialchars($cat) ?></span>
                      <span style="font-size: 0.68rem; opacity: 0.85; margin-left: 2px;">• Dispatched</span>
                    </span>
                  <?php elseif ($status === 'completed'): ?>
                    <span class="category-status-pill completed" title="Category Delivered">
                      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      <span><?= htmlspecialchars($cat) ?></span>
                      <span style="font-size: 0.68rem; opacity: 0.85; margin-left: 2px;">• Delivered</span>
                    </span>
                  <?php else: ?>
                    <span class="category-status-pill pending" title="Category Pending Review">
                      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                      <span><?= htmlspecialchars($cat) ?></span>
                      <span style="font-size: 0.68rem; opacity: 0.85; margin-left: 2px;">• Pending Admin Review</span>
                    </span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Active Request Location & Summary Card -->
            <div
              style="background: #faf7f8; border-radius: var(--radius-md); padding: var(--space-5) var(--space-6); border: 1px solid rgba(112, 13, 35, 0.1); display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4); font-size: var(--fs-xs);">
              <div>
                <span style="color: var(--admin-muted); display: block; margin-bottom: 2px;">Assistance Category:</span>
                <strong style="color: #700d23; font-size: var(--fs-sm);"><?= htmlspecialchars($activeRequest['resources'] ?? 'N/A') ?></strong>
              </div>
              <div>
                <span style="color: var(--admin-muted); display: block; margin-bottom: 2px;">Assigned Location / Landmark:</span>
                <strong style="color: var(--admin-text);"><?= htmlspecialchars($activeRequest['landmark'] ?? 'N/A') ?></strong>
              </div>
              <?php if (!empty($activeRequest['description'])): ?>
              <div style="grid-column: 1 / -1;">
                <span style="color: var(--admin-muted); display: block; margin-bottom: 2px;">Student Remarks / Situation:</span>
                <span style="color: var(--admin-text); line-height: 1.45;"><?= nl2br(htmlspecialchars($activeRequest['description'])) ?></span>
              </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        </section>

        <!-- Dedicated COEASC DRRM Department Contact Section -->
        <section class="emergency-contact-section" id="contactSection" aria-labelledby="drrmContactHeading">
          <div class="dashboard-header-block" style="text-align: left; margin-bottom: var(--space-5);">
            <span class="dashboard-eyebrow" style="color: #700d23; font-weight: 700; text-transform: uppercase; font-size: var(--fs-2xs); letter-spacing: 0.08em; display: inline-flex; align-items: center; gap: 6px;">
              <span style="width: 8px; height: 8px; border-radius: 50%; background: #700d23; display: inline-block;"></span>
              COEASC Disaster Risk Reduction &amp; Management Department
            </span>
            <h2 class="dashboard-main-title" id="drrmContactHeading" style="font-family: var(--heading-font); font-size: clamp(1.35rem, 2vw + 0.8rem, 1.85rem); font-weight: 800; color: #700d23; margin-top: 4px;">COEASC DRRM Department Contacts</h2>
            <p class="dashboard-main-subtitle" style="color: var(--admin-muted); font-size: var(--fs-xs); margin-top: 4px;">Direct communication channels for the College of Engineering &amp; Architecture Student Council (COEASC) DRRM Department. Reach out for relief inquiries, coordination, and updates.</p>
          </div>

          <div class="emergency-contact-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <!-- Card 1: COEASC DRRM Phone Contact -->
            <div class="emergency-contact-card">
              <div>
                <div class="emergency-contact-header">
                  <div class="emergency-contact-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                  </div>
                  <div>
                    <div class="emergency-contact-title">DRRM Department Contact</div>
                    <span style="font-size: var(--fs-2xs); color: #700d23; font-weight: 700; background: #fdebed; padding: 2px 8px; border-radius: var(--radius-pill); border: 1px solid rgba(112, 13, 35, 0.2);">COEASC DRRM Department</span>
                  </div>
                </div>
                <p class="emergency-contact-desc">
                  Direct mobile contact number for the College of Engineering &amp; Architecture Student Council (COEASC) DRRM Department officers.
                </p>
              </div>
              <div>
                <div style="font-family: var(--heading-font); font-size: 1.25rem; font-weight: 800; color: #700d23; margin-bottom: 12px; letter-spacing: 0.02em;">
                  <?= htmlspecialchars($contactPhone) ?>
                </div>
                <a href="tel:<?= htmlspecialchars($contactPhone) ?>" class="emergency-contact-action primary-action" aria-label="Contact COEASC DRRM Department at <?= htmlspecialchars($contactPhone) ?>">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                  <span>Contact <?= htmlspecialchars($contactPhone) ?></span>
                </a>
              </div>
            </div>

            <!-- Card 2: COEASC DRRM Official Email -->
            <div class="emergency-contact-card">
              <div>
                <div class="emergency-contact-header">
                  <div class="emergency-contact-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                      <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                  </div>
                  <div>
                    <div class="emergency-contact-title">COEASC DRRM Email</div>
                    <span style="font-size: var(--fs-2xs); color: #0369a1; font-weight: 700; background: #f0f9ff; padding: 2px 8px; border-radius: var(--radius-pill); border: 1px solid #bae6fd;">Official DRRM Inbox</span>
                  </div>
                </div>
                <p class="emergency-contact-desc">
                  Official email address for sending assistance queries, relief follow-ups, and student council coordination.
                </p>
              </div>
              <div>
                <div style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.95rem; font-weight: 700; color: #700d23; margin-bottom: 12px; word-break: break-all;">
                  <?= htmlspecialchars($contactEmail) ?>
                </div>
                <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" class="emergency-contact-action primary-action" aria-label="Send Email to COEASC DRRM Department">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                  <span>Email <?= htmlspecialchars($contactEmail) ?></span>
                </a>
              </div>
            </div>
          </div>
        </section>

      </div>
    </section>
  </main>

  <!-- Site Footer (Unified format with Admin page) -->
  <div class="site-footer-wrapper">
    <!-- Footer Content -->
    <footer class="site-footer">
      <div class="container">

        <div class="footer-main-grid">

          <!-- Brand & Description Column -->
          <div class="footer-brand-col">
            <div class="footer-brand-row">
              <div class="footer-logo-box">
                <img src="logo/user-main-logo.png" alt="ALERTO User Logo" class="footer-logo-img">
              </div>
              <div class="footer-brand-text">
                <div class="footer-brand-title">ALERTO</div>
                <div class="footer-brand-subtitle">CSU-CARIG Student Council</div>
              </div>
            </div>

            <p class="footer-description">
              A school-based disaster assistance and relief coordination platform developed for the CSU-COEA community.
            </p>

            <!-- Social Links -->
            <div class="footer-social-row" aria-label="Social links">
              <!-- Email -->
              <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" class="social-circle-btn" aria-label="Email COEASC DRRM Department">
                <img src="icons/admin_footer/mail.png" alt="Email" class="footer-social-img">
              </a>
              <!-- Facebook -->
              <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="social-circle-btn"
                aria-label="Facebook Page">
                <img src="icons/admin_footer/epbi.png" alt="Facebook" class="footer-social-img">
              </a>
              <!-- Messenger -->
              <a href="https://m.me" target="_blank" rel="noopener noreferrer" class="social-circle-btn"
                aria-label="Messenger Support">
                <img src="icons/admin_footer/mess.png" alt="Messenger" class="footer-social-img">
              </a>
            </div>
          </div>

          <!-- Contact Info Column (NEW) -->
          <div class="footer-contact-col">
            <h4 style="font-family: var(--heading-font); font-size: var(--fs-sm); font-weight: 700; color: #ffffff; margin-bottom: 12px;">DRRM Contacts</h4>
            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; font-size: var(--fs-xs); color: rgba(255,255,255,0.85);">
              <li style="display: flex; align-items: center; gap: 8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #ff8fa3; flex-shrink: 0;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                <span>Phone: <a href="tel:<?= htmlspecialchars($contactPhone) ?>" style="color: #ffffff; font-weight: 700; text-decoration: underline;"><?= htmlspecialchars($contactPhone) ?></a></span>
              </li>
              <li style="display: flex; align-items: center; gap: 8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #ff8fa3; flex-shrink: 0;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                <span>Email: <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" style="color: #ffffff; font-weight: 700; text-decoration: underline; word-break: break-all;"><?= htmlspecialchars($contactEmail) ?></a></span>
              </li>
              <li style="display: flex; align-items: center; gap: 8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #ff8fa3; flex-shrink: 0;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                <span>CSU Carig Campus, COEA</span>
              </li>
            </ul>
          </div>

          <!-- Quick Links Column -->
          <div class="footer-links-col">
            <h4 style="font-family: var(--heading-font); font-size: var(--fs-sm); font-weight: 700; color: #ffffff; margin-bottom: 12px;">Quick Links</h4>
            <ul class="footer-nav-list">
              <li><a href="user-homepage.php">Home</a></li>
              <li><a href="user-request.php">Request Assistance</a></li>
              <li><a href="#contactSection">COEASC DRRM Contacts</a></li>
              <li><a href="user-homepage.php?action=logout" class="footer-logout-btn">Logout</a></li>
            </ul>
          </div>

        </div>

        <!-- Footer Bottom Bar -->
        <div class="footer-bottom-bar">
          <span>@ALERTO - Cagayan State University-COEA Student Council</span>
          <span>Always Ready. Always Here.</span>
        </div>

      </div>
    </footer>

    <!-- Footer Wave Divider -->
    <div class="footer-wave-divider" aria-hidden="true">
      <svg viewBox="0 0 1200 120" preserveAspectRatio="none">
        <path d="M0,0 C150,55 350,-25 500,30 C650,85 900,10 1200,45 L1200,120 L0,120 Z"
          fill="rgba(178, 24, 60, 0.35)">
        </path>
        <path d="M0,20 C180,75 320,5 520,50 C720,95 920,25 1200,60 L1200,120 L0,120 Z" fill="#3a0410"></path>
      </svg>
    </div>
  </div>

  <!-- Shared Javascript (Handles Mobile Nav Toggle and UI) -->
  <script src="assets/js/main.js"></script>

  <script>
    // Dynamic Verification Decisions & Access Gating
    let currentDecision = '<?= htmlspecialchars($user['status'] ?? 'approved') ?>';
    const isIntakeOpen = <?= $isAssistanceEnabled ? 'true' : 'false' ?>;

    function setVerificationState(state) {
      currentDecision = state;
      const pill = document.getElementById('currentVerificationPill');
      const nameCheck = document.getElementById('nameVerifiedCheck');
      const lockedBanner = document.getElementById('verificationLockedBanner');
      const trackerSection = document.getElementById('requestsTrackSection');
      const bannerTitle = document.getElementById('lockedBannerTitle');
      const bannerDesc = document.getElementById('lockedBannerDesc');

      if (!pill) return;

      pill.className = `verification-badge ${state}`;

      if (state === 'approved' || state === 'verified') {
        pill.innerHTML = `
          <svg style="width:14px;height:14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          <span>Approved (Verified)</span>
        `;
        if (nameCheck) nameCheck.style.display = 'inline-block';
        if (lockedBanner) lockedBanner.style.display = 'none';
        if (trackerSection) trackerSection.classList.remove('locked');
      }
      else if (state === 'pending' || state === 'unverified') {
        pill.innerHTML = `
          <svg style="width:14px;height:14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 14 14"></polyline>
          </svg>
          <span>Pending Verification</span>
        `;
        if (nameCheck) nameCheck.style.display = 'none';
        if (bannerTitle) bannerTitle.textContent = 'Verification in Progress';
        if (bannerDesc) bannerDesc.textContent = 'Your documents have been submitted and are waiting for review by the COEA Student Council.';
        if (lockedBanner) lockedBanner.style.display = 'flex';
        if (trackerSection) trackerSection.classList.add('locked');
      }
      else if (state === 'rejected') {
        pill.innerHTML = `
          <svg style="width:14px;height:14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
          <span>Verification Rejected</span>
        `;
        if (nameCheck) nameCheck.style.display = 'none';
        if (bannerTitle) bannerTitle.textContent = 'Verification Rejected';
        if (bannerDesc) bannerDesc.textContent = 'Your uploaded identification or assessment document was unclear or unverified. Please re-upload valid credentials.';
        if (lockedBanner) lockedBanner.style.display = 'flex';
        if (trackerSection) trackerSection.classList.add('locked');
      }
      else if (state === 'banned') {
        pill.innerHTML = `
          <svg style="width:14px;height:14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
          </svg>
          <span>Account Restricted (Banned)</span>
        `;
        if (nameCheck) nameCheck.style.display = 'none';
        if (bannerTitle) bannerTitle.textContent = 'Account Restricted';
        if (bannerDesc) bannerDesc.textContent = 'This student profile has been restricted from emergency assistance coordination due to administrative policy.';
        if (lockedBanner) lockedBanner.style.display = 'flex';
        if (trackerSection) trackerSection.classList.add('locked');
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      setVerificationState(currentDecision);
    });

    // Request Assistance Click Guard
    function handleRequestAssistanceClick() {
      if (!isIntakeOpen) {
        alert('Notice: Assistance request submission is currently closed. The COEASC DRRM Department enables requests during active post-disaster events.');
        return;
      }
      if (currentDecision !== 'approved' && currentDecision !== 'verified') {
        alert('Action Locked: You must have an Approved student profile to file an assistance request.');
      } else {
        window.location.href = 'user-request.php';
      }
    }

    // Cancel Active Request confirmation and submission from Dashboard
    function handleDashboardCancelRequest(reqId) {
      if (confirm('Are you sure you want to cancel your assistance request? Emergency responders will stand down.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'user-homepage.php';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'cancel_request';
        form.appendChild(actionInput);

        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'request_id';
        idInput.value = reqId;
        form.appendChild(idInput);

        document.body.appendChild(form);
        form.submit();
      }
    }
  </script>
</body>

</html>