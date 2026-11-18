<?php
// Launch a game for one player: your server asks for a launch address, your page opens it full screen.
//   php launch.php <gameId> <playerId>
declare(strict_types=1);
require __DIR__ . '/lib.php';

run(function () use ($argv) {
    if (count($argv) !== 3) {
        echo "Usage: php launch.php <gameId> <playerId>   (game ids: php list-games.php)\n";
        return;
    }
    $out = post_json('/v1/launch', ['gameId' => $argv[1], 'playerId' => $argv[2], 'currency' => 'INR', 'returnUrl' => 'https://your-site.example/lobby']);
    // The address is for this player only and expires: ask for a new one each time a table is opened.
    echo "Open full screen (page, phone browser or app WebView):\n{$out['url']}\nvalid until {$out['expiresAt']}\n";
});
