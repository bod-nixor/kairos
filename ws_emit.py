"""CLI helper to forward Signoff events to the websocket relay."""
from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_EMIT_URL = "http://127.0.0.1:8090/emit"


def _load_payload(raw: str | None):
    if raw is None:
        return None
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return raw


def send_event(url: str, secret: str, event: dict) -> int:
    query = urllib.parse.urlencode({"secret": secret})
    target = f"{url}?{query}"
    data = json.dumps(event).encode()
    req = urllib.request.Request(
        target,
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=10) as resp:
        resp.read()
        return resp.status


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--event", required=True, help="event name to emit")
    parser.add_argument("--secret", required=True, help="shared secret")
    parser.add_argument("--course-id", type=int, default=None)
    parser.add_argument("--room-id", type=int, default=None)
    parser.add_argument("--ref-id", type=int, default=None)
    parser.add_argument("--payload", default=None, help="JSON payload (or raw string)")
    parser.add_argument(
        "--url",
        default=os.environ.get("WS_EMIT_URL", DEFAULT_EMIT_URL),
        help="HTTP endpoint for websocket relay (default: %(default)s)",
    )

    args = parser.parse_args(argv)

    payload = _load_payload(args.payload)
    message = {
        "event": args.event,
        "course_id": args.course_id,
        "room_id": args.room_id,
        "ref_id": args.ref_id,
        "payload": payload,
    }

    try:
        status = send_event(args.url, args.secret, message)
        print(f"sent {args.event} to {args.url} (status={status})")
        return 0
    except urllib.error.HTTPError as exc:  # pragma: no cover - CLI diagnostics
        print(f"HTTP error {exc.code}: {exc.reason}", file=sys.stderr)
        return 1
    except Exception as exc:  # pragma: no cover - CLI diagnostics
        print(f"failed to send event: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
