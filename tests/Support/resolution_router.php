<?php

declare(strict_types=1);

/**
 * Router of the local HTTP server used by Module 21 (forward 026, phase 3).
 *
 * Started by tests/Support/LocalHttpServer.php as `php -S 127.0.0.1:<port> resolution_router.php`,
 * bound to the loopback interface only. The state directory comes from the ASPEC_ROUTER_STATE
 * environment variable:
 * - config.json: behaviour per endpoint (the first path segment): `delay_ms`, `status`, `values`
 *   (JSON value by the last path segment, URL-decoded), `default`, `body` (raw body, overrides the
 *   JSON) and `location` (a Location header);
 * - calls.log: one JSON line per request (endpoint, raw URI, Host, method, Authorization, body),
 *   appended under an exclusive lock: the call counter of the tests.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

$state = (string) getenv('ASPEC_ROUTER_STATE');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) parse_url($uri, PHP_URL_PATH);
$segments = explode('/', ltrim($path, '/'));
$endpoint = $segments[0];
$last = rawurldecode((string) end($segments));

$config = [];
if ($state !== '' && is_file($state . '/config.json')) {
    $config = json_decode((string) file_get_contents($state . '/config.json'), true) ?: [];
}

/**
 * In-flight counter: incremented when a request starts, decremented when its script ends (even when
 * the client gave up on it, which PHP's built-in server does not notice while the script sleeps).
 * LocalHttpServer::waitIdle() polls it, so a test never measures time or resets the call log while
 * an aborted slow request from a previous test is still holding a worker.
 */
$inflight = static function (string $state, int $delta): void {
    $handle = fopen($state . '/inflight', 'c+');
    if ($handle === false) {
        return;
    }
    flock($handle, LOCK_EX);
    $value = (int) stream_get_contents($handle) + $delta;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) max(0, $value));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
};
if ($state !== '') {
    $inflight($state, 1);
    register_shutdown_function($inflight, $state, -1);
}

if ($state !== '') {
    file_put_contents($state . '/calls.log', json_encode([
        'endpoint' => $endpoint,
        'uri' => $uri,
        'host' => $_SERVER['HTTP_HOST'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'body' => file_get_contents('php://input'),
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

$behaviour = $config[$endpoint] ?? null;
if (!is_array($behaviour)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'NOT-FOUND-BODY-' . $endpoint;
    return true;
}

$delay = (int) ($behaviour['delay_ms'] ?? 0);
if ($delay > 0) {
    usleep($delay * 1000);
}

http_response_code((int) ($behaviour['status'] ?? 200));
if (isset($behaviour['location'])) {
    header('Location: ' . $behaviour['location']);
}
if (isset($behaviour['body'])) {
    header('Content-Type: text/plain');
    echo $behaviour['body'];
    return true;
}

header('Content-Type: application/json');
$values = $behaviour['values'] ?? [];
echo json_encode(array_key_exists($last, $values) ? $values[$last] : ($behaviour['default'] ?? null));

return true;
