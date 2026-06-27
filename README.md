# xpmon-dashboard

Web-based status dashboard for Ross Video XPression Monitor servers. Replaces the native `xpStatusClient.exe` with a browser-accessible dashboard designed for broadcast control room use at NewsNation.

---

## Features

- **Live status** — persistent TCP connections to all XPression Monitor servers, real-time updates via WebSocket
- **App inventory** — per-host process list with running/stopped/ignored state
- **Disk monitoring** — per-drive usage bars with warn/critical thresholds
- **Windows Update indicator** — update count and pending restart flag
- **Door color** — chassis door color swatch from XPression hardware config
- **Host controls** — Start All Processes, Stop All Processes, Reboot Machine (with confirmation)
- **Alerts** — per-host critical app config; plays alert sound + flashes card on host offline or critical app stopped
- **WSS Canvas links** — direct links to XPression Canvas Outputs/Previews (configurable per host)
- **XCL import/export** — import host list from `StatusClientList.xcl`; export current list back to XCL
- **Bridge admin page** — live log tail, service start/stop/restart from the browser
- **Light/dark theme** — toggle in topbar, saved per browser
- **Host management** — add, edit, remove hosts; group organization; drag-and-drop XCL import
- **Authentication** — local + LDAP (LDAPS) login, role-based access, user profiles
- **Admin panel** — users, LDAP groups, global preference overrides

---

## Architecture

```
XPression Servers (TCP/9875)
        │
        ▼
bridge/xpmon_bridge.py    Python asyncio — one persistent task per host
        │  ws://host:8765
        ▼
public/                   PHP/Apache — serves dashboard HTML
  index.php               Dashboard
  bridge.php              Bridge admin (log tail + service controls)
  log.php                 AJAX endpoint for bridge admin
  xcl.php                 XCL export endpoint
  login.php               Login page
  admin.php               User / LDAP / global settings admin
  includes/auth.php       Session auth and roles
  api/profile.php         User profile preferences API
  api/admin.php           Admin API
  assets/app.js           WebSocket client + UI
  assets/style.css        Dark/light theme
  config.json             Host persistence (written by bridge)
data/
  auth.json               Users, LDAP config (auto-created on first login)
```

---

## Requirements

- Python 3.10+
- Apache 2.4+ with mod_php
- PHP 8.2+ with `ldap` extension (for LDAP login: `sudo apt install php-ldap`)
- Network access from bridge host to XPression servers on TCP/9875
- `www-data` sudoers entries for bridge admin (see below)

---

## Installation

### 1. Deploy files

```bash
sudo mkdir -p /opt/xpmon-web
sudo cp -r bridge/ public/ data/ /opt/xpmon-web/
sudo chown -R www-data:www-data /opt/xpmon-web
sudo chmod 750 /opt/xpmon-web/data
```

On first visit to the login page, `data/auth.json` is created with default credentials **`admin` / `admin`**. You will be prompted to change the password on first login.

### 2. Python virtual environment

```bash
cd /opt/xpmon-web
python3 -m venv venv
venv/bin/pip install -r bridge/requirements.txt
```

### 3. Apache virtual host

```apache
<VirtualHost *:80>
    ServerName xpmon.yourdomain.local
    DocumentRoot /opt/xpmon-web/public

    <Directory /opt/xpmon-web/public>
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    # If bridge runs on a different host than Apache:
    # SetEnv XPMON_WS_HOST 10.x.x.x

    ErrorLog  ${APACHE_LOG_DIR}/xpmon-error.log
    CustomLog ${APACHE_LOG_DIR}/xpmon-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite xpmon
sudo systemctl reload apache2
```

### 4. systemd service

```bash
sudo cp /opt/xpmon-web/bridge/xpmon-bridge.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable xpmon-bridge
sudo systemctl start xpmon-bridge
sudo systemctl status xpmon-bridge
```

### 5. File permissions

```bash
sudo chown www-data:www-data /opt/xpmon-web/public/config.json
sudo chmod 664 /opt/xpmon-web/public/config.json
sudo chown www-data:www-data /opt/xpmon-web/data
sudo chmod 750 /opt/xpmon-web/data
```

### 6. Authentication and roles

| Role | Dashboard | XCL export | Bridge log | Bridge control | Host mgmt | Host commands |
|------|-----------|------------|------------|----------------|-----------|---------------|
| admin | ✓ | ✓ | ✓ | ✓ | ✓ | view + execute |
| operator | ✓ | | | | | view + execute |
| control_viewer | ✓ | | | | | view only |
| bridge_monitor | ✓ | | ✓ | | | |
| viewer | ✓ | | | | | |

Users can hold multiple roles (permissions are combined). Configure users and LDAP in **Admin** after signing in.

LDAP uses LDAPS with user bind (`{username}` in bind template). AD group names can be mapped to roles for automatic access without pre-creating accounts.

**Note:** WebSocket (`:8765`) is not authenticated — UI and PHP endpoints enforce access. Restrict port 8765 at the firewall to trusted subnets.

### 7. Sudoers for bridge admin page

```bash
sudo visudo -f /etc/sudoers.d/xpmon-bridge
```

Add:
```
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u xpmon-bridge *
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl start xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl stop xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl is-active xpmon-bridge
```

Verify:
```bash
sudo visudo -c
sudo -u www-data sudo /usr/bin/systemctl is-active xpmon-bridge
```

---

## Host controls

Expand a host card to reveal controls. All destructive actions require confirmation.

| Button | Action |
|--------|--------|
| ✎ Edit | Edit config, alerts, Canvas settings, remove host |
| ▶ Start All | Start all stopped XPression processes |
| ■ Stop All | Kill running processes (excludes Monitor and Monitor Launcher) |
| ⟳ Reboot | Reboot the Windows machine |

---

## Alerts

Per-host alert config via **✎ Edit → 🔔 Configure Alerts**. Select which apps trigger an alert when stopped. Host going offline always triggers an alert regardless.

Effect: card flashes red + plays alert sound once. Clears automatically when condition resolves.

Retry logic: a host must miss `OFFLINE_MISS_LIMIT` consecutive watchdog checks (default 2, ~90s) before being marked offline — prevents false alarms during upgrades or brief network hiccups.

---

## Theme

Click ☀/🌙 in the topbar to toggle light/dark theme. Preference is saved per browser in `localStorage`.

---

## XCL export

Click **⬇ XCL** in the topbar to download the current host list as a native-compatible `StatusClientList.xcl` file, importable directly into XPression Status Client.

---

## Bridge admin

Access via **⚙ Bridge** in the topbar:
- Live journal log tail (color-coded, 50–500 lines, auto-scroll, pause)
- Service Start / Stop / Restart with confirmation
- Service status indicator (Running / Stopped)

---

## Protocol notes

Ross Video XPression Monitor TCP/9875 (reverse-engineered from pcap):

- **Framing:** 12-byte header `[uint32 total][uint32 payload][uint32 flags=0]` + UTF-8 XML
- **Server idle timeout:** ~30 seconds — bridge sends `getdoorconfig` keepalive every 20s
- **Door color:** Windows COLORREF (BGR), converted to RGB for display

| Direction | Packet | Description |
|-----------|--------|-------------|
| S→C | `serverinfo` | Identity, version, UID, door flag |
| C→S | `login` | App name + session token |
| C→S | `getinventory` | Request app list |
| S→C | `inventory` | Apps with status, startupcmd, appid |
| C→S | `diskstatus` | Request disk info |
| S→C | `ackdisks` | Per-drive free/total bytes |
| C→S | `getdoorconfig` | Request door color (also keepalive) |
| S→C | `doorconfig` | Door COLORREF + alert disabled |
| S→C | `winupdate` | Windows Update count + restart pending |
| C→S | `kill` | Kill one process by live appid |
| C→S | `start` | Start stopped processes |
| C→S | `reboot` | Reboot machine |

---

## Performance

- **Bridge broadcast:** SHA-1 content hash — no WS message sent when state unchanged. Keepalives generate zero traffic.
- **Smart dispatch:** inventory, disks, doorconfig, and winupdate only broadcast when values actually change.
- **24/7 supervision:** staggered connect/keepalive, task supervisor, health log every 5 min, watchdog force-reconnect on stale TCP.
- **Offline retry:** configurable miss counter (`OFFLINE_MISS_LIMIT`) before forcing reconnect.
- **Fast shutdown:** `SIGTERM` force-closes all connections; `systemctl stop` returns in <2s.
- **Frontend rendering:** `requestAnimationFrame` queue batches DOM updates to one pass per paint frame.
- **Capacity:** 40 hosts / 20 concurrent browser clients comfortably supported at current scale.

---

## Logs

```bash
journalctl -u xpmon-bridge -f
journalctl -u xpmon-bridge --since "1 hour ago"
```

---

## Firewall

- Bridge needs outbound TCP/9875 to all XPression servers
- Browsers need outbound TCP/8765 to the bridge host