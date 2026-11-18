"""List the casino tables on your plan: Diamond and Mac88 live tables, with bet limits.

    python list_tables.py            every table
    python list_tables.py mac88      one provider (diamond or mac88)
"""
import sys

from casino_api import get_json, main


def run():
    print(f"Key for {get_json('/v1/me')['client']}\n")
    provider = sys.argv[1] if len(sys.argv) > 1 else None
    tables = get_json("/v1/casino/tables", {"provider": provider})["tables"]
    for t in tables:
        print(f"{t['id']:<18} {t['provider']:<8} {t['name']:<28} {t['status']:<12} bets {t['minBet']}–{t['maxBet']}")
    print(f"\n{len(tables)} tables, {sum(t['status'] == 'OPEN' for t in tables)} taking bets")


main(run)
