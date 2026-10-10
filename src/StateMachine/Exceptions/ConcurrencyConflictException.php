<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * ConcurrencyConflictException - Optimistic Lock Lost
 *
 * Thrown when the compare-and-set of an instance fails because the version stored differs from
 * the version read (forward 023, D13, RN-08), or when the caller's expected version is stale.
 * Nothing was written: the whole transaction was rolled back. The runner never retries by
 * itself; the caller reloads and decides (resend, or show the new state to the user).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class ConcurrencyConflictException extends StateMachineException
{
    /**
     * @param string $instanceId Instance whose version moved
     * @param int $expectedVersion Version the writer expected to find
     * @param int|null $actualVersion Version found, when known
     * @param string|null $message Optional message (a default one is built otherwise)
     */
    public function __construct(
        private readonly string $instanceId,
        private readonly int $expectedVersion,
        private readonly ?int $actualVersion = null,
        ?string $message = null
    ) {
        parent::__construct($message ?? sprintf(
            'Concurrency conflict on machine instance "%s": expected version %d, found %s. Reload and retry.',
            $instanceId,
            $expectedVersion,
            $actualVersion === null ? 'another version' : (string) $actualVersion
        ));
    }

    /** Instance whose version moved. */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /** Version the writer expected to find. */
    public function getExpectedVersion(): int
    {
        return $this->expectedVersion;
    }

    /** Version found, when the store knows it. */
    public function getActualVersion(): ?int
    {
        return $this->actualVersion;
    }
}
