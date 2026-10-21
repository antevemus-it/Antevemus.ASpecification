<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\IBatchIdempotencyStore;
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
 * concurrency conflict, so the caller retries and gets the original result. Since 2.10.0 findMany()
 * answers the keys of a whole chunk in one `IN (...)` query (IBatchIdempotencyStore).
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoIdempotencyStore implements IBatchIdempotencyStore
{
    /** Pairs (instance, key) per query of findMany() (2.10.0): at most twice as many parameters, far below any driver limit. */
    public const BATCH_PARAMETERS = 200;

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

    /**
     * {@inheritdoc}
     *
     * One query per group of at most BATCH_PARAMETERS pairs, one term per instance:
     * `(instance_id = :i0 AND idempotency_key IN (:k0, :k1, ...)) OR (instance_id = :i1 AND ...)`, each term a
     * lookup on the UNIQUE (instance_id, idempotency_key) index. (Two independent IN lists, `instance_id IN (...)
     * AND idempotency_key IN (...)`, were measured slower than one find() per key on SQLite: the planner probes
     * every combination of the two lists.)
     */
    public function findMany(array $keysByInstance): array
    {
        $pairs = [];
        foreach ($keysByInstance as $instanceId => $keys) {
            foreach ($keys as $key) {
                $pairs[] = [$instanceId, $key];
            }
        }
        $instanceColumn = $this->schema->column('instance_id');
        $keyColumn = $this->schema->column('idempotency_key');
        $found = [];
        foreach (array_chunk($pairs, self::BATCH_PARAMETERS) as $group) {
            $byInstance = [];
            foreach ($group as [$instanceId, $key]) {
                $byInstance[$instanceId][] = $key;
            }
            $params = [];
            $terms = [];
            $k = 0;
            foreach (array_keys($byInstance) as $i => $instanceId) {
                $params[':i' . $i] = (string) $instanceId;
                $marks = [];
                foreach ($byInstance[$instanceId] as $key) {
                    $marks[] = ':k' . $k;
                    $params[':k' . $k++] = $key;
                }
                $terms[] = sprintf('(%s = :i%d AND %s IN (%s))', $instanceColumn, $i, $keyColumn, implode(', ', $marks));
            }
            $rows = $this->schema->fetchAll(sprintf(
                'SELECT %s FROM %s WHERE %s',
                $this->schema->selectList(['instance_id', 'idempotency_key', 'transition_result']),
                $this->schema->table('idempotency'),
                implode(' OR ', $terms)
            ), $params);
            foreach ($rows as $row) {
                $found[$row['instance_id']][$row['idempotency_key']] = TransitionResultCodec::decode(
                    PersistenceSupport::decodeJson((string) $row['transition_result'], 'transition result')
                );
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
