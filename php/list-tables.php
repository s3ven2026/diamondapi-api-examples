<?php
// List the casino tables on your plan: Diamond and Mac88 live tables, with bet limits.
//   php list-tables.php            every table
//   php list-tables.php mac88      one provider (diamond or mac88)
declare(strict_types=1);
require __DIR__ . '/lib.php';

run(function () use ($argv) {
    echo 'Key for ' . get_json('/v1/me')['client'] . "\n\n";
    $tables = get_json('/v1/casino/tables', ['provider' => $argv[1] ?? ''])['tables'];
    $open = 0;
    foreach ($tables as $t) {
        printf("%-18s %-8s %-28s %-12s bets %s–%s\n", $t['id'], $t['provider'], $t['name'], $t['status'], $t['minBet'], $t['maxBet']);
        if ($t['status'] === 'OPEN') $open++;
    }
    printf("\n%d tables, %d taking bets\n", count($tables), $open);
});
