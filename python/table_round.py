"""One table: the round in play, its bets and odds, and the dealer video link.

    python table_round.py            the first table taking bets
    python table_round.py tb_...     this table
"""
import sys
import time
from datetime import datetime

from casino_api import get_json, main, open_table_ids


def run():
    ids = sys.argv[1:] or open_table_ids(1)
    if not ids:
        return print("No table is taking bets right now; pass a table id: python table_round.py tb_...")
    t = get_json(f"/v1/casino/tables/{ids[0]}")
    r = t["round"]
    # Take bets only while the round is BETTING and before closesAt; the server refuses late bets anyway.
    left = max(0, round(datetime.fromisoformat(r["closesAt"].replace("Z", "+00:00")).timestamp() - time.time()))
    print(f"{t['name']} ({t['provider']})  round {r['id']}  {r['status']}" + (f", closes in {left}s" if r["status"] == "BETTING" else "") + "\n")
    for m in r["markets"]:
        print(f"  {m['name']:<26} {m['odds']:>6}  " + ("" if m["status"] == "OPEN" else f"[{m['status']}]"))
    # The video link is signed: it plays only on your whitelisted domains and expires, so fetch a fresh one per player.
    print(f"\nDealer video: {t['stream']['url']}\n  valid until {t['stream']['expiresAt']}")


main(run)
