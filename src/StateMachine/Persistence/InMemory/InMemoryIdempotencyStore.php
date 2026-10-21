<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Persistence\IBatchIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Persistence\TransitionResultCodec;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use DateTimeImmutable;

/**
 * InMemoryIdempotencyStore - Processed Idempotency Keys in Memory
 *
 * Same contract as PdoIdempotencyStore (forward 023, RN-09): the result is kept in its stored
 * (JSON) form and rebuilt on find(), exactly as the PDO store does, so both give back equal
 * results. Since 2.10.0 it also answers many keys at once (IBatchIdempotencyStore).
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryIdempotencyStore implements IBatchIdempotencyStore, IInMemoryTransactional
{
    /** @var array<string, array<string, array{version: int, result: string}>> */
    private array $records = [];

    /** {@inheritdoc} */
    public function find(string $instanceId, string $idempotencyKey): ?TransitionResult
    {
        $record = $this->records[$instanceId][$idempotencyKey] ?? null;

        return $record === null
            ? null
            : TransitionResultCodec::decode(PersistenceSupport::decodeJson($record['result'], 'transition result'));
    }

    /** {@inheritdoc} */
    public function findMany(array $keysByInstance): array
    {
        $found = [];
        foreach ($keysByInstance as $instanceId => $keys) {
            foreach ($keys as $key) {
                $result = $this->find((string) $instanceId, $key);
                if ($result !== null) {
                    $found[$instanceId][$key] = $result;
                }
            }
        }

        return $found;
    }

    /** {@inheritdoc} */
    public function record(
        string $instanceId,
        string $idempotencyKey,
        int $instanceVersion,
        TransitionResult $result,
        DateTimeImmutable $recordedAt
    ): void {
        if (isset($this->records[$instanceId][$idempotencyKey])) {
            throw new ConcurrencyConflictException($instanceId, $instanceVersion - 1, null, sprintf(
                'Idempotency key "%s" of machine instance "%s" was recorded meanwhile.',
                $idempotencyKey,
                $instanceId
            ));
        }
        $this->records[$instanceId][$idempotencyKey] = [
            'version' => $instanceVersion,
            'result' => PersistenceSupport::encodeJson(TransitionResultCodec::encode($result)),
        ];
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return $this->records;
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->records = $state;
    }
}
