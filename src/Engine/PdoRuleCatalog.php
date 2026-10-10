<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PdoRuleCatalog - Relational Rule and Document Catalog over PDO
 *
 * IRuleCatalog implementation that reads the business rule catalog and the document requirement
 * matrix straight from relational tables: `business_rule`, `document_requirement_group` and
 * `document_requirement` by default (table and column names configurable). The reference DDL,
 * without schema and with only the columns read here, ships in `resources/sql/rule_catalog.ansi.sql`.
 *
 * Features:
 * - Portable ANSI SQL, parameterized, no dialect: unquoted identifiers validated against
 *   `[A-Za-z_][A-Za-z0-9_]*`, named placeholders used once each (safe under native prepares)
 * - Same contract as InMemoryRuleCatalog (equivalent by construction): SQL narrows the rows
 *   (`active = 'Y'`, scope, scenario, product/plan, optional validity date) and the final
 *   applicability pass and the ordering reuse InMemoryRuleCatalog over the hydrated rows
 * - Scope: a rule with null (or empty) `scope` is global and applies to every scope, as in memory
 * - Scenario: `scenario = :scenario OR scenario IS NULL`; a null scenario returns scope-global rows only
 * - `$filters` (RN-17 contract of IRuleCatalog): only the reserved keys are interpreted;
 *   `product_code` and `plan_code` (whitelist of filter columns) become
 *   `(col IS NULL OR col = '' OR col = :value)`, or `(col IS NULL OR col = '')` when absent;
 *   `reference_date` is evaluated by the in-memory pass on the hydrated validity dates;
 *   every other key is ignored, so validation context never leaks into SQL (the Portuguese keys
 *   of 1.6.x are read as legacy, exactly as in memory)
 * - Optional validity filter in SQL through withAsOf(); without it the rows arrive with their
 *   dates and the in-memory contract decides (only when `reference_date` is given)
 * - `findDocumentRules()` joins group and requirement by `group_id`; both must be active
 * - Hydration through RuleDefinition::fromArray / DocumentRuleDefinition::fromArray; the
 *   `parameters` column holds a JSON object (null or empty = no parameters)
 * - Column map (1.7.0): `$columns` maps a canonical column (the English name of the reference DDL)
 *   to the real column of the consumer table, in the spirit of the SQL visitor's FieldMapper; each
 *   real column is selected under its canonical alias, so hydration never depends on the real names.
 *   A canonical column shared by several tables (`id`, `code`, `scope`, `scenario`, `sort_order`,
 *   `condition_expression`, `active`) maps to the same real name in all of them
 * - Presets for the Portuguese schema of 1.5.0/1.6.x (`regra_negocio`, `grupo_documento_obrigatorio`,
 *   `grupo_documento_obrigatorio_tipo`): legacyPortugueseTables() and legacyPortugueseColumns(), so
 *   existing tables stay readable without migration
 * - No cache: a caching decorator wraps the catalog when needed
 *
 * Applicability lives in the columns `product_code`, `plan_code`, `valid_from`, `valid_until`: entries
 * under those four keys (or their legacy Portuguese names) inside the `parameters` JSON are dropped on
 * hydration, so that the SQL narrowing and the in-memory contract always read the same values.
 * Date columns must come back as ISO dates (`YYYY-MM-DD`), which is the default of every PDO driver
 * except Oracle (set `NLS_DATE_FORMAT` there).
 *
 * @version    1.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PdoRuleCatalog implements IRuleCatalog
{
    /** Default table names by role, mirroring the reference DDL. */
    public const DEFAULT_TABLES = [
        'rule' => 'business_rule',
        'group' => 'document_requirement_group',
        'requirement' => 'document_requirement',
    ];

    /**
     * Portuguese role names of 1.5.0/1.6.x accepted as keys of `$tables` (legacy => canonical);
     * removed in 2.0.0. The canonical role wins when both are given.
     */
    public const LEGACY_TABLE_ROLES = [
        'regra' => 'rule',
        'grupo' => 'group',
        'grupo_tipo' => 'requirement',
    ];

    /** Canonical columns read from the rule table (the keys RuleDefinition::fromArray understands). */
    public const RULE_COLUMNS = [
        'code', 'name', 'description', 'rule_type', 'violation_action',
        'int_value', 'decimal_value', 'text_value', 'legal_basis', 'violation_message',
        'condition_expression', 'priority', 'scope', 'scenario', 'parameters', 'active',
        'product_code', 'plan_code', 'valid_from', 'valid_until',
    ];

    /** Canonical columns read from the document requirement group table. */
    public const GROUP_COLUMNS = ['id', 'code', 'scope', 'scenario', 'sort_order', 'active'];

    /** Canonical columns read from the document requirement table. */
    public const REQUIREMENT_COLUMNS = [
        'id', 'group_id', 'document_type_code', 'requirement_mode', 'alternative_set_code',
        'condition_expression', 'sort_order', 'active',
    ];

    /** Whitelist of `$filters` keys translated into SQL predicates (RN-17 reserved keys bound to a column). */
    public const FILTER_COLUMNS = [InMemoryRuleCatalog::FILTER_PRODUCT, InMemoryRuleCatalog::FILTER_PLAN];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array{rule: string, group: string, requirement: string} Schema-qualified table names */
    private readonly array $tables;

    /** @var array<string, string> Canonical column => real column, for every canonical column */
    private readonly array $columns;

    private ?DateTimeInterface $asOf = null;

    /**
     * @param PDO $pdo Connection to the database holding the catalog (its error mode is left untouched)
     * @param string $schema Optional schema (or database) qualifying every table; '' = unqualified
     * @param array<string, string> $tables Table names by role (`rule`, `group`, `requirement`; legacy
     *                                      `regra`, `grupo`, `grupo_tipo`); missing roles keep the default
     * @param array<string, string> $columns Real column names by canonical column; missing columns keep
     *                                       the canonical name (see legacyPortugueseColumns())
     * @throws InvalidArgumentException When the schema, a role, a table, a canonical column or a real
     *                                  column name is not a plain SQL identifier or is unknown
     */
    public function __construct(
        private readonly PDO $pdo,
        string $schema = '',
        array $tables = self::DEFAULT_TABLES,
        array $columns = []
    ) {
        if ($schema !== '' && preg_match(self::IDENTIFIER, $schema) !== 1) {
            throw new InvalidArgumentException(sprintf('Rule catalog schema "%s" is not a plain SQL identifier.', $schema));
        }

        $byRole = [];
        foreach ($tables as $role => $name) {
            $canonical = self::LEGACY_TABLE_ROLES[$role] ?? $role;
            if (!array_key_exists($canonical, self::DEFAULT_TABLES)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown rule catalog table role "%s". Expected: %s.',
                    (string) $role,
                    implode(', ', array_keys(self::DEFAULT_TABLES))
                ));
            }
            // The canonical role wins over its legacy synonym.
            if ($canonical === $role || !array_key_exists($canonical, $tables)) {
                $byRole[$canonical] = $name;
            }
        }

        $resolved = [];
        foreach (self::DEFAULT_TABLES as $role => $default) {
            $name = $byRole[$role] ?? $default;
            if (!is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Rule catalog table "%s" (role %s) is not a plain SQL identifier.',
                    is_string($name) ? $name : get_debug_type($name),
                    $role
                ));
            }
            $resolved[$role] = $schema !== '' ? $schema . '.' . $name : $name;
        }

        /** @var array{rule: string, group: string, requirement: string} $resolved */
        $this->tables = $resolved;

        $known = self::canonicalColumns();
        $map = array_combine($known, $known);
        foreach ($columns as $canonical => $real) {
            if (!is_string($canonical) || !in_array($canonical, $known, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown rule catalog column "%s". Expected one of: %s.',
                    (string) $canonical,
                    implode(', ', $known)
                ));
            }
            if (!is_string($real) || preg_match(self::IDENTIFIER, $real) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Rule catalog column "%s" (canonical %s) is not a plain SQL identifier.',
                    is_string($real) ? $real : get_debug_type($real),
                    $canonical
                ));
            }
            $map[$canonical] = $real;
        }
        $this->columns = $map;
    }

    /**
     * Table names of the Portuguese schema read by 1.5.0/1.6.x, by role, for tables that predate the
     * English reference DDL: `new PdoRuleCatalog($pdo, $schema, self::legacyPortugueseTables(),
     * self::legacyPortugueseColumns())`.
     *
     * @return array{rule: string, group: string, requirement: string}
     */
    public static function legacyPortugueseTables(): array
    {
        return [
            'rule' => 'regra_negocio',
            'group' => 'grupo_documento_obrigatorio',
            'requirement' => 'grupo_documento_obrigatorio_tipo',
        ];
    }

    /**
     * Column names of the Portuguese schema read by 1.5.0/1.6.x, by canonical column (columns whose
     * name did not change, `id` and `active`, are omitted). The legacy action values (`bloquear`,
     * `alertar`, `apenas_log`) stored in such tables are read by RuleAction::fromOrDefault().
     *
     * @return array<string, string>
     */
    public static function legacyPortugueseColumns(): array
    {
        return [
            'code' => 'codigo',
            'name' => 'nome',
            'description' => 'descricao',
            'rule_type' => 'tipo_regra',
            'violation_action' => 'acao_ao_violar',
            'int_value' => 'valor_inteiro',
            'decimal_value' => 'valor_decimal',
            'text_value' => 'valor_texto',
            'legal_basis' => 'fundamento_legal',
            'violation_message' => 'mensagem_violacao',
            'condition_expression' => 'condicional_expressao',
            'priority' => 'prioridade',
            'scope' => 'escopo',
            'scenario' => 'cenario',
            'parameters' => 'parametros',
            'product_code' => 'codigo_produto',
            'plan_code' => 'codigo_plano',
            'valid_from' => 'data_inicio_vigencia',
            'valid_until' => 'data_fim_vigencia',
            'group_id' => 'grupo_id',
            'document_type_code' => 'codigo_tipo_documento',
            'requirement_mode' => 'regra_obrigatoriedade',
            'alternative_set_code' => 'codigo_set_alternativas',
            'sort_order' => 'ordem',
        ];
    }

    /**
     * Every canonical column of the three tables, without duplicates.
     *
     * @return list<string>
     */
    public static function canonicalColumns(): array
    {
        return array_values(array_unique(array_merge(self::RULE_COLUMNS, self::GROUP_COLUMNS, self::REQUIREMENT_COLUMNS)));
    }

    /**
     * Returns a copy that filters rules by validity date in SQL: only rules with
     * `valid_from` null or `<= $asOf` and `valid_until` null or `>= $asOf`
     * (inclusive, by day) are read. The original instance is left untouched.
     *
     * @param DateTimeInterface $asOf Reference date of the validity window
     * @return static
     */
    public function withAsOf(DateTimeInterface $asOf): static
    {
        $clone = clone $this;
        $clone->asOf = $asOf;

        return $clone;
    }

    /**
     * Returns the reference date of the SQL validity filter, or null when it is not applied.
     *
     * @return DateTimeInterface|null
     */
    public function getAsOf(): ?DateTimeInterface
    {
        return $this->asOf;
    }

    /**
     * Returns the schema-qualified table names in use, by role.
     *
     * @return array{rule: string, group: string, requirement: string}
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Returns the real column name of every canonical column.
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** {@inheritdoc} */
    public function findRules(string $scope, ?string $scenario = null, array $filters = []): array
    {
        $scopeColumn = $this->column('scope');
        $params = [':scope' => $scope];
        $where = [
            $this->column('active') . " = 'Y'",
            "({$scopeColumn} = :scope OR {$scopeColumn} IS NULL OR {$scopeColumn} = '')",
            $this->scenarioPredicate($this->column('scenario'), $scenario, $params),
        ];

        foreach (self::FILTER_COLUMNS as $key) {
            $column = $this->column($key);
            $value = InMemoryRuleCatalog::filterValue($filters, $key);
            if (is_scalar($value) && (string) $value !== '') {
                $placeholder = ':f_' . $key;
                $where[] = "({$column} IS NULL OR {$column} = '' OR {$column} = {$placeholder})";
                $params[$placeholder] = (string) $value;
            } else {
                $where[] = "({$column} IS NULL OR {$column} = '')";
            }
        }

        if ($this->asOf !== null) {
            $day = $this->asOf->format('Y-m-d');
            $from = $this->column('valid_from');
            $until = $this->column('valid_until');
            $where[] = "({$from} IS NULL OR {$from} <= :as_of_from)";
            $where[] = "({$until} IS NULL OR {$until} >= :as_of_until)";
            $params[':as_of_from'] = $day;
            $params[':as_of_until'] = $day;
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s DESC, %s',
            implode(', ', array_map(fn(string $c): string => $this->selectColumn('', $c), self::RULE_COLUMNS)),
            $this->tables['rule'],
            implode(' AND ', $where),
            $this->column('priority'),
            $this->column('code')
        );

        $rules = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $rules[] = RuleDefinition::fromArray($this->normalizeRuleRow($row));
        }

        // Final applicability pass (RN-17: reference_date, exact product/plan match) and priority
        // ordering through the in-memory contract: both catalogs answer the same way by construction.
        return (new InMemoryRuleCatalog($rules))->findRules($scope, $scenario, $filters);
    }

    /** {@inheritdoc} */
    public function findDocumentRules(string $scope, ?string $scenario = null): array
    {
        $params = [':scope' => $scope];
        $where = [
            'g.' . $this->column('active') . " = 'Y'",
            't.' . $this->column('active') . " = 'Y'",
            'g.' . $this->column('scope') . ' = :scope',
            $this->scenarioPredicate('g.' . $this->column('scenario'), $scenario, $params),
        ];

        $select = [
            'g.' . $this->column('code') . ' AS group_code',
            $this->selectColumn('g', 'scope'),
            $this->selectColumn('g', 'scenario'),
            $this->selectColumn('t', 'document_type_code'),
            $this->selectColumn('t', 'requirement_mode'),
            $this->selectColumn('t', 'alternative_set_code'),
            $this->selectColumn('t', 'condition_expression'),
            $this->selectColumn('t', 'sort_order'),
            $this->selectColumn('t', 'active'),
        ];

        $sql = sprintf(
            'SELECT %s FROM %s g INNER JOIN %s t ON t.%s = g.%s WHERE %s ORDER BY t.%s, g.%s, g.%s, t.%s',
            implode(', ', $select),
            $this->tables['group'],
            $this->tables['requirement'],
            $this->column('group_id'),
            $this->column('id'),
            implode(' AND ', $where),
            $this->column('sort_order'),
            $this->column('sort_order'),
            $this->column('code'),
            $this->column('id')
        );

        $documentRules = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $documentRules[] = DocumentRuleDefinition::fromArray($row);
        }

        return (new InMemoryRuleCatalog([], $documentRules))->findDocumentRules($scope, $scenario);
    }

    /**
     * Real column name of a canonical column.
     */
    private function column(string $canonical): string
    {
        return $this->columns[$canonical];
    }

    /**
     * Select item reading a canonical column under its canonical alias (`real AS canonical`, or the
     * bare column when both names coincide and there is no table alias).
     *
     * @param string $alias Table alias ('' = none)
     * @param string $canonical Canonical column
     * @return string
     */
    private function selectColumn(string $alias, string $canonical): string
    {
        $real = $this->column($canonical);
        $reference = $alias !== '' ? $alias . '.' . $real : $real;

        return ($alias === '' && $real === $canonical) ? $reference : $reference . ' AS ' . $canonical;
    }

    /**
     * Builds the scenario predicate: scope-global rows always; the requested scenario when given.
     *
     * @param string $column Column reference (optionally alias-qualified)
     * @param string|null $scenario Requested scenario; null = scope-global rows only
     * @param array<string, string> $params Bound parameters, extended in place
     * @return string
     */
    private function scenarioPredicate(string $column, ?string $scenario, array &$params): string
    {
        if ($scenario === null || $scenario === '') {
            return "({$column} IS NULL OR {$column} = '')";
        }

        $params[':scenario'] = $scenario;

        return "({$column} = :scenario OR {$column} IS NULL OR {$column} = '')";
    }

    /**
     * Decodes the `parameters` JSON column and drops the applicability keys it may carry (English or
     * legacy Portuguese), so that the columns stay the single source of product, plan and validity
     * restrictions.
     *
     * @param array<string, mixed> $row Row keyed by canonical column
     * @return array<string, mixed>
     * @throws RuleEngineException When `parameters` is not a JSON object
     */
    private function normalizeRuleRow(array $row): array
    {
        $raw = $row['parameters'] ?? null;
        $parameters = [];

        if (is_string($raw) && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuleEngineException(sprintf(
                    'Rule "%s": column %s is not valid JSON (%s).',
                    (string) ($row['code'] ?? '?'),
                    $this->column('parameters'),
                    $e->getMessage()
                ), 0, $e);
            }
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                throw new RuleEngineException(sprintf(
                    'Rule "%s": column %s must hold a JSON object.',
                    (string) ($row['code'] ?? '?'),
                    $this->column('parameters')
                ));
            }
            $parameters = $decoded;
        }

        foreach (RuleDefinition::LEGACY_APPLICABILITY_COLUMNS as $column => $legacy) {
            unset($parameters[$column], $parameters[$legacy]);
        }
        $row['parameters'] = $parameters;

        return $row;
    }

    /**
     * Runs a parameterized query and returns its rows with lowercase column keys.
     *
     * @param string $sql
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     * @throws RuleEngineException When the driver reports an error
     */
    private function fetchAll(string $sql, array $params): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->queryError($this->pdo->errorInfo());
            }
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value, PDO::PARAM_STR);
            }
            if (!$statement->execute()) {
                throw $this->queryError($statement->errorInfo());
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new RuleEngineException('Rule catalog query failed: ' . $e->getMessage(), 0, $e);
        }

        return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     * @return RuleEngineException
     */
    private function queryError(array $errorInfo): RuleEngineException
    {
        return new RuleEngineException(sprintf(
            'Rule catalog query failed: [%s] %s',
            (string) ($errorInfo[0] ?? ''),
            (string) ($errorInfo[2] ?? 'unknown driver error')
        ));
    }
}
