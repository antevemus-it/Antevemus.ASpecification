<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\State\PdoTriggerStateStore;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Sql\SqlDialect;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionIntersectionType;
use TypeError;

/** An external dialect that implements ISqlDialect only (no ISqlPagination). */
class MinimalDialectWithoutPagination implements ISqlDialect
{
    public function getFamily(): string
    {
        return 'minimal';
    }

    public function escapeIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function formatBoolean(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function getTrueCondition(): string
    {
        return '1 = 1';
    }

    public function getFalseCondition(): string
    {
        return '1 = 0';
    }

    public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        return $column . ' LIKE ' . $paramPlaceholder;
    }

    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        return $column . ' REGEXP ' . $paramPlaceholder;
    }

    public function supportsRegex(): bool
    {
        return false;
    }
}

/** The same external dialect, now also paginating. */
final class MinimalPaginatingDialect extends MinimalDialectWithoutPagination implements ISqlPagination
{
    public function paginate(string $selectSql, ?int $limit, ?int $offset = null): string
    {
        return rtrim($selectSql, " ;") . ($limit !== null ? ' LIMIT ' . $limit : '');
    }
}

/**
 * PaginatingDialectRequirementTest - The PDO stores that bound a scan with LIMIT require ISqlPagination (2.1.0).
 *
 * PdoTriggerStateStore, PdoDomainEventOutbox and PdoMachineSchema held an ISqlDialect and called
 * paginate() (declared by ISqlPagination) only when the dialect was an AbstractSqlDialect, running the
 * statement with no limit otherwise. Their dialect is now resolved through
 * SqlDialectFactory::createPaginating(), which refuses a dialect without ISqlPagination up front, and
 * the property is typed ISqlDialect&ISqlPagination.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PaginatingDialectRequirementTest extends TestCase
{
    private const CONSUMERS = [PdoTriggerStateStore::class, PdoDomainEventOutbox::class, PdoMachineSchema::class];

    public function testADialectWithoutPaginationIsRefusedWithAClearMessage(): void
    {
        try {
            SqlDialectFactory::createPaginating(new MinimalDialectWithoutPagination(), PdoTriggerStateStore::class);
            self::fail('A dialect without ISqlPagination was accepted');
        } catch (UnsupportedSqlOperationException $e) {
            self::assertStringContainsString(ISqlPagination::class, $e->getMessage());
            self::assertStringContainsString(PdoTriggerStateStore::class, $e->getMessage());
            self::assertStringContainsString('"minimal"', $e->getMessage());
        }
    }

    public function testAPaginatingDialectIsReturnedAsGiven(): void
    {
        $external = new MinimalPaginatingDialect();

        self::assertSame($external, SqlDialectFactory::createPaginating($external, 'consumer'));
    }

    public function testEveryDialectOfTheLibraryPaginates(): void
    {
        foreach (SqlDialect::cases() as $case) {
            $dialect = SqlDialectFactory::createPaginating($case, 'consumer');
            self::assertInstanceOf(ISqlPagination::class, $dialect, $case->value);
            self::assertSame(SqlDialectFactory::create($case), $dialect, $case->value);
        }
    }

    /** The three consumers declare the requirement in the property type, so paginate() is part of it. */
    public function testConsumersTypeTheirDialectAsPaginating(): void
    {
        foreach (self::CONSUMERS as $consumer) {
            $type = (new ReflectionClass($consumer))->getProperty('dialect')->getType();
            self::assertInstanceOf(ReflectionIntersectionType::class, $type, $consumer);
            $names = array_map(static fn($t): string => $t->getName(), $type->getTypes());
            sort($names);
            self::assertSame([ISqlDialect::class, ISqlPagination::class], $names, $consumer);
        }
    }

    /** A dialect without paginate() can no longer sit behind any of them (before, it ran without LIMIT). */
    public function testConsumersCannotHoldADialectWithoutPagination(): void
    {
        foreach (self::CONSUMERS as $consumer) {
            $reflection = new ReflectionClass($consumer);
            $instance = $reflection->newInstanceWithoutConstructor();
            try {
                $reflection->getProperty('dialect')->setValue($instance, new MinimalDialectWithoutPagination());
                self::fail($consumer . ' holds a dialect that cannot paginate');
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testConsumersStillBuildAndLimitOnABuiltInDialect(): void
    {
        $pdo = new PDO('sqlite::memory:');

        new PdoTriggerStateStore($pdo);
        new PdoDomainEventOutbox($pdo);
        $schema = new PdoMachineSchema($pdo);

        self::assertMatchesRegularExpression('/\bLIMIT\s+3\b/', $schema->limit('SELECT a FROM t ORDER BY a', 3));
    }
}
