<?php
// Collect round results in order, never missing or repeating one, to settle bets.
//   php results.php            every table, from the saved cursor
//   php results.php tb_...     one table
// The cursor is kept in .results-cursor, so a second run picks up where the first stopped.
declare(strict_types=1);
require __DIR__ . '/lib.php';

const CURSOR = '.results-cursor';
const TODO = ['SETTLED' => 'settle', 'CORRECTED' => 're-settle', 'VOIDED' => 'refund stakes'];

run(function () use ($argv) {
    $since = is_file(CURSOR) ? (trim((string) file_get_contents(CURSOR)) ?: '0') : '0';
    do {
        $page = get_json('/v1/casino/results', ['since' => $since, 'table' => $argv[1] ?? '', 'limit' => 500]);
        foreach ($page['results'] as $x) {
            $side = empty($x['sideBets']) ? '' : '  side bets: ' . implode(', ', array_map(function ($s) { return "{$s['name']} {$s['outcome']}"; }, $x['sideBets']));
            printf("#%s %-9s %s %s: %s  [%s]%s  -> %s\n", $x['seq'], $x['action'], $x['table']['name'], $x['round'],
                $x['winner'] ?? 'no winner', implode(' ', $x['cards']), $side, TODO[$x['action']] ?? $x['action']);
        }
        $since = (string) $page['next'];
        file_put_contents(CURSOR, $since);
    } while (!empty($page['more'])); // while more is true, call again at once
    echo "Cursor saved: since=$since\n";
});
