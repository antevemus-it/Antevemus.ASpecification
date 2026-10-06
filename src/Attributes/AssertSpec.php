<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes;

use Attribute;

/**
 * AssertSpec - Declarative PHP 8.4 attribute to bind specifications to classes, properties, and methods
 *
 * Allows domain models, DTOs, request objects, and entities to be annotated directly
 * with domain specification classes. The AttributeValidator evaluates the target value
 * against the configured ISpecification instance.
 *
 * Features:
 * - Repeatable attribute support (multiple specifications on the same element)
 * - Targets properties, methods (getters), and classes (aggregate roots)
 * - Configurable business/regulatory error codes and custom failure messages
 * - Custom constructor arguments forwarding for parameterized specifications
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Attributes
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AssertSpec
{
    /**
     * @param class-string $specificationClass Fully-qualified class name of the ISpecification implementation
     * @param string|null $code Business, regulatory, or error catalog code (e.g. 'CLI_001')
     * @param string|null $message Custom human-readable failure message
     * @param string $severity Failure severity level ('ERROR', 'WARNING', 'INFO')
     * @param array<mixed> $arguments Optional arguments passed to the specification constructor
     */
    public function __construct(
        public string $specificationClass,
        public ?string $code = null,
        public ?string $message = null,
        public string $severity = 'ERROR',
        public array $arguments = [],
    ) {
    }
}
