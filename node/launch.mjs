// Launch a game for one player: your server asks for a launch address, your page opens it full screen.
//   node launch.mjs <gameId> <playerId>
import { postJSON, run } from './lib/api.mjs'

run(async () => {
  const [gameId, playerId] = process.argv.slice(2)
  if (!gameId || !playerId) return console.log('Usage: node launch.mjs <gameId> <playerId>   (game ids: node list-games.mjs)')
  const { url, expiresAt } = await postJSON('/v1/launch', { gameId, playerId, currency: 'INR', returnUrl: 'https://your-site.example/lobby' })
  // The address is for this player only and expires: ask for a new one each time a table is opened.
  console.log(`Open full screen (page, phone browser or app WebView):\n${url}\nvalid until ${expiresAt}`)
})
