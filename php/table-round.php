<?php
// One table: the round in play, its bets and odds, and the dealer video link.
//   php table-round.php            the first table taking bets
//   php table-round.php tb_...     this table
declare(strict_types=1);
require __DIR__ . '/lib.php';

run(function () use ($argv) {
    $id = $argv[1] ?? (open_table_ids(1)[0] ?? null);
    if ($id === null) {
        echo "No table is taking bets right now; pass a table id: php table-round.php tb_...\n";
        return;
    }
    $t = get_json('/v1/casino/tables/' . rawurlencode($id));
    $r = $t['round'];
    // Take bets only while the round is BETTING and before closesAt; the server refuses late bets anyway.
    $left = max(0, strtotime($r['closesAt']) - time());
    echo "{$t['name']} ({$t['provider']})  round {$r['id']}  {$r['status']}" . ($r['status'] === 'BETTING' ? ", closes in {$left}s" : '') . "\n\n";
    foreach ($r['markets'] as $m) {
        printf("  %-26s %6s  %s\n", $m['name'], $m['odds'], $m['status'] === 'OPEN' ? '' : "[{$m['status']}]");
    }
    // The video link is signed: it plays only on your whitelisted domains and expires, so fetch a fresh one per player.
    echo "\nDealer video: {$t['stream']['url']}\n  valid until {$t['stream']['expiresAt']}\n";
});
