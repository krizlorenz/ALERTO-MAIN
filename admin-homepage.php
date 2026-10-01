<?php
session_start();
require_once 'alerto-db.php';

// Process logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: admin-login.php");
    exit;
}

// Session Guard
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("Location: admin-login.php");
    exit;
}

// Process Admin Controls: Toggle Assistance or Update Contacts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'toggle_assistance' && isset($_POST['new_state'])) {
        $newState = intval($_POST['new_state']) ? '1' : '0';
        update_system_setting($pdo, 'request_assistance_enabled', $newState);
        header("Location: admin-homepage.php?toggled=1#systemControls");
        exit;
    }

    if ($_POST['action'] === 'update_contacts') {
        $phone = trim($_POST['contact_phone'] ?? '');
        $email = trim($_POST['contact_email'] ?? '');
        if (!empty($phone)) {
            update_system_setting($pdo, 'contact_phone', $phone);
        }
        if (!empty($email)) {
            update_system_setting($pdo, 'contact_email', $email);
        }
        header("Location: admin-homepage.php?saved_contacts=1#systemControls");
        exit;
    }
}

// Retrieve dynamic system settings
$sysSettings = get_system_settings($pdo);
$contactPhone = $sysSettings['contact_phone'] ?? '09556678451';
$contactEmail = $sysSettings['contact_email'] ?? 'alertoCOEA@gmail.com';
$isAssistanceEnabled = ($sysSettings['request_assistance_enabled'] ?? '1') === '1';

// 1. Fetch dynamic counts for Student Verifications
$stmtPendingVerify = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (status = 'pending' || status = 'unverified')");
$pendingVerifyCount = $stmtPendingVerify->fetchColumn();

$stmtApprovedVerify = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (status = 'approved' || status = 'verified')");
$approvedVerifyCount = $stmtApprovedVerify->fetchColumn();

$stmtRejectedVerify = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'rejected'");
$rejectedVerifyCount = $stmtRejectedVerify->fetchColumn();

$stmtBannedVerify = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'banned'");
$bannedVerifyCount = $stmtBannedVerify->fetchColumn();

// 2. Fetch dynamic counts for Assistance Requests (matching active/status filters)
$stmtActiveReq = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) IN ('pending', 'approved', 'in_progress', 'in progress')");
$activeRequestsCount = $stmtActiveReq->fetchColumn();

$stmtPendingReq = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) = 'pending'");
$pendingRequestsCount = $stmtPendingReq->fetchColumn();

$stmtApprovedReq = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) = 'approved'");
$approvedRequestsCount = $stmtApprovedReq->fetchColumn();

$stmtInProgressReq = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) IN ('in_progress', 'in progress')");
$inProgressRequestsCount = $stmtInProgressReq->fetchColumn();

$stmtCompletedReq = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) = 'completed'");
$completedRequestsCount = $stmtCompletedReq->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>ALERTO - CSU-Carig Student Council Admin</title>

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
  <style>
    /* Fix In Progress label breaking into two lines */
    .dashboard-grid .stat-card-title {
      white-space: nowrap !important;
    }
  </style>
</head>

<body>

  <!-- Navbar -->
  <header class="navbar">
    <div class="navbar-inner">
      <a href="admin-homepage.php" class="brand" aria-label="ALERTO Home">
        <div class="logo-container">
          <img src="logo/user-main-logo.png" alt="ALERTO Admin Logo" class="logo-img">
        </div>
        <div class="brand-text">
          <span class="brand-name">ALERTO</span>
          <span class="brand-sub">CSU-CARIG Student Council</span>
        </div>
      </a>

      <!-- Navigation Links -->
      <nav class="main-nav" id="mainNav" aria-label="Main Navigation">
        <a href="admin-homepage.php" class="active">Home</a>
        <a href="admin-verify.php">Verify</a>
        <a href="admin-request.php">Requests</a>
        <a href="#systemControls">Controls</a>
        <a href="#contactControls">Contacts</a>
        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <a href="admin-add-sign-in.php">Add New Admin</a>
        <?php endif; ?>
        <a href="?action=logout" class="nav-logout-btn">Logout</a>
      </nav>

      <!-- Mobile Menu Toggle -->
      <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobileNavMenu">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
  </header>

  <!-- Mobile Navigation -->
  <div class="mobile-nav-menu" id="mobileNavMenu" aria-label="Mobile Navigation Menu">
    <ul>
      <li>
        <a href="admin-homepage.php" class="active">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
          </svg>
          <span>Home</span>
        </a>
      </li>
      <li>
        <a href="admin-verify.php">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
          </svg>
          <span>Verify</span>
          <?php if ($pendingVerifyCount > 0): ?>
          <span class="mobile-nav-badge"><?= $pendingVerifyCount ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li>
        <a href="admin-request.php">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          <span>Requests</span>
          <?php if ($activeRequestsCount > 0): ?>
          <span class="mobile-nav-badge"><?= $activeRequestsCount ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li>
        <a href="#systemControls">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          </svg>
          <span>Controls</span>
        </a>
      </li>
      <li>
        <a href="#contactControls">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
          </svg>
          <span>Contacts</span>
        </a>
      </li>
      <?php if ($_SESSION['role'] === 'superadmin'): ?>
      <li>
        <a href="admin-add-sign-in.php">
          <svg class="mobile-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <line x1="20" y1="8" x2="20" y2="14"></line>
            <line x1="23" y1="11" x2="17" y2="11"></line>
          </svg>
          <span>Add New Admin</span>
        </a>
      </li>
      <?php endif; ?>
      <li class="mobile-nav-divider" role="separator"></li>
      <li>
        <a href="?action=logout" class="mobile-nav-logout-btn">
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
    <!-- Hero Section -->
    <section class="hero" aria-labelledby="hero-title">

      <!-- Hero Media -->
      <div class="hero-bg-media">
        <img src="images/coea.png" alt="CSU COEA Building" class="hero-bg-img" onerror="this.style.display='none';">
        <div class="hero-gradient-overlay" aria-hidden="true"></div>
      </div>

      <!-- Hero Content -->
      <div class="hero-container">
        <div class="hero-content">
          <span class="hero-pill">ALERTO: Always Ready. Always Here.</span>
          <h1 class="hero-title" id="hero-title">
            Coordinated relief,
            <span class="hero-title-highlight">when it matters most.</span>
          </h1>
          <p class="hero-copy">
            ALERTO is a web-based platform that lets affected students request assistance, follow official advisories,
            and stay informed — while giving the COEA Student Council a clear, centralized way to review, prioritize,
            and respond.
          </p>
        </div>
      </div>

    </section>

    <!-- Operations Dashboard Section -->
    <section class="dashboard-section" aria-labelledby="dashboardOverviewHeading">
      <div class="container">

        <!-- Flash alerts for settings updates -->
        <?php if (isset($_GET['toggled'])): ?>
          <div class="alert-banner alert-success" role="alert" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: var(--space-6); padding: 14px 18px; border-radius: var(--radius-sm); font-size: var(--fs-xs); font-weight: 500; background-color: #edf7f0; color: #1e6b37; border: 1.5px solid #c2e7cd;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width: 20px; height: 20px; flex-shrink: 0; color: #1e6b37;">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
              <span>Student assistance request intake status has been successfully updated.</span>
            </div>
            <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.2rem; color: #1e6b37; cursor: pointer;">&times;</button>
          </div>
        <?php elseif (isset($_GET['saved_contacts'])): ?>
          <div class="alert-banner alert-success" role="alert" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: var(--space-6); padding: 14px 18px; border-radius: var(--radius-sm); font-size: var(--fs-xs); font-weight: 500; background-color: #edf7f0; color: #1e6b37; border: 1.5px solid #c2e7cd;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width: 20px; height: 20px; flex-shrink: 0; color: #1e6b37;">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
              <span>COEASC DRRM contact information has been updated and published across all student and admin pages.</span>
            </div>
            <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.2rem; color: #1e6b37; cursor: pointer;">&times;</button>
          </div>
        <?php endif; ?>

        <div class="dashboard-header-block">
          <span class="dashboard-eyebrow">Operations &amp; Oversight</span>
          <h2 class="dashboard-main-title" id="dashboardOverviewHeading">Council Operations Overview</h2>
          <p class="dashboard-main-subtitle">Real-time status breakdown for student profile verifications and active relief assistance requests.</p>
        </div>

        <!-- 2-Box Grid -->
        <div class="dashboard-grid">

          <!-- Verification Card -->
          <a href="admin-verify.php" class="overview-board-link"
            aria-label="Open Student Profile Verification Management">
            <div class="board-header">
              <div class="board-title-group">
                <h3>
                  <span>Student Profile Verification</span>
                  <span class="board-arrow-indicator">&#8599;</span>
                </h3>
                <p>Manage and verify student identification &amp; accounts</p>
              </div>
              <span class="status-pill red">Records</span>
            </div>

            <div class="board-cards-stack">
              <!-- Top Stat (Pending Review) -->
              <div class="top-featured-row">
                <div class="inner-stat-card featured-stat">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-red">
                        <img src="icons/user_dashboard_logos/pending-request.png" alt="Pending Review">
                      </div>
                      <span class="stat-card-title">Pending Review</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $pendingVerifyCount ?></div>
                  <div class="stat-card-desc">Waiting for student ID &amp; credentials verification</div>
                </div>
              </div>

              <!-- Secondary Breakdown Grid -->
              <div class="bottom-breakdown-grid">

                <!-- Approved -->
                <div class="inner-stat-card">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-success">
                        <img src="icons/user_dashboard_logos/approved.png" alt="Approved">
                      </div>
                      <span class="stat-card-title">Approved</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $approvedVerifyCount ?></div>
                  <div class="stat-card-desc">Verified active students</div>
                </div>

                <!-- Rejected -->
                <div class="inner-stat-card">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-danger">
                        <img src="icons/admin_dashboard_icons/rejected.png" alt="Rejected">
                      </div>
                      <span class="stat-card-title">Rejected</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $rejectedVerifyCount ?></div>
                  <div class="stat-card-desc">Invalid ID details</div>
                </div>

                <!-- Banned -->
                <div class="inner-stat-card">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-dark">
                        <img src="icons/admin_dashboard_icons/banned.png" alt="Banned">
                      </div>
                      <span class="stat-card-title">Banned</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $bannedVerifyCount ?></div>
                  <div class="stat-card-desc">Restricted access</div>
                </div>

              </div>
            </div>
          </a>

          <!-- Requests Card -->
          <a href="admin-request.php" class="overview-board-link" aria-label="Open Assistance Requests Management">
            <div class="board-header">
              <div class="board-title-group">
                <h3>
                  <span>Assistance Requests</span>
                  <span class="board-arrow-indicator">&#8599;</span>
                </h3>
                <p>Track, review, and coordinate disaster relief aid</p>
              </div>
              <span class="status-pill warning">Relief Ops</span>
            </div>

            <div class="board-cards-stack">

              <!-- Top Featured Stats -->
              <div class="top-featured-row dual-featured">

                <!-- Active Requests -->
                <div class="inner-stat-card featured-stat">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-red">
                        <img src="icons/user-login-icons/request-assistance.png" alt="Active Requests">
                      </div>
                      <span class="stat-card-title">Active Requests</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $activeRequestsCount ?></div>
                  <div class="stat-card-desc">Currently active relief submissions</div>
                </div>

                <!-- Pending Action -->
                <div class="inner-stat-card featured-stat pending-highlight">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-warning">
                        <img src="icons/user_dashboard_logos/pending-request.png" alt="Pending Action">
                      </div>
                      <span class="stat-card-title">Pending Action</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $pendingRequestsCount ?></div>
                  <div class="stat-card-desc">Needs dispatch response &amp; allocation</div>
                </div>

              </div>

              <!-- Secondary Breakdown Grid -->
              <div class="bottom-breakdown-grid">

                <!-- Approved -->
                <div class="inner-stat-card">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-success">
                        <img src="icons/user_dashboard_logos/approved.png" alt="Approved">
                      </div>
                      <span class="stat-card-title">Approved</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $approvedRequestsCount ?></div>
                  <div class="stat-card-desc">Ready for relief pack dispatch</div>
                </div>

                <!-- In Progress -->
                <div class="inner-stat-card">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-primary">
                        <img src="icons/user_dashboard_logos/in-progress.png" alt="In Progress">
                      </div>
                      <span class="stat-card-title">In Progress</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $inProgressRequestsCount ?></div>
                  <div class="stat-card-desc">Being dispatched</div>
                </div>

                <!-- Completed -->
                <div class="inner-stat-card">
                  <div class="stat-card-header">
                    <div class="stat-card-label-group">
                      <div class="stat-icon-slot icon-info">
                        <img src="icons/user_dashboard_logos/completed.png" alt="Completed">
                      </div>
                      <span class="stat-card-title">Completed</span>
                    </div>
                  </div>
                  <div class="stat-card-number"><?= $completedRequestsCount ?></div>
                  <div class="stat-card-desc">Aid delivered</div>
                </div>

              </div>
            </div>
          </a>

        </div>
      </div>
    </section>

    <!-- Portal Controls Section: Disaster Mode Switch & Contact Manager -->
    <section class="admin-controls-section" id="systemControls" style="padding: var(--space-8) 0 var(--space-12); background: #faf7f8; border-top: 1px solid var(--admin-border);">
      <div class="container">

        <div class="dashboard-header-block">
          <span class="dashboard-eyebrow" style="color: #700d23; font-weight: 700; text-transform: uppercase; font-size: var(--fs-2xs); letter-spacing: 0.08em; display: inline-flex; align-items: center; gap: 6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            System &amp; Incident Controls
          </span>
          <h2 class="dashboard-main-title" style="font-family: var(--heading-font); font-size: clamp(1.4rem, 2vw + 0.8rem, 1.85rem); font-weight: 800; color: #700d23; margin-top: 4px;">Disaster Response &amp; Contact Controls</h2>
          <p class="dashboard-main-subtitle" style="color: var(--admin-muted); font-size: var(--fs-xs); margin-top: 4px;">Control the student request submission intake during active disaster events and manage the displayed council contact information.</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--space-6); margin-top: var(--space-6);">

          <!-- Control 1: Student Request Intake Switch -->
          <div style="background: #ffffff; border-radius: var(--radius-lg); padding: var(--space-6); border: 1.5px solid rgba(112, 13, 35, 0.12); box-shadow: var(--shadow-card); display: flex; flex-direction: column; justify-content: space-between; gap: var(--space-5);">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: var(--space-3); flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <div style="width: 42px; height: 42px; border-radius: 12px; background: #fdebed; color: #700d23; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid rgba(112, 13, 35, 0.15);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                  </div>
                  <div>
                    <h3 style="font-family: var(--heading-font); font-size: var(--fs-md); font-weight: 700; color: var(--admin-text); line-height: 1.2;">Student Request Intake</h3>
                    <span style="font-size: var(--fs-2xs); color: var(--admin-muted);">Post-Disaster Response Switch</span>
                  </div>
                </div>

                <?php if ($isAssistanceEnabled): ?>
                  <span style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: var(--radius-pill); font-size: var(--fs-2xs); font-weight: 700; background: #edf7f0; color: #1e6b37; border: 1.5px solid #c2e7cd;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e; display: inline-block;"></span>
                    <span>ACTIVE (Open)</span>
                  </span>
                <?php else: ?>
                  <span style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: var(--radius-pill); font-size: var(--fs-2xs); font-weight: 700; background: #f1f5f9; color: #64748b; border: 1.5px solid #cbd5e1;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; display: inline-block;"></span>
                    <span>CLOSED (Stand Down)</span>
                  </span>
                <?php endif; ?>
              </div>

              <p style="font-size: var(--fs-xs); color: var(--admin-muted); line-height: 1.55; margin-bottom: var(--space-4);">
                Turn this <strong>ON</strong> only when a post-disaster event affects the campus/students to open assistance requests. When <strong>OFF</strong>, the request button is grayed out and unclickable on all student devices to avoid spamming.
              </p>
            </div>

            <form method="POST" action="admin-homepage.php" onsubmit="return confirm('<?= $isAssistanceEnabled ? 'Are you sure you want to CLOSE student request submissions? The request button will be grayed out for students.' : 'Are you sure you want to ACTIVATE student request submissions? The request button will become functional again for students.' ?>')">
              <input type="hidden" name="action" value="toggle_assistance">
              <input type="hidden" name="new_state" value="<?= $isAssistanceEnabled ? '0' : '1' ?>">
              
              <?php if ($isAssistanceEnabled): ?>
                <button type="submit" style="width: 100%; min-height: 46px; padding: 12px 18px; border-radius: var(--radius-sm); font-family: var(--heading-font); font-weight: 700; font-size: var(--fs-xs); cursor: pointer; border: 1.5px solid #fecaca; background: #fef2f2; color: #991b1b; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all var(--transition-fast);">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                  <span>Turn OFF Request Intake (Stand Down)</span>
                </button>
              <?php else: ?>
                <button type="submit" style="width: 100%; min-height: 46px; padding: 12px 18px; border-radius: var(--radius-sm); font-family: var(--heading-font); font-weight: 700; font-size: var(--fs-xs); cursor: pointer; border: 1.5px solid #bbf7d0; background: #f0fdf4; color: #166534; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all var(--transition-fast);">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  <span>Turn ON Request Intake (Activate Disaster Mode)</span>
                </button>
              <?php endif; ?>
            </form>
          </div>

          <!-- Control 2: COEASC DRRM Contact Information Manager -->
          <div id="contactControls" style="background: #ffffff; border-radius: var(--radius-lg); padding: var(--space-6); border: 1.5px solid rgba(112, 13, 35, 0.12); box-shadow: var(--shadow-card); display: flex; flex-direction: column; justify-content: space-between; gap: var(--space-5);">
            <div>
              <div style="display: flex; align-items: center; gap: 10px; margin-bottom: var(--space-3);">
                <div style="width: 42px; height: 42px; border-radius: 12px; background: #fdebed; color: #700d23; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid rgba(112, 13, 35, 0.15);">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                </div>
                <div>
                  <h3 style="font-family: var(--heading-font); font-size: var(--fs-md); font-weight: 700; color: var(--admin-text); line-height: 1.2;">COEASC DRRM Contact Details</h3>
                  <span style="font-size: var(--fs-2xs); color: var(--admin-muted);">Syncs across User &amp; Admin pages</span>
                </div>
              </div>

              <p style="font-size: var(--fs-xs); color: var(--admin-muted); line-height: 1.55; margin-bottom: var(--space-4);">
                Update the official mobile contact number and email address displayed in student banners, contact sections, and footers.
              </p>
            </div>

            <form method="POST" action="admin-homepage.php" style="display: flex; flex-direction: column; gap: var(--space-3);">
              <input type="hidden" name="action" value="update_contacts">

              <div>
                <label style="display: block; font-size: var(--fs-2xs); font-weight: 700; color: var(--admin-text); margin-bottom: 4px;">DRRM Contact Number</label>
                <input type="text" name="contact_phone" value="<?= htmlspecialchars($contactPhone) ?>" required class="auth-input" style="width: 100%; height: 42px; padding: 0 14px; font-size: var(--fs-xs); border: 1.5px solid var(--admin-border); border-radius: var(--radius-sm);">
              </div>

              <div>
                <label style="display: block; font-size: var(--fs-2xs); font-weight: 700; color: var(--admin-text); margin-bottom: 4px;">DRRM Official Email</label>
                <input type="email" name="contact_email" value="<?= htmlspecialchars($contactEmail) ?>" required class="auth-input" style="width: 100%; height: 42px; padding: 0 14px; font-size: var(--fs-xs); border: 1.5px solid var(--admin-border); border-radius: var(--radius-sm);">
              </div>

              <button type="submit" style="width: 100%; min-height: 44px; margin-top: 2px; padding: 11px 18px; border-radius: var(--radius-sm); font-family: var(--heading-font); font-weight: 700; font-size: var(--fs-xs); cursor: pointer; border: none; background: #700d23; color: #ffffff; box-shadow: 0 4px 12px rgba(112, 13, 35, 0.25); display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                <span>Save &amp; Publish Contacts</span>
              </button>
            </form>
          </div>

        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <div class="site-footer-wrapper">
    <!-- Footer Content -->
    <footer class="site-footer">
      <div class="container">

        <div class="footer-main-grid">

          <!-- Brand & Description Column -->
          <div class="footer-brand-col">
            <div class="footer-brand-row">
              <div class="footer-logo-box">
                <img src="logo/user-main-logo.png" alt="ALERTO Admin Logo" class="footer-logo-img">
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
              <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" class="social-circle-btn" aria-label="Email CSU Student Council">
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
              <li><a href="admin-homepage.php">Home</a></li>
              <li><a href="admin-verify.php">Student Verifications</a></li>
              <li><a href="admin-request.php">Assistance Requests</a></li>
              <li><a href="#systemControls">Portal Controls</a></li>
              <?php if ($_SESSION['role'] === 'superadmin'): ?>
              <li><a href="admin-add-sign-in.php">Add New Admin</a></li>
              <?php endif; ?>
              <li><a href="?action=logout" class="footer-logout-btn">Logout</a></li>
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

  <!-- Client Script -->
  <script src="assets/js/main.js"></script>
</body>

</html>