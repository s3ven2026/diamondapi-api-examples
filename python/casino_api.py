"""Small helper for the Diamond API. Python 3.8+, needs only `requests`.

    DIAMONDAPI_KEY       your key (fg_test_... for the sandbox, fg_live_... for live)
    DIAMONDAPI_BASE_URL  optional, defaults to the sandbox: https://api-sandbox.diamondapi.live
"""
import json
import os
import random
import sys
import time

import requests

BASE = os.environ.get("DIAMONDAPI_BASE_URL", "https://api-sandbox.diamondapi.live").rstrip("/")


def api_key():
    key = os.environ.get("DIAMONDAPI_KEY")
    if not key:
        sys.exit("Set DIAMONDAPI_KEY first. Ask for a sandbox key at https://diamondapi.live/telegram")
    return key


def is_sandbox_key():
    return api_key().startswith("fg_test_")


class ApiError(Exception):
    def __init__(self, status, code, message, request_id=None):
        super().__init__(f"{status} {code}: {message}" + (f" (X-Request-Id {request_id})" if request_id else ""))
        self.status, self.code, self.request_id = status, code, request_id


session = requests.Session()
session.headers.update({"Accept": "application/json", "User-Agent": "diamondapi-api-examples/1.0"})
# requests sends Accept-Encoding: gzip and decompresses for you.

_last_call = [0.0]


def _pace():
    # The sandbox allows 2 requests per second; 0.55 s between calls stays inside it.
    wait = _last_call[0] + 0.55 - time.time()
    if wait > 0:
        time.sleep(wait)
    _last_call[0] = time.time()


def get(path, params=None, headers=None):
    """GET a path. Retries 429 rate_limited and 503 starting. Returns the Response."""
    params = {k: v for k, v in (params or {}).items() if v not in (None, "")}
    h = {"X-API-Key": api_key(), **(headers or {})}
    for attempt in range(5):
        _pace()
        r = session.get(BASE + path, params=params, headers=h, timeout=(10, 30))
        if r.status_code < 400:
            return r
        try:
            body = r.json()
        except ValueError:
            body = {}
        code = body.get("code", f"http_{r.status_code}")
        if attempt < 4 and (r.status_code, code) in ((429, "rate_limited"), (503, "starting")):
            after = float(r.headers.get("Retry-After") or 2)
            print(f"... {code}, retrying in {after:g}s", file=sys.stderr)
            time.sleep(after)
            continue
        raise ApiError(r.status_code, code, body.get("message", r.text[:200]), r.headers.get("X-Request-Id"))


def get_json(path, params=None):
    return get(path, params).json()


def open_table_ids(limit):
    """The first tables taking bets now, for examples that need a few to work with."""
    tables = get_json("/v1/casino/tables")["tables"]
    return [t["id"] for t in tables if t["status"] == "OPEN"][:limit]


def main(fn):
    """Run fn(); print API errors cleanly instead of a traceback."""
    try:
        fn()
    except ApiError as e:
        print(e, file=sys.stderr)
        if e.status == 403:
            print("Requests are accepted only from IPs on your allowlist, and a key works only in its own environment.", file=sys.stderr)
        sys.exit(1)
    except KeyboardInterrupt:
        pass


def stream(url, on_message, on_status=lambda s: print(f"[stream] {s}", file=sys.stderr)):
    """Read /v1/casino/stream for ever, resuming with Last-Event-ID.

    - sends the id of the last message back as Last-Event-ID on each reconnect,
      so nothing is missed;
    - a `reconnect` message (about every 14 minutes, and before a server
      restart) closes the connection and reconnects at once;
    - a dropped connection, including a 15-minute (900 s) cut by a proxy, is
      resumed after the server's retry: hint plus jitter;
    - 45 s without a byte is a dead connection (heartbeats come every 15 s):
      the read timeout below catches it;
    - an `error` message, or 400/401/403/404, stops: retrying will not help.
    """
    last_id, retry_ms, failures = None, 1000, 0
    while True:
        rotate = connected = False
        headers = {"X-API-Key": api_key(), "Accept": "text/event-stream", "Cache-Control": "no-cache"}
        if last_id:
            headers["Last-Event-ID"] = last_id
        on_status(f"connecting, resuming after {last_id}" if last_id else "connecting")
        try:
            # (connect timeout, read timeout): no byte for 45 s raises and we reconnect.
            with session.get(url, headers=headers, stream=True, timeout=(10, 45)) as r:
                if r.status_code >= 400:
                    try:
                        body = r.json()
                    except ValueError:
                        body = {}
                    msg = f"{r.status_code} {body.get('code', '')} {body.get('message', '')}".strip()
                    if r.status_code in (400, 401, 403, 404):
                        raise ApiError(r.status_code, body.get("code"), body.get("message"), r.headers.get("X-Request-Id"))
                    after = float(r.headers.get("Retry-After") or min(30, 5 * 2 ** failures))
                    on_status(f"{msg}; retrying in {after:g} s")
                    failures += 1
                    time.sleep(after)
                    continue
                failures, connected = 0, True
                on_status("connected")
                event, data, msg_id = "message", [], None
                for raw in r.iter_lines(chunk_size=None):
                    line = raw.decode("utf-8")
                    if line == "":  # end of one message
                        if msg_id is not None:
                            last_id = msg_id
                        if data:
                            text = "\n".join(data)
                            try:
                                payload = json.loads(text)
                            except ValueError:
                                payload = text
                            if event == "reconnect":
                                on_status("server asked us to reconnect")
                                rotate = True
                                break
                            if event == "error":
                                raise ApiError("stream", payload.get("code"), payload.get("message"))
                            on_message(event, payload, last_id)
                        event, data, msg_id = "message", [], None
                        continue
                    if line.startswith(":"):
                        continue  # comment, e.g. ": heartbeat"
                    field, _, value = line.partition(":")
                    value = value[1:] if value.startswith(" ") else value
                    if field == "event":
                        event = value
                    elif field == "data":
                        data.append(value)
                    elif field == "id":
                        msg_id = value
                    elif field == "retry" and value.isdigit():
                        retry_ms = int(value)
                if not rotate:
                    on_status("connection closed by server")
        except requests.RequestException as e:
            on_status(f"connection lost: {type(e).__name__}")
            if not connected:
                failures += 1
        if not rotate:
            time.sleep(min(30, retry_ms / 1000 * 2 ** min(failures, 5)) + random.random())
