#!/usr/bin/env bash
# Launch a game for one player: ask for a launch address, then open it full screen.
#   ./launch.sh <gameId> <playerId>
cd "$(dirname "$0")"; source ./common.sh

[ $# -eq 2 ] || { echo "Usage: ./launch.sh <gameId> <playerId>   (game ids: ./list-games.sh)"; exit 2; }
# The address is for this player only and expires: ask for a new one each time a table is opened.
api /v1/launch -X POST -H 'Content-Type: application/json' \
  --data "$(jq -nc --arg g "$1" --arg p "$2" '{gameId: $g, playerId: $p, currency: "INR", returnUrl: "https://your-site.example/lobby"}')" \
  | jq -r '"Open full screen (page, phone browser or app WebView):", .url, "valid until \(.expiresAt)"'
