"""Collect round results in order, never missing or repeating one, to settle bets.

    python results.py            every table, from the saved cursor
    python results.py tb_...     one table

The cursor is kept in .results-cursor, so a second run picks up where the first stopped.
"""
import sys

from casino_api import get_json, main

CURSOR = ".results-cursor"
TODO = {"SETTLED": "settle", "CORRECTED": "re-settle", "VOIDED": "refund stakes"}


def run():
    try:
        since = open(CURSOR).read().strip() or "0"
    except FileNotFoundError:
        since = "0"
    table = sys.argv[1] if len(sys.argv) > 1 else None
    while True:
        page = get_json("/v1/casino/results", {"since": since, "table": table, "limit": 500})
        for x in page["results"]:
            side = ("  side bets: " + ", ".join(f"{s['name']} {s['outcome']}" for s in x["sideBets"])) if x.get("sideBets") else ""
            print(f"#{x['seq']} {x['action']:<9} {x['table']['name']} {x['round']}: {x.get('winner') or 'no winner'}  [{' '.join(x['cards'])}]{side}  -> {TODO.get(x['action'], x['action'])}")
        since = page["next"]
        open(CURSOR, "w").write(since)
        if not page.get("more"):  # while more is true, call again at once
            break
    print(f"Cursor saved: since={since}")


main(run)
