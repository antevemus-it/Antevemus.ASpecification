<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes\Exceptions;

use Antevemus\ASpecification\Results\SpecificationResult;
use RuntimeException;

/**
 * AttributeValidationException - Thrown when declarative attribute validation assertions fail
 *
 * Encapsulates the complete SpecificationResult containing all violated rules,
 * field paths, error codes, and descriptive failure messages.
 *
 * Features:
 * - Direct access to the underlying SpecificationResult
 * - Formatted message summarizing all validation failures
 * - Seamless integration with HTTP 422 Unprocessable Entity handlers
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Attributes\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AttributeValidationException extends RuntimeException
{
    /**
     * @param SpecificationResult $result The failed evaluation result
     * @param string|null $message Optional override message
     * @param \Throwable|null $previous The exception that aborted an evaluation, when the result is an
     *                                  error (defaults to the result's own exception); null otherwise
     */
    public function __construct(
        public readonly SpecificationResult $result,
        ?string $message = null,
        ?\Throwable $previous = null
    ) {
        $reasons = implode('; ', $result->getReasons());
        parent::__construct(
            $message ?? "Declarative attribute validation failed: {$reasons}",
            0,
            $previous ?? $result->exception
        );
    }

    /**
     * Get the evaluation result object.
     *
     * @return SpecificationResult
     */
    public function getResult(): SpecificationResult
    {
        return $this->result;
    }
}
