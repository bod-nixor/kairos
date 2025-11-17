"""WSGI-compatible Flask-SocketIO relay for Signoff events."""

from __future__ import annotations

import hmac
import os
import threading
import time
from dataclasses import dataclass
from typing import Any, Dict, Optional, Set

import eventlet
from flask import Flask, abort, jsonify, request
from flask_socketio import SocketIO, join_room


eventlet.monkey_patch()

DEFAULT_CHANNELS = {"rooms", "queue", "progress", "ta_accept"}
TOKEN_TTL_SECONDS = int(os.getenv("WS_TOKEN_TTL", "600") or 600)
WS_SHARED_SECRET = os.getenv("WS_SHARED_SECRET", "").strip()
if not WS_SHARED_SECRET:
    raise RuntimeError("WS_SHARED_SECRET must be configured")


def _parse_int(value: Any) -> Optional[int]:
    if value is None:
        return None
    try:
        return int(str(value).strip())
    except (TypeError, ValueError):
        return None


def _parse_channels(raw: Optional[str]) -> Set[str]:
    if not raw:
        return set(DEFAULT_CHANNELS)
    requested = {part.strip() for part in raw.split(",") if part.strip()}
    matched = requested & DEFAULT_CHANNELS
    return matched or set(DEFAULT_CHANNELS)


def _build_payload(message: Dict[str, Any]) -> Dict[str, Any]:
    payload_raw = message.get("payload")
    if isinstance(payload_raw, dict):
        payload = payload_raw
    else:
        payload = {"value": payload_raw}
    return {
        "type": "event",
        "event": str(message.get("event") or "").strip(),
        "course_id": _parse_int(message.get("course_id")),
        "room_id": _parse_int(message.get("room_id")),
        "ref_id": _parse_int(message.get("ref_id")),
        "payload": payload,
        "ts": int(time.time()),
    }


def _token_is_valid(token: Optional[str]) -> tuple[bool, Optional[int]]:
    if not token:
        return False, None
    parts = token.split(".")
    if len(parts) != 3:
        return False, None
    digest, ts_raw, user_raw = parts
    ts = _parse_int(ts_raw)
    user_id = _parse_int(user_raw)
    if ts is None or user_id is None:
        return False, None
    if TOKEN_TTL_SECONDS > 0 and abs(int(time.time()) - ts) > TOKEN_TTL_SECONDS:
        return False, None
    payload = f"{user_id}|{ts}".encode()
    expected = hmac.new(WS_SHARED_SECRET.encode(), payload, "sha256").hexdigest()
    if not hmac.compare_digest(expected, digest):
        return False, None
    return True, user_id


@dataclass
class ClientState:
    sid: str
    user_id: int
    channels: Set[str]
    course_id: Optional[int]
    room_id: Optional[int]


app = Flask(__name__)
socketio = SocketIO(app, async_mode="eventlet", cors_allowed_origins="*")

_connections: Dict[str, ClientState] = {}
_connections_lock = threading.Lock()


def _should_deliver(state: ClientState, payload: Dict[str, Any]) -> bool:
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
        inner = payload.get("payload")
        if isinstance(inner, dict):
            payload_room = _parse_int(inner.get("roomId"))
        if payload_room != state.room_id:
            return False

    return True


def _record_connection(sid: str, state: ClientState) -> None:
    with _connections_lock:
        _connections[sid] = state


def _drop_connection(sid: str) -> None:
    with _connections_lock:
        _connections.pop(sid, None)


def _emit_to_matching_clients(payload: Dict[str, Any]) -> int:
    targets: list[str] = []
    with _connections_lock:
        for sid, state in list(_connections.items()):
            if _should_deliver(state, payload):
                targets.append(sid)
    for sid in targets:
        socketio.emit(payload["event"], payload, room=sid)
    return len(targets)


@socketio.on("connect")
def handle_connect():
    token = request.args.get("token", "").strip()
    ok, user_id = _token_is_valid(token)
    if not ok or user_id is None:
        return False

    channels = _parse_channels(request.args.get("channels"))
    course_id = _parse_int(request.args.get("course_id"))
    room_id = _parse_int(request.args.get("room_id"))
    sid = request.sid

    state = ClientState(
        sid=sid,
        user_id=user_id,
        channels=channels,
        course_id=course_id,
        room_id=room_id,
    )
    _record_connection(sid, state)

    for channel in channels:
        join_room(f"channel::{channel}")
        if course_id is not None:
            join_room(f"channel::{channel}::course::{course_id}")
        if room_id is not None:
            join_room(f"channel::{channel}::room::{room_id}")
        if course_id is not None and room_id is not None:
            join_room(f"channel::{channel}::course::{course_id}::room::{room_id}")

    app.logger.info(
        "client connected user=%s channels=%s course_id=%s room_id=%s",
        user_id,
        ",".join(sorted(channels)) or "*",
        course_id,
        room_id,
    )


@socketio.on("disconnect")
def handle_disconnect():
    sid = request.sid
    state = _connections.get(sid)
    if state:
        app.logger.info("client disconnected user=%s", state.user_id)
    _drop_connection(sid)


@app.post("/emit")
def handle_emit():
    provided_secret = request.args.get("secret", "")
    if not provided_secret or not hmac.compare_digest(provided_secret, WS_SHARED_SECRET):
        abort(403)

    message = request.get_json(silent=True)
    if not isinstance(message, dict):
        abort(400, description="invalid json payload")

    event_name = str(message.get("event") or "").strip()
    if not event_name:
        abort(400, description="event is required")

    outbound = _build_payload(message)
    outbound["event"] = event_name

    recipients = _emit_to_matching_clients(outbound)
    return jsonify({"ok": True, "sent": recipients})


application = socketio.wsgi_app


__all__ = ["app", "socketio", "application"]
