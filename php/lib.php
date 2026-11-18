<?php
// Small helper for the Diamond API. PHP 8.0+ with the curl extension (the handle frees itself, so there is no curl_close).
//
//   DIAMONDAPI_KEY       your key (fg_test_... for the sandbox, fg_live_... for live)
//   DIAMONDAPI_BASE_URL  optional, defaults to the sandbox: https://api-sandbox.diamondapi.live

declare(strict_types=1);

const USER_AGENT = 'diamondapi-api-examples/1.0';

function base_url(): string
{
    $b = getenv('DIAMONDAPI_BASE_URL');
    return rtrim($b !== false && $b !== '' ? $b : 'https://api-sandbox.diamondapi.live', '/');
}

function api_key(): string
{
    $k = getenv('DIAMONDAPI_KEY');
    if ($k === false || $k === '') {
        fwrite(STDERR, "Set DIAMONDAPI_KEY first. Ask for a sandbox key at https://diamondapi.live/telegram\n");
        exit(2);
    }
    return $k;
}

function is_sandbox_key(): bool
{
    return strncmp(api_key(), 'fg_test_', 8) === 0;
}

class ApiError extends RuntimeException
{
    public $status;
    public $apiCode;

    public function __construct($status, ?string $code, ?string $message, ?string $requestId = null)
    {
        parent::__construct("$status $code: $message" . ($requestId ? " (X-Request-Id $requestId)" : ''));
        $this->status = $status;
        $this->apiCode = $code;
    }
}

// The sandbox allows 2 requests per second; 0.55 s between calls stays inside it.
function pace(): void
{
    static $last = 0.0;
    $wait = $last + 0.55 - microtime(true);
    if ($wait > 0) {
        usleep((int)($wait * 1e6));
    }
    $last = microtime(true);
}

/**
 * GET a path. Retries 429 rate_limited and 503 starting.
 * Returns ['status' => int, 'data' => array|null, 'headers' => array (lower-case names)].
 */
function api_get(string $path, array $params = [], array $headers = []): array
{
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    $url = base_url() . $path . ($params ? '?' . http_build_query($params) : '');
    for ($attempt = 0; ; $attempt++) {
        pace();
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',            // send Accept-Encoding: gzip and decompress
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_merge(['X-API-Key: ' . api_key(), 'Accept: application/json', 'User-Agent: ' . USER_AGENT], $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new ApiError('network', 'curl', $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status === 304) {
            return ['status' => 304, 'data' => null, 'headers' => $respHeaders];
        }
        $data = json_decode($body, true);
        if ($status < 400) {
            return ['status' => $status, 'data' => $data, 'headers' => $respHeaders];
        }
        $code = $data['code'] ?? "http_$status";
        if ($attempt < 4 && (($status === 429 && $code === 'rate_limited') || ($status === 503 && $code === 'starting'))) {
            $after = (float)($respHeaders['retry-after'] ?? 2);
            fwrite(STDERR, "... $code, retrying in {$after}s\n");
            usleep((int)($after * 1e6));
            continue;
        }
        throw new ApiError($status, $code, $data['message'] ?? substr($body, 0, 200), $respHeaders['x-request-id'] ?? null);
    }
}

function get_json(string $path, array $params = []): array
{
    return api_get($path, $params)['data'];
}

function open_table_ids(int $limit): array
{
    $tables = get_json('/v1/casino/tables')['tables'];
    $open = array_filter($tables, function ($t) { return $t['status'] === 'OPEN'; });
    return array_map(function ($t) { return $t['id']; }, array_slice(array_values($open), 0, $limit));
}

// Run a main function; print API errors cleanly instead of a stack trace.
function run(callable $main): void
{
    try {
        $main();
    } catch (ApiError $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        if ($e->status === 403) {
            fwrite(STDERR, "Requests are accepted only from IPs on your allowlist, and a key works only in its own environment.\n");
        }
        exit(1);
    }
}

/**
 * Read /v1/casino/stream for ever, resuming with Last-Event-ID.
 *
 * - sends the id of the last message back as Last-Event-ID on each reconnect;
 * - a `reconnect` message (about every 14 minutes, and before a server restart)
 *   closes the connection and reconnects at once;
 * - a dropped connection, including a 15-minute (900 s) cut by a proxy, is
 *   resumed after the server's retry: hint plus jitter;
 * - 45 s without a byte is a dead connection (heartbeats come every 15 s);
 * - an `error` message, or 400/401/403/404, stops: retrying will not help.
 *
 * $onMessage(string $event, mixed $data, ?string $lastId)
 */
function stream(string $url, callable $onMessage, ?callable $onStatus = null): void
{
    $onStatus = $onStatus ?? function (string $s) { fwrite(STDERR, "[stream] $s\n"); };
    $lastId = null;
    $retryMs = 1000;
    $failures = 0;

    while (true) {
        $rotate = false;
        $fatal = null;
        $buf = '';
        $errBody = '';
        $ev = ['event' => 'message', 'data' => [], 'id' => null];
        $respHeaders = [];
        $h = ['X-API-Key: ' . api_key(), 'Accept: text/event-stream', 'Cache-Control: no-cache', 'User-Agent: ' . USER_AGENT];
        if ($lastId !== null) {
            $h[] = 'Last-Event-ID: ' . $lastId;
        }
        $onStatus($lastId ? "connecting, resuming after $lastId" : 'connecting');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_LOW_SPEED_LIMIT => 1,     // fewer than 1 byte/s ...
            CURLOPT_LOW_SPEED_TIME => 45,     // ... for 45 s: the connection is dead
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$ev, &$lastId, &$retryMs, &$rotate, &$fatal, &$errBody, $onMessage, $onStatus) {
                if ((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) >= 400) {
                    $errBody .= $chunk;
                    return strlen($chunk);
                }
                $buf .= $chunk;
                while (preg_match('/\r\n|\r|\n/', $buf, $mm, PREG_OFFSET_CAPTURE)) {
                    $pos = $mm[0][1];
                    $line = substr($buf, 0, $pos);
                    $buf = (string)substr($buf, $pos + strlen($mm[0][0]));
                    if ($line === '') {                       // end of one message
                        if ($ev['id'] !== null) {
                            $lastId = $ev['id'];
                        }
                        if ($ev['data']) {
                            $text = implode("\n", $ev['data']);
                            $data = json_decode($text, true);
                            $data = $data === null ? $text : $data;
                            if ($ev['event'] === 'reconnect') {
                                $onStatus('server asked us to reconnect');
                                $rotate = true;
                                return 0;                     // abort this transfer
                            }
                            if ($ev['event'] === 'error') {
                                $fatal = 'stream error ' . ($data['code'] ?? '') . ': ' . ($data['message'] ?? '');
                                return 0;
                            }
                            $onMessage($ev['event'], $data, $lastId);
                        }
                        $ev = ['event' => 'message', 'data' => [], 'id' => null];
                        continue;
                    }
                    if ($line[0] === ':') {
                        continue;                             // comment, e.g. ": heartbeat"
                    }
                    $p = strpos($line, ':');
                    $field = $p === false ? $line : substr($line, 0, $p);
                    $value = $p === false ? '' : substr($line, $p + 1);
                    if ($value !== '' && $value[0] === ' ') {
                        $value = substr($value, 1);
                    }
                    if ($field === 'event') {
                        $ev['event'] = $value;
                    } elseif ($field === 'data') {
                        $ev['data'][] = $value;
                    } elseif ($field === 'id') {
                        $ev['id'] = $value;
                    } elseif ($field === 'retry' && preg_match('/^[0-9]+$/', $value)) {
                        $retryMs = (int)$value;
                    }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);

        if ($fatal !== null) {
            throw new ApiError('stream', null, $fatal);
        }
        if ($status >= 400) {
            $e = json_decode($errBody, true) ?: [];
            if (in_array($status, [400, 401, 403, 404], true)) {
                throw new ApiError($status, $e['code'] ?? null, $e['message'] ?? $errBody, $respHeaders['x-request-id'] ?? null);
            }
            // 429 too_many_streams: an old connection may still be closing; 503: server starting.
            $after = (float)($respHeaders['retry-after'] ?? min(30, 5 * 2 ** $failures));
            $onStatus("$status " . ($e['code'] ?? '') . "; retrying in {$after} s");
            $failures++;
            usleep((int)($after * 1e6));
            continue;
        }
        if ($rotate) {
            continue;                                         // reconnect at once, with Last-Event-ID
        }
        if ($status === 0) {
            $failures++;                                      // could not connect at all
        } else {
            $failures = 0;
        }
        $onStatus($ok ? 'connection closed by server' : "connection lost: $curlErr");
        usleep((int)((min(30, $retryMs / 1000 * 2 ** min($failures, 5)) + mt_rand(0, 1000) / 1000) * 1e6));
    }
}
