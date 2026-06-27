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
import json
import logging
import signal
import struct
import time
import uuid
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field, asdict
from pathlib import Path
from typing import Optional

import websockets
from websockets.server import WebSocketServerProtocol

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

CONFIG_PATH = Path(__file__).parent.parent / "public" / "config.json"
WS_HOST = "0.0.0.0"
WS_PORT = 8765
OFFLINE_GRACE = 45          # seconds of silence per watchdog check
OFFLINE_MISS_LIMIT = 2      # consecutive misses before marking a host offline
KEEPALIVE_INTERVAL = 20     # seconds between keepalive pings (must be < server idle timeout ~30s)
DISK_POLL_INTERVAL = 60     # seconds between diskstatus polls
RECONNECT_DELAY_MIN = 5     # seconds
RECONNECT_DELAY_MAX = 60    # seconds (exponential backoff cap)
APPNAME = "xpStatusClient"

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
    critical_apps: list = field(default_factory=list)  # list of app key GUIDs to alert on
    _miss_count: int = 0  # consecutive watchdog misses (not persisted)
    canvas_enabled: bool = False   # WSS Canvas links enabled
    canvas_port: int = 9056        # WSS Canvas HTTP port

    def to_dict(self) -> dict:
        d = asdict(self)
        d["apps"] = [asdict(a) for a in self.apps]
        d["disks"] = [asdict(dk) for dk in self.disks]
        return d

# ---------------------------------------------------------------------------
# Protocol helpers
# ---------------------------------------------------------------------------

FRAME_HEADER = struct.Struct("<III")  # total_len, payload_len, flags

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

PACKET_GETINVENTORY = '<packet type="getinventory"/>'
PACKET_DISKSTATUS   = '<packet type="diskstatus"/>'
PACKET_DOORCONFIG   = '<packet type="getdoorconfig"/>'

def parse_xml_packet(data: bytes) -> Optional[ET.Element]:
    """Find and parse the XML portion of a frame payload."""
    idx = data.find(b'<packet')
    if idx < 0:
        return None
    try:
        return ET.fromstring(data[idx:].decode("utf-8", errors="replace"))
    except ET.ParseError:
        return None

# ---------------------------------------------------------------------------
# TCP connection handler per host
# ---------------------------------------------------------------------------

class XPresMonClient:
    def __init__(self, state: HostState, bridge: "XPresMonBridge"):
        self.state = state
        self.bridge = bridge
        self._task: Optional[asyncio.Task] = None
        self._running = False
        self._reader: Optional[asyncio.StreamReader] = None
        self._writer: Optional[asyncio.StreamWriter] = None

    def start(self):
        self._running = True
        self._task = asyncio.create_task(self._run_loop(), name=f"xpmon-{self.state.id}")

    def stop(self):
        self._running = False
        if self._task:
            self._task.cancel()
        if self._writer:
            try:
                self._writer.close()
            except Exception:
                pass

    async def _run_loop(self):
        delay = RECONNECT_DELAY_MIN
        while self._running:
            try:
                await self._connect_and_poll()
                delay = RECONNECT_DELAY_MIN  # reset on clean exit
            except asyncio.CancelledError:
                break
            except Exception as e:
                log.warning(f"[{self.state.display_name}] connection error: {e}")

            if not self._running:
                break

            self._mark_offline()
            log.info(f"[{self.state.display_name}] reconnecting in {delay}s")
            await asyncio.sleep(delay)
            delay = min(delay * 2, RECONNECT_DELAY_MAX)

    async def _connect_and_poll(self):
        log.info(f"[{self.state.display_name}] connecting to {self.state.ip}:{self.state.port}")
        self._reader, self._writer = await asyncio.wait_for(
            asyncio.open_connection(self.state.ip, self.state.port),
            timeout=10,
        )

        # Read serverinfo (server sends first)
        pkt = await self._read_packet(timeout=15)
        if pkt is None or pkt.get("type") != "serverinfo":
            raise ConnectionError("Expected serverinfo, got nothing or wrong packet")
        self._handle_serverinfo(pkt)

        # Send login
        await self._send(make_login_packet())

        # Send initial query burst
        await self._send(PACKET_GETINVENTORY)
        await self._send(PACKET_DISKSTATUS)
        await self._send(PACKET_DOORCONFIG)

        # Mark connected
        self.state.connected = True
        self.state.offline_since = None
        self.state.last_seen = time.time()
        await self.bridge.broadcast_host(self.state)

        # Start keepalive+disk poll task alongside read loop
        keepalive_task = asyncio.create_task(self._keepalive_loop())
        try:
            await self._read_loop()
        finally:
            keepalive_task.cancel()

    async def _read_loop(self):
        # Timeout is keepalive interval * 3 — if we haven't heard anything in
        # that window despite sending keepalives, the connection is truly dead.
        read_timeout = KEEPALIVE_INTERVAL * 3
        while self._running:
            pkt = await self._read_packet(timeout=read_timeout)
            if pkt is None:
                raise ConnectionError("Connection closed by server")
            self.state.last_seen = time.time()
            changed = self._dispatch(pkt)
            if changed:
                await self.bridge.broadcast_host(self.state)

    async def _keepalive_loop(self):
        """
        Send a lightweight keepalive every KEEPALIVE_INTERVAL seconds to
        prevent the XPression Monitor server from closing the idle connection
        (~30s server-side idle timeout observed in practice).

        Uses getdoorconfig as the keepalive — it's the smallest request (30
        bytes) and the server always responds with doorconfig. Every
        DISK_POLL_INTERVAL seconds, diskstatus is sent instead to refresh
        disk space data.
        """
        elapsed = 0
        await asyncio.sleep(KEEPALIVE_INTERVAL)
        elapsed += KEEPALIVE_INTERVAL
        while self._running:
            try:
                if elapsed >= DISK_POLL_INTERVAL:
                    await self._send(PACKET_DISKSTATUS)
                    elapsed = 0
                else:
                    await self._send(PACKET_DOORCONFIG)
            except Exception:
                break
            await asyncio.sleep(KEEPALIVE_INTERVAL)
            elapsed += KEEPALIVE_INTERVAL

    async def _read_packet(self, timeout: float = 30) -> Optional[dict]:
        """Read one framed message. Returns parsed dict or None on EOF."""
        try:
            header_bytes = await asyncio.wait_for(
                self._reader.readexactly(12), timeout=timeout
            )
        except (asyncio.IncompleteReadError, asyncio.TimeoutError):
            return None

        total_len, payload_len, _ = FRAME_HEADER.unpack(header_bytes)

        # Sanity check — prevent unbounded reads
        if payload_len > 1_048_576 or payload_len == 0:
            raise ConnectionError(f"Implausible payload length: {payload_len}")

        payload = await asyncio.wait_for(
            self._reader.readexactly(payload_len), timeout=10
        )

        elem = parse_xml_packet(payload)
        if elem is None:
            return None

        result = dict(elem.attrib)
        result["type"] = elem.get("type", "")
        result["_elem"] = elem
        return result

    async def _send(self, xml: str):
        if self._writer is None or self._writer.is_closing():
            raise ConnectionError("Writer not available")
        self._writer.write(build_frame(xml))
        await self._writer.drain()

    def _mark_offline(self):
        if self.state.connected or self.state.offline_since is None:
            self.state.connected = False
            self.state.offline_since = time.time()
            self.state.apps = []
            self.state.disks = []
            asyncio.create_task(self.bridge.broadcast_host(self.state))

    def _handle_serverinfo(self, pkt: dict):
        elem: ET.Element = pkt["_elem"]
        ver_raw = (elem.findtext("version") or "").strip()
        # e.g. "v12.5 build 6127"
        parts = ver_raw.split(" build ")
        self.state.version = parts[0].lstrip("v") if parts else ver_raw
        self.state.build   = parts[1] if len(parts) > 1 else ""
        self.state.hostname          = elem.findtext("hostname") or ""
        self.state.reported_hostname = elem.findtext("hostname") or ""
        self.state.uid               = elem.findtext("uid") or ""
        self.state.door_detected     = pkt.get("doordetected", "0") == "1"

    def _dispatch(self, pkt: dict) -> bool:
        """Handle incoming packet, return True if state changed."""
        ptype = pkt.get("type", "")
        elem: ET.Element = pkt["_elem"]

        if ptype == "serverinfo":
            self._handle_serverinfo(pkt)
            return True

        elif ptype == "inventory":
            apps = []
            for app in elem.findall(".//app"):
                cfg = app.find("config")
                apps.append(AppEntry(
                    key            = app.get("key", ""),
                    name           = app.get("name", ""),
                    version        = app.get("version", ""),
                    folder         = app.get("folder", ""),
                    process        = app.get("process", ""),
                    status         = int(app.get("status", "0")),
                    heartbeat      = int(app.get("heartbeat", "0")),
                    last_heartbeat = int(app.get("lastHeartbeat", "0")),
                    ignore_status  = cfg is not None and cfg.findtext("ignorestatus") == "1",
                ))
            self.state.apps = apps
            return True

        elif ptype == "ackdisks":
            disks = []
            for disk in elem.findall("disk"):
                free  = int(disk.findtext("freespace") or "0")
                total = int(disk.findtext("totalspace") or "0")
                if total > 0:  # skip unmounted/zero-size drives
                    disks.append(DiskEntry(
                        label       = disk.findtext("label") or "",
                        free_bytes  = free,
                        total_bytes = total,
                    ))
            self.state.disks = disks
            return True

        elif ptype == "doorconfig":
            self.state.door_color = int(pkt.get("color", "0"))
            return True

        elif ptype == "winupdate":
            self.state.win_updates         = int(pkt.get("updatecount", "0"))
            self.state.win_pending_restart = pkt.get("pendingrestart", "0") == "1"
            return True

        return False

# ---------------------------------------------------------------------------
# Bridge — manages all host clients and WebSocket server
# ---------------------------------------------------------------------------

class XPresMonBridge:
    def __init__(self):
        self.hosts: dict[str, HostState] = {}          # id → HostState
        self.clients: dict[str, XPresMonClient] = {}   # id → client
        self.ws_clients: set[WebSocketServerProtocol] = set()
        self._lock = asyncio.Lock()

    # ---- Config loading ----

    def load_config(self):
        if not CONFIG_PATH.exists():
            log.warning(f"Config not found at {CONFIG_PATH}, starting empty")
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
                    canvas_enabled= h.get("canvas_enabled", False),
                    canvas_port   = h.get("canvas_port", 9056),
                )
                self.hosts[state.id] = state
            log.info(f"Loaded {len(self.hosts)} hosts from config")
        except Exception as e:
            log.error(f"Failed to load config: {e}")

    # ---- Host management ----

    async def add_host(self, host_def: dict) -> HostState:
        async with self._lock:
            hid = host_def.get("id") or str(uuid.uuid4())
            state = HostState(
                id           = hid,
                display_name = host_def["display_name"],
                ip           = host_def["ip"],
                port         = host_def.get("port", 9875),
                group        = host_def.get("group", "Ungrouped"),
            )
            self.hosts[hid] = state
            client = XPresMonClient(state, self)
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
            await self._save_config()
            await self.broadcast({"type": "host_removed", "id": host_id})
            return True

    async def _save_config(self):
        data = {
            "hosts": [
                {
                    "id":           h.id,
                    "display_name": h.display_name,
                    "ip":           h.ip,
                    "port":         h.port,
                    "group":        h.group,
                    "critical_apps":  h.critical_apps,
                    "canvas_enabled": h.canvas_enabled,
                    "canvas_port":    h.canvas_port,
                }
                for h in self.hosts.values()
            ]
        }
        CONFIG_PATH.write_text(json.dumps(data, indent=2))

    # ---- WebSocket ----

    async def ws_handler(self, websocket: WebSocketServerProtocol):
        self.ws_clients.add(websocket)
        log.info(f"WS client connected ({len(self.ws_clients)} total)")
        try:
            # Send full state snapshot on connect
            snapshot = {
                "type":  "snapshot",
                "hosts": [h.to_dict() for h in self.hosts.values()],
            }
            await websocket.send(json.dumps(snapshot))

            # Handle incoming control messages from the browser
            async for raw in websocket:
                await self._handle_ws_message(raw, websocket)
        except websockets.exceptions.ConnectionClosed:
            pass
        finally:
            self.ws_clients.discard(websocket)
            log.info(f"WS client disconnected ({len(self.ws_clients)} total)")

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
                # Confirm back to all clients so every browser tab updates
                await self.broadcast({
                    "type":         "alerts_updated",
                    "id":           hid,
                    "critical_apps": self.hosts[hid].critical_apps,
                })

        elif action == "edit_host":
            hid = msg.get("id")
            if hid in self.hosts:
                h = self.hosts[hid]
                h.display_name  = msg.get("display_name", h.display_name)
                h.group         = msg.get("group", h.group)
                h.canvas_enabled= bool(msg.get("canvas_enabled", h.canvas_enabled))
                h.canvas_port   = int(msg.get("canvas_port", h.canvas_port) or 9056)
                # IP/port changes require reconnect
                new_ip   = msg.get("ip", h.ip)
                new_port = int(msg.get("port", h.port) or 9875)
                if new_ip != h.ip or new_port != h.port:
                    h.ip   = new_ip
                    h.port = new_port
                    # Reconnect the client with the new address
                    if hid in self.clients:
                        self.clients[hid].stop()
                        client = XPresMonClient(h, self)
                        self.clients[hid] = client
                        client.start()
                await self._save_config()
                await self.broadcast_host(h)

    async def _import_xcl(self, xml_str: str) -> int:
        """Parse XCL XML and add any hosts not already tracked by IP."""
        existing_ips = {h.ip for h in self.hosts.values()}
        added = 0
        try:
            root = ET.fromstring(xml_str)
            for client in root.findall(".//client"):
                ip   = (client.findtext("ip") or "").strip()
                name = (client.findtext("machinename") or ip).strip()
                port = int(client.findtext("port") or "9875")
                if not ip or ip in existing_ips:
                    continue
                await self.add_host({
                    "display_name": name,
                    "ip":           ip,
                    "port":         port,
                    "group":        "Ungrouped",
                })
                existing_ips.add(ip)
                added += 1
        except ET.ParseError as e:
            log.error(f"XCL parse error: {e}")
        return added

    async def broadcast(self, msg: dict):
        if not self.ws_clients:
            return
        data = json.dumps(msg)
        await asyncio.gather(
            *[ws.send(data) for ws in list(self.ws_clients)],
            return_exceptions=True,
        )

    async def broadcast_host(self, state: HostState):
        await self.broadcast({"type": "host_update", "host": state.to_dict()})

    # ---- Offline watchdog ----

    async def _offline_watchdog(self):
        """
        Periodically check for hosts that have gone silent.
        A host must miss OFFLINE_MISS_LIMIT consecutive checks before being
        marked offline. Each check window is OFFLINE_GRACE seconds.
        This prevents false alarms during brief network hiccups or upgrades.
        """
        while True:
            await asyncio.sleep(OFFLINE_GRACE)
            now = time.time()
            for state in self.hosts.values():
                if state.connected and state.last_seen > 0:
                    if now - state.last_seen > OFFLINE_GRACE:
                        state._miss_count += 1
                        log.warning(
                            f"[{state.display_name}] silent for {OFFLINE_GRACE}s "
                            f"(miss {state._miss_count}/{OFFLINE_MISS_LIMIT})"
                        )
                        if state._miss_count >= OFFLINE_MISS_LIMIT:
                            log.warning(f"[{state.display_name}] marking OFFLINE after {OFFLINE_MISS_LIMIT} misses")
                            state.connected = False
                            state.offline_since = now
                            state._miss_count = 0
                            await self.broadcast_host(state)
                    else:
                        # Host is responding — reset miss counter
                        if state._miss_count > 0:
                            log.info(f"[{state.display_name}] recovered, resetting miss counter")
                            state._miss_count = 0

    # ---- Main entry ----

    async def run(self):
        self.load_config()

        # Start a client for each loaded host
        for hid, state in self.hosts.items():
            client = XPresMonClient(state, self)
            self.clients[hid] = client
            client.start()

        # Start offline watchdog
        asyncio.create_task(self._offline_watchdog())

        log.info(f"WebSocket server starting on ws://{WS_HOST}:{WS_PORT}")
        ws_server = await websockets.serve(self.ws_handler, WS_HOST, WS_PORT)
        self._ws_server = ws_server

        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            loop.add_signal_handler(
                sig,
                lambda: asyncio.create_task(_shutdown(self, ws_server))
            )

        await asyncio.Future()  # run until signal


# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------

async def main():
    bridge = XPresMonBridge()
    await bridge.run()

async def _shutdown(bridge: XPresMonBridge, ws_server):
    """
    Fast clean shutdown — stop all host tasks immediately, force-close all
    WebSocket connections without waiting for graceful handshakes, then exit.
    systemctl stop now returns in <2s instead of waiting 30s for WS timeout.
    """
    log.info("Shutting down (fast)...")

    # Stop all XPression TCP client tasks
    for client in bridge.clients.values():
        client.stop()

    # Force-close all open WebSocket connections immediately
    if bridge.ws_clients:
        await asyncio.gather(
            *[ws.close() for ws in list(bridge.ws_clients)],
            return_exceptions=True,
        )

    # Close the WS server and stop the event loop
    ws_server.close()
    await ws_server.wait_closed()
    asyncio.get_event_loop().stop()

if __name__ == "__main__":
    asyncio.run(main())