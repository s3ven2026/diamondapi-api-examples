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


def request(path, params=None, headers=None, body=None):
    """GET a path, or POST `body` as JSON. Retries 429 rate_limited and 503 starting. Returns the Response."""
    params = {k: v for k, v in (params or {}).items() if v not in (None, "")}
    h = {"X-API-Key": api_key(), **(headers or {})}
    for attempt in range(5):
        _pace()
        r = session.request("POST" if body is not None else "GET", BASE + path, params=params, json=body, headers=h, timeout=(10, 30))
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
    return request(path, params).json()


def post_json(path, body):
    return request(path, body=body).json()


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
