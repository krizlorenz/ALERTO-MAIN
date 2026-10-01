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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ALERTO - CSU-Carig Student Council</title>

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

        <div class="dashboard-header-block">
          <span class="dashboard-eyebrow">Operations &amp; Oversight</span>
          <h2 class="dashboard-main-title" id="dashboardOverviewHeading">Council Operations Overview</h2>
          <p class="dashboard-main-subtitle">Real-time status breakdown for student profile verifications and active
            relief assistance requests.</p>
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
              <a href="mailto:alerto@csu.edu.ph" class="social-circle-btn" aria-label="Email CSU Student Council">
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

          <!-- Quick Links Column -->
          <div class="footer-links-col">
            <h4>Quick Links</h4>
            <ul class="footer-nav-list">
              <li><a href="admin-homepage.php">Home</a></li>
              <li><a href="admin-verify.php">Student Verifications</a></li>
              <li><a href="admin-request.php">Assistance Requests</a></li>
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