# Eventos de Domínio a Partir de Especificações

### 18. Eventos de Domínio a Partir de Especificações (Módulo 23)

Um gatilho liga uma especificação a eventos de domínio: quando uma entidade **passa a** satisfazê-la, o gatilho emite o evento de entrada; quando **deixa de** satisfazer, o de saída. O `TriggeringRepository` decora qualquer `IRepository`: em `put()`/`update()`/`updateWithDelta()` lê o estado guardado da entidade pela identidade, grava, avalia cada gatilho no antes e no depois e publica as travessias; em `remove()`/`removeBy()` emite as saídas. O publicador de produção grava numa outbox na mesma transação da escrita, e o `DomainEventDispatcher` a entrega depois do commit, como os efeitos da máquina de estados.

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
    public string $holderName = 'Maria Silva';    // dado pessoal: nunca copiado para um evento

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

$outbox = new InMemoryDomainEventOutbox();        // PdoDomainEventOutbox em produção
$policies = new TriggeringRepository(new InMemoryRepository(), $triggers, new OutboxDomainEventPublisher($outbox));

$policy = new Policy('P-1');
$policies->put($policy);                          // não satisfaz nenhum gatilho: nenhum evento
$policy->daysOverdue = 45;
$policies->update($policy);                       // passa a satisfazer policy.delinquent
$policies->update($policy);                       // nenhuma travessia, nenhum evento
$policy->daysOverdue = 0;
$policies->update($policy);                       // deixa de satisfazer
$outbox->count();                                 // 2

$event = $outbox->findByEntity(Policy::class, 'P-1')[0]->event;
$event->name;                                     // 'delinquency.started'
$event->getPayload();                             // ['trigger' => 'policy.delinquent', 'entity_id' => 'P-1']

// Depois do commit (um worker ou um cron): cada evento aos seus handlers, na ordem de escrita por entidade
$seen = [];
$handlers = (new DomainEventHandlerRegistry())
    ->register('delinquency.started', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; })
    ->register('delinquency.ended', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; });
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 2
$seen;                                            // ['delinquency.started', 'delinquency.ended']
```

Um evento pode mover uma máquina de estados (exemplo 14): o `MachineEventBridge` é um handler pronto que o envia a um `MachineRunner` como evento interno com a chave de idempotência do evento de domínio, então uma reentrega devolve o resultado original e não move a instância de novo.

```php
use Antevemus\ASpecification\DomainEvents\MachineEventBridge;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

// "Todos os documentos validados" move a análise sem clique
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
$policies->update($policy);                       // documents.validated vai para a outbox
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 1
$runner->load('review-P-1')->snapshot->stateOf('lifecycle');                           // 'under_review'
```

Sobre o `PdoRepository` do exemplo 17, a linha e os seus eventos são confirmados juntos quando o repositório e a outbox compartilham a conexão e o gerenciador de transação. O decorador então lê o "antes" com `Spec::property('entityId', Spec::equalTo($id))`, compilado para SQL, sem configuração:

```php
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;

// Uma vez, com a sua ferramenta de migração: resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);  // uma unidade de trabalho para a linha e os seus eventos
$catalog = new TriggeringRepository(
    PdoRepository::forEntity($pdo, Product::class, $transactions),
    new TriggerSet(new SpecificationTrigger('product.withdrawn', Spec::property('active', Spec::isFalse()), onEnter: 'product.withdrawn')),
    new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo)),
    $transactions,
);

$mouse = $catalog->findSingle(Spec::property('name', Spec::equalTo('Mouse')));
$mouse->active = false;
$catalog->update($mouse);                         // UPDATE product e INSERT INTO domain_event_outbox, um commit
$events->count();                                 // 1
```

- **O payload é mínimo de propósito**: o nome do gatilho e o id da entidade, nunca a entidade, então nenhum dado pessoal chega a uma fila ou a um log; o handler que precisa de mais lê a entidade pelo id. Cada evento leva uma chave de idempotência determinística (`de:` + SHA-256 de gatilho, classe da entidade, id da entidade, tipo e instante): a outbox guarda uma travessia uma vez só, então reaplicar a mesma escrita não a duplica.
- **Ler o "antes" custa uma leitura por escrita** (uma varredura nos repositórios em memória). Sobre um `PdoRepository` a busca pela identidade é a de SQL mostrada acima; para outro repositório que traduz especificações para SQL, passe `identitySpecification: TriggeringRepository::byIdProperty('id')`. Os repositórios em memória guardam o próprio objeto de quem chama, então o decorador compara com os estados que registrou na última escrita daquele objeto por ele. **Limitação conhecida:** nos repositórios em memória, uma entidade guardada antes de o decorador existir (ou gravada direto no repositório interno) e depois alterada no próprio objeto não tem "antes", e essa escrita não emite nada: escreva pelo decorador desde o início. Com um store de estado dos gatilhos (exemplo 18.1) o "antes" é o estado gravado, e a limitação some para toda entidade que tem um.
- **A entrega** é pelo menos uma vez, na ordem de escrita por entidade: os handlers deduplicam pela chave de idempotência que recebem. Um handler que falha nunca desfaz a escrita; a entrada fica marcada como falha (sem stack trace) e é tentada de novo, segurando os eventos seguintes daquela entidade até dar certo ou, com `maxAttempts`, atingir o limite. Evento sem handler é tentativa falha, não descarte silencioso.
- **Erros:** uma especificação que lança ao ser avaliada não emite evento; por padrão a escrita segue e o erro vai para um `IDomainEventErrorReporter`, e com `failOnEvaluationError: true` a escrita lança. Falha do publicador (a outbox) sempre propaga.
- **A travessia causada só pela passagem do tempo**, sem escrita (uma apólice que vence à meia-noite sem ninguém gravá-la), o decorador não vê: é o varredor por tempo do exemplo 18.1. Event sourcing e cliente de barramento de mensagens estão fora do escopo: publique no Kafka ou no RabbitMQ a partir de um handler.
