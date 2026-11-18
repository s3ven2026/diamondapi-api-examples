#!/usr/bin/env bash
# Test your wallet endpoint the way we call it: signed balance, debit, a retried debit,
# credit and refund calls. Needs bash, curl, jq and openssl; no API key.
#   DIAMONDAPI_WALLET_SECRET=... ./wallet-test.sh [http://localhost:8080/wallet] [playerId]
set -euo pipefail
URL="${1:-http://localhost:8080/wallet}"; P="${2:-player-1}"
: "${DIAMONDAPI_WALLET_SECRET:?Set DIAMONDAPI_WALLET_SECRET first}"
R="$RANDOM$RANDOM"

call() { # call JSON -> prints the wallet's answer
  local sig; sig=$(printf '%s' "$1" | openssl dgst -sha256 -hmac "$DIAMONDAPI_WALLET_SECRET" -r | cut -d' ' -f1)
  printf '%-42s -> %s\n' "$(jq -c '{action, txId, amount}' <<<"$1")" "$(curl -sS -X POST -H 'Content-Type: application/json' -H "X-Signature: $sig" --data-binary "$1" "$URL")"
}

call '{"action":"balance","playerId":"'"$P"'"}'
call '{"action":"debit","txId":"d1-'"$R"'","playerId":"'"$P"'","amount":5000,"gameId":"teen20","roundId":"r1"}'
echo "retry of the same debit: the answer must match and the balance must not drop again"
call '{"action":"debit","txId":"d1-'"$R"'","playerId":"'"$P"'","amount":5000,"gameId":"teen20","roundId":"r1"}'
call '{"action":"credit","txId":"c1-'"$R"'","playerId":"'"$P"'","amount":9800,"gameId":"teen20","roundId":"r1"}'
call '{"action":"debit","txId":"d2-'"$R"'","playerId":"'"$P"'","amount":2000,"gameId":"teen20","roundId":"r2"}'
call '{"action":"refund","txId":"f2-'"$R"'","refTxId":"d2-'"$R"'","playerId":"'"$P"'","amount":2000,"gameId":"teen20","roundId":"r2"}'
echo "a refund that arrives before its debit, then the late debit: it must be refused"
call '{"action":"refund","txId":"f3-'"$R"'","refTxId":"d3-'"$R"'","playerId":"'"$P"'","amount":1000,"gameId":"teen20","roundId":"r3"}'
call '{"action":"debit","txId":"d3-'"$R"'","playerId":"'"$P"'","amount":1000,"gameId":"teen20","roundId":"r3"}'
echo "a call with a wrong signature must be refused (401)"
curl -sS -o /dev/null -w '%{http_code}\n' -X POST -H 'X-Signature: wrong' --data-binary '{"action":"balance","playerId":"'"$P"'"}' "$URL"
