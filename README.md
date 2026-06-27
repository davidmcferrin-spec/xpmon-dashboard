# xpmon-dashboard

Web-based status dashboard for Ross Video XPression Monitor servers. Replaces the native `xpStatusClient.exe` with a browser-accessible dark-theme dashboard designed for broadcast control room use at NewsNation.

---

## Features

- **Live status** — persistent TCP connections to all XPression Monitor servers, real-time updates via WebSocket
- **App inventory** — per-host process list with running/stopped/ignored state
- **Disk monitoring** — per-drive usage bars with warn/critical thresholds
- **Windows Update indicator** — update count and pending restart flag
- **Door color** — chassis door color swatch from XPression hardware config
- **Host controls** — Start All Processes, Stop All Processes, Reboot Machine (with confirmation)
- **Alerts** — per-host critical app configuration; plays alert sound + flashes card on host offline or critical app stopped
- **WSS Canvas links** — direct links to XPression Canvas Outputs/Previews (configurable per host)
- **XCL import** — import host list directly from `StatusClientList.xcl` exported by XPression Status Client
- **Bridge admin page** — live log tail, service start/stop/restart from the browser
- **Host management** — add, edit, remove hosts; group organization; drag-and-drop XCL import

---

## Architecture

```
XPression Servers (TCP/9875)
        │
        ▼
bridge/xpmon_bridge.py    Python asyncio — one task per host
        │  ws://host:8765
        ▼
public/                   PHP/Apache — serves dashboard HTML
  index.php               Dashboard
  bridge.php              Bridge admin (log tail + service controls)
  log.php                 AJAX endpoint for bridge admin
  assets/app.js           WebSocket client + UI
  assets/style.css        Dark theme
  config.json             Host persistence (written by bridge)
```

---

## Requirements

- Python 3.10+
- Apache 2.4+ with mod_php
- PHP 8.2+
- Network access from bridge host to XPression servers on TCP/9875
- www-data sudoers entries for bridge admin (see below)

---

## Installation

### 1. Deploy files

```bash
sudo mkdir -p /opt/xpmon-web
sudo cp -r bridge/ public/ /opt/xpmon-web/
sudo chown -R www-data:www-data /opt/xpmon-web
```

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

    ErrorLog  ${APACHE_LOG_DIR}/xpmon-error.log
    CustomLog ${APACHE_LOG_DIR}/xpmon-access.log combined
</VirtualHost>
```

### 4. systemd service

```bash
sudo cp /opt/xpmon-web/bridge/xpmon-bridge.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable xpmon-bridge
sudo systemctl start xpmon-bridge
```

### 5. File permissions

```bash
sudo chown www-data:www-data /opt/xpmon-web/public/config.json
sudo chmod 664 /opt/xpmon-web/public/config.json
```

### 6. Sudoers for bridge admin page

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

---

## Host controls

Expand a host card to access controls via the ✎ Edit button:

| Button | Action |
|--------|--------|
| ✎ Edit | Edit config, alerts, Canvas settings, remove host |
| ▶ Start All | Start all stopped XPression processes |
| ■ Stop All | Kill running processes (excludes Monitor and Monitor Launcher) |
| ⟳ Reboot | Reboot the Windows machine |

---

## Protocol notes

Ross Video XPression Monitor TCP/9875 (reverse-engineered from pcap):

- **Framing:** 12-byte header `[uint32 total][uint32 payload][uint32 flags=0]` + UTF-8 XML
- **Server idle timeout:** ~30 seconds — bridge sends keepalive every 20s
- **Door color:** Windows COLORREF (BGR), converted to RGB for display

---

## Performance

- **Bridge:** diff-based broadcast — SHA-1 hash gates serialization; keepalives generate zero WS traffic
- **Frontend:** requestAnimationFrame render queue — batches DOM updates to one pass per paint frame
- **Capacity:** 40 hosts / 20 concurrent browser clients comfortably supported

---

## Logs

```bash
journalctl -u xpmon-bridge -f
```