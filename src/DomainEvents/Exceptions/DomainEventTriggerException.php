<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Exceptions;

use Antevemus\ASpecification\DomainEvents\TriggerEvaluationError;

/**
 * DomainEventTriggerException - A Trigger Could Not Be Evaluated (Strict Mode)
 *
 * Thrown by TriggeringRepository only when it was built with `failOnEvaluationError: true`
 * (forward 029, D3). By default an evaluation error lets the write go on and is handed to the
 * IDomainEventErrorReporter instead. The message names the trigger and the entity, never the
 * message of the original exception, which stays reachable through getPrevious() and getErrors()
 * for the log (the 1.8.0 rule: an exception never reaches a failure message).
 *
 * The write already reached the decorated repository when this is thrown: it is undone only when
 * the decorator runs inside a transaction (its ITransactionManager, or one opened by the caller).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DomainEventTriggerException extends DomainEventException
{
    /** @var list<TriggerEvaluationError> */
    private readonly array $errors;

    /**
     * @param list<TriggerEvaluationError> $errors Evaluation errors of the write (at least one)
     */
    public function __construct(array $errors)
    {
        $this->errors = array_values($errors);
        $first = $this->errors[0] ?? null;
        parent::__construct(
            $first === null
                ? 'A domain event trigger could not be evaluated.'
                : sprintf('%s The write was refused (failOnEvaluationError).', $first->getMessage()),
            0,
            $first?->exception
        );
    }

    /**
     * Every evaluation error of the write, in trigger order.
     *
     * @return list<TriggerEvaluationError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
