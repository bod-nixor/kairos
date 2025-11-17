#!/usr/bin/env python3
"""Signoff WebSocket relay.

This service accepts front-end WebSocket subscriptions on ``/ws`` and
backend event injections on ``/emit``. The Apache/LiteSpeed proxy in
front of cPanel handles TLS and forwards traffic to this process.
"""

from __future__ import annotations

import asyncio
import hmac
import json
import logging
import os
import signal
import ssl
import threading
import time
from dataclasses import dataclass
from typing import Any, Optional
from urllib.parse import parse_qs, urlparse

from websockets.exceptions import ConnectionClosed
from websockets.server import WebSocketServerProtocol, serve

DEFAULT_CHANNELS = {"rooms", "queue", "progress", "ta_accept"}
TOKEN_TTL_SECONDS = int(os.getenv("WS_TOKEN_TTL", "600") or 600)


@dataclass
class ClientState:
    websocket: WebSocketServerProtocol
    user_id: int
    channels: set[str]
    course_id: Optional[int]
    room_id: Optional[int]


def _env(name: str, default: str) -> str:
    value = os.getenv(name)
    if value is None or value == "":
        return default
    return value


def _parse_int(value: Any) -> Optional[int]:
    if value is None:
        return None
    try:
        return int(str(value).strip())
    except (TypeError, ValueError):
        return None


def _build_ssl_context() -> Optional[ssl.SSLContext]:
    """Return a TLS context when both cert/key paths are configured."""

    cert_path = os.getenv("WS_SSL_CERT")
    key_path = os.getenv("WS_SSL_KEY")
    if not cert_path or not key_path:
        return None

    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(certfile=cert_path, keyfile=key_path)
    return context


class SignoffRelay:
    def __init__(self) -> None:
        self._clients: dict[WebSocketServerProtocol, ClientState] = {}
        self._shared_secret = os.getenv("WS_SHARED_SECRET", "").strip()
        if not self._shared_secret:
            raise RuntimeError("WS_SHARED_SECRET must be configured")
        self._lock = asyncio.Lock()

    async def dispatch(self, websocket: WebSocketServerProtocol, raw_path: str) -> None:
        parsed = urlparse(raw_path or "/")
        path = parsed.path or "/"
        params = parse_qs(parsed.query)

        if path == "/emit":
            await self._handle_emit(websocket, params)
            return
        if path == "/ws":
            await self._handle_client(websocket, params)
            return

        await websocket.close(code=4004, reason="unknown endpoint")

    async def _handle_client(
        self, websocket: WebSocketServerProtocol, params: dict[str, list[str]]
    ) -> None:
        token = self._single_param(params, "token")
        user_id = self._verify_token(token)
        if user_id is None:
            await websocket.close(code=4003, reason="invalid token")
            return

        channels = self._parse_channels(params)
        course_id = _parse_int(self._single_param(params, "course_id"))
        room_id = _parse_int(self._single_param(params, "room_id"))

        state = ClientState(
            websocket=websocket,
            user_id=user_id,
            channels=channels,
            course_id=course_id,
            room_id=room_id,
        )

        logging.info(
            "client connected user=%s channels=%s course_id=%s room_id=%s",
            user_id,
            ",".join(sorted(channels)) or "*",
            course_id,
            room_id,
        )

        async with self._lock:
            self._clients[websocket] = state

        try:
            async for _ in websocket:
                # The frontend never sends payloads; discard everything just in case.
                continue
        except ConnectionClosed:
            pass
        finally:
            async with self._lock:
                self._clients.pop(websocket, None)
            logging.info("client disconnected user=%s", user_id)

    async def _handle_emit(
        self, websocket: WebSocketServerProtocol, params: dict[str, list[str]]
    ) -> None:
        provided_secret = self._single_param(params, "secret")
        if not provided_secret or not hmac.compare_digest(provided_secret, self._shared_secret):
            await websocket.close(code=4003, reason="forbidden")
            return

        try:
            async for raw in websocket:
                await self._process_emit_message(websocket, raw)
        except ConnectionClosed:
            pass

    async def _process_emit_message(self, websocket: WebSocketServerProtocol, raw: str) -> None:
        try:
            message = json.loads(raw)
        except json.JSONDecodeError:
            await self._send_ack(websocket, ok=False, error="invalid json")
            return

        event_name = str(message.get("event") or "").strip()
        if not event_name:
            await self._send_ack(websocket, ok=False, error="event required")
            return

        payload: dict[str, Any]
        payload_raw = message.get("payload")
        if isinstance(payload_raw, dict):
            payload = payload_raw
        else:
            payload = {"value": payload_raw}

        outbound = {
            "type": "event",
            "event": event_name,
            "course_id": _parse_int(message.get("course_id")),
            "room_id": _parse_int(message.get("room_id")),
            "ref_id": _parse_int(message.get("ref_id")),
            "payload": payload,
            "ts": int(time.time()),
        }

        await self._broadcast(outbound)
        await self._send_ack(websocket, ok=True)

    async def _broadcast(self, payload: dict[str, Any]) -> None:
        if not self._clients:
            return

        message = json.dumps(payload, separators=(",", ":"))
        targets: list[WebSocketServerProtocol] = []
        async with self._lock:
            for ws, state in list(self._clients.items()):
                if self._should_deliver(state, payload):
                    targets.append(ws)

        if not targets:
            return

        await asyncio.gather(*(self._safe_send(ws, message) for ws in targets))

    async def _safe_send(self, websocket: WebSocketServerProtocol, message: str) -> None:
        try:
            await websocket.send(message)
        except ConnectionClosed:
            async with self._lock:
                self._clients.pop(websocket, None)

    def _should_deliver(self, state: ClientState, payload: dict[str, Any]) -> bool:
        event_name = payload.get("event")
        if event_name not in state.channels:
            return False

        event_course = _parse_int(payload.get("course_id"))
        if state.course_id is not None and event_course != state.course_id:
            payload_course = None
            inner = payload.get("payload")
            if isinstance(inner, dict):
                payload_course = _parse_int(inner.get("courseId"))
            if payload_course != state.course_id:
                return False

        event_room = _parse_int(payload.get("room_id"))
        if state.room_id is not None and event_room != state.room_id:
            payload_room = None
            inner = payload.get("payload" )
            if isinstance(inner, dict):
                payload_room = _parse_int(inner.get("roomId"))
            if payload_room != state.room_id:
                return False

        return True

    async def _send_ack(self, websocket: WebSocketServerProtocol, *, ok: bool, error: str | None = None) -> None:
        message = {"ok": ok}
        if error:
            message["error"] = error
        try:
            await websocket.send(json.dumps(message, separators=(",", ":")))
        except ConnectionClosed:
            pass

    def _single_param(self, params: dict[str, list[str]], key: str) -> Optional[str]:
        values = params.get(key)
        if not values:
            return None
        return values[0]

    def _parse_channels(self, params: dict[str, list[str]]) -> set[str]:
        raw = self._single_param(params, "channels")
        if not raw:
            return set(DEFAULT_CHANNELS)
        requested = {part.strip() for part in raw.split(",") if part.strip()}
        matched = requested & DEFAULT_CHANNELS
        return matched or set(DEFAULT_CHANNELS)

    def _verify_token(self, token: Optional[str]) -> Optional[int]:
        if not token:
            return None
        parts = token.split(".")
        if len(parts) != 3:
            return None
        digest, ts_raw, user_raw = parts
        ts = _parse_int(ts_raw)
        user_id = _parse_int(user_raw)
        if ts is None or user_id is None:
            return None
        if TOKEN_TTL_SECONDS > 0 and abs(int(time.time()) - ts) > TOKEN_TTL_SECONDS:
            return None
        payload = f"{user_id}|{ts}".encode()
        expected = hmac.new(self._shared_secret.encode(), payload, "sha256").hexdigest()
        if not hmac.compare_digest(expected, digest):
            return None
        return user_id


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="[%(asctime)s] %(levelname)s %(message)s")
    host = _env("WS_HOST", "127.0.0.1")
    port = int(_env("WS_PORT", "8090"))
    ssl_context = _build_ssl_context()
    relay = SignoffRelay()

    async def _run() -> None:
        stop: asyncio.Future[None] = asyncio.Future()
        loop = asyncio.get_running_loop()
        for signame in {signal.SIGINT, signal.SIGTERM}:
            loop.add_signal_handler(signame, stop.set_result, None)

        async with serve(relay.dispatch, host, port, ssl=ssl_context, ping_interval=30, ping_timeout=30):
            logging.info("WebSocket relay listening on %s:%s", host, port)
            await stop

    try:
        asyncio.run(_run())
    except KeyboardInterrupt:
        pass

# --- WSGI HACK FOR CPANEL ---

# We need to run our server, but cPanel's Passenger/WSGI
# runner wants to import this file and find an 'application'
# object. It doesn't know how to "run" the file.
#
# So, we do two things:
# 1. Start our `main()` server function in a new, background
#    thread. This lets the server run while Passenger
#    continues to load the rest of this file.
threading.Thread(target=main, daemon=True).start()


# 2. We provide the dummy 'application' object that
#    Passenger requires. This code will never be called
#    because the proxy routes traffic to our server thread,
#    but its presence stops Passenger from crashing.
def application(env, start_response):
    """A dummy WSGI app to satisfy Passenger."""
    start_response('200 OK', [('Content-Type','text/plain')])
    return [b'WebSocket server is running in a background thread.']

# --- END WSGI HACK ---