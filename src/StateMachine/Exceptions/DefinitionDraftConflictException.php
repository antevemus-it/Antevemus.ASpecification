<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * DefinitionDraftConflictException - A Draft or a Publication Lost a Race (2.7.0)
 *
 * Thrown by IMachineDefinitionDrafts when the revision of the draft moved since the screen read it
 * (another save, a discard or a publication), when a draft was expected to be absent and is there,
 * or when another publication took the version first. It is a ConcurrencyConflictException, so code
 * that already handles conflicts of instances handles it too; getInstanceId() answers the machine key
 * and the versions are the draft revisions (0 = no draft).
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DefinitionDraftConflictException extends ConcurrencyConflictException
{
    /**
     * @param string $machineKey Machine whose draft or publication conflicted
     * @param int|null $expectedRevision Revision the writer expected (null = no draft)
     * @param int|null $actualRevision Revision found (null = no draft)
     * @param string|null $message Optional message (a default one is built otherwise)
     */
    public function __construct(
        private readonly string $machineKey,
        private readonly ?int $expectedRevision,
        private readonly ?int $actualRevision,
        ?string $message = null
    ) {
        parent::__construct($machineKey, $expectedRevision ?? 0, $actualRevision ?? 0, $message ?? sprintf(
            'Concurrency conflict on the draft of machine "%s": expected %s, found %s. Reload the draft and retry.',
            $machineKey,
            $expectedRevision === null ? 'no draft' : 'revision ' . $expectedRevision,
            $actualRevision === null ? 'no draft' : 'revision ' . $actualRevision
        ));
    }

    /** Machine whose draft or publication conflicted. */
    public function getMachineKey(): string
    {
        return $this->machineKey;
    }

    /** Revision the writer expected (null = no draft). */
    public function getExpectedRevision(): ?int
    {
        return $this->expectedRevision;
    }

    /** Revision found (null = no draft). */
    public function getActualRevision(): ?int
    {
        return $this->actualRevision;
    }
}
