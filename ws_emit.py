#!/usr/bin/env python3
"""Utility to push events into the Kairos Signoff WebSocket relay."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import ssl
from typing import Any, Optional
from urllib.parse import urlencode, urlparse, urlunparse

import websockets

PAYLOAD_MAX_BYTES = 32 * 1024
DEFAULT_PORT = 8090
DEFAULT_SCHEME = "ws"


def _env(name: str, default: Optional[str] = None) -> Optional[str]:
    value = os.getenv(name)
    if value is None or value == "":
        return default
    return value


def _bool_env(name: str, default: bool = False) -> bool:
    raw = _env(name)
    if raw is None:
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _default_url() -> str:
    port = int(_env("WS_PORT", str(DEFAULT_PORT)) or DEFAULT_PORT)
    host = _env("WS_EMIT_HOST", "127.0.0.1") or "127.0.0.1"
    scheme = _env("WS_EMIT_SCHEME", DEFAULT_SCHEME) or DEFAULT_SCHEME
    if scheme not in {"ws", "wss"}:
        scheme = DEFAULT_SCHEME
    return f"{scheme}://{host}:{port}/emit"


def _append_query(url: str, params: dict[str, str]) -> str:
    parsed = urlparse(url)
    query = parsed.query
    extra = urlencode(params)
    if query:
        query = f"{query}&{extra}"
    else:
        query = extra
    rebuilt = parsed._replace(query=query)
    return urlunparse(rebuilt)


def _build_ssl_context(verify: bool) -> ssl.SSLContext:
    ctx = ssl.create_default_context()
    if not verify:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    return ctx


async def _send_event(args: argparse.Namespace) -> None:
    url = args.url or _env("WS_EMIT_URL") or _default_url()
    secret = args.secret or _env("WS_SHARED_SECRET")
    if not secret:
        raise SystemExit("WS_SHARED_SECRET is required for ws_emit")

    payload_raw = args.payload
    try:
        payload = json.loads(payload_raw) if payload_raw is not None else None
    except json.JSONDecodeError as exc:  # pragma: no cover - defensive
        raise SystemExit(f"Invalid JSON payload: {exc}")

    message: dict[str, Any] = {
        "event": args.event,
        "course_id": args.course_id,
        "room_id": args.room_id,
        "ref_id": args.ref_id,
        "payload": payload,
    }

    verify_cert = args.verify or _bool_env("WS_EMIT_VERIFY_CERT", False)

    parsed_url = urlparse(url)
    use_ssl = (parsed_url.scheme or "ws").lower() == "wss"
    ssl_context = _build_ssl_context(verify_cert) if use_ssl else None

    target = _append_query(url, {"secret": secret})

    async with websockets.connect(
        target,
        ssl=ssl_context,
        max_size=PAYLOAD_MAX_BYTES,
        ping_interval=None,
    ) as websocket:
        await websocket.send(json.dumps(message, separators=(",", ":")))
        try:
            response = await asyncio.wait_for(websocket.recv(), timeout=5)
            data = json.loads(response)
            if not data.get("ok"):
                raise SystemExit(f"Emit failed: {data}")
        except asyncio.TimeoutError:
            pass


def main() -> None:
    parser = argparse.ArgumentParser(description="Send a WS event")
    parser.add_argument("--event", required=True, help="Event name (rooms/queue/progress/ta_accept)")
    parser.add_argument("--course-id", type=int, default=None)
    parser.add_argument("--room-id", type=int, default=None)
    parser.add_argument("--ref-id", type=int, default=None)
    parser.add_argument("--payload", default="null", help="JSON payload")
    parser.add_argument("--secret", default=None, help="Override WS shared secret")
    parser.add_argument("--url", default=None, help="Override websocket emit URL")
    parser.add_argument(
        "--verify",
        action="store_true",
        help="Verify TLS certificates (disabled by default for localhost connections)",
    )

    args = parser.parse_args()
    if args.event not in {"rooms", "queue", "progress", "ta_accept"}:
        raise SystemExit("Unsupported event")

    asyncio.run(_send_event(args))


if __name__ == "__main__":
    main()
