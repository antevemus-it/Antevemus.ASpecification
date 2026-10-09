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
 * matrix straight from relational tables, mirroring the shape of `regra_negocio`,
 * `grupo_documento_obrigatorio` and `grupo_documento_obrigatorio_tipo` (table names configurable).
 * The reference DDL, without schema and with only the columns read here, ships in
 * `resources/sql/rule_catalog.ansi.sql`.
 *
 * Features:
 * - Portable ANSI SQL, parameterized, no dialect: unquoted identifiers validated against
 *   `[A-Za-z_][A-Za-z0-9_]*`, named placeholders used once each (safe under native prepares)
 * - Same contract as InMemoryRuleCatalog (equivalent by construction): SQL narrows the rows
 *   (`active = 'Y'`, scope, scenario, product/plan, optional validity date) and the final
 *   applicability pass and the ordering reuse InMemoryRuleCatalog over the hydrated rows
 * - Scope: a rule with null (or empty) `escopo` is global and applies to every scope, as in memory
 * - Scenario: `cenario = :cenario OR cenario IS NULL`; a null scenario returns scope-global rows only
 * - `$filters` (RN-17 contract of IRuleCatalog): only the reserved keys are interpreted;
 *   `codigo_produto` and `codigo_plano` (whitelist of filter columns) become
 *   `(col IS NULL OR col = '' OR col = :value)`, or `(col IS NULL OR col = '')` when absent;
 *   `data_referencia` is evaluated by the in-memory pass on the hydrated validity dates;
 *   every other key is ignored, so validation context never leaks into SQL
 * - Optional validity filter in SQL through withAsOf(); without it the rows arrive with their
 *   dates and the in-memory contract decides (only when `data_referencia` is given)
 * - `findDocumentRules()` joins group and group type by `grupo_id`; both must be active
 * - Hydration through RuleDefinition::fromArray / DocumentRuleDefinition::fromArray; the
 *   `parametros` column holds a JSON object (null or empty = no parameters)
 * - No cache: a caching decorator wraps the catalog when needed
 *
 * Applicability lives in the columns `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`,
 * `data_fim_vigencia`: entries under those four keys inside the `parametros` JSON are dropped on
 * hydration, so that the SQL narrowing and the in-memory contract always read the same values.
 * Date columns must come back as ISO dates (`YYYY-MM-DD`), which is the default of every PDO driver
 * except Oracle (set `NLS_DATE_FORMAT` there).
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PdoRuleCatalog implements IRuleCatalog
{
    /** Default table names, mirroring the relational rule catalog. */
    public const DEFAULT_TABLES = [
        'regra' => 'regra_negocio',
        'grupo' => 'grupo_documento_obrigatorio',
        'grupo_tipo' => 'grupo_documento_obrigatorio_tipo',
    ];

    /** Columns read from the rule table (the keys RuleDefinition::fromArray understands). */
    public const RULE_COLUMNS = [
        'codigo', 'nome', 'descricao', 'tipo_regra', 'acao_ao_violar',
        'valor_inteiro', 'valor_decimal', 'valor_texto', 'fundamento_legal', 'mensagem_violacao',
        'condicional_expressao', 'prioridade', 'escopo', 'cenario', 'parametros', 'active',
        'codigo_produto', 'codigo_plano', 'data_inicio_vigencia', 'data_fim_vigencia',
    ];

    /** Whitelist of `$filters` keys translated into SQL predicates (RN-17 reserved keys bound to a column). */
    public const FILTER_COLUMNS = [InMemoryRuleCatalog::FILTER_PRODUCT, InMemoryRuleCatalog::FILTER_PLAN];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array{regra: string, grupo: string, grupo_tipo: string} Schema-qualified table names */
    private readonly array $tables;

    private ?DateTimeInterface $asOf = null;

    /**
     * @param PDO $pdo Connection to the database holding the catalog (its error mode is left untouched)
     * @param string $schema Optional schema (or database) qualifying every table; '' = unqualified
     * @param array<string, string> $tables Table names by role (`regra`, `grupo`, `grupo_tipo`); missing roles keep the default
     * @throws InvalidArgumentException When the schema, a role or a table name is not a plain SQL identifier
     */
    public function __construct(
        private readonly PDO $pdo,
        string $schema = '',
        array $tables = self::DEFAULT_TABLES
    ) {
        if ($schema !== '' && preg_match(self::IDENTIFIER, $schema) !== 1) {
            throw new InvalidArgumentException(sprintf('Rule catalog schema "%s" is not a plain SQL identifier.', $schema));
        }

        $unknown = array_diff(array_keys($tables), array_keys(self::DEFAULT_TABLES));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown rule catalog table role(s): %s. Expected: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::DEFAULT_TABLES))
            ));
        }

        $resolved = [];
        foreach (self::DEFAULT_TABLES as $role => $default) {
            $name = $tables[$role] ?? $default;
            if (!is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Rule catalog table "%s" (role %s) is not a plain SQL identifier.',
                    is_string($name) ? $name : get_debug_type($name),
                    $role
                ));
            }
            $resolved[$role] = $schema !== '' ? $schema . '.' . $name : $name;
        }

        /** @var array{regra: string, grupo: string, grupo_tipo: string} $resolved */
        $this->tables = $resolved;
    }

    /**
     * Returns a copy that filters rules by validity date in SQL: only rules with
     * `data_inicio_vigencia` null or `<= $asOf` and `data_fim_vigencia` null or `>= $asOf`
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
     * @return array{regra: string, grupo: string, grupo_tipo: string}
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /** {@inheritdoc} */
    public function findRules(string $escopo, ?string $cenario = null, array $filters = []): array
    {
        $params = [':escopo' => $escopo];
        $where = [
            "active = 'Y'",
            "(escopo = :escopo OR escopo IS NULL OR escopo = '')",
            $this->scenarioPredicate('cenario', $cenario, $params),
        ];

        foreach (self::FILTER_COLUMNS as $column) {
            $value = $filters[$column] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $placeholder = ':f_' . $column;
                $where[] = "({$column} IS NULL OR {$column} = '' OR {$column} = {$placeholder})";
                $params[$placeholder] = (string) $value;
            } else {
                $where[] = "({$column} IS NULL OR {$column} = '')";
            }
        }

        if ($this->asOf !== null) {
            $day = $this->asOf->format('Y-m-d');
            $where[] = '(data_inicio_vigencia IS NULL OR data_inicio_vigencia <= :as_of_inicio)';
            $where[] = '(data_fim_vigencia IS NULL OR data_fim_vigencia >= :as_of_fim)';
            $params[':as_of_inicio'] = $day;
            $params[':as_of_fim'] = $day;
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY prioridade DESC, codigo',
            implode(', ', self::RULE_COLUMNS),
            $this->tables['regra'],
            implode(' AND ', $where)
        );

        $rules = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $rules[] = RuleDefinition::fromArray($this->normalizeRuleRow($row));
        }

        // Final applicability pass (RN-17: data_referencia, exact product/plan match) and priority
        // ordering through the in-memory contract: both catalogs answer the same way by construction.
        return (new InMemoryRuleCatalog($rules))->findRules($escopo, $cenario, $filters);
    }

    /** {@inheritdoc} */
    public function findDocumentRules(string $escopo, ?string $cenario = null): array
    {
        $params = [':escopo' => $escopo];
        $where = [
            "g.active = 'Y'",
            "t.active = 'Y'",
            'g.escopo = :escopo',
            $this->scenarioPredicate('g.cenario', $cenario, $params),
        ];

        $sql = sprintf(
            'SELECT g.codigo AS grupo_codigo, g.escopo AS escopo, g.cenario AS cenario,'
            . ' t.codigo_tipo_documento AS codigo_tipo_documento, t.regra_obrigatoriedade AS regra_obrigatoriedade,'
            . ' t.codigo_set_alternativas AS codigo_set_alternativas, t.condicional_expressao AS condicional_expressao,'
            . ' t.ordem AS ordem, t.active AS active'
            . ' FROM %s g INNER JOIN %s t ON t.grupo_id = g.id'
            . ' WHERE %s ORDER BY t.ordem, g.ordem, g.codigo, t.id',
            $this->tables['grupo'],
            $this->tables['grupo_tipo'],
            implode(' AND ', $where)
        );

        $documentRules = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $documentRules[] = DocumentRuleDefinition::fromArray($row);
        }

        return (new InMemoryRuleCatalog([], $documentRules))->findDocumentRules($escopo, $cenario);
    }

    /**
     * Builds the scenario predicate: scope-global rows always; the requested scenario when given.
     *
     * @param string $column Column reference (optionally alias-qualified)
     * @param string|null $cenario Requested scenario; null = scope-global rows only
     * @param array<string, string> $params Bound parameters, extended in place
     * @return string
     */
    private function scenarioPredicate(string $column, ?string $cenario, array &$params): string
    {
        if ($cenario === null || $cenario === '') {
            return "({$column} IS NULL OR {$column} = '')";
        }

        $params[':cenario'] = $cenario;

        return "({$column} = :cenario OR {$column} IS NULL OR {$column} = '')";
    }

    /**
     * Decodes the `parametros` JSON column and drops the applicability keys it may carry, so that
     * the columns stay the single source of product, plan and validity restrictions.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws RuleEngineException When `parametros` is not a JSON object
     */
    private function normalizeRuleRow(array $row): array
    {
        $raw = $row['parametros'] ?? null;
        $parametros = [];

        if (is_string($raw) && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuleEngineException(sprintf(
                    'Rule "%s": column parametros is not valid JSON (%s).',
                    (string) ($row['codigo'] ?? '?'),
                    $e->getMessage()
                ), 0, $e);
            }
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                throw new RuleEngineException(sprintf(
                    'Rule "%s": column parametros must hold a JSON object.',
                    (string) ($row['codigo'] ?? '?')
                ));
            }
            $parametros = $decoded;
        }

        foreach (RuleDefinition::APPLICABILITY_COLUMNS as $column) {
            unset($parametros[$column]);
        }
        $row['parametros'] = $parametros;

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
