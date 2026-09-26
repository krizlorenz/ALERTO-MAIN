<?php
session_start();
require_once 'alerto-db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'admin', 'superadmin'])) {
    header("Location: user-login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Check for any existing active request for this user (ignoring completed, archived, cancelled, or rejected)
$stmtActive = $pdo->prepare("
    SELECT * FROM assistance_requests 
    WHERE user_id = ? AND LOWER(status) NOT IN ('completed', 'archived', 'cancelled', 'rejected') 
    ORDER BY id DESC LIMIT 1
");
$stmtActive->execute([$user_id]);
$activeRequest = $stmtActive->fetch(PDO::FETCH_ASSOC);

// 3. Handle Form Submission (Insert, Update, or Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'cancel_request') {
        if ($activeRequest) {
            $currentStatus = strtolower(trim($activeRequest['status'] ?? 'pending'));
            if ($currentStatus === 'pending' || $currentStatus === 'approved') {
                $stmt = $pdo->prepare("UPDATE assistance_requests SET status = 'cancelled' WHERE id = ? AND user_id = ?");
                $stmt->execute([$activeRequest['id'], $user_id]);
                header("Location: user-homepage.php?cancelled=1");
                exit;
            } else {
                $error = "Requests in progress or completed cannot be cancelled.";
            }
        } else {
            $error = "No active request found to cancel.";
        }
    } else {
        $assistance_type = trim($_POST['assistance_type'] ?? '');
        $landmark        = trim($_POST['location'] ?? '');
        $latitude        = trim($_POST['latitude'] ?? '');
        $longitude       = trim($_POST['longitude'] ?? '');
        $description     = trim($_POST['remarks'] ?? '');

        if (empty($assistance_type) || empty($landmark)) {
            $error = "Please fill in all required request details.";
        } else {
            if ($activeRequest) {
                $currentStatus = strtolower(trim($activeRequest['status'] ?? 'pending'));
                
                // Allow modifications while pending or approved (case-insensitive)
                if ($currentStatus === 'pending' || $currentStatus === 'approved') {
                    $stmt = $pdo->prepare("
                        UPDATE assistance_requests 
                        SET resources = ?, landmark = ?, latitude = ?, longitude = ?, description = ? 
                        WHERE id = ?
                    ");
                    if ($stmt->execute([$assistance_type, $landmark, $latitude, $longitude, $description, $activeRequest['id']])) {
                        header("Location: user-homepage.php?updated=1");
                        exit;
                    } else {
                        $error = "Failed to update request. Please try again.";
                    }
                } else {
                    $error = "Your request is currently " . ucfirst($activeRequest['status']) . ". Modifications are locked once dispatch operations are underway.";
                }
            } else {
                // Insert brand new request, including the required request_code
                $requestCode = 'REQ-' . rand(10000, 99999);
                
                $stmt = $pdo->prepare("
                    INSERT INTO assistance_requests (request_code, user_id, resources, landmark, latitude, longitude, description, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                if ($stmt->execute([$requestCode, $_SESSION['user_id'], $assistance_type, $landmark, $latitude, $longitude, $description])) {
                    header("Location: user-homepage.php?requested=1");
                    exit;
                } else {
                    $error = "Failed to submit request. Please try again.";
                }
            }
        }
    }
}

// Pre-fill coordinates if an active request exists
$currentLat = $activeRequest['latitude'] ?? '17.6534';
$currentLng = $activeRequest['longitude'] ?? '121.7512';
$currentType = $activeRequest['resources'] ?? 'Food, Water';
$activeStatusLower = strtolower(trim($activeRequest['status'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $activeRequest ? 'Update Assistance Request' : 'Assistance Request' ?> | ALERTO - CSU-Carig Student Council</title>

  <!-- Google Fonts: Poppins & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap"
    rel="stylesheet">

  <!-- Interactive Map Engine (Supports Google Maps Tiles & Live Pinning) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

  <!-- Core Stylesheets -->
  <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/student.css?v=<?= time() ?>">
</head>

<body>

  <main class="request-page-wrapper">

    <!-- Centered Assistance Request Card Container (Crisp White Theme) -->
    <div class="request-card-container">

      <!-- Card Top Crisp White Header -->
      <div class="request-header">
        <div class="auth-header-top-row">
          <a href="user-homepage.php" class="header-back-link">
            &larr; <span>Back to Dashboard</span>
          </a>
          <span class="auth-badge-terminal">CSU-CARIG • COEA</span>
        </div>

        <div class="request-title-box" style="display: flex; justify-content: space-between; align-items: flex-start;">
          <div>
            <h1 class="request-main-title"><?= $activeRequest ? 'Update Assistance Request' : 'Assistance Request' ?></h1>
            <p class="request-sub-title">CSU-COEA Disaster Relief & Emergency Response Coordination</p>
          </div>
          <?php if ($activeRequest): ?>
            <span style="background: #fef3c7; color: #d97706; padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">
              Status: <?= htmlspecialchars($activeRequest['status']) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Assistance Request Alerts -->
      <?php if (!empty($error)): ?>
        <div class="alert-banner alert-error" role="alert" style="display: flex; align-items: flex-start; gap: 10px; margin: 20px 28px 0; padding: 12px 14px; border-radius: var(--radius-sm, 8px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: var(--status-rejected-bg, #fdf0f2); color: var(--status-rejected-text, #9c2438); border: 1.5px solid var(--status-rejected-border, #f8c9d1); line-height: 1.45;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px; flex-shrink: 0; color: #b82b43; margin-top: 1px;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($activeRequest && in_array($activeStatusLower, ['in_progress', 'completed', 'cancelled'])): ?>
        <div class="alert-banner alert-info" role="alert" style="display: flex; align-items: flex-start; gap: 10px; margin: 20px 28px 0; padding: 12px 14px; border-radius: var(--radius-sm, 8px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: #f0f4ff; color: #1e40af; border: 1.5px solid #bfdbfe; line-height: 1.45;">
          <div>Your request status is currently <strong><?= ucfirst($activeRequest['status']) ?></strong>. Online modifications are locked once dispatch operations are underway.</div>
        </div>
      <?php else: ?>
        <?php if ($activeRequest): ?>
          <div class="alert-banner alert-info" role="alert" style="display: flex; align-items: flex-start; gap: 10px; margin: 20px 28px 0; padding: 12px 14px; border-radius: var(--radius-sm, 8px); font-size: var(--fs-xs, 0.8125rem); font-weight: 500; background-color: #f0f4ff; color: #1e40af; border: 1.5px solid #bfdbfe; line-height: 1.45;">
            <div>You have an active request. You can update your requirements below while processing is underway.</div>
          </div>
        <?php endif; ?>

        <!-- Assistance Request Form Body -->
        <form class="request-form-body" id="assistanceRequestForm" action="user-request.php" method="POST">

          <!-- Hidden input for combined assistance_type -->
          <input type="hidden" name="assistance_type" id="assistanceTypeInput" value="<?= htmlspecialchars($currentType) ?>">
          <!-- Hidden inputs for coordinates -->
          <input type="hidden" name="latitude" id="latitudeInput" value="<?= htmlspecialchars($currentLat) ?>">
          <input type="hidden" name="longitude" id="longitudeInput" value="<?= htmlspecialchars($currentLng) ?>">

          <!-- Resources Requested -->
          <div class="form-field-group">
            <label class="form-label">
              <span>Request Resources (Select all that apply).</span>
            </label>

            <div class="resource-cards-list">

              <!-- 1. Food -->
              <label class="resource-row-card" id="cardFood">
                <input type="checkbox" name="resources" value="Food" id="chkFood"
                  onchange="toggleRowCard('cardFood', this)">
                <span class="resource-label-text">Food</span>
              </label>

              <!-- 2. Water -->
              <label class="resource-row-card" id="cardWater">
                <input type="checkbox" name="resources" value="Water" id="chkWater"
                  onchange="toggleRowCard('cardWater', this)">
                <span class="resource-label-text">Water</span>
              </label>

              <!-- 3. Medicine -->
              <label class="resource-row-card" id="cardMedicine">
                <input type="checkbox" name="resources" value="Medicine" id="chkMedicine"
                  onchange="toggleRowCard('cardMedicine', this)">
                <span class="resource-label-text">Medicine</span>
              </label>

              <!-- 4. Transportation -->
              <label class="resource-row-card" id="cardTransport">
                <input type="checkbox" name="resources" value="Transportation" id="chkTransport"
                  onchange="toggleRowCard('cardTransport', this)">
                <span class="resource-label-text">Transportation</span>
              </label>

              <!-- 5. Others -->
              <div class="resource-row-card others-row-card" id="cardOthers">
                <label class="others-check-label">
                  <input type="checkbox" name="resources" value="Others" id="chkOthers"
                    onchange="toggleRowCard('cardOthers', this)">
                  <span class="resource-label-text">Others (specify):</span>
                </label>
                <input type="text" id="othersSpecifyText" class="others-inline-input"
                  placeholder="Specify items (e.g. hygiene kit, blankets)" oninput="updateAssistanceType()">
              </div>

            </div>
          </div>

          <!-- Map Location -->
          <div class="form-field-group">
            <label class="form-label">
              <span>One-Time Map Pin Location</span>
              <span class="form-label-tag">Click / Drag Pin on Google Map</span>
            </label>

            <div class="google-map-widget-card">

              <!-- Map Control Header -->
              <div class="google-map-control-bar">
                <div class="map-location-badge">
                  <span id="googleCoordsText">📍 <?= htmlspecialchars($currentLat) ?>° N, <?= htmlspecialchars($currentLng) ?>° E</span>
                </div>
                <button type="button" class="gps-locate-btn" onclick="useCurrentGoogleGPS()">
                  <img src="icons/crosshair.svg" alt="" style="width:12px;height:12px;"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    style="display:none;width:12px;height:12px;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="22" y1="12" x2="18" y2="12"></line>
                    <line x1="6" y1="12" x2="2" y2="12"></line>
                    <line x1="12" y1="6" x2="12" y2="2"></line>
                    <line x1="12" y1="22" x2="12" y2="18"></line>
                  </svg>
                  <span>Use My GPS Pin</span>
                </button>
              </div>

              <!-- Interactive Google Map Viewport with Draggable Pin -->
              <div id="googleMapInteractive" class="google-map-frame-wrapper"></div>

              <!-- Enlarged Landmark & Specific Address Input -->
              <div class="landmark-large-container">
                <label for="locationLandmark" class="landmark-large-label">Specific Landmark / House Description / Street Address</label>
                <input type="text" id="locationLandmark" name="location" class="landmark-large-input"
                  placeholder="e.g. Yellow 2-Story House, Purok 3, Near CSU Carig Gate 2, Carig Sur" 
                  value="<?= htmlspecialchars($activeRequest['landmark'] ?? '') ?>" required>
              </div>

              <div class="map-pin-note">
                <img src="icons/info.svg" alt="" style="width:13px;height:13px;"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  style="display:none;width:13px;height:13px;">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="16" x2="12" y2="12"></line>
                  <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <span>Click anywhere on the map or drag the red pin directly to your exact stranded location.</span>
              </div>

            </div>
          </div>

          <!-- Emergency Message -->
          <div class="form-field-group">
            <label for="emergencyMessage" class="form-label">
              <span>Emergency Message Explanation</span>
            </label>

            <textarea id="emergencyMessage" name="remarks" class="emergency-textarea"
              placeholder="Explain your current emergency situation, immediate needs, or condition..."
              required><?= htmlspecialchars($activeRequest['description'] ?? '') ?></textarea>
          </div>

          <!-- Quick Fill Sample Request Button -->
          <div class="auth-extras-row">
            <span style="font-size: var(--fs-2xs); color: var(--admin-muted);">All information is transmitted directly to COEA DRRM Council.</span>
            <button type="button" class="quick-fill-btn" onclick="quickFillAssistanceRequest()">
              <img src="icons/bolt.svg" alt="" style="width:12px;height:12px;"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                style="display:none;">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
              </svg>
              <span>Fill Sample Request</span>
            </button>
          </div>

          <!-- Submit & Cancel Buttons -->
          <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: var(--space-2);">
            <button type="submit" class="request-submit-btn" id="assistanceSubmitBtn" style="flex: 1; min-width: 200px; margin-top: 0;">
              <span><?= $activeRequest ? 'Save Request Updates' : 'Submit Assistance Request' ?></span>
            </button>
            <?php if ($activeRequest && in_array($activeStatusLower, ['pending', 'approved'])): ?>
              <button type="button" class="request-cancel-btn" id="assistanceCancelBtn" onclick="handleUserCancelRequest()" style="flex: 0 1 auto; min-width: 160px; margin-top: 0;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="15" y1="9" x2="9" y2="15"></line>
                  <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
                <span>Cancel Request</span>
              </button>
            <?php endif; ?>
          </div>

        </form>
      <?php endif; ?>

      <!-- Security Protocol Footer -->
      <div
        style="padding: 14px var(--space-8) var(--space-6); background: #ffffff; border-top: 1px solid rgba(112, 13, 35, 0.08); text-align: center;">
        <div class="security-badge" style="justify-content: center;">
          <img src="icons/shield-check.svg" alt="" style="width:14px;height:14px;"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            style="display:none;">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          <span>COEA-SC DRRM Operations Security Standard</span>
        </div>
      </div>

    </div>

  </main>

  <!-- Interactive Map Library with Google Tiles -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

  <script>
    // Update Hidden assistance_type input from checked resources
    function updateAssistanceType() {
      const selected = document.querySelectorAll('input[name="resources"]:checked');
      const types = [];
      selected.forEach(cb => {
        if (cb.value === 'Others') {
          const specify = document.getElementById('othersSpecifyText').value.trim();
          types.push(specify ? 'Others: ' + specify : 'Others');
        } else {
          types.push(cb.value);
        }
      });
      document.getElementById('assistanceTypeInput').value = types.join(', ');
    }

    // Toggle Selected State on Card
    function toggleRowCard(cardId, checkbox) {
      const card = document.getElementById(cardId);
      if (checkbox.checked) {
        card.classList.add('selected');
      } else {
        card.classList.remove('selected');
      }
      updateAssistanceType();
    }

    // 1. Interactive Google Map with Click & Drag Pin Drop
    const initialLat = <?= json_encode(floatval($currentLat)) ?>;
    const initialLng = <?= json_encode(floatval($currentLng)) ?>;
    let gMap, gMarker;

    document.addEventListener('DOMContentLoaded', () => {
      // Initialize map centered on stored coordinates or CSU Carig
      gMap = L.map('googleMapInteractive').setView([initialLat, initialLng], 15);

      // Official Google Maps Standard Roadmap Tiles
      L.tileLayer('https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
        attribution: '&copy; Google Maps',
        maxZoom: 20
      }).addTo(gMap);

      // Authentic Google Maps Red Droplet Pin
      const googleRedPinIcon = L.divIcon({
        className: 'google-red-pin-drop',
        html: `
          <div style="position:relative; width:36px; height:48px; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.35));">
            <svg viewBox="0 0 24 32" style="width:36px; height:48px; display:block;">
              <path fill="#EA4335" stroke="#B31412" stroke-width="0.75" d="M12 0C5.37 0 0 5.37 0 12c0 9 12 20 12 20s12-11 12-20c0-6.63-5.37-12-12-12z"/>
              <circle cx="12" cy="11" r="4.5" fill="#700D23"/>
            </svg>
          </div>
        `,
        iconSize: [36, 48],
        iconAnchor: [18, 46]
      });

      // Add Draggable Red Pin
      gMarker = L.marker([initialLat, initialLng], {
        draggable: true,
        icon: googleRedPinIcon
      }).addTo(gMap);

      // Update coordinates on Pin Drag
      gMarker.on('dragend', function (e) {
        const pos = gMarker.getLatLng();
        updatePinnedLocation(pos.lat, pos.lng);
      });

      // Drop pin immediately wherever the student clicks on the map
      gMap.on('click', function (e) {
        gMarker.setLatLng(e.latlng);
        updatePinnedLocation(e.latlng.lat, e.latlng.lng);
      });
    });

    function updatePinnedLocation(lat, lng) {
      document.getElementById('googleCoordsText').textContent = `📍 ${lat.toFixed(4)}° N, ${lng.toFixed(4)}° E`;
      document.getElementById('latitudeInput').value = lat;
      document.getElementById('longitudeInput').value = lng;
    }

    // GPS Geolocation Handler
    function useCurrentGoogleGPS() {
      if (navigator.geolocation) {
        const btn = document.querySelector('.gps-locate-btn');
        btn.textContent = 'Locating GPS...';

        navigator.geolocation.getCurrentPosition(
          (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            gMap.setView([lat, lng], 17);
            gMarker.setLatLng([lat, lng]);
            updatePinnedLocation(lat, lng);
            btn.innerHTML = '<span>GPS Pin Set &check;</span>';
          },
          (error) => {
            alert('Unable to access device GPS. You can click or drag the red pin anywhere on the map to pin your location.');
            btn.innerHTML = '<span>Use My GPS Pin</span>';
          },
          { enableHighAccuracy: true, timeout: 10000 }
        );
      } else {
        alert('Geolocation is not supported by your browser.');
      }
    }

    // Quick Fill Sample Request Form
    function quickFillAssistanceRequest() {
      document.getElementById('chkFood').checked = true;
      document.getElementById('cardFood').classList.add('selected');

      document.getElementById('chkWater').checked = true;
      document.getElementById('cardWater').classList.add('selected');

      document.getElementById('locationLandmark').value = 'Yellow 2-Story House, Purok 3 (Across CSU Carig Gate 2, Carig Sur)';
      document.getElementById('emergencyMessage').value = 'Floodwaters rising above knee level. 4 students stranded on upper floor with limited clean drinking water.';

      updateAssistanceType();
    }

    // Cancel Active Request confirmation and submission
    function handleUserCancelRequest() {
      if (confirm('Are you sure you want to cancel this assistance request? Once cancelled, emergency responders will not be dispatched.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'user-request.php';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'cancel_request';
        form.appendChild(actionInput);

        document.body.appendChild(form);
        form.submit();
      }
    }

    // Validate resource selection before native submit
    document.getElementById('assistanceRequestForm')?.addEventListener('submit', function (event) {
      updateAssistanceType();
      const typeVal = document.getElementById('assistanceTypeInput').value.trim();
      if (!typeVal) {
        event.preventDefault();
        alert('Please select at least one Resource Requested (Food, Water, Medicine, Transportation, or Others).');
      }
    });
  </script>
</body>

</html>