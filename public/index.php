<?php
/**
 * index.php — XPression Monitor Web Dashboard
 */
$ws_host = getenv('XPMON_WS_HOST') ?: $_SERVER['HTTP_HOST'];
$ws_host = preg_replace('/:\d+$/', '', $ws_host);
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
    <button class="btn btn-sm btn-secondary" id="btnTheme" title="Toggle light/dark theme">🌙</button>
    <a href="xcl.php" class="btn btn-sm btn-secondary" title="Export host list as XCL">⬇ XCL</a>
    <a href="bridge.php" class="btn btn-sm btn-secondary">⚙ Bridge</a>
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

<!-- Host Command Confirmation Modal -->
<div class="modal-overlay" id="modalCommand" hidden>
  <div class="modal modal-sm">
    <div class="modal-header">
      <h2 id="cmdModalTitle">Confirm Action</h2>
      <button class="modal-close" data-modal="modalCommand">✕</button>
    </div>
    <div class="modal-body">
      <p id="cmdModalBody"></p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger" id="btnCmdConfirm">Confirm</button>
      <button class="btn btn-secondary" data-modal="modalCommand">Cancel</button>
    </div>
  </div>
</div>

<!-- Edit Host Modal -->
<div class="modal-overlay" id="modalEditHost" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Edit Host</h2>
      <button class="modal-close" data-modal="modalEditHost">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editHostId">
      <label>Display Name
        <input type="text" id="editName" placeholder="NN Project Server Primary">
      </label>
      <label>IP Address
        <input type="text" id="editIp" placeholder="10.70.4.84">
      </label>
      <label>Port
        <input type="number" id="editPort" value="9875" min="1" max="65535">
      </label>
      <label>Group
        <input type="text" id="editGroup" placeholder="Ungrouped">
      </label>
      <div class="edit-section-title">Alerts</div>
      <p class="hint">Configure which app failures trigger an alert sound and card flash.</p>
      <button class="btn btn-sm btn-alert" id="btnEditOpenAlerts">🔔 Configure Alerts</button>

      <div class="edit-section-title">WSS Canvas</div>
      <label class="checkbox-label">
        <input type="checkbox" id="editCanvasEnabled">
        <span>Enable Canvas Output / Preview links</span>
      </label>
      <label id="editCanvasPortRow">Canvas Port
        <input type="number" id="editCanvasPort" value="9056" min="1" max="65535">
      </label>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger btn-sm" id="btnEditRemoveHost" style="margin-right:auto">Remove Host</button>
      <button class="btn" id="btnEditHostSubmit">Save Changes</button>
      <button class="btn btn-secondary" data-modal="modalEditHost">Cancel</button>
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
  window.XPMON_WS_URL = <?= json_encode($ws_url) ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>