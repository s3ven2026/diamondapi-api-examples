// List the casino tables on your plan: Diamond and Mac88 live tables, with bet limits.
//   node list-tables.mjs            every table
//   node list-tables.mjs mac88      one provider (diamond or mac88)
import { getJSON, run } from './lib/api.mjs'

run(async () => {
  const me = await getJSON('/v1/me')
  console.log(`Key for ${me.client}\n`)
  const provider = process.argv[2]
  const { tables } = await getJSON('/v1/casino/tables', { provider })
  for (const t of tables) {
    console.log(`${t.id.padEnd(18)} ${t.provider.padEnd(8)} ${t.name.padEnd(28)} ${t.status.padEnd(12)} bets ${t.minBet}–${t.maxBet}`)
  }
  console.log(`\n${tables.length} tables, ${tables.filter((t) => t.status === 'OPEN').length} taking bets`)
})
