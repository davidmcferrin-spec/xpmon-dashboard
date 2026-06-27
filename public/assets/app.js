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

/** Re-evaluate alerts periodically so upgrade grace expiry is detected */
let alertRecheckTimer = null;
const ALERT_RECHECK_MS = 5000;

let ws = null;
let wsReconnectTimer = null;
let wsReconnectDelay = 2000;
let pendingRemoveId = null;
let xclFileContent = null;
let alertsTargetHostId = null; // host being configured in alerts modal
let editTargetHostId   = null; // host being edited
let pendingCommand     = null; // { id, name, command }
let searchTerm         = '';

// ---------------------------------------------------------------------------
// Auth / permissions (from PHP session via window.XPMON_USER)
// ---------------------------------------------------------------------------

const XPMON_USER = window.XPMON_USER || { permissions: {}, prefs: {} };

function can(perm) {
  return !!(XPMON_USER.permissions && XPMON_USER.permissions[perm]);
}

function userPrefs() {
  return XPMON_USER.prefs || {};
}

function forcedPrefs() {
  return XPMON_USER.forced_prefs || {};
}

function applyForcedPrefControl(rowId, inputId, lockId, forcedKey) {
  const forced = !!forcedPrefs()[forcedKey];
  const row = document.getElementById(rowId);
  const input = document.getElementById(inputId);
  const lock = document.getElementById(lockId);
  if (input) input.disabled = forced;
  if (row) row.classList.toggle('pref-forced', forced);
  if (lock) lock.hidden = !forced;
}

function pref(key, fallback) {
  const p = userPrefs();
  return p[key] !== undefined ? p[key] : fallback;
}

const WS_GUARDED_ACTIONS = {
  add_host: 'manage_hosts',
  remove_host: 'manage_hosts',
  edit_host: 'manage_hosts',
  import_xcl: 'manage_hosts',
  update_group: 'manage_hosts',
  set_critical_apps: 'manage_hosts',
};

function canExecuteCommand(command) {
  if (command === 'reboot') return can('execute_reboot');
  if (command === 'start' || command === 'stop') return can('execute_service_commands');
  return false;
}

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

/** Stable identity for a logical app across GUID/version changes (matches bridge). */
function appIdentity(app) {
  const name = (app.name || '').trim().toLowerCase();
  const proc = (app.process || '').trim().toLowerCase();
  return `${name}|${proc}`;
}

function isInUpgradeGrace(host, app) {
  const grace = host.upgrade_grace || {};
  const until = grace[appIdentity(app)];
  return until && until > Date.now() / 1000;
}

/** Monitor all hosts when alert_hosts_all is true (default); else only IDs in alert_hosts. */
function shouldAlertForHost(hostId) {
  const p = userPrefs();
  if (p.alert_hosts_all !== false) return true;
  return (p.alert_hosts || []).includes(hostId);
}

/** Per-user app overrides; falls back to global host critical_apps when unset. */
function criticalAppsForHost(host) {
  const overrides = userPrefs().user_critical_apps;
  if (overrides && typeof overrides === 'object'
      && Object.prototype.hasOwnProperty.call(overrides, host.id)) {
    return new Set(overrides[host.id] || []);
  }
  return new Set(host.critical_apps || []);
}

async function saveUserPrefs(partial) {
  const r = await fetch('api/profile.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'save_prefs', ...partial }),
  });
  const d = await r.json();
  if (d.ok && d.prefs) XPMON_USER.prefs = d.prefs;
  return d;
}

function clearHostAlerts(hostId) {
  for (const key of [...activeAlerts.keys()]) {
    if (key === `host:${hostId}` || key.startsWith(`app:${hostId}:`)) {
      activeAlerts.delete(key);
    }
  }
  clearFlash(hostId);
}

/** Remove alert entries for app GUIDs no longer in inventory. */
function reconcileActiveAlerts(host) {
  const validKeys = new Set((host.apps || []).map(a => a.key));
  for (const key of [...activeAlerts.keys()]) {
    if (!key.startsWith(`app:${host.id}:`)) continue;
    const appKey = key.slice(`app:${host.id}:`.length);
    if (!validKeys.has(appKey)) {
      activeAlerts.delete(key);
    }
  }
}

function startAlertRecheck() {
  if (alertRecheckTimer) return;
  alertRecheckTimer = setInterval(() => {
    if (!initialLoadDone) return;
    for (const host of hosts.values()) {
      evaluateAlerts(host, host);
    }
  }, ALERT_RECHECK_MS);
}

/**
 * Evaluate a host's state for alert conditions.
 * Fires sound + flash on new faults. Clears flash when fault resolves.
 * Does nothing during initial snapshot load.
 */
function evaluateAlerts(host, previousHost) {
  if (!initialLoadDone) return;

  if (!shouldAlertForHost(host.id)) {
    clearHostAlerts(host.id);
    return;
  }

  reconcileActiveAlerts(host);

  const criticalApps = criticalAppsForHost(host);

  // --- Host offline (not while CHECKING — still connected) ---
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
      if (isInUpgradeGrace(host, app)) continue;

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
  const mode = pref('alert_mode', 'both');
  if (mode === 'horn' || mode === 'both') playAlertSound();
  if (mode === 'flash' || mode === 'both') flashCard(hostId);
  if (mode !== 'none') {
    toast('error', `⚠ ${message}`, 8000);
    console.warn(`[ALERT] ${message}`);
  }
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
    if (obj.action === 'host_command') {
      if (!canExecuteCommand(obj.command)) {
        toast('error', 'You do not have permission for that command');
        return;
      }
    } else {
      const required = WS_GUARDED_ACTIONS[obj.action];
      if (required && !can(required)) {
        toast('error', 'You do not have permission for that action');
        return;
      }
    }
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
      setTimeout(() => {
        initialLoadDone = true;
        startAlertRecheck();
      }, 2000);
      break;

    case 'host_update': {
      const prev = hosts.get(msg.host.id);
      hosts.set(msg.host.id, msg.host);
      scheduleRender(msg.host.id);
      evaluateAlerts(msg.host, prev);
      break;
    }

    case 'host_added':
      hosts.set(msg.host.id, msg.host);
      scheduleRender(msg.host.id);
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
      scheduleRender(msg.host.id);
      toast('success', `Host updated: ${msg.host.display_name}`);
      break;

    case 'command_result':
      handleCommandResult(msg);
      break;

    case 'alerts_updated':
      if (hosts.has(msg.id)) {
        hosts.get(msg.id).critical_apps = msg.critical_apps;
        scheduleRender(msg.id);
      }
      if (!msg.silent) {
        toast('success', 'Alert configuration saved');
      }
      break;

    case 'app_upgraded':
      if (msg.changes && msg.changes.length > 0) {
        for (const ch of msg.changes) {
          const from = ch.old_version ? ` ${ch.old_version}` : '';
          const to = ch.new_version ? ` → ${ch.new_version}` : '';
          toast('info', `${msg.host}: ${ch.name}${from}${to}`, 6000);
        }
      }
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

// ---------------------------------------------------------------------------
// Render queue — batches DOM updates via requestAnimationFrame
// Prevents redundant re-renders when multiple host_update messages arrive
// in the same JS event loop tick (e.g. on initial connect or mass reconnect).
// ---------------------------------------------------------------------------

/** @type {Set<string>} host IDs queued for re-render */
const renderQueue = new Set();
let renderFramePending = false;

function scheduleRender(hostId) {
  renderQueue.add(hostId);
  if (!renderFramePending) {
    renderFramePending = true;
    requestAnimationFrame(flushRenderQueue);
  }
}

function flushRenderQueue() {
  renderFramePending = false;
  // Snapshot and clear the queue before rendering so any updates that
  // arrive during rendering are queued for the next frame, not lost.
  const toRender = [...renderQueue];
  renderQueue.clear();
  for (const id of toRender) {
    const host = hosts.get(id);
    if (host) renderHost(host);
  }
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
  applySearch();
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
  applySearch();
}

function countStoppedApps(host) {
  if (!host.connected || !host.apps) return 0;
  return host.apps.filter(app =>
    !app.ignore_status && app.status !== 2 && !isInUpgradeGrace(host, app)
  ).length;
}

function hostCardStateClass(host) {
  if (!host.connected) return 'offline';
  if (host.checking) return 'checking';
  if (countStoppedApps(host) > 0) return 'degraded';
  return 'connected';
}

function hostStatusBadge(host, stoppedCount) {
  if (host.checking) {
    return '<span class="badge badge-checking">CHECKING</span>';
  }
  if (!host.connected) {
    return '<span class="badge badge-offline">OFFLINE</span>';
  }
  if (stoppedCount > 0) {
    const label = stoppedCount === 1 ? '1 DOWN' : `${stoppedCount} DOWN`;
    return `<span class="badge badge-degraded" title="${stoppedCount} application(s) not running">${label}</span>`;
  }
  return '<span class="badge badge-connected">ONLINE</span>';
}

function buildHostCard(host, expanded) {
  const card = document.createElement('div');
  card.id = `host-${host.id}`;
  const stoppedCount = countStoppedApps(host);
  card.className = `host-card ${hostCardStateClass(host)} ${expanded ? 'expanded' : ''}`;

  const r = (host.door_color & 0xFF);
  const g = (host.door_color >> 8) & 0xFF;
  const b = (host.door_color >> 16) & 0xFF;
  const doorRgb = `rgb(${r},${g},${b})`;

  const versionStr = host.version
    ? `v${host.version}${host.build ? ' b' + host.build : ''}`
    : '';
  const metaParts = [host.ip, versionStr].filter(Boolean);

  const criticalApps = criticalAppsForHost(host);
  const showDoor = host.door_detected && !pref('hide_door', false);
  const showUpd = host.win_updates > 0 && !pref('hide_win_updates', false);

  const footerButtons = [];
  if (can('manage_hosts')) {
    footerButtons.push('<button class="btn btn-sm btn-secondary" data-action="edit">✎ Edit</button>');
  }
  if (can('view_host_commands')) {
    const svcDis = can('execute_service_commands')
      ? '' : ' disabled title="No permission to start/stop services"';
    const rebootDis = can('execute_reboot')
      ? '' : ' disabled title="No permission to reboot"';
    footerButtons.push(`<button class="btn btn-sm btn-success btn-cmd" data-action="start"${svcDis}>▶ Start All</button>`);
    footerButtons.push(`<button class="btn btn-sm btn-warning btn-cmd" data-action="stop"${svcDis}>■ Stop All</button>`);
    footerButtons.push(`<button class="btn btn-sm btn-danger  btn-cmd" data-action="reboot"${rebootDis}>⟳ Reboot</button>`);
  }
  const footerHtml = footerButtons.length
    ? `<div class="card-footer">${footerButtons.join('')}</div>`
    : '';

  card.innerHTML = `
    <div class="card-header">
      <span class="status-dot"></span>
      <div class="card-title">
        <div class="card-name" title="${esc(host.display_name)}">${esc(host.display_name)}</div>
        <div class="card-meta">${metaParts.map(esc).join(' · ')}</div>
      </div>
      <div class="card-badges">
        ${showDoor ? `<span class="door-swatch" style="background:${doorRgb}" title="Door color"></span>` : ''}
        ${showUpd ? `<span class="badge badge-update" title="${host.win_updates} update(s) pending${host.win_pending_restart ? ' · Restart required' : ''}">UPD</span>` : ''}
        ${hostStatusBadge(host, stoppedCount)}
      </div>
      <span class="card-expand-icon">▼</span>
    </div>
    <div class="card-body">
      ${buildDiskSection(host.disks)}
      ${buildCanvasSection(host)}
      ${buildAppSection(host.apps, criticalApps, host.upgrade_grace)}
      ${footerHtml}
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
    if (btn.disabled) return;
    e.stopPropagation();
    const action = btn.dataset.action;
    if      (action === 'edit')   openEditModal(host.id);
    else if (['start','stop','reboot'].includes(action)) {
      if (!canExecuteCommand(action)) {
        toast('error', action === 'reboot'
          ? 'You do not have permission to reboot hosts'
          : 'You do not have permission to start/stop services');
        return;
      }
      openCommandModal(host.id, host.display_name, action);
    }
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

function buildAppSection(apps, criticalApps, upgradeGrace) {
  if (!apps || apps.length === 0) return '';

  const showIgnored = pref('show_ignored_services', true);
  const filtered = showIgnored ? apps : apps.filter(a => !a.ignore_status);
  if (filtered.length === 0) return '';

  const rows = filtered.map(app => {
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
    const upgrading = isInUpgradeGrace({ upgrade_grace: upgradeGrace || {} }, app);
    const stateLabelFinal = upgrading && stateClass === 'app-stopped'
      ? 'Updating…'
      : stateLabel;
    const stateClassFinal = upgrading && stateClass === 'app-stopped'
      ? 'app-upgrading'
      : stateClass;
    return `
      <li class="app-row ${stateClassFinal} ${isCritical ? 'app-critical' : ''}">
        <span class="app-status-dot"></span>
        ${isCritical ? '<span class="app-critical-icon" title="Critical — alerts enabled">🔔</span>' : ''}
        <span class="app-name" title="${esc(app.name)}">${esc(app.name)}</span>
        <span class="app-version">${esc(app.version)}</span>
        <span class="app-state-label">${stateLabelFinal}</span>
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
    saveCollapseState();
  });

  // Restore saved collapsed state for this group
  if (loadCollapseState()[cssId(groupName)]) {
    section.classList.add('collapsed');
  }

  return section;
}

function saveCollapseState() {
  const state = {};
  document.querySelectorAll('.group-section').forEach(s => {
    state[s.id.replace('group-', '')] = s.classList.contains('collapsed');
  });
  try { localStorage.setItem('xpmon-groups', JSON.stringify(state)); } catch(e) {}
}

function loadCollapseState() {
  try { return JSON.parse(localStorage.getItem('xpmon-groups') || '{}'); } catch(e) { return {}; }
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
  const addBtn = can('manage_hosts')
    ? '<button class="btn" onclick="document.getElementById(\'btnAddHost\').click()">+ Add Host</button>'
    : '';
  return `
    <div class="empty-state">
      <h3>No hosts configured</h3>
      <p>Add a host manually or import a <code>.xcl</code> file from XPression Status Client.</p>
      ${addBtn}
    </div>`;
}

// ---------------------------------------------------------------------------
// Alerts Modal
// ---------------------------------------------------------------------------

function openAlertsModal(hostId, hostName) {
  alertsTargetHostId = hostId;
  const host = hosts.get(hostId);
  if (!host) return;

  const userApps = userPrefs().user_critical_apps || {};
  const criticalApps = Object.prototype.hasOwnProperty.call(userApps, hostId)
    ? new Set(userApps[hostId] || [])
    : new Set(host.critical_apps || []);

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

document.getElementById('btnAlertsSave')?.addEventListener('click', async () => {
  if (!alertsTargetHostId) return;

  const hostId = alertsTargetHostId;
  const checked = [...document.querySelectorAll('#alertsAppList input[type=checkbox]:checked')]
    .map(cb => cb.value);

  const userApps = { ...(userPrefs().user_critical_apps || {}) };
  userApps[hostId] = checked;

  try {
    const d = await saveUserPrefs({ user_critical_apps: userApps });
    if (d.ok) {
      for (const h of hosts.values()) evaluateAlerts(h, h);
      toast('success', 'Your alert preferences saved');
      closeModal('modalAlerts');
      alertsTargetHostId = null;
    } else {
      toast('error', d.error || 'Save failed');
    }
  } catch (e) {
    toast('error', 'Save failed');
  }
});

document.getElementById('btnAlertsTest')?.addEventListener('click', () => {
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

document.getElementById('btnCmdConfirm')?.addEventListener('click', () => {
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
  document.getElementById('editHostname').value        = host.hostname || host.ip;
  document.getElementById('editPort').value            = host.port || 9875;
  document.getElementById('editGroup').value           = host.group || 'Ungrouped';
  document.getElementById('editCanvasEnabled').checked = !!host.canvas_enabled;
  document.getElementById('editCanvasPort').value      = host.canvas_port || 9056;
  document.getElementById('editCanvasPortRow').style.display = host.canvas_enabled ? '' : 'none';
  openModal('modalEditHost');
}

document.getElementById('btnEditOpenAlerts')?.addEventListener('click', () => {
  if (!editTargetHostId) return;
  const host = hosts.get(editTargetHostId);
  closeModal('modalEditHost');
  openAlertsModal(editTargetHostId, host ? host.display_name : editTargetHostId);
});

document.getElementById('btnEditRemoveHost')?.addEventListener('click', () => {
  if (!editTargetHostId) return;
  const host = hosts.get(editTargetHostId);
  closeModal('modalEditHost');
  openRemoveModal(editTargetHostId, host ? host.display_name : editTargetHostId);
  editTargetHostId = null;
});

document.getElementById('editCanvasEnabled')?.addEventListener('change', function() {
  document.getElementById('editCanvasPortRow').style.display = this.checked ? '' : 'none';
});

document.getElementById('btnEditHostSubmit')?.addEventListener('click', () => {
  const id     = document.getElementById('editHostId').value;
  const name   = document.getElementById('editName').value.trim();
  const ip     = document.getElementById('editIp').value.trim();
  const hostname = document.getElementById('editHostname').value.trim();
  const port   = parseInt(document.getElementById('editPort').value) || 9875;
  const group  = document.getElementById('editGroup').value.trim() || 'Ungrouped';
  const canvas = document.getElementById('editCanvasEnabled').checked;
  const cport  = parseInt(document.getElementById('editCanvasPort').value) || 9056;
  if (!name || !ip) { toast('error', 'Name and IP are required'); return; }
  wsSend({ action: 'edit_host', id, display_name: name, ip, port, group, hostname: hostname || ip,
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
document.getElementById('btnAddHost')?.addEventListener('click', () => {
  openModal('modalAddHost');
  document.getElementById('addName').focus();
});

document.getElementById('btnAddHostSubmit')?.addEventListener('click', () => {
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
document.getElementById('btnImport')?.addEventListener('click', () => openModal('modalImport'));

const xclFile         = document.getElementById('xclFile');
const xclDrop         = document.getElementById('xclDropZone');
const xclLabel        = document.getElementById('xclDropLabel');
const btnImportSubmit = document.getElementById('btnImportSubmit');

if (xclDrop) {
xclDrop.addEventListener('click', () => xclFile.click());
xclDrop.addEventListener('dragover', (e) => { e.preventDefault(); xclDrop.classList.add('drag-over'); });
xclDrop.addEventListener('dragleave', () => xclDrop.classList.remove('drag-over'));
xclDrop.addEventListener('drop', (e) => { e.preventDefault(); xclDrop.classList.remove('drag-over'); loadXclFile(e.dataTransfer.files[0]); });
}
xclFile?.addEventListener('change', () => { if (xclFile.files[0]) loadXclFile(xclFile.files[0]); });

function loadXclFile(file) {
  if (!file || !xclLabel) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    xclFileContent = e.target.result;
    xclLabel.textContent = `✓ ${file.name}`;
    xclDrop.classList.add('has-file');
    if (btnImportSubmit) btnImportSubmit.disabled = false;
  };
  reader.readAsText(file);
}

btnImportSubmit?.addEventListener('click', () => {
  if (!xclFileContent) return;
  wsSend({ action: 'import_xcl', xml: xclFileContent });
  xclFileContent = null;
  xclLabel.textContent = 'Drop .xcl file here or click to browse';
  xclDrop.classList.remove('has-file');
  if (btnImportSubmit) btnImportSubmit.disabled = true;
  if (xclFile) xclFile.value = '';
});

// Remove host
function openRemoveModal(id, name) {
  pendingRemoveId = id;
  document.getElementById('removeHostName').textContent = name;
  openModal('modalRemove');
}

document.getElementById('btnRemoveConfirm')?.addEventListener('click', () => {
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
// Search / filter
// ---------------------------------------------------------------------------

function applySearch() {
  const term = searchTerm.trim().toLowerCase();
  let anyVisible = false;

  document.querySelectorAll('.group-section').forEach(section => {
    let groupHasMatch = false;
    section.querySelectorAll('.host-card').forEach(card => {
      const id = card.id.replace('host-', '');
      const host = hosts.get(id);
      if (!host) return;

      const matches = !term
        || host.display_name.toLowerCase().includes(term)
        || host.ip.toLowerCase().includes(term)
        || (host.hostname || '').toLowerCase().includes(term)
        || (host.reported_hostname || '').toLowerCase().includes(term)
        || (host.group || '').toLowerCase().includes(term);

      card.style.display = matches ? '' : 'none';
      if (matches) { groupHasMatch = true; anyVisible = true; }
    });

    // Hide entire group section if nothing in it matches
    section.style.display = groupHasMatch ? '' : 'none';
  });

  // Show empty state if search returns nothing
  const dashboard = document.getElementById('dashboard');
  let noResults = dashboard.querySelector('.no-results');
  if (!anyVisible && term && hosts.size > 0) {
    if (!noResults) {
      noResults = document.createElement('div');
      noResults.className = 'no-results';
      noResults.innerHTML = `No hosts match <strong>${esc(term)}</strong>`;
      dashboard.appendChild(noResults);
    }
  } else if (noResults) {
    noResults.remove();
  }
}

function initSearch() {
  const input = document.getElementById('searchInput');
  if (!input) return;
  input.addEventListener('input', () => {
    searchTerm = input.value;
    applySearch();
  });
  // Clear on Escape
  input.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      input.value = '';
      searchTerm = '';
      applySearch();
      input.blur();
    }
  });
}

// ---------------------------------------------------------------------------
// Theme
// ---------------------------------------------------------------------------

(function initTheme() {
  const saved = pref('theme', localStorage.getItem('xpmon-theme') || 'dark');
  xpmonApplyTheme(saved);
})();

async function saveThemePreference(theme) {
  await xpmonSaveThemePreference(theme);
}

function updateThemeButton(theme) {
  xpmonUpdateThemeButton(theme);
}

// ---------------------------------------------------------------------------
// Profile modal
// ---------------------------------------------------------------------------

function applyPrefsToProfileForm() {
  const p = userPrefs();
  const themeEl = document.getElementById('prefTheme');
  if (themeEl) themeEl.value = p.theme || 'dark';
  const alertEl = document.getElementById('prefAlertMode');
  if (alertEl) alertEl.value = p.alert_mode || 'both';
  const ign = document.getElementById('prefShowIgnored');
  if (ign) ign.checked = p.show_ignored_services !== false;
  const door = document.getElementById('prefHideDoor');
  if (door) door.checked = !!p.hide_door;
  const upd = document.getElementById('prefHideWinUpdates');
  if (upd) upd.checked = !!p.hide_win_updates;
  applyForcedPrefControl('prefAlertModeRow', 'prefAlertMode', 'prefAlertModeLock', 'alert_mode');
  applyForcedPrefControl('prefShowIgnoredRow', 'prefShowIgnored', 'prefShowIgnoredLock', 'show_ignored_services');
  applyForcedPrefControl('prefHideDoorRow', 'prefHideDoor', 'prefHideDoorLock', 'hide_door');
  applyForcedPrefControl('prefHideWinUpdatesRow', 'prefHideWinUpdates', 'prefHideWinUpdatesLock', 'hide_win_updates');
  populateAlertHostsList();
}

function populateAlertHostsList() {
  const container = document.getElementById('prefAlertHostsList');
  if (!container) return;
  const p = userPrefs();
  const allMode = p.alert_hosts_all !== false;
  const selected = new Set(p.alert_hosts || []);
  const sorted = [...hosts.values()].sort((a, b) =>
    (a.display_name || '').localeCompare(b.display_name || '')
  );
  if (sorted.length === 0) {
    container.innerHTML = '<p class="hint">No hosts loaded yet.</p>';
    return;
  }
  container.innerHTML = sorted.map(h => {
    const checked = allMode || selected.has(h.id);
    return `<label class="checkbox-label alert-host-row">
      <input type="checkbox" value="${esc(h.id)}" ${checked ? 'checked' : ''}>
      <span>${esc(h.display_name)} <span class="muted">${esc(h.ip)}</span></span>
    </label>`;
  }).join('');
}

function collectAlertHostsFromForm() {
  const boxes = document.querySelectorAll('#prefAlertHostsList input[type="checkbox"]');
  const checked = [...boxes].filter(b => b.checked).map(b => b.value);
  if (boxes.length > 0 && checked.length === boxes.length) {
    return { alert_hosts_all: true, alert_hosts: [] };
  }
  return { alert_hosts_all: false, alert_hosts: checked };
}

document.getElementById('btnProfile')?.addEventListener('click', () => {
  applyPrefsToProfileForm();
  openModal('modalProfile');
});

document.getElementById('btnSaveProfile')?.addEventListener('click', async () => {
  const alertHostPrefs = collectAlertHostsFromForm();
  const forced = forcedPrefs();
  const payload = {
    action: 'save_prefs',
    theme: document.getElementById('prefTheme')?.value,
    alert_hosts_all: alertHostPrefs.alert_hosts_all,
    alert_hosts: alertHostPrefs.alert_hosts,
  };
  if (!forced.alert_mode) {
    payload.alert_mode = document.getElementById('prefAlertMode')?.value;
  }
  if (!forced.show_ignored_services) {
    payload.show_ignored_services = document.getElementById('prefShowIgnored')?.checked;
  }
  if (!forced.hide_door) {
    payload.hide_door = document.getElementById('prefHideDoor')?.checked;
  }
  if (!forced.hide_win_updates) {
    payload.hide_win_updates = document.getElementById('prefHideWinUpdates')?.checked;
  }
  try {
    const r = await fetch('api/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.ok) {
      if (d.prefs) XPMON_USER.prefs = d.prefs;
      else Object.assign(XPMON_USER.prefs, payload);
      if (d.forced_prefs) XPMON_USER.forced_prefs = d.forced_prefs;
      if (payload.theme) {
        xpmonApplyTheme(payload.theme);
      }
      renderAll();
      for (const h of hosts.values()) evaluateAlerts(h, h);
      toast('success', 'Profile saved');
      closeModal('modalProfile');
    } else {
      toast('error', d.error || 'Save failed');
    }
  } catch (e) {
    toast('error', 'Save failed');
  }
});

async function changePassword(current, newPass) {
  const r = await fetch('api/profile.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'change_password', current_password: current, new_password: newPass }),
  });
  return r.json();
}

document.getElementById('btnChangePassword')?.addEventListener('click', async () => {
  const d = await changePassword(
    document.getElementById('pwCurrent')?.value,
    document.getElementById('pwNew')?.value
  );
  if (d.ok) {
    toast('success', 'Password updated');
    document.getElementById('pwCurrent').value = '';
    document.getElementById('pwNew').value = '';
  } else toast('error', d.error || 'Password change failed');
});

document.getElementById('btnForcePassword')?.addEventListener('click', async () => {
  const d = await changePassword(
    document.getElementById('forcePwCurrent')?.value,
    document.getElementById('forcePwNew')?.value
  );
  if (d.ok) {
    toast('success', 'Password updated');
    closeModal('modalForcePassword');
    location.reload();
  } else toast('error', d.error || 'Password change failed');
});

if (document.getElementById('modalForcePassword')) {
  openModal('modalForcePassword');
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

initSearch();
wsConnect();