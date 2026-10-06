<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Results;

use Stringable;

/**
 * SpecificationFailure - Notification Object for Detailed Rule Violation Logging
 *
 * Represents a discrete failure resulting from specification evaluation under
 * Martin Fowler's Notification Pattern. Encapsulates a descriptive error message,
 * business/regulatory code, violated rule name, target property path, and context metadata.
 *
 * Features:
 * - Immutable (readonly) structure with strict typing
 * - Support for regulatory and business error codes (e.g. 'INQ_004', 'APOL_002')
 * - Association with the target object property evaluated
 * - Contextual metadata attachment for diagnostics and telemetry
 * - Human-readable string representation via Stringable
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Results
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SpecificationFailure implements Stringable
{
    /**
     * @param string $message Descriptive message explaining the reason for violation
     * @param string|null $code Error identifier code (e.g. regulatory article or business rule code)
     * @param string|null $ruleName Name of the specification class or identifier that failed
     * @param string|null $property Target property name of the evaluated candidate object
     * @param array<string, mixed> $metadata Contextual metadata (actual values received, thresholds, etc.)
     */
    public function __construct(
        public string $message,
        public ?string $code = null,
        public ?string $ruleName = null,
        public ?string $property = null,
        public array $metadata = []
    ) {
    }

    /**
     * Return a new instance with the specified target property name attached.
     *
     * @param string $property Target property name of the evaluated object
     * @return self
     */
    public function withProperty(string $property): self
    {
        return new self(
            message: $this->message,
            code: $this->code,
            ruleName: $this->ruleName,
            property: $property,
            metadata: $this->metadata
        );
    }

    /**
     * Return a human-readable string representation of the failure.
     *
     * @return string
     */
    public function __toString(): string
    {
        $prefix = $this->code !== null ? "[{$this->code}] " : "";
        $prop = $this->property !== null ? " (propriedade '{$this->property}')" : "";
        return "{$prefix}{$this->message}{$prop}";
    }
}
