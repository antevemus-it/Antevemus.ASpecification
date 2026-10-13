<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Throwable;

/**
 * InMemoryFeatureFlagErrorReporter - Keeps the Reported Failures in a List
 *
 * An IFeatureFlagErrorReporter for tests and diagnostics: every report becomes one entry of
 * entries(), in order (the shape of InMemoryQueryLogger).
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryFeatureFlagErrorReporter implements IFeatureFlagErrorReporter
{
    /** @var list<array{code: string|null, error: Throwable}> */
    private array $entries = [];

    /** {@inheritdoc} */
    public function report(?string $code, Throwable $error): void
    {
        $this->entries[] = ['code' => $code, 'error' => $error];
    }

    /**
     * The reported failures, oldest first.
     *
     * @return list<array{code: string|null, error: Throwable}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /** Forgets every entry. */
    public function clear(): void
    {
        $this->entries = [];
    }
}
