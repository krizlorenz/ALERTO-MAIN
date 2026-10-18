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
$currentAdmin = validate_active_session($pdo, ['admin', 'superadmin']);

$actionError = '';

// Process Admin Controls: Toggle Assistance or Update Contacts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submittedToken)) {
        $actionError = 'Invalid or expired session security token. Please refresh and try again.';
    } elseif ($_POST['action'] === 'toggle_assistance') {
        if (!isset($_POST['new_state']) || !in_array((string)$_POST['new_state'], ['0', '1'], true)) {
            $actionError = 'Invalid request intake state submitted.';
        } else {
            $newState = ($_POST['new_state'] === '1') ? '1' : '0';
            update_system_setting($pdo, 'request_assistance_enabled', $newState);
            header("Location: admin-controls.php?toggled=1");
            exit;
        }
    } elseif ($_POST['action'] === 'update_contacts') {
        $phone = trim($_POST['contact_phone'] ?? '');
        $email = trim($_POST['contact_email'] ?? '');
        $phoneDigits = preg_replace('/\D/', '', $phone);

        if (empty($phone) || !preg_match('/^[0-9+() -]{7,25}$/', $phone) || strlen($phoneDigits) < 7) {
            $actionError = 'Please enter a valid DRRM contact phone number (7-25 digits/symbols).';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $actionError = 'Please enter a valid DRRM official email address.';
        } else {
            update_system_setting($pdo, 'contact_phone', $phone);
            update_system_setting($pdo, 'contact_email', $email);
            header("Location: admin-controls.php?saved_contacts=1");
            exit;
        }
    }
}

// Retrieve dynamic system settings
$sysSettings = get_system_settings($pdo);
$contactPhone = $sysSettings['contact_phone'] ?? '09556678451';
$contactEmail = $sysSettings['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
$isAssistanceEnabled = ($sysSettings['request_assistance_enabled'] ?? '1') === '1';

// Fetch dynamic counts for sidebar badges
$stmtPending = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (status = 'pending' || status = 'unverified')");
$pendingCount = $stmtPending->fetchColumn();

$stmtActiveReq = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) IN ('pending', 'approved', 'in_progress', 'in progress')");
$activeRequestsCount = $stmtActiveReq->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>ALERTO - Controls &amp; Contacts Management</title>

  <!-- Google Fonts: Plus Jakarta Sans, Inter & JetBrains Mono (3-Font System) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
    rel="stylesheet">

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= time() ?>">
</head>

<body>

  <div class="admin-layout">

    <!-- Mobile Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Sidebar Component -->
    <aside class="admin-sidebar" id="adminSidebar">

      <!-- Brand Identity -->
      <a href="admin-homepage.php" class="sidebar-brand">
        <div class="sidebar-logo">
          <img src="logo/main_logo.png" alt="ALERTO Admin Logo">
        </div>
        <div>
          <div class="sidebar-brand-name">ALERTO</div>
          <div class="sidebar-brand-sub">Admin Portal</div>
        </div>
      </a>

      <!-- Sidebar Navigation Menu -->
      <nav class="sidebar-nav">
        <a href="admin-homepage.php" class="nav-item-link">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
          </span>
          <span>Dashboard</span>
        </a>

        <a href="admin-verify.php" class="nav-item-link">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
          </span>
          <span>Verifications</span>
          <span class="nav-item-badge"><?= $pendingCount ?></span>
        </a>

        <a href="admin-request.php" class="nav-item-link">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
          </span>
          <span>Requests</span>
          <span class="nav-item-badge"><?= $activeRequestsCount ?></span>
        </a>

        <a href="admin-controls.php" class="nav-item-link active" title="Incident Controls &amp; Council Contacts">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
          </span>
          <span>Controls &amp; Contacts</span>
          <span class="nav-item-badge <?= $isAssistanceEnabled ? 'badge-online' : 'badge-offline' ?>">
            <?= $isAssistanceEnabled ? 'Active' : 'Paused' ?>
          </span>
        </a>

        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <a href="admin-add-sign-in.php" class="nav-item nav-item-link">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <line x1="20" y1="8" x2="20" y2="14"></line>
              <line x1="23" y1="11" x2="17" y2="11"></line>
            </svg>
          </span>
          <span>+ Add New Admin</span>
        </a>
        <?php endif; ?>
      </nav>

      <!-- Sidebar Footer -->
      <div class="sidebar-footer">
        <a href="?action=logout" class="sidebar-logout-link">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
          <span>Logout</span>
        </a>

        <span>&copy; 2026 CSU-COEA Student Council</span>
      </div>

    </aside>

    <!-- Main Content Area -->
    <div class="admin-main-content">

      <!-- Mobile Header Bar -->
      <header class="admin-mobile-header">
        <div class="mobile-header-left">
          <button type="button" class="admin-mobile-toggle-btn" id="sidebarMobileToggle" aria-label="Open sidebar menu" aria-expanded="false" aria-controls="adminSidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <line x1="3" y1="12" x2="21" y2="12"></line>
              <line x1="3" y1="6" x2="21" y2="6"></line>
              <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
          </button>
          <span class="mobile-header-title">ALERTO Admin</span>
        </div>
        <a href="admin-homepage.php" class="table-action-btn fs-2xs">Exit Portal</a>
      </header>

      <div class="admin-content-body">

        <!-- Page Header & Top Stat Badge -->
        <div class="admin-page-header">
          <div>
            <span class="admin-page-eyebrow">System Operations &amp; Incident Oversight</span>
            <h1 class="admin-page-title">Controls &amp; Contacts</h1>
            <p class="admin-page-desc">Centralized control panel for post-disaster student relief request intake and official DRRM council emergency hotlines.</p>
          </div>
          <div class="admin-top-stat">
            <div class="top-stat-number <?= $isAssistanceEnabled ? 'text-success' : 'text-danger' ?>">
              <?= $isAssistanceEnabled ? 'ACTIVE' : 'STANDBY' ?>
            </div>
            <div class="top-stat-label">Intake Mode</div>
          </div>
        </div>

        <!-- Flash Alert Banners -->
        <?php if (!empty($actionError) || isset($_GET['error'])): ?>
          <div class="alert-banner alert-warning mb-6" role="alert" style="margin-bottom: var(--space-5);">
            <div class="alert-banner-content">
              <svg class="alert-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
              </svg>
              <span><?= htmlspecialchars($actionError ?: ($_GET['error'] ?? 'An error occurred.')) ?></span>
            </div>
            <button type="button" class="alert-banner-close" onclick="this.parentElement.style.display='none'">&times;</button>
          </div>
        <?php endif; ?>
        <?php if (isset($_GET['toggled'])): ?>
          <div class="alert-banner alert-success mb-6" role="alert" style="margin-bottom: var(--space-5);">
            <div class="alert-banner-content">
              <svg class="alert-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
              <span>Student assistance request intake status has been successfully updated.</span>
            </div>
            <button type="button" class="alert-banner-close" onclick="this.parentElement.style.display='none'">&times;</button>
          </div>
        <?php elseif (isset($_GET['saved_contacts'])): ?>
          <div class="alert-banner alert-success mb-6" role="alert" style="margin-bottom: var(--space-5);">
            <div class="alert-banner-content">
              <svg class="alert-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
              <span>COEASC DRRM contact information has been updated and published across all student and admin pages.</span>
            </div>
            <button type="button" class="alert-banner-close" onclick="this.parentElement.style.display='none'">&times;</button>
          </div>
        <?php endif; ?>

        <!-- Combined Panels Grid (Controls & Contacts) -->
        <div class="controls-panels-grid">

          <!-- 1. Incident & Request Intake Control Card -->
          <div class="controls-full-card" id="intakeControlPanel">
            <div>
              <div class="controls-card-header">
                <div class="control-header-left">
                  <div class="control-icon-box">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                  </div>
                  <div>
                    <h3 class="control-title-text">Student Request Intake</h3>
                    <span class="fs-2xs text-muted">Post-Disaster Incident Response Switch</span>
                  </div>
                </div>

                <?php if ($isAssistanceEnabled): ?>
                  <span class="control-status-pill is-active">
                    <span class="control-status-dot dot-active"></span>
                    <span>ACTIVE (Open)</span>
                  </span>
                <?php else: ?>
                  <span class="control-status-pill is-closed">
                    <span class="control-status-dot dot-closed"></span>
                    <span>CLOSED (Stand Down)</span>
                  </span>
                <?php endif; ?>
              </div>

              <!-- Live Status Banner -->
              <div style="margin-top: var(--space-4);">
                <?php if ($isAssistanceEnabled): ?>
                  <div class="modal-banner-active">
                    <div class="modal-banner-dot-active"></div>
                    <div>
                      <div class="modal-banner-title-active">INTAKE STATUS: ACTIVE (OPEN)</div>
                      <p class="modal-banner-desc-active">
                        Student relief assistance submissions are currently <strong>OPEN</strong>. Verified students can access the assistance form and submit disaster aid requests.
                      </p>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="modal-banner-paused">
                    <div class="modal-banner-dot-paused"></div>
                    <div>
                      <div class="modal-banner-title-paused">INTAKE STATUS: PAUSED (STAND DOWN)</div>
                      <p class="modal-banner-desc-paused">
                        Student request intake is currently <strong>PAUSED</strong>. The "Request Assistance" action is disabled and unavailable on student screens to avoid spamming during non-disaster periods.
                      </p>
                    </div>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Operational Checklist -->
              <div class="controls-meta-list">
                <div class="controls-meta-item">
                  <span class="controls-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                    Student Request Assistance Button
                  </span>
                  <span class="controls-meta-value <?= $isAssistanceEnabled ? 'text-success' : 'text-muted' ?>">
                    <?= $isAssistanceEnabled ? 'Clickable &amp; Active' : 'Disabled (Stand Down)' ?>
                  </span>
                </div>
                <div class="controls-meta-item">
                  <span class="controls-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Disaster Relief API Intake
                  </span>
                  <span class="controls-meta-value <?= $isAssistanceEnabled ? 'text-success' : 'text-muted' ?>">
                    <?= $isAssistanceEnabled ? 'Accepting Submissions' : 'Restricted' ?>
                  </span>
                </div>
                <div class="controls-meta-item">
                  <span class="controls-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Council Operations Status
                  </span>
                  <span class="controls-meta-value <?= $isAssistanceEnabled ? 'text-success' : 'text-muted' ?>">
                    <?= $isAssistanceEnabled ? 'Monitoring Active Dispatches' : 'Standby Mode' ?>
                  </span>
                </div>
              </div>

              <p class="control-desc-text">
                Activate this <strong>ON</strong> whenever campus-wide or localized weather disturbances, floods, earthquakes, or emergencies impact students. Turn <strong>OFF</strong> during calm periods.
              </p>
            </div>

            <!-- Toggle Form -->
            <form method="POST" action="admin-controls.php" onsubmit="return confirm('<?= $isAssistanceEnabled ? 'Are you sure you want to CLOSE student request submissions? The request button will be unavailable for students.' : 'Are you sure you want to ACTIVATE student request submissions? The request button will become functional again for students.' ?>')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle_assistance">
              <input type="hidden" name="new_state" value="<?= $isAssistanceEnabled ? '0' : '1' ?>">

              <?php if ($isAssistanceEnabled): ?>
                <button type="submit" class="control-btn-toggle-off">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                  <span>Turn OFF Request Intake (Stand Down)</span>
                </button>
              <?php else: ?>
                <button type="submit" class="control-btn-toggle-on">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  <span>Turn ON Request Intake (Activate Disaster Mode)</span>
                </button>
              <?php endif; ?>
            </form>
          </div>

          <!-- 2. COEASC DRRM Contact Details Card -->
          <div class="controls-full-card" id="contactsPanel">
            <div>
              <div class="controls-card-header">
                <div class="control-header-left">
                  <div class="control-icon-box">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                  </div>
                  <div>
                    <h3 class="control-title-text">COEASC DRRM Contact Details</h3>
                    <span class="fs-2xs text-muted">Synchronized Across All User &amp; Admin Pages</span>
                  </div>
                </div>

                <span class="control-status-pill is-active">
                  <span class="control-status-dot dot-active"></span>
                  <span>BROADCAST SYNCED</span>
                </span>
              </div>

              <p class="control-desc-text" style="margin-top: var(--space-4);">
                Update the official mobile hotline and email address. Any updates saved here will instantly reflect on student banners, emergency contact sections, and footers across all devices.
              </p>

              <!-- Contacts Form -->
              <form method="POST" action="admin-controls.php" class="control-form-stack">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                <input type="hidden" name="action" value="update_contacts">

                <div>
                  <label class="control-field-label" for="contactPhoneInput">DRRM Contact Hotline Number</label>
                  <div class="modal-input-group">
                    <input type="text" id="contactPhoneInput" name="contact_phone" value="<?= htmlspecialchars($contactPhone) ?>" required class="modal-input-field" placeholder="e.g. 09556678451">
                    <svg class="modal-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                  </div>
                  <span class="modal-field-hint">Primary number for emergency calls and urgent SMS dispatch.</span>
                </div>

                <div>
                  <label class="control-field-label" for="contactEmailInput">DRRM Official Email Address</label>
                  <div class="modal-input-group">
                    <input type="email" id="contactEmailInput" name="contact_email" value="<?= htmlspecialchars($contactEmail) ?>" required class="modal-input-field" placeholder="e.g. alerto.coeasc.csu@gmail.com">
                    <svg class="modal-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                      <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                  </div>
                  <span class="modal-field-hint">Official council email for inquiries and relief coordination logs.</span>
                </div>

                <button type="submit" class="control-submit-btn">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                  <span>Save &amp; Publish Contacts</span>
                </button>
              </form>
            </div>

            <!-- Broadcast Channels Info -->
            <div class="controls-meta-list" style="margin-top: var(--space-4); margin-bottom: 0;">
              <div class="controls-meta-item">
                <span class="controls-meta-label">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                  Student Emergency Banner
                </span>
                <span class="controls-meta-value text-success">Active &amp; Broadcasted</span>
              </div>
              <div class="controls-meta-item">
                <span class="controls-meta-label">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                  Portal Footer Direct Links
                </span>
                <span class="controls-meta-value text-success">Synced</span>
              </div>
            </div>
          </div>

        </div>

        <!-- 3. Student-Facing Live Simulation Card -->
        <div class="controls-full-card" style="margin-top: var(--space-6);">
          <div>
            <div class="controls-card-header">
              <div class="control-header-left">
                <div class="control-icon-box" style="background: #f0fdf4; color: #166534; border-color: #bbf7d0;">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                  </svg>
                </div>
                <div>
                  <h3 class="control-title-text">Live Student Portal Preview</h3>
                  <span class="fs-2xs text-muted">Simulated appearance of DRRM status &amp; contacts as seen by students</span>
                </div>
              </div>

              <span class="control-status-pill is-active">
                <span class="control-status-dot dot-active"></span>
                <span>REAL-TIME VIEW</span>
              </span>
            </div>

            <!-- Simulation Box -->
            <div class="preview-sim-box" style="margin-top: var(--space-5);">
              
              <div class="preview-sim-header">
                <div>
                  <span class="fs-2xs text-muted" style="text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">Student Incident Banner</span>
                  <div style="font-weight: 700; font-size: 1rem; color: var(--admin-text); margin-top: 2px;">
                    <?php if ($isAssistanceEnabled): ?>
                      <span style="color: #1e6b37;">&#9889; Active Emergency Relief Operations</span>
                    <?php else: ?>
                      <span style="color: #64748b;">&#9432; Non-Disaster Standby Mode</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div>
                  <?php if ($isAssistanceEnabled): ?>
                    <span class="preview-cta-btn is-active">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                      <span>Request Assistance (Clickable)</span>
                    </span>
                  <?php else: ?>
                    <span class="preview-cta-btn is-disabled">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                      <span>Request Intake Closed</span>
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Student View Contact Chips -->
              <div class="preview-chips-row">
                <div class="preview-chip" title="Direct Phone Hotline">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                  <span>Hotline: <?= htmlspecialchars($contactPhone) ?></span>
                </div>

                <div class="preview-chip" title="Official Council Email">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                  <span>Email: <?= htmlspecialchars($contactEmail) ?></span>
                </div>

                <div class="preview-chip" title="Station Headquarters">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                  <span>CSU Carig Campus, COEA</span>
                </div>
              </div>

            </div>
          </div>
        </div>

      </div>

    </div>

  </div>

  <!-- Client Script for Sidebar Toggle -->
  <script src="assets/js/main.js"></script>
  <script>
    // Mobile Sidebar Drawer Toggle
    const mobileToggle = document.getElementById('sidebarMobileToggle');
    const adminSidebar = document.getElementById('adminSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (mobileToggle && adminSidebar && sidebarBackdrop) {
      mobileToggle.addEventListener('click', () => {
        const isOpen = adminSidebar.classList.toggle('sidebar-open');
        sidebarBackdrop.classList.toggle('active', isOpen);
        mobileToggle.setAttribute('aria-expanded', isOpen);
      });

      sidebarBackdrop.addEventListener('click', () => {
        adminSidebar.classList.remove('sidebar-open');
        sidebarBackdrop.classList.remove('active');
        mobileToggle.setAttribute('aria-expanded', 'false');
      });
    }
  </script>
</body>

</html>
