<?php
session_start();
require_once 'alerto-db.php';

// 1. Process logout request first
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

// 3. Handle request status updates via standard POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['status'])) {
    $req_id = intval($_POST['request_id']);
    $req_status = trim($_POST['status']);
    
    if (in_array($req_status, ['pending', 'approved', 'in_progress', 'completed', 'archived', 'cancelled', 'rejected'])) {
        $stmt = $pdo->prepare("UPDATE assistance_requests SET status = ? WHERE id = ?");
        $stmt->execute([$req_status, $req_id]);
        
        header("Location: admin-request.php");
        exit;
    }
}

// 4. Fetch all student assistance requests from database (including archived so they can be filtered)
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name, u.student_id, u.email, u.contact_number, u.program, u.year_level 
    FROM assistance_requests r
    JOIN users u ON r.user_id = u.id
    ORDER BY r.id DESC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count pending verifications dynamically for sidebar badge
$stmtPending = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'unverified'");
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

  <!-- Google Fonts: Poppins & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">

  <!-- Interactive Map Engine (Supports Google Maps Tiles & Live Pinning) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= time() ?>">
  <style>
    /* Ensure status pills never break text formatting across lines */
    .status-pill {
      white-space: nowrap !important;
      display: inline-block;
    }
  </style>
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
          <img src="logo/admin-main-logo.png" alt="ALERTO Admin Logo">
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
        <div style="display: flex; align-items: center; gap: 10px;">
          <button type="button" class="admin-mobile-toggle-btn" id="sidebarMobileToggle" aria-label="Open sidebar menu" aria-expanded="false" aria-controls="adminSidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="3" y1="12" x2="21" y2="12"></line>
              <line x1="3" y1="6" x2="21" y2="6"></line>
              <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
          </button>
          <span style="font-family: var(--heading-font); font-weight: 800; color: var(--admin-purple-mid); font-size: 1.15rem;">ALERTO Admin</span>
        </div>
        <a href="admin-homepage.php" class="table-action-btn" style="font-size: var(--fs-2xs);">Exit Portal</a>
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
              <option value="cancelled">Cancelled</option>
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
                  <th scope="col" style="text-align: right;">Action</th>
                </tr>
              </thead>
              <tbody id="requestsTableBody">
                <?php if (empty($requests)): ?>
                <tr>
                  <td colspan="7" style="text-align: center; padding: 40px; color: var(--admin-muted);">No assistance requests found in the database.</td>
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
                    <td style="text-align: right;">
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
          <div class="table-empty-state" id="tableEmptyState" style="display: none;">
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
              Showing 1 to <?= count($requests) ?> of <?= count($requests) ?> entries
            </div>
            <div class="table-pagination-controls">
              <button type="button" class="pagination-btn" disabled>&larr; Previous</button>
              <button type="button" class="pagination-btn" style="background: var(--admin-purple); color: #ffffff; border-color: var(--admin-purple);">1</button>
              <button type="button" class="pagination-btn">Next &rarr;</button>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- Detail Review Modal Dialog -->
  <dialog id="requestDetailModal">
    <div style="padding: var(--space-6); border-bottom: 1px solid var(--admin-border); display: flex; align-items: center; justify-content: space-between;">
      <div>
        <span class="admin-page-eyebrow" id="modalRequestEyebrow">Request Details</span>
        <h3 id="modalRequestId" style="font-size: var(--fs-lg); color: var(--admin-text);">REQ-00021</h3>
      </div>
      <button type="button" onclick="closeRequestModal()" style="font-size: 1.5rem; color: var(--admin-muted); cursor: pointer; padding: 4px 8px; border: none; background: transparent;">&times;</button>
    </div>

    <div style="padding: var(--space-6); display: flex; flex-direction: column; gap: var(--space-4); font-size: var(--fs-sm); max-height: 75vh; overflow-y: auto;">
      <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--admin-border-subtle); padding-bottom: var(--space-2);">
        <span style="color: var(--admin-muted);">Name:</span>
        <strong id="modalRequesterName" style="color: var(--admin-text);">--</strong>
      </div>
      <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--admin-border-subtle); padding-bottom: var(--space-2);">
        <span style="color: var(--admin-muted);">Student ID:</span>
        <span id="modalStudentId" style="font-family: monospace; font-weight: 600;">--</span>
      </div>
      <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--admin-border-subtle); padding-bottom: var(--space-2);">
        <span style="color: var(--admin-muted);">Contact Number:</span>
        <strong id="modalContactNumber" style="font-family: monospace; color: var(--admin-text);">--</strong>
      </div>
      <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--admin-border-subtle); padding-bottom: var(--space-2);">
        <span style="color: var(--admin-muted);">Email Address:</span>
        <span id="modalEmailAddress" style="color: var(--admin-text);">--</span>
      </div>
      <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--admin-border-subtle); padding-bottom: var(--space-2);">
        <span style="color: var(--admin-muted);">Assistance Needed:</span>
        <strong id="modalAssistanceType" style="color: var(--admin-purple-mid);">--</strong>
      </div>
      
      <!-- Incident Location with Interactive Minimap Button -->
      <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--admin-border-subtle); padding-bottom: var(--space-2); flex-wrap: wrap; gap: 6px;">
        <span style="color: var(--admin-muted);">Location & Landmark:</span>
        <div style="display: flex; align-items: center; gap: 8px;">
          <strong id="modalLocation" style="color: var(--admin-text);">--</strong>
          <button type="button" class="table-action-btn" id="toggleRequestMinimapBtn" onclick="toggleRequestMinimap()" style="padding: 2px 8px; font-size: 0.72rem; color: #352264; border-color: rgba(53, 34, 100, 0.25); background: #f6f3fc; display: inline-flex; align-items: center; gap: 4px;">
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
      <div id="requestMinimapCard" style="display: none; background: #faf8fc; border: 1.5px solid rgba(53, 34, 100, 0.15); border-radius: var(--radius-md); padding: var(--space-3); margin-top: -2px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: var(--fs-2xs);">
          <span style="font-weight: 700; color: #352264;" id="requestMinimapCoordsLabel">📍 17.6534° N, 121.7512° E</span>
          <span style="color: var(--admin-muted);">Emergency Pin Location</span>
        </div>
        <div id="adminRequestMinimap" style="height: 180px; width: 100%; border-radius: 8px; border: 1px solid rgba(0,0,0,0.1); z-index: 1;"></div>
      </div>

      <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--admin-border-subtle); padding-bottom: var(--space-2);">
        <span style="color: var(--admin-muted);">Status:</span>
        <span id="modalStatusBadge"><span class="status-pill pending">Pending</span></span>
      </div>
      <div>
        <span style="color: var(--admin-muted); display: block; margin-bottom: 4px;">Incident Notes & Description:</span>
        <div id="modalDescription" style="background: var(--admin-bg); padding: var(--space-3); border-radius: var(--radius-sm); color: var(--admin-text-secondary); line-height: 1.5;">
          --
        </div>
      </div>
    </div>

    <div style="padding: var(--space-4) var(--space-6); background: var(--admin-bg); border-top: 1px solid var(--admin-border); display: flex; justify-content: flex-end; gap: var(--space-3);" id="requestModalFooterActions">
      <!-- Populated dynamically based on request status -->
    </div>
  </dialog>

  <!-- Client Script for Search, Sidebar & Request Minimap -->
  <script src="assets/js/main.js"></script>
  <script>
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
          <button type="button" class="table-action-btn" style="border-color: var(--status-rejected-border, #f8c9d1); color: var(--status-rejected-text, #9c2438); background: #ffffff;" onmouseenter="this.style.background='#fdf0f2'" onmouseleave="this.style.background='#ffffff'" onclick="handleModalRejectRequest()">Reject Request</button>
          <button type="button" class="table-action-btn btn-filled" style="background: #4f46e5; color: #ffffff;" onclick="sendStatusUpdateToServer('approved', 'Request approved successfully.')">Approve</button>
        `;
      } else if (statusLower === 'approved') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn" style="border-color: var(--status-rejected-border, #f8c9d1); color: var(--status-rejected-text, #9c2438); background: #ffffff;" onmouseenter="this.style.background='#fdf0f2'" onmouseleave="this.style.background='#ffffff'" onclick="handleModalRejectRequest()">Reject Request</button>
          <button type="button" class="table-action-btn btn-filled" style="background: #4f46e5; color: #ffffff;" onclick="sendStatusUpdateToServer('in_progress', 'Relief aid assigned and marked In Progress.')">Assign Relief Aid</button>
        `;
      } else if (statusLower === 'in progress' || statusLower === 'in_progress') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled" style="background: #4f46e5; color: #ffffff;" onclick="sendStatusUpdateToServer('completed', 'Request marked as completed and delivered.')">Complete</button>
        `;
      } else if (statusLower === 'completed') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled" style="background: #4f46e5; color: #ffffff;" onclick="sendStatusUpdateToServer('archived', 'Request archived successfully.')">Archive</button>
        `;
      } else if (statusLower === 'rejected') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled" style="background: #4f46e5; color: #ffffff;" onclick="sendStatusUpdateToServer('pending', 'Request restored to Pending.')">Restore to Pending</button>
        `;
      } else if (statusLower === 'cancelled') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
        `;
      } else if (statusLower === 'archived') {
        footerEl.innerHTML = `
          <button type="button" class="table-action-btn" onclick="closeRequestModal()">Close</button>
          <button type="button" class="table-action-btn btn-filled" style="background: #4f46e5; color: #ffffff;" onclick="sendStatusUpdateToServer('completed', 'Request restored to completed.')">Restore to Completed</button>
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
                <div style="position: relative; cursor: pointer; transform: translate(-50%, -100%);">
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

    // Search and Filter logic
    document.addEventListener('DOMContentLoaded', () => {
      const searchInput = document.getElementById('requestSearchInput');
      const statusSelect = document.getElementById('statusFilterSelect');
      const tableRows = document.querySelectorAll('#requestsTableBody tr');
      const emptyState = document.getElementById('tableEmptyState');
      const paginationInfo = document.getElementById('paginationInfo');
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
          paginationInfo.textContent = `Showing 1 to ${visibleCount} of ${totalEntries} entries`;
        }
      }

      if (searchInput) searchInput.addEventListener('input', filterTable);
      if (statusSelect) statusSelect.addEventListener('change', filterTable);
    });
  </script>
</body>
</html>