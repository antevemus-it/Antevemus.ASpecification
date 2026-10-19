<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleTypeCatalog;
use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;
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
 * - Applicability filters (product_code, plan_code, reference_date) read from the rule's parameters (RN-17);
 *   the Portuguese keys of 1.6.x (read as legacy from 1.7.0 to 1.14.x) are refused since 2.0.0, in the
 *   filters and in the parameters, with LegacyVocabularyException
 * - Lookup of one active rule by its code (findRuleByCode(), 2.4.0, forward 041)
 * - The rules of one type across every scenario (findRulesByType(), IRuleTypeCatalog, 2.5.0, forward 048,
 *   D4), ordered by priority descending then code, for RulePrecedence
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InMemoryRuleCatalog implements IRuleCatalog, IRuleTypeCatalog
{
    /** Reserved `$filters` key: product code the validation is about (RN-17). */
    public const FILTER_PRODUCT = 'product_code';

    /** Reserved `$filters` key: plan code the validation is about (RN-17). */
    public const FILTER_PLAN = 'plan_code';

    /** Reserved `$filters` key: reference date for the validity window (RN-17). */
    public const FILTER_REFERENCE_DATE = 'reference_date';

    /**
     * Portuguese names of the reserved `$filters` keys up to 1.6.x (canonical => legacy), read as legacy
     * until 1.14.x; refused since 2.0.0 (LegacyVocabularyException): the filters usually come from the
     * validation context, and a key that is not read silently changes which rules apply.
     */
    public const LEGACY_FILTER_KEYS = [
        self::FILTER_PRODUCT => 'codigo_produto',
        self::FILTER_PLAN => 'codigo_plano',
        self::FILTER_REFERENCE_DATE => 'data_referencia',
    ];

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
    public function findRules(string $scope, ?string $scenario = null, array $filters = []): array
    {
        // Refused even when no rule would read it (2.0.0): the caller must learn the key is dead.
        LegacyVocabularyException::assertNoLegacyKeys($filters, array_flip(self::LEGACY_FILTER_KEYS), 'filter (validation context) key');

        $matched = [];

        foreach ($this->rules as $rule) {
            if (!$rule->isActive()) {
                continue;
            }

            $ruleScope = $rule->getScope();
            // Global rule (null scope) or matching scope
            if ($ruleScope !== null && $ruleScope !== '' && $ruleScope !== $scope) {
                continue;
            }

            $ruleScenario = $rule->getScenario();
            // Rule without scenario applies to the whole scope. A rule bound to a scenario only
            // applies when that exact scenario is requested: validating without a scenario means
            // "scope-global rules only", never "every scenario at once".
            if ($ruleScenario !== null && $ruleScenario !== '' && ($scenario === null || $ruleScenario !== $scenario)) {
                continue;
            }

            if (!$this->ruleMatchesFilters($rule, $filters)) {
                continue;
            }

            $matched[] = $rule;
        }

        // Sort by descending priority
        usort($matched, fn(IRuleDefinition $a, IRuleDefinition $b) => $b->getPriority() <=> $a->getPriority());

        return $matched;
    }

    /**
     * {@inheritdoc}
     *
     * Same scope and applicability pass as findRules(), without the scenario; ordered by priority
     * descending, then code ascending (strcmp), so the order never depends on the order of insertion
     * (2.5.0, forward 048, D4).
     */
    public function findRulesByType(string $scope, string $ruleType, array $filters = []): array
    {
        LegacyVocabularyException::assertNoLegacyKeys($filters, array_flip(self::LEGACY_FILTER_KEYS), 'filter (validation context) key');

        $matched = [];
        foreach ($this->rules as $rule) {
            if (!$rule->isActive() || $rule->getRuleType() !== $ruleType) {
                continue;
            }
            $ruleScope = $rule->getScope();
            if ($ruleScope !== null && $ruleScope !== '' && $ruleScope !== $scope) {
                continue;
            }
            if (!$this->ruleMatchesFilters($rule, $filters)) {
                continue;
            }
            $matched[] = $rule;
        }

        usort(
            $matched,
            static fn(IRuleDefinition $a, IRuleDefinition $b): int => ($b->getPriority() <=> $a->getPriority()) ?: strcmp($a->getCode(), $b->getCode())
        );

        return $matched;
    }

    /**
     * {@inheritdoc}
     *
     * The first active rule added with the code (2.4.0, forward 041).
     */
    public function findRuleByCode(string $code): ?IRuleDefinition
    {
        foreach ($this->rules as $rule) {
            if ($rule->isActive() && $rule->getCode() === $code) {
                return $rule;
            }
        }

        return null;
    }

    /** {@inheritdoc} */
    public function findDocumentRules(string $scope, ?string $scenario = null): array
    {
        $matched = [];

        foreach ($this->documentRules as $docRule) {
            if (!$docRule->isActive()) {
                continue;
            }

            if ($this->documentRuleMatches($docRule, $scope, $scenario)) {
                $matched[] = $docRule;
            }
        }

        // Sort by order column
        usort($matched, fn(IDocumentRuleDefinition $a, IDocumentRuleDefinition $b) => $a->getSortOrder() <=> $b->getSortOrder());

        return $matched;
    }

    /**
     * Decides whether a rule applies under the applicability filters (RN-17, BUG-20261007-ZB6A).
     *
     * Reads the rule's restrictions from `getParameters()` (`product_code`, `plan_code`, `valid_from`,
     * `valid_until`) and the reserved keys of `$filters`, with AND semantics mirroring scope and
     * scenario: a restriction only passes when the filter brings the same value, so a missing filter
     * excludes every restricted rule and never excludes a global one. The validity window is evaluated
     * only when `reference_date` is given (inclusive, by day). Unknown filter keys are ignored, except
     * the Portuguese names of the reserved keys and of the restrictions (1.6.x), refused since 2.0.0.
     *
     * @param IRuleDefinition $rule
     * @param array<string, mixed> $filters
     * @return bool
     * @throws InvalidArgumentException When a date value cannot be read as a date
     * @throws LegacyVocabularyException When the filters or the rule parameters use a Portuguese key of 1.x
     */
    protected function ruleMatchesFilters(IRuleDefinition $rule, array $filters): bool
    {
        $restrictions = $rule->getParameters();
        RuleDefinition::assertNoLegacyParameters($restrictions, $rule->getCode());

        foreach ([self::FILTER_PRODUCT, self::FILTER_PLAN] as $key) {
            $required = self::restriction($restrictions, $key);
            if ($required === null || $required === '') {
                continue;
            }
            $given = self::filterValue($filters, $key);
            if (!is_scalar($given) || !is_scalar($required) || (string) $given !== (string) $required) {
                return false;
            }
        }

        $reference = self::filterValue($filters, self::FILTER_REFERENCE_DATE);
        if ($reference === null) {
            return true;
        }
        $day = self::toDay($reference, self::FILTER_REFERENCE_DATE);

        $start = self::restriction($restrictions, 'valid_from');
        if ($start !== null && $start !== '' && self::toDay($start, 'valid_from') > $day) {
            return false;
        }

        $end = self::restriction($restrictions, 'valid_until');
        if ($end !== null && $end !== '' && self::toDay($end, 'valid_until') < $day) {
            return false;
        }

        return true;
    }

    /**
     * Reads a reserved `$filters` key. Its Portuguese name of 1.6.x (read as fallback up to 1.14.x) is
     * refused since 2.0.0.
     *
     * @param array<string, mixed> $filters
     * @param string $key One of the FILTER_* constants
     * @return mixed
     * @throws LegacyVocabularyException When $filters carries the Portuguese name of a reserved key
     */
    public static function filterValue(array $filters, string $key): mixed
    {
        LegacyVocabularyException::assertNoLegacyKeys($filters, array_flip(self::LEGACY_FILTER_KEYS), 'filter (validation context) key');

        return $filters[$key] ?? null;
    }

    /**
     * Reads an applicability restriction from the rule parameters.
     *
     * @param array<string, mixed> $parameters
     * @param string $key One of RuleDefinition::APPLICABILITY_COLUMNS
     * @return mixed
     */
    private static function restriction(array $parameters, string $key): mixed
    {
        return $parameters[$key] ?? null;
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
     * @param string $scope
     * @param string|null $scenario
     * @return bool
     */
    protected function documentRuleMatches(IDocumentRuleDefinition $docRule, string $scope, ?string $scenario): bool
    {
        if ($docRule->getScope() !== $scope) {
            return false;
        }

        $docScenario = $docRule->getScenario();
        if ($docScenario === null || $docScenario === '') {
            return true;
        }

        return $scenario !== null && $docScenario === $scenario;
    }
}
