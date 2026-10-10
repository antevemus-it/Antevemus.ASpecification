<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;

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
 * - English vocabulary since 1.7.0: fromArray() reads the English keys and, as legacy, the Portuguese
 *   keys of 1.6.x (English wins when both are present); the Portuguese getters remain as deprecated
 *   aliases, removed in 2.0.0
 *
 * @version    1.7.0
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
     * from catalog rows and from `parameters` when the English key is absent; removed in 2.0.0.
     */
    public const LEGACY_APPLICABILITY_COLUMNS = [
        'product_code' => 'codigo_produto',
        'plan_code' => 'codigo_plano',
        'valid_from' => 'data_inicio_vigencia',
        'valid_until' => 'data_fim_vigencia',
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
     * Canonical keys: `code`, `name`, `description`, `rule_type`, `violation_action`, `int_value`,
     * `decimal_value`, `text_value`, `legal_basis`, `violation_message`, `condition_expression`,
     * `priority`, `scope`, `scenario`, `parameters`, `active` and the applicability columns
     * `product_code`, `plan_code`, `valid_from`, `valid_until`. The Portuguese keys of 1.6.x are
     * accepted as legacy (`codigo`, `nome`, `descricao`, `tipo_regra`/`tipo`, `acao_ao_violar`/`acao`,
     * `valor_inteiro`, `valor_decimal`, `valor_texto`, `fundamento_legal`, `mensagem_violacao`,
     * `condicional_expressao`, `prioridade`, `escopo`, `cenario`, `parametros`, `ativo`,
     * `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia`); when both are
     * present the English key wins. No runtime notice is raised for a legacy key (catalog data is not
     * code); the action value accepts the legacy Portuguese values too (RuleAction::fromOrDefault()).
     *
     * @param array<string, mixed> $row Relational record (e.g. rule catalog table row)
     * @return self
     */
    public static function fromArray(array $row): self
    {
        $actionRaw = self::pick($row, 'violation_action', 'acao_ao_violar', 'acao');
        $action = $actionRaw instanceof RuleAction
            ? $actionRaw
            : RuleAction::fromOrDefault(is_string($actionRaw) ? $actionRaw : null);

        $activeRaw = self::pick($row, 'active', 'ativo') ?? true;
        $active = is_bool($activeRaw)
            ? $activeRaw
            : ($activeRaw === 'Y' || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'true');

        $intRaw = self::pick($row, 'int_value', 'valor_inteiro');
        $decimalRaw = self::pick($row, 'decimal_value', 'valor_decimal');

        $parametersRaw = self::pick($row, 'parameters', 'parametros');
        $parameters = is_array($parametersRaw) ? $parametersRaw : [];

        // RN-17: applicability columns are copied into parameters under the English key; an explicit
        // entry (English or legacy key) prevails, a null or empty column means "no restriction".
        foreach (self::LEGACY_APPLICABILITY_COLUMNS as $column => $legacy) {
            if (array_key_exists($column, $parameters) || array_key_exists($legacy, $parameters)) {
                continue;
            }
            $value = self::pick($row, $column, $legacy);
            if ($value !== null && $value !== '') {
                $parameters[$column] = $value;
            }
        }

        return new self(
            code: (string) (self::pick($row, 'code', 'codigo') ?? ''),
            name: (string) (self::pick($row, 'name', 'nome') ?? ''),
            ruleType: (string) (self::pick($row, 'rule_type', 'tipo_regra', 'tipo') ?? ''),
            violationAction: $action,
            description: self::stringOrNull(self::pick($row, 'description', 'descricao')),
            intValue: $intRaw !== null && $intRaw !== '' ? (int) $intRaw : null,
            decimalValue: $decimalRaw !== null && $decimalRaw !== '' ? (float) $decimalRaw : null,
            textValue: self::stringOrNull(self::pick($row, 'text_value', 'valor_texto')),
            legalBasis: self::stringOrNull(self::pick($row, 'legal_basis', 'fundamento_legal')),
            violationMessage: self::stringOrNull(self::pick($row, 'violation_message', 'mensagem_violacao')),
            conditionExpression: self::stringOrNull(self::pick($row, 'condition_expression', 'condicional_expressao')),
            priority: (int) (self::pick($row, 'priority', 'prioridade') ?? 0),
            scope: self::stringOrNull(self::pick($row, 'scope', 'escopo')),
            scenario: self::stringOrNull(self::pick($row, 'scenario', 'cenario')),
            parameters: $parameters,
            active: $active
        );
    }

    /**
     * Returns the value of the first key present with a non-null value (canonical key first).
     *
     * @param array<string, mixed> $row
     * @param string ...$keys Canonical key followed by its legacy synonyms
     * @return mixed
     */
    private static function pick(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($row[$key])) {
                return $row[$key];
            }
        }

        return null;
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

    // ------------------------------------------------------------------
    // Portuguese aliases of 1.6.x (not part of IRuleDefinition since 1.7.0)
    // ------------------------------------------------------------------

    /** @deprecated 1.7.0 Removed in 2.0.0; use getCode(). */
    public function getCodigo(): string
    {
        return $this->getCode();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getName(). */
    public function getNome(): string
    {
        return $this->getName();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getDescription(). */
    public function getDescricao(): ?string
    {
        return $this->getDescription();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getRuleType(). */
    public function getTipoRegra(): string
    {
        return $this->getRuleType();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getViolationAction(). */
    public function getAcaoAoViolar(): RuleAction
    {
        return $this->getViolationAction();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getIntValue(). */
    public function getValorInteiro(): ?int
    {
        return $this->getIntValue();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getDecimalValue(). */
    public function getValorDecimal(): ?float
    {
        return $this->getDecimalValue();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getTextValue(). */
    public function getValorTexto(): ?string
    {
        return $this->getTextValue();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getLegalBasis(). */
    public function getFundamentoLegal(): ?string
    {
        return $this->getLegalBasis();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getViolationMessage(). */
    public function getMensagemViolacao(): ?string
    {
        return $this->getViolationMessage();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getConditionExpression(). */
    public function getCondicionalExpressao(): ?string
    {
        return $this->getConditionExpression();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getPriority(). */
    public function getPrioridade(): int
    {
        return $this->getPriority();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getScope(). */
    public function getEscopo(): ?string
    {
        return $this->getScope();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getScenario(). */
    public function getCenario(): ?string
    {
        return $this->getScenario();
    }

    /**
     * @return array<string, mixed>
     * @deprecated 1.7.0 Removed in 2.0.0; use getParameters().
     */
    public function getParametros(): array
    {
        return $this->getParameters();
    }
}
