<?php
/**
 * index.php — XPression Monitor Web Dashboard
 * Serves the single-page dashboard. The JS connects directly to the
 * Python WebSocket bridge at ws://<host>:8765.
 */

// WS bridge address — browser connects to this directly.
// If the dashboard is served from a different host than the bridge,
// set XPMON_WS_HOST to the bridge server's IP/hostname.
$ws_host = getenv('XPMON_WS_HOST') ?: $_SERVER['HTTP_HOST'];
$ws_host = preg_replace('/:\d+$/', '', $ws_host); // strip any port
$ws_url  = "ws://{$ws_host}:8765";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>XPression Monitor</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <span class="topbar-logo">XP<span class="accent">MON</span></span>
    <span class="topbar-subtitle">XPression Monitor Dashboard</span>
  </div>
  <div class="topbar-right">
    <span class="ws-status" id="wsStatus" title="WebSocket bridge connection">
      <span class="ws-dot"></span>
      <span class="ws-label">Connecting…</span>
    </span>
    <button class="btn btn-sm" id="btnAddHost">+ Add Host</button>
    <button class="btn btn-sm btn-secondary" id="btnImport">Import XCL</button>
  </div>
</header>

<main class="dashboard" id="dashboard">
  <div class="loading-splash" id="loadingSplash">
    <div class="spinner"></div>
    <p>Connecting to bridge…</p>
  </div>
</main>

<!-- Add Host Modal -->
<div class="modal-overlay" id="modalAddHost" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Add Host</h2>
      <button class="modal-close" data-modal="modalAddHost">✕</button>
    </div>
    <div class="modal-body">
      <label>Display Name
        <input type="text" id="addName" placeholder="NN Project Server Primary">
      </label>
      <label>IP Address
        <input type="text" id="addIp" placeholder="10.70.4.84">
      </label>
      <label>Port
        <input type="number" id="addPort" value="9875" min="1" max="65535">
      </label>
      <label>Group
        <input type="text" id="addGroup" placeholder="Ungrouped">
      </label>
    </div>
    <div class="modal-footer">
      <button class="btn" id="btnAddHostSubmit">Add Host</button>
      <button class="btn btn-secondary" data-modal="modalAddHost">Cancel</button>
    </div>
  </div>
</div>

<!-- Import XCL Modal -->
<div class="modal-overlay" id="modalImport" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Import XCL Configuration</h2>
      <button class="modal-close" data-modal="modalImport">✕</button>
    </div>
    <div class="modal-body">
      <p class="hint">Select a <code>StatusClientList.xcl</code> file exported from XPression Status Client. Hosts already tracked by IP will be skipped.</p>
      <label class="file-drop" id="xclDropZone">
        <input type="file" id="xclFile" accept=".xcl,.xml" hidden>
        <span id="xclDropLabel">Drop .xcl file here or click to browse</span>
      </label>
    </div>
    <div class="modal-footer">
      <button class="btn" id="btnImportSubmit" disabled>Import</button>
      <button class="btn btn-secondary" data-modal="modalImport">Cancel</button>
    </div>
  </div>
</div>

<!-- Alerts Config Modal -->
<div class="modal-overlay" id="modalAlerts" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Configure Alerts — <span id="alertsHostName"></span></h2>
      <button class="modal-close" data-modal="modalAlerts">✕</button>
    </div>
    <div class="modal-body">
      <p class="hint">Select which apps trigger an alert when they stop running. The host going offline always triggers an alert regardless of this setting.</p>
      <div class="alert-app-list" id="alertsAppList"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" id="btnAlertsTest">▶ Test Sound</button>
      <button class="btn" id="btnAlertsSave">Save</button>
      <button class="btn btn-secondary" data-modal="modalAlerts">Cancel</button>
    </div>
  </div>
</div>

<!-- Remove Host Confirm Modal -->
<div class="modal-overlay" id="modalRemove" hidden>
  <div class="modal modal-sm">
    <div class="modal-header">
      <h2>Remove Host</h2>
      <button class="modal-close" data-modal="modalRemove">✕</button>
    </div>
    <div class="modal-body">
      <p>Remove <strong id="removeHostName"></strong> from monitoring?</p>
      <p class="hint">This does not affect the XPression server itself.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger" id="btnRemoveConfirm">Remove</button>
      <button class="btn btn-secondary" data-modal="modalRemove">Cancel</button>
    </div>
  </div>
</div>

<script>
  // Pass PHP-generated WS URL to JS
  window.XPMON_WS_URL = <?= json_encode($ws_url) ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>
