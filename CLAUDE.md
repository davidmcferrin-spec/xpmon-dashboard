# CLAUDE.md — xpmon-dashboard

Project context and working instructions for Claude.

## What this project is

xpmon-dashboard is a self-hosted web dashboard for monitoring Ross Video XPression systems running the XPression Monitor service (TCP/9875). It replaces the native Windows xpStatusClient.exe with a browser-accessible dashboard designed for broadcast control room use.

Built by David McFerrin, Director of Engineering at NewsNation (Nexstar Media Group), for internal use across multi-site newsroom XPression deployments.

## Architecture

```
XPression Servers (TCP/9875)
        │ persistent TCP, XML-over-binary-framing protocol
        ▼
bridge/xpmon_bridge.py          Python asyncio service
        │ WebSocket ws://host:8765
        ▼
public/index.php                PHP/Apache serves HTML shell
public/assets/app.js            JS connects directly to WS bridge
public/assets/style.css         Dark/light theme (CSS variables)
public/bridge.php               Bridge admin page (log tail, start/stop/restart)
public/log.php                  AJAX backend for bridge admin
public/xcl.php                  XCL export — generates StatusClientList.xcl from config.json
public/includes/auth.php        Session auth, roles, LDAP, user prefs
public/api/profile.php          User profile preferences API
data/auth.json                  Users, LDAP config (not in git)
```

### Stack

- **Backend bridge:** Python 3.10+, asyncio, websockets>=12.0
- **Frontend:** PHP 8.2 / Apache, vanilla JS (no framework), CSS variables for theming
- **Persistence:** `public/config.json` (flat JSON, rewritten by bridge on changes; blocked from web via `.htaccess`)
- **Auth:** `data/auth.json` (local + LDAP, role-based permissions)
- **Service management:** systemd (`xpmon-bridge.service`)
- **Deployment path:** `/opt/xpmon-web/`
- **GitHub:** davidmcferrin-spec/xpmon-dashboard

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

Never kill **XPression Monitor** or **XPression Monitor Launcher** (case-insensitive) — these are the management layer needed to restart everything else.

## Key data model

### HostState (`bridge/xpmon_bridge.py`)

```python
id, display_name, ip, port, group          # config
hostname, reported_hostname                # XCL export fields (auto-persisted from serverinfo)
connected, checking, offline_since, last_seen # live connection state
_miss_count                                  # consecutive watchdog misses (not persisted)
version, build, hostname, uid                # from serverinfo
door_detected, door_color                    # chassis door (Windows COLORREF BGR)
win_updates, win_pending_restart             # Windows Update state
apps: list[AppEntry]                         # from inventory packet
disks: list[DiskEntry]                       # from ackdisks packet
critical_apps: list[str]                     # app key GUIDs that trigger alerts (global)
canvas_enabled: bool                         # WSS Canvas links shown in UI
canvas_port: int                             # Canvas HTTP port (default 9056)
```

### User prefs (`data/auth.json` per user)

```python
theme, alert_mode                            # display + how to alert
alert_hosts_all: bool                         # true (default) = all hosts; false = use alert_hosts list
alert_hosts: list[str]                       # host IDs when alert_hosts_all is false
user_critical_apps: dict[str, list[str]]     # per-user app alert filters by host id
show_ignored_services, hide_door, hide_win_updates
```

Global prefs in `data/auth.json`: `force_*` (override all users), `default_*` (when user has not set a pref). Session payload includes `forced_prefs` booleans so the profile modal can lock forced fields.

### config.json schema

```json
{
  "hosts": [{
    "id": "uuid",
    "display_name": "NN Project Server Primary",
    "ip": "10.70.4.84",
    "port": 9875,
    "group": "Ungrouped",
    "hostname": "10.70.4.84",
    "reported_hostname": "xpn50235291001",
    "critical_apps": ["{GUID}", "..."],
    "canvas_enabled": false,
    "canvas_port": 9056
  }]
}
```

## WebSocket message protocol (bridge ↔ browser)

### Bridge → Browser

| type | when | payload |
|------|------|---------|
| `snapshot` | on WS connect | `{ hosts: HostState[] }` |
| `host_update` | on state change | `{ host: HostState }` — only when content hash changes |
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
| `edit_host` | `{ id, display_name, ip, port, group, hostname, canvas_enabled, canvas_port }` |
| `import_xcl` | `{ xml: string }` |
| `set_critical_apps` | `{ id, critical_apps: string[] }` |
| `host_command` | `{ id, command: "start"\|"stop"\|"reboot" }` — requires `execute_service_commands` (start/stop) or `execute_reboot` (reboot) in UI |
| `update_group` | `{ id, group }` |

## Performance and robustness

- **Diff-based broadcast:** `content_hash()` skips unchanged host updates (keepalive round-trips produce zero WS traffic)
- **Offline retry:** `OFFLINE_MISS_LIMIT` (default 2) × `OFFLINE_GRACE` (45s) ≈ 90s before force reconnect; `checking: true` while `_miss_count > 0`
- **Config save lock:** `_config_lock` serializes all `_save_config()` writes
- **Auto-persist hostname metadata:** `reported_hostname` saved to config on first `serverinfo`
- **Fast shutdown:** SIGTERM force-closes WebSocket connections immediately
- **rAF render queue:** burst `host_update` messages batched to one DOM pass per frame

## Key constants (`bridge/xpmon_bridge.py`)

```python
OFFLINE_GRACE = 45           # seconds per watchdog interval
OFFLINE_MISS_LIMIT = 2       # misses before forcing reconnect
KEEPALIVE_INTERVAL = 20      # seconds between keepalive pings
DISK_POLL_INTERVAL = 60      # seconds between disk refreshes
APP_UPGRADE_GRACE = 90       # suppress critical-app alerts after version change
```

## Deployment

- **App root:** `/opt/xpmon-web/`
- **Bridge venv:** `/opt/xpmon-web/venv/`
- **Config:** `/opt/xpmon-web/public/config.json` (deny web access via `public/.htaccess`)
- **Auth:** `/opt/xpmon-web/data/auth.json`
- **Service:** `/etc/systemd/system/xpmon-bridge.service`

### Required sudoers (`/etc/sudoers.d/xpmon-bridge`)

```
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u xpmon-bridge *
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl start xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl stop xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart xpmon-bridge
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl is-active xpmon-bridge
```

## Built-in roles

Defined in `public/includes/auth.php` as `DEFAULT_ROLES`. Permissions from multiple roles are combined (OR). Hover role names in Admin for full descriptions.

| Role ID | Label | Host commands |
|---------|-------|---------------|
| `admin` | Administrator | view + start/stop + reboot (+ full admin) |
| `operator` | Operator | view + start/stop only |
| `operator_reboot` | Operator+Reboot | view + start/stop + reboot |
| `control_viewer` | Control Viewer | view only (buttons shown, disabled) |
| `bridge_monitor` | Bridge Monitor | none (bridge log only) |
| `viewer` | Viewer | none |
| `kiosk` | Kiosk | none (wall display, no idle timeout) |

Per-user **permission overrides** can grant or deny individual permissions regardless of role.

## Open TODO items

- **WebSocket token auth** — PHP issues HMAC token; bridge validates on connect (control plane currently unauthenticated)

## Completed items

- Auth (local + LDAP, roles, admin UI, profile prefs)
- Admin UI: permission overrides per user, default global prefs, forced-pref lock in profile modal
- Profile-based alerts (`alert_hosts`, `user_critical_apps`, `alert_mode`)
- XCL export/import with hostname fidelity
- Bridge 24/7 robustness (watchdog, task supervisor, diff broadcast, config lock)
- Fast shutdown, light/dark theme, retry before offline, CHECKING degraded state

## Development notes

- David prefers PHP/Apache/Python stacks — no Node.js, no containers
- No frontend framework — vanilla JS only
- `config.json` is the only host persistence layer
- **Critical:** `app.js` calls `document.getElementById()` at parse time for modal buttons. Any new modal element in `app.js` must have a matching `id` in `index.php`. Always deliver both as a pair.
- Bridge runs as `www-data` — needs write access to `public/config.json`
- Always pull current files from GitHub before patching — local copies drift from repo
- When patching Python, watch for indentation errors on `async def` block boundaries
