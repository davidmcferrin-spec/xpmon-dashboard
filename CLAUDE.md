# CLAUDE.md — xpmon-dashboard

Project context and working instructions for Claude.

---

## What this project is

**xpmon-dashboard** is a self-hosted web dashboard for monitoring Ross Video XPression systems running the XPression Monitor service (TCP/9875). It replaces the native Windows `xpStatusClient.exe` with a browser-accessible dark-theme dashboard designed for broadcast control room use.

Built by David McFerrin, Director of Engineering at NewsNation (Nexstar Media Group), for internal use across multi-site newsroom XPression deployments.

---

## Architecture

```
XPression Servers (TCP/9875)
        │  persistent TCP, XML-over-binary-framing protocol
        ▼
bridge/xpmon_bridge.py       Python asyncio service
        │  WebSocket ws://host:8765
        ▼
public/index.php              PHP/Apache serves HTML shell
public/assets/app.js          JS connects directly to WS bridge
public/assets/style.css       Dark theme
public/bridge.php             Bridge admin page (log tail, start/stop/restart)
public/log.php                AJAX backend for bridge admin
```

## Stack

- **Backend bridge:** Python 3.10+, asyncio, `websockets>=12.0`
- **Frontend:** PHP 8.2 / Apache, vanilla JS (no framework), Tailwind-free custom CSS
- **Persistence:** `public/config.json` (flat JSON, rewritten by bridge on changes)
- **Service management:** systemd (`xpmon-bridge.service`)
- **Deployment path:** `/opt/xpmon-web/`

---

## Protocol (reverse-engineered from pcap)

Ross Video XPression Monitor TCP/9875 is a proprietary XML-over-TCP protocol.

### Wire framing
```
[uint32 LE total_len][uint32 LE payload_len][uint32 LE flags=0] + UTF-8 XML (CRLF)
total_len = payload_len + 8
```

### Handshake sequence
1. Server → Client: `serverinfo` (unsolicited on connect)
2. Client → Server: `login` (app name + session token)
3. Client → Server: `getinventory`, `diskstatus`, `getdoorconfig`
4. Server → Client: `inventory`, `ackdisks`, `doorconfig`

### Ongoing
- Server pushes `winupdate` unsolicited
- Bridge sends `getdoorconfig` every 20s as keepalive (server has ~30s idle timeout)
- Bridge sends `diskstatus` every 60s for disk refresh

### Control packets (client → server)
- `<packet type="kill" appid="Process.exe(PID)"/>` — stop one process
- `<packet type="start"><apps><app cmd="..." args="..."/></apps></packet>` — start stopped apps
- `<packet type="reboot"/>` — reboot Windows machine

### Stop All exclusions
Never kill `XPression Monitor` or `XPression Monitor Launcher` — these are the management layer needed to restart everything else.

---

## Key data model

### HostState (bridge/xpmon_bridge.py)
```python
id, display_name, ip, port, group        # config
connected, offline_since, last_seen      # live connection state
version, build, hostname, uid            # from serverinfo
door_detected, door_color                # chassis door (Windows COLORREF BGR)
win_updates, win_pending_restart         # Windows Update state
apps: list[AppEntry]                     # from inventory packet
disks: list[DiskEntry]                   # from ackdisks packet
critical_apps: list[str]                 # app key GUIDs that trigger alerts
canvas_enabled: bool                     # WSS Canvas links shown in UI
canvas_port: int                         # Canvas HTTP port (default 9056)
```

### AppEntry
```python
key           # stable GUID — use this for critical_apps, not name
name          # display name (e.g. "XPression Studio (64bit)")
version       # e.g. "12.6_6183"
appid         # live process instance "XPression.exe(8564)" — changes on restart/upgrade
startupcmd    # path used to launch (for start packet)
startupargs   # launch args
status        # 2=running, 0=stopped
ignore_status # bool — if true, status is cosmetic only
```

### config.json schema
```json
{
  "hosts": [{
    "id": "uuid",
    "display_name": "NN Project Server Primary",
    "ip": "10.70.4.84",
    "port": 9875,
    "group": "Ungrouped",
    "critical_apps": ["{GUID}", ...],
    "canvas_enabled": false,
    "canvas_port": 9056
  }]
}
```

---

## WebSocket message protocol (bridge ↔ browser)

### Bridge → Browser
| type | when | payload |
|------|------|---------|
| `snapshot` | on WS connect | `{ hosts: HostState[] }` |
| `host_update` | on state change | `{ host: HostState }` — only sent when content hash changes |
| `host_added` | after add_host | `{ host: HostState }` |
| `host_removed` | after remove_host | `{ id }` |
| `import_result` | after XCL import | `{ added: int }` |
| `alerts_updated` | after set_critical_apps | `{ id, critical_apps }` |
| `command_result` | after host_command | `{ id, command, ok, error? }` |

### Browser → Bridge
| action | payload |
|--------|---------|
| `add_host` | `{ host: { display_name, ip, port, group } }` |
| `remove_host` | `{ id }` |
| `edit_host` | `{ id, display_name, ip, port, group, canvas_enabled, canvas_port }` |
| `import_xcl` | `{ xml: string }` |
| `set_critical_apps` | `{ id, critical_apps: string[] }` |
| `host_command` | `{ id, command: "start"|"stop"|"reboot" }` |
| `update_group` | `{ id, group }` |

---

## Performance optimizations

### Bridge: diff-based broadcast
`broadcast_host` computes a `content_hash()` (SHA-1 of visible fields, excluding `last_seen`) before serializing. If the hash matches the last broadcast for that host, no JSON is generated and no WS message is sent. Keepalive `doorconfig` round-trips that produce no visible state change generate zero network traffic.

### Frontend: requestAnimationFrame render queue
`host_update` messages go into a `renderQueue` Set instead of triggering immediate DOM updates. A pending `requestAnimationFrame` callback drains the queue once per paint frame. If 10 hosts update in the same JS tick (e.g. mass reconnect), only one DOM pass happens instead of 10.

---

## Deployment

### Paths
- App root: `/opt/xpmon-web/`
- Bridge venv: `/opt/xpmon-web/venv/`
- Config: `/opt/xpmon-web/public/config.json`
- Service: `/etc/systemd/system/xpmon-bridge.service`

### Required sudoers (`/etc/sudoers.d/xpmon-bridge`)
```
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u xpmon-bridge *
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl start xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl stop xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl is-active xpmon-bridge
```

### Service management
```bash
sudo systemctl restart xpmon-bridge
journalctl -u xpmon-bridge -f
```

---

## Known limitations / TODO (from TODO file)

- No authentication (planned: local + LDAP, manually approved users/groups)
- Alerts are global — planned: per-user profile-based alerts (Flash/Horn/Both/None)
- No retry before marking a service down — false alarms possible during upgrades
- Light theme not yet implemented
- `Ignored` services visibility not user-configurable
- XCL file download from bridge not yet implemented
- Bridge shutdown waits for WS disconnect — should be faster/cleaner

---

## Development notes

- David prefers PHP/Apache/Python stacks — no Node.js, no containers
- No frontend framework — vanilla JS only
- `config.json` is the only persistence layer; swap to SQLite if host count exceeds ~200
- `app.js` references several `document.getElementById()` calls at parse time — every new modal added to `app.js` must have a matching element in `index.php` or the page will throw on load
- Always deliver `app.js` and `index.php` as a pair when adding modals
- Bridge runs as `www-data` — needs write access to `public/config.json`
- GitHub: `davidmcferrin-spec/xpmon-dashboard`