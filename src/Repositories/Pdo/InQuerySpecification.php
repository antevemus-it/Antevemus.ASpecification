<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use UnitEnum;

/**
 * InQuerySpecification - "The Value Is One of the Values a Query of Another Repository Returns"
 *
 * The leaf behind Spec::inQuery() (since 1.13.0, forward 031, D6): the candidate value belongs to the
 * values of one property of the entities of another PdoRepository that satisfy a specification. It is
 * always used inside a property clause, `Spec::inQuery('customerId', $customers, $where, 'id')`.
 *
 * Two evaluations, the same answer:
 * - inside a query of a PdoRepository it compiles to `col IN (SELECT othercol FROM other WHERE ...)`,
 *   one statement, with the parameters of both queries bound (renamed apart); the subquery applies the
 *   read scope of the other repository (soft delete: its live rows, or what its withTrashed() /
 *   onlyTrashed() view sees) and leaves NULL values out. Both repositories must share the connection
 *   (the same PDO object) and the dialect; otherwise the leaf cannot be translated
 *   (NonTranslatableSpecificationException: with the in-memory fallback of the outer repository on, it
 *   is then evaluated in PHP)
 * - in memory (isSatisfiedBy(), the ALinq visitor, the in-memory fallback) the subquery runs ONCE per
 *   instance of this leaf, through PdoRepository::select() of the other repository (same scope), and
 *   its non-null values are kept for the life of the instance: build a new leaf to see newer data
 *
 * Membership in memory compares like the database: date-times by instant, enums by their backing
 * value (a pure enum by its name), integers and integral floats as the same number, everything else
 * strictly. A null candidate is never a member (SQL: the guard `col IS NOT NULL` of the property
 * clause); a non-null candidate of a kind no value of the set has (a string against integers) is a
 * type error (IncompatibleTypeException), as in InSpecification; the empty set is satisfied by none.
 * The SQL, TCriteria and ALinq visitors of the library (outside a PdoRepository) cannot translate it:
 * the first two refuse it, ALinq evaluates it in PHP.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InQuerySpecification extends AbstractSpecification implements ILeafSpecification
{
    /** @var array<string, true>|null Normalized keys of the values, once fetched */
    private ?array $keys = null;

    /** @var list<mixed> Values of the subquery that have no normalized key (compared strictly) */
    private array $others = [];

    /** @var array<string, true> Kinds of the values (n, s, d, b, e, o) */
    private array $kinds = [];

    private mixed $sample = null;

    /** Number of times the subquery ran in memory (diagnostics). */
    private int $executions = 0;

    /**
     * @param PdoRepository $repository Repository whose entities the subquery reads
     * @param ISpecification $where Specification of the subquery
     * @param string $property Property of the subquery's entities whose values form the set
     * @throws InvalidArgumentException When the property is not a readable mapped property of the
     *                                  repository's entity
     */
    public function __construct(
        private readonly PdoRepository $repository,
        private readonly ISpecification $where,
        private readonly string $property
    ) {
        $reason = null;
        if ($repository->getMapping()->findQueryColumn($property, $reason) === null) {
            throw new InvalidArgumentException(sprintf('Cannot build a subquery on "%s": %s.', $property, (string) $reason));
        }
    }

    /** The repository the subquery reads. */
    public function getRepository(): PdoRepository
    {
        return $this->repository;
    }

    /** The specification of the subquery. */
    public function getWhere(): ISpecification
    {
        return $this->where;
    }

    /** The property whose values form the set. */
    public function getProperty(): string
    {
        return $this->property;
    }

    /** How many times the subquery ran in memory (0 or 1). */
    public function getExecutionCount(): int
    {
        return $this->executions;
    }

    /**
     * {@inheritdoc}
     *
     * @throws IncompatibleTypeException When the candidate is not null and no value of the (non-empty)
     *                                   set is of its kind
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        $this->load();
        if ($this->keys === [] && $this->others === []) {
            return false;
        }

        [$kind, $key] = self::normalize($candidate);
        if ($key !== null) {
            if (isset($this->keys[$key])) {
                return true;
            }
        } elseif (in_array($candidate, $this->others, true)) {
            return true;
        }
        if (isset($this->kinds[$kind])) {
            return false;
        }

        throw new IncompatibleTypeException('InQuerySpecification', $candidate, $this->sample);
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return 'mixed';
    }

    /**
     * {@inheritdoc}
     *
     * Same repository instance, same property and an equal subquery specification (the fetched values
     * do not take part), with the same custom reason and code.
     */
    public function equals(mixed $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $other->repository === $this->repository
            && $other->property === $this->property
            && $other->customReason === $this->customReason
            && $other->customCode === $this->customCode
            && $this->where->equals($other->where);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf('inQuery(%s.%s)', (string) $this->repository->getMapping()->getTable(), $this->property);
    }

    /**
     * Runs the subquery once and keeps its non-null values.
     */
    private function load(): void
    {
        if ($this->keys !== null) {
            return;
        }
        $keys = [];
        $others = [];
        $kinds = [];
        $sample = null;
        foreach ($this->repository->select($this->where, [$this->property]) as $row) {
            $value = $row[$this->property];
            if ($value === null) {
                continue;
            }
            [$kind, $key] = self::normalize($value);
            $kinds[$kind] = true;
            $sample ??= $value;
            if ($key === null) {
                $others[] = $value;
            } else {
                $keys[$key] = true;
            }
        }
        $this->executions++;
        $this->others = $others;
        $this->kinds = $kinds;
        $this->sample = $sample;
        $this->keys = $keys;
    }

    /**
     * Kind and comparison key of a value (null key: compared strictly).
     *
     * @return array{0: string, 1: ?string}
     */
    private static function normalize(mixed $value): array
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            return ['s', 's:' . $value->name];
        }

        return match (true) {
            is_int($value) => ['n', 'n:' . $value],
            is_float($value) => ['n', (is_finite($value) && floor($value) === $value && abs($value) < 9.0E15)
                ? 'n:' . (int) $value
                : 'f:' . sprintf('%.17G', $value)],
            is_string($value) => ['s', 's:' . $value],
            is_bool($value) => ['b', 'b:' . ($value ? '1' : '0')],
            $value instanceof DateTimeInterface => ['d', 'd:' . $value->format('U.u')],
            default => ['o', null],
        };
    }
}
