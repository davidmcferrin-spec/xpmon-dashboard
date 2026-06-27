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
  <title>Admin — XPression Monitor</title>
  <?php require __DIR__ . '/includes/theme_head.php'; ?>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <span class="topbar-logo"><a href="index.php" style="text-decoration:none;color:inherit">XP<span class="accent">MON</span></a></span>
    <nav class="topbar-nav">
      <a href="index.php" class="btn btn-sm btn-secondary">← Dashboard</a>
      <span class="btn btn-sm" style="cursor:default;opacity:0.6">Admin</span>
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
    <h2>Users</h2>
    <p class="hint">Local users need a password. LDAP users authenticate against Active Directory.</p>
    <div class="admin-toolbar">
      <button class="btn btn-sm" id="btnAddUser">+ Add User</button>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table" id="usersTable">
        <thead>
          <tr>
            <th>Username</th>
            <th>Type</th>
            <th>Roles</th>
            <th>Overrides</th>
            <th>Enabled</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="usersBody"></tbody>
      </table>
    </div>
  </section>

  <section class="admin-section">
    <h2>LDAP Settings</h2>
    <form id="ldapForm" class="admin-form">
      <label class="checkbox-label">
        <input type="checkbox" id="ldapEnabled">
        <span>Enable LDAP authentication</span>
      </label>
      <label>LDAP Host
        <input type="text" id="ldapHost" placeholder="ldaps://ad.example.com">
      </label>
      <label>Port
        <input type="number" id="ldapPort" value="636">
      </label>
      <label>Bind template <span class="hint-inline">use {username} — e.g. {username}@nexstar.tv</span>
        <input type="text" id="ldapBindTemplate" placeholder="{username}@nexstar.tv">
      </label>
      <label>Base DN <span class="hint-inline">optional — auto-derived from bind template if empty</span>
        <input type="text" id="ldapBaseDn" placeholder="DC=nexstar,DC=tv">
      </label>
      <label class="checkbox-label">
        <input type="checkbox" id="ldapIgnoreCert" checked>
        <span>Ignore SSL certificate errors</span>
      </label>
      <button type="submit" class="btn btn-sm">Save LDAP Settings</button>
    </form>
  </section>

  <section class="admin-section">
    <h2>LDAP Groups</h2>
    <p class="hint">Users in these AD groups can sign in without a pre-created account. Match by group CN or full DN.</p>
    <div class="admin-inline-form">
      <input type="text" id="newGroupName" placeholder="Group name (e.g. XPMon-Operators)">
      <select id="newGroupRoles" multiple size="3" title="Hover each role in the list for a description"></select>
      <button class="btn btn-sm" id="btnAddGroup">Add Group</button>
    </div>
    <ul class="admin-list" id="ldapGroupsList"></ul>
  </section>

  <section class="admin-section">
    <h2>Session Settings</h2>
    <p class="hint">Normal users are logged out after this many minutes of inactivity. Users with the <strong>Kiosk</strong> role stay signed in indefinitely (long-lived session cookie).</p>
    <form id="sessionForm" class="admin-form">
      <label>Session idle timeout (minutes)
        <input type="number" id="sessionIdleMinutes" min="5" max="1440" value="120">
      </label>
      <button type="submit" class="btn btn-sm">Save Session Settings</button>
    </form>
  </section>

  <section class="admin-section">
    <h2>Global Preferences</h2>
    <p class="hint">Force values override individual user settings. Leave force fields empty to allow user choice. Defaults apply when no force is set and the user has not chosen their own preference.</p>
    <form id="globalForm" class="admin-form">
      <div class="edit-section-title">Force (override all users)</div>
      <div class="admin-form-grid">
        <label>Force alert mode
          <select id="forceAlertMode">
            <option value="">— user choice —</option>
            <option value="none">None</option>
            <option value="flash">Flash only</option>
            <option value="horn">Horn only</option>
            <option value="both">Flash + Horn</option>
          </select>
        </label>
        <label>Force show ignored services
          <select id="forceShowIgnored">
            <option value="">— user choice —</option>
            <option value="1">Show</option>
            <option value="0">Hide</option>
          </select>
        </label>
        <label>Force hide door indicator
          <select id="forceHideDoor">
            <option value="">— user choice —</option>
            <option value="1">Hide</option>
            <option value="0">Show</option>
          </select>
        </label>
        <label>Force hide Windows Updates
          <select id="forceHideWinUpdates">
            <option value="">— user choice —</option>
            <option value="1">Hide</option>
            <option value="0">Show</option>
          </select>
        </label>
      </div>
      <div class="edit-section-title">Defaults (new users / unset prefs)</div>
      <div class="admin-form-grid">
        <label>Default alert mode
          <select id="defaultAlertMode">
            <option value="none">None</option>
            <option value="flash">Flash only</option>
            <option value="horn">Horn only</option>
            <option value="both">Flash + Horn</option>
          </select>
        </label>
        <label>Default show ignored services
          <select id="defaultShowIgnored">
            <option value="1">Show</option>
            <option value="0">Hide</option>
          </select>
        </label>
        <label>Default hide door indicator
          <select id="defaultHideDoor">
            <option value="0">Show</option>
            <option value="1">Hide</option>
          </select>
        </label>
        <label>Default hide Windows Updates
          <select id="defaultHideWinUpdates">
            <option value="0">Show</option>
            <option value="1">Hide</option>
          </select>
        </label>
      </div>
      <button type="submit" class="btn btn-sm">Save Global Settings</button>
    </form>
  </section>
</main>

<!-- User edit modal -->
<div class="modal-overlay" id="modalUser" hidden>
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2 id="userModalTitle">Edit User</h2>
      <button class="modal-close" data-modal="modalUser">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="userId">
      <label>Username
        <input type="text" id="userUsername">
      </label>
      <label>Account type
        <select id="userType">
          <option value="local">Local</option>
          <option value="ldap">LDAP</option>
        </select>
      </label>
      <label id="userPasswordRow">Password <span class="hint-inline">leave blank to keep unchanged</span>
        <input type="password" id="userPassword" autocomplete="new-password">
      </label>
      <label class="checkbox-label">
        <input type="checkbox" id="userEnabled" checked>
        <span>Account enabled</span>
      </label>
      <div class="edit-section-title">Roles</div>
      <p class="hint">Hover a role name for a summary of what it allows.</p>
      <div id="userRolesCheckboxes" class="checkbox-grid"></div>
      <div class="edit-section-title">Permission overrides</div>
      <p class="hint"><strong>Role default</strong> uses the selected roles. <strong>Grant</strong> forces allow; <strong>Deny</strong> forces block — even when roles disagree. Hover each permission for details.</p>
      <div class="perm-override-header">
        <span>Permission</span>
        <span>Override</span>
        <span title="Net result from selected roles plus overrides">Effective</span>
      </div>
      <div id="userPermOverrides" class="perm-override-grid"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger btn-sm" id="btnDeleteUser" style="margin-right:auto">Delete User</button>
      <button class="btn" id="btnSaveUser">Save</button>
      <button class="btn btn-secondary" data-modal="modalUser">Cancel</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
'use strict';

let adminData = null;

async function apiPost(action, payload = {}) {
  const r = await fetch('api/admin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...payload }),
  });
  return r.json();
}

async function loadAdmin() {
  const r = await fetch('api/admin.php');
  adminData = await r.json();
  if (!adminData.ok) { toast('error', adminData.error || 'Load failed'); return; }
  renderUsers();
  renderLdap();
  renderGroups();
  renderGlobal();
  populateRoleSelects();
}

function renderUsers() {
  const tbody = document.getElementById('usersBody');
  tbody.innerHTML = adminData.users.map(u => {
    const overrideCount = Object.keys(u.permission_overrides || {}).length;
    const overrideLabel = overrideCount
      ? `${overrideCount} override${overrideCount === 1 ? '' : 's'}`
      : '—';
    return `
    <tr>
      <td>${esc(u.username)}</td>
      <td>${esc(u.type || 'local')}</td>
      <td>${(u.roles || []).map(esc).join(', ')}</td>
      <td>${esc(overrideLabel)}</td>
      <td>${u.enabled ? 'Yes' : 'No'}</td>
      <td><button class="btn btn-sm btn-secondary" data-edit-user="${esc(u.id)}">Edit</button></td>
    </tr>`;
  }).join('');
  tbody.querySelectorAll('[data-edit-user]').forEach(btn => {
    btn.addEventListener('click', () => openUserModal(btn.dataset.editUser));
  });
}

function renderLdap() {
  const l = adminData.ldap;
  document.getElementById('ldapEnabled').checked = !!l.enabled;
  document.getElementById('ldapHost').value = l.host || '';
  document.getElementById('ldapPort').value = l.port || 636;
  document.getElementById('ldapBindTemplate').value = l.bind_template || '';
  document.getElementById('ldapBaseDn').value = l.base_dn || '';
  document.getElementById('ldapIgnoreCert').checked = l.ignore_cert !== false;
}

function renderGroups() {
  const list = document.getElementById('ldapGroupsList');
  const groups = adminData.ldap.allowed_groups || [];
  if (!groups.length) {
    list.innerHTML = '<li class="hint">No LDAP groups configured.</li>';
    return;
  }
  list.innerHTML = groups.map(g => {
    const name = typeof g === 'string' ? g : g.name;
    const roles = typeof g === 'string' ? ['viewer'] : (g.roles || []);
    return `<li class="admin-list-item">
      <span><strong>${esc(name)}</strong> → ${roles.map(esc).join(', ')}</span>
      <button class="btn btn-sm btn-danger" data-rm-group="${esc(name)}">Remove</button>
    </li>`;
  }).join('');
  list.querySelectorAll('[data-rm-group]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const d = await apiPost('remove_ldap_group', { name: btn.dataset.rmGroup });
      if (d.ok) { toast('success', 'Group removed'); loadAdmin(); }
      else toast('error', d.error);
    });
  });
}

function renderGlobal() {
  const g = adminData.global;
  document.getElementById('sessionIdleMinutes').value = g.session_idle_minutes ?? 120;
  document.getElementById('forceAlertMode').value = g.force_alert_mode ?? '';
  document.getElementById('forceShowIgnored').value = g.force_show_ignored_services === null ? '' : (g.force_show_ignored_services ? '1' : '0');
  document.getElementById('forceHideDoor').value = g.force_hide_door === null ? '' : (g.force_hide_door ? '1' : '0');
  document.getElementById('forceHideWinUpdates').value = g.force_hide_win_updates === null ? '' : (g.force_hide_win_updates ? '1' : '0');
  document.getElementById('defaultAlertMode').value = g.default_alert_mode ?? 'both';
  document.getElementById('defaultShowIgnored').value = (g.default_show_ignored_services !== false) ? '1' : '0';
  document.getElementById('defaultHideDoor').value = g.default_hide_door ? '1' : '0';
  document.getElementById('defaultHideWinUpdates').value = g.default_hide_win_updates ? '1' : '0';
}

function populateRoleSelects() {
  const sel = document.getElementById('newGroupRoles');
  sel.innerHTML = Object.entries(adminData.roles).map(([id, r]) =>
    `<option value="${esc(id)}" title="${esc(r.description || '')}" selected>${esc(r.label)}</option>`
  ).join('');
}

function rolePermissions(roleIds) {
  const merged = {};
  for (const perm of adminData.permissions) merged[perm] = false;
  for (const rid of roleIds) {
    const role = adminData.roles[rid];
    if (!role) continue;
    for (const [perm, val] of Object.entries(role.permissions || {})) {
      if (val) merged[perm] = true;
    }
  }
  return merged;
}

function effectivePermissionsForUser(roles, overrides) {
  const merged = rolePermissions(roles);
  for (const [perm, val] of Object.entries(overrides || {})) {
    if (adminData.permissions.includes(perm)) merged[perm] = !!val;
  }
  if (overrides?.execute_host_commands) {
    merged.execute_service_commands = true;
    merged.execute_reboot = true;
  }
  return merged;
}

function renderPermOverrides(user) {
  const box = document.getElementById('userPermOverrides');
  const roles = [...document.querySelectorAll('input[name=userRole]:checked')].map(c => c.value);
  const overrides = user.permission_overrides || {};
  const effective = effectivePermissionsForUser(roles, overrides);

  box.innerHTML = adminData.permissions.map(perm => {
    const val = overrides[perm];
    const mode = val === undefined ? 'inherit' : (val ? 'grant' : 'deny');
    const eff = effective[perm] ? 'Yes' : 'No';
    const meta = (adminData.permission_meta && adminData.permission_meta[perm]) || {};
    const label = meta.label || perm;
    const tip = meta.description || label;
    return `<div class="perm-override-row">
      <span class="perm-override-name" title="${esc(tip)}">${esc(label)}</span>
      <select class="perm-override-select" data-perm="${esc(perm)}" title="${esc(tip)}">
        <option value="inherit" ${mode === 'inherit' ? 'selected' : ''}>Role default</option>
        <option value="grant" ${mode === 'grant' ? 'selected' : ''}>Grant</option>
        <option value="deny" ${mode === 'deny' ? 'selected' : ''}>Deny</option>
      </select>
      <span class="perm-override-effective ${effective[perm] ? 'perm-yes' : 'perm-no'}" title="Effective: ${eff}">${eff}</span>
    </div>`;
  }).join('');

  box.querySelectorAll('.perm-override-select').forEach(sel => {
    sel.addEventListener('change', () => {
      renderPermOverrides({ permission_overrides: collectPermOverridesFromForm() });
    });
  });
}

function collectPermOverridesFromForm() {
  const overrides = {};
  document.querySelectorAll('.perm-override-select').forEach(sel => {
    const perm = sel.dataset.perm;
    if (sel.value === 'grant') overrides[perm] = true;
    else if (sel.value === 'deny') overrides[perm] = false;
  });
  return overrides;
}

function roleCheckboxHtml(id, role, checked) {
  const tip = esc(role.description || role.label || '');
  return `<label class="checkbox-label role-option" title="${tip}">
    <input type="checkbox" name="userRole" value="${esc(id)}" ${checked ? 'checked' : ''}>
    <span>${esc(role.label)}</span>
  </label>`;
}

function openUserModal(userId) {
  const isNew = !userId;
  document.getElementById('userModalTitle').textContent = isNew ? 'Add User' : 'Edit User';
  document.getElementById('userId').value = userId || '';
  document.getElementById('btnDeleteUser').style.display = isNew ? 'none' : '';

  const user = isNew ? { username: '', type: 'local', roles: ['viewer'], enabled: true, permission_overrides: {} }
    : adminData.users.find(u => u.id === userId);

  document.getElementById('userUsername').value = user.username || '';
  document.getElementById('userType').value = user.type || 'local';
  document.getElementById('userPassword').value = '';
  document.getElementById('userEnabled').checked = user.enabled !== false;
  togglePasswordRow();

  const rolesBox = document.getElementById('userRolesCheckboxes');
  rolesBox.innerHTML = Object.entries(adminData.roles).map(([id, r]) =>
    roleCheckboxHtml(id, r, (user.roles || []).includes(id))
  ).join('');
  rolesBox.querySelectorAll('input[name=userRole]').forEach(cb => {
    cb.addEventListener('change', () => {
      renderPermOverrides({ permission_overrides: collectPermOverridesFromForm() });
    });
  });

  renderPermOverrides(user);

  document.getElementById('modalUser').removeAttribute('hidden');
}

function togglePasswordRow() {
  const isLocal = document.getElementById('userType').value === 'local';
  document.getElementById('userPasswordRow').style.display = isLocal ? '' : 'none';
}

document.getElementById('userType').addEventListener('change', togglePasswordRow);
document.getElementById('btnAddUser').addEventListener('click', () => openUserModal(null));

document.getElementById('btnSaveUser').addEventListener('click', async () => {
  const roles = [...document.querySelectorAll('input[name=userRole]:checked')].map(c => c.value);
  const payload = {
    id: document.getElementById('userId').value || undefined,
    username: document.getElementById('userUsername').value.trim(),
    type: document.getElementById('userType').value,
    password: document.getElementById('userPassword').value,
    roles,
    enabled: document.getElementById('userEnabled').checked,
    permission_overrides: collectPermOverridesFromForm(),
  };
  const d = await apiPost('save_user', payload);
  if (d.ok) {
    toast('success', 'User saved');
    document.getElementById('modalUser').setAttribute('hidden', '');
    loadAdmin();
  } else toast('error', d.error);
});

document.getElementById('btnDeleteUser').addEventListener('click', async () => {
  const id = document.getElementById('userId').value;
  if (!id || !confirm('Delete this user?')) return;
  const d = await apiPost('delete_user', { id });
  if (d.ok) {
    toast('success', 'User deleted');
    document.getElementById('modalUser').setAttribute('hidden', '');
    loadAdmin();
  } else toast('error', d.error);
});

document.getElementById('ldapForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const d = await apiPost('save_ldap', {
    enabled: document.getElementById('ldapEnabled').checked,
    host: document.getElementById('ldapHost').value.trim(),
    port: parseInt(document.getElementById('ldapPort').value) || 636,
    bind_template: document.getElementById('ldapBindTemplate').value.trim(),
    base_dn: document.getElementById('ldapBaseDn').value.trim(),
    ignore_cert: document.getElementById('ldapIgnoreCert').checked,
  });
  if (d.ok) toast('success', 'LDAP settings saved');
  else toast('error', d.error);
});

document.getElementById('btnAddGroup').addEventListener('click', async () => {
  const name = document.getElementById('newGroupName').value.trim();
  const roles = [...document.getElementById('newGroupRoles').selectedOptions].map(o => o.value);
  const d = await apiPost('add_ldap_group', { name, roles });
  if (d.ok) {
    document.getElementById('newGroupName').value = '';
    toast('success', 'Group added');
    loadAdmin();
  } else toast('error', d.error);
});

document.getElementById('sessionForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const mins = parseInt(document.getElementById('sessionIdleMinutes').value, 10) || 120;
  const d = await apiPost('save_global', { session_idle_minutes: mins });
  if (d.ok) toast('success', 'Session settings saved');
  else toast('error', d.error);
});

document.getElementById('globalForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const parseForce = (id) => {
    const v = document.getElementById(id).value;
    return v === '' ? null : v === '1';
  };
  const d = await apiPost('save_global', {
    force_alert_mode: document.getElementById('forceAlertMode').value || null,
    force_show_ignored_services: parseForce('forceShowIgnored'),
    force_hide_door: parseForce('forceHideDoor'),
    force_hide_win_updates: parseForce('forceHideWinUpdates'),
    default_alert_mode: document.getElementById('defaultAlertMode').value,
    default_show_ignored_services: document.getElementById('defaultShowIgnored').value === '1',
    default_hide_door: document.getElementById('defaultHideDoor').value === '1',
    default_hide_win_updates: document.getElementById('defaultHideWinUpdates').value === '1',
  });
  if (d.ok) toast('success', 'Global settings saved');
  else toast('error', d.error);
});

document.querySelectorAll('[data-modal]').forEach(btn => {
  btn.addEventListener('click', () => document.getElementById(btn.dataset.modal).setAttribute('hidden', ''));
});

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

loadAdmin();
</script>
<script src="assets/theme.js"></script>
</body>
</html>
