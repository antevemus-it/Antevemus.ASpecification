<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\FeatureFlags\FeatureFlagChange;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagState;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagStore;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use DateTimeImmutable;

/**
 * CountingFeatureFlagStore - A feature flag store that counts the calls it serves (the database a cache spares).
 *
 * @version 2.8.0
 */
final class CountingFeatureFlagStore implements IFeatureFlagStore
{
    /** @var array<string, int> */
    public array $calls = [];

    /**
     * @param ITransactionManager|null $transactions A manager to report instead of the inner store's (a manager of the application)
     */
    public function __construct(private readonly IFeatureFlagStore $inner, private readonly ?ITransactionManager $transactions = null)
    {
    }

    public function count(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    public function loadAll(): array
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->loadAll());
    }

    public function find(string $code): ?FeatureFlagState
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->find($code));
    }

    public function insertIfAbsent(FeatureFlagDefinition $definition, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->insertIfAbsent($definition, $at, $actor));
    }

    public function compareAndSetEnabled(string $code, bool $expected, bool $enabled, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->compareAndSetEnabled($code, $expected, $enabled, $at, $actor));
    }

    public function supportsValidity(): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->supportsValidity());
    }

    public function compareAndSetValidity(string $code, int $expectedVersion, ?DateTimeImmutable $from, ?DateTimeImmutable $until, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->compareAndSetValidity($code, $expectedVersion, $from, $until, $at, $actor));
    }

    public function supportsRollout(): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->supportsRollout());
    }

    public function compareAndSetRollout(string $code, int $expectedVersion, ?int $percent, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->compareAndSetRollout($code, $expectedVersion, $percent, $at, $actor));
    }

    public function supportsSubjects(): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->supportsSubjects());
    }

    public function addSubject(string $code, string $subjectKey): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->addSubject($code, $subjectKey));
    }

    public function removeSubject(string $code, string $subjectKey): bool
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->removeSubject($code, $subjectKey));
    }

    public function appendChange(FeatureFlagChange $change): void
    {
        $this->call(__FUNCTION__, fn() => $this->inner->appendChange($change));
    }

    public function history(?string $code = null, int $limit = 50): array
    {
        return $this->call(__FUNCTION__, fn() => $this->inner->history($code, $limit));
    }

    public function getTransactionManager(): ITransactionManager
    {
        return $this->transactions ?? $this->inner->getTransactionManager();
    }

    private function call(string $method, \Closure $work): mixed
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;

        return $work();
    }
}
