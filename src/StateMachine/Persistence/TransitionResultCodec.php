<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\TakenTransition;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Antevemus\ASpecification\StateMachine\TransitionStatus;
use InvalidArgumentException;
use Throwable;

/**
 * TransitionResultCodec - Stored Form of an Accepted TransitionResult
 *
 * The idempotency record must give back the ORIGINAL result of an event (forward 023, RN-09):
 * snapshot after the event, transitions taken and effects. Only accepted results are recorded
 * (a rejected or unhandled event writes nothing, so a retry with the same key is evaluated
 * again), and an accepted result carries no failure, so its stored form is the event, the
 * snapshot, the transitions and the effects in their lossless array forms.
 *
 * @internal
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransitionResultCodec
{
    private function __construct()
    {
    }

    /**
     * Array form of an accepted result.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the result is not accepted
     */
    public static function encode(TransitionResult $result): array
    {
        if (!$result->isAccepted()) {
            throw new InvalidArgumentException('Only accepted transition results are recorded for idempotency.');
        }

        return [
            'status' => $result->status->value,
            'event' => $result->event?->toArray(),
            'snapshot' => $result->snapshot->toArray(),
            'transitions' => array_map(static fn(TakenTransition $t): array => $t->toArray(), $result->getTransitions()),
            'effects' => array_map(static fn(Effect $e): array => $e->toArray(), $result->getEffects()),
        ];
    }

    /**
     * Rebuilds an accepted result from its array form.
     *
     * @param array<mixed> $data
     * @return TransitionResult
     * @throws \Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException When malformed
     */
    public static function decode(array $data): TransitionResult
    {
        try {
            if (($data['status'] ?? null) !== TransitionStatus::ACCEPTED->value
                || !is_array($data['snapshot'] ?? null)
                || !is_array($data['transitions'] ?? null)
                || !is_array($data['effects'] ?? null)) {
                throw new InvalidArgumentException('missing status, snapshot, transitions or effects');
            }
            $event = is_array($data['event'] ?? null) ? Event::fromArray($data['event']) : null;

            return new TransitionResult(
                TransitionStatus::ACCEPTED,
                $event,
                MachineSnapshot::fromArray($data['snapshot']),
                array_map(static fn(mixed $t): TakenTransition => TakenTransition::fromArray((array) $t), $data['transitions']),
                array_map(static fn(mixed $e): Effect => Effect::fromArray((array) $e), $data['effects'])
            );
        } catch (Throwable $e) {
            throw new \Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException(
                'Stored transition result is malformed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
