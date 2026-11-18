"""Your wallet endpoint: we call it during play, it answers with the player's new balance.

    DIAMONDAPI_WALLET_SECRET=... python wallet_server.py     listens on PORT (default 8080), POST /wallet

The rules that keep balances right:
 - check the signature on every call before anything else;
 - every call carries a txId: a repeated txId gets the first answer again, money moves once;
 - a refund cancels its debit (refTxId); a debit that arrives after its refund is refused.
Amounts are integers in the smallest unit (paise), never floats. Standard library only.
"""
import hashlib
import hmac
import json
import os
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SECRET = os.environ.get("DIAMONDAPI_WALLET_SECRET", "").encode()
if not SECRET:
    sys.exit("Set DIAMONDAPI_WALLET_SECRET (it comes with your key).")

# shortcut: in-memory, lost on restart. Use your database: balance change and txId insert in one transaction, txId unique.
balances = {"player-1": 100000}
done = {}  # txId -> the answer we gave
debits = {}  # debit txId -> amount taken
refunded = set()  # debit txIds already refunded
lock = threading.Lock()


def handle(c):
    pid, tx, amount = c.get("playerId"), c.get("txId"), c.get("amount")
    if pid not in balances:
        return {"status": "UNKNOWN_PLAYER"}
    bal = balances[pid]
    action = c.get("action")
    if action == "balance":
        return {"status": "OK", "balance": bal}
    if not tx or not isinstance(amount, int) or isinstance(amount, bool) or amount < 0:
        return {"status": "BAD_REQUEST"}
    if tx in done:
        return done[tx]  # a retry: same answer, no money moved
    if action == "debit":
        if tx in refunded:
            answer = {"status": "ALREADY_REFUNDED", "balance": bal}
        elif amount > bal:
            answer = {"status": "INSUFFICIENT_FUNDS", "balance": bal}
        else:
            balances[pid] = bal - amount
            debits[tx] = amount
            answer = {"status": "OK", "balance": bal - amount}
    elif action == "credit":
        balances[pid] = bal + amount
        answer = {"status": "OK", "balance": bal + amount}
    elif action == "refund":
        refunded.add(c.get("refTxId"))
        back = debits.pop(c.get("refTxId"), 0)  # give back what the debit took; nothing if it never took money
        balances[pid] = bal + back
        answer = {"status": "OK", "balance": bal + back}
    else:
        return {"status": "BAD_REQUEST"}
    done[tx] = answer
    return answer


class Wallet(BaseHTTPRequestHandler):
    def reply(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        if self.path != "/wallet":
            return self.reply(404, {"status": "NOT_FOUND"})
        want = hmac.new(SECRET, raw, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(want, self.headers.get("X-Signature", "")):
            return self.reply(401, {"status": "BAD_SIGNATURE"})
        try:
            call = json.loads(raw)
        except ValueError:
            return self.reply(400, {"status": "BAD_REQUEST"})
        with lock:
            answer = handle(call)
        print(f"{call.get('action')} {call.get('txId', '')} {call.get('playerId')} {call.get('amount', '')} -> {json.dumps(answer)}", flush=True)
        self.reply(200, answer)

    def log_message(self, *args):
        pass  # we print our own line per call


port = int(os.environ.get("PORT") or 8080)
print(f"Wallet listening on :{port}/wallet", flush=True)
ThreadingHTTPServer(("", port), Wallet).serve_forever()
