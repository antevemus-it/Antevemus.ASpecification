<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * RedisCache - A Minimal PSR-16 Client of Redis, for the Portability Round of the Caching Decorators
 *
 * Development only (never in `require`): it speaks RESP over a TCP socket, with the commands the decorators
 * need (GET, SET with EX, DEL, EXISTS, MGET, FLUSHDB), and serializes values as PHP's serialize(), as the
 * PSR-16 adapters of Redis do by default. tests/Fixtures/pdo_repository/run_portability.sh starts a
 * disposable Redis container and hands its address in ASPEC_REDIS_DSN (`tcp://127.0.0.1:<port>`); Module 43
 * then runs its shared-cache scenarios over it, between two connections and between two processes.
 *
 * @version 2.8.0
 */
final class RedisCache implements CacheInterface
{
    /** @var resource */
    private $socket;

    public function __construct(private readonly string $dsn, float $timeout = 5.0)
    {
        $socket = @stream_socket_client($dsn, $errno, $error, $timeout);
        if ($socket === false) {
            throw new ArrayCacheFailure(sprintf('RedisCache: cannot connect to %s: %s (%d)', $dsn, $error, $errno));
        }
        stream_set_timeout($socket, (int) ceil($timeout));
        $this->socket = $socket;
    }

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /** The address of the server. */
    public function getDsn(): string
    {
        return $this->dsn;
    }

    /**
     * Runs one command and returns its reply (string, int, null or list).
     *
     * @param list<string|int> $arguments
     */
    public function command(string|int ...$arguments): mixed
    {
        $request = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            $request .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }
        for ($written = 0; $written < strlen($request); $written += $bytes) {
            $bytes = fwrite($this->socket, substr($request, $written));
            if ($bytes === false || $bytes === 0) {
                throw new ArrayCacheFailure('RedisCache: write failed');
            }
        }

        return $this->reply();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        self::check($key);
        $value = $this->command('GET', $key);

        return $value === null ? $default : unserialize((string) $value);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        self::check($key);
        $seconds = $ttl instanceof DateInterval ? (new DateTimeImmutable('@0'))->add($ttl)->getTimestamp() : $ttl;
        if ($seconds !== null && $seconds <= 0) {
            $this->command('DEL', $key);

            return true;
        }
        $reply = $seconds === null
            ? $this->command('SET', $key, serialize($value))
            : $this->command('SET', $key, serialize($value), 'EX', $seconds);

        return $reply === 'OK';
    }

    public function delete(string $key): bool
    {
        self::check($key);
        $this->command('DEL', $key);

        return true;
    }

    public function clear(): bool
    {
        return $this->command('FLUSHDB') === 'OK';
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $list = [];
        foreach ($keys as $key) {
            self::check($key);
            $list[] = $key;
        }
        if ($list === []) {
            return [];
        }
        $values = [];
        foreach ($this->command('MGET', ...$list) as $i => $value) {
            $values[$list[$i]] = $value === null ? $default : unserialize((string) $value);
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $list = [];
        foreach ($keys as $key) {
            self::check($key);
            $list[] = $key;
        }
        if ($list !== []) {
            $this->command('DEL', ...$list);
        }

        return true;
    }

    public function has(string $key): bool
    {
        self::check($key);

        return $this->command('EXISTS', $key) === 1;
    }

    /** The seconds left of a key (-1 without expiry, -2 when it does not exist). */
    public function ttlOf(string $key): int
    {
        return (int) $this->command('TTL', $key);
    }

    private function reply(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new ArrayCacheFailure('RedisCache: no reply');
        }
        $type = $line[0];
        $payload = substr($line, 1, -2);

        switch ($type) {
            case '+':
                return $payload;
            case '-':
                throw new ArrayCacheFailure('RedisCache: ' . $payload);
            case ':':
                return (int) $payload;
            case '$':
                $length = (int) $payload;
                if ($length < 0) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $length + 2) {
                    $chunk = fread($this->socket, $length + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        throw new ArrayCacheFailure('RedisCache: truncated reply');
                    }
                    $data .= $chunk;
                }

                return substr($data, 0, $length);
            case '*':
                $count = (int) $payload;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->reply();
                }

                return $items;
        }

        throw new ArrayCacheFailure('RedisCache: unexpected reply ' . $line);
    }

    private static function check(mixed $key): void
    {
        if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new ArrayCacheInvalidKey(sprintf('RedisCache: invalid PSR-16 key %s', var_export($key, true)));
        }
    }
}
