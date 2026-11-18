#!/usr/bin/env bash
# List the casino tables on your plan: Diamond and Mac88 live tables, with bet limits.
#   ./list-tables.sh            every table
#   ./list-tables.sh mac88      one provider (diamond or mac88)
cd "$(dirname "$0")"; source ./common.sh

echo "Key for $(api /v1/me | jq -r .client)"; echo
api /v1/casino/tables -G ${1:+--data-urlencode "provider=$1"} | jq -r '
  (.tables[] | "\(.id)\t\(.provider)\t\(.name)\t\(.status)\tbets \(.minBet)–\(.maxBet)"),
  "", "\(.tables | length) tables, \([.tables[] | select(.status == "OPEN")] | length) taking bets"'
