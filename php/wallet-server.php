<?php
// Your wallet endpoint: we call it during play, it answers with the player's new balance.
//   DIAMONDAPI_WALLET_SECRET=... php -S 0.0.0.0:8080 wallet-server.php      POST /wallet
//
// The rules that keep balances right:
//  - check the signature on every call before anything else;
//  - every call carries a txId: a repeated txId gets the first answer again, money moves once;
//  - a refund cancels its debit (refTxId); a debit that arrives after its refund is refused.
// Amounts are integers in the smallest unit (paise), never floats.
declare(strict_types=1);

function reply(int $code, array $obj): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($obj);
}

function handle(array &$s, array $c): array
{
    $pid = $c['playerId'] ?? null;
    $tx = $c['txId'] ?? null;
    $amount = $c['amount'] ?? null;
    if (!isset($s['balances'][$pid])) return ['status' => 'UNKNOWN_PLAYER'];
    $bal = $s['balances'][$pid];
    $action = $c['action'] ?? '';
    if ($action === 'balance') return ['status' => 'OK', 'balance' => $bal];
    if (!$tx || !is_int($amount) || $amount < 0) return ['status' => 'BAD_REQUEST'];
    if (isset($s['done'][$tx])) return $s['done'][$tx]; // a retry: same answer, no money moved
    if ($action === 'debit') {
        if (isset($s['refunded'][$tx])) $answer = ['status' => 'ALREADY_REFUNDED', 'balance' => $bal];
        elseif ($amount > $bal) $answer = ['status' => 'INSUFFICIENT_FUNDS', 'balance' => $bal];
        else {
            $s['balances'][$pid] = $bal - $amount;
            $s['debits'][$tx] = $amount;
            $answer = ['status' => 'OK', 'balance' => $bal - $amount];
        }
    } elseif ($action === 'credit') {
        $s['balances'][$pid] = $bal + $amount;
        $answer = ['status' => 'OK', 'balance' => $bal + $amount];
    } elseif ($action === 'refund') {
        $ref = (string)($c['refTxId'] ?? '');
        $s['refunded'][$ref] = true;
        $back = $s['debits'][$ref] ?? 0; // give back what the debit took; nothing if it never took money
        unset($s['debits'][$ref]);
        $s['balances'][$pid] = $bal + $back;
        $answer = ['status' => 'OK', 'balance' => $bal + $back];
    } else {
        return ['status' => 'BAD_REQUEST'];
    }
    $s['done'][$tx] = $answer;
    return $answer;
}

$secret = (string)getenv('DIAMONDAPI_WALLET_SECRET');
if ($secret === '') {
    reply(500, ['status' => 'NOT_CONFIGURED', 'message' => 'Set DIAMONDAPI_WALLET_SECRET (it comes with your key).']);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/wallet') {
    reply(404, ['status' => 'NOT_FOUND']);
    return;
}
$raw = (string)file_get_contents('php://input');
if (!hash_equals(hash_hmac('sha256', $raw, $secret), $_SERVER['HTTP_X_SIGNATURE'] ?? '')) {
    reply(401, ['status' => 'BAD_SIGNATURE']);
    return;
}
$call = json_decode($raw, true);
if (!is_array($call)) {
    reply(400, ['status' => 'BAD_REQUEST']);
    return;
}

// shortcut: a JSON file under a lock, so this runs with `php -S`. Use your database: balance change and txId insert in one transaction, txId unique.
$fh = fopen(sys_get_temp_dir() . '/diamondapi-wallet-example.json', 'c+');
flock($fh, LOCK_EX);
$s = json_decode((string)stream_get_contents($fh), true) ?: ['balances' => ['player-1' => 100000], 'done' => [], 'debits' => [], 'refunded' => []];
$answer = handle($s, $call);
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($s));
flock($fh, LOCK_UN);
fclose($fh);
error_log(sprintf('%s %s %s %s -> %s', $call['action'] ?? '', $call['txId'] ?? '', $call['playerId'] ?? '', $call['amount'] ?? '', json_encode($answer)));
reply(200, $answer);
