// One table: the round in play, its bets and odds, and the dealer video link.
//   node table-round.mjs            the first table taking bets
//   node table-round.mjs tb_…       this table
import { getJSON, openTableIds, run } from './lib/api.mjs'

run(async () => {
  const id = process.argv[2] || (await openTableIds(1))[0]
  if (!id) return console.log('No table is taking bets right now; pass a table id: node table-round.mjs tb_…')
  const t = await getJSON(`/v1/casino/tables/${id}`)
  const r = t.round
  // Take bets only while the round is BETTING and before closesAt; the server refuses late bets anyway.
  const left = Math.max(0, Math.round((Date.parse(r.closesAt) - Date.now()) / 1000))
  console.log(`${t.name} (${t.provider})  round ${r.id}  ${r.status}${r.status === 'BETTING' ? `, closes in ${left}s` : ''}\n`)
  for (const m of r.markets) console.log(`  ${m.name.padEnd(26)} ${String(m.odds).padStart(6)}  ${m.status === 'OPEN' ? '' : '[' + m.status + ']'}`)
  // The video link is signed: it plays only on your whitelisted domains and expires, so fetch a fresh one per player.
  console.log(`\nDealer video: ${t.stream.url}\n  valid until ${t.stream.expiresAt}`)
})
