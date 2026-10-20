<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use RuntimeException;

/**
 * Local HTTP server of Module 21 (forward 026, phase 3): `php -S 127.0.0.1:<free port>` with
 * tests/Support/resolution_router.php, several workers (PHP_CLI_SERVER_WORKERS) so that concurrent
 * calls are served concurrently, Xdebug off, bound to the loopback interface only.
 *
 * The behaviour of each endpoint (delay, status, values, raw body) is written to a state directory
 * the router reads on every request; every request is appended to calls.log, the call counter.
 * stop() terminates the server and its workers (SIGTERM, then SIGKILL) and removes the state
 * directory; the tests call it in a `finally`, so the server never outlives the suite.
 *
 * The server's standard streams are redirected to /dev/null by `/bin/sh` (which then `exec`s PHP, so the
 * pid is the server's), not by `['file', '/dev/null', ...]` descriptors of proc_open() (2.6.0): inside a
 * mutant process of Infection the `file://` stream wrapper is Infection's own userland interceptor, a
 * stream opened through it has no file descriptor, and proc_open() failed with "posix_spawn() failed: Bad
 * file descriptor", failing Module 21 for every mutant whatever the mutation (spurious kills).
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class LocalHttpServer
{
    /** @var resource|null */
    private $process = null;
    private int $pid = 0;
    private int $port = 0;
    private string $stateDir;

    /** @var array<string, array<string, mixed>> */
    private array $endpoints = [];

    public function __construct(private readonly int $workers = 8)
    {
        $this->stateDir = sys_get_temp_dir() . '/aspec_resolution_' . getmypid() . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Starts the server on a free port and waits until it accepts connections.
     */
    public function start(): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open() is required to start the local HTTP server.');
        }
        if (!@mkdir($this->stateDir, 0777, true) && !is_dir($this->stateDir)) {
            throw new RuntimeException("Unable to create {$this->stateDir}");
        }
        $this->writeConfig();

        $this->port = self::freePort();
        $env = [
            'PHP_CLI_SERVER_WORKERS' => (string) $this->workers,
            'ASPEC_ROUTER_STATE' => $this->stateDir,
            'XDEBUG_MODE' => 'off',
            'PATH' => (string) getenv('PATH'),
        ];
        $command = [
            '/bin/sh', '-c', 'exec "$0" "$@" </dev/null >/dev/null 2>&1',
            PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=0',
            '-S', '127.0.0.1:' . $this->port,
            __DIR__ . '/resolution_router.php',
        ];
        $process = proc_open(
            $command,
            [],
            $pipes,
            $this->stateDir,
            $env
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the local HTTP server.');
        }
        $this->process = $process;
        $this->pid = (int) proc_get_status($process)['pid'];

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50000);
        }
        $this->stop();

        throw new RuntimeException('The local HTTP server did not start.');
    }

    /**
     * Terminates the server and its workers and removes the state directory. Idempotent.
     */
    public function stop(): void
    {
        if ($this->process !== null) {
            $children = self::childPids($this->pid);
            @proc_terminate($this->process, 15);
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline && proc_get_status($this->process)['running']) {
                usleep(20000);
            }
            if (proc_get_status($this->process)['running']) {
                @proc_terminate($this->process, 9);
            }
            foreach ($children as $child) {
                if (function_exists('posix_kill') && @posix_kill($child, 0)) {
                    @posix_kill($child, 9);
                }
            }
            // A signal is asynchronous: wait until every worker has really exited (gone, or a zombie,
            // which no longer holds the listening socket). PHP 8.4's server stops its workers itself;
            // on 8.2 the workers are ours to kill, and a connection made right after the kill could
            // still reach one that was dying.
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline && array_filter($children, [self::class, 'isAlive']) !== []) {
                usleep(10000);
            }
            @proc_close($this->process);
            $this->process = null;
        }

        if (is_dir($this->stateDir)) {
            foreach ((array) glob($this->stateDir . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->stateDir);
        }
    }

    /** Base URL of the server (`http://127.0.0.1:<port>`). */
    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** Port the server listens on. */
    public function port(): int
    {
        return $this->port;
    }

    /** PID of the server's main process. */
    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * Sets the behaviour of an endpoint (the first path segment).
     *
     * @param array<string, mixed> $behaviour delay_ms, status, values, default, body, location
     */
    public function endpoint(string $name, array $behaviour): void
    {
        $this->endpoints[$name] = $behaviour;
        $this->writeConfig();
    }

    /**
     * Requests received, in order, optionally for one endpoint.
     *
     * @return list<array{endpoint: string, uri: string, host: string, method: string, authorization: ?string, body: string}>
     */
    public function calls(?string $endpoint = null): array
    {
        $file = $this->stateDir . '/calls.log';
        if (!is_file($file)) {
            return [];
        }
        $calls = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $call = json_decode($line, true);
            if (is_array($call) && ($endpoint === null || $call['endpoint'] === $endpoint)) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /** Number of requests received by an endpoint. */
    public function count(string $endpoint): int
    {
        return count($this->calls($endpoint));
    }

    /**
     * Forgets the requests received so far, after waiting for the requests still running (a slow
     * request aborted by a client time limit keeps its worker until it finishes and would be
     * logged after the reset).
     */
    public function resetCalls(): void
    {
        $this->waitIdle();
        @unlink($this->stateDir . '/calls.log');
    }

    /**
     * Waits until no request is running on the server (the router's in-flight counter is zero).
     *
     * @throws RuntimeException When the server is still busy after the time limit
     */
    public function waitIdle(float $timeoutSeconds = 15.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $file = $this->stateDir . '/inflight';
        while (is_file($file) && (int) @file_get_contents($file) > 0) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The local HTTP server is still busy after the time limit.');
            }
            usleep(10_000);
        }
    }

    private function writeConfig(): void
    {
        if (is_dir($this->stateDir)) {
            file_put_contents($this->stateDir . '/config.json.tmp', json_encode($this->endpoints, JSON_UNESCAPED_SLASHES));
            rename($this->stateDir . '/config.json.tmp', $this->stateDir . '/config.json');
        }
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            throw new RuntimeException("Unable to find a free port: {$error}");
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** Whether a process still runs (exists and is not a zombie). */
    private static function isAlive(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return false;
        }
        $state = substr($stat, (int) strrpos($stat, ')') + 2, 1);

        return $state !== 'Z' && $state !== 'X';
    }

    /**
     * Direct children of a process (Linux /proc), to make sure no worker survives the server.
     *
     * @return list<int>
     */
    private static function childPids(int $pid): array
    {
        if ($pid <= 0 || !is_dir('/proc')) {
            return [];
        }
        $children = [];
        foreach ((array) glob('/proc/[0-9]*/stat') as $statFile) {
            $stat = @file_get_contents((string) $statFile);
            if ($stat === false) {
                continue;
            }
            // pid (comm) state ppid ...: comm may contain spaces, so read after the last ')'.
            $after = substr($stat, (int) strrpos($stat, ')') + 2);
            $fields = explode(' ', $after);
            if ((int) ($fields[1] ?? 0) === $pid) {
                $children[] = (int) basename(dirname((string) $statFile));
            }
        }

        return $children;
    }
}
