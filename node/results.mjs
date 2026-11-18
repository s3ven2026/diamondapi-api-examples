// Collect round results in order, never missing or repeating one, to settle bets.
//   node results.mjs                every table, from the saved cursor
//   node results.mjs tb_…           one table
// The cursor is kept in .results-cursor, so a second run picks up where the first stopped.
import { readFileSync, writeFileSync } from 'node:fs'
import { getJSON, run } from './lib/api.mjs'

const CURSOR = '.results-cursor'
const TODO = { SETTLED: 'settle', CORRECTED: 're-settle', VOIDED: 'refund stakes' }

run(async () => {
  let since = '0'
  try { since = readFileSync(CURSOR, 'utf8').trim() || '0' } catch { /* first run */ }
  for (;;) {
    const page = await getJSON('/v1/casino/results', { since, table: process.argv[2], limit: 500 })
    for (const x of page.results) {
      const side = x.sideBets?.length ? '  side bets: ' + x.sideBets.map((s) => `${s.name} ${s.outcome}`).join(', ') : ''
      console.log(`#${x.seq} ${x.action.padEnd(9)} ${x.table.name} ${x.round}: ${x.winner ?? 'no winner'}  [${x.cards.join(' ')}]${side}  -> ${TODO[x.action] ?? x.action}`)
    }
    since = page.next
    writeFileSync(CURSOR, since)
    if (!page.more) break // while more is true, call again at once
  }
  console.log(`Cursor saved: since=${since}`)
})
