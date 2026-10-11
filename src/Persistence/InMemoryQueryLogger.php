<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

/**
 * InMemoryQueryLogger - Keeps the Logged Statements in a List
 *
 * An IQueryLogger for tests and diagnostics (since 1.13.0, forward 031, D1): every call becomes one
 * entry of entries(), in order. Bounded by $capacity (the oldest entries are dropped), so it can stay
 * attached to a long-running process.
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryQueryLogger implements IQueryLogger
{
    /** @var list<array{sql: string, parameterCount: int, milliseconds: float, parameters: array<string|int, mixed>|null}> */
    private array $entries = [];

    /**
     * @param int $capacity Maximum number of entries kept (>= 1)
     * @throws \InvalidArgumentException When the capacity is below 1
     */
    public function __construct(private readonly int $capacity = 10000)
    {
        if ($capacity < 1) {
            throw new \InvalidArgumentException(sprintf('The capacity must be at least 1, %d given.', $capacity));
        }
    }

    /** {@inheritdoc} */
    public function log(string $sql, int $parameterCount, float $milliseconds, ?array $parameters): void
    {
        $this->entries[] = [
            'sql' => $sql,
            'parameterCount' => $parameterCount,
            'milliseconds' => $milliseconds,
            'parameters' => $parameters,
        ];
        if (count($this->entries) > $this->capacity) {
            array_shift($this->entries);
        }
    }

    /**
     * The logged statements, oldest first.
     *
     * @return list<array{sql: string, parameterCount: int, milliseconds: float, parameters: array<string|int, mixed>|null}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The statement texts, oldest first.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return array_column($this->entries, 'sql');
    }

    /** Forgets every entry. */
    public function clear(): void
    {
        $this->entries = [];
    }
}
