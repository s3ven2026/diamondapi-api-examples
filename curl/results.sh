#!/usr/bin/env bash
# Collect round results in order, never missing or repeating one, to settle bets.
#   ./results.sh            every table, from the saved cursor
#   ./results.sh tb_...     one table
# The cursor is kept in .results-cursor, so a second run picks up where the first stopped.
cd "$(dirname "$0")"; source ./common.sh

SINCE=$(cat .results-cursor 2>/dev/null || echo 0)
while true; do
  api /v1/casino/results -G --data-urlencode "since=$SINCE" --data-urlencode limit=500 ${1:+--data-urlencode "table=$1"} > "$TMP/r.json"
  jq -r '{"SETTLED": "settle", "CORRECTED": "re-settle", "VOIDED": "refund stakes"} as $todo |
    .results[] | "#\(.seq) \(.action)\t\(.table.name) \(.round): \(.winner // "no winner")  [\(.cards | join(" "))]\(if (.sideBets // []) | length > 0 then "  side bets: " + ([.sideBets[] | "\(.name) \(.outcome)"] | join(", ")) else "" end)  -> \($todo[.action] // .action)"' "$TMP/r.json"
  SINCE=$(jq -r .next "$TMP/r.json")
  echo "$SINCE" > .results-cursor
  [ "$(jq -r .more "$TMP/r.json")" = true ] || break   # while more is true, call again at once
done
echo "Cursor saved: since=$SINCE"
