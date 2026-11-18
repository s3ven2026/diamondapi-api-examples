// Load the game list: Diamond tables, Mac88 tables and virtual games, to show in your own lobby.
//   node list-games.mjs              every game on your plan
//   node list-games.mjs mac88        one provider
import { getJSON, run } from './lib/api.mjs'

run(async () => {
  const { games } = await getJSON('/v1/games', { provider: process.argv[2] })
  for (const g of games) console.log(`${g.id.padEnd(16)} ${g.provider.padEnd(8)} ${g.category.padEnd(12)} ${g.name}`)
  console.log(`\n${games.length} games. Each has artwork at .image for your lobby tile.`)
})
