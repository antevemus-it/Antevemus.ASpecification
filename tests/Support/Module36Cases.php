<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Dialects\MySqlDialect;
use Antevemus\ASpecification\Sql\Dialects\PostgreSqlDialect;
use InvalidArgumentException;

/**
 * Module36Cases - Cases of forward 048 described as data, rebuilt here by the test (Module36) and by the
 * child process that dumps them with the REAL Adianti TFilter/TCriteria (real_adianti_048_probe.php).
 *
 * - accent cases (L4): `{dialect, op, value, negated}` over the column `df`;
 * - subquery cases (L5): `{kind: in|exists, negated, where}` over the fixture `asq_item` (outer) and
 *   `asq_state` (inner), `where` a tree `[eq|neq|lt|gt, field, value] | [isNull|notNull, field] |
 *   [startsWith|contains, field, text] | [in, field, list] | [and|or, a, b] | [not, a]`.
 *
 * The expected answer of a subquery case is computed by evaluateWhere(), plain PHP over the fixture rows,
 * outside the library (RN-02).
 *
 * @version 2.5.0
 */
final class Module36Cases
{
    /** Inner rows: [id, module_id, label, deleted_at, item_ref] (hostile literals included, RN-02 L5). */
    public const STATES = [
        [1, 7, 'open', null, 1],
        [2, 7, 'apto #3', null, 2],
        [3, 7, 'a--b', '2026-01-01', 3],
        [4, 8, 'x/*y*/z', null, 1],
        [5, 8, 'x]:y', null, 4],
        [6, 8, "o'brien", null, 5],
        [7, 9, 'back\\slash', null, null],
        [8, null, 'closed', null, 2],
        [9, 9, null, null, 6],
        [10, 7, 'apto 3', '2026-02-01', 7],
        [11, 8, ':[v]:', null, 8],
        [12, 9, '50% off_!', null, 9],
    ];

    /** Outer rows: [id, state_id, name]. */
    public const ITEMS = [
        [1, 1, 'i1'], [2, 2, 'i2'], [3, 3, 'i3'], [4, 4, 'i4'], [5, 5, 'i5'], [6, 6, 'i6'], [7, 7, 'i7'],
        [8, 8, 'i8'], [9, 9, 'i9'], [10, 10, 'i10'], [11, null, 'i11'], [12, 11, 'i12'], [13, 12, 'i13'],
        [14, 99, 'i14'],
    ];

    /** Fields of the inner rows, by property name of the specification. */
    private const FIELDS = ['moduleId' => 1, 'label' => 2, 'deletedAt' => 3, 'itemRef' => 4];

    /**
     * Builds the specification of an accent case.
     *
     * @param array{dialect: string, op: string, value: string, negated: bool} $case
     */
    public static function accentSpec(array $case): ISpecification
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $leaf = match ($case['op']) {
            'contains' => Spec::contains($case['value'], $ai),
            'startsWith' => Spec::startsWith($case['value'], $ai),
            'endsWith' => Spec::endsWith($case['value'], $ai),
            'equal' => Spec::equalIgnoreCaseAndAccents($case['value']),
            'wildcard' => Spec::wildcard($case['value'], $ai),
            'regex' => Spec::regex($case['value'], $ai),
            default => throw new InvalidArgumentException('unknown op ' . $case['op']),
        };
        $spec = Spec::property('df', $leaf);

        return $case['negated'] ? Spec::not($spec) : $spec;
    }

    /**
     * The dialect of an accent case: `pgsql`, `pgsql:<function>` or `mysql`.
     */
    public static function dialect(string $name): PostgreSqlDialect|MySqlDialect
    {
        if (str_starts_with($name, 'pgsql:')) {
            return new PostgreSqlDialect(substr($name, 6));
        }

        return $name === 'pgsql' ? new PostgreSqlDialect() : new MySqlDialect();
    }

    /**
     * Builds the specification of a subquery case over `asq_item`.
     *
     * @param array{kind: string, negated: bool, where: array} $case
     */
    public static function subquerySpec(array $case): ISpecification
    {
        $where = self::whereSpec($case['where']);
        $spec = $case['kind'] === 'in'
            ? Spec::inSubquery('stateId', 'asq_state', 'id', $where)
            : Spec::existsIn('asq_state', ['item_ref' => 'id'], $where, 'asq_item');

        return $case['negated'] ? Spec::not($spec) : $spec;
    }

    /**
     * @param array<int, mixed> $node
     */
    public static function whereSpec(array $node): ISpecification
    {
        return match ($node[0]) {
            'and' => self::whereSpec($node[1])->and(self::whereSpec($node[2])),
            'or' => self::whereSpec($node[1])->or(self::whereSpec($node[2])),
            'not' => Spec::not(self::whereSpec($node[1])),
            'eq' => Spec::property($node[1], Spec::equalTo($node[2])),
            'neq' => Spec::property($node[1], Spec::notEqual($node[2])),
            'lt' => Spec::property($node[1], Spec::lessThan($node[2])),
            'gt' => Spec::property($node[1], Spec::greaterThan($node[2])),
            'isNull' => Spec::property($node[1], Spec::isNull()),
            'notNull' => Spec::property($node[1], Spec::isNotNull()),
            'startsWith' => Spec::property($node[1], Spec::startsWith($node[2])),
            'contains' => Spec::property($node[1], Spec::contains($node[2])),
            'in' => Spec::property($node[1], Spec::in(...$node[2])),
            default => throw new InvalidArgumentException('unknown node ' . $node[0]),
        };
    }

    /**
     * The expected answer of a where tree for one inner row, in plain PHP (two-valued: a comparison with a
     * NULL field is false, NOT is the plain negation, as the in-memory evaluation of the library).
     *
     * @param array<int, mixed> $node
     * @param array<int, mixed> $row One of STATES
     */
    public static function evaluateWhere(array $node, array $row): bool
    {
        $value = isset($node[1]) && is_string($node[1]) && isset(self::FIELDS[$node[1]]) ? $row[self::FIELDS[$node[1]]] : null;

        return match ($node[0]) {
            'and' => self::evaluateWhere($node[1], $row) && self::evaluateWhere($node[2], $row),
            'or' => self::evaluateWhere($node[1], $row) || self::evaluateWhere($node[2], $row),
            'not' => !self::evaluateWhere($node[1], $row),
            'eq' => $value !== null && $value === $node[2],
            'neq' => $value !== null && $value !== $node[2],
            'lt' => $value !== null && $value < $node[2],
            'gt' => $value !== null && $value > $node[2],
            'isNull' => $value === null,
            'notNull' => $value !== null,
            'startsWith' => $value !== null && str_starts_with($value, $node[2]),
            'contains' => $value !== null && str_contains($value, $node[2]),
            'in' => $value !== null && in_array($value, $node[2], true),
        };
    }

    /**
     * The ids of `asq_item` a subquery case selects, computed over the fixture rows.
     *
     * @param array{kind: string, negated: bool, where: array} $case
     * @return list<int>
     */
    public static function expectedItems(array $case): array
    {
        $ids = [];
        foreach (self::ITEMS as [$id, $stateId]) {
            if ($case['kind'] === 'in') {
                $member = false;
                foreach (self::STATES as $state) {
                    if ($stateId !== null && $state[0] === $stateId && self::evaluateWhere($case['where'], $state)) {
                        $member = true;
                    }
                }
                // NOT IN is true for a NULL column (as every negated comparison), IN is false there
                $selected = $case['negated'] ? !$member : $member;
            } else {
                $exists = false;
                foreach (self::STATES as $state) {
                    if ($state[4] === $id && self::evaluateWhere($case['where'], $state)) {
                        $exists = true;
                    }
                }
                $selected = $case['negated'] ? !$exists : $exists;
            }
            if ($selected) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * RN-07 (d): five of the single-table subqueries of the Virttus (d4ef0f5), as written there with the raw
     * `NOESC:(SELECT ...)` of TFilter and as Spec::inSubquery(), by case id: [outer column, raw value, spec].
     * The sources: AflowEngineProcessList:72 (and the five other copies), BusinessConsultantList:241,
     * PessoaList:285, PessoaList:271 and RentalUnitList:378.
     *
     * @param array{id: string, value: int|string} $case
     * @return array{0: string, 1: string, 2: ISpecification}
     */
    public static function virttusSubquery(array $case): array
    {
        $v = $case['value'];

        return match ($case['id']) {
            'module_states' => ['id', "NOESC:(SELECT state_id FROM \"aflow-engine\".module_states WHERE module_id = {$v} AND deleted_at IS NULL)",
                Spec::inSubquery('id', '"aflow-engine".module_states', 'state_id', Spec::property('moduleId', Spec::equalTo($v))->and(Spec::property('deletedAt', Spec::isNull())))],
            'consultant_units' => ['id', "NOESC:(SELECT u.business_consultant_id FROM rental_guarantee_contract_manager.business_consultant_units u WHERE u.deleted_at IS NULL AND u.system_unit_id = {$v})",
                Spec::inSubquery('id', 'rental_guarantee_contract_manager.business_consultant_units', 'business_consultant_id', Spec::property('deletedAt', Spec::isNull())->and(Spec::property('systemUnitId', Spec::equalTo($v))))],
            'contract_parties' => ['id', "NOESC:(SELECT cp.pessoa_id FROM rental_guarantee_contract_manager.contract_parties cp WHERE cp.system_unit_id = {$v} AND cp.deleted_at IS NULL)",
                Spec::inSubquery('id', 'rental_guarantee_contract_manager.contract_parties', 'pessoa_id', Spec::property('systemUnitId', Spec::equalTo($v))->and(Spec::property('deletedAt', Spec::isNull())))],
            'pessoa_documento' => ['id', "NOESC:(SELECT d.pessoa_id FROM pessoa.pessoa_documento d WHERE d.active = 'Y' AND upper(d.numero) LIKE upper('%{$v}%'))",
                Spec::inSubquery('id', 'pessoa.pessoa_documento', 'pessoa_id', Spec::property('active', Spec::equalTo('Y'))->and(Spec::property('numero', Spec::contains((string) $v, false))))],
            'rental_units' => ['id', 'NOESC:(SELECT ru.system_unit_id FROM rental_guarantee_contract_manager.rental_units ru)',
                // No WHERE in the original: alwaysTrue() (a TConstantFilter since 2.5.0, `1 = 1` never bound)
                Spec::inSubquery('id', 'rental_guarantee_contract_manager.rental_units', 'system_unit_id', Spec::alwaysTrue())],
            default => throw new InvalidArgumentException('unknown case ' . $case['id']),
        };
    }

    /**
     * The constant conditions of a TCriteria (the fix of 2.5.0): a specification by case id, or, for `raw`, the
     * TFilter('1', '=', 1) the visitor wrote up to 2.4.0 (the control).
     */
    public static function constantSpec(string $id): ?ISpecification
    {
        return match ($id) {
            'alwaysTrue' => Spec::alwaysTrue(),
            'notAlwaysFalse' => Spec::not(Spec::alwaysFalse()),
            'alwaysFalse' => Spec::alwaysFalse(),
            'notAlwaysTrue' => Spec::not(Spec::alwaysTrue()),
            'inEmpty' => Spec::property('x', Spec::in()),
            'notInEmpty' => Spec::property('x', Spec::notIn()),
            'trueOrFalse' => Spec::alwaysFalse()->or(Spec::property('x', Spec::equalTo(2))),
            'raw' => null,
            default => throw new InvalidArgumentException('unknown constant case ' . $id),
        };
    }

    /**
     * Random where trees over the inner fixture (seeded by the caller with mt_srand()).
     *
     * @return array<int, mixed>
     */
    public static function randomWhere(int $depth): array
    {
        if ($depth <= 0 || mt_rand(0, 2) === 0) {
            $labels = ['apto #3', 'a--b', 'x/*y*/z', 'x]:y', "o'brien", 'back\\slash', 'open', ':[v]:', '50% off_!', 'apto 3'];
            return match (mt_rand(0, 8)) {
                0 => ['eq', 'moduleId', mt_rand(7, 9)],
                1 => ['neq', 'moduleId', mt_rand(7, 9)],
                2 => ['eq', 'label', $labels[mt_rand(0, count($labels) - 1)]],
                3 => ['isNull', 'deletedAt'],
                4 => ['notNull', 'label'],
                5 => ['startsWith', 'label', ['apto #', 'x/*', 'x]:', "o'", 'back\\', 'a--', '50%'][mt_rand(0, 6)]],
                6 => ['contains', 'label', ['#', '--', '/*', ']:', "'", '\\', '_!'][mt_rand(0, 6)]],
                7 => ['lt', 'moduleId', mt_rand(7, 10)],
                default => ['in', 'label', [$labels[mt_rand(0, 4)], $labels[mt_rand(5, 9)]]],
            };
        }

        return match (mt_rand(0, 2)) {
            0 => ['and', self::randomWhere($depth - 1), self::randomWhere($depth - 1)],
            1 => ['or', self::randomWhere($depth - 1), self::randomWhere($depth - 1)],
            default => ['not', self::randomWhere($depth - 1)],
        };
    }
}
