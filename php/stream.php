<?php
// Follow tables live: each new round, betting closing, and the result the moment it is dealt.
//   php stream.php                 the first 5 tables taking bets (sandbox keys list up to 5 ids)
//   php stream.php tb_a,tb_b       these tables
//   php stream.php all             every table on your plan (live keys only)
// Runs until Ctrl-C. Resume, reconnect and heartbeats are handled in stream() in lib.php.
declare(strict_types=1);
require __DIR__ . '/lib.php';

run(function () use ($argv) {
    $ids = $argv[1] ?? implode(',', open_table_ids(is_sandbox_key() ? 5 : 100));
    if ($ids === '') {
        echo "No table is taking bets right now; pass table ids: php stream.php tb_a,tb_b\n";
        return;
    }
    $tables = []; // table id -> table with its current round, as the API sends it
    stream(base_url() . '/v1/casino/stream?tables=' . str_replace('%2C', ',', rawurlencode($ids)), function (string $type, $data, ?string $lastId) use (&$tables) {
        $t = date('H:i:s');
        switch ($type) {
            case 'snapshot': // a whole table with its current round: replace your copy
                $tables[$data['id']] = $data;
                echo "$t snapshot  {$data['name']}: round {$data['round']['id']} {$data['round']['status']}\n";
                break;
            case 'ready':
                echo "$t ready     " . count($tables) . " tables loaded; changes from here on (last id $lastId)\n";
                break;
            case 'round': // BETTING -> CLOSED -> RESULT, then a new round opens
                if (isset($tables[$data['table']])) $tables[$data['table']]['round'] = $data['round'];
                $name = $tables[$data['table']]['name'] ?? $data['table'];
                echo "$t round     $name: {$data['round']['id']} {$data['round']['status']}\n";
                break;
            case 'result':
                echo "$t result    #{$data['seq']} {$data['action']} {$data['table']['name']} {$data['round']}: " . ($data['winner'] ?? 'no winner') . ' [' . implode(' ', $data['cards']) . "]\n";
                break;
            default:
                echo "$t $type\n";
        }
    });
});
