<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use InvalidArgumentException;

/**
 * PayloadHasSpecification - Built-in Guard `payloadHas('field')`
 *
 * Satisfied when the event payload provides a non-blank value at the key or dot path: present,
 * not null, not an empty (or whitespace-only) string and not an empty array. "Requires a motive"
 * and "requires an observation" are guards, not columns (forward 023, D15): `payloadHas('motive')`.
 *
 * @extends AbstractSpecification<TransitionCandidate>
 * @implements ILeafSpecification<TransitionCandidate>
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PayloadHasSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * @param string $field Payload key or dot path
     * @throws InvalidArgumentException When the field is empty
     */
    public function __construct(private readonly string $field)
    {
        if (trim($field) === '') {
            throw new InvalidArgumentException('payloadHas expects a non-empty field name.');
        }
    }

    /** Payload key or dot path. */
    public function getField(): string
    {
        return $this->field;
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return TransitionCandidate::class;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException When the candidate is not a TransitionCandidate
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!$candidate instanceof TransitionCandidate) {
            throw new InvalidArgumentException('payloadHas guards evaluate a TransitionCandidate.');
        }
        $value = $candidate->event->payloadValue($this->field);

        return !($value === null || (is_string($value) && trim($value) === '') || $value === []);
    }

    /** {@inheritdoc} */
    protected function getDefaultFailureMessage(mixed $candidate): string
    {
        return sprintf("The event payload must provide '%s'.", $this->field);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf("payloadHas('%s')", $this->field);
    }
}
