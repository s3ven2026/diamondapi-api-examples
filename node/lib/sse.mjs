// A small Server-Sent Events client for /v1/casino/stream, with resume. Node 18+, no
// dependencies. It does what a stream needs to stay whole for days:
//
//  - remembers the id of the last message and sends it back as Last-Event-ID
//    on every reconnect, so you receive exactly what you missed;
//  - on a `reconnect` message (sent about every 14 minutes, and before a
//    server restart) closes the connection and reconnects at once;
//  - reconnects when the connection drops, including the hard cut at
//    15 minutes (900 s) that a proxy may apply to any long request;
//  - treats 45 s without a byte as a dead connection (a `: heartbeat`
//    comment arrives every 15 s);
//  - honours the server's `retry:` hint, with jitter and backoff on failures;
//  - stops on errors that retrying cannot fix (bad key, wrong IP, bad request).

import { ApiError, headers, sleep } from './api.mjs'

const IDLE_MS = 45_000
const FATAL = new Set([400, 401, 403, 404])

export async function stream(url, onMessage, { onStatus = () => {}, signal } = {}) {
  let lastId = null
  let retryMs = 1000
  let failures = 0

  while (!signal?.aborted) {
    const ctrl = new AbortController()
    const stop = () => ctrl.abort()
    signal?.addEventListener('abort', stop, { once: true })
    let idle
    const kick = () => { clearTimeout(idle); idle = setTimeout(() => { onStatus('no data for 45 s, reconnecting'); ctrl.abort() }, IDLE_MS) }
    let rotate = false
    let connected = false

    try {
      const h = headers({ Accept: 'text/event-stream', 'Cache-Control': 'no-cache' })
      if (lastId) h['Last-Event-ID'] = lastId
      onStatus(lastId ? `connecting, resuming after ${lastId}` : 'connecting')
      kick()
      const res = await fetch(url, { headers: h, signal: ctrl.signal })

      if (!res.ok) {
        const body = await res.text()
        let err = {}
        try { err = JSON.parse(body) } catch { /* not JSON */ }
        const msg = `${res.status} ${err.code || ''} ${err.message || body.slice(0, 200)}`.trim()
        if (FATAL.has(res.status)) throw Object.assign(new ApiError(res.status, err.code || 'http_' + res.status, err.message || body.slice(0, 200), res.headers.get('x-request-id')), { fatal: true })
        // 429 too_many_streams: an old connection may still be closing; 503: server starting.
        const after = Number(res.headers.get('retry-after')) * 1000 || Math.min(30_000, 5000 * 2 ** failures)
        onStatus(`${msg}; retrying in ${Math.round(after / 1000)} s`)
        failures++
        clearTimeout(idle)
        await sleep(after)
        continue
      }

      failures = 0
      connected = true
      onStatus('connected')
      const decoder = new TextDecoder()
      let buf = ''
      let ev = { event: 'message', data: [], id: null }

      for await (const chunk of res.body) {
        kick()
        buf += decoder.decode(chunk, { stream: true })
        let nl
        while ((nl = buf.search(/\r\n|\r|\n/)) >= 0) {
          const line = buf.slice(0, nl)
          buf = buf.slice(nl + (buf[nl] === '\r' && buf[nl + 1] === '\n' ? 2 : 1))

          if (line === '') { // end of one message
            if (ev.id !== null) lastId = ev.id
            if (ev.data.length) {
              const data = ev.data.join('\n')
              let parsed
              try { parsed = JSON.parse(data) } catch { parsed = data }
              if (ev.event === 'reconnect') { rotate = true; onStatus('server asked us to reconnect'); ctrl.abort(); break }
              if (ev.event === 'error') throw Object.assign(new ApiError('stream', parsed.code, parsed.message), { fatal: true })
              await onMessage(ev.event, parsed, lastId)
            }
            ev = { event: 'message', data: [], id: null }
            continue
          }
          if (line.startsWith(':')) continue // comment, e.g. ": heartbeat"
          const i = line.indexOf(':')
          const field = i < 0 ? line : line.slice(0, i)
          const value = i < 0 ? '' : line.slice(i + 1).replace(/^ /, '')
          if (field === 'event') ev.event = value
          else if (field === 'data') ev.data.push(value)
          else if (field === 'id') ev.id = value
          else if (field === 'retry' && /^\d+$/.test(value)) retryMs = Number(value)
        }
        if (rotate) break
      }
      if (!rotate) onStatus('connection closed by server')
    } catch (e) {
      if (e.fatal) { clearTimeout(idle); throw e }
      if (signal?.aborted) break
      if (!rotate && e.name !== 'AbortError') { onStatus(`connection lost: ${e.message}`); if (!connected) failures++ }
    } finally {
      clearTimeout(idle)
      signal?.removeEventListener('abort', stop)
    }
    if (signal?.aborted) break
    // A rotation reconnects at once; anything else waits retry: plus jitter, backing off.
    const wait = rotate ? 0 : Math.min(30_000, retryMs * 2 ** Math.min(failures, 5)) + Math.random() * 1000
    if (wait) await sleep(wait)
  }
}
