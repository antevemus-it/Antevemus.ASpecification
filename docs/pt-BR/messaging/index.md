# Mensageria Entre Módulos: Outbox, Relay, Consumidor e Inbox

### 26. Publicar um Fato e Levá-lo ao Broker (Módulo 48)

Desde a v2.14.0 o núcleo tem um gancho para mensagens entre módulos, em `Antevemus\ASpecification\Messaging`: um módulo publica um fato com dados ("uma cotação foi enviada para análise", "chegou uma mensagem no live chat") e os outros o consomem por um broker, sem que um módulo chame o outro. O núcleo não traz cliente de broker: os transportes moram em pacotes satélites, [Redis Streams](https://github.com/antevemus-it/Antevemus.ASpecificationRedisStreams) e [RabbitMQ](https://github.com/antevemus-it/Antevemus.ASpecificationRabbitMQ) (os dois na v1.0.0), atrás de duas interfaces do núcleo, `IMessageTransport` (saída) e `IMessageSource` (entrada), e o núcleo tem uma implementação em memória das duas, a de referência e o dublê de teste.

A aplicação nunca fala com o broker. Ela grava a mensagem numa tabela de **outbox**, na mesma transação da escrita que produziu o fato, e um **relay** leva a outbox ao broker depois do commit, marcando como entregue só o que o broker confirmou. Não há um segundo caminho, então não há gravação dupla: o fato e a sua mensagem confirmam ou desfazem juntos.

```php
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageTransport;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\MessagePublisher;
use Antevemus\ASpecification\Messaging\Outbox\PdoMessageOutbox;
use Antevemus\ASpecification\Messaging\OutboxRelay;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;

// $pdo: as tabelas de resources/sql/messaging.ansi.sql, ao lado das tabelas da aplicação
$transactions = new PdoTransactionManager($pdo);
$publisher = new MessagePublisher(new PdoMessageOutbox($pdo));

$transactions->transactional(function () use ($pdo, $publisher): void {
    $pdo->exec("UPDATE quote SET status = 'submitted' WHERE id = 123"); // a escrita da aplicação
    $publisher->publish(new Message(
        'underwriting.quote_submitted.v1',
        ['quote_id' => 123, 'premium' => 1250.0],
        orderingKey: 'quote:123',
        tenant: 'acme'
    ));
});

$transport = new InMemoryMessageTransport(); // em produção, o transporte de um satélite (Redis Streams, RabbitMQ)
$relay = new OutboxRelay(new PdoMessageOutbox($pdo), $transport, maxAttempts: 10);

$sent = $relay->relayPending(100)->deliveredCount();  // 1: confirmada pelo broker, marcada como entregue
$again = $relay->relayPending(100)->deliveredCount(); // 0: o relay não a manda duas vezes
```

- **A mensagem** (`Message`, `final readonly`): um **nome versionado** em minúsculas, `módulo.fato.vN` (`livechat.message_received.v1`; uma mudança incompatível da carga é um nome novo, `.v2`, consumido lado a lado com o antigo); uma **carga** que o JSON leva sem perda (escalares, arrays e null, floats finitos, UTF-8, no máximo 64 níveis), de no máximo 65 535 bytes codificada, o tamanho de uma coluna `TEXT` do MySQL e do MariaDB; um `messageId` ULID (gerado) e `idempotencyKey()` = `msg:` + o id; `occurredAt` em UTC, ao microssegundo; uma `orderingKey` opcional (`quote:123`), a fila de ordem; um `tenant` opcional; no máximo 32 `headers` de texto, para rastreio. `toArray()`/`fromArray()` e `toJson()`/`fromJson()` são a forma de fio, versão de formato `v: 1`, lida de volta sem perda; um documento de outra versão, ou com chave desconhecida, é recusado.
- **A outbox** (`IMessageOutbox`, com `PdoMessageOutbox` sobre `message_outbox` e `InMemoryMessageOutbox`, o mesmo contrato): uma linha por id de mensagem (publicar a mesma mensagem duas vezes grava uma linha), a ordem de publicação dentro de uma chave de ordem, as tentativas e o último motivo de cada envio que o broker não confirmou. `PdoMessageOutbox` recebe o nome da tabela, um schema e um mapa de colunas (`columns: [canônica => real]`), como os outros stores PDO da biblioteca.
- **O relay** (`OutboxRelay::relayPending($limit)`) lê a cabeça de cada chave de ordem, manda no máximo `batchSize` delas numa chamada do transporte (então um lote tem no máximo uma mensagem por chave, e o broker grava as mensagens de uma chave na ordem de publicação), marca como entregue o que o transporte confirmou e como falha o que não confirmou (uma recusa, uma confirmação que não veio, um envio inteiro que lançou), e segura a chave que falhou pelo resto da rodada. Com `maxAttempts`, uma mensagem que chegou ao limite fica esgotada, não é mais mandada nem segura a sua chave, e o relatório diz isso (`getExhausted()`). A entrega é **pelo menos uma vez**: um processo que morre entre a confirmação e a marca manda a mensagem de novo na rodada seguinte, e os consumidores a deduplicam.
- **O transporte** (`IMessageTransport::send(list<Message>): TransportSendResult`) responde, mensagem a mensagem, se o broker a confirmou, ou seja, "gravou de forma durável" (um `XADD` respondido pelo Redis, um publisher confirm do RabbitMQ), ou por que falhou. `InMemoryMessageTransport` grava a forma de fio num log e pode ser instruído a recusar uma mensagem, perder a sua confirmação, gravá-la duas vezes, reordenar um lote entre chaves de ordem ou fazer um lote inteiro falhar, as falhas que um broker real tem.

### 26.1. Consumir, Uma Vez por Consumidor

`MessageConsumer` recebe entregas de uma `IMessageSource` e entrega cada mensagem aos handlers do seu nome (`MessageHandlerRegistry`, um handler é `fn(Message): void`). Uma **inbox** (`IProcessedMessageStore`, com `PdoProcessedMessageStore` sobre `message_inbox` e `InMemoryProcessedMessageStore`) registra, na mesma transação dos handlers, que este consumidor tratou esta mensagem: uma mensagem entregue duas vezes roda os handlers uma vez por consumidor, o padrão do consumidor idempotente.

```php
use Antevemus\ASpecification\Messaging\Inbox\PdoProcessedMessageStore;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageSource;
use Antevemus\ASpecification\Messaging\MessageConsumer;
use Antevemus\ASpecification\Messaging\MessageHandlerRegistry;

$handlers = (new MessageHandlerRegistry())->register(
    'underwriting.quote_submitted.v1',
    function (Message $message) use ($pdo): void {
        $insert = $pdo->prepare('INSERT INTO agent_task (quote_id, kind) VALUES (?, ?)');
        $insert->execute([$message->payload['quote_id'], 'review_quote']);
    }
);
$consumer = new MessageConsumer(
    'agents',                              // o consumidor: as suas próprias linhas da inbox
    new InMemoryMessageSource($transport), // em produção, um grupo de consumidores de um stream, uma fila do RabbitMQ
    $handlers,
    new PdoProcessedMessageStore($pdo),
    $transactions,                         // a linha da inbox e o INSERT do handler confirmam juntos
    maxDeliveries: 5,
    tenant: 'acme'                         // só as mensagens deste tenant
);

$report = $consumer->consume(100, waitMs: 1000);
$handled = count($report->handledIds());                                     // 1
$tasks = (int) $pdo->query('SELECT COUNT(*) FROM agent_task')->fetchColumn(); // 1

$transport->send([(new PdoMessageOutbox($pdo))->findByOrderingKey('quote:123')[0]->message]); // o broker a entrega de novo
$duplicate = $consumer->consume(100)->entries()[0]->outcome;                 // ConsumeOutcome::DUPLICATE: o handler não rodou
```

- **Cada entrega** tem um desfecho no `ConsumeReport` (`ConsumeOutcome`): `handled` (os handlers e a linha da inbox confirmados, depois o ack); `duplicate` (a inbox já a tinha); `unknown_name` (nenhum handler para o nome: ack, com um aviso em `warnings()`, porque o consumidor não a quer); `other_tenant` (o consumidor é restrito a um tenant e a mensagem é de outro, ou de nenhum: ack sem tratar); `retried` (um handler lançou: rollback e rejeição com requeue); `held_back` (uma mensagem anterior da mesma chave de ordem foi tentada de novo nesta rodada: requeue sem rodar, para a chave manter a ordem); `dead_lettered` (rejeitada sem requeue, para a fila morta do broker).
- **A transação.** Com um gerenciador de transação sobre a conexão das escritas dos handlers (e da inbox), a linha da inbox é gravada primeiro e os handlers rodam na mesma transação: o efeito é exatamente uma vez por consumidor. Sem ele, os handlers rodam primeiro e a linha depois deles, sem atomicidade: um processo que morre entre os dois roda os handlers de novo na reentrega.
- **A fonte** (`IMessageSource`: `receive($max, $waitMs)`, `ack()`, `reject($requeue, $reason)`) entrega `ReceivedMessage`s: a mensagem reconstruída da forma de fio, o número de entregas, o id que o broker lhe deu. Uma entrega rejeitada com requeue volta antes de qualquer entrega posterior da mesma chave; o que o broker entregou e não é uma mensagem é uma entrega inválida, nunca descartada. `InMemoryMessageSource` lê o log de um `InMemoryMessageTransport` com a semântica de um grupo de consumidores de um stream do Redis: toda fonte vê toda mensagem, as entregas ficam em voo até a resposta, e `recoverInFlight()` as devolve como faria um consumidor morto.
- **A ordem** de uma chave de ordem vale da publicação ao handler, para um consumidor por fonte (consumidores concorrentes numa fila só a mantêm por consumidor, como em qualquer broker), salvo para uma mensagem que foi à fila morta, o que o relatório declara.

### 26.2. Falhas, Reentregas e a Fila Morta

Um handler que lança é tentado de novo: a transação desfaz e a entrega é rejeitada com requeue, até `maxDeliveries`. Um handler que sabe que tentar de novo não adianta lança `InvalidMessagePayloadException`, e a mensagem vai à fila morta de uma vez.

```php
use Antevemus\ASpecification\Messaging\ConsumeEntry;
use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessagePayloadException;

$handlers->register('crm.lead_created.v1', function (Message $message): void {
    if (!isset($message->payload['lead_id'])) {
        throw new InvalidMessagePayloadException('lead_id is required'); // tentar de novo não mudaria a resposta
    }
});
$transactions->transactional(fn(): int => $publisher->publish(
    new Message('crm.lead_created.v1', ['name' => 'Ana'], orderingKey: 'lead:9', tenant: 'acme'),
    new Message('crm.lead_created.v1', ['lead_id' => 9, 'name' => 'Ana'], orderingKey: 'lead:9', tenant: 'acme'),
));
$relay->relayPending(100);

$report = $consumer->consume(100);
$outcomes = array_map(fn(ConsumeEntry $entry): string => $entry->outcome->value, $report->entries()); // ['dead_lettered', 'handled']
$reason = $report->entries()[0]->reason;
// A handler of consumer "agents" failed for message "crm.lead_created.v1" (id 01..., ordering key "lead:9")
// (InvalidMessagePayloadException) on delivery 1 of at most 5; the payload was refused.
```

- **maxDeliveries.** Um handler que falha na última entrega permitida manda a mensagem à fila morta; uma entrega cujo número já passou do limite (uma mensagem que derruba o processo antes da resposta) vai à fila morta sem rodar. Uma mensagem na fila morta destrava a sua chave: a próxima mensagem da chave é tratada, aqui na mesma rodada.
- **Sigilo.** Nenhum motivo, relatório ou exceção do gancho leva a carga: eles nomeiam a mensagem pelo nome, pelo id e pela chave de ordem, e o consumidor e a classe da exceção de um handler, nunca a mensagem dela, que pode citar a carga. Os stores PDO relatam um comando que falhou pela operação, pela tabela, pelo SQLSTATE e pelo código do driver, sem a mensagem do driver, porque o PostgreSQL cita a linha que falhou. Dê ao consumidor `onHandlerError: fn(Message $message, Throwable $error, int $deliveryCount)` para registrar a exceção em si.
- **O que não é da mensagem** interrompe a rodada e deixa o resto em voo, para ser entregue de novo: uma falha do broker num ack, uma falha da inbox fora de um handler. A exceção é a corrida de dois processos do mesmo consumidor na inbox: a entrega volta com requeue, e a próxima entrega a acha tratada.

### 26.3. Eventos de Domínio para Fora, Mensagens para Dentro de uma Máquina de Estados

Duas pontes prontas ligam as mensagens ao resto da biblioteca. `DomainEventMessageBridge` é um handler de eventos de domínio (exemplos 18 e 18.1) que publica cada um como mensagem, para que os gatilhos de uma especificação ("uma fatura venceu") cheguem aos outros módulos pelo mesmo caminho. `MessageMachineBridge` é um handler de mensagens que manda um evento a um `MachineRunner` (exemplo 14), para que um fato de outro módulo mova um processo deste.

```php
use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\Messaging\DomainEventMessageBridge;
use Antevemus\ASpecification\Messaging\MessageMachineBridge;

// Saída: $domainOutbox tem os eventos de domínio do gatilho invoice.is_overdue
$domainHandlers = (new DomainEventMessageBridge(
    $publisher,
    ['invoice.overdue' => 'billing.invoice_overdue.v1'], // nome do evento de domínio => nome da mensagem
    fn(DomainEvent $event): ?string => 'acme'           // o tenant de um evento
))->registerIn(new DomainEventHandlerRegistry());

// Entrada: a mensagem inicia o processo de cobrança da fatura ($runner: um MachineRunner)
(new MessageMachineBridge(
    $runner,
    ['billing.invoice_overdue.v1' => 'START_COLLECTION'],
    fn(Message $message): ?string => 'collection-' . $message->payload['entity_id']
))->registerIn($handlers);

$dispatched = (new DomainEventDispatcher($domainOutbox, $domainHandlers))->dispatchPending()->deliveredCount(); // 1
$relay->relayPending(100);
$consumer->consume(100);
$state = $runner->load('collection-77')->snapshot->stateOf('collection'); // 'collecting'
```

- **De um evento de domínio**, a mensagem tem o nome mapeado (ou o do próprio evento, quando já é `módulo.fato.vN`), uma carga com `trigger`, `kind`, `entity_class` e `entity_id`, nunca a entidade, a fila da entidade como chave de ordem (`App\Invoice:77`) e um id derivado da chave de idempotência do evento: uma reentrega do evento pelo despachante publica o mesmo id de mensagem, que a outbox pula. O cabeçalho `domain-event-key` leva a chave do evento.
- **Para dentro da máquina**, o evento tem o instante da mensagem, a carga como está, a origem EXTERNAL por padrão e a chave de idempotência da mensagem, então uma reentrega devolve o resultado original do runner e não move a instância de novo. O tenant da mensagem é passado ao runner (`scopeByTenant`, verdadeiro por padrão), que então carrega só a instância daquele tenant.
- **Fora do escopo**: entrega exatamente uma vez entre processos (o desenho é pelo menos uma vez mais a inbox), Kafka, NATS e SQS (outros satélites, sob demanda), e os efeitos da máquina de estados levados pelo broker (um handler de efeito pode publicar uma `Message`).

### 26.4. Rodar o Relay: Um por Vez

Rode `relayPending()` a partir de um worker ou de um cron, fora de qualquer transação da aplicação. **Um relay por outbox, ou uma trava.** Dois relays sobre a mesma outbox mantêm a ordem da primeira entrega de cada chave de ordem, porque uma mensagem só é cabeça depois que as anteriores da sua chave foram confirmadas. Mesmo assim custam duas coisas: um relay que leu uma entrada antes de o outro marcá-la a manda de novo, depois das mensagens seguintes da sua chave (uma duplicata tardia, que a inbox só absorve enquanto guarda a linha), e os dois contam a tentativa, então o `maxAttempts` se esgota antes. Desde a v2.16.0 uma trava deixa os relays de uma outbox trabalharem um por vez.

```php
use Antevemus\ASpecification\Persistence\PdoLeaseLock;
use DateTimeImmutable;

$lock = new PdoLeaseLock($pdo); // a tabela lease_lock: uma linha por nome de trava
$relay = new OutboxRelay(
    new PdoMessageOutbox($pdo),
    $transport,
    maxAttempts: 10,
    lock: $lock,
    leaseSeconds: 60,                                      // maior que o lote mais lento
    lockOwner: 'relay@' . gethostname() . ':' . getmypid() // quem a segura, para quem lê a tabela
);
$name = $relay->getLockName(); // 'messaging.outbox_relay:message_outbox'

$transactions->transactional(fn(): int => $publisher->publish(
    new Message('underwriting.quote_approved.v1', ['quote_id' => 123], orderingKey: 'quote:123', tenant: 'acme')
));

$lock->tryAcquire($name, 'relay@other-host:4242', new DateTimeImmutable(), 60); // outro processo segura a trava
$denied = $relay->relayPending(100)->isLockDenied();                            // true: nada foi mandado
$lock->release($name, 'relay@other-host:4242');

$report = $relay->relayPending(100); // pega a trava, renova antes de cada lote, libera no fim
$sent = $report->deliveredCount();   // 1
$lost = $report->isLockLost();       // false: nenhum outro relay assumiu a trava
```

- **Sem trava**, o relay é o da v2.15.0: rode exatamente um por outbox (uma linha de cron, uma réplica do worker).
- **Com trava** (`ILeaseLock`, em `Antevemus\ASpecification\Persistence`: `PdoLeaseLock` sobre a tabela `lease_lock` de `resources/sql/lease_lock.ansi.sql`, ou `InMemoryLeaseLock` para os relays de um mesmo processo), `relayPending()` a pega pelo seu nome antes do primeiro lote; quando outro relay a segura, a rodada não manda nada e o relatório diz isso (`isLockDenied()`). Ele renova a trava antes de cada lote seguinte, antes de ler as cabeças, e para quando a renovação falha (`isLockLost()`: o prazo dele venceu e outro relay assumiu). Ele libera a trava no fim, também quando a rodada lança.
- **O nome.** Um armazém de travas serve a todos os despachantes da aplicação: o relay, o despachante dos eventos de domínio ([exemplo 18.2](../events/from-specifications.md)) e o dos efeitos da máquina de estados ([exemplo 14.8](../state-machine/index.md)) usam o mesmo `PdoLeaseLock`, cada um com o seu nome, e duas outboxes nunca seguram uma à outra. O nome padrão é o tipo do despachante mais a tabela da sua outbox (`messaging.outbox_relay:message_outbox`; `getLockName()`); todos os processos de relay de uma outbox usam o mesmo, e `lockName:` dá outro.
- **O prazo** (`leaseSeconds`, 60 por padrão) é quanto tempo um relay que morreu segura os outros. Escolha um prazo maior que o lote mais lento, contando o tempo que o transporte pode levar para responder, e maior que a diferença entre os relógios das máquinas (cada relay compara os seus próprios instantes). Um relay pausado por mais tempo que o prazo pode voltar enquanto outro trabalha: o que ele já tinha mandado pode chegar duas vezes ao broker (continua pelo menos uma vez), e uma marca de falha nunca volta uma entrada entregue para falha, então uma mensagem entregue não é mandada de novo.
- **O dono** (`lockOwner`) identifica o relay na tabela; sem ele, cada instância recebe um ULID novo. Dê a cada processo o seu (o host e o pid). Donos e nomes de trava que diferem só por maiúsculas são o mesmo no MySQL e no MariaDB.
- **A conexão** pode ser a da outbox. Pegue a trava fora de uma transação longa, para os outros relays a verem na hora (no MySQL e no MariaDB o `UPDATE` condicional de uma transação também segura um gap lock que faz outro relay esperar o commit dela); se a conexão estiver numa transação mesmo assim, a primeira aquisição insere a linha dentro de um savepoint, e uma corrida perdida não aborta a transação de quem chamou (no PostgreSQL, um comando que falha abortaria).
- **A aquisição é portável**, sem `SKIP LOCKED`: um `UPDATE` condicional da linha da trava (livre, com o prazo vencido ou já de quem chama) e, quando não há linha, um `INSERT` protegido pela chave primária. O banco serializa dois deles, no SQLite, no PostgreSQL, no MySQL e no MariaDB.

### 26.5. Retenção da Outbox

A outbox guarda toda entrada, entregue ou não. `PdoMessageOutbox` e `InMemoryMessageOutbox` implementam `IMessageOutboxRetention`: `purgeDeliveredBefore($before, $limit = 1000)` remove no máximo `$limit` entradas entregues cuja entrega é anterior a `$before`, a entrega mais antiga primeiro, e devolve quantas removeu. Uma interface à parte, porque um método acrescentado a `IMessageOutbox` quebraria as implementações de fora da biblioteca.

```php
use Antevemus\ASpecification\Messaging\Outbox\IMessageOutboxRetention;

// Um job diário: as entradas entregues mais antigas que a janela da outbox, 1 000 por vez
$purge = function (IMessageOutboxRetention $outbox, DateTimeImmutable $before): int {
    $total = 0;
    do {
        $purged = $outbox->purgeDeliveredBefore($before, 1000);
        $total += $purged;
    } while ($purged === 1000);

    return $total;
};

$purged = $purge(new PdoMessageOutbox($pdo), new DateTimeImmutable('-7 days')); // 2: as entregas do ano passado
$left = (new PdoMessageOutbox($pdo))->count();                                // 1: a mensagem pendente fica

// A inbox de cada consumidor guarda as linhas por mais tempo: a janela da outbox mais o maior atraso do relay
(new PdoProcessedMessageStore($pdo))->purgeProcessedBefore('agents', new DateTimeImmutable('-8 days'));
```

- **Só entradas entregues.** Uma entrada pendente ou com falha, esgotada ou não, nunca é removida: é uma mensagem que não chegou ao broker, uma mensagem morta do lado do produtor, guardada para investigação.
- **Em lotes, de forma portável.** Os ids de um lote são lidos com o limite de linhas do dialeto e apagados por lista, 500 por `DELETE` (o MySQL recusa `LIMIT` dentro de uma subconsulta `IN`, e builds antigos do SQLite aceitam no máximo 999 parâmetros), então uma chamada nunca vira um `DELETE` gigante. A busca usa o índice `uq_mo_delivered` (`status`, `delivered_at`, `entry_id`) do DDL de referência; para uma tabela criada antes da v2.16.0, o cabeçalho do DDL traz o `CREATE UNIQUE INDEX` dele, opcional: a remoção é correta sem ele, só mais lenta numa outbox grande.
- **As janelas.** `enqueue()` pula um id de mensagem só enquanto a linha dele existe: depois da remoção, um produtor que grave o mesmo id de novo publica a mensagem de novo. Então:
  - a janela da outbox precisa ser maior que qualquer nova tentativa de um produtor que possa gravar a mesma mensagem de novo (um evento de domínio reentregue pelo seu despachante, um job repetido pela sua fila);
  - a retenção da inbox de cada consumidor (`purgeProcessedBefore()`) precisa ser pelo menos a janela da outbox mais o maior atraso do relay, para que uma cópia mandada com atraso ainda encontre a sua linha.

**DDL de referência:** `resources/sql/messaging.ansi.sql` (`message_outbox`, `message_inbox`, `CREATE TABLE IF NOT EXISTS`, portável para SQLite, PostgreSQL, MySQL e MariaDB; no MySQL e no MariaDB as tabelas precisam de `utf8mb4`). Nada mais da biblioteca lê ou grava essas tabelas: um banco sem elas responde como na 2.13.0. A trava do relay fica em `resources/sql/lease_lock.ansi.sql` (`lease_lock`, desde a v2.16.0), necessária só com trava.

## Satélites de broker

O gancho não conhece broker nenhum: o relay chama `send()` e lê as confirmações que ele devolve, e o consumidor chama `receive()`, `ack()` e `reject()`; o núcleo nunca referencia um cliente de broker. Dois satélites implementam as duas interfaces, cada um testado contra um broker real na sua própria suíte, e nenhum dos dois precisa de extensão do PHP:

- [`antevemus/aspecification-redis-streams`](https://github.com/antevemus-it/Antevemus.ASpecificationRedisStreams) (`RedisStreamsTransport`, `RedisStreamsSource`, sobre o predis): um stream por nome de mensagem, um consumer group por consumidor, as entradas pendentes de um processo morto recuperadas com `XAUTOCLAIM`, um stream de mensagens mortas; nunca Pub/Sub. Precisa do Redis 7.0 ou mais novo.
- [`antevemus/aspecification-rabbitmq`](https://github.com/antevemus-it/Antevemus.ASpecificationRabbitMQ) (`RabbitMqTransport`, `RabbitMqSource`, sobre o php-amqplib): uma exchange do tipo topic com publisher confirms e a flag mandatory, uma fila quorum durável por consumidor com acknowledgements manuais e a contagem de entregas do broker, uma dead letter exchange.

Desde a v2.15.0 a bateria de contrato é pública e vem com o núcleo: `MessagingContractTestCase`, em `Antevemus\ASpecification\Messaging\Testing`, roda todos os cenários do gancho contra uma `IMessagingBench` sobre um broker, e o núcleo a roda contra a sua referência em memória e contra um satélite didático em SQLite (Módulo 49). Um satélite para outro broker (Kafka, SQS, Azure Service Bus, Google Cloud Pub/Sub, NATS JetStream) implementa `IMessageTransport` e `IMessageSource` e passa por essa bateria na sua própria suíte; não toca no núcleo nem nos dois satélites acima. [Escrevendo um satélite](writing-a-satellite.md) mostra como, item por item do contrato, com o mapeamento de cada item para as primitivas desses brokers e o satélite didático completo (exemplos 27 a 27.4).
