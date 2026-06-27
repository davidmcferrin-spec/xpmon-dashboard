<?php
/**
 * bridge.php — XPression Monitor Bridge Admin
 * Live log tail, service start/stop/restart, and status indicator.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bridge Admin — XPression Monitor</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    /* ---- Page-specific overrides ---- */
    .bridge-page {
      margin-top: var(--topbar-h);
      padding: 24px 20px;
      max-width: 1100px;
      margin-left: auto;
      margin-right: auto;
    }

    .bridge-header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }

    .bridge-title {
      font-size: 18px;
      font-weight: 600;
      color: var(--text-primary);
      flex: 1;
    }

    .service-status {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      padding: 6px 14px;
      border-radius: var(--radius-sm);
      border: 1px solid var(--border-bright);
      background: var(--bg-card);
    }
    .service-dot {
      width: 9px; height: 9px;
      border-radius: 50%;
      background: var(--gray);
      flex-shrink: 0;
    }
    .service-status.active   .service-dot { background: var(--green); box-shadow: 0 0 5px var(--green); }
    .service-status.inactive .service-dot { background: var(--red); }
    .service-status.active   .service-label { color: var(--green); }
    .service-status.inactive .service-label { color: var(--red); }

    .bridge-controls {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    /* ---- Log panel ---- */
    .log-panel {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
    }

    .log-toolbar {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      border-bottom: 1px solid var(--border);
      background: var(--bg-header);
    }

    .log-toolbar-title {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: var(--text-muted);
      flex: 1;
    }

    .log-lines-select {
      background: var(--bg-card);
      border: 1px solid var(--border-bright);
      border-radius: var(--radius-sm);
      color: var(--text-primary);
      font-size: 12px;
      font-family: var(--font);
      padding: 3px 8px;
      cursor: pointer;
    }

    .log-autoscroll-label {
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: 12px;
      color: var(--text-secondary);
      cursor: pointer;
      user-select: none;
    }
    .log-autoscroll-label input { accent-color: var(--accent); cursor: pointer; }

    .log-pause-btn {
      font-size: 11px;
      padding: 3px 10px;
    }

    .log-output {
      font-family: var(--font-mono);
      font-size: 12px;
      line-height: 1.6;
      color: var(--log-text, #c9d1d9);
      background: var(--log-bg, #0d1117);
      padding: 12px 14px;
      height: 520px;
      overflow-y: auto;
      white-space: pre-wrap;
      word-break: break-all;
    }
    .log-output::-webkit-scrollbar { width: 6px; }
    .log-output::-webkit-scrollbar-track { background: transparent; }
    .log-output::-webkit-scrollbar-thumb { background: var(--border-bright); border-radius: 3px; }

    /* Log line coloring */
    .log-line { display: block; }
    .log-line.err  { color: #ff7b72; }
    .log-line.warn { color: #e3b341; }
    .log-line.info { color: var(--log-text, #c9d1d9); }
    .log-line.conn { color: #79c0ff; }

    .log-status-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 14px;
      border-top: 1px solid var(--border);
      font-size: 11px;
      color: var(--text-muted);
      background: var(--bg-header);
    }
    .log-last-update { margin-left: auto; }

    /* ---- Confirm modal override ---- */
    .modal-body p { color: var(--text-primary); line-height: 1.6; }
  </style>
<script>
  // Apply saved theme before first paint to avoid flash
  (function() {
    var t = localStorage.getItem('xpmon-theme') || 'dark';
    document.documentElement.setAttribute('data-theme', t);
  })();
</script>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <span class="topbar-logo"><a href="index.php" style="text-decoration:none;color:inherit">XP<span class="accent">MON</span></a></span>
    <nav style="display:flex;gap:4px;">
      <a href="index.php" class="btn btn-sm btn-secondary">← Dashboard</a>
      <span class="btn btn-sm" style="cursor:default;opacity:0.6">Bridge Admin</span>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="service-status" id="serviceStatus">
      <span class="service-dot"></span>
      <span class="service-label">Checking…</span>
    </div>
  </div>
</header>

<div class="bridge-page">
  <div class="bridge-header">
    <div class="bridge-title">xpmon-bridge Service</div>
    <div class="bridge-controls">
      <button class="btn btn-success" id="btnStart"   data-action="start">▶ Start</button>
      <button class="btn btn-warning" id="btnStop"    data-action="stop">■ Stop</button>
      <button class="btn btn-secondary" id="btnRestart" data-action="restart">⟳ Restart</button>
    </div>
  </div>

  <div class="log-panel">
    <div class="log-toolbar">
      <span class="log-toolbar-title">Journal Log — xpmon-bridge</span>
      <label class="log-autoscroll-label">
        <input type="checkbox" id="chkAutoscroll" checked> Auto-scroll
      </label>
      <select class="log-lines-select" id="selLines">
        <option value="50">50 lines</option>
        <option value="100" selected>100 lines</option>
        <option value="200">200 lines</option>
        <option value="500">500 lines</option>
      </select>
      <button class="btn btn-sm btn-secondary log-pause-btn" id="btnPause">⏸ Pause</button>
    </div>
    <div class="log-output" id="logOutput"><span style="color:var(--text-muted)">Loading log…</span></div>
    <div class="log-status-bar">
      <span id="logLineCount">—</span> lines
      <span class="log-last-update">Updated: <span id="logLastUpdate">—</span></span>
    </div>
  </div>
</div>

<!-- Service command confirm modal -->
<div class="modal-overlay" id="modalServiceCmd" hidden>
  <div class="modal modal-sm">
    <div class="modal-header">
      <h2 id="svcCmdTitle">Confirm</h2>
      <button class="modal-close" data-modal="modalServiceCmd">✕</button>
    </div>
    <div class="modal-body">
      <p id="svcCmdBody"></p>
    </div>
    <div class="modal-footer">
      <button class="btn" id="btnSvcCmdConfirm">Confirm</button>
      <button class="btn btn-secondary" data-modal="modalServiceCmd">Cancel</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
'use strict';

const LOG_POLL_MS = 2000;
let pollTimer     = null;
let paused        = false;
let pendingAction = null;

// ---- Service status ----

async function fetchStatus() {
  try {
    const r = await fetch('log.php?action=status');
    const d = await r.json();
    const el = document.getElementById('serviceStatus');
    el.className = 'service-status ' + (d.active ? 'active' : 'inactive');
    el.querySelector('.service-label').textContent = d.active ? 'Running' : d.state;
  } catch (e) {
    console.warn('Status fetch failed:', e);
  }
}

// ---- Log polling ----

async function fetchLog() {
  if (paused) return;
  const lines = document.getElementById('selLines').value;
  try {
    const r = await fetch(`log.php?action=log&lines=${lines}`);
    const d = await r.json();
    if (!d.ok) return;

    const out    = document.getElementById('logOutput');
    const scroll = document.getElementById('chkAutoscroll').checked;

    out.innerHTML = d.lines.map(line => {
      const esc  = line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      let cls    = 'info';
      if (/\[ERROR\]|\[CRITICAL\]/i.test(line))   cls = 'err';
      else if (/\[WARNING\]/i.test(line))          cls = 'warn';
      else if (/connect|reconnect|WebSocket/i.test(line)) cls = 'conn';
      return `<span class="log-line ${cls}">${esc}</span>`;
    }).join('\n');

    document.getElementById('logLineCount').textContent = d.lines.length;
    document.getElementById('logLastUpdate').textContent = new Date().toLocaleTimeString();

    if (scroll) out.scrollTop = out.scrollHeight;
  } catch (e) {
    console.warn('Log fetch failed:', e);
  }
}

function startPolling() {
  fetchLog();
  fetchStatus();
  pollTimer = setInterval(() => { fetchLog(); fetchStatus(); }, LOG_POLL_MS);
}

// ---- Controls ----

document.getElementById('btnPause').addEventListener('click', () => {
  paused = !paused;
  document.getElementById('btnPause').textContent = paused ? '▶ Resume' : '⏸ Pause';
});

document.getElementById('selLines').addEventListener('change', fetchLog);

// Service action buttons
['btnStart','btnStop','btnRestart'].forEach(id => {
  document.getElementById(id).addEventListener('click', () => {
    const action = document.getElementById(id).dataset.action;
    openServiceModal(action);
  });
});

function openServiceModal(action) {
  pendingAction = action;
  const labels = {
    start:   { title: 'Start Bridge',   body: 'Start the <strong>xpmon-bridge</strong> service?', cls: 'btn btn-success' },
    stop:    { title: 'Stop Bridge',    body: 'Stop the <strong>xpmon-bridge</strong> service? All dashboard connections will drop.', cls: 'btn btn-warning' },
    restart: { title: 'Restart Bridge', body: 'Restart the <strong>xpmon-bridge</strong> service? Dashboard will reconnect automatically after a few seconds.', cls: 'btn btn-secondary' },
  };
  const m = labels[action];
  document.getElementById('svcCmdTitle').textContent    = m.title;
  document.getElementById('svcCmdBody').innerHTML       = m.body;
  document.getElementById('btnSvcCmdConfirm').className = m.cls;
  document.getElementById('btnSvcCmdConfirm').textContent = m.title;
  document.getElementById('modalServiceCmd').removeAttribute('hidden');
}

document.getElementById('btnSvcCmdConfirm').addEventListener('click', async () => {
  document.getElementById('modalServiceCmd').setAttribute('hidden','');
  if (!pendingAction) return;
  const action = pendingAction;
  pendingAction = null;

  try {
    const r = await fetch(`log.php?action=${action}`);
    const d = await r.json();
    if (d.ok) {
      toast('success', `Bridge ${action} command sent`);
      setTimeout(fetchStatus, 1500);
      setTimeout(fetchLog, 2000);
    } else {
      toast('error', `Command failed: ${d.output || 'unknown error'}`);
    }
  } catch(e) {
    toast('error', `Request failed: ${e.message}`);
  }
});

// ---- Modal close ----
document.querySelectorAll('[data-modal]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById(btn.dataset.modal).setAttribute('hidden','');
  });
});
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => {
    if (e.target === overlay) overlay.setAttribute('hidden','');
  });
});

// ---- Toast ----
function toast(type, message, duration = 4000) {
  const container = document.getElementById('toastContainer');
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = message;
  container.appendChild(el);
  setTimeout(() => el.remove(), duration);
}

// ---- Boot ----
startPolling();
</script>
</body>
</html>