#!/usr/bin/env python3
"""
xpmon_bridge.py — XPression Monitor WebSocket Bridge
Maintains persistent TCP connections to XPression Monitor servers (port 9875),
parses their XML status protocol, and broadcasts state to WebSocket clients.

Protocol summary (from wire analysis):
  Frame: [uint32 total_len][uint32 payload_len][uint32 flags=0] + XML payload (CRLF)
  Handshake: server sends serverinfo → client sends login → client sends getinventory,
             diskstatus, getdoorconfig → server responds with inventory, ackdisks, doorconfig
  Ongoing: server sends winupdate unsolicited; client polls diskstatus periodically
"""

import asyncio
import hashlib
import json
import logging
import os
import random
import signal
import socket
import struct
import time
import uuid
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field, asdict, replace
from pathlib import Path
from typing import Optional
from xml.sax.saxutils import quoteattr

import websockets
from websockets.server import WebSocketServerProtocol

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

CONFIG_PATH = Path(__file__).parent.parent / "public" / "config.json"
WS_HOST = "0.0.0.0"
WS_PORT = 8765
OFFLINE_GRACE = 45          # seconds of silence per watchdog check
OFFLINE_MISS_LIMIT = 2      # consecutive misses before forcing reconnect
KEEPALIVE_INTERVAL = 20     # seconds between keepalive pings (must be < server idle timeout ~30s)
DISK_POLL_INTERVAL = 300      # seconds between diskstatus polls (5 min)
INVENTORY_POLL_INTERVAL = 20  # seconds between getinventory polls (app status refresh)
APP_UPGRADE_GRACE = 90      # seconds to suppress critical-app alerts after version/key change
RECONNECT_DELAY_MIN = 5     # seconds
RECONNECT_DELAY_MAX = 60    # seconds (exponential backoff cap)
STARTUP_STAGGER_MAX = 5.0   # max random delay before first connect (spread load on bridge restart)
HEALTH_LOG_INTERVAL = 300   # seconds between periodic health summaries
TASK_SUPERVISOR_INTERVAL = 60  # seconds between dead-task checks
WS_MAX_MESSAGE_SIZE = 1_048_576
_MAX_FRAME_PAYLOAD = 1_048_576   # hard ceiling for TCP frame payloads (read + parse)
_LARGE_PAYLOAD_WARN = 65_536     # log anomalies above typical XPression packet sizes
WS_PING_INTERVAL = 30
WS_PING_TIMEOUT = 10
APPNAME = "xpStatusClient"

# Never kill these when Stop All is requested — needed to restart everything else
STOP_EXCLUDE_NAMES = frozenset({
    "XPression Monitor",
    "XPression Monitor Launcher",
})
STOP_EXCLUDE_NAMES_LOWER = frozenset(n.lower() for n in STOP_EXCLUDE_NAMES)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("xpmon_bridge")

# ---------------------------------------------------------------------------
# Data model
# ---------------------------------------------------------------------------

@dataclass
class AppEntry:
    key: str
    name: str
    version: str
    folder: str
    process: str
    startupcmd: str
    startupargs: str
    appid: str           # live process instance e.g. "XPression.exe(8564)"
    status: int          # 0=stopped, 2=running
    heartbeat: int
    last_heartbeat: int
    ignore_status: bool

@dataclass
class DiskEntry:
    label: str
    free_bytes: int
    total_bytes: int

@dataclass
class HostState:
    id: str
    display_name: str
    ip: str
    port: int
    group: str
    # Live state
    connected: bool = False
    offline_since: Optional[float] = None
    version: str = ""
    build: str = ""
    hostname: str = ""
    reported_hostname: str = ""
    uid: str = ""
    door_detected: bool = False
    door_color: int = 0
    win_updates: int = 0
    win_pending_restart: bool = False
    apps: list = field(default_factory=list)
    disks: list = field(default_factory=list)
    last_seen: float = 0.0
    critical_apps: list = field(default_factory=list)
    _miss_count: int = 0  # consecutive watchdog misses (not persisted)
    _upgrade_grace: dict = field(default_factory=dict)  # identity_key → expiry unix time
    canvas_enabled: bool = False
    canvas_port: int = 9056

    def to_dict(self) -> dict:
        d = asdict(self)
        d.pop("_miss_count", None)
        d.pop("_upgrade_grace", None)
        d["checking"] = self.connected and self._miss_count > 0
        now = time.time()
        d["upgrade_grace"] = {
            k: v for k, v in self._upgrade_grace.items() if v > now
        }
        d["apps"] = [asdict(a) for a in self.apps]
        d["disks"] = [asdict(dk) for dk in self.disks]
        return d

    def content_hash(self) -> str:
        """SHA-1 of visible state — excludes last_seen and internal counters."""
        payload = {
            "connected": self.connected,
            "checking": self.connected and self._miss_count > 0,
            "offline_since": self.offline_since,
            "version": self.version,
            "build": self.build,
            "hostname": self.hostname,
            "reported_hostname": self.reported_hostname,
            "uid": self.uid,
            "door_detected": self.door_detected,
            "door_color": self.door_color,
            "win_updates": self.win_updates,
            "win_pending_restart": self.win_pending_restart,
            "critical_apps": self.critical_apps,
            "canvas_enabled": self.canvas_enabled,
            "canvas_port": self.canvas_port,
            "display_name": self.display_name,
            "ip": self.ip,
            "port": self.port,
            "group": self.group,
            "apps": [
                (a.key, a.name, a.version, a.process, a.status,
                 a.appid, a.ignore_status, a.startupcmd, a.startupargs)
                for a in self.apps
            ],
            "disks": [(d.label, d.free_bytes, d.total_bytes) for d in self.disks],
            "upgrade_grace": sorted(
                (k, v) for k, v in self._upgrade_grace.items() if v > time.time()
            ),
        }
        raw = json.dumps(payload, sort_keys=True, separators=(",", ":"))
        return hashlib.sha1(raw.encode()).hexdigest()

# ---------------------------------------------------------------------------
# Protocol helpers
# ---------------------------------------------------------------------------

FRAME_HEADER = struct.Struct("<III")  # total_len, payload_len, flags

PACKET_GETINVENTORY = '<packet type="getinventory"/>'
PACKET_DISKSTATUS   = '<packet type="diskstatus"/>'
PACKET_DOORCONFIG   = '<packet type="getdoorconfig"/>'
PACKET_REBOOT       = '<packet type="reboot"/>'

def build_frame(xml: str) -> bytes:
    payload = xml.encode("utf-8")
    plen = len(payload)
    header = FRAME_HEADER.pack(plen + 8, plen, 0)
    return header + payload

def make_login_packet() -> str:
    token = uuid.uuid4().hex.upper()[:32]
    return (
        f'<packet type="login" subtype="{token}">\r\n'
        f'<appname>{APPNAME}</appname>\r\n'
        f'<statusclient>1</statusclient>\r\n'
        f'</packet>'
    )

_XML_PARSE_CAP = 65_536

def parse_xml_packet(data: bytes) -> Optional[ET.Element]:
    """Find and parse the XML portion of a frame payload.

    Caller must bound `data` size (see _MAX_FRAME_PAYLOAD in _read_packet).
    """
    idx = data.find(b'<packet')
    if idx < 0:
        return None
    try:
        return ET.fromstring(data[idx:idx + _XML_PARSE_CAP].decode("utf-8", errors="replace"))
    except ET.ParseError:
        return None

def _inventory_text(elem: ET.Element, name: str) -> str:
    """Read an inventory field from an XML attribute or child element."""
    val = (elem.get(name) or "").strip()
    if val:
        return val
    child = elem.find(name)
    if child is not None and child.text:
        return child.text.strip()
    return ""


def _is_abs_windows_path(path: str) -> bool:
    if not path:
        return False
    if path.startswith("\\\\"):
        return True
    return len(path) >= 2 and path[1] == ":"


def _join_windows_path(folder: str, name: str) -> str:
    return folder.rstrip("\\/") + "\\" + name.lstrip("\\/")


def _resolve_startup_cmd(app: AppEntry) -> str:
    """Return the launch command path the monitor expects in a start packet."""
    cmd = (app.startupcmd or "").strip()
    if not cmd:
        if app.folder and app.process:
            return _join_windows_path(app.folder, app.process)
        return ""
    if _is_abs_windows_path(cmd):
        return cmd
    if app.folder:
        return _join_windows_path(app.folder, cmd)
    if app.process and cmd.lower() == app.process.lower():
        return cmd
    return cmd


def _build_start_packet(apps: list[tuple[str, str]]) -> str:
    """Build a start-all packet. Each item is (cmd, args); omit args when empty."""
    app_lines: list[str] = []
    for cmd, args in apps:
        if args:
            app_lines.append(
                f'  <app cmd={quoteattr(cmd)} args={quoteattr(args)}/>'
            )
        else:
            app_lines.append(f'  <app cmd={quoteattr(cmd)}/>')
    body = "\r\n".join(app_lines)
    return (
        f'<packet type="start">\r\n<apps>\r\n{body}\r\n'
        f'</apps>\r\n</packet>'
    )


def _apps_snapshot(apps: list) -> tuple:
    return tuple(
        (a.key, a.status, a.process, a.version, a.ignore_status, a.appid)
        for a in apps
    )

def app_identity(app: AppEntry) -> tuple[str, str]:
    return (app.name.strip().lower(), app.process.strip().lower())

def identity_key(ident: tuple[str, str]) -> str:
    return f"{ident[0]}|{ident[1]}"

def _version_sort_key(version: str) -> tuple:
    """Best-effort sort key for version strings like 12.6_6183."""
    parts = []
    for chunk in version.replace("_", ".").split("."):
        try:
            parts.append((0, int(chunk)))
        except ValueError:
            parts.append((1, chunk))
    return tuple(parts)

def _pick_inventory_row(group: list[AppEntry]) -> AppEntry:
    """Prefer running row, then row with a usable startup command, then newest version."""
    if len(group) == 1:
        return group[0]

    candidates = [a for a in group if a.status == 2 and not a.ignore_status]
    pool = candidates if candidates else group

    def row_rank(app: AppEntry) -> tuple:
        resolved = _resolve_startup_cmd(app)
        return (
            bool(resolved and _is_abs_windows_path(resolved)),
            bool(resolved),
            _version_sort_key(app.version),
        )

    chosen = max(pool, key=row_rank)
    if not chosen.startupcmd:
        for app in pool:
            if app.startupcmd:
                chosen = replace(
                    chosen,
                    startupcmd=app.startupcmd,
                    startupargs=app.startupargs or chosen.startupargs,
                )
                break
    return chosen


def collapse_inventory_apps(apps: list) -> list[AppEntry]:
    """One row per logical app (name+process); prefer running, then newest version."""
    groups: dict[tuple[str, str], list[AppEntry]] = {}
    for app in apps:
        groups.setdefault(app_identity(app), []).append(app)

    return [_pick_inventory_row(group) for group in groups.values()]

def migrate_critical_apps(
    critical_apps: list[str],
    old_apps: list[AppEntry],
    new_apps: list[AppEntry],
) -> tuple[list[str], bool]:
    """Remap critical-app GUIDs when inventory keys change after an upgrade."""
    if not critical_apps:
        return critical_apps, False

    old_by_key = {a.key: a for a in old_apps if a.key}
    new_by_key = {a.key: a for a in new_apps if a.key}
    new_by_identity: dict[tuple[str, str], AppEntry] = {}
    for app in new_apps:
        ident = app_identity(app)
        existing = new_by_identity.get(ident)
        if existing is None or (app.status == 2 and existing.status != 2):
            new_by_identity[ident] = app

    migrated: list[str] = []
    seen: set[str] = set()
    changed = False

    for key in critical_apps:
        if key in new_by_key:
            if key not in seen:
                migrated.append(key)
                seen.add(key)
            continue

        old_app = old_by_key.get(key)
        if old_app is None:
            changed = True
            continue

        match = new_by_identity.get(app_identity(old_app))
        if match and match.key not in seen:
            migrated.append(match.key)
            seen.add(match.key)
            changed = True
        else:
            changed = True

    if migrated != critical_apps:
        changed = True
    return migrated, changed

def _disks_snapshot(disks: list) -> tuple:
    return tuple((d.label, d.free_bytes, d.total_bytes) for d in disks)

def _configure_tcp_socket(writer: asyncio.StreamWriter) -> None:
    sock = writer.get_extra_info("socket")
    if sock is not None:
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)

def _loop_exception_handler(loop: asyncio.AbstractEventLoop, context: dict) -> None:
    exc = context.get("exception")
    msg = context.get("message", "asyncio error")
    if exc:
        log.error("%s", msg, exc_info=exc)
    else:
        log.error("asyncio: %s — %s", msg, context)

# ---------------------------------------------------------------------------
# TCP connection handler per host
# ---------------------------------------------------------------------------

class XPresMonClient:
    def __init__(
        self,
        state: HostState,
        bridge: "XPresMonBridge",
        *,
        startup_delay: float = 0.0,
        keepalive_offset: float = 0.0,
    ):
        self.state = state
        self.bridge = bridge
        self._startup_delay = startup_delay
        self._keepalive_offset = keepalive_offset
        self._task: Optional[asyncio.Task] = None
        self._running = False
        self._reader: Optional[asyncio.StreamReader] = None
        self._writer: Optional[asyncio.StreamWriter] = None
        self._command_lock = asyncio.Lock()
        self._pending_critical_migrated = False
        self._pending_upgrade_events: list[dict] = []

    def start(self):
        if self._task and not self._task.done():
            return
        self._running = True
        self._task = asyncio.create_task(self._run_loop(), name=f"xpmon-{self.state.id}")

    def stop(self):
        self._running = False
        if self._task and not self._task.done():
            self._task.cancel()
        asyncio.create_task(self._close_connection())

    async def force_reconnect(self):
        """Tear down a stale TCP session so _run_loop reconnects cleanly."""
        log.warning("[%s] forcing reconnect (watchdog)", self.state.display_name)
        self.state._miss_count = 0
        await self._close_connection()

    async def _close_connection(self):
        self._reader = None
        writer = self._writer
        self._writer = None
        if writer is None or writer.is_closing():
            return
        try:
            writer.close()
            await writer.wait_closed()
        except Exception:
            pass

    async def _run_loop(self):
        if self._startup_delay > 0:
            await asyncio.sleep(self._startup_delay)

        delay = RECONNECT_DELAY_MIN
        while self._running:
            try:
                await self._connect_and_poll()
                delay = RECONNECT_DELAY_MIN
            except asyncio.CancelledError:
                break
            except Exception as e:
                log.warning("[%s] connection error: %s", self.state.display_name, e)

            if not self._running:
                break

            await self._mark_offline()
            log.info("[%s] reconnecting in %ss", self.state.display_name, delay)
            await asyncio.sleep(delay)
            delay = min(delay * 2, RECONNECT_DELAY_MAX)

        await self._close_connection()

    async def _connect_and_poll(self):
        log.info("[%s] connecting to %s:%s", self.state.display_name, self.state.ip, self.state.port)
        self._reader, self._writer = await asyncio.wait_for(
            asyncio.open_connection(self.state.ip, self.state.port),
            timeout=10,
        )
        _configure_tcp_socket(self._writer)

        pkt = await self._read_packet(timeout=15)
        if pkt is None or pkt.get("type") != "serverinfo":
            raise ConnectionError("Expected serverinfo, got nothing or wrong packet")
        if self._handle_serverinfo(pkt):
            await self.bridge.broadcast_host(self.state)
        await self.bridge.maybe_persist_hostname(self.state)

        await self._send(make_login_packet())
        await self._send(PACKET_GETINVENTORY)
        await self._send(PACKET_DISKSTATUS)
        await self._send(PACKET_DOORCONFIG)

        self.state.connected = True
        self.state.offline_since = None
        self.state.last_seen = time.time()
        self.state._miss_count = 0
        await self.bridge.broadcast_host(self.state)

        keepalive_task = asyncio.create_task(self._keepalive_loop())
        try:
            await self._read_loop()
        finally:
            keepalive_task.cancel()
            try:
                await keepalive_task
            except asyncio.CancelledError:
                pass
            await self._close_connection()

    async def _read_loop(self):
        read_timeout = KEEPALIVE_INTERVAL * 3
        while self._running:
            pkt = await self._read_packet(timeout=read_timeout)
            if pkt is None:
                raise ConnectionError("Connection closed by server")
            self.state.last_seen = time.time()
            if self.state._miss_count > 0:
                self.state._miss_count = 0
                await self.bridge.broadcast_host(self.state)
            changed = self._dispatch(pkt)
            if changed:
                await self.bridge.broadcast_host(self.state)
            if self._pending_upgrade_events:
                events = self._pending_upgrade_events
                self._pending_upgrade_events = []
                await self.bridge.broadcast_app_upgrades(self.state.id, events)
            if self._pending_critical_migrated:
                self._pending_critical_migrated = False
                await self.bridge.on_critical_apps_migrated(self.state)

    async def _keepalive_loop(self):
        elapsed = self._keepalive_offset
        if self._keepalive_offset > 0:
            await asyncio.sleep(self._keepalive_offset)

        while self._running:
            try:
                poll_due = elapsed >= DISK_POLL_INTERVAL or elapsed >= INVENTORY_POLL_INTERVAL
                if poll_due:
                    if elapsed >= DISK_POLL_INTERVAL:
                        await self._send(PACKET_DISKSTATUS)
                    if elapsed >= INVENTORY_POLL_INTERVAL:
                        await self._send(PACKET_GETINVENTORY)
                    elapsed = 0
                else:
                    await self._send(PACKET_DOORCONFIG)
            except Exception:
                break
            await asyncio.sleep(KEEPALIVE_INTERVAL)
            elapsed += KEEPALIVE_INTERVAL

    async def _read_packet(self, timeout: float = 30) -> Optional[dict]:
        if self._reader is None:
            return None
        while True:
            try:
                header_bytes = await asyncio.wait_for(
                    self._reader.readexactly(12), timeout=timeout
                )
            except (asyncio.IncompleteReadError, asyncio.TimeoutError):
                return None

            _total_len, payload_len, _ = FRAME_HEADER.unpack(header_bytes)

            if payload_len > _MAX_FRAME_PAYLOAD or payload_len == 0:
                raise ConnectionError(f"Implausible payload length: {payload_len}")
            if payload_len > _LARGE_PAYLOAD_WARN:
                log.warning(
                    "[%s] Unusually large payload: %d bytes",
                    self.state.display_name, payload_len
                )

            payload = await asyncio.wait_for(
                self._reader.readexactly(payload_len), timeout=10
            )

            elem = parse_xml_packet(payload)
            if elem is None:
                log.warning(
                    "[%s] Unparseable payload (%d bytes) — skipping frame",
                    self.state.display_name, len(payload)
                )
                continue

            result = dict(elem.attrib)
            result["type"] = elem.get("type", "")
            result["_elem"] = elem
            return result

    async def _send(self, xml: str):
        if self._writer is None or self._writer.is_closing():
            raise ConnectionError("Writer not available")
        self._writer.write(build_frame(xml))
        await self._writer.drain()

    async def _mark_offline(self):
        if not self.state.connected and self.state.offline_since is not None:
            return
        self.state.connected = False
        self.state.offline_since = time.time()
        self.state.apps = []
        self.state.disks = []
        self.state._upgrade_grace = {}
        self.bridge.invalidate_host_hash(self.state.id)
        await self.bridge.broadcast_host(self.state)

    def _handle_serverinfo(self, pkt: dict) -> bool:
        elem: ET.Element = pkt["_elem"]
        ver_raw = (elem.findtext("version") or "").strip()
        parts = ver_raw.split(" build ")
        new_version = parts[0].lstrip("v") if parts else ver_raw
        new_build = parts[1] if len(parts) > 1 else ""
        new_hostname = elem.findtext("hostname") or ""
        new_uid = elem.findtext("uid") or ""
        new_door = pkt.get("doordetected", "0") == "1"

        changed = (
            self.state.version != new_version
            or self.state.build != new_build
            or self.state.reported_hostname != new_hostname.lower()
            or self.state.uid != new_uid
            or self.state.door_detected != new_door
        )

        self.state.version = new_version
        self.state.build = new_build
        # hostname in HostState = the IP/address we connected to (for XCL <hostname> field)
        # reported_hostname = what the server announces as its own name (for XCL <reportedhostname>)
        # The serverinfo <hostname> element IS the reported hostname.
        # We keep self.state.hostname as the connection address (already set at init from config).
        self.state.reported_hostname = new_hostname.lower()
        self.state.uid = new_uid
        self.state.door_detected = new_door
        return changed

    def _dispatch(self, pkt: dict) -> bool:
        ptype = pkt.get("type", "")
        elem: ET.Element = pkt["_elem"]

        if ptype == "serverinfo":
            return self._handle_serverinfo(pkt)

        if ptype == "inventory":
            raw_apps = []
            for app in elem.findall(".//app"):
                cfg = app.find("config")
                raw_apps.append(AppEntry(
                    key            = app.get("key", ""),
                    name           = app.get("name", ""),
                    version        = app.get("version", ""),
                    folder         = app.get("folder", ""),
                    process        = app.get("process", ""),
                    startupcmd     = _inventory_text(app, "startupcmd"),
                    startupargs    = _inventory_text(app, "startupargs"),
                    appid          = app.get("appid", ""),
                    status         = int(app.get("status", "0")),
                    heartbeat      = int(app.get("heartbeat", "0")),
                    last_heartbeat = int(app.get("lastHeartbeat", "0")),
                    ignore_status  = cfg is not None and cfg.findtext("ignorestatus") == "1",
                ))
            return self._apply_inventory(raw_apps)

        if ptype == "ackdisks":
            disks = []
            for disk in elem.findall("disk"):
                free  = int(disk.findtext("freespace") or "0")
                total = int(disk.findtext("totalspace") or "0")
                if total > 0:
                    disks.append(DiskEntry(
                        label       = disk.findtext("label") or "",
                        free_bytes  = free,
                        total_bytes = total,
                    ))
            if _disks_snapshot(self.state.disks) == _disks_snapshot(disks):
                return False
            self.state.disks = disks
            return True

        if ptype == "doorconfig":
            color = int(pkt.get("color", "0"))
            if color == self.state.door_color:
                return False
            self.state.door_color = color
            return True

        if ptype == "winupdate":
            updates = int(pkt.get("updatecount", "0"))
            pending = pkt.get("pendingrestart", "0") == "1"
            if updates == self.state.win_updates and pending == self.state.win_pending_restart:
                return False
            self.state.win_updates = updates
            self.state.win_pending_restart = pending
            return True

        return False

    def _apply_inventory(self, raw_apps: list[AppEntry]) -> bool:
        """Collapse duplicates, migrate critical_apps, set upgrade grace."""
        new_apps = collapse_inventory_apps(raw_apps)
        old_apps = list(self.state.apps)
        now = time.time()

        old_by_identity = {app_identity(a): a for a in old_apps}
        upgrade_events: list[dict] = []
        grace = {
            k: v for k, v in self.state._upgrade_grace.items() if v > now
        }

        for app in new_apps:
            ident = app_identity(app)
            old = old_by_identity.get(ident)
            if old is None:
                continue
            if old.key == app.key and old.version == app.version:
                continue

            grace[identity_key(ident)] = now + APP_UPGRADE_GRACE
            upgrade_events.append({
                "name":        app.name,
                "old_version": old.version,
                "new_version": app.version,
            })
            log.info(
                "[%s] app upgrade detected: %s %s -> %s (grace %ss)",
                self.state.display_name, app.name,
                old.version, app.version, APP_UPGRADE_GRACE,
            )

        self.state._upgrade_grace = grace

        new_critical, crit_changed = migrate_critical_apps(
            self.state.critical_apps, old_apps, new_apps,
        )
        if crit_changed:
            old_by_key = {a.key: a for a in old_apps if a.key}
            new_by_key = {a.key: a for a in new_apps if a.key}
            for old_key in self.state.critical_apps:
                if old_key in new_by_key:
                    continue
                old_app = old_by_key.get(old_key)
                if not old_app:
                    continue
                match = next(
                    (a for a in new_apps if app_identity(a) == app_identity(old_app)),
                    None,
                )
                if match:
                    log.info(
                        "[%s] migrated critical_app %s -> %s (%s)",
                        self.state.display_name, old_key, match.key, match.name,
                    )
            self.state.critical_apps = new_critical
            self._pending_critical_migrated = True

        apps_changed = _apps_snapshot(old_apps) != _apps_snapshot(new_apps)
        if apps_changed:
            self.state.apps = new_apps

        if upgrade_events:
            self._pending_upgrade_events = upgrade_events

        return apps_changed or crit_changed or bool(upgrade_events)

# ---------------------------------------------------------------------------
# Bridge — manages all host clients and WebSocket server
# ---------------------------------------------------------------------------

class XPresMonBridge:
    def __init__(self):
        self.hosts: dict[str, HostState] = {}
        self.clients: dict[str, XPresMonClient] = {}
        self.ws_clients: set[WebSocketServerProtocol] = set()
        self._lock = asyncio.Lock()
        self._config_lock = asyncio.Lock()
        self._broadcast_hashes: dict[str, str] = {}
        self._loaded_reported_hostname: dict[str, str] = {}
        self._loaded_hostname: dict[str, str] = {}
        self._shutdown_event = asyncio.Event()

    # ---- Config loading ----

    def load_config(self):
        if not CONFIG_PATH.exists():
            log.warning("Config not found at %s, starting empty", CONFIG_PATH)
            return
        try:
            data = json.loads(CONFIG_PATH.read_text())
            for h in data.get("hosts", []):
                state = HostState(
                    id           = h["id"],
                    display_name = h["display_name"],
                    ip           = h["ip"],
                    port         = h.get("port", 9875),
                    group        = h.get("group", "Ungrouped"),
                    critical_apps= h.get("critical_apps", []),
                    canvas_enabled    = h.get("canvas_enabled", False),
                    canvas_port       = h.get("canvas_port", 9056),
                    hostname          = h.get("hostname", h.get("ip", "")),
                    reported_hostname = h.get("reported_hostname", ""),
                )
                self.hosts[state.id] = state
                self._loaded_reported_hostname[state.id] = h.get("reported_hostname", "")
                self._loaded_hostname[state.id] = h.get("hostname", h.get("ip", ""))
            log.info("Loaded %d hosts from config", len(self.hosts))
        except Exception as e:
            log.error("Failed to load config: %s", e)

    def invalidate_host_hash(self, host_id: str):
        self._broadcast_hashes.pop(host_id, None)

    # ---- Host management ----

    def _make_client(self, state: HostState, *, stagger: bool = False) -> XPresMonClient:
        startup_delay = random.uniform(0, STARTUP_STAGGER_MAX) if stagger else 0.0
        keepalive_offset = random.uniform(0, KEEPALIVE_INTERVAL)
        return XPresMonClient(
            state,
            self,
            startup_delay=startup_delay,
            keepalive_offset=keepalive_offset,
        )

    async def add_host(self, host_def: dict) -> HostState:
        async with self._lock:
            hid = host_def.get("id") or str(uuid.uuid4())
            ip = host_def["ip"]
            state = HostState(
                id           = hid,
                display_name = host_def["display_name"],
                ip           = ip,
                port         = host_def.get("port", 9875),
                group        = host_def.get("group", "Ungrouped"),
                hostname          = host_def.get("hostname") or ip,
                reported_hostname = host_def.get("reported_hostname", ""),
            )
            self.hosts[hid] = state
            self._loaded_hostname[hid] = state.hostname
            self._loaded_reported_hostname[hid] = state.reported_hostname
            client = self._make_client(state, stagger=False)
            self.clients[hid] = client
            client.start()
            await self._save_config()
            return state

    async def remove_host(self, host_id: str) -> bool:
        async with self._lock:
            if host_id not in self.hosts:
                return False
            if host_id in self.clients:
                self.clients[host_id].stop()
                del self.clients[host_id]
            del self.hosts[host_id]
            self._broadcast_hashes.pop(host_id, None)
            await self._save_config()
            await self.broadcast({"type": "host_removed", "id": host_id})
            return True

    async def _save_config(self):
        """
        Persist config to disk without blocking the event loop.
        asyncio.to_thread() runs _write_config() in a thread-pool thread so
        the JSON serialisation, tmp file write, and os.replace() don't stall
        host TCP connections or WebSocket broadcasts while they execute.
        The _config_lock ensures only one write runs at a time.
        """
        async with self._config_lock:
            await asyncio.to_thread(self._write_config)

    def _write_config(self):
        data = {
            "hosts": [
                {
                    "id":           h.id,
                    "display_name": h.display_name,
                    "ip":           h.ip,
                    "port":         h.port,
                    "group":        h.group,
                    "critical_apps":  h.critical_apps,
                    "canvas_enabled":    h.canvas_enabled,
                    "canvas_port":       h.canvas_port,
                    "hostname":          h.hostname or h.ip,
                    "reported_hostname": h.reported_hostname,
                }
                for h in self.hosts.values()
            ]
        }
        tmp = CONFIG_PATH.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(data, indent=2))
        os.replace(tmp, CONFIG_PATH)

    async def maybe_persist_hostname(self, state: HostState):
        """Write hostname/reported_hostname to config when first learned from serverinfo."""
        if not state.reported_hostname:
            return
        if not state.hostname:
            state.hostname = state.ip
        loaded_reported = self._loaded_reported_hostname.get(state.id, "")
        loaded_hostname = self._loaded_hostname.get(state.id, "")
        current_hostname = state.hostname or state.ip
        if (
            state.reported_hostname == loaded_reported
            and current_hostname == (loaded_hostname or state.ip)
        ):
            return
        await self._save_config()
        self._loaded_reported_hostname[state.id] = state.reported_hostname
        self._loaded_hostname[state.id] = current_hostname
        log.info(
            "[%s] persisted hostname metadata (hostname=%s reported=%s)",
            state.display_name, current_hostname, state.reported_hostname,
        )

    # ---- WebSocket ----

    async def ws_handler(self, websocket: WebSocketServerProtocol):
        self.ws_clients.add(websocket)
        log.info("WS client connected (%d total)", len(self.ws_clients))
        try:
            snapshot = {
                "type":  "snapshot",
                "hosts": [h.to_dict() for h in self.hosts.values()],
            }
            await websocket.send(json.dumps(snapshot))

            async for raw in websocket:
                if len(raw) > WS_MAX_MESSAGE_SIZE:
                    log.warning("WS message too large (%d bytes), ignoring", len(raw))
                    continue
                await self._handle_ws_message(raw, websocket)
        except websockets.exceptions.ConnectionClosed:
            pass
        finally:
            self.ws_clients.discard(websocket)
            log.info("WS client disconnected (%d total)", len(self.ws_clients))

    async def _handle_ws_message(self, raw: str, ws: WebSocketServerProtocol):
        try:
            msg = json.loads(raw)
        except json.JSONDecodeError:
            return

        action = msg.get("action")

        if action == "add_host":
            state = await self.add_host(msg["host"])
            await ws.send(json.dumps({"type": "host_added", "host": state.to_dict()}))

        elif action == "remove_host":
            await self.remove_host(msg["id"])

        elif action == "import_xcl":
            added = await self._import_xcl(msg["xml"])
            await ws.send(json.dumps({"type": "import_result", "added": added}))

        elif action == "update_group":
            hid = msg.get("id")
            if hid in self.hosts:
                self.hosts[hid].group = msg.get("group", "Ungrouped")
                await self._save_config()
                await self.broadcast_host(self.hosts[hid])

        elif action == "set_critical_apps":
            hid = msg.get("id")
            if hid in self.hosts:
                self.hosts[hid].critical_apps = msg.get("critical_apps", [])
                await self._save_config()
                await self.broadcast({
                    "type":          "alerts_updated",
                    "id":            hid,
                    "critical_apps": self.hosts[hid].critical_apps,
                })

        elif action == "edit_host":
            hid = msg.get("id")
            if hid in self.hosts:
                h = self.hosts[hid]
                h.display_name   = msg.get("display_name", h.display_name)
                h.group          = msg.get("group", h.group)
                h.canvas_enabled = bool(msg.get("canvas_enabled", h.canvas_enabled))
                h.canvas_port    = int(msg.get("canvas_port", h.canvas_port) or 9056)
                if "hostname" in msg:
                    h.hostname = (msg.get("hostname") or h.ip).strip()
                new_ip   = msg.get("ip", h.ip)
                new_port = int(msg.get("port", h.port) or 9875)
                if new_ip != h.ip or new_port != h.port:
                    h.ip = new_ip
                    h.port = new_port
                    if hid in self.clients:
                        self.clients[hid].stop()
                        client = self._make_client(h, stagger=False)
                        self.clients[hid] = client
                        client.start()
                await self._save_config()
                self._loaded_hostname[hid] = h.hostname or h.ip
                await self.broadcast_host(h)

        elif action == "host_command":
            await self._handle_host_command(msg, ws)

    async def _handle_host_command(self, msg: dict, ws: WebSocketServerProtocol):
        hid     = msg.get("id")
        command = msg.get("command")

        if hid not in self.hosts or hid not in self.clients:
            await ws.send(json.dumps({
                "type": "command_result", "id": hid, "command": command,
                "ok": False, "error": "Host not found or not connected",
            }))
            return

        state  = self.hosts[hid]
        client = self.clients[hid]

        if not state.connected:
            await ws.send(json.dumps({
                "type": "command_result", "id": hid, "command": command,
                "ok": False, "error": "Host is offline",
            }))
            return

        async with client._command_lock:
            try:
                if command == "reboot":
                    await client._send(PACKET_REBOOT)
                    log.info("[%s] REBOOT sent", state.display_name)

                elif command == "stop":
                    killed = 0
                    for app in state.apps:
                        if app.name.lower() in STOP_EXCLUDE_NAMES_LOWER:
                            continue
                        if app.appid and app.status == 2 and not app.ignore_status:
                            pkt = f'<packet type="kill" appid="{app.appid}"/>'
                            await client._send(pkt)
                            killed += 1
                    log.info("[%s] STOP ALL sent (%d kill packets)", state.display_name, killed)

                elif command == "start":
                    start_apps: list[tuple[str, str]] = []
                    for app in state.apps:
                        if app.status == 2 or app.ignore_status:
                            continue
                        cmd = _resolve_startup_cmd(app)
                        if not cmd:
                            continue
                        args = (app.startupargs or "").strip()
                        start_apps.append((cmd, args))

                    if start_apps:
                        pkt = _build_start_packet(start_apps)
                        await client._send(pkt)
                        log.info(
                            "[%s] START ALL sent (%d apps)",
                            state.display_name, len(start_apps),
                        )
                        log.debug("[%s] start packet:\n%s", state.display_name, pkt)
                        await asyncio.sleep(1.5)
                    else:
                        log.info("[%s] START ALL: no stopped apps to start", state.display_name)
                        await ws.send(json.dumps({
                            "type": "command_result", "id": hid, "command": command,
                            "ok": False, "error": "No stopped apps with a startup command",
                        }))
                        return

                else:
                    await ws.send(json.dumps({
                        "type": "command_result", "id": hid, "command": command,
                        "ok": False, "error": f"Unknown command: {command}",
                    }))
                    return

                await client._send(PACKET_GETINVENTORY)
                await ws.send(json.dumps({
                    "type": "command_result", "id": hid, "command": command, "ok": True,
                }))

            except Exception as e:
                log.error("[%s] command %s failed: %s", state.display_name, command, e)
                await ws.send(json.dumps({
                    "type": "command_result", "id": hid, "command": command,
                    "ok": False, "error": str(e),
                }))

    async def _import_xcl(self, xml_str: str) -> int:
        existing_ips = {h.ip for h in self.hosts.values()}
        added = 0
        try:
            root = ET.fromstring(xml_str)
            for client in root.findall(".//client"):
                ip   = (client.findtext("ip") or "").strip()
                name = (client.findtext("machinename") or ip).strip()
                port = int(client.findtext("port") or "9875")
                conn_hostname = (client.findtext("hostname") or ip).strip()
                reported = (client.findtext("reportedhostname") or "").strip().lower()
                if not ip or ip in existing_ips:
                    continue
                await self.add_host({
                    "display_name":      name,
                    "ip":                ip,
                    "port":              port,
                    "group":             "Ungrouped",
                    "hostname":          conn_hostname,
                    "reported_hostname": reported,
                })
                existing_ips.add(ip)
                added += 1
        except ET.ParseError as e:
            log.error("XCL parse error: %s", e)
        return added

    async def broadcast(self, msg: dict):
        if not self.ws_clients:
            return
        data = json.dumps(msg)
        dead = []
        for ws in list(self.ws_clients):
            try:
                await ws.send(data)
            except Exception:
                dead.append(ws)
        for ws in dead:
            self.ws_clients.discard(ws)

    async def broadcast_host(self, state: HostState):
        h = state.content_hash()
        if self._broadcast_hashes.get(state.id) == h:
            return
        self._broadcast_hashes[state.id] = h
        await self.broadcast({"type": "host_update", "host": state.to_dict()})

    async def on_critical_apps_migrated(self, state: HostState):
        await self._save_config()
        await self.broadcast({
            "type":          "alerts_updated",
            "id":            state.id,
            "critical_apps": state.critical_apps,
            "silent":        True,
        })

    async def broadcast_app_upgrades(self, host_id: str, events: list[dict]):
        if not events:
            return
        host = self.hosts.get(host_id)
        await self.broadcast({
            "type":    "app_upgraded",
            "id":      host_id,
            "host":    host.display_name if host else host_id,
            "changes": events,
        })

    # ---- Supervision ----

    async def _offline_watchdog(self):
        while True:
            await asyncio.sleep(OFFLINE_GRACE)
            now = time.time()
            for hid, state in list(self.hosts.items()):
                if not state.connected or state.last_seen <= 0:
                    continue
                if now - state.last_seen <= OFFLINE_GRACE:
                    if state._miss_count > 0:
                        log.info("[%s] recovered, resetting miss counter", state.display_name)
                        state._miss_count = 0
                        await self.broadcast_host(state)
                    continue

                state._miss_count += 1
                log.warning(
                    "[%s] silent for %ss (miss %d/%d)",
                    state.display_name, OFFLINE_GRACE,
                    state._miss_count, OFFLINE_MISS_LIMIT,
                )
                await self.broadcast_host(state)
                if state._miss_count >= OFFLINE_MISS_LIMIT:
                    client = self.clients.get(hid)
                    if client:
                        await client.force_reconnect()

    async def _task_supervisor(self):
        while True:
            await asyncio.sleep(TASK_SUPERVISOR_INTERVAL)
            for _hid, client in list(self.clients.items()):
                if not client._running:
                    continue
                task = client._task
                if task is None or not task.done():
                    continue
                if task.cancelled():
                    continue
                exc = task.exception()
                if exc:
                    log.error("[%s] host task died: %s", client.state.display_name, exc)
                else:
                    log.warning(
                        "[%s] host task exited unexpectedly, restarting",
                        client.state.display_name,
                    )
                client.start()

    async def _health_log(self):
        while True:
            await asyncio.sleep(HEALTH_LOG_INTERVAL)
            connected = sum(1 for h in self.hosts.values() if h.connected)
            log.info(
                "health: %d/%d hosts connected, %d ws clients",
                connected, len(self.hosts), len(self.ws_clients),
            )

    # ---- Main entry ----

    async def run(self):
        loop = asyncio.get_running_loop()
        loop.set_exception_handler(_loop_exception_handler)

        self.load_config()

        for state in self.hosts.values():
            client = self._make_client(state, stagger=True)
            self.clients[state.id] = client
            client.start()

        asyncio.create_task(self._offline_watchdog(), name="offline-watchdog")
        asyncio.create_task(self._task_supervisor(), name="task-supervisor")
        asyncio.create_task(self._health_log(), name="health-log")

        log.info("WebSocket server starting on ws://%s:%s", WS_HOST, WS_PORT)
        ws_server = await websockets.serve(
            self.ws_handler,
            WS_HOST,
            WS_PORT,
            ping_interval=WS_PING_INTERVAL,
            ping_timeout=WS_PING_TIMEOUT,
            max_size=WS_MAX_MESSAGE_SIZE,
        )
        self._ws_server = ws_server

        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                loop.add_signal_handler(
                    sig,
                    lambda s=sig: asyncio.create_task(_shutdown(self, ws_server)),
                )
            except NotImplementedError:
                pass

        await self._shutdown_event.wait()


# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------

async def main():
    bridge = XPresMonBridge()
    await bridge.run()

async def _shutdown(bridge: XPresMonBridge, ws_server):
    log.info("Shutting down (fast)...")

    for client in bridge.clients.values():
        client.stop()

    if bridge.ws_clients:
        await asyncio.gather(
            *[ws.close() for ws in list(bridge.ws_clients)],
            return_exceptions=True,
        )

    ws_server.close()
    await ws_server.wait_closed()
    bridge._shutdown_event.set()

if __name__ == "__main__":
    asyncio.run(main())