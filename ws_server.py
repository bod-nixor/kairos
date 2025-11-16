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
from urllib.parse import parse_qs, parse_qsl, urlencode, urlparse, urlsplit

import websockets

try:  # websockets >= 10
    from websockets.asyncio.server import ServerConnection
except ImportError:  # pragma: no cover - legacy fallback
    from websockets.server import WebSocketServerProtocol as ServerConnection  # type: ignore

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

_SENSITIVE_QUERY_KEYS = {"token", "secret"}


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


def _extract_request_context(connection: ServerConnection) -> Tuple[str, Dict[str, str], str, str]:
    """Return (path, query, origin, target) for a websocket connection."""

    origin = ""
    target = ""

    request = getattr(connection, "request", None)
    headers = None
    if request is not None:
        headers = getattr(request, "headers", None)
    if headers is None:
        headers = getattr(connection, "request_headers", None)

    if headers:
        try:
            origin = (headers.get("Origin") or "").rstrip("/")
        except Exception:  # pragma: no cover - very defensive
            origin = ""

    if request is not None:
        target = getattr(request, "target", None) or ""
        if not target:
            req_path = getattr(request, "path", None) or ""
            req_query = (
                getattr(request, "query", None)
                or getattr(request, "query_string", None)
                or ""
            )
            if req_query:
                target = f"{req_path or '/'}?{req_query}"
            else:
                target = req_path or ""

    if not target:
        target = getattr(connection, "path", "") or ""

    if not target:
        target = "/"

    parsed = urlparse(target)
    path = parsed.path or "/"
    query = {key: values[-1] for key, values in parse_qs(parsed.query).items() if values}

    return path, query, origin, target


def _scrub_request_target(target: str) -> str:
    """Return the request target without sensitive query parameters."""

    if not target:
        return "/"

    try:
        parsed = urlsplit(target)
    except ValueError:
        return target

    filtered = [
        (key, value)
        for key, value in parse_qsl(parsed.query, keep_blank_values=True)
        if key.lower() not in _SENSITIVE_QUERY_KEYS
    ]
    safe_query = urlencode(filtered, doseq=True)
    safe_path = parsed.path or "/"
    if safe_query:
        return f"{safe_path}?{safe_query}"
    return safe_path


def _scrub_query(query: Dict[str, str]) -> Dict[str, str]:
    """Return a shallow copy of `query` with sensitive values redacted."""

    safe = {}
    for key, value in query.items():
        if key.lower() in _SENSITIVE_QUERY_KEYS:
            safe[key] = "<redacted>"
        else:
            safe[key] = value
    return safe


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


async def _handle_client(
    websocket: ServerConnection,
    query: Dict[str, str],
    origin: str,
    request_target: str,
) -> None:
    client: Optional[Client] = None
    safe_target = _scrub_request_target(request_target)

    if not WS_SHARED_SECRET:
        LOGGER.error("WS: shared secret missing – rejecting client from %s", origin or "<unknown>")
        await websocket.close(code=1013, reason="WS disabled")
        return

    origin = origin.rstrip("/") if origin else ""
    if ALLOWED_ORIGINS and origin and origin not in ALLOWED_ORIGINS:
        LOGGER.warning(
            "WS: rejected origin %s for target=%s (allowed=%s)",
            origin,
            safe_target,
            ",".join(sorted(ALLOWED_ORIGINS)),
        )
        await websocket.close(code=4403, reason="Origin not allowed")
        return

    raw_token = query.get("token", "")
    token_info = _validate_token(raw_token)
    if not token_info:
        LOGGER.warning(
            "WS: invalid token (len=%d) for origin=%s target=%s",
            len(raw_token or ""),
            origin or "<none>",
            safe_target,
        )
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
        "WS: client accepted user=%s origin=%s target=%s channels=%s course=%s room=%s token_len=%d",
        client.user_id,
        origin or "<none>",
        safe_target,
        ",".join(sorted(client.channels)),
        client.course_id,
        client.room_id,
        len(raw_token or ""),
    )

    try:
        async for _ in websocket:
            # we don't expect messages from browser clients, just keep connection alive
            pass
    except ConnectionClosedOK:
        LOGGER.info(
            "WS: graceful close user=%s code=%s", client.user_id, websocket.close_code
        )
    except ConnectionClosedError:
        LOGGER.info(
            "WS: connection error user=%s code=%s", client.user_id, websocket.close_code
        )
    except Exception:
        LOGGER.exception("WS: unhandled error while streaming to user=%s", client.user_id)
        try:
            await websocket.close(code=1011, reason="Internal error")
        except Exception:  # pragma: no cover
            pass
    finally:
        if client:
            CLIENTS.discard(client)
            LOGGER.info(
                "WS: client disconnected user=%s code=%s reason=%s",
                client.user_id,
                websocket.close_code,
                websocket.close_reason,
            )


async def _handle_emit(
    websocket: ServerConnection,
    query: Dict[str, str],
    origin: str,
    request_target: str,
) -> None:
    safe_target = _scrub_request_target(request_target)
    secret = query.get("secret", "")
    if not secret or not WS_SHARED_SECRET:
        LOGGER.warning(
            "WS: emitter rejected (missing secret) origin=%s target=%s",
            origin or "<none>",
            safe_target,
        )
        await websocket.close(code=4401, reason="Missing secret")
        return

    import hmac

    if not hmac.compare_digest(secret, WS_SHARED_SECRET):
        LOGGER.warning(
            "WS: emitter rejected (invalid secret) origin=%s target=%s",
            origin or "<none>",
            safe_target,
        )
        await websocket.close(code=4401, reason="Invalid secret")
        return

    try:
        LOGGER.info(
            "WS: emitter connected origin=%s target=%s", origin or "<none>", safe_target
        )
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
        LOGGER.info(
            "WS: emitter disconnected code=%s reason=%s",
            websocket.close_code,
            websocket.close_reason,
        )
    except Exception:
        LOGGER.exception(
            "WS: emitter error origin=%s target=%s", origin or "<none>", safe_target
        )
        try:
            await websocket.close(code=1011, reason="Internal error")
        except Exception:  # pragma: no cover
            pass


async def _dispatch(connection: ServerConnection) -> None:
    """Route an upgraded websocket connection to the appropriate handler."""

    origin = ""
    target = ""
    safe_target = ""
    try:
        path, query, origin, target = _extract_request_context(connection)
        safe_target = _scrub_request_target(target)
        safe_query = _scrub_query(query)
        LOGGER.info(
            "WS: incoming connection target=%s origin=%s query=%s remote=%s",
            safe_target,
            origin or "<none>",
            safe_query,
            connection.remote_address,
        )

        if path == "/emit":
            await _handle_emit(connection, query, origin, target)
        else:
            await _handle_client(connection, query, origin, target)
    except Exception:
        LOGGER.exception(
            "WS: dispatcher failure origin=%s target=%s",
            origin or "<none>",
            safe_target or target or "<unknown>",
        )
        try:
            await connection.close(code=1011, reason="Dispatch error")
        except Exception:  # pragma: no cover
            pass


def _build_ssl_context() -> Optional[ssl.SSLContext]:
    if not WS_SSL_CERT or not WS_SSL_KEY:
        LOGGER.warning(
            "WS_SSL_CERT/WS_SSL_KEY not configured – running websocket relay without TLS"
        )
        return None

    cert_path = Path(WS_SSL_CERT).expanduser()
    key_path = Path(WS_SSL_KEY).expanduser()

    if not cert_path.is_file():
        raise FileNotFoundError(f"TLS certificate file not found: {cert_path}")
    if not key_path.is_file():
        raise FileNotFoundError(f"TLS key file not found: {key_path}")

    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    try:
        ctx.load_cert_chain(certfile=str(cert_path), keyfile=str(key_path))
    except ssl.SSLError as exc:
        LOGGER.error("Failed to load TLS cert chain (%s)", exc)
        raise

    LOGGER.info("Loaded TLS certificate from %s", cert_path)
    return ctx


async def main() -> None:
    try:
        ssl_context = _build_ssl_context()
    except FileNotFoundError as exc:
        LOGGER.error("%s", exc)
        raise

    if ssl_context is None:
        LOGGER.warning(
            "WS: starting without TLS on %s:%s – this should only happen in development",
            WS_HOST,
            WS_PORT,
        )

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