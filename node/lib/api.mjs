// Small REST helper for the Diamond API. Node 18+, no dependencies.
//
//   DIAMONDAPI_KEY       your key (fg_test_… for the sandbox, fg_live_… for live)
//   DIAMONDAPI_BASE_URL  optional, defaults to the sandbox: https://api-sandbox.diamondapi.live

export const BASE = (process.env.DIAMONDAPI_BASE_URL || 'https://api-sandbox.diamondapi.live').replace(/\/+$/, '')

export function apiKey() {
  const key = process.env.DIAMONDAPI_KEY
  if (!key) {
    console.error('Set DIAMONDAPI_KEY first. Ask for a sandbox key at https://diamondapi.live/telegram')
    process.exit(2)
  }
  return key
}

export const isSandboxKey = () => apiKey().startsWith('fg_test_')

export function headers(extra = {}) {
  // fetch in Node already sends Accept-Encoding: gzip and decompresses for you.
  return { 'X-API-Key': apiKey(), Accept: 'application/json', 'User-Agent': 'diamondapi-api-examples/1.0', ...extra }
}

export class ApiError extends Error {
  constructor(status, code, message, requestId) {
    super(`${status} ${code}: ${message}${requestId ? ` (X-Request-Id ${requestId})` : ''}`)
    Object.assign(this, { status, code, requestId })
  }
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// The sandbox allows 2 requests per second. Spacing calls 550 ms apart keeps
// these examples well inside that; a live key may go faster (see /v1/me).
let lastCall = 0
async function pace() {
  const wait = lastCall + 550 - Date.now()
  if (wait > 0) await sleep(wait)
  lastCall = Date.now()
}

// GET a path. Retries 429 rate_limited (after Retry-After) and 503 starting.
// Returns { status, data, headers }; status 304 has data = null.
export async function get(path, params = {}, extraHeaders = {}) {
  const url = new URL(BASE + path)
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v)
  for (let attempt = 0; ; attempt++) {
    await pace()
    const res = await fetch(url, { headers: headers(extraHeaders) })
    if (res.status === 304) return { status: 304, data: null, headers: res.headers }
    const body = await res.text()
    let data = null
    try { data = body ? JSON.parse(body) : null } catch { /* not JSON */ }
    if (res.ok) return { status: res.status, data, headers: res.headers }
    const code = data?.code || 'http_' + res.status
    const retryable = (res.status === 429 && code === 'rate_limited') || (res.status === 503 && code === 'starting')
    if (retryable && attempt < 4) {
      const after = Number(res.headers.get('retry-after')) || 2
      console.error(`… ${code}, retrying in ${after}s`)
      await sleep(after * 1000)
      continue
    }
    throw new ApiError(res.status, code, data?.message || body.slice(0, 200), res.headers.get('x-request-id'))
  }
}

export const getJSON = async (path, params) => (await get(path, params)).data

// The first tables taking bets now, for examples that need a few to work with.
export async function openTableIds(limit) {
  const { tables } = await getJSON('/v1/casino/tables')
  return tables.filter((t) => t.status === 'OPEN').slice(0, limit).map((t) => t.id)
}

// Print an ApiError cleanly and exit; anything else is a bug, so rethrow it.
export function run(main) {
  main().catch((e) => {
    if (e instanceof ApiError) {
      console.error(e.message)
      if (e.status === 403) console.error('Requests are accepted only from IPs on your allowlist, and a key works only in its own environment.')
      process.exit(1)
    }
    throw e
  })
}
