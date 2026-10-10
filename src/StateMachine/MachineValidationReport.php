<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * MachineValidationReport - Errors and Warnings of a Machine Definition Analysis
 *
 * Returned by MachineDefinitionValidator::analyze() (forward 023, D17). validate() turns a report
 * with errors into an InvalidMachineDefinitionException and returns the warnings otherwise.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineValidationReport
{
    /**
     * @param list<MachineDefinitionError> $errors Blocking problems
     * @param list<MachineDefinitionWarning> $warnings Non-blocking notices
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings
    ) {
    }

    /** True when the definition has no error. */
    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * Error kinds, in the order found.
     *
     * @return list<string>
     */
    public function errorKinds(): array
    {
        return array_map(static fn(MachineDefinitionError $e): string => $e->kind, $this->errors);
    }

    /**
     * Warning kinds, in the order found.
     *
     * @return list<string>
     */
    public function warningKinds(): array
    {
        return array_map(static fn(MachineDefinitionWarning $w): string => $w->kind, $this->warnings);
    }

    /**
     * Warnings of the given kind.
     *
     * @param string $kind One of the MachineDefinitionWarning::KIND_* constants
     * @return list<MachineDefinitionWarning>
     */
    public function warningsOfKind(string $kind): array
    {
        return array_values(array_filter($this->warnings, static fn(MachineDefinitionWarning $w): bool => $w->kind === $kind));
    }

    /**
     * Errors of the given kind.
     *
     * @param string $kind One of the MachineDefinitionError::KIND_* constants
     * @return list<MachineDefinitionError>
     */
    public function errorsOfKind(string $kind): array
    {
        return array_values(array_filter($this->errors, static fn(MachineDefinitionError $e): bool => $e->kind === $kind));
    }
}
