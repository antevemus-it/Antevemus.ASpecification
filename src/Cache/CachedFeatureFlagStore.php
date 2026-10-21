<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use Antevemus\ASpecification\FeatureFlags\FeatureFlagChange;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagState;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagStore;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use UnexpectedValueException;

/**
 * CachedFeatureFlagStore - A Feature Flag Store Read Through a Shared PSR-16 Cache
 *
 * Decorates an IFeatureFlagStore (a PdoFeatureFlagStore, usually) so that the flags are read from the
 * database once per TTL for every process sharing the cache: FeatureFlags reads the whole table once per
 * instance (one request) with loadAll(), and this decorator serves that read from the cache. Every answer
 * is the decorated store's answer.
 *
 * - Cached: loadAll() (`<prefix>flags.all.<hash>.s1`, the subject lists included) and find()
 *   (`<prefix>flags.one.<hash>.s1`, the absence of a row included). history() and the `supports*()` go to the store.
 * - Writes go to the store, always: insertIfAbsent(), the three compare-and-set, addSubject(),
 *   removeSubject() and appendChange(). Each marks the key of its flag and the key of loadAll(); FeatureFlags
 *   runs its writes inside getTransactionManager(), whose outermost call deletes the marked keys on its way
 *   out, after the commit when it opened the transaction (ITransactionManager has no post-commit hook). A
 *   write outside a transaction of that manager deletes the keys at once. A failed compare-and-set
 *   invalidates too: the caller's view was stale.
 * - Reads inside a transaction (of that manager, or while the decorated store's PdoTransactionManager or
 *   InMemoryTransactionManager is in one) go to the store and are not cached, so a value not yet committed,
 *   or rolled back later, never reaches the cache, and a compare-and-set reads the version it compares.
 * - Consistency between nodes: the writer's node deletes the keys, so the next read of every node
 *   reads the database. A write made by something else (a script, a migration, another application) is
 *   seen at the end of the TTL (30 s by default, the safety net), or after invalidate()/invalidateAll(); a
 *   read that raced with the commit may put the old value back for one TTL. Nothing here locks: the
 *   compare-and-set of the store is still the truth of every write.
 * - Failures: a cache failure is a miss (the store answers) reported to the error reporter as a
 *   CacheFailureException, once per key per instance; a failure of the store is thrown as without a cache,
 *   and FeatureFlags turns it into "off" (fail closed) as it always did.
 *
 * Loadable without psr/simple-cache, but not constructible: the cache is a Psr\SimpleCache\CacheInterface.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CachedFeatureFlagStore implements IFeatureFlagStore
{
    /** Seconds an entry lives when no TTL is given (the safety net for writes this decorator did not see). */
    public const DEFAULT_TTL = 30;

    /** Version of the stored format (the last segment of every key). */
    public const FORMAT = 's1';

    /** Instants are stored with microseconds, in their own time zone. */
    private const INSTANT = 'Y-m-d H:i:s.u';

    private readonly GuardedCache $guard;

    private readonly string $prefix;

    private readonly string $allKey;

    private readonly InvalidatingTransactionManager $transactions;

    /** @var array<string, string> Keys to delete when the outermost transaction ends, by key */
    private array $pending = [];

    /**
     * @param IFeatureFlagStore $inner The store read on a miss and written to on every change
     * @param CacheInterface $cache The shared PSR-16 cache
     * @param int $ttl Seconds an entry lives (>= 1)
     * @param string $prefix Prefix of every key (A-Z, a-z, 0-9, "_" and ".")
     * @param string $storeId Name of this store in the cache: two stores over different tables sharing a cache and a prefix need different ids
     * @param IFeatureFlagErrorReporter|null $reporter Where cache failures are reported (null = not reported; still misses)
     * @throws InvalidArgumentException When the TTL is below 1 or the prefix is not valid
     */
    public function __construct(
        private readonly IFeatureFlagStore $inner,
        CacheInterface $cache,
        private readonly int $ttl = self::DEFAULT_TTL,
        string $prefix = CacheKeys::DEFAULT_PREFIX,
        private readonly string $storeId = 'default',
        ?IFeatureFlagErrorReporter $reporter = null
    ) {
        if ($ttl < 1) {
            throw new InvalidArgumentException(sprintf('The TTL of a cached feature flag store must be at least 1 second, %d given.', $ttl));
        }
        $this->prefix = CacheKeys::prefix($prefix);
        $this->guard = new GuardedCache($cache, $reporter);
        $this->allKey = CacheKeys::key($this->prefix, 'flags', 'all', CacheKeys::hash([$storeId]), self::FORMAT);
        $this->transactions = new InvalidatingTransactionManager($inner->getTransactionManager(), function (): void {
            $this->flush();
        });
    }

    /** The decorated store. */
    public function getInner(): IFeatureFlagStore
    {
        return $this->inner;
    }

    /** Seconds an entry lives. */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /** The key of loadAll(). */
    public function allKey(): string
    {
        return $this->allKey;
    }

    /** The key of find($code). */
    public function flagKey(string $code): string
    {
        return CacheKeys::key($this->prefix, 'flags', 'one', CacheKeys::hash([$this->storeId, $code]), self::FORMAT);
    }

    /**
     * Deletes the cached entries of a flag and of loadAll(), for every node: for a change this decorator did
     * not see, or after the commit of a transaction of the caller that a change joined.
     *
     * @return bool False when the cache failed (reported): the entries expire at the end of the TTL
     */
    public function invalidate(string $code): bool
    {
        return $this->guard->delete([$this->flagKey($code), $this->allKey], $code);
    }

    /**
     * Deletes the cached entries of every flag of the store (the codes read from the store) and of loadAll().
     *
     * @return bool False when the cache failed (reported)
     */
    public function invalidateAll(): bool
    {
        $keys = [$this->allKey];
        foreach (array_keys($this->inner->loadAll()) as $code) {
            $keys[] = $this->flagKey((string) $code);
        }

        return $this->guard->delete($keys);
    }

    /** Reports again, once per key, the cache failures already reported by this instance (for long-running workers). */
    public function forgetReportedFailures(): void
    {
        $this->guard->forgetReportedFailures();
    }

    /** {@inheritdoc} */
    public function loadAll(): array
    {
        if ($this->inTransaction()) {
            return $this->inner->loadAll();
        }
        [$state, $value] = $this->guard->get($this->allKey);
        if ($state === GuardedCache::HIT) {
            try {
                return self::decodeAll($value);
            } catch (UnexpectedValueException $e) {
                $this->guard->fail('read', $this->allKey, $e->getMessage(), $e);
            }
        }
        $states = $this->inner->loadAll();
        if ($state !== GuardedCache::FAILED) {
            $this->guard->set($this->allKey, ['flags' => array_values(array_map(self::encode(...), $states))], $this->ttl);
        }

        return $states;
    }

    /** {@inheritdoc} */
    public function find(string $code): ?FeatureFlagState
    {
        if ($this->inTransaction()) {
            return $this->inner->find($code);
        }
        $key = $this->flagKey($code);
        [$state, $value] = $this->guard->get($key, $code);
        if ($state === GuardedCache::HIT) {
            try {
                if (!is_array($value) || !array_key_exists('flag', $value)) {
                    throw new UnexpectedValueException('the entry is not a flag');
                }

                return $value['flag'] === null ? null : self::decode($value['flag']);
            } catch (UnexpectedValueException $e) {
                $this->guard->fail('read', $key, $e->getMessage(), $e, $code);
            }
        }
        $flag = $this->inner->find($code);
        if ($state !== GuardedCache::FAILED) {
            $this->guard->set($key, ['flag' => $flag === null ? null : self::encode($flag)], $this->ttl, $code);
        }

        return $flag;
    }

    /** {@inheritdoc} */
    public function insertIfAbsent(FeatureFlagDefinition $definition, DateTimeImmutable $at, string|int|null $actor): bool
    {
        try {
            return $this->inner->insertIfAbsent($definition, $at, $actor);
        } finally {
            $this->written($definition->code);
        }
    }

    /** {@inheritdoc} */
    public function compareAndSetEnabled(string $code, bool $expected, bool $enabled, DateTimeImmutable $at, string|int|null $actor): bool
    {
        try {
            return $this->inner->compareAndSetEnabled($code, $expected, $enabled, $at, $actor);
        } finally {
            $this->written($code);
        }
    }

    /** {@inheritdoc} */
    public function supportsValidity(): bool
    {
        return $this->inner->supportsValidity();
    }

    /** {@inheritdoc} */
    public function compareAndSetValidity(string $code, int $expectedVersion, ?DateTimeImmutable $from, ?DateTimeImmutable $until, DateTimeImmutable $at, string|int|null $actor): bool
    {
        try {
            return $this->inner->compareAndSetValidity($code, $expectedVersion, $from, $until, $at, $actor);
        } finally {
            $this->written($code);
        }
    }

    /** {@inheritdoc} */
    public function supportsRollout(): bool
    {
        return $this->inner->supportsRollout();
    }

    /** {@inheritdoc} */
    public function compareAndSetRollout(string $code, int $expectedVersion, ?int $percent, DateTimeImmutable $at, string|int|null $actor): bool
    {
        try {
            return $this->inner->compareAndSetRollout($code, $expectedVersion, $percent, $at, $actor);
        } finally {
            $this->written($code);
        }
    }

    /** {@inheritdoc} */
    public function supportsSubjects(): bool
    {
        return $this->inner->supportsSubjects();
    }

    /** {@inheritdoc} */
    public function addSubject(string $code, string $subjectKey): bool
    {
        try {
            return $this->inner->addSubject($code, $subjectKey);
        } finally {
            $this->written($code);
        }
    }

    /** {@inheritdoc} */
    public function removeSubject(string $code, string $subjectKey): bool
    {
        try {
            return $this->inner->removeSubject($code, $subjectKey);
        } finally {
            $this->written($code);
        }
    }

    /** {@inheritdoc} */
    public function appendChange(FeatureFlagChange $change): void
    {
        try {
            $this->inner->appendChange($change);
        } finally {
            $this->written($change->code);
        }
    }

    /** {@inheritdoc} */
    public function history(?string $code = null, int $limit = 50): array
    {
        return $this->inner->history($code, $limit);
    }

    /**
     * {@inheritdoc}
     *
     * The decorated store's manager, wrapped: when its outermost call ends, the keys written to inside it are
     * deleted. Hand this one (not the decorated store's) to code that writes through this store.
     */
    public function getTransactionManager(): ITransactionManager
    {
        return $this->transactions;
    }

    /** Marks the keys of a flag that was written to; deletes them now when no transaction of this store runs. */
    private function written(string $code): void
    {
        $key = $this->flagKey($code);
        $this->pending[$key] = $code;
        $this->pending[$this->allKey] = $code;
        if (!$this->transactions->isRunning()) {
            $this->flush();
        }
    }

    /** Deletes the marked keys in one call. */
    private function flush(): void
    {
        if ($this->pending === []) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        $this->guard->delete(array_keys($pending), reset($pending));
    }

    /** True while a write may be uncommitted: a transaction of this store, or of the decorated store's manager, runs. */
    private function inTransaction(): bool
    {
        if ($this->transactions->isRunning()) {
            return true;
        }
        $manager = $this->transactions->getInner();

        return ($manager instanceof PdoTransactionManager || $manager instanceof InMemoryTransactionManager) && $manager->inTransaction();
    }

    /** @return array<string, mixed> */
    private static function encode(FeatureFlagState $state): array
    {
        return [
            'code' => $state->code,
            'enabled' => $state->enabled,
            'name' => $state->name,
            'description' => $state->description,
            'category' => $state->category,
            'audience' => $state->audience,
            'notes' => $state->notes,
            'validFrom' => self::encodeInstant($state->validFrom),
            'validUntil' => self::encodeInstant($state->validUntil),
            'enabledAt' => self::encodeInstant($state->enabledAt),
            'enabledBy' => $state->enabledBy,
            'disabledAt' => self::encodeInstant($state->disabledAt),
            'disabledBy' => $state->disabledBy,
            'version' => $state->version,
            'subjects' => $state->subjects,
            'rolloutPercent' => $state->rolloutPercent,
        ];
    }

    /**
     * @return array<string, FeatureFlagState>
     * @throws UnexpectedValueException
     */
    private static function decodeAll(mixed $value): array
    {
        if (!is_array($value) || !isset($value['flags']) || !is_array($value['flags']) || !array_is_list($value['flags'])) {
            throw new UnexpectedValueException('the entry has no list of flags');
        }
        $states = [];
        foreach ($value['flags'] as $row) {
            $state = self::decode($row);
            $states[$state->code] = $state;
        }

        return $states;
    }

    /** @throws UnexpectedValueException */
    private static function decode(mixed $row): FeatureFlagState
    {
        if (!is_array($row)) {
            throw new UnexpectedValueException('a flag is not an array');
        }
        $subjects = self::field($row, 'subjects', 'array');
        foreach ($subjects as $subject) {
            if (!is_string($subject)) {
                throw new UnexpectedValueException('a subject key is not a string');
            }
        }
        try {
            return new FeatureFlagState(
                code: self::field($row, 'code', 'string'),
                enabled: self::field($row, 'enabled', 'bool'),
                name: self::field($row, 'name', '?string'),
                description: self::field($row, 'description', '?string'),
                category: self::field($row, 'category', '?string'),
                audience: self::field($row, 'audience', '?string'),
                notes: self::field($row, 'notes', '?string'),
                validFrom: self::decodeInstant($row, 'validFrom'),
                validUntil: self::decodeInstant($row, 'validUntil'),
                enabledAt: self::decodeInstant($row, 'enabledAt'),
                enabledBy: self::field($row, 'enabledBy', '?string'),
                disabledAt: self::decodeInstant($row, 'disabledAt'),
                disabledBy: self::field($row, 'disabledBy', '?string'),
                version: self::field($row, 'version', '?int'),
                subjects: $subjects,
                rolloutPercent: self::field($row, 'rolloutPercent', '?int')
            );
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedValueException('a flag is not valid: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @return array{0: string, 1: string}|null The instant with microseconds and the name of its time zone */
    private static function encodeInstant(?DateTimeImmutable $instant): ?array
    {
        return $instant === null ? null : [$instant->format(self::INSTANT), $instant->getTimezone()->getName()];
    }

    /**
     * @param array<array-key, mixed> $row
     * @throws UnexpectedValueException
     */
    private static function decodeInstant(array $row, string $name): ?DateTimeImmutable
    {
        if (!array_key_exists($name, $row)) {
            throw new UnexpectedValueException(sprintf('the field %s is missing', $name));
        }
        $value = $row[$name];
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !isset($value[0], $value[1]) || !is_string($value[0]) || !is_string($value[1])) {
            throw new UnexpectedValueException(sprintf('the instant %s is not [text, time zone]', $name));
        }
        try {
            $instant = DateTimeImmutable::createFromFormat(self::INSTANT, $value[0], new DateTimeZone($value[1]));
        } catch (Exception $e) {
            throw new UnexpectedValueException(sprintf('the time zone of %s is not valid', $name), previous: $e);
        }
        if ($instant === false) {
            throw new UnexpectedValueException(sprintf('the instant %s is not readable', $name));
        }

        return $instant;
    }

    /**
     * A field of a stored row, of the expected type.
     *
     * @param array<array-key, mixed> $row
     * @param string $type `string`, `?string`, `?int`, `bool` or `array`
     * @throws UnexpectedValueException
     */
    private static function field(array $row, string $name, string $type): mixed
    {
        if (!array_key_exists($name, $row)) {
            throw new UnexpectedValueException(sprintf('the field %s is missing', $name));
        }
        $value = $row[$name];
        $valid = match ($type) {
            'string' => is_string($value),
            '?string' => $value === null || is_string($value),
            '?int' => $value === null || is_int($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
        };
        if (!$valid) {
            throw new UnexpectedValueException(sprintf('the field %s is not of type %s', $name, $type));
        }

        return $value;
    }
}
