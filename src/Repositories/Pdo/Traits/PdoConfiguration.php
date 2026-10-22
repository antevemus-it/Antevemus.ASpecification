<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Persistence\IQueryLogger;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Closure;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * PdoConfiguration - The Configuration, the Accessors and the Soft Delete Views of PdoRepository
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * the optional configuration (withInMemoryFallback(), withQueryLogger(), withTimezone(), withClock(),
 * withBatchSize()), what the repository was built with (getMapping(), getConnection(), getDialect(),
 * getTransactionManager()) and its read state (hasInMemoryFallback(), getQueryLogger(),
 * logsParameterValues(), getTimezone(), getBatchSize(), seesTrashed()), and the views of the soft
 * delete (withTrashed(), onlyTrashed()).
 *
 * - SQL log (D1): withQueryLogger() receives every statement with its parameter count and duration;
 *   the parameter VALUES only with `logParameters: true` (RN-02: they can be personal data)
 * - booleans stored as 1/0 (D2, BooleanStorage; since 1.14.0 also as text, BooleanStorage::Char, read
 *   strictly: an unknown stored value is a DataIntegrityException) and default time zones (D3): a temporal column is
 *   written and read in the zone it declares, else the mapping's default, else the repository's
 *   (withTimezone()), else UTC
 * - soft delete views (D4, #[SoftDelete]; the removal itself: PdoRemoveOperations): withTrashed() and
 *   onlyTrashed() return VIEWS (copies of this repository with the configuration of the moment) whose
 *   reads also see, or only see, the marked rows; removals in any view only mark live rows.
 *
 * Like every trait of this folder it holds no state and is used only by PdoRepository, whose private
 * properties it reads and, here, writes.
 *
 * @version    2.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait PdoConfiguration
{
    /**
     * Allows (or forbids again) evaluating in PHP a specification that cannot be translated to SQL:
     * the whole table is read and filtered row by row. Off by default, because loading a table in
     * silence is the worst performance defect a repository can have; turn it on only for small tables.
     *
     * @return static
     */
    public function withInMemoryFallback(bool $enabled = true): static
    {
        $this->inMemoryFallback = $enabled;

        return $this;
    }

    /** True when non-translatable specifications are evaluated in PHP over a full scan. */
    public function hasInMemoryFallback(): bool
    {
        return $this->inMemoryFallback;
    }

    /**
     * Sends every statement to a logger (since 1.13.0, forward 031, D1), or stops logging (null).
     * The parameter values are logged only when $logParameters is true: they can be personal data,
     * so they stay out of the log unless asked for (RN-02).
     *
     * @return static
     */
    public function withQueryLogger(?IQueryLogger $logger, bool $logParameters = false): static
    {
        $this->logger = $logger;
        $this->logParameters = $logger !== null && $logParameters;

        return $this;
    }

    /** The query logger, or null (since 1.13.0). */
    public function getQueryLogger(): ?IQueryLogger
    {
        return $this->logger;
    }

    /** True when the logger receives the parameter values (since 1.13.0). */
    public function logsParameterValues(): bool
    {
        return $this->logParameters;
    }

    /**
     * Default time zone of the temporal columns when neither the column nor the mapping declares one
     * (since 1.13.0, forward 031, D3); null restores UTC. Precedence: column > mapping > repository > UTC.
     *
     * @return static
     * @throws InvalidArgumentException When the zone is unknown
     */
    public function withTimezone(?string $timezone): static
    {
        if ($timezone !== null) {
            try {
                new DateTimeZone($timezone);
            } catch (Throwable $e) {
                throw new InvalidArgumentException(sprintf('Unknown time zone "%s".', $timezone), 0, $e);
            }
        }
        $this->timezone = $timezone;
        $this->applyMapping();

        return $this;
    }

    /** The repository's default time zone, or null (since 1.13.0). */
    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * The clock of the lifecycle columns (since 1.13.0, forward 031, D4): read once per write call;
     * null restores the system clock. The instant is truncated to the second.
     *
     * @param (Closure(): DateTimeInterface)|null $clock
     * @return static
     */
    public function withClock(?Closure $clock): static
    {
        $this->clock = $clock;

        return $this;
    }

    /**
     * Maximum number of rows per batch of insertAll() and putAll() (since 1.13.0, forward 031, D5);
     * the driver's parameter limit can lower it per statement.
     *
     * @return static
     * @throws InvalidArgumentException When the size is below 1
     */
    public function withBatchSize(int $rows): static
    {
        if ($rows < 1) {
            throw new InvalidArgumentException(sprintf('The batch size must be at least 1, %d given.', $rows));
        }
        $this->batchSize = $rows;

        return $this;
    }

    /** Maximum number of rows per batch (since 1.13.0). */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * The mapping in use: the one given to the constructor, with the repository's default time zone
     * applied to the temporal columns that declare none (the same object when there is no such zone).
     */
    public function getMapping(): EntityMapping
    {
        return $this->mapping;
    }

    /** The connection. */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /** The SQL dialect in use. */
    public function getDialect(): ISqlDialect
    {
        return $this->dialect;
    }

    /** The transaction boundary of the writes. */
    public function getTransactionManager(): ITransactionManager
    {
        return $this->transactions;
    }

    /**
     * A view whose reads also see the soft-removed rows (since 1.13.0, D4). The view is a copy of
     * this repository with its configuration of the moment; removals through it still only mark live
     * rows.
     *
     * @return static
     * @throws EntityMappingException When the mapping has no soft delete column
     */
    public function withTrashed(): static
    {
        return $this->view(self::SCOPE_WITH_TRASHED);
    }

    /**
     * A view whose reads see ONLY the soft-removed rows (since 1.13.0, D4); see withTrashed().
     *
     * @return static
     * @throws EntityMappingException When the mapping has no soft delete column
     */
    public function onlyTrashed(): static
    {
        return $this->view(self::SCOPE_ONLY_TRASHED);
    }

    /** True when the reads of this repository (or view) see the soft-removed rows (since 1.13.0). */
    public function seesTrashed(): bool
    {
        return $this->scope !== self::SCOPE_LIVE;
    }

    /**
     * A copy of this repository whose reads use another soft delete scope.
     */
    private function view(string $scope): static
    {
        if ($this->softDelete === null) {
            throw new EntityMappingException(sprintf(
                '%s has no soft delete column: there is no removed row to see (map one with #[SoftDelete] or EntityMapping::softDelete()).',
                $this->mapping->getEntityClass()
            ));
        }
        $view = clone $this;
        $view->scope = $scope;

        return $view;
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function applyMapping(): void;
}
