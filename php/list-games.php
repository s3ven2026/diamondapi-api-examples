<?php
// Load the game list: Diamond tables, Mac88 tables and virtual games, to show in your own lobby.
//   php list-games.php            every game on your plan
//   php list-games.php mac88      one provider
declare(strict_types=1);
require __DIR__ . '/lib.php';

run(function () use ($argv) {
    $games = get_json('/v1/games', ['provider' => $argv[1] ?? ''])['games'];
    foreach ($games as $g) {
        printf("%-16s %-8s %-12s %s\n", $g['id'], $g['provider'], $g['category'], $g['name']);
    }
    printf("\n%d games. Each has artwork at .image for your lobby tile.\n", count($games));
});
