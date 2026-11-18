"""Follow tables live: each new round, betting closing, and the result the moment it is dealt.

    python stream.py                 the first 5 tables taking bets (sandbox keys list up to 5 ids)
    python stream.py tb_a,tb_b       these tables
    python stream.py all             every table on your plan (live keys only)

Runs until Ctrl-C. Resume, reconnect and heartbeats are handled in casino_api.stream.
"""
import sys
import time

from casino_api import BASE, is_sandbox_key, main, open_table_ids, stream

tables = {}  # table id -> table with its current round, as the API sends it


def on_message(kind, data, last_id):
    t = time.strftime("%H:%M:%S")
    if kind == "snapshot":  # a whole table with its current round: replace your copy
        tables[data["id"]] = data
        print(f"{t} snapshot  {data['name']}: round {data['round']['id']} {data['round']['status']}")
    elif kind == "ready":
        print(f"{t} ready     {len(tables)} tables loaded; changes from here on (last id {last_id})")
    elif kind == "round":  # BETTING -> CLOSED -> RESULT, then a new round opens
        tb = tables.get(data["table"])
        if tb:
            tb["round"] = data["round"]
        print(f"{t} round     {tb['name'] if tb else data['table']}: {data['round']['id']} {data['round']['status']}")
    elif kind == "result":
        print(f"{t} result    #{data['seq']} {data['action']} {data['table']['name']} {data['round']}: {data.get('winner') or 'no winner'} [{' '.join(data['cards'])}]")
    else:
        print(f"{t} {kind}")


def run():
    sys.stdout.reconfigure(line_buffering=True)  # print each message at once, even into a pipe or log
    ids = sys.argv[1] if len(sys.argv) > 1 else ",".join(open_table_ids(5 if is_sandbox_key() else 100))
    if not ids:
        return print("No table is taking bets right now; pass table ids: python stream.py tb_a,tb_b")
    stream(f"{BASE}/v1/casino/stream?tables={ids}", on_message)


main(run)
