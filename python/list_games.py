"""Load the game list: Diamond tables, Mac88 tables and virtual games, to show in your own lobby.

    python list_games.py            every game on your plan
    python list_games.py mac88      one provider
"""
import sys

from casino_api import get_json, main


def run():
    games = get_json("/v1/games", {"provider": sys.argv[1] if len(sys.argv) > 1 else None})["games"]
    for g in games:
        print(f"{g['id']:<16} {g['provider']:<8} {g['category']:<12} {g['name']}")
    print(f"\n{len(games)} games. Each has artwork at .image for your lobby tile.")


main(run)
