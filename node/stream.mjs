// Follow tables live: each new round, betting closing, and the result the moment it is dealt.
//   node stream.mjs                 the first 5 tables taking bets (sandbox keys list up to 5 ids)
//   node stream.mjs tb_a,tb_b       these tables
//   node stream.mjs all             every table on your plan (live keys only)
// Runs until Ctrl-C. Resume, reconnect and heartbeats are handled in lib/sse.mjs.
import { BASE, isSandboxKey, openTableIds, run } from './lib/api.mjs'
import { stream } from './lib/sse.mjs'

const tables = new Map() // table id -> table with its current round, as the API sends it

run(async () => {
  let ids = process.argv[2]
  if (!ids) {
    ids = (await openTableIds(isSandboxKey() ? 5 : 100)).join(',')
    if (!ids) return console.log('No table is taking bets right now; pass table ids: node stream.mjs tb_a,tb_b')
  }
  const url = `${BASE}/v1/casino/stream?tables=${encodeURIComponent(ids).replace(/%2C/g, ',')}`
  const stop = new AbortController()
  process.on('SIGINT', () => stop.abort())
  process.on('SIGTERM', () => stop.abort())
  const t = () => new Date().toISOString().slice(11, 19)

  await stream(url, (type, data, id) => {
    switch (type) {
      case 'snapshot': // a whole table with its current round: replace your copy
        tables.set(data.id, data)
        console.log(`${t()} snapshot  ${data.name}: round ${data.round.id} ${data.round.status}`)
        break
      case 'ready':
        console.log(`${t()} ready     ${tables.size} tables loaded; changes from here on (last id ${id})`)
        break
      case 'round': { // the round changed: BETTING -> CLOSED -> RESULT, then a new round opens
        const tb = tables.get(data.table)
        if (tb) tb.round = data.round
        console.log(`${t()} round     ${tb?.name ?? data.table}: ${data.round.id} ${data.round.status}`)
        break
      }
      case 'result':
        console.log(`${t()} result    #${data.seq} ${data.action} ${data.table.name} ${data.round}: ${data.winner ?? 'no winner'} [${data.cards.join(' ')}]`)
        break
      default:
        console.log(`${t()} ${type}`)
    }
  }, { signal: stop.signal, onStatus: (s) => console.error(`${t()} [stream] ${s}`) })
  console.log(`Stopped. Holding ${tables.size} tables.`)
})
