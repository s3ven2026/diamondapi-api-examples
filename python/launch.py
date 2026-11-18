"""Launch a game for one player: your server asks for a launch address, your page opens it full screen.

    python launch.py <gameId> <playerId>
"""
import sys

from casino_api import main, post_json


def run():
    if len(sys.argv) != 3:
        return print("Usage: python launch.py <gameId> <playerId>   (game ids: python list_games.py)")
    out = post_json("/v1/launch", {"gameId": sys.argv[1], "playerId": sys.argv[2], "currency": "INR", "returnUrl": "https://your-site.example/lobby"})
    # The address is for this player only and expires: ask for a new one each time a table is opened.
    print(f"Open full screen (page, phone browser or app WebView):\n{out['url']}\nvalid until {out['expiresAt']}")


main(run)
