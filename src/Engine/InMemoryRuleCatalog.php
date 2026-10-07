<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;

/**
 * InMemoryRuleCatalog - In-Memory Rule and Document Catalog
 *
 * In-memory implementation of IRuleCatalog designed for unit testing, isolated
 * development environments, and rapid dynamic rule compilation.
 *
 * Features:
 * - Fluent in-memory storage of business rules and document requirements
 * - Filtering by operational scope, business scenario, and active status
 * - Exact scope/scenario matching for documents (no cross-scope leakage); null scenario = scope-global only
 * - Native descending priority sorting
 * - Applicability filters (codigo_produto, codigo_plano, data_referencia) read from the rule's parametros (RN-17)
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InMemoryRuleCatalog implements IRuleCatalog
{
    /** Reserved `$filters` key: product code the validation is about (RN-17). */
    public const FILTER_PRODUCT = 'codigo_produto';

    /** Reserved `$filters` key: plan code the validation is about (RN-17). */
    public const FILTER_PLAN = 'codigo_plano';

    /** Reserved `$filters` key: reference date for the validity window (RN-17). */
    public const FILTER_REFERENCE_DATE = 'data_referencia';

    /** @var list<IRuleDefinition> */
    private array $rules = [];

    /** @var list<IDocumentRuleDefinition> */
    private array $documentRules = [];

    /**
     * @param list<IRuleDefinition> $rules Initial rules collection
     * @param list<IDocumentRuleDefinition> $documentRules Initial document requirements collection
     */
    public function __construct(array $rules = [], array $documentRules = [])
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }
        foreach ($documentRules as $docRule) {
            $this->addDocumentRule($docRule);
        }
    }

    /**
     * Adds a business rule to the catalog.
     *
     * @param IRuleDefinition $rule
     * @return self
     */
    public function addRule(IRuleDefinition $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    /**
     * Adds a document requirement to the catalog.
     *
     * @param IDocumentRuleDefinition $documentRule
     * @return self
     */
    public function addDocumentRule(IDocumentRuleDefinition $documentRule): self
    {
        $this->documentRules[] = $documentRule;
        return $this;
    }

    /** {@inheritdoc} */
    public function findRules(string $escopo, ?string $cenario = null, array $filters = []): array
    {
        $matched = [];

        foreach ($this->rules as $rule) {
            if (!$rule->isActive()) {
                continue;
            }

            $ruleEscopo = $rule->getEscopo();
            // Global rule (null scope) or matching scope
            if ($ruleEscopo !== null && $ruleEscopo !== '' && $ruleEscopo !== $escopo) {
                continue;
            }

            $ruleCenario = $rule->getCenario();
            // Rule without scenario applies to the whole scope. A rule bound to a scenario only
            // applies when that exact scenario is requested: validating without a scenario means
            // "scope-global rules only", never "every scenario at once".
            if ($ruleCenario !== null && $ruleCenario !== '' && ($cenario === null || $ruleCenario !== $cenario)) {
                continue;
            }

            if (!$this->ruleMatchesFilters($rule, $filters)) {
                continue;
            }

            $matched[] = $rule;
        }

        // Sort by descending priority
        usort($matched, fn(IRuleDefinition $a, IRuleDefinition $b) => $b->getPrioridade() <=> $a->getPrioridade());

        return $matched;
    }

    /** {@inheritdoc} */
    public function findDocumentRules(string $escopo, ?string $cenario = null): array
    {
        $matched = [];

        foreach ($this->documentRules as $docRule) {
            if (!$docRule->isActive()) {
                continue;
            }

            if ($this->documentRuleMatches($docRule, $escopo, $cenario)) {
                $matched[] = $docRule;
            }
        }

        // Sort by order column
        usort($matched, fn(IDocumentRuleDefinition $a, IDocumentRuleDefinition $b) => $a->getOrdem() <=> $b->getOrdem());

        return $matched;
    }

    /**
     * Decides whether a rule applies under the applicability filters (RN-17, BUG-20261007-ZB6A).
     *
     * Reads the rule's restrictions from `getParametros()` (`codigo_produto`, `codigo_plano`,
     * `data_inicio_vigencia`, `data_fim_vigencia`) and the reserved keys of `$filters`, with AND
     * semantics mirroring scope and scenario: a restriction only passes when the filter brings the
     * same value, so a missing filter excludes every restricted rule and never excludes a global one.
     * The validity window is evaluated only when `data_referencia` is given (inclusive, by day).
     * Unknown filter keys are ignored.
     *
     * @param IRuleDefinition $rule
     * @param array<string, mixed> $filters
     * @return bool
     * @throws InvalidArgumentException When a date value cannot be read as a date
     */
    protected function ruleMatchesFilters(IRuleDefinition $rule, array $filters): bool
    {
        $restrictions = $rule->getParametros();

        foreach ([self::FILTER_PRODUCT, self::FILTER_PLAN] as $key) {
            $required = $restrictions[$key] ?? null;
            if ($required === null || $required === '') {
                continue;
            }
            $given = $filters[$key] ?? null;
            if (!is_scalar($given) || !is_scalar($required) || (string) $given !== (string) $required) {
                return false;
            }
        }

        $reference = $filters[self::FILTER_REFERENCE_DATE] ?? null;
        if ($reference === null) {
            return true;
        }
        $day = self::toDay($reference, self::FILTER_REFERENCE_DATE);

        $start = $restrictions['data_inicio_vigencia'] ?? null;
        if ($start !== null && $start !== '' && self::toDay($start, 'data_inicio_vigencia') > $day) {
            return false;
        }

        $end = $restrictions['data_fim_vigencia'] ?? null;
        if ($end !== null && $end !== '' && self::toDay($end, 'data_fim_vigencia') < $day) {
            return false;
        }

        return true;
    }

    /**
     * Normalizes a date value (DateTimeInterface or date string) to `Y-m-d`.
     *
     * @param mixed $value
     * @param string $field Name used in the error message
     * @return string
     * @throws InvalidArgumentException When the value is not a readable date
     */
    private static function toDay(mixed $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            try {
                return (new DateTimeImmutable($value))->format('Y-m-d');
            } catch (Exception) {
                // falls through to the typed error below
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Rule applicability value "%s" must be a DateTimeInterface or a date string, %s given.',
            $field,
            is_string($value) ? '"' . $value . '"' : get_debug_type($value)
        ));
    }

    /**
     * Decides whether a document requirement belongs to the requested scope and scenario.
     *
     * Matching is exact on the declared scope and scenario: the scope must be equal; a declared
     * scenario must be equal to the requested one; a null scenario applies to the whole scope.
     * Validating without a scenario (null) only returns scope-global documents. The group code is
     * an identifier and never takes part in matching, so nothing can leak between scopes.
     *
     * @param IDocumentRuleDefinition $docRule
     * @param string $escopo
     * @param string|null $cenario
     * @return bool
     */
    protected function documentRuleMatches(IDocumentRuleDefinition $docRule, string $escopo, ?string $cenario): bool
    {
        if ($docRule->getEscopo() !== $escopo) {
            return false;
        }

        $docCenario = $docRule->getCenario();
        if ($docCenario === null || $docCenario === '') {
            return true;
        }

        return $cenario !== null && $docCenario === $cenario;
    }
}
