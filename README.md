# Diamond API examples: casino API integration with seamless wallet

Example code for the **[Diamond API](https://diamondapi.live)**, a **casino API** that brings **Diamond live tables, Mac88 tables and virtual games** (Teen Patti 20-20, Lucky 7, 32 Cards, Dragon Tiger Lion, Baccarat and more) to your site through one integration: load the game list, launch a table for a player, and answer our **seamless wallet** calls. Examples in **Node.js, Python, PHP and curl**, each one a single file you can read in a few minutes.

Website: **https://diamondapi.live** · Developer guide: **https://diamondapi.live/docs** · Get a sandbox key: **https://diamondapi.live/telegram**

> Diamond API is an independent API provider, not run by or affiliated with Diamond Exchange.

> **Full request and response details come with your sandbox key.** The paths and fields in this repo show how the integration works; [message us](https://diamondapi.live/telegram) for a key and the exact details for your plan.

## Contents

- [How the integration works](#how-the-integration-works)
- [Quickstart](#quickstart)
- [The examples](#the-examples)
- [Game list](#game-list)
- [Launching a table](#launching-a-table)
- [Your wallet endpoint](#your-wallet-endpoint)
- [Testing your wallet](#testing-your-wallet)
- [Errors](#errors)
- [Running each language](#running-each-language)
- [Get an API key](#get-an-api-key)

## How the integration works

Diamond tables, Mac88 tables and virtual games all use the same flow, so you build it once:

1. **Get a sandbox key** and send the IP addresses of your servers. The key is accepted only from them.
2. **Load the game list** and show it in your own lobby, in your own layout.
3. **Launch a table**: when a player opens one, your server asks for a launch address; you open it full screen in your site or app.
4. **Connect your wallet**: the player's balance stays with you. During play we call your wallet endpoint (balance, debit, credit, refund) and you answer with the new balance. We never hold player money.
5. **Check your results** in your back office, by player and by game.
6. **Go live** with your live key. The flow and the calls stay the same.

## Quickstart

```bash
export DIAMONDAPI_KEY="fg_test_…"     # your sandbox key, from https://diamondapi.live/telegram
cd diamondapi-api-examples
node node/list-games.mjs                       # every game on your plan
node node/launch.mjs teen20 player-1           # a launch address for one player

export DIAMONDAPI_WALLET_SECRET="…"   # comes with your key
node node/wallet-server.mjs                    # your wallet endpoint on :8080/wallet
curl/wallet-test.sh                            # call it the way we do, retries included
```

Node 18 or newer, nothing to install. The same examples exist in Python, PHP and curl: see [Running each language](#running-each-language).

The API examples read the key from `DIAMONDAPI_KEY` and the base URL from `DIAMONDAPI_BASE_URL`, which defaults to the sandbox, `https://api-sandbox.diamondapi.live`. For live, set `DIAMONDAPI_BASE_URL=https://api.diamondapi.live` and use your `fg_live_…` key.

## The examples

| What it does | Node.js | Python | PHP | curl |
|---|---|---|---|---|
| Load the game list for your lobby | [list-games.mjs](node/list-games.mjs) | [list_games.py](python/list_games.py) | [list-games.php](php/list-games.php) | [list-games.sh](curl/list-games.sh) |
| Launch a table for one player | [launch.mjs](node/launch.mjs) | [launch.py](python/launch.py) | [launch.php](php/launch.php) | [launch.sh](curl/launch.sh) |
| Seamless wallet endpoint: balance, debit, credit, refund | [wallet-server.mjs](node/wallet-server.mjs) | [wallet_server.py](python/wallet_server.py) | [wallet-server.php](php/wallet-server.php) | |
| Test your wallet endpoint with signed calls | | | | [wallet-test.sh](curl/wallet-test.sh) |

The shared code is small and meant to be copied: [node/lib/api.mjs](node/lib/api.mjs), [python/casino_api.py](python/casino_api.py), [php/lib.php](php/lib.php), [curl/common.sh](curl/common.sh). It handles the key header, gzip and `429 rate_limited` with `Retry-After`.

## Game list

`GET /v1/games` returns every game on your plan; add `provider=diamond` or `provider=mac88` to filter. Each game has an id, a name, a provider, a category and artwork for your lobby tile:

```json
{ "games": [
  { "id": "teen20", "name": "Teen Patti 20-20", "provider": "diamond", "category": "teen-patti",
    "image": "https://…/teen20.jpg" } ] }
```

## Launching a table

When a player opens a table, your server asks for a launch address for that player and game:

```json
POST /v1/launch
{ "gameId": "teen20", "playerId": "player-1", "currency": "INR", "returnUrl": "https://your-site.example/lobby" }

{ "url": "https://…", "expiresAt": "2026-11-18T12:00:00Z" }
```

Open `url` full screen. It works in a desktop browser, a phone browser and an Android or iOS WebView. The address is for that player only and expires, so ask for a new one each time a table is opened; never share or store it.

## Your wallet endpoint

The player's balance stays in your wallet. During play we `POST` to your wallet endpoint and you answer with the new balance:

| `action` | When | What you do |
|---|---|---|
| `balance` | The table opens or refreshes | Return the balance. |
| `debit` | A bet is placed | Take `amount` if the player has it; otherwise answer `INSUFFICIENT_FUNDS`. |
| `credit` | A round is settled and the player won | Add `amount`. |
| `refund` | A round is cancelled | Give back what the debit `refTxId` took. |

```json
{ "action": "debit", "txId": "d1-8f2k", "playerId": "player-1", "amount": 5000, "gameId": "teen20", "roundId": "r1" }

{ "status": "OK", "balance": 95000 }
```

Amounts are integers in the smallest unit (paise), never floats. Three rules keep your balances right even when the network is not:

1. **Check the signature first.** Every call is signed with your wallet secret (`X-Signature`, an HMAC-SHA256 of the raw body in the examples). Refuse anything unsigned or wrongly signed with `401`.
2. **Safe to retry.** Every call carries a `txId`. If a call is repeated after a timeout, answer it with the same result and do not move the money twice.
3. **A refund cancels its debit.** Give back what the debit took. If the refund arrives first, nothing moves, and the late debit is refused.

A bet your wallet does not confirm is not accepted. A win or a refund is sent again until your wallet confirms it.

The wallet examples keep balances in memory (PHP: in a locked file) so they run on their own. In production, make each change and its `txId` one database transaction, with `txId` unique.

## Testing your wallet

[curl/wallet-test.sh](curl/wallet-test.sh) calls your wallet endpoint the way we do: a balance, a debit, the same debit again, a credit, a debit and its refund, a refund that arrives before its debit, and a wrongly signed call. It needs no API key, only your wallet secret:

```bash
DIAMONDAPI_WALLET_SECRET="…" curl/wallet-test.sh http://localhost:8080/wallet player-1
```

```
{"action":"debit","txId":"d1-…","amount":5000}    -> {"status":"OK","balance":95000}
retry of the same debit: the answer must match and the balance must not drop again
{"action":"debit","txId":"d1-…","amount":5000}    -> {"status":"OK","balance":95000}
…
a call with a wrong signature must be refused (401)
401
```

## Errors

API errors are JSON, `{"code", "message"}`, and every response carries `X-Request-Id`: quote it when you contact us.

| Status | `code` | Meaning |
|---|---|---|
| 400 | `bad_request` | A parameter is wrong; the message says which. |
| 401 | `unauthorized` | No key, or a key we do not know. |
| 403 | `forbidden` | The IP is not on your allowlist, or the key is for the other environment. |
| 404 | `not_found` | No such game. |
| 429 | `rate_limited` | Too many requests; retry after `Retry-After`. |
| 503 | `starting` / `maintenance` | Retry in a few seconds / planned maintenance. |

## Running each language

Set your key once (or copy [.env.example](.env.example) to `.env` and load it with `set -a; . ./.env; set +a`).

**Node.js 18+**, no dependencies:

```bash
node node/list-games.mjs [diamond | mac88]
node node/launch.mjs <gameId> <playerId>
node node/wallet-server.mjs
```

**Python 3.8+**, `requests` for the API examples; the wallet server uses only the standard library:

```bash
pip install -r python/requirements.txt
python python/list_games.py [diamond | mac88]
python python/launch.py <gameId> <playerId>
python python/wallet_server.py
```

**PHP 8.0+** with the curl extension:

```bash
php php/list-games.php [diamond | mac88]
php php/launch.php <gameId> <playerId>
php -S 0.0.0.0:8080 php/wallet-server.php
```

**curl + bash**, needs `jq` (and `openssl` for the wallet test). The key is passed to curl on stdin, never on the command line:

```bash
curl/list-games.sh [diamond | mac88]
curl/launch.sh <gameId> <playerId>
curl/wallet-test.sh [walletUrl] [playerId]
```

## Get an API key

1. Message us at **https://diamondapi.live/telegram** with the IP addresses of your servers and your wallet endpoint URL.
2. We send a sandbox key (`fg_test_…`), your wallet secret and the full request and response details. The key is shown once; store it safely.
3. Build against the sandbox with these examples, and test every table type.
4. When you are ready, ask for a live key (`fg_live_…`). Integration usually takes under 48 hours, with 24/7 support.

Keep keys and the wallet secret on your servers, in environment variables or a secret store, never in code or in a URL. More at **https://diamondapi.live**.

## License

[MIT](LICENSE). Questions about the API: https://diamondapi.live/telegram. Problems with these examples: open an issue, see [CONTRIBUTING.md](CONTRIBUTING.md).
