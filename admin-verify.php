<?php
session_start();
require_once 'alerto-db.php';

// 1. Process logout first
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: admin-login.php");
    exit;
}

// 2. Protect page with session guard
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("Location: admin-login.php");
    exit;
}

// 3. Handle Admin Controls: Toggle Assistance or Update Contacts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'toggle_assistance' && isset($_POST['new_state'])) {
        $newState = intval($_POST['new_state']) ? '1' : '0';
        update_system_setting($pdo, 'request_assistance_enabled', $newState);
        header("Location: admin-verify.php?toggled=1");
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
        if (isset($_POST['request_assistance_enabled'])) {
            $reqEnabled = intval($_POST['request_assistance_enabled']) ? '1' : '0';
            update_system_setting($pdo, 'request_assistance_enabled', $reqEnabled);
        }
        header("Location: admin-verify.php?saved_settings=1");
        exit;
    }
}

// 4. Handle user verification status updates (supports AJAX fetch or regular POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['status'])) {
    $target_id = intval($_POST['user_id']);
    $new_status = $_POST['status'];
    if (in_array($new_status, ['verified', 'rejected', 'banned'])) {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $target_id]);
        
        // If requested via AJAX/fetch, exit early with success JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true]);
            exit;
        }
        
        header("Location: admin-verify.php");
        exit;
    }
}

// Retrieve dynamic system settings
$sysSettings = get_system_settings($pdo);
$contactPhone = $sysSettings['contact_phone'] ?? '09556678451';
$contactEmail = $sysSettings['contact_email'] ?? 'alertoCOEA@gmail.com';
$isAssistanceEnabled = ($sysSettings['request_assistance_enabled'] ?? '1') === '1';

// 5. Fetch all student registration records along with their document paths
$stmt = $pdo->prepare("
    SELECT u.id, u.student_id, u.full_name, u.email, u.program, u.year_level, u.status, u.contact_number, u.created_at,
           d.id_selfie_path, d.assessment_form_path
    FROM users u
    LEFT JOIN documents d ON u.id = d.user_id
    WHERE u.role = 'student' 
    ORDER BY u.id DESC
");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count pending verifications dynamically for header and sidebar badges (matching unverified or pending)
$stmtPending = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (status = 'unverified' OR status = 'pending')");
$pendingCount = $stmtPending->fetchColumn();

// Active Requests count for the requests sidebar badge (Pending, Approved, In Progress)
$stmtActiveCount = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) IN ('pending', 'approved', 'in_progress', 'in progress')");
$activeRequestsCount = $stmtActiveCount->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Verification | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Poppins & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap"
    rel="stylesheet">

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= time() ?>">
</head>

<body>

  <div class="admin-layout">

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Admin Sidebar -->
    <aside class="admin-sidebar" id="adminSidebar" aria-label="Admin Sidebar Navigation">

      <!-- Sidebar Brand -->
      <a href="admin-homepage.php" class="sidebar-brand">
        <div class="sidebar-logo">
          <img src="logo/user-main-logo.png" alt="ALERTO Admin Logo">
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

        <a href="admin-verify.php" class="nav-item-link active">
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

        <a href="admin-homepage.php#systemControls" onclick="openControlsModal(); return false;" class="nav-item-link" title="Disaster Response Intake &amp; Incident Controls">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
          </span>
          <span>Controls</span>
          <span class="nav-item-badge <?= $isAssistanceEnabled ? 'badge-online' : 'badge-offline' ?>">
            <?= $isAssistanceEnabled ? 'Active' : 'Paused' ?>
          </span>
        </a>

        <a href="admin-homepage.php#contactControls" onclick="openContactsModal(); return false;" class="nav-item-link" title="COEASC DRRM Contact Information">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
            </svg>
          </span>
          <span>Contacts</span>
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
            <span class="admin-page-eyebrow">Student Identification</span>
            <h1 class="admin-page-title">Student Profile Verification</h1>
            <p class="admin-page-desc">Review and verify student credentials, department enrollment, and account
              authenticity.</p>
          </div>
          <div class="admin-top-stat">
            <div class="top-stat-number" id="pendingVerificationCount"><?= $pendingCount ?></div>
            <div class="top-stat-label">Pending Verification</div>
          </div>
        </div>

        <!-- Interactive Search & Filter Card -->
        <div class="admin-filter-card">
          <div class="search-input-wrapper">
            <svg class="search-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" class="search-input" id="verificationSearchInput"
              placeholder="Search student ID, name, or program..." aria-label="Search verifications">
          </div>

          <div class="filter-actions-group">
            <select class="status-select-dropdown" id="verificationStatusFilter"
              aria-label="Filter by verification status">
              <option value="all">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="banned">Banned</option>
            </select>
          </div>
        </div>

        <!-- Data Table Card -->
        <div class="data-table-card">
          <div class="table-responsive-wrapper">
            <table class="admin-data-table" id="verificationsTable">
              <thead>
                <tr>
                  <th scope="col">Student ID</th>
                  <th scope="col">Student Name</th>
                  <th scope="col">College Program</th>
                  <th scope="col">Year Level</th>
                  <th scope="col">Status</th>
                  <th scope="col">Submitted</th>
                  <th scope="col" class="text-right">Action</th>
                </tr>
              </thead>
              <tbody id="verificationsTableBody">
                <?php if (empty($students)): ?>
                  <tr>
                    <td colspan="7" class="table-empty-message">
                      No student registration records found.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($students as $student): ?>
                    <?php 
                      $rawStatus = strtolower($student['status'] ?? 'unverified');
                      $statusLabel = 'Pending';
                      $statusClass = 'pending';

                      if ($rawStatus === 'verified' || $rawStatus === 'approved') {
                          $statusLabel = 'Approved';
                          $statusClass = 'approved';
                      } elseif ($rawStatus === 'rejected') {
                          $statusLabel = 'Rejected';
                          $statusClass = 'rejected';
                      } elseif ($rawStatus === 'banned') {
                          $statusLabel = 'Banned';
                          $statusClass = 'banned';
                      }

                      $submittedDate = !empty($student['created_at']) ? date('M j, Y', strtotime($student['created_at'])) : 'Recent';
                      $searchString = htmlspecialchars($student['student_id'] . ' ' . $student['full_name'] . ' ' . $student['program'] . ' ' . $student['year_level']);
                    ?>
                    <tr data-status="<?= strtolower($statusLabel) ?>" data-search="<?= $searchString ?>">
                      <td><span class="table-id-badge"><?= htmlspecialchars($student['student_id'] ?? 'N/A') ?></span></td>
                      <td>
                        <div class="table-primary-text"><?= htmlspecialchars($student['full_name']) ?></div>
                        <div class="table-secondary-text"><?= htmlspecialchars($student['email']) ?></div>
                      </td>
                      <td>
                        <span class="table-primary-text"><?= htmlspecialchars($student['program'] ?? 'N/A') ?></span>
                      </td>
                      <td><?= htmlspecialchars($student['year_level'] ?? 'N/A') ?></td>
                      <td><span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                      <td><?= $submittedDate ?></td>
                      <td class="text-right">
                        <button type="button" class="table-action-btn"
                          onclick="openVerificationModal(
                            '<?= htmlspecialchars($student['student_id'] ?? '') ?>',
                            '<?= htmlspecialchars($student['full_name'] ?? '') ?>',
                            '<?= htmlspecialchars($student['contact_number'] ?? 'N/A') ?>',
                            '<?= htmlspecialchars($student['email'] ?? '') ?>',
                            '<?= htmlspecialchars($student['program'] ?? 'N/A') ?>',
                            '<?= htmlspecialchars($student['year_level'] ?? 'N/A') ?>',
                            '<?= $statusLabel ?>',
                            '<?= $submittedDate ?>',
                            'Database registration record submitted for verification.',
                            <?= $student['id'] ?>,
                            '<?= htmlspecialchars($student['id_selfie_path'] ?? '') ?>',
                            '<?= htmlspecialchars($student['assessment_form_path'] ?? '') ?>'
                          )">View</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Empty Search State -->
          <div class="table-empty-state" id="verificationEmptyState">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
              stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              <line x1="8" y1="11" x2="14" y2="11"></line>
            </svg>
            <h4>No matching students found</h4>
            <p>Try searching for a different student ID, name, or change the status filter.</p>
          </div>

          <!-- Table Footer -->
          <div class="table-footer">
            <div class="table-pagination-info" id="verificationPaginationInfo">
              <?= count($students) > 0 ? 'Showing 1 to ' . count($students) . ' of ' . count($students) . ' entries' : 'Showing 0 of 0 entries' ?>
            </div>
            <div class="table-pagination-controls">
              <button type="button" class="pagination-btn" disabled>&larr; Previous</button>
              <button type="button" class="pagination-btn pagination-btn-active">1</button>
              <button type="button" class="pagination-btn">Next &rarr;</button>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- Detail Verification Modal Dialog -->
  <dialog id="verificationDetailModal">
    <div class="modal-box-header">
      <div>
        <span class="admin-page-eyebrow">Student Verification</span>
        <h3 id="modalStudentTitle" class="modal-box-title">Student Name</h3>
      </div>
      <button type="button" onclick="document.getElementById('verificationDetailModal').close()" class="modal-close-icon-btn" aria-label="Close modal">&times;</button>
    </div>

    <div class="modal-body-scrollable">
      <div class="modal-detail-row">
        <span class="modal-detail-label">Student ID:</span>
        <strong id="modalStudentNumber" class="modal-detail-value-mono">--</strong>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Contact Number:</span>
        <strong id="modalStudentContact" class="modal-detail-value-mono">--</strong>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Email Address:</span>
        <span id="modalStudentEmail" class="modal-detail-value">--</span>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">College Program:</span>
        <strong id="modalStudentProgram" class="modal-detail-value">--</strong>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Year Level:</span>
        <span id="modalStudentYear" class="modal-detail-value">--</span>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Verification Status:</span>
        <span id="modalStudentStatusBadge"><span class="status-pill pending">Pending</span></span>
      </div>

      <!-- Attached Verification Documents -->
      <div class="doc-attachment-section">
        <span class="doc-attachment-label">Uploaded Verification Documents:</span>
        <div class="doc-attachment-grid">

          <!-- Document 1: Selfie with Govt ID / Student ID -->
          <div class="doc-attachment-card">
            <div class="doc-attachment-left">
              <div class="doc-attachment-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round">
                  <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                  <circle cx="12" cy="13" r="4"></circle>
                </svg>
              </div>
              <div class="doc-attachment-meta">
                <div class="doc-attachment-title">Selfie with ID</div>
                <div class="doc-attachment-filename" id="modalIdDocFilename">csu_id_selfie.png</div>
              </div>
            </div>
            <button type="button" onclick="viewSelfieDocument()" class="table-action-btn doc-attachment-view-btn">View</button>
          </div>

          <!-- Document 2: Assessment Form / COR -->
          <div class="doc-attachment-card">
            <div class="doc-attachment-left">
              <div class="doc-attachment-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="16" y1="13" x2="8" y2="13"></line>
                  <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
              </div>
              <div class="doc-attachment-meta">
                <div class="doc-attachment-title">Assessment Form</div>
                <div class="doc-attachment-filename" id="modalCorDocFilename">cor_assessment_2026.pdf</div>
              </div>
            </div>
            <button type="button" onclick="viewCorDocument()" class="table-action-btn doc-attachment-view-btn">View</button>
          </div>

        </div>
      </div>

      <div class="modal-notes-section">
        <span class="modal-notes-label">Verification Notes:</span>
        <div id="modalStudentNotes" class="modal-notes-box">
          Database record loaded for review.
        </div>
      </div>
    </div>

    <!-- Modal Footer Actions -->
    <div class="modal-box-footer" id="verificationModalFooterActions">
      <!-- Populated dynamically based on verification status -->
    </div>
  </dialog>

  <!-- 1. Disaster Response & Incident Controls Modal Dialog -->
  <dialog id="controlsModal" aria-labelledby="controlsModalTitle">
    <div class="modal-header-accent">
      <div class="modal-header-left">
        <div class="modal-header-icon-box">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
        </div>
        <div>
          <span class="modal-header-eyebrow">System &amp; Incident Controls</span>
          <h3 id="controlsModalTitle" class="modal-header-title-text">Student Request Intake</h3>
        </div>
      </div>
      <button type="button" onclick="closeControlsModal()" class="modal-close-icon-btn" aria-label="Close modal">&times;</button>
    </div>

    <div class="modal-body-scrollable">
      
      <!-- Live Status Banner -->
      <?php if ($isAssistanceEnabled): ?>
        <div class="modal-banner-active">
          <div class="modal-banner-dot-active"></div>
          <div>
            <div class="modal-banner-title-active">INTAKE STATUS: ACTIVE (OPEN)</div>
            <p class="modal-banner-desc-active">
              Student request submissions are currently <strong>OPEN</strong>. Students can access the request form and submit emergency disaster relief requests.
            </p>
          </div>
        </div>
      <?php else: ?>
        <div class="modal-banner-paused">
          <div class="modal-banner-dot-paused"></div>
          <div>
            <div class="modal-banner-title-paused">INTAKE STATUS: PAUSED (STAND DOWN)</div>
            <p class="modal-banner-desc-paused">
              Student request intake is currently <strong>PAUSED</strong>. The "Request Assistance" button is unavailable and disabled on all student screens to avoid spamming.
            </p>
          </div>
        </div>
      <?php endif; ?>

      <!-- Switch Action Box -->
      <div class="modal-switch-box">
        <div>
          <strong class="modal-switch-title">Toggle Request Submission State</strong>
          <p class="modal-switch-desc">
            Turn this <strong>ON</strong> immediately when a natural disaster or emergency affects students to start collecting requests. In non-disaster periods, keep it <strong>OFF</strong>.
          </p>
        </div>

        <form method="POST" action="admin-verify.php" onsubmit="return confirm('<?= $isAssistanceEnabled ? 'Are you sure you want to CLOSE student request submissions? The request button will be unavailable for students.' : 'Are you sure you want to ACTIVATE student request submissions? The request button will become functional again for students.' ?>')" class="modal-form-no-margin">
          <input type="hidden" name="action" value="toggle_assistance">
          <input type="hidden" name="new_state" value="<?= $isAssistanceEnabled ? '0' : '1' ?>">

          <?php if ($isAssistanceEnabled): ?>
            <button type="submit" class="control-btn-toggle-off">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
              <span>Turn OFF Request Intake (Stand Down)</span>
            </button>
          <?php else: ?>
            <button type="submit" class="control-btn-toggle-on">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
              <span>Turn ON Request Intake (Activate Disaster Mode)</span>
            </button>
          <?php endif; ?>
        </form>
      </div>

    </div>

    <div class="modal-box-footer">
      <button type="button" class="table-action-btn" onclick="closeControlsModal()">Close</button>
    </div>
  </dialog>

  <!-- 2. DRRM Emergency Contacts Modal Dialog -->
  <dialog id="contactsModal" aria-labelledby="contactsModalTitle">
    <div class="modal-header-accent">
      <div class="modal-header-left">
        <div class="modal-header-icon-box">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
        </div>
        <div>
          <span class="modal-header-eyebrow">COEASC DRRM Department</span>
          <h3 id="contactsModalTitle" class="modal-header-title-text">Emergency Contact Details</h3>
        </div>
      </div>
      <button type="button" onclick="closeContactsModal()" class="modal-close-icon-btn" aria-label="Close modal">&times;</button>
    </div>

    <form method="POST" action="admin-verify.php" class="modal-form-no-margin">
      <input type="hidden" name="action" value="update_contacts">

      <div class="modal-body-scrollable">
        <p class="modal-switch-desc">
          Update the council contact numbers and email address displayed in student banners, emergency contact sections, and footers across devices.
        </p>

        <!-- Contact Phone Number -->
        <div>
          <label for="contactModalPhone" class="control-field-label">DRRM Contact Hotline Number</label>
          <div class="modal-input-group">
            <input type="text" id="contactModalPhone" name="contact_phone" value="<?= htmlspecialchars($contactPhone) ?>" required class="modal-input-field" placeholder="e.g. 09556678451">
            <svg class="modal-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
            </svg>
          </div>
          <span class="modal-field-hint">Primary phone line for student disaster calls and SMS.</span>
        </div>

        <!-- Contact Email Address -->
        <div>
          <label for="contactModalEmail" class="control-field-label">DRRM Contact Email Address</label>
          <div class="modal-input-group">
            <input type="email" id="contactModalEmail" name="contact_email" value="<?= htmlspecialchars($contactEmail) ?>" required class="modal-input-field" placeholder="e.g. alertoCOEA@gmail.com">
            <svg class="modal-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
              <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
          </div>
          <span class="modal-field-hint">Official council email for inquiries and assistance logs.</span>
        </div>

      </div>

      <div class="modal-box-footer">
        <button type="button" class="table-action-btn" onclick="closeContactsModal()">Cancel</button>
        <button type="submit" class="table-action-btn btn-filled">Save Contacts</button>
      </div>
    </form>
  </dialog>

  <!-- Client Script for Search & Sidebar -->
  <script src="assets/js/main.js"></script>
  <script>
    function openControlsModal() {
      const modal = document.getElementById('controlsModal');
      if (modal && typeof modal.showModal === 'function') {
        modal.showModal();
      }
    }

    function closeControlsModal() {
      const modal = document.getElementById('controlsModal');
      if (modal && typeof modal.close === 'function') {
        modal.close();
      }
    }

    function openContactsModal() {
      const modal = document.getElementById('contactsModal');
      if (modal && typeof modal.showModal === 'function') {
        modal.showModal();
      }
    }

    function closeContactsModal() {
      const modal = document.getElementById('contactsModal');
      if (modal && typeof modal.close === 'function') {
        modal.close();
      }
    }

    let currentStudentId = '';
    let currentDatabaseId = null;
    let currentSelfiePath = '';
    let currentCorPath = '';

    // Dynamic Action Buttons Generator for Verification Modal (Cancel/Close only when approved/non-pending)
    function renderVerificationModalActions(status) {
      const footerEl = document.getElementById('verificationModalFooterActions');
      if (!footerEl) return;
      const statusLower = (status || '').toLowerCase().trim();

      if (statusLower === 'pending' || statusLower === 'unverified') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="document.getElementById('verificationDetailModal').close()">Close</button>
          <button type="button" class="table-action-btn btn-ban-action" onclick="handleModalBanAction()">Ban Account</button>
          <button type="button" class="table-action-btn btn-reject-action" onclick="handleModalRejectAction()">Reject</button>
          <button type="button" class="table-action-btn btn-filled" onclick="handleModalApproveAction()">Approve Profile</button>
        `;
      } else {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="document.getElementById('verificationDetailModal').close()">Cancel</button>
        `;
      }
    }

    // Modal Handler updated to accept selfiePath and corPath
    function openVerificationModal(studentId, name, contact, email, program, year, status, submitted, notes, dbId, selfiePath, corPath) {
      currentStudentId = studentId;
      currentDatabaseId = dbId;
      currentSelfiePath = selfiePath;
      currentCorPath = corPath;

      document.getElementById('modalStudentTitle').textContent = name;
      document.getElementById('modalStudentNumber').textContent = studentId;
      document.getElementById('modalStudentContact').textContent = contact || 'N/A';
      document.getElementById('modalStudentEmail').textContent = email;
      document.getElementById('modalStudentProgram').textContent = program;
      document.getElementById('modalStudentYear').textContent = year;
      document.getElementById('modalStudentNotes').textContent = notes;

      // Update filename text labels in UI
      if (selfiePath) {
          document.getElementById('modalIdDocFilename').textContent = selfiePath.split('/').pop();
      } else {
          document.getElementById('modalIdDocFilename').textContent = 'No file uploaded';
      }

      if (corPath) {
          document.getElementById('modalCorDocFilename').textContent = corPath.split('/').pop();
      } else {
          document.getElementById('modalCorDocFilename').textContent = 'No file uploaded';
      }

      const badgeEl = document.getElementById('modalStudentStatusBadge');
      let pillClass = 'pending';
      const statusLower = (status || '').toLowerCase().trim();
      if (statusLower === 'approved' || statusLower === 'verified') pillClass = 'approved';
      else if (statusLower === 'rejected') pillClass = 'rejected';
      else if (statusLower === 'banned') pillClass = 'banned';
      
      badgeEl.innerHTML = `<span class="status-pill ${pillClass}">${status}</span>`;

      renderVerificationModalActions(status);

      const modal = document.getElementById('verificationDetailModal');
      if (modal) modal.showModal();
    }

    // Handlers to open document paths in new tabs
    function viewSelfieDocument() {
        if (currentSelfiePath) {
            window.open(currentSelfiePath, '_blank');
        } else {
            alert('No Selfie ID document found for this user.');
        }
    }

    function viewCorDocument() {
        if (currentCorPath) {
            window.open(currentCorPath, '_blank');
        } else {
            alert('No Assessment Form (COR) document found for this user.');
        }
    }

    // Send Database Status Update via Fetch
    function sendStatusUpdateToServer(newStatus, successMessage) {
      if (!currentDatabaseId) return;

      const formData = new URLSearchParams();
      formData.append('user_id', currentDatabaseId);
      formData.append('status', newStatus);

      fetch('admin-verify.php', {
          method: 'POST',
          headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-Requested-With': 'XMLHttpRequest'
          },
          body: formData.toString()
      })
      .then(response => response.json())
      .then(data => {
          if (data.success) {
              alert(successMessage);
              window.location.reload();
          } else {
              alert('Failed to update status in the database.');
          }
      })
      .catch(error => {
          console.error('Error:', error);
          window.location.reload();
      });
    }

    // Modal Action Handlers
    function handleModalBanAction() {
      const studentName = document.getElementById('modalStudentTitle').textContent;
      if (confirm(`Are you sure you want to BAN the account of ${studentName} (${currentStudentId})?`)) {
          sendStatusUpdateToServer('banned', `Account for ${studentName} has been successfully BANNED.`);
      }
    }

    function handleModalRejectAction() {
      const studentName = document.getElementById('modalStudentTitle').textContent;
      if (confirm(`Are you sure you want to REJECT the application for ${studentName}?`)) {
          sendStatusUpdateToServer('rejected', `Student application for ${studentName} marked as Rejected.`);
      }
    }

    function handleModalApproveAction() {
      const studentName = document.getElementById('modalStudentTitle').textContent;
      if (confirm(`Are you sure you want to APPROVE the profile for ${studentName}?`)) {
          sendStatusUpdateToServer('verified', `Student profile for ${studentName} successfully Verified and Approved.`);
      }
    }

    // Search and Filter logic
    document.addEventListener('DOMContentLoaded', () => {
      const searchInput = document.getElementById('verificationSearchInput');
      const statusSelect = document.getElementById('verificationStatusFilter');
      const tableRows = document.querySelectorAll('#verificationsTableBody tr');
      const emptyState = document.getElementById('verificationEmptyState');
      const paginationInfo = document.getElementById('verificationPaginationInfo');
      const totalEntries = tableRows.length;

      function filterTable() {
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const statusFilter = statusSelect ? statusSelect.value.toLowerCase() : 'all';
        let visibleCount = 0;

        tableRows.forEach(row => {
          const searchData = (row.getAttribute('data-search') || '').toLowerCase();
          const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();

          const matchesQuery = query === '' || searchData.includes(query);
          const matchesStatus = statusFilter === 'all' || rowStatus === statusFilter;

          if (matchesQuery && matchesStatus) {
            row.style.display = '';
            visibleCount++;
          } else {
            row.style.display = 'none';
          }
        });

        if (emptyState) {
          emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
        }
        if (paginationInfo) {
          if (visibleCount === 0) {
            paginationInfo.textContent = `Showing 0 of ${totalEntries} entries`;
          } else {
            paginationInfo.textContent = `Showing 1 to ${visibleCount} of ${totalEntries} entries`;
          }
        }
      }

      if (searchInput) searchInput.addEventListener('input', filterTable);
      if (statusSelect) statusSelect.addEventListener('change', filterTable);
    });
  </script>
</body>

</html>