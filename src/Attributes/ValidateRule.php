<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes;

use Antevemus\ASpecification\Attributes\Exceptions\UnknownRuleOperatorException;
use Attribute;

/**
 * ValidateRule - Inline declarative attribute for rapid field-level rule assertions
 *
 * Provides a lightweight, zero-boilerplate attribute for common relational, range,
 * regex, and type validations directly on properties, methods, and classes without
 * requiring a dedicated ISpecification class.
 *
 * Features:
 * - Common relational operators: '=', '==', '===', '!=', '<>', '!==', '>', '>=', '<', '<='
 * - Advanced set and range operators: 'between', 'in', 'notIn'
 * - Pattern and format operators: 'regex', 'email', 'notNull', 'null', 'notEmpty', 'not_blank'
 * - Repeatable attribute support for compounding constraints
 * - Custom error codes and localized error messages
 * - `value:` accepted as an alias of `expected:` (the spelling used by the README examples)
 * - Closed operator catalog (OPERATORS): an unknown operator raises UnknownRuleOperatorException
 *   at construction, so a typo never degrades into a silent business violation
 *
 * @version    1.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Attributes
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ValidateRule
{
    /**
     * Closed catalog of accepted operators, in their normalized spelling (lower case, trimmed).
     *
     * Single source of truth shared with AttributeValidator::evaluateRule(): the attribute refuses
     * anything outside this list, and the validator never evaluates an operator that is not here.
     *
     * @var list<string>
     */
    public const OPERATORS = [
        '=', '==', '===', '!=', '<>', '!==',
        '>', '>=', '<', '<=',
        'between', 'in', 'notin', 'not_in',
        'regex', 'email',
        'notnull', 'not_null', 'null',
        'notempty', 'not_empty', 'empty',
        'not_blank', 'notblank',
    ];

    /**
     * Expected value, threshold, range [min, max] or pattern the operator compares against.
     *
     * Fed by `expected:` or by its alias `value:`; this property is the single source of truth
     * read by AttributeValidator.
     */
    public mixed $expected;

    /**
     * @param string $operator Validation operator ('=', '>', 'between', 'regex', 'email', etc.); see OPERATORS
     * @param mixed $expected Expected value, threshold, range [min, max], or pattern
     * @param string|null $code Business or regulatory error code
     * @param string|null $message Custom error message describing failure
     * @param string $severity Failure severity level ('ERROR', 'WARNING', 'INFO')
     * @param mixed $value Alias of $expected, as written in the README (`#[ValidateRule('>=', value: 18)]`);
     *                     used only when $expected is not given
     * @throws UnknownRuleOperatorException When the operator is not in OPERATORS (case and whitespace ignored)
     * @throws \InvalidArgumentException When both $expected and $value are given with different values
     */
    public function __construct(
        public string $operator,
        mixed $expected = null,
        public ?string $code = null,
        public ?string $message = null,
        public string $severity = 'ERROR',
        mixed $value = null,
    ) {
        if (!self::isKnownOperator($operator)) {
            throw new UnknownRuleOperatorException($operator, self::OPERATORS);
        }

        if ($expected !== null && $value !== null && $expected !== $value) {
            throw new \InvalidArgumentException(sprintf(
                "#[ValidateRule('%s')] received both 'expected' and its alias 'value' with different contents; pass only one of them.",
                $operator
            ));
        }

        $this->expected = $expected ?? $value;
    }

    /**
     * Normalize an operator spelling to the catalog form (lower case, trimmed).
     *
     * @param string $operator Operator as written on the attribute
     * @return string
     */
    public static function normalizeOperator(string $operator): string
    {
        return strtolower(trim($operator));
    }

    /**
     * Whether the operator, once normalized, belongs to the catalog.
     *
     * @param string $operator Operator as written on the attribute
     * @return bool
     */
    public static function isKnownOperator(string $operator): bool
    {
        return in_array(self::normalizeOperator($operator), self::OPERATORS, true);
    }
}
