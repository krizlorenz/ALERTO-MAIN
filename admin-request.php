<?php
session_start();
require_once 'alerto-db.php';
require_once 'notification-service.php';

// 1. Process logout request first
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: admin-login.php");
    exit;
}

// 2. Protect page with session guard
$currentAdmin = validate_active_session($pdo, ['admin', 'superadmin']);

// 3. Handle Admin Controls: Toggle Assistance or Update Contacts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (in_array($_POST['action'], ['toggle_assistance', 'update_contacts'], true)) {
        if (!verify_csrf_token($submittedToken)) {
            header("Location: admin-request.php?error=" . urlencode('Invalid or expired security token.'));
            exit;
        }

        if ($_POST['action'] === 'toggle_assistance') {
            if (!isset($_POST['new_state']) || !in_array((string)$_POST['new_state'], ['0', '1'], true)) {
                header("Location: admin-request.php?error=" . urlencode('Invalid intake state.'));
                exit;
            }
            $newState = ($_POST['new_state'] === '1') ? '1' : '0';
            update_system_setting($pdo, 'request_assistance_enabled', $newState);
            header("Location: admin-request.php?toggled=1");
            exit;
        }

        if ($_POST['action'] === 'update_contacts') {
            $phone = trim($_POST['contact_phone'] ?? '');
            $email = trim($_POST['contact_email'] ?? '');
            $phoneDigits = preg_replace('/\D/', '', $phone);

            if (empty($phone) || !preg_match('/^[0-9+() -]{7,25}$/', $phone) || strlen($phoneDigits) < 7) {
                header("Location: admin-request.php?error=" . urlencode('Invalid phone format.'));
                exit;
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
                header("Location: admin-request.php?error=" . urlencode('Invalid email format.'));
                exit;
            }

            update_system_setting($pdo, 'contact_phone', $phone);
            update_system_setting($pdo, 'contact_email', $email);
            header("Location: admin-request.php?saved_settings=1");
            exit;
        }
    }
}

// 4. Handle request status updates via standard POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['status'])) {
    $req_id = intval($_POST['request_id']);
    $req_status = trim($_POST['status']);
    
    if (in_array($req_status, ['pending', 'approved', 'in_progress', 'completed', 'archived', 'rejected'])) {
        // Fetch current status to ensure genuine transition
        $stmtOld = $pdo->prepare("SELECT status FROM assistance_requests WHERE id = ? AND LOWER(status) != 'cancelled'");
        $stmtOld->execute([$req_id]);
        $old_status = $stmtOld->fetchColumn();

        if ($old_status !== false && strtolower(trim($old_status)) !== strtolower(trim($req_status))) {
            $stmt = $pdo->prepare("UPDATE assistance_requests SET status = ? WHERE id = ? AND LOWER(status) != 'cancelled'");
            $stmt->execute([$req_status, $req_id]);

            // Notify student only for: approved, in_progress, completed, rejected (no cancellation or archive emails)
            if (in_array($req_status, ['approved', 'in_progress', 'completed', 'rejected'])) {
                notify_student_request_status($pdo, $req_id, $req_status, $old_status);
            }
        }
        
        header("Location: admin-request.php");
        exit;
    }
}

// Retrieve dynamic system settings
$sysSettings = get_system_settings($pdo);
$contactPhone = $sysSettings['contact_phone'] ?? '09556678451';
$contactEmail = $sysSettings['contact_email'] ?? 'alerto.coeasc.csu@gmail.com';
$isAssistanceEnabled = ($sysSettings['request_assistance_enabled'] ?? '1') === '1';

// 5. Fetch all student assistance requests from database (excluding cancelled requests)
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name, u.student_id, u.email, u.contact_number, u.program, u.year_level 
    FROM assistance_requests r
    JOIN users u ON r.user_id = u.id
    WHERE (r.status IS NULL OR LOWER(r.status) != 'cancelled')
    ORDER BY r.id DESC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count pending verifications dynamically for sidebar badge
$stmtPending = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (status = 'unverified' OR status = 'pending')");
$pendingVerificationCount = $stmtPending->fetchColumn();

// Active Requests count for sidebar & top right (Pending, Approved, In Progress)
$stmtActiveCount = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE LOWER(status) IN ('pending', 'approved', 'in_progress', 'in progress')");
$activeRequestsCount = $stmtActiveCount->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Request Management | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Plus Jakarta Sans, Inter & JetBrains Mono (3-Font System) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

  <!-- Interactive Map Engine (Supports Google Maps Tiles & Live Pinning) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
          </span>
          <span>Dashboard</span>
        </a>

        <a href="admin-verify.php" class="nav-item-link">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
          </span>
          <span>Verifications</span>
          <span class="nav-item-badge"><?= $pendingVerificationCount ?></span>
        </a>

        <a href="admin-request.php" class="nav-item-link active">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
          </span>
          <span>Requests</span>
          <span class="nav-item-badge"><?= $activeRequestsCount ?></span>
        </a>

        <a href="admin-controls.php" class="nav-item-link" title="Disaster Response Controls &amp; DRRM Contacts">
          <span class="nav-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
            <span class="admin-page-eyebrow">Assistance Coordination</span>
            <h1 class="admin-page-title">Request Management</h1>
            <p class="admin-page-desc">Review, prioritize, assign, and monitor submitted assistance requests.</p>
          </div>
          <div class="admin-top-stat">
            <div class="top-stat-number" id="totalRequestsCount"><?= $activeRequestsCount ?></div>
            <div class="top-stat-label">Active Requests</div>
          </div>
        </div>

        <!-- Interactive Search & Filter Card -->
        <div class="admin-filter-card">
          <div class="search-input-wrapper">
            <svg class="search-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" class="search-input" id="requestSearchInput" placeholder="Search request ID, student name, or location..." aria-label="Search requests">
          </div>

          <div class="filter-actions-group">
            <select class="status-select-dropdown" id="statusFilterSelect" aria-label="Filter by request status">
              <option value="all">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="in_progress">In Progress</option>
              <option value="completed">Completed</option>
              <option value="rejected">Rejected</option>
              <option value="archived">Archived</option>
            </select>
          </div>
        </div>

        <!-- Data Table Card -->
        <div class="data-table-card">
          <div class="table-responsive-wrapper">
            <table class="admin-data-table" id="requestsTable">
              <thead>
                <tr>
                  <th scope="col">ID</th>
                  <th scope="col">Name</th>
                  <th scope="col">Assistance</th>
                  <th scope="col">Location</th>
                  <th scope="col">Status</th>
                  <th scope="col">Submitted</th>
                  <th scope="col" class="text-right">Action</th>
                </tr>
              </thead>
              <tbody id="requestsTableBody">
                <?php if (empty($requests)): ?>
                <tr>
                  <td colspan="7" class="table-empty-message">No assistance requests found in the database.</td>
                </tr>
                <?php else: ?>
                  <?php foreach ($requests as $req): 
                      $requestCode = !empty($req['request_code']) ? $req['request_code'] : 'REQ-' . str_pad($req['id'], 5, '0', STR_PAD_LEFT);
                      $statusRaw = trim($req['status'] ?? 'pending');
                      $statusLower = strtolower($statusRaw);
                      
                      // Map status classes
                      $pillClass = 'pending';
                      $statusLabel = 'Pending';
                      if ($statusLower === 'approved') {
                          $pillClass = 'approved';
                          $statusLabel = 'Approved';
                      } elseif (strpos($statusLower, 'in_progress') !== false || strpos($statusLower, 'in progress') !== false) {
                          $pillClass = 'in-progress';
                          $statusLabel = 'In Progress';
                          $statusLower = 'in_progress';
                      } elseif ($statusLower === 'completed') {
                          $pillClass = 'completed';
                          $statusLabel = 'Completed';
                      } elseif ($statusLower === 'rejected') {
                          $pillClass = 'rejected';
                          $statusLabel = 'Rejected';
                      } elseif ($statusLower === 'cancelled') {
                          $pillClass = 'cancelled';
                          $statusLabel = 'Cancelled';
                      } elseif ($statusLower === 'archived') {
                          $pillClass = 'archived';
                          $statusLabel = 'Archived';
                      }

                      $resourcesVal = $req['resources'] ?? '';
                      $landmarkVal = $req['landmark'] ?? '';
                      $descriptionVal = $req['description'] ?? 'No additional remarks provided.';
                      $submittedTimestamp = $req['submitted_at'] ?? $req['created_at'] ?? null;
                      $submittedDateStr = $submittedTimestamp ? date('M j, Y', strtotime($submittedTimestamp)) : 'Recent';
                  ?>
                  <tr data-status="<?= $statusLower ?>" data-search="<?= htmlspecialchars(strtolower($requestCode . ' ' . $req['full_name'] . ' ' . $resourcesVal . ' ' . $landmarkVal . ' ' . $req['student_id'])) ?>">
                    <td><span class="table-id-badge"><?= htmlspecialchars($requestCode) ?></span></td>
                    <td>
                      <div class="table-primary-text"><?= htmlspecialchars($req['full_name']) ?></div>
                      <div class="table-secondary-text"><?= htmlspecialchars($req['student_id']) ?></div>
                    </td>
                    <td>
                      <span class="table-primary-text"><?= htmlspecialchars($resourcesVal) ?></span>
                    </td>
                    <td><?= htmlspecialchars($landmarkVal) ?></td>
                    <td><span class="status-pill <?= $pillClass ?>"><?= $statusLabel ?></span></td>
                    <td><?= $submittedDateStr ?></td>
                    <td class="text-right">
                      <button type="button" class="table-action-btn" onclick="openRequestModal(
                        '<?= addslashes($requestCode) ?>',
                        '<?= addslashes($req['full_name']) ?>',
                        '<?= addslashes($req['student_id']) ?>',
                        '<?= addslashes($req['contact_number'] ?? 'N/A') ?>',
                        '<?= addslashes($req['email']) ?>',
                        '<?= addslashes($resourcesVal) ?>',
                        '<?= addslashes($landmarkVal) ?>',
                        '<?= $statusLabel ?>',
                        '<?= addslashes(str_replace(["\r", "\n"], ' ', $descriptionVal)) ?>',
                        <?= $req['id'] ?>,
                        <?= floatval($req['latitude'] ?? 17.6534) ?>,
                        <?= floatval($req['longitude'] ?? 121.7512) ?>
                      )">View</button>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Empty State (Shows when search has no matches) -->
          <div class="table-empty-state" id="tableEmptyState">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              <line x1="8" y1="11" x2="14" y2="11"></line>
            </svg>
            <h4>No matching requests found</h4>
            <p>Try adjusting your search keywords or status filter.</p>
          </div>

          <!-- Table Footer -->
          <div class="table-footer">
            <div class="table-pagination-info" id="paginationInfo">
              <?= count($requests) > 0 ? 'Showing 1 to ' . min(7, count($requests)) . ' of ' . count($requests) . ' entries' : 'Showing 0 of 0 entries' ?>
            </div>
            <div class="table-pagination-controls" id="requestPaginationControls">
              <button type="button" class="pagination-btn" disabled>&larr; Previous</button>
              <button type="button" class="pagination-btn pagination-btn-active">1</button>
              <button type="button" class="pagination-btn" <?= count($requests) <= 7 ? 'disabled' : '' ?>>Next &rarr;</button>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- Detail Review Modal Dialog -->
  <dialog id="requestDetailModal">
    <div class="modal-box-header">
      <div>
        <span class="admin-page-eyebrow" id="modalRequestEyebrow">Request Details</span>
        <h3 id="modalRequestId" class="modal-box-title">REQ-00021</h3>
      </div>
      <button type="button" onclick="closeRequestModal()" class="modal-close-icon-btn" aria-label="Close modal">&times;</button>
    </div>

    <div class="modal-body-scrollable">
      <div class="modal-detail-row">
        <span class="modal-detail-label">Name:</span>
        <strong id="modalRequesterName" class="modal-detail-value">--</strong>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Student ID:</span>
        <span id="modalStudentId" class="modal-detail-value-mono">--</span>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Contact Number:</span>
        <strong id="modalContactNumber" class="modal-detail-value-mono">--</strong>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Email Address:</span>
        <span id="modalEmailAddress" class="modal-detail-value">--</span>
      </div>
      <div class="modal-detail-row">
        <span class="modal-detail-label">Assistance Needed:</span>
        <strong id="modalAssistanceType" class="modal-detail-value-mono">--</strong>
      </div>
      
      <!-- Incident Location with Interactive Minimap Button -->
      <div class="modal-detail-row">
        <span class="modal-detail-label">Location &amp; Landmark:</span>
        <div class="flex-items-center-gap-2">
          <strong id="modalLocation" class="modal-detail-value">--</strong>
          <button type="button" class="table-action-btn modal-minimap-btn" id="toggleRequestMinimapBtn" onclick="toggleRequestMinimap()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon>
              <line x1="8" y1="2" x2="8" y2="18"></line>
              <line x1="16" y1="6" x2="16" y2="22"></line>
            </svg>
            <span id="requestMinimapBtnText">View Minimap</span>
          </button>
        </div>
      </div>

      <!-- Collapsible Request Incident Minimap -->
      <div id="requestMinimapCard" class="modal-minimap-card">
        <div class="modal-minimap-header">
          <span class="modal-minimap-coords" id="requestMinimapCoordsLabel">📍 17.6534° N, 121.7512° E</span>
          <span class="modal-detail-label">Emergency Pin Location</span>
        </div>
        <div id="adminRequestMinimap" class="modal-minimap-container"></div>
      </div>

      <div class="modal-detail-row">
        <span class="modal-detail-label">Status:</span>
        <span id="modalStatusBadge"><span class="status-pill pending">Pending</span></span>
      </div>
      <div class="modal-notes-section">
        <span class="modal-notes-label">Incident Notes &amp; Description:</span>
        <div id="modalDescription" class="modal-notes-box">
          --
        </div>
      </div>
    </div>

    <div class="modal-box-footer" id="requestModalFooterActions">
      <!-- Populated dynamically based on request status -->
    </div>
  </dialog>

  <!-- Client Script for Search, Sidebar & Request Minimap -->
  <script src="assets/js/main.js"></script>
  <script>
    function openControlsModal() {
      window.location.href = 'admin-controls.php';
    }

    function closeControlsModal() {}

    function openContactsModal() {
      window.location.href = 'admin-controls.php#contactsPanel';
    }

    function closeContactsModal() {}

    let reqMinimap = null;
    let reqMarker = null;
    let reqLat = 17.6534;
    let reqLng = 121.7512;
    let currentDatabaseId = null;
    let currentRequestStatus = '';

    function closeRequestModal() {
      const modal = document.getElementById('requestDetailModal');
      if (modal) modal.close();
      const minimapCard = document.getElementById('requestMinimapCard');
      if (minimapCard) minimapCard.style.display = 'none';
      const btnText = document.getElementById('requestMinimapBtnText');
      if (btnText) btnText.textContent = 'View Minimap';
    }

    // Dynamic Action Buttons Generator with reject, archive & restore support
    function renderModalActions(status) {
      const footerEl = document.getElementById('requestModalFooterActions');
      if (!footerEl) return;
      const statusLower = (status || '').toLowerCase().trim();

      if (statusLower === 'pending') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-reject-action" onclick="handleModalRejectRequest()">Reject Request</button>
          <button type="button" class="table-action-btn btn-filled btn-indigo-action" onclick="sendStatusUpdateToServer('approved', 'Request approved successfully.')">Approve</button>
        `;
      } else if (statusLower === 'approved') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-reject-action" onclick="handleModalRejectRequest()">Reject Request</button>
          <button type="button" class="table-action-btn btn-filled btn-indigo-action" onclick="sendStatusUpdateToServer('in_progress', 'Relief aid assigned and marked In Progress.')">Assign Relief Aid</button>
        `;
      } else if (statusLower === 'in progress' || statusLower === 'in_progress') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled btn-indigo-action" onclick="sendStatusUpdateToServer('completed', 'Relief delivered and marked Completed.')">Complete</button>
        `;
      } else if (statusLower === 'completed') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled btn-indigo-action" onclick="sendStatusUpdateToServer('archived', 'Request archived successfully.')">Archive</button>
        `;
      } else if (statusLower === 'rejected') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled btn-indigo-action" onclick="sendStatusUpdateToServer('pending', 'Request restored to Pending.')">Restore to Pending</button>
        `;
      } else if (statusLower === 'archived') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled btn-indigo-action" onclick="sendStatusUpdateToServer('completed', 'Request restored to Completed.')">Restore to Completed</button>
        `;
      } else {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
        `;
      }
    }

    // Modal Handler
    function openRequestModal(code, requester, studentId, contact, email, assistance, location, status, desc, dbId, lat, lng) {
      currentDatabaseId = dbId;
      currentRequestStatus = status;
      reqLat = lat || 17.6534;
      reqLng = lng || 121.7512;

      document.getElementById('modalRequestId').textContent = code;
      document.getElementById('modalRequesterName').textContent = requester;
      document.getElementById('modalStudentId').textContent = studentId;
      document.getElementById('modalContactNumber').textContent = contact || 'N/A';
      document.getElementById('modalEmailAddress').textContent = email || 'N/A';
      document.getElementById('modalAssistanceType').textContent = assistance;
      document.getElementById('modalLocation').textContent = location;
      document.getElementById('modalDescription').textContent = desc;

      const coordsEl = document.getElementById('requestMinimapCoordsLabel');
      if (coordsEl) coordsEl.textContent = `📍 ${reqLat.toFixed(4)}° N, ${reqLng.toFixed(4)}° E (${location})`;

      const badgeEl = document.getElementById('modalStatusBadge');
      let pillClass = 'pending';
      const statusLower = (status || '').toLowerCase().trim();
      if (statusLower === 'completed') pillClass = 'completed';
      else if (statusLower === 'approved') pillClass = 'approved';
      else if (statusLower === 'in progress' || statusLower === 'in_progress') pillClass = 'in-progress';
      else if (statusLower === 'rejected') pillClass = 'rejected';
      else if (statusLower === 'cancelled') pillClass = 'cancelled';
      else if (statusLower === 'archived') pillClass = 'archived';
      
      badgeEl.innerHTML = `<span class="status-pill ${pillClass}">${status}</span>`;

      renderModalActions(status);

      // Hide minimap by default on open
      const minimapCard = document.getElementById('requestMinimapCard');
      if (minimapCard) minimapCard.style.display = 'none';
      const btnText = document.getElementById('requestMinimapBtnText');
      if (btnText) btnText.textContent = 'View Minimap';

      const modal = document.getElementById('requestDetailModal');
      if (modal) modal.showModal();
    }

    // Reject Request confirmation & server dispatch
    function handleModalRejectRequest() {
      if (confirm('Are you sure you want to reject this assistance request?')) {
        sendStatusUpdateToServer('rejected', 'Assistance request has been rejected.');
      }
    }

    // Reliable Direct Form Submission Handler for Status Updates
    function sendStatusUpdateToServer(newStatus, successMessage) {
      if (!currentDatabaseId) return;

      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'admin-request.php';

      const idInput = document.createElement('input');
      idInput.type = 'hidden';
      idInput.name = 'request_id';
      idInput.value = currentDatabaseId;
      form.appendChild(idInput);

      const statusInput = document.createElement('input');
      statusInput.type = 'hidden';
      statusInput.name = 'status';
      statusInput.value = newStatus;
      form.appendChild(statusInput);

      document.body.appendChild(form);
      alert(successMessage);
      form.submit();
    }

    // Toggle Minimap Inside Request Modal
    function toggleRequestMinimap() {
      const minimapCard = document.getElementById('requestMinimapCard');
      const btnText = document.getElementById('requestMinimapBtnText');
      if (!minimapCard) return;

      if (minimapCard.style.display === 'none' || minimapCard.style.display === '') {
        minimapCard.style.display = 'block';
        if (btnText) btnText.textContent = 'Hide Minimap';

        // Initialize or update Leaflet map
        setTimeout(() => {
          if (!reqMinimap) {
            reqMinimap = L.map('adminRequestMinimap', {
              zoomControl: true,
              attributionControl: false
            }).setView([reqLat, reqLng], 16);

            // Google Maps Standard Road Tile Layer
            L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
              maxZoom: 20,
              subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
            }).addTo(reqMinimap);

            // Google Maps Style Red Pin Icon
            const googleRedPinIcon = L.divIcon({
              className: 'custom-google-pin',
              html: `
                <div class="google-pin-container">
                  <svg width="32" height="42" viewBox="0 0 24 32" fill="none">
                    <path d="M12 0C5.37 0 0 5.37 0 12c0 9 12 20 12 20s12-11 12-20c0-6.63-5.37-12-12-12z" fill="#ea4335"/>
                    <circle cx="12" cy="11" r="4.5" fill="#ffffff"/>
                    <circle cx="12" cy="11" r="2" fill="#b31412"/>
                  </svg>
                </div>
              `,
              iconSize: [32, 42],
              iconAnchor: [16, 42],
              popupAnchor: [0, -36]
            });

            reqMarker = L.marker([reqLat, reqLng], {
              icon: googleRedPinIcon
            }).addTo(reqMinimap);

            reqMarker.bindPopup(`<strong>Incident Location</strong><br>${document.getElementById('modalLocation').textContent}`);
          } else {
            reqMinimap.setView([reqLat, reqLng], 16);
            if (reqMarker) {
              reqMarker.setLatLng([reqLat, reqLng]);
              reqMarker.setPopupContent(`<strong>Incident Location</strong><br>${document.getElementById('modalLocation').textContent}`);
            }
            reqMinimap.invalidateSize();
          }
        }, 100);
      } else {
        minimapCard.style.display = 'none';
        if (btnText) btnText.textContent = 'View Minimap';
      }
    }

    // Search, Filter and Pagination logic (7 rows per page)
    document.addEventListener('DOMContentLoaded', () => {
      const searchInput = document.getElementById('requestSearchInput');
      const statusSelect = document.getElementById('statusFilterSelect');
      const tableRows = Array.from(document.querySelectorAll('#requestsTableBody tr[data-status]'));
      const emptyState = document.getElementById('tableEmptyState');
      const paginationInfo = document.getElementById('paginationInfo');
      const paginationControls = document.getElementById('requestPaginationControls');

      const PAGE_SIZE = 7;
      let currentPage = 1;

      function renderPaginationControls(totalPages, totalMatching) {
        if (!paginationControls) return;
        paginationControls.innerHTML = '';

        // Previous button
        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'pagination-btn';
        prevBtn.innerHTML = '&larr; Previous';
        prevBtn.disabled = currentPage <= 1 || totalMatching === 0;
        prevBtn.addEventListener('click', () => {
          if (currentPage > 1) {
            currentPage--;
            filterAndPaginate();
          }
        });
        paginationControls.appendChild(prevBtn);

        // Page number buttons
        const pagesCount = Math.max(1, totalPages);
        if (pagesCount <= 7) {
          for (let p = 1; p <= pagesCount; p++) {
            const pageBtn = document.createElement('button');
            pageBtn.type = 'button';
            pageBtn.className = 'pagination-btn' + (p === currentPage && totalMatching > 0 ? ' pagination-btn-active' : (p === 1 && totalMatching === 0 ? ' pagination-btn-active' : ''));
            pageBtn.textContent = p;
            if (p !== currentPage && totalMatching > 0) {
              pageBtn.addEventListener('click', () => {
                currentPage = p;
                filterAndPaginate();
              });
            }
            paginationControls.appendChild(pageBtn);
          }
        } else {
          const createPageBtn = (p) => {
            const pageBtn = document.createElement('button');
            pageBtn.type = 'button';
            pageBtn.className = 'pagination-btn' + (p === currentPage ? ' pagination-btn-active' : '');
            pageBtn.textContent = p;
            if (p !== currentPage) {
              pageBtn.addEventListener('click', () => {
                currentPage = p;
                filterAndPaginate();
              });
            }
            return pageBtn;
          };

          const createEllipsis = () => {
            const span = document.createElement('span');
            span.className = 'pagination-btn';
            span.style.cursor = 'default';
            span.style.opacity = '0.6';
            span.textContent = '...';
            return span;
          };

          paginationControls.appendChild(createPageBtn(1));
          if (currentPage > 3) {
            paginationControls.appendChild(createEllipsis());
          }

          const startPage = Math.max(2, currentPage - 1);
          const endPage = Math.min(pagesCount - 1, currentPage + 1);
          for (let p = startPage; p <= endPage; p++) {
            paginationControls.appendChild(createPageBtn(p));
          }

          if (currentPage < pagesCount - 2) {
            paginationControls.appendChild(createEllipsis());
          }
          paginationControls.appendChild(createPageBtn(pagesCount));
        }

        // Next button
        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'pagination-btn';
        nextBtn.innerHTML = 'Next &rarr;';
        nextBtn.disabled = currentPage >= totalPages || totalMatching === 0;
        nextBtn.addEventListener('click', () => {
          if (currentPage < totalPages) {
            currentPage++;
            filterAndPaginate();
          }
        });
        paginationControls.appendChild(nextBtn);
      }

      function filterAndPaginate() {
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const statusFilter = statusSelect ? statusSelect.value.toLowerCase() : 'all';

        const matchingRows = [];
        tableRows.forEach(row => {
          const searchData = (row.getAttribute('data-search') || '').toLowerCase();
          const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();

          const matchesQuery = query === '' || searchData.includes(query);
          const matchesStatus = statusFilter === 'all' || rowStatus === statusFilter;

          if (matchesQuery && matchesStatus) {
            matchingRows.push(row);
          } else {
            row.style.display = 'none';
          }
        });

        const totalMatching = matchingRows.length;
        const totalPages = Math.ceil(totalMatching / PAGE_SIZE) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        if (totalMatching === 0) {
          if (paginationInfo) {
            paginationInfo.textContent = 'Showing 0 to 0 of 0 entries';
          }
          if (emptyState) {
            emptyState.style.display = 'block';
          }
        } else {
          if (emptyState) {
            emptyState.style.display = 'none';
          }
          const startIndex = (currentPage - 1) * PAGE_SIZE;
          const endIndex = Math.min(startIndex + PAGE_SIZE, totalMatching);
          const displayStart = startIndex + 1;
          const displayEnd = endIndex;

          if (paginationInfo) {
            paginationInfo.textContent = `Showing ${displayStart} to ${displayEnd} of ${totalMatching} entries`;
          }

          matchingRows.forEach((row, idx) => {
            if (idx >= startIndex && idx < endIndex) {
              row.style.display = '';
            } else {
              row.style.display = 'none';
            }
          });
        }

        renderPaginationControls(totalPages, totalMatching);
      }

      if (searchInput) {
        searchInput.addEventListener('input', () => {
          currentPage = 1;
          filterAndPaginate();
        });
      }

      if (statusSelect) {
        statusSelect.addEventListener('change', () => {
          currentPage = 1;
          filterAndPaginate();
        });
      }

      filterAndPaginate();
    });
  </script>
</body>
</html>