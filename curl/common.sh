# Shared helpers for the curl examples. Needs bash, curl and jq.
#
#   DIAMONDAPI_KEY       your key (fg_test_... for the sandbox, fg_live_... for live)
#   DIAMONDAPI_BASE_URL  optional, defaults to the sandbox: https://api-sandbox.diamondapi.live

set -euo pipefail

BASE="${DIAMONDAPI_BASE_URL:-https://api-sandbox.diamondapi.live}"
BASE="${BASE%/}"
UA="diamondapi-api-examples/1.0"

command -v jq >/dev/null || { echo "These examples need jq: https://jqlang.github.io/jq/" >&2; exit 2; }
if [ -z "${DIAMONDAPI_KEY:-}" ]; then
  echo "Set DIAMONDAPI_KEY first. Ask for a sandbox key at https://diamondapi.live/telegram" >&2
  exit 2
fi

# The key goes to curl on stdin (-H @-), so it never shows in the process list.
key_header() { printf 'X-API-Key: %s\n' "$DIAMONDAPI_KEY"; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# api PATH [curl args...]  ->  JSON body on stdout. Exits on an API error.
# Spaces calls 0.55 s apart (the sandbox allows 2 requests per second) and
# retries 429 rate_limited / 503 starting after Retry-After.
api() {
  local path="$1"; shift
  local attempt status code
  for attempt in 1 2 3 4 5; do
    sleep 0.55
    status=$(key_header | curl -sS --compressed -H @- -H 'Accept: application/json' -A "$UA" \
      -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$@" "$BASE$path")
    if [ "$status" -lt 400 ]; then cat "$TMP/b"; return 0; fi
    [ "$status" = 410 ] && return 10   # resync: the caller takes a fresh snapshot / cursor
    code=$(jq -r '.code // empty' "$TMP/b" 2>/dev/null || true)
    if { [ "$status" = 429 ] && [ "$code" = rate_limited ]; } || { [ "$status" = 503 ] && [ "$code" = starting ]; }; then
      local after; after=$(awk -F': ' 'tolower($1)=="retry-after"{print $2+0}' "$TMP/h"); after=${after:-2}
      echo "... $code, retrying in ${after}s" >&2; sleep "$after"; continue
    fi
    echo "$status $(jq -r '"\(.code): \(.message)"' "$TMP/b" 2>/dev/null || cat "$TMP/b")" \
      "(X-Request-Id $(awk -F': ' 'tolower($1)=="x-request-id"{print $2}' "$TMP/h" | tr -d '\r'))" >&2
    [ "$status" = 403 ] && echo "Requests are accepted only from IPs on your allowlist, and a key works only in its own environment." >&2
    exit 1
  done
}

