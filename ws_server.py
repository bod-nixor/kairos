"""Minimal TLS WebSocket relay for Kairos Signoff (modern websockets API)."""

from __future__ import annotations

import asyncio
import json
import logging
import os
import signal
import ssl
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional, Set, Tuple
from urllib.parse import parse_qs, urlparse

import websockets
from websockets.asyncio.server import ServerConnection
from websockets.exceptions import ConnectionClosed, ConnectionClosedError, ConnectionClosedOK

logging.basicConfig(level=logging.INFO, format="[%(asctime)s] %(levelname)s %(message)s")
LOGGER = logging.getLogger("ws_server")

ALLOWED_EVENTS = {"rooms", "queue", "progress", "ta_accept"}
PAYLOAD_MAX_BYTES = 32 * 1024
DEFAULT_PORT = 8090


def _env(name: str, default: Optional[str] = None) -> Optional[str]:
    value = os.getenv(name)
    if value is None or value == "":
        return default
    return value


WS_SHARED_SECRET = _env("WS_SHARED_SECRET") or ""
if not WS_SHARED_SECRET:
    LOGGER.warning("WS_SHARED_SECRET is not configured – refusing unauthenticated connections")

WS_HOST = _env("WS_HOST", "0.0.0.0") or "0.0.0.0"
WS_PORT = int(_env("WS_PORT", str(DEFAULT_PORT)) or DEFAULT_PORT)

WS_SSL_CERT = _env("WS_SSL_CERT")
WS_SSL_KEY = _env("WS_SSL_KEY")

_default_origin = "https://regatta.nixorcorporate.com"
_allowed_origins = _env("WS_ALLOWED_ORIGINS")
if _allowed_origins:
    ALLOWED_ORIGINS: Set[str] = {
        origin.strip().rstrip("/")
        for origin in _allowed_origins.split(",")
        if origin.strip()
    }
else:
    ALLOWED_ORIGINS = {_default_origin}


@dataclass(eq=False)
class Client:
    websocket: ServerConnection
    channels: Set[str]
    course_id: Optional[int]
    room_id: Optional[int]
    user_id: Optional[int]

    async def send(self, message: Dict[str, Any]) -> None:
        payload = json.dumps(message, separators=(",", ":"))
        await self.websocket.send(payload)


CLIENTS: Set[Client] = set()


def _parse_int(value: Any) -> Optional[int]:
    if value is None:
        return None
    try:
        return int(str(value))
    except (TypeError, ValueError):
        return None


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
    # Token valid for ~10 minutes skew
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


async def broadcast_event(
    event: str,
    payload: Any,
    course_id: Optional[int] = None,
    room_id: Optional[int] = None,
    ref_id: Optional[int] = None,
) -> int:
    if event not in ALLOWED_EVENTS:
        raise ValueError(f"Unsupported event: {event}")

    cleaned_payload = _sanitize_payload(payload)
    message = {
        "type": "event",
        "event": event,
        "course_id": course_id,
        "room_id": room_id,
        "ref_id": ref_id,
        "payload": cleaned_payload,
        "ts": int(time.time()),
    }

    delivered = 0
    to_close: Set[Client] = set()

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
        except Exception as exc:  # pragma: no cover - network errors
            LOGGER.debug("Failed to deliver to user %s: %s", client.user_id, exc)
            to_close.add(client)

    for client in to_close:
        CLIENTS.discard(client)
        try:
            await client.websocket.close(code=1011)
        except Exception:  # pragma: no cover
            pass

    return delivered


async def _handle_client(websocket: ServerConnection, query: Dict[str, str]) -> None:
    if not WS_SHARED_SECRET:
        await websocket.close(code=1013, reason="WS disabled")
        return

    origin = (websocket.request_headers.get("Origin") or "").rstrip("/")
    if ALLOWED_ORIGINS and origin and origin not in ALLOWED_ORIGINS:
        LOGGER.warning("WS: rejected origin %s (allowed=%s)", origin, ",".join(sorted(ALLOWED_ORIGINS)))
        await websocket.close(code=4403, reason="Origin not allowed")
        return

    raw_token = query.get("token", "")
    token_info = _validate_token(raw_token)
    if not token_info:
        LOGGER.warning("WS: invalid token (len=%d) for origin=%s", len(raw_token or ""), origin)
        await websocket.close(code=4401, reason="Invalid token")
        return

    raw_channels = query.get("channels", "")
    channels = {
        chan.strip()
        for chan in raw_channels.split(",")
        if chan.strip() in ALLOWED_EVENTS
    }
    if not channels:
        channels = set(ALLOWED_EVENTS)

    course_id = _parse_int(query.get("course_id"))
    room_id = _parse_int(query.get("room_id"))

    client = Client(
        websocket=websocket,
        channels=channels,
        course_id=course_id,
        room_id=room_id,
        user_id=token_info.get("user_id"),
    )
    CLIENTS.add(client)

    LOGGER.info(
        "Client connected user=%s channels=%s course=%s room=%s",
        client.user_id,
        ",".join(sorted(client.channels)),
        client.course_id,
        client.room_id,
    )

    try:
        async for _ in websocket:
            # we don't expect messages from browser clients, just keep connection alive
            pass
    except ConnectionClosedOK:
        pass
    except ConnectionClosedError:
        pass
    finally:
        CLIENTS.discard(client)
        LOGGER.info("Client disconnected user=%s", client.user_id)


async def _handle_emit(websocket: ServerConnection, query: Dict[str, str]) -> None:
    secret = query.get("secret", "")
    if not secret or not WS_SHARED_SECRET:
        await websocket.close(code=4401, reason="Missing secret")
        return

    import hmac

    if not hmac.compare_digest(secret, WS_SHARED_SECRET):
        await websocket.close(code=4401, reason="Invalid secret")
        return

    try:
        async for message in websocket:
            try:
                data = json.loads(message)
            except json.JSONDecodeError:
                await websocket.send(json.dumps({"ok": False, "error": "invalid_json"}))
                continue

            event = str(data.get("event", "")).strip()
            if event not in ALLOWED_EVENTS:
                await websocket.send(json.dumps({"ok": False, "error": "unsupported_event"}))
                continue

            course_id = _parse_int(data.get("course_id"))
            room_id = _parse_int(data.get("room_id"))
            ref_id = _parse_int(data.get("ref_id"))
            payload = data.get("payload")

            delivered = await broadcast_event(event, payload, course_id, room_id, ref_id)
            await websocket.send(json.dumps({"ok": True, "delivered": delivered}))
    except ConnectionClosed:
        # normal close from emitter side
        pass


async def _dispatch(connection: WebSocketServerProtocol) -> None:
    """
    Main dispatcher for incoming websocket connections.

    For websockets >= 13, the handler receives a 'ServerConnection' object
    (not the old (websocket, path) signature). The HTTP request is available
    via connection.request.
    """
    # Try to get the HTTP request object that initiated the WS upgrade
    req = getattr(connection, "request", None)
    if req is None:
        LOGGER.error("WS: connection.request is missing – cannot route")
        await connection.close(code=1011, reason="No request info")
        return

    # On recent websockets, Request usually has a 'target' like "/ws?foo=bar"
    target = getattr(req, "target", None)
    if not target:
        # Fallback: build a target from path + query if they exist
        path = getattr(req, "path", "/")
        query = getattr(req, "query", "") or getattr(req, "query_string", "")
        if query:
            target = f"{path}?{query}"
        else:
            target = path

    parsed = urlparse(target)
    query = {key: values[-1] for key, values in parse_qs(parsed.query).items() if values}

    LOGGER.info("WS dispatch path=%s query=%s", parsed.path, query)

    if parsed.path == "/emit":
        await _handle_emit(connection, query)
    else:
        await _handle_client(connection, query)


def _candidate_cert_paths() -> Tuple[Optional[str], Optional[str]]:
    """Return the first pair of certificate/key files that exist on disk."""

    if WS_SSL_CERT:
        cert_path = Path(WS_SSL_CERT).expanduser()
        key_path = Path(WS_SSL_KEY).expanduser() if WS_SSL_KEY else None
        missing = []
        if not cert_path.is_file():
            missing.append(f"certificate file '{cert_path}'")
        if key_path and not key_path.is_file():
            missing.append(f"key file '{key_path}'")
        if missing:
            missing_list = ", ".join(missing)
            raise FileNotFoundError(
                f"Configured TLS {missing_list} not found – refusing to start"
            )
        return str(cert_path), str(key_path) if key_path else None

    project_root = Path(__file__).resolve().parent
    default_dirs = [
        project_root / "config" / "ssl",
        project_root / "config",
        project_root,
    ]
    file_pairs = [
        ("ws.crt", "ws.key"),
        ("ws_cert.pem", "ws_key.pem"),
        ("server.crt", "server.key"),
        ("server.pem", "server.key"),
        ("cert.pem", "key.pem"),
        ("fullchain.pem", "privkey.pem"),
        ("ws.pem", None),
    ]

    for base in default_dirs:
        for cert_name, key_name in file_pairs:
            cert_path = base / cert_name
            if not cert_path.is_file():
                continue
            if key_name:
                key_path = base / key_name
                if not key_path.is_file():
                    continue
                return str(cert_path), str(key_path)
            return str(cert_path), None

    return None, None


def _build_ssl_context() -> Optional[ssl.SSLContext]:
    cert_path, key_path = _candidate_cert_paths()
    if not cert_path:
        LOGGER.warning("TLS certificates not found – running websocket relay without TLS")
        return None

    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    try:
        ctx.load_cert_chain(certfile=cert_path, keyfile=key_path)
    except FileNotFoundError:
        LOGGER.error("TLS certificate/key files missing (cert=%s key=%s)", cert_path, key_path)
        return None
    except ssl.SSLError as exc:
        LOGGER.error("Failed to load TLS cert chain (%s)", exc)
        return None

    LOGGER.info("Loaded TLS certificate from %s", cert_path)
    return ctx


async def main() -> None:
    ssl_context = _build_ssl_context()

    server = await websockets.serve(
        _dispatch,
        host=WS_HOST,
        port=WS_PORT,
        ssl=ssl_context,
        max_size=PAYLOAD_MAX_BYTES,
        ping_interval=20,
        ping_timeout=20,
    )
    LOGGER.info("Starting WS server on %s:%s", WS_HOST, WS_PORT)

    stop_event = asyncio.Event()

    def _shutdown() -> None:
        stop_event.set()

    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, _shutdown)
        except NotImplementedError:  # pragma: no cover (Windows)
            pass

    await stop_event.wait()
    LOGGER.info("Shutting down WS server")
    server.close()
    await server.wait_closed()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass