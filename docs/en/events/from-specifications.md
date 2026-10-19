# Domain Events From Specifications

### 18. Domain Events From Specifications (Module 23)

A trigger binds a specification to domain events: when an entity **starts** to satisfy it, the trigger emits its enter event; when it **stops**, its exit event. `TriggeringRepository` decorates any `IRepository`: on `put()`/`update()`/`updateWithDelta()` it reads the stored state of the entity by its identity, writes, evaluates every trigger on the state before and after, and publishes the crossings; on `remove()`/`removeBy()` it emits the exits. The production publisher writes to an outbox in the same transaction as the write, and `DomainEventDispatcher` delivers it after the commit, as the effects of the state machine.

```php
use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\DomainEvents\Outbox\InMemoryDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\OutboxDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\SpecificationTrigger;
use Antevemus\ASpecification\DomainEvents\TriggeringRepository;
use Antevemus\ASpecification\DomainEvents\TriggerSet;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Spec;

class Policy extends AbstractEntity
{
    public int $daysOverdue = 0;
    public bool $documentsValidated = false;
    public string $holderName = 'Maria Silva';    // personal data: never copied into an event

    public function __construct(private string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}

$triggers = new TriggerSet(
    new SpecificationTrigger('policy.delinquent', Spec::property('daysOverdue', Spec::greaterThan(30)),
        onEnter: 'delinquency.started', onExit: 'delinquency.ended'),
    new SpecificationTrigger('policy.documents', Spec::property('documentsValidated', Spec::isTrue()),
        onEnter: 'documents.validated'),
);

$outbox = new InMemoryDomainEventOutbox();        // PdoDomainEventOutbox in production
$policies = new TriggeringRepository(new InMemoryRepository(), $triggers, new OutboxDomainEventPublisher($outbox));

$policy = new Policy('P-1');
$policies->put($policy);                          // satisfies no trigger: no event
$policy->daysOverdue = 45;
$policies->update($policy);                       // starts to satisfy policy.delinquent
$policies->update($policy);                       // no crossing, no event
$policy->daysOverdue = 0;
$policies->update($policy);                       // stops satisfying it
$outbox->count();                                 // 2

$event = $outbox->findByEntity(Policy::class, 'P-1')[0]->event;
$event->name;                                     // 'delinquency.started'
$event->getPayload();                             // ['trigger' => 'policy.delinquent', 'entity_id' => 'P-1']

// After the commit (a worker or a cron): every event to its handlers, in write order per entity
$seen = [];
$handlers = (new DomainEventHandlerRegistry())
    ->register('delinquency.started', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; })
    ->register('delinquency.ended', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; });
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 2
$seen;                                            // ['delinquency.started', 'delinquency.ended']
```

An event can move a state machine (example 14): `MachineEventBridge` is a ready-made handler that sends it to a `MachineRunner` as an internal event carrying the domain event's idempotency key, so a redelivery returns the original result and does not move the instance again.

```php
use Antevemus\ASpecification\DomainEvents\MachineEventBridge;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

// "Every document validated" moves the review without a click
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
$policies->update($policy);                       // documents.validated goes to the outbox
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 1
$runner->load('review-P-1')->snapshot->stateOf('lifecycle');                           // 'under_review'
```

Over the `PdoRepository` of example 17 the row and its events commit together when the repository and the outbox share the connection and the transaction manager. The decorator then reads the "before" with `Spec::property('entityId', Spec::equalTo($id))`, compiled to SQL, without configuration:

```php
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;

// Once, with your migration tool: resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);  // one unit of work for the row and its events
$catalog = new TriggeringRepository(
    PdoRepository::forEntity($pdo, Product::class, $transactions),
    new TriggerSet(new SpecificationTrigger('product.withdrawn', Spec::property('active', Spec::isFalse()), onEnter: 'product.withdrawn')),
    new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo)),
    $transactions,
);

$mouse = $catalog->findSingle(Spec::property('name', Spec::equalTo('Mouse')));
$mouse->active = false;
$catalog->update($mouse);                         // UPDATE product and INSERT INTO domain_event_outbox, one commit
$events->count();                                 // 1
```

- **The payload is minimal on purpose**: the trigger name and the entity id, never the entity, so no personal data reaches a queue or a log; a handler that needs more reads the entity by its id. Each event carries a deterministic idempotency key (`de:` + SHA-256 of trigger, entity class, entity id, kind and instant): the outbox stores a crossing once, so re-applying the same write does not duplicate it.
- **Reading "before" costs one read per write** (a scan in the in-memory repositories). Over a `PdoRepository` the identity lookup is the SQL one shown above; for another repository that translates specifications to SQL, pass `identitySpecification: TriggeringRepository::byIdProperty('id')`. The in-memory repositories store the caller's object, so the decorator compares with the states it recorded at the last write of that object through it. **Known limitation:** on the in-memory repositories, an entity stored before the decorator existed (or written to the inner repository directly) and then mutated in place has no "before", and that write emits nothing: write through the decorator from the start. With a trigger state store (example 18.1) the "before" is the recorded state, and the limitation is gone for every entity that has one.
- **Delivery** is at-least-once, in write order per entity: handlers deduplicate by the idempotency key they receive. A failing handler never undoes the write; the entry is marked failed (no stack trace) and retried, holding that entity's later events back until it succeeds or, with `maxAttempts`, reaches the limit. An event without a handler is a failed attempt, not a silent drop.
- **Errors:** a specification that throws while being evaluated emits no event; by default the write goes on and the error goes to an `IDomainEventErrorReporter`, and with `failOnEvaluationError: true` the write throws instead. A failure of the publisher (the outbox) always propagates.
- **A crossing caused by time alone**, with no write (a policy that becomes overdue at midnight while nobody writes it), is not seen by the decorator: that is the time-based sweeper of example 18.1. Event sourcing and a message bus client are out of scope: publish to Kafka or RabbitMQ from a handler.
