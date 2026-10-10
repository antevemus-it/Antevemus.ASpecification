<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\IIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Persistence\TransitionResultCodec;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use DateTimeImmutable;

/**
 * PdoIdempotencyStore - Processed Idempotency Keys over PDO
 *
 * Table `state_machine_idempotency` (forward 023, RN-09): one row per keyed accepted event,
 * UNIQUE (instance_id, idempotency_key); the original result is stored as JSON and rebuilt on
 * find(). A unique violation on record() means the key was recorded meanwhile: reported as a
 * concurrency conflict, so the caller retries and gets the original result.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoIdempotencyStore implements IIdempotencyStore
{
    public function __construct(private readonly PdoMachineSchema $schema)
    {
    }

    /** {@inheritdoc} */
    public function find(string $instanceId, string $idempotencyKey): ?TransitionResult
    {
        $rows = $this->schema->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :instance_id AND %s = :idempotency_key',
            $this->schema->selectList(['transition_result']),
            $this->schema->table('idempotency'),
            $this->schema->column('instance_id'),
            $this->schema->column('idempotency_key')
        ), [':instance_id' => $instanceId, ':idempotency_key' => $idempotencyKey]);

        return $rows === []
            ? null
            : TransitionResultCodec::decode(PersistenceSupport::decodeJson((string) $rows[0]['transition_result'], 'transition result'));
    }

    /** {@inheritdoc} */
    public function record(
        string $instanceId,
        string $idempotencyKey,
        int $instanceVersion,
        TransitionResult $result,
        DateTimeImmutable $recordedAt
    ): void {
        try {
            $this->schema->insert('idempotency', [
                'instance_id' => $instanceId,
                'instance_version' => $instanceVersion,
                'idempotency_key' => $idempotencyKey,
                'transition_result' => PersistenceSupport::encodeJson(TransitionResultCodec::encode($result)),
                'recorded_at' => PersistenceSupport::toText($recordedAt),
            ]);
        } catch (MachinePersistenceException $e) {
            if ($e->isIntegrityViolation()) {
                throw new ConcurrencyConflictException($instanceId, $instanceVersion - 1, null, sprintf(
                    'Idempotency key "%s" of machine instance "%s" was recorded meanwhile.',
                    $idempotencyKey,
                    $instanceId
                ));
            }
            throw $e;
        }
    }
}
