<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;

/**
 * RuleDefinition - Canonical Immutable Implementation of Business Rule Definition
 *
 * Represents the metadata and parameters of a configurable business rule loaded
 * from the database, supporting fluent hydration from associative arrays (PDO, ORM).
 *
 * Features:
 * - Immutable typed structure (readonly)
 * - Static factory from relational database records/arrays (fromArray)
 * - Automatic type normalization (RuleAction, int, float, string)
 * - Support for open-ended parameters and contextual metadata
 * - Applicability columns of the catalog row (product, plan, validity window) hydrated into parameters (RN-17)
 * - English vocabulary since 1.7.0, only English since 2.0.0: fromArray() refuses a Portuguese key of
 *   1.6.x (LEGACY_KEYS, LEGACY_APPLICABILITY_COLUMNS, also inside `parameters`) and a Portuguese action
 *   value with LegacyVocabularyException; the deprecated Portuguese getters were removed
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleDefinition implements IRuleDefinition
{
    /**
     * Catalog row columns that restrict where a rule applies. They travel in `parameters` under the
     * same keys, where IRuleCatalog implementations read them (RN-17, BUG-20261007-ZB6A).
     */
    public const APPLICABILITY_COLUMNS = ['product_code', 'plan_code', 'valid_from', 'valid_until'];

    /**
     * Portuguese names of the applicability columns up to 1.6.x (canonical => legacy). Read as legacy
     * until 1.14.x; since 2.0.0 refused in a catalog row and inside `parameters`
     * (LegacyVocabularyException).
     */
    public const LEGACY_APPLICABILITY_COLUMNS = [
        'product_code' => 'codigo_produto',
        'plan_code' => 'codigo_plano',
        'valid_from' => 'data_inicio_vigencia',
        'valid_until' => 'data_fim_vigencia',
    ];

    /**
     * Portuguese keys of a catalog row read by fromArray() up to 1.14.x (legacy => canonical), synonyms
     * included; refused since 2.0.0 (LegacyVocabularyException). The applicability columns are in
     * LEGACY_APPLICABILITY_COLUMNS.
     */
    public const LEGACY_KEYS = [
        'codigo' => 'code',
        'nome' => 'name',
        'descricao' => 'description',
        'tipo_regra' => 'rule_type',
        'tipo' => 'rule_type',
        'acao_ao_violar' => 'violation_action',
        'acao' => 'violation_action',
        'valor_inteiro' => 'int_value',
        'valor_decimal' => 'decimal_value',
        'valor_texto' => 'text_value',
        'fundamento_legal' => 'legal_basis',
        'mensagem_violacao' => 'violation_message',
        'condicional_expressao' => 'condition_expression',
        'prioridade' => 'priority',
        'escopo' => 'scope',
        'cenario' => 'scenario',
        'parametros' => 'parameters',
        'ativo' => 'active',
    ];

    /**
     * @param string $code Unique rule identifier in the catalog
     * @param string $name Descriptive rule name
     * @param string $ruleType Technical key identifying the validation type
     * @param RuleAction $violationAction Prescribed violation action (block, warn, log)
     * @param string|null $description Detailed description of the rule purpose
     * @param int|null $intValue Configured integer numeric parameter
     * @param float|null $decimalValue Configured decimal numeric parameter
     * @param string|null $textValue Configured string parameter
     * @param string|null $legalBasis Statutory article or regulatory foundation
     * @param string|null $violationMessage Human-readable explanatory failure message
     * @param string|null $conditionExpression Conditional activation expression
     * @param int $priority Evaluation priority order
     * @param string|null $scope Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $scenario Specific business scenario
     * @param array<string, mixed> $parameters Additional parameters and metadata
     * @param bool $active Active rule indicator
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $ruleType,
        public RuleAction $violationAction = RuleAction::BLOCK,
        public ?string $description = null,
        public ?int $intValue = null,
        public ?float $decimalValue = null,
        public ?string $textValue = null,
        public ?string $legalBasis = null,
        public ?string $violationMessage = null,
        public ?string $conditionExpression = null,
        public int $priority = 0,
        public ?string $scope = null,
        public ?string $scenario = null,
        public array $parameters = [],
        public bool $active = true
    ) {
    }

    /**
     * Instantiates a rule definition from an associative database record.
     *
     * Keys: `code`, `name`, `description`, `rule_type`, `violation_action`, `int_value`,
     * `decimal_value`, `text_value`, `legal_basis`, `violation_message`, `condition_expression`,
     * `priority`, `scope`, `scenario`, `parameters`, `active` and the applicability columns
     * `product_code`, `plan_code`, `valid_from`, `valid_until`. The Portuguese keys of 1.6.x (read as
     * legacy up to 1.14.x: `codigo`, `nome`, `descricao`, `tipo_regra`/`tipo`, `acao_ao_violar`/`acao`,
     * `valor_inteiro`, `valor_decimal`, `valor_texto`, `fundamento_legal`, `mensagem_violacao`,
     * `condicional_expressao`, `prioridade`, `escopo`, `cenario`, `parametros`, `ativo`,
     * `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia`), the Portuguese
     * applicability keys inside `parameters` and the Portuguese action values are refused since 2.0.0
     * with LegacyVocabularyException, which points to the UPDATE statements of UPGRADE-2.0.md.
     *
     * @param array<string, mixed> $row Relational record (e.g. rule catalog table row)
     * @return self
     * @throws LegacyVocabularyException When the row carries Portuguese vocabulary of 1.x
     */
    public static function fromArray(array $row): self
    {
        $subject = sprintf('rule catalog row "%s" key', is_scalar($row['code'] ?? null) ? (string) $row['code'] : '?');
        LegacyVocabularyException::assertNoLegacyKeys($row, self::LEGACY_KEYS, $subject);
        LegacyVocabularyException::assertNoLegacyKeys($row, array_flip(self::LEGACY_APPLICABILITY_COLUMNS), $subject);

        $actionRaw = $row['violation_action'] ?? null;
        $action = $actionRaw instanceof RuleAction
            ? $actionRaw
            : RuleAction::fromOrDefault(is_string($actionRaw) ? $actionRaw : null);

        $activeRaw = $row['active'] ?? true;
        $active = is_bool($activeRaw)
            ? $activeRaw
            : ($activeRaw === 'Y' || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'true');

        $intRaw = $row['int_value'] ?? null;
        $decimalRaw = $row['decimal_value'] ?? null;

        $parametersRaw = $row['parameters'] ?? null;
        $parameters = is_array($parametersRaw) ? $parametersRaw : [];
        self::assertNoLegacyParameters($parameters, (string) ($row['code'] ?? '?'));

        // RN-17: applicability columns are copied into parameters; an explicit entry prevails, a null or
        // empty column means "no restriction".
        foreach (self::APPLICABILITY_COLUMNS as $column) {
            if (array_key_exists($column, $parameters)) {
                continue;
            }
            $value = $row[$column] ?? null;
            if ($value !== null && $value !== '') {
                $parameters[$column] = $value;
            }
        }

        return new self(
            code: (string) ($row['code'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            ruleType: (string) ($row['rule_type'] ?? ''),
            violationAction: $action,
            description: self::stringOrNull($row['description'] ?? null),
            intValue: $intRaw !== null && $intRaw !== '' ? (int) $intRaw : null,
            decimalValue: $decimalRaw !== null && $decimalRaw !== '' ? (float) $decimalRaw : null,
            textValue: self::stringOrNull($row['text_value'] ?? null),
            legalBasis: self::stringOrNull($row['legal_basis'] ?? null),
            violationMessage: self::stringOrNull($row['violation_message'] ?? null),
            conditionExpression: self::stringOrNull($row['condition_expression'] ?? null),
            priority: (int) ($row['priority'] ?? 0),
            scope: self::stringOrNull($row['scope'] ?? null),
            scenario: self::stringOrNull($row['scenario'] ?? null),
            parameters: $parameters,
            active: $active
        );
    }

    /**
     * Refuses the Portuguese applicability keys of 1.6.x inside `parameters` (read as legacy up to
     * 1.14.x when the English key was absent). Shared by fromArray() and InMemoryRuleCatalog, which also
     * receives rules built in code.
     *
     * @param array<array-key, mixed> $parameters Rule parameters
     * @param string $ruleCode Rule code (error message)
     * @return void
     * @throws LegacyVocabularyException
     */
    public static function assertNoLegacyParameters(array $parameters, string $ruleCode): void
    {
        LegacyVocabularyException::assertNoLegacyKeys(
            $parameters,
            array_flip(self::LEGACY_APPLICABILITY_COLUMNS),
            sprintf('parameters of rule "%s": key', $ruleCode)
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    /** {@inheritdoc} */
    public function getCode(): string
    {
        return $this->code;
    }

    /** {@inheritdoc} */
    public function getName(): string
    {
        return $this->name;
    }

    /** {@inheritdoc} */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** {@inheritdoc} */
    public function getRuleType(): string
    {
        return $this->ruleType;
    }

    /** {@inheritdoc} */
    public function getViolationAction(): RuleAction
    {
        return $this->violationAction;
    }

    /** {@inheritdoc} */
    public function getIntValue(): ?int
    {
        return $this->intValue;
    }

    /** {@inheritdoc} */
    public function getDecimalValue(): ?float
    {
        return $this->decimalValue;
    }

    /** {@inheritdoc} */
    public function getTextValue(): ?string
    {
        return $this->textValue;
    }

    /** {@inheritdoc} */
    public function getLegalBasis(): ?string
    {
        return $this->legalBasis;
    }

    /** {@inheritdoc} */
    public function getViolationMessage(): ?string
    {
        return $this->violationMessage;
    }

    /** {@inheritdoc} */
    public function getConditionExpression(): ?string
    {
        return $this->conditionExpression;
    }

    /** {@inheritdoc} */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /** {@inheritdoc} */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /** {@inheritdoc} */
    public function getScenario(): ?string
    {
        return $this->scenario;
    }

    /** {@inheritdoc} */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /** {@inheritdoc} */
    public function isActive(): bool
    {
        return $this->active;
    }
}
