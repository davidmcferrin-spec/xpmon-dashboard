<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_users');

$user = session_user_payload_full();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Audit Log — XPression Monitor</title>
  <?php require __DIR__ . '/includes/theme_head.php'; ?>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <span class="topbar-logo"><a href="index.php" style="text-decoration:none;color:inherit">XP<span class="accent">MON</span></a></span>
    <nav class="topbar-nav">
      <a href="index.php" class="btn btn-sm btn-secondary">← Dashboard</a>
      <a href="admin.php" class="btn btn-sm btn-secondary">Admin</a>
      <span class="btn btn-sm" style="cursor:default;opacity:0.6">Audit Log</span>
    </nav>
  </div>
  <div class="topbar-right">
    <span class="topbar-user"><?= htmlspecialchars($user['username']) ?></span>
    <button class="btn btn-sm btn-secondary" id="btnTheme" title="Toggle light/dark theme">🌙</button>
    <a href="logout.php" class="btn btn-sm btn-secondary">Logout</a>
  </div>
</header>

<main class="admin-page">
  <section class="admin-section">
    <h2>Audit Log</h2>
    <p class="hint">Who issued host commands (start/stop/reboot) and bridge service control, from which IP. Newest entries first. Log file rotates at 10&nbsp;MB.</p>
    <div class="admin-toolbar">
      <label class="audit-limit-label">Show
        <select id="auditLimit">
          <option value="100">100</option>
          <option value="200" selected>200</option>
          <option value="500">500</option>
        </select>
        entries
      </label>
      <button class="btn btn-sm btn-secondary" id="btnRefreshAudit">Refresh</button>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table audit-table">
        <thead>
          <tr>
            <th>Time (UTC)</th>
            <th>User</th>
            <th>IP</th>
            <th>Action</th>
            <th>Detail</th>
            <th>Result</th>
          </tr>
        </thead>
        <tbody id="auditBody">
          <tr><td colspan="6" class="hint">Loading…</td></tr>
        </tbody>
      </table>
    </div>
    <p class="hint" id="auditMeta"></p>
  </section>
</main>

<div class="toast-container" id="toastContainer"></div>

<script>
'use strict';

const ACTION_LABELS = {
  host_command: 'Host command',
  host_command_result: 'Command result',
  bridge_control: 'Bridge service',
};

function formatDetail(e) {
  if (e.action === 'host_command' || e.action === 'host_command_result') {
    const cmd = e.command || '?';
    const name = e.host_name || e.host_id || '?';
    const ip = e.host_ip || '';
    return ip ? `${cmd} → ${name} (${ip})` : `${cmd} → ${name}`;
  }
  if (e.action === 'bridge_control') return e.command || '';
  return '';
}

function formatResult(e) {
  if (e.action === 'host_command') return '—';
  if (e.ok === true) return 'OK';
  if (e.ok === false) return e.error ? `Failed: ${e.error}` : 'Failed';
  return '—';
}

function formatTs(ts) {
  if (!ts) return '—';
  try {
    const d = new Date(ts);
    return d.toISOString().replace('T', ' ').replace(/\.\d{3}Z$/, ' UTC');
  } catch {
    return ts;
  }
}

async function loadAudit() {
  const limit = document.getElementById('auditLimit').value;
  const tbody = document.getElementById('auditBody');
  const meta = document.getElementById('auditMeta');
  tbody.innerHTML = '<tr><td colspan="6" class="hint">Loading…</td></tr>';

  try {
    const r = await fetch(`api/audit.php?limit=${encodeURIComponent(limit)}`);
    const d = await r.json();
    if (!d.ok) {
      tbody.innerHTML = `<tr><td colspan="6" class="hint">${esc(d.error || 'Load failed')}</td></tr>`;
      return;
    }
    if (!d.entries.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="hint">No audit entries yet.</td></tr>';
      meta.textContent = '';
      return;
    }
    tbody.innerHTML = d.entries.map(e => `
      <tr>
        <td class="audit-ts">${esc(formatTs(e.ts))}</td>
        <td>${esc(e.username || '—')}</td>
        <td class="audit-ip">${esc(e.ip || '—')}</td>
        <td>${esc(ACTION_LABELS[e.action] || e.action || '—')}</td>
        <td>${esc(formatDetail(e))}</td>
        <td class="${e.ok === true ? 'audit-ok' : e.ok === false ? 'audit-fail' : ''}">${esc(formatResult(e))}</td>
      </tr>
    `).join('');
    meta.textContent = `Showing ${d.entries.length} of ${d.total} entries in current log.`;
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" class="hint">Error: ${esc(err.message)}</td></tr>`;
  }
}

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
}

function toast(type, msg) {
  const c = document.getElementById('toastContainer');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  c.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

document.getElementById('btnRefreshAudit').addEventListener('click', loadAudit);
document.getElementById('auditLimit').addEventListener('change', loadAudit);
loadAudit();
</script>
<script src="assets/theme.js"></script>
</body>
</html>
