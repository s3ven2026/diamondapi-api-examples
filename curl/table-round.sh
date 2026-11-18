#!/usr/bin/env bash
# One table: the round in play, its bets and odds, and the dealer video link.
#   ./table-round.sh            the first table taking bets
#   ./table-round.sh tb_...     this table
cd "$(dirname "$0")"; source ./common.sh

ID="${1:-$(open_table_ids 1)}"
[ -n "$ID" ] || { echo "No table is taking bets right now; pass a table id: ./table-round.sh tb_..."; exit 0; }
# Take bets only while the round is BETTING and before closesAt. The video link is signed:
# it plays only on your whitelisted domains and expires, so fetch a fresh one per player.
api "/v1/casino/tables/$ID" | jq -r '
  .round as $r |
  "\(.name) (\(.provider))  round \($r.id)  \($r.status)\(if $r.status == "BETTING" then ", closes at \($r.closesAt)" else "" end)", "",
  ($r.markets[] | "  \(.name)\t\(.odds)\(if .status == "OPEN" then "" else "  [\(.status)]" end)"),
  "", "Dealer video: \(.stream.url)", "  valid until \(.stream.expiresAt)"'
