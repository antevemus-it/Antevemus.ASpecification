# Eventos de Domínio pela Passagem do Tempo

### 18.1. Eventos de Domínio pela Passagem do Tempo (Módulo 24)

"Não paga e vencida há mais de 30 dias" vira verdade à meia-noite sem ninguém gravar a fatura: só o relógio andou, então os gatilhos de escrita do exemplo 18 nunca a veem. Um **gatilho temporal** monta a especificação a partir do "agora"; um **store de estado** guarda, para cada gatilho e entidade, a última resposta conhecida (`satisfied`, `evaluated_at`); um **varredor**, chamado pelo seu cron com o instante injetado, compara os dois, grava cada travessia e a publica pelo mesmo publicador e pela mesma outbox, com o mesmo tipo de chave de idempotência. O `TriggeringRepository` mantém o mesmo store a cada escrita, então escrita e varredura nunca publicam a mesma travessia duas vezes.

```php
use Antevemus\ASpecification\DomainEvents\State\InMemoryTriggerStateStore;
use Antevemus\ASpecification\DomainEvents\TriggerSweeper;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

#[Table('invoice')]                               // mapeada para o bloco PDO abaixo; usada como está em memória
class Invoice extends AbstractEntity
{
    #[Column] public bool $paid = false;

    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private string $number,
        #[Column('due_date')] public DateTimeImmutable $dueDate,
    ) {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}

$overdue = new TriggerSet(SpecificationTrigger::timed(
    'invoice.overdue',
    fn(DateTimeImmutable $now) => Spec::property('paid', Spec::isFalse())
        ->and(Spec::property('dueDate', Spec::lessThan($now->modify('-30 days')))),
    onEnter: 'collection.started',
    onExit: 'collection.ended',
    crossedAt: fn(Invoice $invoice) => $invoice->dueDate->modify('+30 days'),   // o instante real da travessia
));

$today = new DateTimeImmutable('2026-01-01');
$clock = function () use (&$today): DateTimeImmutable { return $today; };   // o relógio do sistema por padrão

$states = new InMemoryTriggerStateStore();        // PdoTriggerStateStore em produção
$outbox = new InMemoryDomainEventOutbox();
$repository = new InMemoryRepository();
$invoices = new TriggeringRepository($repository, $overdue, new OutboxDomainEventPublisher($outbox), clock: $clock, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, new OutboxDomainEventPublisher($outbox));

$invoice = new Invoice('INV-1', new DateTimeImmutable('2026-01-05'));
$invoices->put($invoice);                         // vence em 4 dias: nenhum evento; o estado é gravado
$sweeper->sweep(new DateTimeImmutable('2026-01-20'))->enteredCount();   // 0
$sweeper->sweep(new DateTimeImmutable('2026-02-05'))->enteredCount();   // 1: ninguém gravou, o tempo passou
$sweeper->sweep(new DateTimeImmutable('2026-02-06'))->enteredCount();   // 0: já anunciada
$outbox->findByEntity(Invoice::class, 'INV-1')[0]->event->occurredAt == $invoice->dueDate->modify('+30 days');   // true

$today = new DateTimeImmutable('2026-02-09');
$invoice->paid = true;
$invoices->update($invoice);                      // collection.ended, publicado pela escrita
$sweeper->sweep(new DateTimeImmutable('2026-02-10'))->exitedCount();    // 0: a escrita já publicou
$outbox->count();                                 // 2
```

> [!WARNING]
> ⚠️ **Vai introduzir um gatilho sobre dados antigos? Rode `baseline()` antes.** Entidade sem estado gravado conta como "não satisfazia", então a primeira varredura publica uma entrada para cada fatura que já está vencida. É a verdade (elas estão do lado de dentro e ninguém foi avisado), mas raramente o que um disparo de e-mails quer. `baseline($now)` grava o estado atual de todos os gatilhos para todas as entidades e não publica nada; as varreduras seguintes publicam só as travessias novas.

```php
// Gatilho introduzido sobre dados antigos: baseline() antes, ou a primeira varredura anuncia tudo o que já estava dentro
$archive = new InMemoryRepository([
    new Invoice('INV-2', new DateTimeImmutable('2025-11-03')),   // vencida desde dezembro
    new Invoice('INV-3', new DateTimeImmutable('2026-03-02')),
]);
$catchUp = new TriggerSweeper($archive, Invoice::class, $overdue, new InMemoryTriggerStateStore(), new OutboxDomainEventPublisher($outbox));
$catchUp->baseline(new DateTimeImmutable('2026-02-10'))->recordedCount();   // 2: um estado por fatura, nenhum evento
$catchUp->sweep(new DateTimeImmutable('2026-02-11'))->enteredCount();      // 0: a INV-2 já estava dentro
$catchUp->sweep(new DateTimeImmutable('2026-04-02'))->eventNames();        // ['collection.started']: a INV-3, travessia nova

// O cron (de hora em hora, toda noite): o instante é o do cron, o limite fatia um acúmulo grande
do {
    $report = $catchUp->sweep(new DateTimeImmutable(), limit: 500);
} while ($report->limitReached());
```

Em produção o store é uma tabela, `domain_event_trigger_state`, no mesmo `resources/sql/domain_events.ansi.sql` (rodar o script de novo num banco da 1.11 só a acrescenta). Dê ao store, à outbox e ao repositório a mesma conexão e o mesmo gerenciador de transação, para o estado de uma travessia e o evento dela serem confirmados juntos:

```php
use Antevemus\ASpecification\DomainEvents\State\PdoTriggerStateStore;

// $pdo do exemplo 17; uma vez, com sua ferramenta de migração: a tabela invoice e resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);
$repository = PdoRepository::forEntity($pdo, Invoice::class, $transactions);
$states = new PdoTriggerStateStore($pdo);         // tabela domain_event_trigger_state
$publisher = new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo));
$invoices = new TriggeringRepository($repository, $overdue, $publisher, $transactions, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, $publisher, $transactions);

$invoices->put(new Invoice('INV-4', new DateTimeImmutable('2026-01-05')));   // já vencida: a escrita publica a entrada
$sweeper->sweep(new DateTimeImmutable())->enteredCount();                   // 0: o estado dela diz "dentro"
count($events->findByEntity(Invoice::class, 'INV-4'));                      // 1
```

- **Como a varredura acha as travessias, e quanto custa.** Para cada gatilho temporal, os estados gravados como "dentro" são lidos em páginas de 500 (pelo id da entidade) e, por página, um `COUNT(*)` de `spec(now) AND id IN (página)` diz se todos continuam dentro; só quando não continuam essas entidades são carregadas (uma removida é saída). Depois, quando o número confirmado dentro é igual ao `COUNT(*)` de `spec(now)`, nada pode entrar e a passada de entradas é pulada; senão as entidades que satisfazem `spec(now)` são lidas em streaming (em SQL com um `PdoRepository`) e as que não estão gravadas como dentro são entradas. A varredura que não acha travessia não carrega entidade: com 10 000 faturas no SQLite, 5 000 vencidas, são 22 comandos, algumas dezenas de milissegundos, e o mesmo pico de memória (uma página) que com 1 000 faturas. O SQL só estreita: cada travessia é confirmada em PHP na entidade carregada, como numa escrita.
- **Uma transação por travessia, compare-and-set no estado.** O estado só é gravado se ainda está na versão que a varredura leu, e o evento só é publicado quando essa gravação venceu; então dois varredores (dois hosts de cron, uma rodada sobreposta), ou um varredor e uma escrita disputando a mesma travessia, a publicam uma vez; a perdedora conta em `conflictCount()`. A chave de idempotência única da outbox é a segunda barreira. Dois varredores juntos são corretos, não mais rápidos: o trabalho não é dividido entre eles, e não há agendador embutido (o cron é seu, como no `deliverDue()` da máquina de estados).
- **O instante.** Um evento achado pela varredura leva o `$now` dela, a não ser que o gatilho declare `crossedAt` (recebe a entidade, o `$now` e o tipo da travessia): o instante dele é usado quando fica depois da última avaliação gravada e não depois do `$now`, o que mantém a chave de idempotência igual em toda varredura e todo processo, enquanto uma reentrada depois de uma saída ganha chave própria. Uma travessia vista por uma escrita leva o instante da escrita.
- **`limit`** limita as travessias tratadas por chamada: repita enquanto `limitReached()`. O `SweepReport` traz as contagens, os eventos, os compare-and-set perdidos, os erros de avaliação (também enviados ao `IDomainEventErrorReporter`) e dois contadores de custo (`entitiesLoaded()`, `statesRead()`).
- **Limites:** um varredor por classe concreta de entidade (os estados são chaveados por ela; uma entidade de outra classe achada no repositório é reportada, nunca gravada); no MySQL com consultas sem buffer, dê ao varredor um repositório numa conexão própria (ele lê estados com o cursor das entidades aberto); os gatilhos comuns do conjunto não são varridos (só mudam em escritas), mas o `baseline()` os grava também.
