<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Results;

use Countable;
use Stringable;

/**
 * SpecificationResult - Rich Result Object and Notification Container for Specification Evaluations
 *
 * Implements Martin Fowler's Notification Pattern and the Result Object Pattern to encapsulate
 * the comprehensive verdict of specification or composite rule evaluations.
 * Provides access to the boolean satisfaction status alongside the full collection of recorded failures.
 *
 * Features:
 * - Boolean satisfaction indicator (isSatisfied)
 * - Immutable aggregation of SpecificationFailure instances
 * - Expressive static factory methods (satisfied, failure, combine)
 * - Extraction of friendly error messages (getReasons) and regulatory error codes (getCodes)
 * - Fast query by error code (hasError) or by target candidate property (getFailuresForProperty)
 * - Idiomatic integration via Countable and Stringable interfaces
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Results
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SpecificationResult implements Countable, Stringable
{
    /**
     * @param bool $isSatisfied True if all specification conditions are satisfied; false if violations occurred
     * @param list<SpecificationFailure> $failures Collection of recorded rule failures
     */
    public function __construct(
        public bool $isSatisfied,
        public array $failures = []
    ) {
    }

    /**
     * Create a successful evaluation result containing no failures.
     *
     * @return self
     */
    public static function satisfied(): self
    {
        return new self(true, []);
    }

    /**
     * Create an unsatisfied evaluation result containing a single discrete failure.
     *
     * @param string $message Descriptive failure explanation
     * @param string|null $code Regulatory or business error identifier code
     * @param string|null $ruleName Name or identifier of the failing specification
     * @param string|null $property Target property name evaluated
     * @param array<string, mixed> $metadata Additional contextual diagnostics metadata
     * @return self
     */
    public static function failure(
        string $message,
        ?string $code = null,
        ?string $ruleName = null,
        ?string $property = null,
        array $metadata = []
    ): self {
        return new self(false, [
            new SpecificationFailure($message, $code, $ruleName, $property, $metadata)
        ]);
    }

    /**
     * Combine multiple evaluation results into a single consolidated result.
     * The combined result is satisfied if and only if ALL individual results are satisfied.
     *
     * @param self ...$results Results to combine
     * @return self
     */
    public static function combine(self ...$results): self
    {
        $isSatisfied = true;
        $failures = [];

        foreach ($results as $result) {
            if (!$result->isSatisfied) {
                $isSatisfied = false;
            }
            foreach ($result->failures as $failure) {
                $failures[] = $failure;
            }
        }

        return new self($isSatisfied, $failures);
    }

    /**
     * Return a list containing human-readable error messages for all recorded failures.
     *
     * @return list<string>
     */
    public function getReasons(): array
    {
        return array_map(
            static fn(SpecificationFailure $failure): string => $failure->message,
            $this->failures
        );
    }

    /**
     * Return a list containing all distinct non-empty error codes from recorded failures.
     *
     * @return list<string>
     */
    public function getCodes(): array
    {
        $codes = [];
        foreach ($this->failures as $failure) {
            if ($failure->code !== null && $failure->code !== '') {
                $codes[] = $failure->code;
            }
        }
        return $codes;
    }

    /**
     * Determine whether the result contains failures, optionally filtering by specific error code.
     *
     * @param string|null $code Error code to query (null to check for any failure)
     * @return bool
     */
    public function hasError(?string $code = null): bool
    {
        if ($this->isSatisfied) {
            return false;
        }

        if ($code === null) {
            return !empty($this->failures);
        }

        foreach ($this->failures as $failure) {
            if ($failure->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return failures specifically associated with a candidate property name.
     *
     * @param string $property Property name to filter by
     * @return list<SpecificationFailure>
     */
    public function getFailuresForProperty(string $property): array
    {
        return array_values(
            array_filter(
                $this->failures,
                static fn(SpecificationFailure $f): bool => $f->property === $property
            )
        );
    }

    /**
     * Return the total count of failures recorded in this result.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->failures);
    }

    /**
     * Return a formatted string representation of the evaluation result.
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->isSatisfied) {
            return "Satisfied";
        }

        return implode("; ", $this->getReasons());
    }
}
