/**
 * app.js — XPression Monitor Dashboard
 * WebSocket client + UI rendering. No framework dependencies.
 *
 * Alert system:
 *   - Per-host critical app list stored in config (managed via bridge WS)
 *   - Triggers: host goes offline, OR a critical app flips to status != 2
 *   - Effect: card flashes red, alert sound plays once
 *   - Resets automatically when condition clears
 *   - Snapshot on connect does NOT trigger alerts (avoids false alarms on page load)
 */

'use strict';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/** @type {Map<string, Object>} host id → host state */
const hosts = new Map();

/**
 * Alert tracking — keys are "host:<id>" or "app:<hostId>:<appKey>"
 * Value is true while the condition is active.
 * Prevents re-firing the sound on every update while the fault persists.
 */
const activeAlerts = new Map();

/** Set to true after first snapshot — suppresses alerts on initial load */
let initialLoadDone = false;

let ws = null;
let wsReconnectTimer = null;
let wsReconnectDelay = 2000;
let pendingRemoveId = null;
let xclFileContent = null;
let alertsTargetHostId = null; // host being configured in alerts modal
let editTargetHostId   = null; // host being edited
let pendingCommand     = null; // { id, name, command }

// ---------------------------------------------------------------------------
// Audio
// ---------------------------------------------------------------------------

let audioCtx = null;

function getAudioContext() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  return audioCtx;
}

/**
 * Play the alert sound. Uses Web Audio to load and decode the MP3 so it
 * works without user gesture after the first interaction unlocks the context.
 * Falls back silently if audio is unavailable.
 */
let alertAudioBuffer = null;
let alertAudioLoading = false;

async function loadAlertSound() {
  if (alertAudioBuffer || alertAudioLoading) return;
  alertAudioLoading = true;
  try {
    const ctx = getAudioContext();
    const resp = await fetch('assets/alert.mp3');
    const arrayBuf = await resp.arrayBuffer();
    alertAudioBuffer = await ctx.decodeAudioData(arrayBuf);
  } catch (e) {
    console.warn('Alert sound load failed:', e);
  } finally {
    alertAudioLoading = false;
  }
}

function playAlertSound() {
  try {
    const ctx = getAudioContext();
    if (ctx.state === 'suspended') ctx.resume();
    if (!alertAudioBuffer) { loadAlertSound(); return; }
    const src = ctx.createBufferSource();
    src.buffer = alertAudioBuffer;
    src.connect(ctx.destination);
    src.start(0);
  } catch (e) {
    console.warn('Alert sound play failed:', e);
  }
}

// Unlock AudioContext on first user interaction (browser autoplay policy)
document.addEventListener('click', () => {
  loadAlertSound();
  if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
}, { once: true });

// ---------------------------------------------------------------------------
// Alert logic
// ---------------------------------------------------------------------------

/**
 * Evaluate a host's state for alert conditions.
 * Fires sound + flash on new faults. Clears flash when fault resolves.
 * Does nothing during initial snapshot load.
 */
function evaluateAlerts(host, previousHost) {
  if (!initialLoadDone) return;

  const criticalApps = new Set(host.critical_apps || []);

  // --- Host offline ---
  const hostKey = `host:${host.id}`;
  if (!host.connected) {
    if (!activeAlerts.has(hostKey)) {
      activeAlerts.set(hostKey, true);
      triggerAlert(host.id, `${host.display_name} went OFFLINE`);
    }
  } else {
    if (activeAlerts.has(hostKey)) {
      activeAlerts.delete(hostKey);
      clearFlash(host.id);
    }
  }

  // --- Critical app stopped ---
  if (host.connected && criticalApps.size > 0) {
    for (const app of (host.apps || [])) {
      if (!criticalApps.has(app.key)) continue;
      const appKey = `app:${host.id}:${app.key}`;
      const isFaulted = app.status !== 2 && !app.ignore_status;
      if (isFaulted) {
        if (!activeAlerts.has(appKey)) {
          activeAlerts.set(appKey, true);
          triggerAlert(host.id, `${app.name} stopped on ${host.display_name}`);
        }
      } else {
        if (activeAlerts.has(appKey)) {
          activeAlerts.delete(appKey);
          // Only clear flash if no other alerts remain for this host
          if (!hostHasActiveAlert(host.id)) clearFlash(host.id);
        }
      }
    }
  }
}

function hostHasActiveAlert(hostId) {
  for (const [key] of activeAlerts) {
    if (key.startsWith(`host:${hostId}`) || key.startsWith(`app:${hostId}:`)) {
      return true;
    }
  }
  return false;
}

function triggerAlert(hostId, message) {
  playAlertSound();
  flashCard(hostId);
  toast('error', `⚠ ${message}`, 8000);
  console.warn(`[ALERT] ${message}`);
}

function flashCard(hostId) {
  const card = document.getElementById(`host-${hostId}`);
  if (!card) return;
  card.classList.add('alert-flash');
}

function clearFlash(hostId) {
  const card = document.getElementById(`host-${hostId}`);
  if (!card) return;
  card.classList.remove('alert-flash');
}

// ---------------------------------------------------------------------------
// WebSocket connection
// ---------------------------------------------------------------------------

function wsConnect() {
  const url = window.XPMON_WS_URL;
  ws = new WebSocket(url);

  ws.addEventListener('open', () => {
    setWsStatus('connected', 'Connected');
    wsReconnectDelay = 2000;
    clearTimeout(wsReconnectTimer);
  });

  ws.addEventListener('close', () => {
    setWsStatus('disconnected', 'Disconnected');
    scheduleReconnect();
  });

  ws.addEventListener('error', () => {
    setWsStatus('disconnected', 'Error');
  });

  ws.addEventListener('message', (ev) => {
    try {
      handleMessage(JSON.parse(ev.data));
    } catch (e) {
      console.error('WS parse error:', e);
    }
  });
}

function scheduleReconnect() {
  clearTimeout(wsReconnectTimer);
  wsReconnectTimer = setTimeout(() => {
    console.log(`Reconnecting in ${wsReconnectDelay}ms…`);
    wsConnect();
    wsReconnectDelay = Math.min(wsReconnectDelay * 1.5, 30000);
  }, wsReconnectDelay);
}

function wsSend(obj) {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify(obj));
  }
}

// ---------------------------------------------------------------------------
// Message handlers
// ---------------------------------------------------------------------------

function handleMessage(msg) {
  switch (msg.type) {
    case 'snapshot':
      hosts.clear();
      activeAlerts.clear();
      msg.hosts.forEach(h => hosts.set(h.id, h));
      renderAll();
      hideSplash();
      // Mark load done after a short delay so any immediate updates
      // that arrive right after snapshot don't false-alarm
      setTimeout(() => { initialLoadDone = true; }, 2000);
      break;

    case 'host_update': {
      const prev = hosts.get(msg.host.id);
      hosts.set(msg.host.id, msg.host);
      renderHost(msg.host);
      evaluateAlerts(msg.host, prev);
      break;
    }

    case 'host_added':
      hosts.set(msg.host.id, msg.host);
      renderHost(msg.host);
      toast('info', `Host added: ${msg.host.display_name}`);
      break;

    case 'host_removed':
      hosts.delete(msg.id);
      // Clear any alerts for this host
      for (const key of [...activeAlerts.keys()]) {
        if (key.includes(msg.id)) activeAlerts.delete(key);
      }
      const card = document.getElementById(`host-${msg.id}`);
      if (card) card.remove();
      cleanEmptyGroups();
      break;

    case 'import_result':
      toast('success', `Imported ${msg.added} host(s) from XCL file`);
      closeModal('modalImport');
      break;

    case 'host_updated':
      hosts.set(msg.host.id, msg.host);
      renderHost(msg.host);
      toast('success', `Host updated: ${msg.host.display_name}`);
      break;

    case 'command_result':
      handleCommandResult(msg);
      break;

    case 'alerts_updated':
      // Bridge confirmed critical_apps saved — update local state
      if (hosts.has(msg.id)) {
        hosts.get(msg.id).critical_apps = msg.critical_apps;
      }
      toast('success', 'Alert configuration saved');
      break;
  }
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

function hideSplash() {
  const splash = document.getElementById('loadingSplash');
  if (splash) splash.remove();
}

function renderAll() {
  const dashboard = document.getElementById('dashboard');

  if (hosts.size === 0) {
    dashboard.innerHTML = emptyStateHTML();
    return;
  }

  const groups = groupHosts();
  const empty = dashboard.querySelector('.empty-state');
  if (empty) empty.remove();

  groups.forEach((groupHosts, groupName) => {
    let section = document.getElementById(`group-${cssId(groupName)}`);
    if (!section) {
      section = createGroupSection(groupName);
      dashboard.appendChild(section);
    }
    groupHosts.forEach(h => renderHost(h));
  });

  cleanEmptyGroups();
}

function renderHost(host) {
  const existing = document.getElementById(`host-${host.id}`);

  if (existing) {
    const wasExpanded = existing.classList.contains('expanded');
    const wasFlashing = existing.classList.contains('alert-flash');
    const newCard = buildHostCard(host, wasExpanded);
    if (wasFlashing && hostHasActiveAlert(host.id)) {
      newCard.classList.add('alert-flash');
    }
    existing.replaceWith(newCard);
  } else {
    const groupName = host.group || 'Ungrouped';
    let section = document.getElementById(`group-${cssId(groupName)}`);
    if (!section) {
      section = createGroupSection(groupName);
      document.getElementById('dashboard').appendChild(section);
    }
    const container = section.querySelector('.group-hosts');
    container.appendChild(buildHostCard(host, false));
  }

  updateGroupCount(host.group || 'Ungrouped');
}

function buildHostCard(host, expanded) {
  const card = document.createElement('div');
  card.id = `host-${host.id}`;
  card.className = `host-card ${host.connected ? 'connected' : 'offline'} ${expanded ? 'expanded' : ''}`;

  const r = (host.door_color & 0xFF);
  const g = (host.door_color >> 8) & 0xFF;
  const b = (host.door_color >> 16) & 0xFF;
  const doorRgb = `rgb(${r},${g},${b})`;

  const versionStr = host.version
    ? `v${host.version}${host.build ? ' b' + host.build : ''}`
    : '';
  const metaParts = [host.ip, versionStr].filter(Boolean);

  const criticalApps = new Set(host.critical_apps || []);

  card.innerHTML = `
    <div class="card-header">
      <span class="status-dot"></span>
      <div class="card-title">
        <div class="card-name" title="${esc(host.display_name)}">${esc(host.display_name)}</div>
        <div class="card-meta">${metaParts.map(esc).join(' · ')}</div>
      </div>
      <div class="card-badges">
        ${host.door_detected ? `<span class="door-swatch" style="background:${doorRgb}" title="Door color"></span>` : ''}
        ${host.win_updates > 0 ? `<span class="badge badge-update" title="${host.win_updates} update(s) pending${host.win_pending_restart ? ' · Restart required' : ''}">UPD</span>` : ''}
        <span class="badge ${host.connected ? 'badge-connected' : 'badge-offline'}">${host.connected ? 'ONLINE' : 'OFFLINE'}</span>
      </div>
      <span class="card-expand-icon">▼</span>
    </div>
    <div class="card-body">
      ${buildDiskSection(host.disks)}
      ${buildCanvasSection(host)}
      ${buildAppSection(host.apps, criticalApps)}
      <div class="card-footer">
        <button class="btn btn-sm btn-secondary" data-action="edit">✎ Edit</button>
        <button class="btn btn-sm btn-success btn-cmd" data-action="start">▶ Start All</button>
        <button class="btn btn-sm btn-warning btn-cmd" data-action="stop">■ Stop All</button>
        <button class="btn btn-sm btn-danger  btn-cmd" data-action="reboot">⟳ Reboot</button>
      </div>
    </div>
  `;

  // Disable command buttons when host is offline
  if (!host.connected) {
    card.querySelectorAll('.btn-cmd').forEach(b => b.setAttribute('disabled', ''));
  }

  // Single delegated handler — covers all buttons and header expand
  card.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) {
      if (e.target.closest('.card-header')) card.classList.toggle('expanded');
      return;
    }
    e.stopPropagation();
    const action = btn.dataset.action;
    if      (action === 'edit')   openEditModal(host.id);
    else if (['start','stop','reboot'].includes(action)) openCommandModal(host.id, host.display_name, action);
  });

  return card;
}

function buildCanvasSection(host) {
  if (!host.canvas_enabled || !host.connected) return '';
  const port = host.canvas_port || 9056;
  const base = `https://${host.ip}:${port}`;
  return `
    <div class="canvas-section">
      <div class="canvas-section-title">WSS Canvas</div>
      <div class="canvas-links">
        <a href="${base}/outputs"  target="_blank" rel="noopener" class="canvas-link">Outputs ↗</a>
        <a href="${base}/previews" target="_blank" rel="noopener" class="canvas-link">Previews ↗</a>
      </div>
    </div>`;
}

function buildDiskSection(disks) {
  if (!disks || disks.length === 0) return '';

  const rows = disks.map(d => {
    const usedPct = d.total_bytes > 0 ? ((d.total_bytes - d.free_bytes) / d.total_bytes) * 100 : 0;
    const barClass = usedPct >= 90 ? 'crit' : usedPct >= 75 ? 'warn' : '';
    const freeStr = formatBytes(d.free_bytes) + ' free';
    return `
      <div class="disk-row">
        <span class="disk-label">${esc(d.label)}</span>
        <div class="disk-bar-wrap">
          <div class="disk-bar ${barClass}" style="width:${usedPct.toFixed(1)}%"></div>
        </div>
        <span class="disk-free">${freeStr}</span>
      </div>`;
  }).join('');

  return `
    <div class="disk-section">
      <div class="disk-section-title">Disk Status</div>
      ${rows}
    </div>`;
}

function buildAppSection(apps, criticalApps) {
  if (!apps || apps.length === 0) return '';

  const rows = apps.map(app => {
    let stateClass, stateLabel;
    if (app.ignore_status) {
      stateClass = 'app-ignored';
      stateLabel = 'Ignored';
    } else if (app.status === 2) {
      stateClass = 'app-running';
      stateLabel = 'Running';
    } else {
      stateClass = 'app-stopped';
      stateLabel = 'Not running';
    }
    const isCritical = criticalApps && criticalApps.has(app.key);
    return `
      <li class="app-row ${stateClass} ${isCritical ? 'app-critical' : ''}">
        <span class="app-status-dot"></span>
        ${isCritical ? '<span class="app-critical-icon" title="Critical — alerts enabled">🔔</span>' : ''}
        <span class="app-name" title="${esc(app.name)}">${esc(app.name)}</span>
        <span class="app-version">${esc(app.version)}</span>
        <span class="app-state-label">${stateLabel}</span>
      </li>`;
  }).join('');

  return `
    <div class="app-section">
      <div class="app-section-title">Applications</div>
      <ul class="app-list">${rows}</ul>
    </div>`;
}

function createGroupSection(groupName) {
  const section = document.createElement('div');
  section.id = `group-${cssId(groupName)}`;
  section.className = 'group-section';
  section.innerHTML = `
    <div class="group-header" data-group="${esc(groupName)}">
      <span class="group-title">${esc(groupName)}</span>
      <span class="group-count" id="gcount-${cssId(groupName)}">0</span>
      <span class="group-toggle">▼</span>
    </div>
    <div class="group-hosts"></div>
  `;
  section.querySelector('.group-header').addEventListener('click', () => {
    section.classList.toggle('collapsed');
  });
  return section;
}

function updateGroupCount(groupName) {
  const counter = document.getElementById(`gcount-${cssId(groupName)}`);
  if (!counter) return;
  const section = document.getElementById(`group-${cssId(groupName)}`);
  if (!section) return;
  counter.textContent = section.querySelectorAll('.host-card').length;
}

function cleanEmptyGroups() {
  document.querySelectorAll('.group-section').forEach(section => {
    if (section.querySelectorAll('.host-card').length === 0) section.remove();
  });
  if (hosts.size === 0) {
    const dashboard = document.getElementById('dashboard');
    if (!dashboard.querySelector('.empty-state')) {
      dashboard.insertAdjacentHTML('beforeend', emptyStateHTML());
    }
  }
}

function groupHosts() {
  const groups = new Map();
  hosts.forEach(h => {
    const g = h.group || 'Ungrouped';
    if (!groups.has(g)) groups.set(g, []);
    groups.get(g).push(h);
  });
  return groups;
}

function emptyStateHTML() {
  return `
    <div class="empty-state">
      <h3>No hosts configured</h3>
      <p>Add a host manually or import a <code>.xcl</code> file from XPression Status Client.</p>
      <button class="btn" onclick="document.getElementById('btnAddHost').click()">+ Add Host</button>
    </div>`;
}

// ---------------------------------------------------------------------------
// Alerts Modal
// ---------------------------------------------------------------------------

function openAlertsModal(hostId, hostName) {
  alertsTargetHostId = hostId;
  const host = hosts.get(hostId);
  if (!host) return;

  const criticalApps = new Set(host.critical_apps || []);

  document.getElementById('alertsHostName').textContent = hostName;

  const list = document.getElementById('alertsAppList');
  list.innerHTML = '';

  if (!host.apps || host.apps.length === 0) {
    list.innerHTML = '<p class="hint" style="padding:12px 0">No apps discovered yet. Connect to host first.</p>';
  } else {
    host.apps
      .filter(a => !a.ignore_status) // don't show ignored apps
      .forEach(app => {
        const checked = criticalApps.has(app.key);
        const row = document.createElement('label');
        row.className = 'alert-app-row';
        row.innerHTML = `
          <input type="checkbox" value="${esc(app.key)}" ${checked ? 'checked' : ''}>
          <span class="alert-app-name">${esc(app.name)}</span>
          <span class="alert-app-version">${esc(app.version)}</span>
        `;
        list.appendChild(row);
      });
  }

  openModal('modalAlerts');
}

document.getElementById('btnAlertsSave').addEventListener('click', () => {
  if (!alertsTargetHostId) return;

  const checked = [...document.querySelectorAll('#alertsAppList input[type=checkbox]:checked')]
    .map(cb => cb.value);

  wsSend({
    action: 'set_critical_apps',
    id: alertsTargetHostId,
    critical_apps: checked,
  });

  closeModal('modalAlerts');
  alertsTargetHostId = null;
});

document.getElementById('btnAlertsTest').addEventListener('click', () => {
  playAlertSound();
});

// ---------------------------------------------------------------------------
// Host command confirmation modal
// ---------------------------------------------------------------------------

const COMMAND_LABELS = {
  start:  { label: 'Start All Processes', verb: 'start all processes on',  btnClass: 'btn-success' },
  stop:   { label: 'Stop All Processes',  verb: 'stop all processes on',   btnClass: 'btn-warning' },
  reboot: { label: 'Reboot Machine',      verb: 'reboot',                  btnClass: 'btn-danger'  },
};

function openCommandModal(hostId, hostName, command) {
  pendingCommand = { id: hostId, name: hostName, command };
  const meta = COMMAND_LABELS[command];
  document.getElementById('cmdModalTitle').textContent = meta.label;
  document.getElementById('cmdModalBody').innerHTML =
    `Are you sure you want to <strong>${meta.verb}</strong> <strong>${esc(hostName)}</strong>?` +
    (command === 'reboot' ? '<br><br><span style="color:var(--yellow)">⚠ This will immediately reboot the Windows machine.</span>' : '') +
    (command === 'stop'   ? '<br><br><span style="color:var(--yellow)">⚠ This will kill all running XPression processes except Monitor and Monitor Launcher.</span>' : '');
  const btn = document.getElementById('btnCmdConfirm');
  btn.className = `btn ${meta.btnClass}`;
  btn.textContent = meta.label;
  openModal('modalCommand');
}

document.getElementById('btnCmdConfirm').addEventListener('click', () => {
  if (!pendingCommand) return;
  wsSend({ action: 'host_command', id: pendingCommand.id, command: pendingCommand.command });
  const card = document.getElementById(`host-${pendingCommand.id}`);
  if (card) {
    card.querySelectorAll('.btn-cmd').forEach(b => { b.disabled = true; });
    setTimeout(() => {
      const c = document.getElementById(`host-${pendingCommand.id}`);
      if (c) c.querySelectorAll('.btn-cmd').forEach(b => { b.disabled = false; });
    }, 3000);
  }
  pendingCommand = null;
  closeModal('modalCommand');
});

function handleCommandResult(msg) {
  if (msg.ok) {
    toast('success', `${COMMAND_LABELS[msg.command]?.label ?? msg.command} sent to ${hosts.get(msg.id)?.display_name ?? msg.id}`);
  } else {
    toast('error', `Command failed: ${msg.error || 'unknown error'}`);
  }
}

// ---------------------------------------------------------------------------
// Edit Host Modal
// ---------------------------------------------------------------------------

function openEditModal(hostId) {
  editTargetHostId = hostId;
  const host = hosts.get(hostId);
  if (!host) return;
  document.getElementById('editHostId').value          = hostId;
  document.getElementById('editName').value            = host.display_name;
  document.getElementById('editIp').value              = host.ip;
  document.getElementById('editPort').value            = host.port || 9875;
  document.getElementById('editGroup').value           = host.group || 'Ungrouped';
  document.getElementById('editCanvasEnabled').checked = !!host.canvas_enabled;
  document.getElementById('editCanvasPort').value      = host.canvas_port || 9056;
  document.getElementById('editCanvasPortRow').style.display = host.canvas_enabled ? '' : 'none';
  openModal('modalEditHost');
}

document.getElementById('btnEditOpenAlerts').addEventListener('click', () => {
  if (!editTargetHostId) return;
  const host = hosts.get(editTargetHostId);
  closeModal('modalEditHost');
  openAlertsModal(editTargetHostId, host ? host.display_name : editTargetHostId);
});

document.getElementById('btnEditRemoveHost').addEventListener('click', () => {
  if (!editTargetHostId) return;
  const host = hosts.get(editTargetHostId);
  closeModal('modalEditHost');
  openRemoveModal(editTargetHostId, host ? host.display_name : editTargetHostId);
  editTargetHostId = null;
});

document.getElementById('editCanvasEnabled').addEventListener('change', function() {
  document.getElementById('editCanvasPortRow').style.display = this.checked ? '' : 'none';
});

document.getElementById('btnEditHostSubmit').addEventListener('click', () => {
  const id     = document.getElementById('editHostId').value;
  const name   = document.getElementById('editName').value.trim();
  const ip     = document.getElementById('editIp').value.trim();
  const port   = parseInt(document.getElementById('editPort').value) || 9875;
  const group  = document.getElementById('editGroup').value.trim() || 'Ungrouped';
  const canvas = document.getElementById('editCanvasEnabled').checked;
  const cport  = parseInt(document.getElementById('editCanvasPort').value) || 9056;
  if (!name || !ip) { toast('error', 'Name and IP are required'); return; }
  wsSend({ action: 'edit_host', id, display_name: name, ip, port, group,
           canvas_enabled: canvas, canvas_port: cport });
  closeModal('modalEditHost');
  editTargetHostId = null;
});

// ---------------------------------------------------------------------------
// Modals — generic
// ---------------------------------------------------------------------------

function openModal(id) {
  document.getElementById(id).removeAttribute('hidden');
}

function closeModal(id) {
  document.getElementById(id).setAttribute('hidden', '');
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) closeModal(overlay.id);
  });
});

document.querySelectorAll('[data-modal]').forEach(btn => {
  btn.addEventListener('click', () => closeModal(btn.dataset.modal));
});

// Add Host
document.getElementById('btnAddHost').addEventListener('click', () => {
  openModal('modalAddHost');
  document.getElementById('addName').focus();
});

document.getElementById('btnAddHostSubmit').addEventListener('click', () => {
  const name  = document.getElementById('addName').value.trim();
  const ip    = document.getElementById('addIp').value.trim();
  const port  = parseInt(document.getElementById('addPort').value) || 9875;
  const group = document.getElementById('addGroup').value.trim() || 'Ungrouped';

  if (!name || !ip) { toast('error', 'Name and IP address are required'); return; }
  if (!/^[\d.]+$/.test(ip) && !/^[a-zA-Z0-9.\-]+$/.test(ip)) {
    toast('error', 'Invalid IP address or hostname'); return;
  }

  wsSend({ action: 'add_host', host: { display_name: name, ip, port, group } });
  closeModal('modalAddHost');
  ['addName', 'addIp', 'addGroup'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('addPort').value = '9875';
});

// Import XCL
document.getElementById('btnImport').addEventListener('click', () => openModal('modalImport'));

const xclFile         = document.getElementById('xclFile');
const xclDrop         = document.getElementById('xclDropZone');
const xclLabel        = document.getElementById('xclDropLabel');
const btnImportSubmit = document.getElementById('btnImportSubmit');

xclDrop.addEventListener('click', () => xclFile.click());
xclDrop.addEventListener('dragover', (e) => { e.preventDefault(); xclDrop.classList.add('drag-over'); });
xclDrop.addEventListener('dragleave', () => xclDrop.classList.remove('drag-over'));
xclDrop.addEventListener('drop', (e) => { e.preventDefault(); xclDrop.classList.remove('drag-over'); loadXclFile(e.dataTransfer.files[0]); });
xclFile.addEventListener('change', () => { if (xclFile.files[0]) loadXclFile(xclFile.files[0]); });

function loadXclFile(file) {
  if (!file) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    xclFileContent = e.target.result;
    xclLabel.textContent = `✓ ${file.name}`;
    xclDrop.classList.add('has-file');
    btnImportSubmit.disabled = false;
  };
  reader.readAsText(file);
}

btnImportSubmit.addEventListener('click', () => {
  if (!xclFileContent) return;
  wsSend({ action: 'import_xcl', xml: xclFileContent });
  xclFileContent = null;
  xclLabel.textContent = 'Drop .xcl file here or click to browse';
  xclDrop.classList.remove('has-file');
  btnImportSubmit.disabled = true;
  xclFile.value = '';
});

// Remove host
function openRemoveModal(id, name) {
  pendingRemoveId = id;
  document.getElementById('removeHostName').textContent = name;
  openModal('modalRemove');
}

document.getElementById('btnRemoveConfirm').addEventListener('click', () => {
  if (pendingRemoveId) {
    wsSend({ action: 'remove_host', id: pendingRemoveId });
    pendingRemoveId = null;
  }
  closeModal('modalRemove');
});

// ---------------------------------------------------------------------------
// WS status indicator
// ---------------------------------------------------------------------------

function setWsStatus(state, label) {
  const el = document.getElementById('wsStatus');
  el.className = `ws-status ${state}`;
  el.querySelector('.ws-label').textContent = label;
}

// ---------------------------------------------------------------------------
// Toast notifications
// ---------------------------------------------------------------------------

function toast(type, message, duration = 4000) {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = message;
  container.appendChild(el);
  setTimeout(() => el.remove(), duration);
}

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

function esc(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function cssId(str) {
  return String(str).replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
}

function formatBytes(bytes) {
  if (bytes >= 1e12) return (bytes / 1e12).toFixed(1) + ' TB';
  if (bytes >= 1e9)  return (bytes / 1e9).toFixed(1) + ' GB';
  if (bytes >= 1e6)  return (bytes / 1e6).toFixed(0) + ' MB';
  return bytes + ' B';
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

wsConnect();
