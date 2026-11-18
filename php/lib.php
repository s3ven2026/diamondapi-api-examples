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
 * GET a path, or POST $body as JSON. Retries 429 rate_limited and 503 starting.
 * Returns ['status' => int, 'data' => array|null, 'headers' => array (lower-case names)].
 */
function api_request(string $path, array $params = [], array $headers = [], ?array $body = null): array
{
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    $url = base_url() . $path . ($params ? '?' . http_build_query($params) : '');
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    for ($attempt = 0; ; $attempt++) {
        pace();
        $respHeaders = [];
        $ch = curl_init($url);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
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
    return api_request($path, $params)['data'];
}

function post_json(string $path, array $body): array
{
    return api_request($path, [], [], $body)['data'];
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
