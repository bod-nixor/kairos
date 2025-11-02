"""
Systemd unit example:

[Unit]
Description=Signoff WebSocket relay
After=network.target

[Service]
WorkingDirectory=/srv/signoff
Environment="WS_HOST=0.0.0.0" "WS_PORT=8090" "WS_ALLOWED_ORIGINS=https://regatta.nixorcorporate.com" "WS_SHARED_SECRET=CHANGE_ME"
ExecStart=/usr/bin/python3 ws_server.py
Restart=on-failure

[Install]
WantedBy=multi-user.target
"""

import asyncio
import contextlib
import json
import logging
import os
import signal
import time
from dataclasses import dataclass, field
from typing import Any, Dict, Optional, Set

from aiohttp import web, WSCloseCode, WSMsgType

try:
    import websockets  # noqa: F401  # Imported to satisfy dependency requirements
except Exception:  # pragma: no cover - optional dependency just needs to be importable
    websockets = None

logging.basicConfig(level=logging.INFO, format="[%(asctime)s] %(levelname)s %(message)s")
LOGGER = logging.getLogger("ws_server")

ALLOWED_EVENTS = {"rooms", "queue", "progress", "ta_accept"}
PAYLOAD_MAX_BYTES = 32 * 1024


def _env(name: str, default: Optional[str] = None) -> Optional[str]:
    value = os.getenv(name)
    if value is None or value == "":
        return default
    return value


WS_SHARED_SECRET = _env("WS_SHARED_SECRET")
if not WS_SHARED_SECRET:
    LOGGER.warning("WS_SHARED_SECRET is not configured – refusing all websocket connections")

WS_HOST = _env("WS_HOST", "0.0.0.0")
WS_PORT = int(_env("WS_PORT", "8090"))

_default_origin = "https://regatta.nixorcorporate.com"
allowed_origins_env = _env("WS_ALLOWED_ORIGINS")
if allowed_origins_env:
    ALLOWED_ORIGINS: Set[str] = {
        origin.strip().rstrip("/") for origin in allowed_origins_env.split(",") if origin.strip()
    }
else:
    ALLOWED_ORIGINS = {_default_origin}


@dataclass(eq=False)
class Client:
    ws: web.WebSocketResponse
    channels: Set[str]
    course_id: Optional[int]
    room_id: Optional[int]
    user_id: Optional[int]
    last_activity: float = field(default_factory=lambda: time.time())

    async def send(self, message: Dict[str, Any]) -> None:
        try:
            await self.ws.send_json(message, dumps=lambda obj: json.dumps(obj, separators=(",", ":")))
        except Exception as exc:  # pragma: no cover - network failures
            LOGGER.debug("Send failed for user %s: %s", self.user_id, exc)
            await self.close(WSCloseCode.ABNORMAL_CLOSURE)

    async def close(self, code: int = WSCloseCode.GOING_AWAY) -> None:
        if not self.ws.closed:
            try:
                await self.ws.close(code=code)
            except Exception:  # pragma: no cover
                pass

    def touch(self) -> None:
        self.last_activity = time.time()


CLIENTS: Set[Client] = set()
REAPER_INTERVAL = 20
IDLE_TIMEOUT = 60


def _parse_int(value: Any) -> Optional[int]:
    if value is None:
        return None
    try:
        ivalue = int(str(value))
    except (TypeError, ValueError):
        return None
    return ivalue


def _validate_token(token: str) -> Optional[Dict[str, Any]]:
    if not token or not WS_SHARED_SECRET:
        return None
    parts = token.split(".")
    if len(parts) != 3:
        return None
    signature, ts_str, user_id_str = parts
    if not signature or not ts_str or not user_id_str:
        return None
    try:
        ts = int(ts_str)
        user_id = int(user_id_str)
    except ValueError:
        return None
    now = int(time.time())
    if ts < now - 600 or ts > now + 60:
        return None
    import hmac
    import hashlib

    raw = f"{user_id}|{ts}".encode("utf-8")
    secret_bytes = WS_SHARED_SECRET.encode("utf-8")
    expected = hmac.new(secret_bytes, raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        return None
    return {"user_id": user_id, "timestamp": ts}


async def websocket_handler(request: web.Request) -> web.StreamResponse:
    if not WS_SHARED_SECRET:
        return web.Response(status=503, text="WebSocket disabled")

    origin = (request.headers.get("Origin") or "").rstrip("/")
    if ALLOWED_ORIGINS and origin not in ALLOWED_ORIGINS:
        LOGGER.warning("Rejected WS origin %s", origin)
        return web.Response(status=403, text="Origin not allowed")

    token = request.query.get("token", "")
    token_info = _validate_token(token)
    if not token_info:
        return web.Response(status=401, text="Invalid token")

    raw_channels = request.query.get("channels", "")
    channels = {
        chan.strip()
        for chan in raw_channels.split(",")
        if chan.strip() in ALLOWED_EVENTS
    }
    if not channels:
        channels = set(ALLOWED_EVENTS)

    course_id = _parse_int(request.query.get("course_id"))
    room_id = _parse_int(request.query.get("room_id"))

    ws = web.WebSocketResponse(heartbeat=20.0, max_msg_size=PAYLOAD_MAX_BYTES)
    await ws.prepare(request)

    client = Client(ws=ws, channels=channels, course_id=course_id, room_id=room_id, user_id=token_info.get("user_id"))
    CLIENTS.add(client)
    LOGGER.info("Client connected user=%s channels=%s course=%s room=%s", client.user_id, ",".join(sorted(client.channels)), client.course_id, client.room_id)

    try:
        async for msg in ws:
            if msg.type == WSMsgType.TEXT:
                client.touch()
            elif msg.type == WSMsgType.PONG:
                client.touch()
            elif msg.type == WSMsgType.ERROR:
                LOGGER.debug("WebSocket error for user %s: %s", client.user_id, ws.exception())
                break
    finally:
        CLIENTS.discard(client)
        LOGGER.info("Client disconnected user=%s", client.user_id)
        await client.close()

    return ws


def _sanitize_payload(payload: Any) -> Optional[Any]:
    if payload is None:
        return None
    try:
        dumped = json.dumps(payload, separators=(",", ":"))
    except (TypeError, ValueError):
        return None
    if len(dumped.encode("utf-8")) > PAYLOAD_MAX_BYTES:
        return None
    return json.loads(dumped)


async def emit_handler(request: web.Request) -> web.Response:
    if not WS_SHARED_SECRET:
        return web.Response(status=503, text="Shared secret not configured")

    provided_secret = request.headers.get("X-WS-SECRET", "")
    if not provided_secret:
        return web.Response(status=403, text="Forbidden")

    import hmac

    if not hmac.compare_digest(provided_secret, WS_SHARED_SECRET):
        return web.Response(status=403, text="Forbidden")

    if request.content_length and request.content_length > PAYLOAD_MAX_BYTES:
        await request.read()
        return web.Response(status=413, text="Payload too large")

    try:
        data = await request.json()
    except Exception:
        return web.Response(status=400, text="Invalid JSON")

    if not isinstance(data, dict):
        return web.Response(status=400, text="Invalid body")

    event = str(data.get("event", "")).strip()
    if event not in ALLOWED_EVENTS:
        return web.Response(status=400, text="Unsupported event")

    course_id = _parse_int(data.get("course_id"))
    room_id = _parse_int(data.get("room_id"))
    ref_id = _parse_int(data.get("ref_id"))
    payload = data.get("payload")
    payload = _sanitize_payload(payload)

    message = {
        "type": "event",
        "event": event,
        "course_id": course_id,
        "room_id": room_id,
        "ref_id": ref_id,
        "payload": payload,
        "ts": int(time.time()),
    }

    delivered = 0
    to_remove: Set[Client] = set()
    for client in list(CLIENTS):
        if event not in client.channels:
            continue
        if course_id is not None and client.course_id not in (None, course_id):
            continue
        if room_id is not None and client.room_id not in (None, room_id):
            continue
        try:
            await client.send(message)
            delivered += 1
        except Exception:
            to_remove.add(client)

    for client in to_remove:
        CLIENTS.discard(client)
        await client.close(WSCloseCode.ABNORMAL_CLOSURE)

    return web.json_response({"success": True, "delivered": delivered})


async def health_handler(_: web.Request) -> web.Response:
    return web.json_response({"ok": True, "clients": len(CLIENTS)})


async def reap_idle_clients() -> None:
    while True:
        await asyncio.sleep(REAPER_INTERVAL)
        now = time.time()
        to_drop = [client for client in CLIENTS if now - client.last_activity > IDLE_TIMEOUT]
        for client in to_drop:
            LOGGER.info("Closing idle client user=%s", client.user_id)
            CLIENTS.discard(client)
            await client.close(WSCloseCode.GOING_AWAY)


async def _on_startup(app: web.Application) -> None:
    app["reaper_task"] = asyncio.create_task(reap_idle_clients())


async def _on_cleanup(app: web.Application) -> None:
    task = app.get("reaper_task")
    if task:
        task.cancel()
        with contextlib.suppress(asyncio.CancelledError):
            await task
    for client in list(CLIENTS):
        await client.close()
    CLIENTS.clear()


async def create_app() -> web.Application:
    app = web.Application()
    app.on_startup.append(_on_startup)
    app.on_cleanup.append(_on_cleanup)
    app.router.add_get("/ws", websocket_handler)
    app.router.add_post("/emit", emit_handler)
    app.router.add_get("/healthz", health_handler)
    return app


async def main() -> None:
    app = await create_app()
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, host=WS_HOST, port=WS_PORT)
    LOGGER.info("Starting WS server on %s:%s", WS_HOST, WS_PORT)
    await site.start()

    stop_event = asyncio.Event()

    def _shutdown() -> None:
        stop_event.set()

    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        loop.add_signal_handler(sig, _shutdown)

    await stop_event.wait()
    LOGGER.info("Shutting down WS server")
    await runner.cleanup()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
