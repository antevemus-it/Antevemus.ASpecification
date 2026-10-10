<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

use Antevemus\ASpecification\StateMachine\MachineDefinitionError;
use Antevemus\ASpecification\StateMachine\MachineDefinitionWarning;

/**
 * InvalidMachineDefinitionException - Exception Carrying Every Error of a Machine Definition
 *
 * Thrown by the definition model when a structural invariant is broken (duplicate names or
 * transition ids, malformed array, non JSON-friendly parameter) and by
 * MachineDefinitionValidator::validate() when the definition is refused (forward 023, D17).
 * The exception lists ALL the errors found, never only the first one, and also carries the
 * warnings of the same analysis so tooling can show them together.
 *
 * Features:
 * - Complete list of MachineDefinitionError objects (kind, message, region, state, transition)
 * - Warnings of the same analysis (non-blocking)
 * - Multi-line human-readable message
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InvalidMachineDefinitionException extends StateMachineException
{
    /** @var list<MachineDefinitionError> */
    private array $errors;

    /** @var list<MachineDefinitionWarning> */
    private array $warnings;

    /**
     * @param list<MachineDefinitionError> $errors Every error found (at least one)
     * @param list<MachineDefinitionWarning> $warnings Warnings of the same analysis
     * @param string|null $subject Optional subject (e.g. "machine 'quote' v1") prefixed to the message
     */
    public function __construct(array $errors, array $warnings = [], ?string $subject = null)
    {
        $this->errors = array_values($errors);
        $this->warnings = array_values($warnings);

        $lines = array_map(static fn(MachineDefinitionError $e): string => ' - ' . $e->message, $this->errors);
        parent::__construct(sprintf(
            'Invalid state machine definition%s: %d error(s):%s%s',
            $subject !== null ? " ({$subject})" : '',
            count($this->errors),
            PHP_EOL,
            implode(PHP_EOL, $lines)
        ));
    }

    /**
     * Shortcut for a single structural error raised by the definition model.
     *
     * @param string $kind One of the MachineDefinitionError::KIND_* constants
     * @param string $message Human-readable explanation
     * @param string|null $region Region involved, when any
     * @param string|null $state State involved, when any
     * @param string|null $transitionId Transition involved, when any
     * @return self
     */
    public static function single(
        string $kind,
        string $message,
        ?string $region = null,
        ?string $state = null,
        ?string $transitionId = null
    ): self {
        return new self([new MachineDefinitionError($kind, $message, $region, $state, $transitionId)]);
    }

    /**
     * Returns every error found.
     *
     * @return list<MachineDefinitionError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns the warnings of the same analysis (empty for structural errors of the model).
     *
     * @return list<MachineDefinitionWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Returns the error kinds, in the order the errors were found.
     *
     * @return list<string>
     */
    public function getErrorKinds(): array
    {
        return array_map(static fn(MachineDefinitionError $e): string => $e->kind, $this->errors);
    }
}
