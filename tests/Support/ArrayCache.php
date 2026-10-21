<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * ArrayCache - An In-Memory PSR-16 Cache for the Tests of the Caching Decorators
 *
 * Behaves as a shared cache would: values are serialized on write and unserialized on read (two readers
 * never share an object, and a value that cannot be serialized fails the write), keys are checked against
 * the characters PSR-16 reserves, TTLs expire by a clock the test controls, and every call is counted. It
 * can be told to fail: `failOn('get')` makes every get() throw a CacheException, `failWhen($rule)` makes
 * the calls the rule picks throw (`$rule(string $method, ?string $key): bool`), `refuseWrites()` makes
 * set() answer false. Two decorators given the same instance share the cache, as two processes sharing a
 * Redis would.
 *
 * Loaded only when psr/simple-cache is installed (it implements its interface).
 *
 * @version 2.8.0
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{0: string, 1: int|null}> Key => [serialized value, expiry second or null] */
    private array $entries = [];

    /** @var array<string, int> Calls by method */
    private array $calls = [];

    /** @var array<string, true> Methods that throw */
    private array $failing = [];

    private bool $refuseWrites = false;

    private bool $refuseDeletes = false;

    /** @var (Closure(string, ?string): bool)|null */
    private ?Closure $failWhen = null;

    private Closure $clock;

    /**
     * @param Closure(): int|null $clock Current second (null = time())
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Makes every call of the methods throw an ArrayCacheFailure (`get`, `set`, `delete`, `getMultiple`, `setMultiple`, `deleteMultiple`, `has`, `clear`). */
    public function failOn(string ...$methods): self
    {
        foreach ($methods as $method) {
            $this->failing[$method] = true;
        }

        return $this;
    }

    /**
     * Makes the calls the rule picks throw an ArrayCacheFailure.
     *
     * @param Closure(string, ?string): bool $rule Method and key (null for the calls over several keys) => fail
     */
    public function failWhen(Closure $rule): self
    {
        $this->failWhen = $rule;

        return $this;
    }

    /** Stops every failure. */
    public function heal(): self
    {
        $this->failing = [];
        $this->failWhen = null;
        $this->refuseWrites = false;
        $this->refuseDeletes = false;

        return $this;
    }

    /** Makes set() and setMultiple() answer false without writing. */
    public function refuseWrites(): self
    {
        $this->refuseWrites = true;

        return $this;
    }

    /** Makes deleteMultiple() answer false without deleting. */
    public function refuseDeletes(): self
    {
        $this->refuseDeletes = true;

        return $this;
    }

    /** Calls of a method so far. */
    public function calls(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    /** Forgets the call counts. */
    public function resetCalls(): void
    {
        $this->calls = [];
    }

    /**
     * The live keys, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];
        foreach (array_keys($this->entries) as $key) {
            if ($this->live((string) $key)) {
                $keys[] = (string) $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /** Writes a raw value under a key, bypassing the counters (a value another writer left). */
    public function plant(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->entries[$key] = [serialize($value), $ttl === null ? null : ($this->clock)() + $ttl];
    }

    /** The seconds left of a key, or null when it has no expiry (or does not exist). */
    public function ttlOf(string $key): ?int
    {
        $expiry = $this->entries[$key][1] ?? null;

        return $expiry === null ? null : $expiry - ($this->clock)();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->enter('get', $key);

        return $this->live($key) ? unserialize($this->entries[$key][0]) : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->enter('set', $key);
        if ($this->refuseWrites) {
            return false;
        }
        $seconds = $ttl instanceof DateInterval
            ? (new DateTimeImmutable('@0'))->add($ttl)->getTimestamp()
            : $ttl;
        if ($seconds !== null && $seconds <= 0) {
            unset($this->entries[$key]);

            return true;
        }
        $this->entries[$key] = [serialize($value), $seconds === null ? null : ($this->clock)() + $seconds];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->enter('delete', $key);
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->enter('clear');
        $this->entries = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $this->enter('getMultiple');
        $values = [];
        foreach ($keys as $key) {
            self::check($key);
            $values[$key] = $this->live($key) ? unserialize($this->entries[$key][0]) : $default;
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $this->enter('setMultiple');
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $this->enter('deleteMultiple');
        if ($this->refuseDeletes) {
            return false;
        }
        foreach ($keys as $key) {
            self::check($key);
            unset($this->entries[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->enter('has', $key);

        return $this->live($key);
    }

    private function enter(string $method, ?string $key = null): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
        if ($key !== null) {
            self::check($key);
        }
        if (isset($this->failing[$method]) || ($this->failWhen !== null && ($this->failWhen)($method, $key))) {
            throw new ArrayCacheFailure(sprintf('ArrayCache: %s failed on purpose', $method));
        }
    }

    private function live(string $key): bool
    {
        if (!isset($this->entries[$key])) {
            return false;
        }
        $expiry = $this->entries[$key][1];
        if ($expiry !== null && $expiry <= ($this->clock)()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    /** PSR-16: a key is a non-empty string without the reserved characters {}()/\@: */
    private static function check(mixed $key): void
    {
        if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new ArrayCacheInvalidKey(sprintf('ArrayCache: invalid PSR-16 key %s', var_export($key, true)));
        }
    }
}
