# Diamond API examples: casino API for live tables, rounds and results

Example code for the **[Diamond API](https://diamondapi.live)**: a **casino API** that connects **Diamond and Mac88 live tables** (Teen Patti 20-20, Lucky 7, 32 Cards, Dragon Tiger Lion, Baccarat and more) to your platform. List tables, follow each round live, show the dealer video and collect results with winning cards and side bet outcomes, over REST and Server-Sent Events. Examples in **Node.js, Python, PHP and curl**, each one a single file you can read in a few minutes.

Website: **https://diamondapi.live** · Integration guide: **https://diamondapi.live/diamond-api-integration** · Get a sandbox key: **https://diamondapi.live/telegram**

> Diamond API is an independent API provider, not run by or affiliated with Diamond Exchange.

> **Endpoint list on request.** The paths and fields in this repo show how a Diamond API integration works. Your exact endpoint list and fields come with your sandbox key: [message us](https://diamondapi.live/telegram) and we send both.

## Contents

- [What the API does](#what-the-api-does)
- [Quickstart](#quickstart)
- [The examples](#the-examples)
- [Tables and rounds](#tables-and-rounds)
- [Dealer video](#dealer-video)
- [Following tables live](#following-tables-live)
- [Results and settlement](#results-and-settlement)
- [Wallet and bets](#wallet-and-bets)
- [Sandbox limits and errors](#sandbox-limits-and-errors)
- [Running each language](#running-each-language)
- [Get an API key](#get-an-api-key)

## What the API does

One integration brings **50+ Diamond live dealer tables**, **Mac88 tables** and virtual games to your casino site.

- **REST** for snapshots: list tables with bet limits, fetch one table with the round in play, page through results.
- **A live stream (SSE)**: one long HTTP response that sends each table in full, then every round change and result as it happens. No WebSocket needed; any HTTP client can read it.
- **Dealer video**: signed H5 stream links with adaptive bitrate, playable only on your whitelisted domains.
- **Results**: winning cards and side bet outcomes after each round, kept for you to collect in order.
- Stable ids: `tb_…` tables, `rd_…` rounds.

The API is for **server-to-server** use. Keys work only from the server IPs you register, so do not call it from a browser or an app.

## Quickstart

```bash
export DIAMONDAPI_KEY="fg_test_…"     # your sandbox key, from https://diamondapi.live/telegram
cd diamondapi-api-examples
node node/list-tables.mjs              # every table on your plan
node node/table-round.mjs              # one table: round, bets, odds, video link
node node/results.mjs                  # results with winning cards, from a saved cursor
node node/stream.mjs                   # live rounds and results, Ctrl-C to stop
```

Node 18 or newer, nothing to install. The same examples exist in Python, PHP and curl: see [Running each language](#running-each-language).

Every example reads the key from `DIAMONDAPI_KEY` and the base URL from `DIAMONDAPI_BASE_URL`, which defaults to the sandbox, `https://api-sandbox.diamondapi.live`. For live, set `DIAMONDAPI_BASE_URL=https://api.diamondapi.live` and use your `fg_live_…` key.

## The examples

| What it does | Node.js | Python | PHP | curl + jq |
|---|---|---|---|---|
| List tables with provider, status and bet limits | [list-tables.mjs](node/list-tables.mjs) | [list_tables.py](python/list_tables.py) | [list-tables.php](php/list-tables.php) | [list-tables.sh](curl/list-tables.sh) |
| One table: the round in play, bets, odds, video link | [table-round.mjs](node/table-round.mjs) | [table_round.py](python/table_round.py) | [table-round.php](php/table-round.php) | [table-round.sh](curl/table-round.sh) |
| Results in order, with a saved cursor | [results.mjs](node/results.mjs) | [results.py](python/results.py) | [results.php](php/results.php) | [results.sh](curl/results.sh) |
| Live SSE stream of rounds and results, with resume | [stream.mjs](node/stream.mjs) | [stream.py](python/stream.py) | [stream.php](php/stream.php) | [stream.sh](curl/stream.sh) |

The shared code is small and meant to be copied: [node/lib](node/lib), [python/casino_api.py](python/casino_api.py), [php/lib.php](php/lib.php), [curl/common.sh](curl/common.sh). It handles the key header, gzip, `429 rate_limited` with `Retry-After`, and the stream.

## Tables and rounds

`GET /v1/casino/tables` lists the tables on your plan; add `provider=diamond` or `provider=mac88` to filter.

```json
{ "tables": [
  { "id": "tb_teen20", "name": "Teen Patti 20-20", "provider": "diamond", "game": "teen20",
    "status": "OPEN", "minBet": 100, "maxBet": 100000 } ] }
```

| Table `status` | Meaning |
|---|---|
| `OPEN` | Dealing rounds. |
| `CLOSED` | Not dealing now. |
| `MAINTENANCE` | Paused by the studio. |

`GET /v1/casino/tables/{id}` adds the round in play and the video link. Each round moves through three states:

| Round `status` | Meaning | What to do |
|---|---|---|
| `BETTING` | Bets are open until `closesAt`. | Take bets on markets whose `status` is `OPEN`. |
| `CLOSED` | Cards are being dealt. | Take no bets. |
| `RESULT` | The round is decided; its result follows. | Settle. A new round opens next. |

Each market has a name (Player A, Player B, Pair Plus…), decimal `odds` and its own `status`. Take bets only while the round is `BETTING`, before `closesAt`, on `OPEN` markets.

## Dealer video

The table carries `stream.url`, an H5 link with adaptive bitrate for HD dealer video, and `stream.expiresAt`. The link is signed: it plays only on the domains you whitelist with us and expires, so fetch a fresh one for each player session rather than storing it.

## Following tables live

`GET /v1/casino/stream?tables=tb_a,tb_b` is a Server-Sent Events stream. On connect you get a `snapshot` of each table, then `ready`, then changes:

| Message | Data | What to do |
|---|---|---|
| `snapshot` | A whole table with its round | Store it (replace any copy you hold). |
| `ready` | | The opening snapshots are done. |
| `round` | `{"table", "round"}` | Replace your copy of that table's round. |
| `result` | A result entry | Settle. See [Results](#results-and-settlement). |
| `reconnect` | | Close and reconnect **now**, with your last id. |
| `error` | `{"code", "message"}` | The stream closes, for example a revoked key. Do not retry blindly. |

To keep one stream running for days, each example:

1. **Resumes with `Last-Event-ID`.** Every message has an `id`. Send the last one back when you reconnect and you receive what you missed, results included.
2. **Reconnects on `reconnect`**, which the server sends before it rotates a stream.
3. **Watches the heartbeat.** A `: heartbeat` comment arrives every 15 s; nothing for 45 s means the connection is dead.
4. **Backs off on failure** with the stream's `retry:` hint plus jitter, and stops on `400`, `401`, `403` and `error`, which retrying cannot fix.

The Node, Python and PHP examples parse SSE themselves in one commented function each, so you need no SSE library.

## Results and settlement

`GET /v1/casino/results?since=<cursor>` lists results in order, oldest first; add `table=tb_…` for one table. Each entry:

```json
{ "seq": 1842, "action": "SETTLED", "table": { "id": "tb_teen20", "name": "Teen Patti 20-20" },
  "round": "rd_8f2k1", "winner": "Player A", "cards": ["AS", "KH", "7D"],
  "sideBets": [{ "name": "Pair Plus A", "outcome": "lost" }] }
```

| `action` | What to do |
|---|---|
| `SETTLED` | Settle bets on the round. |
| `CORRECTED` | Re-settle to the new result. |
| `VOIDED` | Refund stakes. |

Settle by round id, so a result you see twice (on the stream and in the list) is applied once. Page with `since=<next>` and call again at once while `more` is true; [results](node/results.mjs) saves its cursor to `.results-cursor`, so each run continues from the last.

## Wallet and bets

Diamond and Mac88 tables share **one wallet, one bet history and one report**. How bets reach the table and how your wallet is debited and credited is set up with you during integration, to fit your platform. [Message us](https://diamondapi.live/telegram) and we walk through it.

## Sandbox limits and errors

The sandbox, `https://api-sandbox.diamondapi.live`, is for building, not for running a site. A sandbox key opens 1 stream at a time with up to 5 tables listed by id (`tables=all` needs a live key) and makes up to 2 requests per second; the helpers here space requests 0.55 s apart and retry `429 rate_limited`. `GET /v1/me` shows the limits your key has.

Errors are JSON, `{"code", "message"}`, and every response carries `X-Request-Id`: quote it when you contact us.

| Status | `code` | Meaning |
|---|---|---|
| 400 | `bad_request` | A parameter is wrong; the message says which. |
| 401 | `unauthorized` | No key, or a key we do not know. |
| 403 | `forbidden` | The IP is not on your allowlist, or the key is for the other environment. |
| 404 | `not_found` | No such id, or not one of yours. |
| 429 | `rate_limited` / `too_many_streams` | Too many requests, or all stream slots in use. |
| 503 | `starting` / `maintenance` | Retry in a few seconds / planned maintenance. |

## Running each language

Set your key once (or copy [.env.example](.env.example) to `.env` and load it with `set -a; . ./.env; set +a`):

```bash
export DIAMONDAPI_KEY="fg_test_…"
# optional: export DIAMONDAPI_BASE_URL=https://api.diamondapi.live   (live key)
```

**Node.js 18+**, no dependencies:

```bash
node node/list-tables.mjs [diamond | mac88]
node node/table-round.mjs [tb_…]
node node/results.mjs [tb_…]
node node/stream.mjs [tb_a,tb_b | all]
```

**Python 3.8+**, needs only `requests`:

```bash
pip install -r python/requirements.txt
python python/list_tables.py [diamond | mac88]
python python/table_round.py [tb_…]
python python/results.py [tb_…]
python python/stream.py [tb_a,tb_b | all]
```

**PHP 8.0+** with the curl extension:

```bash
php php/list-tables.php [diamond | mac88]
php php/table-round.php [tb_…]
php php/results.php [tb_…]
php php/stream.php [tb_a,tb_b | all]
```

**curl + bash**, needs `jq`. The key is passed to curl through a header file or stdin, never on the command line:

```bash
curl/list-tables.sh [diamond | mac88]
curl/table-round.sh [tb_…]
curl/results.sh [tb_…]
curl/stream.sh [tb_a,tb_b | all]
```

## Get an API key

1. Message us at **https://diamondapi.live/telegram** with the server IPs you will call from and the domains that will show the video.
2. We send a sandbox key (`fg_test_…`) with your endpoint list. The key is shown once; store it safely.
3. Build against the sandbox with these examples.
4. When you are ready, ask for a live key (`fg_live_…`) for `https://api.diamondapi.live`. Integration usually takes under 48 hours, with 24/7 support.

Keep keys on your servers, in an environment variable or a secret store, never in code or in a URL. More at **https://diamondapi.live**.

## License

[MIT](LICENSE). Questions about the API: https://diamondapi.live/telegram. Problems with these examples: open an issue, see [CONTRIBUTING.md](CONTRIBUTING.md).
