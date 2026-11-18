#!/usr/bin/env bash
# Load the game list: Diamond tables, Mac88 tables and virtual games, to show in your own lobby.
#   ./list-games.sh            every game on your plan
#   ./list-games.sh mac88      one provider
cd "$(dirname "$0")"; source ./common.sh

api /v1/games -G ${1:+--data-urlencode "provider=$1"} | jq -r '(.games[] | "\(.id)\t\(.provider)\t\(.category)\t\(.name)"), "", "\(.games | length) games"'
