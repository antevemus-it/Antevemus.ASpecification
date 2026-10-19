<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\DomainEvents\DomainEventKind;
use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException;
use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventTriggerException;
use Antevemus\ASpecification\DomainEvents\IDomainEventErrorReporter;
use Antevemus\ASpecification\DomainEvents\IDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\InMemoryDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\MachineEventBridge;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxEntry;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxStatus;
use Antevemus\ASpecification\DomainEvents\Outbox\IDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\Outbox\InMemoryDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\OutboxDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\SpecificationTrigger;
use Antevemus\ASpecification\DomainEvents\TriggerEvaluationError;
use Antevemus\ASpecification\DomainEvents\TriggerEvaluator;
use Antevemus\ASpecification\DomainEvents\TriggeringRepository;
use Antevemus\ASpecification\DomainEvents\TriggerSet;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoTransactionManager;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\Persistence\PdoTransactionManager as SharedPdoTransactionManager;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\ReadmePolicy;
use Antevemus\ASpecification\Tests\Support\ReadmeProduct;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Util\UlidGenerator;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * DomainEvents23Policy - Insurance policy used by the domain event tests: two trigger-relevant
 * fields and two personal fields that must never reach an event.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DomainEvents23Policy implements IEntity
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        public int|string|null $id,
        public int $daysOverdue = 0,
        public bool $documentsValidated = false,
        public string $holderName = 'Maria Aparecida da Silva',
        public string $taxId = '123.456.789-09'
    ) {
        $this->createdAt = new DateTimeImmutable('2026-01-01 00:00:00');
    }

    public function getEntityId(): mixed
    {
        return $this->id;
    }

    public function getTimeOfCreation(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function equals(IEntity $other): bool
    {
        return $other instanceof self && $this->id !== null && (string) $other->id === (string) $this->id;
    }
}

/**
 * DomainEvents23RecordingReporter - Error reporter that keeps what it receives.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DomainEvents23RecordingReporter implements IDomainEventErrorReporter
{
    /** @var list<TriggerEvaluationError> */
    public array $errors = [];

    public function report(TriggerEvaluationError $error): void
    {
        $this->errors[] = $error;
    }
}

/**
 * Module23_DomainEventTriggersTest - Eventos de Domínio Disparados por Especificação (1.11.0)
 *
 * Forward 029 (marco 19): `SpecificationTrigger`/`TriggerSet` (D1), `TriggerEvaluator` com a tabela
 * de casos (RN-02, D2), erro de avaliação devolvido junto e reportado (D3), `DomainEvent` com
 * payload mínimo e chave determinística (D4), publicadores, outbox InMemory e PDO sobre o DDL
 * `resources/sql/domain_events.ansi.sql` e `DomainEventDispatcher` (D5), o decorador
 * `TriggeringRepository` sobre `InMemoryRepository` com contagem exata e idempotência na outbox PDO
 * (RN-03, D6) e a ponte `MachineEventBridge` movendo uma instância real sem repetir na reentrega
 * (RN-04, D7). README 18 (1.11.0): os três blocos do exemplo transcritos e iguais em EN e pt-BR fora
 * os comentários.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module23_DomainEventTriggersTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/domain_events.ansi.sql';

    public function run(): void
    {
        $this->testTriggerDefinitions();
        $this->testEvaluatorCaseTable();
        $this->testEvaluatorErrorsAndInstant();
        $this->testDomainEventPayloadAndKey();
        $this->testDecoratorOverInMemoryRepository();
        $this->testDecoratorRemovalsAndDelegation();
        $this->testDecoratorErrorPath();
        $this->testInMemoryTransactionRollsEventsBack();

        foreach ($this->outboxes() as $label => $factory) {
            $this->testOutboxFifoFailuresAndAttempts($label, $factory);
        }
        $this->testMachineBridge();

        if (extension_loaded('pdo_sqlite')) {
            $this->testReferenceDdl();
            $this->testDecoratorWithPdoOutboxExactCounts();
            $this->testPdoTransactionAtomicity();
            $this->testOutboxParityInMemoryVsPdo();
        $this->testDecoratorOverPdoRepositoryUsesSqlIdentity();
        }

        // README 18 (1.11.0)
        $this->testReadme18BlocksAreTheSameInBothLanguages();
        $this->testReadme18RunsAsWritten();
    }

    // ==========================================
    // Helpers
    // ==========================================

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /** @return Closure(): DateTimeImmutable */
    private function fixedClock(string $time): Closure
    {
        $instant = $this->at($time);

        return static fn(): DateTimeImmutable => $instant;
    }

    /** Clock advancing one minute per read, starting at $time. */
    private function tickingClock(string $time): Closure
    {
        $instant = $this->at($time);

        return static function () use (&$instant): DateTimeImmutable {
            $now = $instant;
            $instant = $instant->modify('+1 minute');

            return $now;
        };
    }

    private function delinquent(): SpecificationTrigger
    {
        return new SpecificationTrigger(
            'policy.delinquent',
            Spec::property('daysOverdue', Spec::greaterThan(30)),
            onEnter: 'delinquency.started',
            onExit: 'delinquency.ended'
        );
    }

    private function documents(): SpecificationTrigger
    {
        return new SpecificationTrigger(
            'policy.documents',
            Spec::property('documentsValidated', Spec::isTrue()),
            onEnter: 'documents.validated'
        );
    }

    private function triggers(): TriggerSet
    {
        return new TriggerSet($this->delinquent(), $this->documents());
    }

    private function exploding(string $name = 'policy.exploding'): SpecificationTrigger
    {
        return new SpecificationTrigger(
            $name,
            new PredicateSpecification(static function (mixed $candidate): bool {
                throw new RuntimeException('secret detail of ' . $candidate->taxId);
            }),
            onEnter: 'never.emitted'
        );
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));

        return $pdo;
    }

    /**
     * Runs a DDL script: drops `--` comment lines and executes each `;`-terminated statement.
     */
    private static function loadDdl(PDO $pdo, string $ddl): void
    {
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    /**
     * Outbox backends: label => factory.
     *
     * @return array<string, Closure(): IDomainEventOutbox>
     */
    private function outboxes(): array
    {
        $backends = ['InMemory' => static fn(): IDomainEventOutbox => new InMemoryDomainEventOutbox()];
        if (extension_loaded('pdo_sqlite')) {
            $backends['PDO'] = fn(): IDomainEventOutbox => new PdoDomainEventOutbox($this->sqlite());
        }

        return $backends;
    }

    private function event(string $name, string $entityId, DomainEventKind $kind = DomainEventKind::ENTER, string $at = '2026-10-11 09:00:00'): DomainEvent
    {
        return new DomainEvent($name, 'policy.trigger', $kind, DomainEvents23Policy::class, $entityId, $this->at($at));
    }

    // ==========================================
    // D1: triggers and sets
    // ==========================================

    private function testTriggerDefinitions(): void
    {
        $trigger = $this->delinquent();
        $this->assertEquals('policy.delinquent', $trigger->getName());
        $this->assertEquals('delinquency.started', $trigger->eventFor(DomainEventKind::ENTER));
        $this->assertEquals('delinquency.ended', $trigger->eventFor(DomainEventKind::EXIT));
        $this->assertEquals(null, $this->documents()->eventFor(DomainEventKind::EXIT));

        $this->assertThrows(InvalidArgumentException::class, fn() => new SpecificationTrigger('no.events', Spec::alwaysTrue()));
        $this->assertThrows(InvalidArgumentException::class, fn() => new SpecificationTrigger('', Spec::alwaysTrue(), 'a'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new SpecificationTrigger(' padded', Spec::alwaysTrue(), 'a'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new SpecificationTrigger('t', Spec::alwaysTrue(), str_repeat('e', 121)));
        $this->assertThrows(InvalidArgumentException::class, fn() => new SpecificationTrigger('t', Spec::alwaysTrue(), null, ''));

        $set = $this->triggers();
        $this->assertCount(2, $set);
        $this->assertEquals(['policy.delinquent', 'policy.documents'], $set->names());
        $this->assertEquals(['delinquency.started', 'delinquency.ended', 'documents.validated'], $set->eventNames());
        $this->assertTrue($set->has('policy.documents'));
        $this->assertEquals(null, $set->get('missing'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new TriggerSet($this->delinquent(), $this->delinquent()));
        $this->assertThrows(InvalidArgumentException::class, fn() => $set->with($this->documents()));
        $bigger = $set->with($this->exploding());
        $this->assertCount(3, $bigger);
        $this->assertCount(2, $set, 'with() returns a new set');
        $this->assertEquals(['policy.delinquent', 'policy.documents'], array_keys(iterator_to_array($set)));
    }

    // ==========================================
    // RN-02: the evaluator case table
    // ==========================================

    private function testEvaluatorCaseTable(): void
    {
        $evaluator = new TriggerEvaluator();
        $set = $this->triggers();
        $now = $this->at('2026-10-11 09:00:00');
        $late = new DomainEvents23Policy(7, 45);
        $onTime = new DomainEvents23Policy(7, 0);

        // Creation (before null) that already satisfies: enter
        $changes = $evaluator->changes($set, null, $late, $now);
        $this->assertEquals(['delinquency.started'], $changes->eventNames());
        $this->assertFalse($changes->hasErrors());
        $event = $changes->getEvents()[0];
        $this->assertEquals(DomainEventKind::ENTER, $event->kind);
        $this->assertEquals('policy.delinquent', $event->triggerName);
        $this->assertEquals(DomainEvents23Policy::class, $event->entityClass);
        $this->assertEquals('7', $event->entityId);

        // Creation that does not satisfy: nothing
        $this->assertCount(0, $evaluator->changes($set, null, $onTime, $now));
        $this->assertTrue($evaluator->changes($set, null, $onTime, $now)->isEmpty());

        // Removal (after null) of an entity that satisfied: exit
        $this->assertEquals(['delinquency.ended'], $evaluator->changes($set, $late, null, $now)->eventNames());
        // Removal of an entity that did not satisfy: nothing
        $this->assertCount(0, $evaluator->changes($set, $onTime, null, $now));

        // Enter (false -> true) and exit (true -> false)
        $this->assertEquals(['delinquency.started'], $evaluator->changes($set, $onTime, $late, $now)->eventNames());
        $exit = $evaluator->changes($set, $late, $onTime, $now);
        $this->assertEquals(['delinquency.ended'], $exit->eventNames());
        $this->assertTrue($exit->getEvents()[0]->isExit());

        // No change (true -> true, false -> false): nothing
        $this->assertCount(0, $evaluator->changes($set, $late, new DomainEvents23Policy(7, 60), $now));
        $this->assertCount(0, $evaluator->changes($set, $onTime, new DomainEvents23Policy(7, 10), $now));

        // A trigger without exit event is silent on the exit crossing
        $validated = new DomainEvents23Policy(7, 0, true);
        $this->assertCount(0, $evaluator->changes($set, $validated, $onTime, $now));

        // Two triggers on the same object, in declaration order
        $both = $evaluator->changes($set, $onTime, new DomainEvents23Policy(7, 45, true), $now);
        $this->assertEquals(['delinquency.started', 'documents.validated'], $both->eventNames());
        $this->assertCount(2, $both);
        $this->assertEquals(['delinquency.started', 'documents.validated'], array_map(static fn(DomainEvent $e): string => $e->name, iterator_to_array($both)));
        // One trigger enters while the other exits
        $mixed = $evaluator->changes(new TriggerSet(
            $this->delinquent(),
            new SpecificationTrigger('policy.documents', Spec::property('documentsValidated', Spec::isTrue()), 'documents.validated', 'documents.invalidated')
        ), new DomainEvents23Policy(7, 0, true), new DomainEvents23Policy(7, 45, false), $now);
        $this->assertEquals(['delinquency.started', 'documents.invalidated'], $mixed->eventNames());

        // Both sides absent: nothing at all
        $this->assertTrue($evaluator->changes($set, null, null, $now)->isEmpty());
        // The same object on both sides: no change by construction
        $this->assertCount(0, $evaluator->changes($set, $late, $late, $now));
        // Before and after must be the same entity
        $this->assertThrows(InvalidArgumentException::class, fn() => $evaluator->changes($set, new DomainEvents23Policy(1), new DomainEvents23Policy(2, 45), $now));
        $this->assertThrows(InvalidArgumentException::class, fn() => $evaluator->changes($set, new \stdClass(), $late, $now));
    }

    private function testEvaluatorErrorsAndInstant(): void
    {
        $evaluator = new TriggerEvaluator();
        $now = $this->at('2026-10-11 09:00:00');
        $set = new TriggerSet($this->delinquent(), $this->exploding(), $this->documents());

        // Error on the after state: that trigger emits nothing, the others still do, the error comes along
        $changes = $evaluator->changes($set, null, new DomainEvents23Policy(9, 45, true), $now);
        $this->assertEquals(['delinquency.started', 'documents.validated'], $changes->eventNames());
        $this->assertTrue($changes->hasErrors());
        $this->assertCount(1, $changes->getErrors());
        $error = $changes->getErrors()[0];
        $this->assertEquals('policy.exploding', $error->triggerName);
        $this->assertEquals(TriggerEvaluationError::SIDE_AFTER, $error->side);
        $this->assertEquals('9', $error->entityId);
        $this->assertInstanceOf(RuntimeException::class, $error->getException());
        // The exception stays for the log; it never reaches the message (1.8.0 rule)
        $this->assertTrue(str_contains($error->getException()->getMessage(), '123.456.789-09'));
        $this->assertFalse(str_contains($error->getMessage(), '123.456.789-09'));
        $this->assertFalse(str_contains($error->getMessage(), 'secret'));
        $this->assertFalse(str_contains((string) json_encode($error->toArray()), 'secret'));
        $this->assertEquals(RuntimeException::class, $error->toArray()['exception_class']);

        // Error on both sides: two errors, no event of that trigger
        $twice = $evaluator->changes($set, new DomainEvents23Policy(9), new DomainEvents23Policy(9), $now);
        $this->assertEquals([TriggerEvaluationError::SIDE_BEFORE, TriggerEvaluationError::SIDE_AFTER], array_map(static fn(TriggerEvaluationError $e): string => $e->side, $twice->getErrors()));
        $this->assertCount(0, $twice);

        // "Now" is injected: occurredAt is the given instant, in UTC
        $local = new DateTimeImmutable('2026-10-11 06:00:00', new DateTimeZone('America/Sao_Paulo'));
        $stamped = $evaluator->changes($this->triggers(), null, new DomainEvents23Policy(1, 45), $local)->getEvents()[0];
        $this->assertEquals('2026-10-11 09:00:00.000000', $stamped->occurredAt->format('Y-m-d H:i:s.u'));
        $this->assertEquals('UTC', $stamped->occurredAt->getTimezone()->getName());
        // No $now and no clock: refused only when an event must be stamped
        $this->assertThrows(InvalidArgumentException::class, fn() => $evaluator->changes($this->triggers(), null, new DomainEvents23Policy(1, 45)));
        $this->assertCount(0, $evaluator->changes($this->triggers(), null, new DomainEvents23Policy(1, 0)));
        // The evaluator's clock is used when $now is null
        $clocked = new TriggerEvaluator($this->fixedClock('2026-12-25 12:00:00'));
        $this->assertEquals('2026-12-25', $clocked->changes($this->triggers(), null, new DomainEvents23Policy(1, 45))->getEvents()[0]->occurredAt->format('Y-m-d'));

        // Identity: a non-IEntity object needs an identity reader; a missing id is an identity error
        $plain = new class {
            public int $daysOverdue = 45;
            public string $code = 'P-1';
        };
        $noReader = $evaluator->changes(new TriggerSet($this->delinquent()), null, $plain, $now);
        $this->assertCount(0, $noReader);
        $this->assertEquals(TriggerEvaluationError::SIDE_IDENTITY, $noReader->getErrors()[0]->side);
        $reader = new TriggerEvaluator(null, static fn(object $o): string => $o->code);
        $this->assertEquals('P-1', $reader->changes(new TriggerSet($this->delinquent()), null, $plain, $now)->getEvents()[0]->entityId);
        $nullId = $evaluator->changes(new TriggerSet($this->delinquent()), null, new DomainEvents23Policy(null, 45), $now);
        $this->assertEquals(TriggerEvaluationError::SIDE_IDENTITY, $nullId->getErrors()[0]->side);
        $this->assertFalse(str_contains($nullId->getErrors()[0]->getMessage(), 'NULL'));
        $this->assertEquals('7', $evaluator->identityOf(new DomainEvents23Policy(7)));
        $this->assertThrows(InvalidArgumentException::class, fn() => $evaluator->identityOf(new DomainEvents23Policy(null)));
        $this->assertEquals(null, TriggerEvaluator::textOf(['array']));
        $this->assertEquals('enter', TriggerEvaluator::textOf(DomainEventKind::ENTER));
    }

    // ==========================================
    // D4: payload and idempotency key
    // ==========================================

    private function testDomainEventPayloadAndKey(): void
    {
        $evaluator = new TriggerEvaluator();
        $now = $this->at('2026-10-11 09:00:00');
        $policy = new DomainEvents23Policy(42, 45, true);
        $events = $evaluator->changes($this->triggers(), null, $policy, $now)->getEvents();
        $event = $events[0];

        // Minimal payload: the trigger and the entity id, never the entity
        $this->assertEquals(['trigger' => 'policy.delinquent', 'entity_id' => '42'], $event->getPayload());
        foreach ($events as $e) {
            $serialized = json_encode($e->toArray()) . serialize($e);
            $this->assertFalse(str_contains($serialized, 'Maria Aparecida'), 'The holder name must not travel with the event');
            $this->assertFalse(str_contains($serialized, '123.456.789-09'), 'The tax id must not travel with the event');
        }
        $this->assertEquals(
            ['name', 'trigger', 'kind', 'entity_class', 'entity_id', 'occurred_at', 'idempotency_key', 'payload'],
            array_keys($event->toArray())
        );

        // Deterministic key: same crossing, same instant => same key; any part changes it
        $again = $evaluator->changes($this->triggers(), null, new DomainEvents23Policy(42, 99, true), $now)->getEvents()[0];
        $this->assertEquals($event->getIdempotencyKey(), $again->getIdempotencyKey());
        $this->assertTrue(str_starts_with($event->getIdempotencyKey(), DomainEvent::KEY_PREFIX));
        $this->assertEquals(67, strlen($event->getIdempotencyKey()));
        $keys = [
            $event->getIdempotencyKey(),
            (new DomainEvent($event->name, $event->triggerName, DomainEventKind::EXIT, $event->entityClass, '42', $now))->getIdempotencyKey(),
            (new DomainEvent($event->name, $event->triggerName, DomainEventKind::ENTER, $event->entityClass, '43', $now))->getIdempotencyKey(),
            (new DomainEvent($event->name, $event->triggerName, DomainEventKind::ENTER, $event->entityClass, '42', $now->modify('+1 second')))->getIdempotencyKey(),
            (new DomainEvent($event->name, 'other.trigger', DomainEventKind::ENTER, $event->entityClass, '42', $now))->getIdempotencyKey(),
            (new DomainEvent($event->name, $event->triggerName, DomainEventKind::ENTER, 'Other\\Entity', '42', $now))->getIdempotencyKey(),
        ];
        $this->assertCount(6, array_unique($keys));
        // The same instant in another time zone is the same key
        $this->assertEquals(
            $event->getIdempotencyKey(),
            (new DomainEvent($event->name, $event->triggerName, DomainEventKind::ENTER, $event->entityClass, '42', new DateTimeImmutable('2026-10-11 06:00:00', new DateTimeZone('America/Sao_Paulo'))))->getIdempotencyKey()
        );
        $this->assertThrows(InvalidArgumentException::class, fn() => new DomainEvent('e', 't', DomainEventKind::ENTER, 'C', str_repeat('9', 191), $now));
        $this->assertThrows(InvalidArgumentException::class, fn() => new DomainEvent('e', 't', DomainEventKind::ENTER, 'C', '', $now));
        $this->assertThrows(InvalidArgumentException::class, fn() => new DomainEvent('e', 't', DomainEventKind::ENTER, '', '1', $now));
    }

    // ==========================================
    // D6: the decorator over InMemoryRepository
    // ==========================================

    private function testDecoratorOverInMemoryRepository(): void
    {
        $publisher = new InMemoryDomainEventPublisher();
        $repository = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), $publisher, clock: $this->tickingClock('2026-10-11 09:00:00'));

        // Creation that does not satisfy: nothing
        $policy = new DomainEvents23Policy(1, 0);
        $repository->put($policy);
        $this->assertEquals([], $publisher->publishedNames());

        // Mutation in place (the in-memory repository stores this very object): the recorded
        // states of the last write are the "before"
        $policy->daysOverdue = 45;
        $repository->update($policy);
        $this->assertEquals(['delinquency.started'], $publisher->publishedNames());

        // The same write again: no change, nothing
        $repository->update($policy);
        $repository->put($policy);
        $this->assertCount(1, $publisher->published());

        // Back to normal: exit
        $policy->daysOverdue = 0;
        $repository->update($policy);
        $this->assertEquals(['delinquency.started', 'delinquency.ended'], $publisher->publishedNames());

        // Creation that already satisfies: enter of both triggers, in declaration order
        $repository->put(new DomainEvents23Policy(2, 31, true));
        $this->assertEquals(['delinquency.started', 'delinquency.ended', 'delinquency.started', 'documents.validated'], $publisher->publishedNames());

        // A different instance with the same id (a hydrated copy): the stored object is the "before"
        $copy = new DomainEvents23Policy(2, 0, true);
        $repository->update($copy);
        $this->assertEquals('delinquency.ended', $publisher->published()[4]->name);
        $this->assertCount(5, $publisher->published());

        // updateWithDelta: the delta is applied by the inner repository; the after state includes it
        $repository->updateWithDelta($copy, Spec::property('daysOverdue', Spec::equalTo(90)));
        $this->assertEquals(90, $copy->daysOverdue);
        $this->assertEquals('delinquency.started', $publisher->published()[5]->name);

        // putAll: each entity evaluated
        $repository->putAll([new DomainEvents23Policy(3, 45), new DomainEvents23Policy(4, 0, true)]);
        $this->assertEquals(['delinquency.started', 'documents.validated'], array_slice($publisher->publishedNames(), 6));
        $this->assertThrows(InvalidArgumentException::class, fn() => $repository->putAll([new \stdClass()]));

        // Every event of one write shares the instant of that write; each write reads the clock once
        $instants = array_map(static fn(DomainEvent $e): string => $e->occurredAt->format('H:i'), $publisher->published());
        $this->assertEquals('09:05', $instants[2]);
        $this->assertEquals('09:05', $instants[3]);

        // Limitation (declared): an entity stored before the decorator existed and mutated in place
        // has no recorded "before", so its crossing is not seen
        $inner = new InMemoryRepository([$legacy = new DomainEvents23Policy(5, 0)]);
        $late = new TriggeringRepository($inner, $this->triggers(), $silent = new InMemoryDomainEventPublisher(), clock: $this->fixedClock('2026-10-11 10:00:00'));
        $legacy->daysOverdue = 45;
        $late->update($legacy);
        $this->assertEquals([], $silent->publishedNames());
        // ...while a fresh copy (as a hydrating repository gives) is seen
        $inner2 = new InMemoryRepository([new DomainEvents23Policy(6, 0)]);
        $seen = new TriggeringRepository($inner2, $this->triggers(), $loud = new InMemoryDomainEventPublisher(), clock: $this->fixedClock('2026-10-11 10:00:00'));
        $seen->update(new DomainEvents23Policy(6, 45));
        $this->assertEquals(['delinquency.started'], $loud->publishedNames());

        // An entity without id yet (auto id) is a creation: no lookup
        $noId = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), $p = new InMemoryDomainEventPublisher(), clock: $this->fixedClock('2026-10-11 10:00:00'));
        $noId->put(new DomainEvents23Policy(null, 0));
        $this->assertEquals([], $p->publishedNames());

        // A translatable identity specification (for SQL repositories) works the same in memory
        $byProperty = new TriggeringRepository(
            new InMemoryRepository(),
            $this->triggers(),
            $q = new InMemoryDomainEventPublisher(),
            clock: $this->fixedClock('2026-10-11 10:00:00'),
            identitySpecification: TriggeringRepository::byIdProperty('id')
        );
        $byProperty->put(new DomainEvents23Policy(8, 0));
        $byProperty->update(new DomainEvents23Policy(8, 45));
        $this->assertEquals(['delinquency.started'], $q->publishedNames());
        $this->assertThrows(InvalidArgumentException::class, fn() => TriggeringRepository::byIdProperty(''));
    }

    private function testDecoratorRemovalsAndDelegation(): void
    {
        $publisher = new InMemoryDomainEventPublisher();
        $inner = new InMemoryRepository();
        $repository = new TriggeringRepository($inner, $this->triggers(), $publisher, clock: $this->fixedClock('2026-10-11 09:00:00'));
        $a = new DomainEvents23Policy('a', 45);
        $b = new DomainEvents23Policy('b', 10);
        $c = new DomainEvents23Policy('c', 60);
        $d = new DomainEvents23Policy('d', 31);
        $repository->putAll([$a, $b, $c, $d]);
        $this->assertCount(3, $publisher->drain());

        // Reads are delegated
        $lateSpec = Spec::property('daysOverdue', Spec::greaterThan(30));
        $this->assertEquals(3, $repository->count($lateSpec));
        $this->assertEquals(3, $repository->countAll($lateSpec));
        $this->assertCount(3, $repository->findAll($lateSpec));
        $this->assertCount(3, $repository->find($lateSpec));
        $this->assertCount(3, iterator_to_array($repository->iterate($lateSpec), false));
        $this->assertCount(3, iterator_to_array($repository->iterateAll($lateSpec), false));
        $this->assertEquals('b', $repository->findSingle(Spec::property('daysOverdue', Spec::equalTo(10)))?->getEntityId());
        $this->assertTrue($repository->contains($a));
        $this->assertFalse($repository->isNativelyPartitioned());
        $this->assertThrows(RepositoryException::class, fn() => $repository->makePartition());

        // remove() of a satisfying entity: exit; of a non-satisfying one: nothing; of a missing one: false
        $this->assertTrue($repository->remove($a));
        $this->assertEquals(['delinquency.ended'], $publisher->publishedNames());
        $this->assertEquals('a', $publisher->published()[0]->entityId);
        $this->assertTrue($repository->remove($b));
        $this->assertFalse($repository->remove(new DomainEvents23Policy('zzz', 99)));
        $this->assertCount(1, $publisher->drain());

        // removeBy(): one exit per removed entity that satisfied
        $repository->put($e = new DomainEvents23Policy('e', 5));
        $this->assertEquals(2, $repository->removeBy(Spec::property('daysOverdue', Spec::greaterThan(30))));
        $this->assertEquals(['delinquency.ended', 'delinquency.ended'], $publisher->publishedNames());
        $this->assertEquals(['c', 'd'], array_map(static fn(DomainEvent $ev): string => $ev->entityId, $publisher->published()));
        $this->assertEquals(1, $repository->removeAll(Spec::alwaysTrue()));
        $this->assertCount(2, $publisher->published(), 'e did not satisfy: no exit');

        // A removed object mutated in place before removal: the recorded "before" decides
        $repository->put($f = new DomainEvents23Policy('f', 45));
        $publisher->drain();
        $f->daysOverdue = 0;
        $repository->remove($f);
        $this->assertEquals(['delinquency.ended'], $publisher->publishedNames());
        $this->assertEquals($inner, $repository->getInner());
    }

    // ==========================================
    // D3: the error path of the decorator
    // ==========================================

    private function testDecoratorErrorPath(): void
    {
        $set = new TriggerSet($this->delinquent(), $this->exploding());

        // Default: the write goes on, the error is reported, the other triggers publish
        $reporter = new DomainEvents23RecordingReporter();
        $publisher = new InMemoryDomainEventPublisher();
        $repository = new TriggeringRepository(new InMemoryRepository(), $set, $publisher, errors: $reporter, clock: $this->fixedClock('2026-10-11 09:00:00'));
        $repository->put(new DomainEvents23Policy(1, 45));
        $this->assertTrue($repository->contains(new DomainEvents23Policy(1)));
        $this->assertEquals(['delinquency.started'], $publisher->publishedNames());
        $this->assertCount(1, $reporter->errors);
        $this->assertEquals('policy.exploding', $reporter->errors[0]->triggerName);
        $this->assertEquals(TriggerEvaluationError::SIDE_AFTER, $reporter->errors[0]->side);

        // A broken reporter never changes the outcome
        $broken = new class implements IDomainEventErrorReporter {
            public function report(TriggerEvaluationError $error): void
            {
                throw new RuntimeException('logger down');
            }
        };
        $tolerant = new TriggeringRepository(new InMemoryRepository(), $set, $p = new InMemoryDomainEventPublisher(), errors: $broken, clock: $this->fixedClock('2026-10-11 09:00:00'));
        $tolerant->put(new DomainEvents23Policy(2, 45));
        $this->assertEquals(['delinquency.started'], $p->publishedNames());

        // Strict: reported, then refused; nothing published; the exception keeps the original for the log
        $strictReporter = new DomainEvents23RecordingReporter();
        $strict = new TriggeringRepository(
            new InMemoryRepository(),
            $set,
            $sp = new InMemoryDomainEventPublisher(),
            errors: $strictReporter,
            clock: $this->fixedClock('2026-10-11 09:00:00'),
            failOnEvaluationError: true
        );
        $thrown = $this->assertThrows(DomainEventTriggerException::class, fn() => $strict->put(new DomainEvents23Policy(3, 45)));
        $this->assertEquals([], $sp->publishedNames());
        $this->assertCount(1, $strictReporter->errors);
        $this->assertCount(1, $thrown->getErrors());
        $this->assertInstanceOf(RuntimeException::class, $thrown->getPrevious());
        $this->assertFalse(str_contains($thrown->getMessage(), 'secret'));

        // Strict inside the caller's transaction: the events never reach the publisher
        $inner = new InMemoryRepository();
        $tm = new InMemoryTransactionManager([$sp]);
        $this->assertThrows(DomainEventTriggerException::class, fn() => $tm->transactional(function () use ($inner, $set, $sp): void {
            (new TriggeringRepository($inner, $set, $sp, failOnEvaluationError: true, clock: $this->fixedClock('2026-10-11 09:00:00')))
                ->put(new DomainEvents23Policy(4, 45));
        }));
        $this->assertEquals([], $sp->publishedNames());

        // A failing publisher is never swallowed
        $down = new class implements IDomainEventPublisher {
            public function publish(array $events): int
            {
                throw new RuntimeException('outbox down');
            }
        };
        $failing = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), $down, clock: $this->fixedClock('2026-10-11 09:00:00'));
        $this->assertThrows(RuntimeException::class, fn() => $failing->put(new DomainEvents23Policy(5, 45)));
        // ...but a write with no event never calls it
        $failing->put(new DomainEvents23Policy(6, 0));
        $this->assertTrue($failing->contains(new DomainEvents23Policy(6)));
    }

    private function testInMemoryTransactionRollsEventsBack(): void
    {
        $publisher = new InMemoryDomainEventPublisher();
        $tm = new InMemoryTransactionManager([$publisher]);
        $repository = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), $publisher, $tm, clock: $this->fixedClock('2026-10-11 09:00:00'));

        $repository->put(new DomainEvents23Policy(1, 45));
        $this->assertCount(1, $publisher->published());
        // The caller's business transaction: the decorator joins it; a failure after the write drops the events
        $this->assertThrows(RuntimeException::class, fn() => $tm->transactional(function () use ($repository): void {
            $repository->put(new DomainEvents23Policy(2, 45, true));
            throw new RuntimeException('business rule failed after the write');
        }));
        $this->assertCount(1, $publisher->published());
        // Re-publishing the same events keeps one copy
        $this->assertEquals(0, $publisher->publish($publisher->published()));
    }

    // ==========================================
    // D5: outbox and dispatcher (both backends)
    // ==========================================

    /**
     * @param Closure(): IDomainEventOutbox $factory
     */
    private function testOutboxFifoFailuresAndAttempts(string $label, Closure $factory): void
    {
        $outbox = $factory();
        $ids = new UlidGenerator(static fn(): int => 1_760_000_000_000);
        $publisher = new OutboxDomainEventPublisher($outbox, $ids);
        $a1 = $this->event('first', 'A');
        $a2 = $this->event('second', 'A', DomainEventKind::EXIT);
        $b1 = $this->event('first', 'B');
        $this->assertEquals(3, $publisher->publish([$a1, $b1, $a2]), $label);
        $this->assertEquals(0, $publisher->publish([$a1, $b1, $a2]), "{$label}: idempotent enqueue");
        $this->assertEquals(0, $publisher->publish([]), $label);
        $this->assertEquals(3, $outbox->count(), $label);
        $this->assertEquals('second', $outbox->findByIdempotencyKey($a2->getIdempotencyKey())?->event->name, $label);
        $this->assertEquals(null, $outbox->findByIdempotencyKey('de:missing'), $label);
        $this->assertEquals(['first', 'second'], array_map(static fn(DomainEventOutboxEntry $e): string => $e->event->name, $outbox->findByEntity(DomainEvents23Policy::class, 'A')), $label);

        // Heads: one per entity, the oldest first
        $heads = $outbox->fetchPending();
        $this->assertEquals(['A', 'B'], array_map(static fn(DomainEventOutboxEntry $e): string => $e->event->entityId, $heads), $label);
        $this->assertEquals(['first', 'first'], array_map(static fn(DomainEventOutboxEntry $e): string => $e->event->name, $heads), $label);
        $this->assertCount(1, $outbox->fetchPending(1), $label);
        $this->assertThrows(InvalidArgumentException::class, fn() => $outbox->fetchPending(0));

        $log = [];
        $failFirstOfA = true;
        $handlers = (new DomainEventHandlerRegistry())
            ->register('first', function (DomainEvent $event, string $key) use (&$log, &$failFirstOfA): void {
                if ($event->entityId === 'A' && $failFirstOfA) {
                    throw new RuntimeException("boom\nStack trace:\n#0 /secret/path.php(12): x()");
                }
                $log[] = 'first:' . $event->entityId . ':' . $key;
            })
            ->register('first', function (DomainEvent $event) use (&$log): void {
                $log[] = 'audit:' . $event->entityId;
            })
            ->register('second', function (DomainEvent $event) use (&$log): void {
                $log[] = 'second:' . $event->entityId;
            });
        $this->assertEquals(['first', 'second'], $handlers->names(), $label);
        $this->assertTrue($handlers->has('first'), $label);
        $this->assertCount(2, $handlers->handlersFor('first'), $label);
        $this->assertThrows(InvalidArgumentException::class, fn() => $handlers->register(' x', static function (): void {}));
        $clock = $this->fixedClock('2026-10-11 10:00:00');

        // Run 1: A1 fails (A2 waits behind it), B1 delivered to both handlers in order
        $report = (new DomainEventDispatcher($outbox, $handlers, null, $clock))->dispatchPending();
        $this->assertEquals(1, $report->deliveredCount(), $label);
        $this->assertEquals(1, $report->failedCount(), $label);
        $this->assertEquals(['first:B:' . $b1->getIdempotencyKey(), 'audit:B'], $log, $label);
        $this->assertEquals('RuntimeException: boom', $report->getFailures()[$a1->getIdempotencyKey()], "{$label}: no stack trace");
        $stored = $outbox->findByEntity(DomainEvents23Policy::class, 'A');
        $this->assertEquals(DomainEventOutboxStatus::FAILED, $stored[0]->status, $label);
        $this->assertEquals(1, $stored[0]->attempts, $label);
        $this->assertEquals('RuntimeException: boom', $stored[0]->lastError, $label);
        $this->assertEquals(DomainEventOutboxStatus::PENDING, $stored[1]->status, "{$label}: A2 not overtaking A1");
        $this->assertEquals(0, $stored[1]->attempts, $label);

        // Run 2: A1 then A2, in order
        $failFirstOfA = false;
        $report = (new DomainEventDispatcher($outbox, $handlers, null, $clock))->dispatchPending();
        $this->assertEquals(2, $report->deliveredCount(), $label);
        $this->assertEquals(['first:B:' . $b1->getIdempotencyKey(), 'audit:B', 'first:A:' . $a1->getIdempotencyKey(), 'audit:A', 'second:A'], $log, $label);
        $deliveredA1 = $outbox->findByEntity(DomainEvents23Policy::class, 'A')[0];
        $this->assertEquals(2, $deliveredA1->attempts, $label);
        $this->assertTrue($deliveredA1->isDelivered(), $label);
        $this->assertEquals('2026-10-11 10:00:00', $deliveredA1->deliveredAt?->format('Y-m-d H:i:s'), $label);
        $this->assertEquals([], $outbox->fetchPending(), $label);
        $this->assertEquals(0, (new DomainEventDispatcher($outbox, $handlers, null, $clock))->dispatchPending()->deliveredCount(), $label);

        // Missing handler: a failed attempt, not a silent drop; maxAttempts exhausts it and unblocks the entity
        $publisher->publish([$this->event('unknown', 'C', at: '2026-10-11 11:00:00'), $this->event('second', 'C', DomainEventKind::EXIT, '2026-10-11 11:00:00')]);
        $limited = new DomainEventDispatcher($outbox, $handlers, 1, $clock);
        $report = $limited->dispatchPending();
        $this->assertEquals(0, $report->deliveredCount(), $label);
        $this->assertEquals(['No handler registered for domain event "unknown".'], array_values($report->getFailures()), $label);
        $report = $limited->dispatchPending();
        $this->assertEquals(1, $report->deliveredCount(), "{$label}: exhausted entry no longer blocks");
        $this->assertEquals('second:C', end($log), $label);
        $this->assertEquals(0, $limited->dispatchPending()->deliveredCount(), $label);
        $this->assertThrows(InvalidArgumentException::class, fn() => new DomainEventDispatcher($outbox, $handlers, 0));
        $this->assertThrows(InvalidArgumentException::class, fn() => $limited->dispatchPending(0));

        // Limit: attempts per run
        $publisher->publish([$this->event('second', 'D'), $this->event('second', 'E'), $this->event('second', 'F')]);
        $this->assertEquals(2, $limited->dispatchPending(2)->deliveredCount(), $label);
        $this->assertEquals(1, $limited->dispatchPending(2)->deliveredCount(), $label);

        // Marks on an unknown entry
        $ghost = new DomainEventOutboxEntry('01ZZZZZZZZZZZZZZZZZZZZZZZZ', $this->event('ghost', 'G'));
        $this->assertThrows(DomainEventPersistenceException::class, fn() => $outbox->markDelivered($ghost, $this->at('2026-10-11 12:00:00')));
        $this->assertThrows(DomainEventPersistenceException::class, fn() => $outbox->markFailed($ghost, 'x', $this->at('2026-10-11 12:00:00')));
        $this->assertThrows(InvalidArgumentException::class, fn() => new DomainEventOutboxEntry('', $this->event('ghost', 'G')));
    }

    // ==========================================
    // RN-04 / D7: the machine bridge
    // ==========================================

    private function quoteMachine(): MachineDefinition
    {
        return Spec::machine('quote', 1)
            ->region('progress', 'awaiting_documents')
                ->state('awaiting_documents')
                    ->on('VALIDATE', 'documents_validated')
                ->state('documents_validated')
                    ->on('CLOSE', 'closed')
                ->finalState('closed')
            ->build();
    }

    private function testMachineBridge(): void
    {
        $persistence = MachinePersistence::inMemory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$this->quoteMachine()]), $persistence);
        $runner->start('quote-7', 'quote', 1, null, $this->at('2026-10-11 08:00:00'));
        $runner->start('quote-8', 'quote', 1, null, $this->at('2026-10-11 08:00:00'));

        // Policies write through the decorator into the domain event outbox
        $outbox = new InMemoryDomainEventOutbox();
        $policies = new TriggeringRepository(
            new InMemoryRepository(),
            $this->triggers(),
            new OutboxDomainEventPublisher($outbox),
            clock: $this->fixedClock('2026-10-11 09:00:00')
        );
        $policy = new DomainEvents23Policy(7, 0, false);
        $policies->put($policy);

        $contexts = [];
        $bridge = new MachineEventBridge(
            $runner,
            ['documents.validated' => 'VALIDATE'],
            static fn(DomainEvent $e): ?string => $e->entityId === '99' ? null : 'quote-' . $e->entityId,
            static function (DomainEvent $e, string $instanceId) use (&$contexts): array {
                $contexts[] = $instanceId;

                return ['source' => 'domain-event'];
            },
            'domain-events'
        );
        $handlers = $bridge->registerIn(new DomainEventHandlerRegistry());
        $this->assertEquals(['documents.validated'], $handlers->names());
        $this->assertEquals(['documents.validated' => 'VALIDATE'], $bridge->getEventTypes());

        // "All documents validated": the write emits documents.validated, the dispatcher moves the instance
        $policy->documentsValidated = true;
        $policies->update($policy);
        $this->assertEquals(1, $outbox->count());
        $dispatcher = new DomainEventDispatcher($outbox, $handlers, null, $this->fixedClock('2026-10-11 09:05:00'));
        $this->assertEquals(1, $dispatcher->dispatchPending()->deliveredCount());
        $instance = $runner->load('quote-7');
        $this->assertEquals('documents_validated', $instance->snapshot->stateOf('progress'));
        $this->assertEquals(2, $instance->version);
        $this->assertEquals(['quote-7'], $contexts);
        $history = $persistence->history->findByInstance('quote-7');
        $this->assertCount(2, $history);
        $move = $history[1];
        $this->assertEquals('VALIDATE', $move->eventType);
        $this->assertEquals(EventOrigin::INTERNAL, $move->origin);
        $this->assertEquals('domain-events', $move->actor);
        $domainEvent = $outbox->findByEntity(DomainEvents23Policy::class, '7')[0]->event;
        $this->assertEquals($domainEvent->getIdempotencyKey(), $move->idempotencyKey);
        $this->assertEquals('2026-10-11 09:00:00', $move->occurredAt->format('Y-m-d H:i:s'));
        $this->assertEquals(
            ['trigger' => 'policy.documents', 'entity_id' => '7', 'domain_event' => 'documents.validated', 'kind' => 'enter'],
            $move->payload
        );
        $this->assertFalse(str_contains((string) json_encode($move->payload), 'Maria'));

        // Redelivery (at-least-once): the runner returns the original result and does not move again
        $again = $bridge->deliver($domainEvent);
        $this->assertTrue($again?->isAccepted() ?? false);
        $bridge($domainEvent, $domainEvent->getIdempotencyKey());
        $this->assertEquals(2, $runner->load('quote-7')->version);
        $this->assertCount(2, $persistence->history->findByInstance('quote-7'));
        $this->assertEquals(0, $dispatcher->dispatchPending()->deliveredCount(), 'already delivered');

        // A new crossing (exit then enter again) at another instant is a new key: it is delivered,
        // and the machine answers for its current state (unhandled here: a normal outcome, not an error)
        $policy->documentsValidated = false;
        $policies->update($policy);
        $later = new DomainEvent('documents.validated', 'policy.documents', DomainEventKind::ENTER, DomainEvents23Policy::class, '7', $this->at('2026-10-12 09:00:00'));
        $result = $bridge->deliver($later);
        $this->assertTrue($result?->isUnhandled() ?? false);
        $this->assertEquals(2, $runner->load('quote-7')->version);

        // No instance for the event: nothing is sent
        $this->assertEquals(null, $bridge->deliver(new DomainEvent('documents.validated', 'policy.documents', DomainEventKind::ENTER, DomainEvents23Policy::class, '99', $this->at('2026-10-11 09:00:00'))));
        // An unmapped event is a registration mistake
        $this->assertThrows(LogicException::class, fn() => $bridge->deliver($this->event('delinquency.started', '7')));
        // An unknown instance propagates (the dispatcher marks the entry failed and retries)
        $this->assertThrows(Throwable::class, fn() => $bridge->deliver(new DomainEvent('documents.validated', 'policy.documents', DomainEventKind::ENTER, DomainEvents23Policy::class, '404', $this->at('2026-10-11 09:00:00'))));
        $this->assertThrows(InvalidArgumentException::class, fn() => new MachineEventBridge($runner, [], static fn(): ?string => null));
        $this->assertThrows(InvalidArgumentException::class, fn() => new MachineEventBridge($runner, ['x' => ''], static fn(): ?string => null));

        // The other instance never moved
        $this->assertEquals('awaiting_documents', $runner->load('quote-8')->snapshot->stateOf('progress'));
        $this->assertEquals(1, $runner->load('quote-8')->version);
        // An external event with the same type still works for a caller (the bridge only adds an origin)
        $this->assertTrue($runner->send('quote-8', Event::external('VALIDATE', $this->at('2026-10-11 10:00:00')))->isAccepted());
    }

    // ==========================================
    // PDO: DDL, exact counts, idempotency, atomicity, parity
    // ==========================================

    private function testReferenceDdl(): void
    {
        $ddl = (string) file_get_contents(self::DDL);
        $this->assertTrue(str_contains($ddl, 'CREATE TABLE IF NOT EXISTS domain_event_outbox'));
        $this->assertTrue(str_contains($ddl, 'PRIMARY KEY (event_id)'));
        $this->assertTrue(str_contains($ddl, 'UNIQUE (idempotency_key)'));
        $statements = implode("\n", array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--')));
        $this->assertFalse(str_contains(strtoupper($statements), 'CREATE INDEX'), 'MySQL has no CREATE INDEX IF NOT EXISTS');
        $this->assertEquals(2, substr_count($statements, ';'), 'two tables since 1.12.0: the outbox and the trigger state');

        // Idempotent script
        $pdo = $this->sqlite();
        self::loadDdl($pdo, $ddl);
        $columns = array_map(static fn(array $c): string => (string) $c['name'], $pdo->query('PRAGMA table_info(domain_event_outbox)')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertEquals(PdoDomainEventOutbox::COLUMNS, $columns);

        // The UNIQUE constraint is the last line of defence
        $outbox = new PdoDomainEventOutbox($pdo);
        $event = $this->event('first', 'A');
        $this->assertEquals(1, $outbox->enqueue([new DomainEventOutboxEntry('01AAAAAAAAAAAAAAAAAAAAAAAA', $event)]));
        $this->assertThrows(PDOException::class, fn() => $pdo->exec(sprintf(
            "INSERT INTO domain_event_outbox (event_id, idempotency_key, event_name, trigger_name, kind, entity_class, entity_id, occurred_at) VALUES ('01BBBBBBBBBBBBBBBBBBBBBBBB', '%s', 'x', 'y', 'enter', 'C', '1', '2026-10-11 09:00:00.000000')",
            $event->getIdempotencyKey()
        )));
        $this->assertThrows(PDOException::class, fn() => $pdo->exec(
            "INSERT INTO domain_event_outbox (event_id, idempotency_key, event_name, trigger_name, kind, entity_class, entity_id, occurred_at) VALUES ('01CCCCCCCCCCCCCCCCCCCCCCCC', 'de:k', 'x', 'y', 'sideways', 'C', '1', '2026-10-11 09:00:00.000000')"
        ));
        // Stored row as written: payload minimal, instants UTC fixed width
        $row = $pdo->query('SELECT payload, occurred_at, status, attempts FROM domain_event_outbox')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('{"trigger":"policy.trigger","entity_id":"A"}', $row['payload']);
        $this->assertEquals('2026-10-11 09:00:00.000000', $row['occurred_at']);
        $this->assertEquals('pending', $row['status']);
        $this->assertEquals(0, (int) $row['attempts']);

        // Identifiers are validated; a schema qualifies the table
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoDomainEventOutbox($pdo, 'bad table'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoDomainEventOutbox($pdo, 'domain_event_outbox', 'bad;schema'));
        $this->assertEquals('main.domain_event_outbox', (new PdoDomainEventOutbox($pdo, schema: 'main'))->getTable());
        $this->assertEquals(1, (new PdoDomainEventOutbox($pdo, schema: 'main'))->count());
        // A driver error surfaces as DomainEventPersistenceException with the SQLSTATE
        $missing = $this->assertThrows(DomainEventPersistenceException::class, fn() => (new PdoDomainEventOutbox($pdo, 'no_such_table'))->count());
        $this->assertEquals('HY000', $missing->getSqlState());
    }

    private function testDecoratorWithPdoOutboxExactCounts(): void
    {
        $pdo = $this->sqlite();
        $outbox = new PdoDomainEventOutbox($pdo);
        $tm = new PdoTransactionManager($pdo);
        $clock = $this->fixedClock('2026-10-11 09:00:00');
        $repository = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), new OutboxDomainEventPublisher($outbox), $tm, clock: $clock);
        $rows = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn();

        $p1 = new DomainEvents23Policy(1, 0);
        $p2 = new DomainEvents23Policy(2, 45, true);       // enter x2
        $p3 = new DomainEvents23Policy(3, 10);
        $repository->putAll([$p1, $p2, $p3]);
        $this->assertEquals(2, $rows());

        $p1->daysOverdue = 31;                              // enter
        $repository->update($p1);
        $this->assertEquals(3, $rows());

        // Re-applying the same write does not duplicate a row
        $repository->update($p1);
        $repository->put($p1);
        $repository->updateWithDelta($p1, Spec::property('daysOverdue', Spec::equalTo(31)));
        $this->assertEquals(3, $rows());

        $p3->documentsValidated = true;                     // enter
        $p3->daysOverdue = 5;                               // no change
        $repository->update($p3);
        $this->assertEquals(4, $rows());

        $repository->remove($p2);                           // exit of delinquency (documents has no exit event)
        $this->assertEquals(5, $rows());

        $this->assertEquals(1, $repository->removeBy(Spec::property('daysOverdue', Spec::greaterThan(30))));  // p1 exit
        $this->assertEquals(6, $rows());

        $names = array_map(static fn(array $r): string => $r['entity_id'] . ':' . $r['event_name'], $pdo->query('SELECT entity_id, event_name FROM domain_event_outbox ORDER BY event_id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertEquals([
            '2:delinquency.started',
            '2:documents.validated',
            '1:delinquency.started',
            '3:documents.validated',
            '2:delinquency.ended',
            '1:delinquency.ended',
        ], $names);

        // The same crossing re-applied by a retried write (a fresh repository whose write was lost,
        // the same instant): same key, no second row
        $retry = new TriggeringRepository(new InMemoryRepository([new DomainEvents23Policy(9, 0)]), $this->triggers(), new OutboxDomainEventPublisher($outbox), $tm, clock: $clock);
        $retry->update(new DomainEvents23Policy(9, 45));
        $this->assertEquals(7, $rows());
        $retryAgain = new TriggeringRepository(new InMemoryRepository([new DomainEvents23Policy(9, 0)]), $this->triggers(), new OutboxDomainEventPublisher($outbox), $tm, clock: $clock);
        $retryAgain->update(new DomainEvents23Policy(9, 45));
        $this->assertEquals(7, $rows(), 'idempotent: one row per idempotency key');
        $this->assertEquals(7, $outbox->count());
    }

    private function testPdoTransactionAtomicity(): void
    {
        $pdo = $this->sqlite();
        $outbox = new PdoDomainEventOutbox($pdo);
        $tm = new PdoTransactionManager($pdo);
        $rows = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn();

        // A publisher that writes its rows and then fails: the decorator's transaction rolls them back
        $writesThenFails = new class (new OutboxDomainEventPublisher($outbox)) implements IDomainEventPublisher {
            public function __construct(private readonly IDomainEventPublisher $inner)
            {
            }

            public function publish(array $events): int
            {
                $this->inner->publish($events);
                throw new RuntimeException('failed after writing');
            }
        };
        $repository = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), $writesThenFails, $tm, clock: $this->fixedClock('2026-10-11 09:00:00'));
        $this->assertThrows(RuntimeException::class, fn() => $repository->put(new DomainEvents23Policy(1, 45, true)));
        $this->assertEquals(0, $rows());
        $this->assertFalse($pdo->inTransaction());

        // The caller's transaction on the same connection: the decorator joins it
        $joined = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), new OutboxDomainEventPublisher($outbox), $tm, clock: $this->fixedClock('2026-10-11 09:00:00'));
        $this->assertThrows(RuntimeException::class, fn() => $tm->transactional(function () use ($joined): void {
            $joined->put(new DomainEvents23Policy(2, 45));
            throw new RuntimeException('business rule failed after the write');
        }));
        $this->assertEquals(0, $rows());
        $tm->transactional(fn() => $joined->put(new DomainEvents23Policy(3, 45)));
        $this->assertEquals(1, $rows());
    }

    /**
     * 1.11.0 integration of forwards 028 and 029: over a PdoRepository the decorator looks "before"
     * up with a SQL-translatable identity by default (the PHP predicate would be refused).
     */
    private function testDecoratorOverPdoRepositoryUsesSqlIdentity(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE aspec_article (id VARCHAR(26) PRIMARY KEY, title VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL, views INTEGER NOT NULL, price TEXT NOT NULL, published INTEGER NOT NULL, published_at TEXT NULL, tags TEXT NOT NULL, note TEXT NULL)');
        $articles = \Antevemus\ASpecification\Repositories\Pdo\PdoRepository::forEntity($pdo, \Antevemus\ASpecification\Tests\Support\PdoArticle::class);
        $publisher = new InMemoryDomainEventPublisher();
        $triggers = new TriggerSet(new SpecificationTrigger('popular', Spec::property('views', Spec::greaterThanOrEqualTo(100)), 'article.popular', 'article.unpopular'));
        $clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-11T10:00:00Z');
        $repository = new TriggeringRepository($articles, $triggers, $publisher, new PdoTransactionManager($pdo), clock: $clock);

        $article = new \Antevemus\ASpecification\Tests\Support\PdoArticle('Spec-driven events', views: 10);
        $repository->put($article);
        $this->assertEquals([], $publisher->publishedNames(), 'a creation below the threshold emits nothing');

        $article->setViews(150);
        $repository->update($article);
        $this->assertEquals(['article.popular'], $publisher->publishedNames(), 'the stored row (views 10) is "before": entering');

        $repository->update($article);
        $this->assertEquals(['article.popular'], $publisher->publishedNames(), 'no change, no event');

        $article->setViews(5);
        $repository->update($article);
        $this->assertEquals(['article.popular', 'article.unpopular'], $publisher->publishedNames(), 'leaving');
    }

    private function testOutboxParityInMemoryVsPdo(): void
    {
        $results = [];
        foreach ($this->outboxes() as $label => $factory) {
            $outbox = $factory();
            $ids = new UlidGenerator(static fn(): int => 1_760_000_000_000);
            $publisher = new OutboxDomainEventPublisher($outbox, $ids);
            $repository = new TriggeringRepository(new InMemoryRepository(), $this->triggers(), $publisher, clock: $this->tickingClock('2026-10-11 09:00:00'));
            $p = new DomainEvents23Policy('P-1', 45, true);
            $repository->put($p);
            $p->daysOverdue = 0;
            $repository->update($p);
            $failing = true;
            $handlers = (new DomainEventHandlerRegistry())
                ->register('delinquency.started', static function () use (&$failing): void {
                    if ($failing) {
                        $failing = false;
                        throw new RuntimeException(str_repeat('x', 2500));
                    }
                })
                ->register('documents.validated', static function (): void {})
                ->register('delinquency.ended', static function (): void {});
            $dispatcher = new DomainEventDispatcher($outbox, $handlers, null, $this->fixedClock('2026-10-11 10:00:00'));
            $dispatcher->dispatchPending();
            $dispatcher->dispatchPending(1);
            $results[$label] = array_map(static function (DomainEventOutboxEntry $e): array {
                $a = $e->toArray();
                unset($a['event_id']);

                return $a;
            }, $outbox->findByEntity(DomainEvents23Policy::class, 'P-1'));
        }
        $this->assertEquals($results['InMemory'], $results['PDO']);
        $this->assertEquals(2000, mb_strlen((string) $results['PDO'][0]['last_error']));
        $this->assertEquals(['delivered', 'pending', 'pending'], array_column($results['PDO'], 'status'));
    }

    // ==========================================
    // README (1.11.0): exemplo 18 transcrito (EN/pt-BR); cada comentário "// valor" do README vira
    // uma asserção. Únicas trocas: Policy e Product são ReadmePolicy e ReadmeProduct (tests/Support),
    // escritas iguais; o bloco 3 parte do estado do exemplo 17 (recriado aqui) e da DDL de referência.
    // ==========================================

    /** Os três blocos do exemplo 18 são iguais nos dois idiomas, fora os comentários. */
    private function testReadme18BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/events/from-specifications.md', 18);
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/events/from-specifications.md', 18);
        $this->assertCount(3, $english, 'três blocos PHP no exemplo 18 (EN)');
        $this->assertCount(3, $portuguese, 'três blocos PHP no exemplo 18 (pt-BR)');
        foreach ($english as $i => $block) {
            $this->assertEquals(ReadmeBlocks::codeTokens($block), ReadmeBlocks::codeTokens($portuguese[$i]), "README 18: bloco {$i} igual fora os comentários");
        }
    }

    /** README 18: gatilhos, decorador sobre InMemory, outbox + dispatcher, ponte com a máquina, PDO atômico. */
    private function testReadme18RunsAsWritten(): void
    {
        // --- bloco 1 ---
        $triggers = new TriggerSet(
            new SpecificationTrigger('policy.delinquent', Spec::property('daysOverdue', Spec::greaterThan(30)),
                onEnter: 'delinquency.started', onExit: 'delinquency.ended'),
            new SpecificationTrigger('policy.documents', Spec::property('documentsValidated', Spec::isTrue()),
                onEnter: 'documents.validated'),
        );

        $outbox = new InMemoryDomainEventOutbox();
        $policies = new TriggeringRepository(new InMemoryRepository(), $triggers, new OutboxDomainEventPublisher($outbox));

        $policy = new ReadmePolicy('P-1');
        $policies->put($policy);
        $this->assertEquals(0, $outbox->count(), 'README 18: não satisfaz nenhum gatilho');
        $policy->daysOverdue = 45;
        $policies->update($policy);
        $this->assertEquals(1, $outbox->count(), 'README 18: passa a satisfazer policy.delinquent');
        $policies->update($policy);
        $this->assertEquals(1, $outbox->count(), 'README 18: nenhuma travessia');
        $policy->daysOverdue = 0;
        $policies->update($policy);
        $this->assertEquals(2, $outbox->count());

        $event = $outbox->findByEntity(ReadmePolicy::class, 'P-1')[0]->event;
        $this->assertEquals('delinquency.started', $event->name);
        $this->assertEquals(['trigger' => 'policy.delinquent', 'entity_id' => 'P-1'], $event->getPayload());
        // Prosa: nenhum dado pessoal no evento; chave determinística 'de:' + SHA-256
        $this->assertFalse(str_contains((string) json_encode($event->toArray()), 'Maria'));
        $this->assertEquals(1, preg_match('/^de:[0-9a-f]{64}$/', $event->getIdempotencyKey()));

        $seen = [];
        $handlers = (new DomainEventHandlerRegistry())
            ->register('delinquency.started', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; })
            ->register('delinquency.ended', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; });
        $this->assertEquals(2, (new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount());
        $this->assertEquals(['delinquency.started', 'delinquency.ended'], $seen);

        // --- bloco 2 ---
        $review = Spec::machine('policy_review', 1)
            ->region('lifecycle', 'waiting_documents')
                ->state('waiting_documents')
                    ->on('VALIDATE', 'under_review')
                ->finalState('under_review')
            ->build();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$review]), MachinePersistence::inMemory());
        $runner->start('review-P-1', 'policy_review', 1, null, new DateTimeImmutable('2026-10-11 09:00'));

        (new MachineEventBridge($runner, ['documents.validated' => 'VALIDATE'], fn(DomainEvent $e): ?string => 'review-' . $e->entityId))
            ->registerIn($handlers);

        $policy->documentsValidated = true;
        $policies->update($policy);
        $this->assertEquals(3, $outbox->count(), 'README 18: documents.validated vai para a outbox');
        $this->assertEquals(1, (new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount());
        $this->assertEquals('under_review', $runner->load('review-P-1')->snapshot->stateOf('lifecycle'));

        // Prosa: a reentrega devolve o resultado original e não move a instância de novo
        $validated = $outbox->findByEntity(ReadmePolicy::class, 'P-1')[2]->event;
        $this->assertEquals('documents.validated', $validated->name);
        $bridge = new MachineEventBridge($runner, ['documents.validated' => 'VALIDATE'], fn(DomainEvent $e): ?string => 'review-' . $e->entityId);
        $this->assertTrue($bridge->deliver($validated)?->isAccepted() ?? false);
        $this->assertEquals(2, $runner->load('review-P-1')->version);

        // Prosa (limitação conhecida): entidade guardada antes do decorador e alterada no próprio objeto não tem "antes"
        $legacy = new ReadmePolicy('P-2');
        $inner = new InMemoryRepository([$legacy]);
        $late = new TriggeringRepository($inner, $triggers, $silent = new InMemoryDomainEventPublisher());
        $legacy->daysOverdue = 45;
        $late->update($legacy);
        $this->assertEquals([], $silent->publishedNames());

        if (!extension_loaded('pdo_sqlite')) {
            return;
        }

        // --- estado do exemplo 17 (bloco 1), de onde o bloco 3 parte ---
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80) NOT NULL,
    unit_price TEXT NOT NULL, active INTEGER NOT NULL, cost TEXT NOT NULL, version INTEGER NOT NULL)');
        $products = PdoRepository::forEntity($pdo, ReadmeProduct::class);
        $products->put(new ReadmeProduct('Keyboard', '149.90', cost: '90.00'));
        $products->putAll([new ReadmeProduct('Mouse', '59.90'), new ReadmeProduct('Cable', '19.90', active: false)]);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));   // README: "Once, with your migration tool"

        // --- bloco 3 ---
        $transactions = new SharedPdoTransactionManager($pdo);
        $catalog = new TriggeringRepository(
            PdoRepository::forEntity($pdo, ReadmeProduct::class, $transactions),
            new TriggerSet(new SpecificationTrigger('product.withdrawn', Spec::property('active', Spec::isFalse()), onEnter: 'product.withdrawn')),
            new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo)),
            $transactions,
        );

        $mouse = $catalog->findSingle(Spec::property('name', Spec::equalTo('Mouse')));
        $mouse->active = false;
        $catalog->update($mouse);
        $this->assertEquals(1, $events->count());

        // Prosa: a linha e o evento são confirmados juntos; sem escrita, nada (a mesma escrita de novo não duplica)
        $this->assertEquals('product.withdrawn', $events->findByEntity(ReadmeProduct::class, '2')[0]->event->name);
        $catalog->update($mouse);
        $this->assertEquals(1, $events->count());
        $this->assertFalse($pdo->inTransaction());
    }
}
