<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes;

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
 * - Pattern and format operators: 'regex', 'email', 'notNull', 'null', 'notEmpty'
 * - Repeatable attribute support for compounding constraints
 * - Custom error codes and localized error messages
 *
 * @version    1.1.0
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
     * @param string $operator Validation operator ('=', '>', 'between', 'regex', 'email', etc.)
     * @param mixed $expected Expected value, threshold, range [min, max], or pattern
     * @param string|null $code Business or regulatory error code
     * @param string|null $message Custom error message describing failure
     * @param string $severity Failure severity level ('ERROR', 'WARNING', 'INFO')
     */
    public function __construct(
        public string $operator,
        public mixed $expected = null,
        public ?string $code = null,
        public ?string $message = null,
        public string $severity = 'ERROR',
    ) {
    }
}
