# Escrevendo um Satélite de Broker: a Bateria de Contrato e um Exemplo Completo

Um satélite liga o gancho de mensageria do núcleo (a [página de mensageria](index.md), exemplos 26 a 26.5) a um broker: implementa `IMessageTransport` (com o que o `OutboxRelay` envia) e `IMessageSource` (o que o `MessageConsumer` lê), num pacote próprio, e o núcleo continua sem cliente de broker nenhum. Desde a v2.15.0 o núcleo traz a **bateria de contrato** que essas duas interfaces precisam passar, em `Antevemus\ASpecification\Messaging\Testing`: o satélite estende `MessagingContractTestCase` na sua própria suíte do PHPUnit, responde com uma `IMessagingBench` sobre o seu broker e roda todos os cenários que o gancho promete. A bateria é o contrato da versão do núcleo que a traz.

Esta página percorre a bateria com um **satélite didático sobre tabelas do SQLite** (exemplos 27.1 a 27.4): um "broker" escrito em algumas dezenas de linhas de PDO, que passa a bateria inteira na própria suíte do núcleo (Módulo 49). Ele existe para mostrar o que cada método tem de fazer; **não é um broker para produção** (sem rede, um processo só, sem retenção, sem vazão).

## O que um satélite implementa, e o que ele nunca faz

- **`IMessageTransport::send(list<Message>): TransportSendResult`**: grava cada mensagem de forma durável no broker, o corpo sendo `Message::toJson()` e o tenant guardado num header ou propriedade ao lado, e responde mensagem por mensagem se o broker **confirmou** (gravou de forma durável), com o id do broker quando ele dá um, ou por que falhou. Um envio que não alcança o broker lança exceção, e o lote inteiro falha. Um lote tem no máximo uma mensagem por chave de ordem, então o transporte não precisa de ordem própria.
- **`IMessageSource`**: `receive($max, $waitMs)` devolve até `$max` entregas (`ReceivedMessage`), esperando no máximo `$waitMs` pela primeira; uma entrega fica em voo até `ack()` (removida de vez) ou `reject($requeue, $reason)` (volta para a fila, ou vai para a fila de mensagens mortas com o motivo).
- **Uma `IMessagingBench`** para a bateria (exemplo 27.3): um mundo próprio por instância, e as operações de uma implantação real que a bateria precisa provocar (conexão perdida, consumidor morto, processo reiniciado, corpo escrito por outro produtor, a fila de mensagens mortas lida de volta).

Nunca:

- **marcar uma mensagem como confirmada antes de o broker gravá-la** (um XADD respondido, um publisher confirm, um HTTP 200 cujo corpo lista a entrada como bem-sucedida): o relay marca entregue o que você confirma, e uma mensagem confirmada que o broker perdeu está perdida de vez;
- **descartar o que o broker entregou e não é mensagem** (outro produtor, um corpo truncado, um formato de outra versão): passe para `ReceivedMessage::decode()`, que transforma isso numa entrega inválida que o consumidor manda para as mensagens mortas com um motivo;
- **colocar um valor do payload num motivo ou na mensagem de uma exceção**: nomeie a mensagem (`Message::describe()`, `ReceivedMessage::describe()`), o broker e a causa; a mensagem de um driver ou de um SDK pode citar o payload, então embrulhe e guarde o código dela, não o texto;
- **confirmar no broker por conta própria** (auto-ack, receive-and-delete): só `ack()` e `reject()` respondem uma entrega, depois que o consumidor fez o commit ou decidiu.

## O contrato, item por item

Cada linha é uma promessa de `IMessageTransport` ou `IMessageSource` e os cenários da bateria que a verificam. O que a bateria compara é o que o contrato promete: a ordem **dentro** de uma chave de ordem, e o multiconjunto do resto. A ordem entre duas chaves não faz parte do contrato (uma partição do Kafka, um grupo de mensagens do SQS, um stream por nome de mensagem entregam as chaves intercaladas de qualquer jeito), então a bateria nunca a afirma sobre entregas.

| Item do contrato | O que a bateria verifica |
|---|---|
| Confirmação por mensagem; só o que foi gravado é confirmado | a ida e volta; a conexão perdida (nada gravado, toda mensagem falha com um motivo, enviada de novo quando o broker volta) |
| A mensagem sai como entrou (id, nome, instante ao microssegundo, chave, tenant, headers, payload de até 65 535 bytes) | a ida e volta; o maior payload, byte a byte |
| Todo consumidor recebe toda mensagem; um consumidor recebe só as suas | todo consumidor recebe toda mensagem; cada bancada é um mundo próprio |
| A ordem de uma chave de ordem, entre nomes de mensagem e entre lotes do relay | a ordem entre nomes; entre lotes; lotes de chaves misturadas; um lote de muitas chaves |
| Uma entrega rejeitada com requeue volta **antes** de qualquer entrega posterior da sua chave | devolvida numa rodada; com entregas deixadas para trás; uma entrega por vez |
| A contagem de entregas sobe a cada entrega; o consumidor manda para as mortas depois de `maxDeliveries` | a mensagem que continua falhando (`retried 1`, `retried 2`, `dead_lettered 3`) |
| `reject($requeue = false)` guarda a mensagem **com o motivo** numa fila de mensagens mortas que a bancada consegue ler | a mensagem morta depois de `maxDeliveries`; os corpos inválidos |
| O que não é mensagem é uma entrega inválida, nunca descartada | um corpo que não é mensagem (`not json at all`, um documento de formato 2) |
| As entregas em voo de um consumidor morto vão para o sucessor; um processo reiniciado recebe de volta o que deixou em voo; a contagem sobe | o consumidor morto e o sucessor (duas vezes); o processo reiniciado |
| Pelo menos uma vez: cópias gravadas pelo broker, um relay que morre entre a confirmação e a marca | a duplicata; o relay que morre (duas vezes), cada uma absorvida pela inbox, o efeito uma vez |
| Responder uma entrega que não está em voo lança `MessagingException`; nenhum motivo, relatório ou exceção carrega o payload | o cenário de sigilo (um segredo plantado em todo payload, por todos os caminhos) |
| Tudo isso sob intercalações aleatórias | a sequência de propriedade: seis sementes fixas, pelo menos 150 passos cada, de publicações, relays que morrem ou perdem a conexão, consumidores que morrem antes de uma confirmação, handlers que falham uma vez, duas ou sempre, e cópias gravadas pelo broker; no fim nenhuma mensagem confirmada perdida, nenhum efeito duas vezes, a ordem de toda chave mantida e a fila de mensagens mortas igual ao que os relatórios mandaram para lá |

Todo cenário é obrigatório: não há flag de capacidade, a bateria nunca pula nada, e todo cenário é um método `final`, então um satélite não consegue sobrescrever um deles para afrouxá-lo. Um broker que não consegue manter um desses itens não está conforme com o gancho. A bateria também espera de um receive que, dentro da espera que a bancada declara (`receiveWaitMs()`), ele devolva toda entrega disponível, até o seu máximo: um satélite cujo cliente devolve páginas parciais repete até ter `$max` entregas ou uma página vir vazia.

## Mapeando o contrato para brokers

Os dois satélites que existem, e orientação para outros. Nenhum dos brokers abaixo dos dois primeiros tem pacote deste projeto, e nenhum é prometido; as notas dizem o que a documentação do broker afirma e, onde isso não foi verificado, o que conferir antes de escrever o satélite.

| Broker | Chave de ordem | Confirmação por mensagem | Em voo | Contagem de entregas | Devolvida antes das posteriores da chave | Mensagem morta com o motivo | Consumidor morto | Cliente PHP |
|---|---|---|---|---|---|---|---|---|
| [Redis Streams](https://github.com/antevemus-it/Antevemus.ASpecificationRedisStreams) (existe, v1.0.0) | um stream por nome de mensagem; a ordem dos ids das entradas | a resposta do `XADD` | a lista de entradas pendentes do consumer group | o contador de entregas da entrada pendente | as entradas pendentes do consumidor são lidas antes das novas | um stream de mensagens mortas, o motivo num campo | `XAUTOCLAIM` depois de um tempo ocioso | predis, PHP puro |
| [RabbitMQ](https://github.com/antevemus-it/Antevemus.ASpecificationRabbitMQ) (existe, v1.0.0) | uma fila quorum por consumidor | publisher confirms (`basic.ack`), a flag mandatory para uma mensagem sem rota | entregas sem acknowledgement de um canal com acknowledgements manuais | a contagem de entregas da fila quorum | uma mensagem devolvida mantém o seu lugar | uma dead letter exchange, o motivo num header | o broker devolve à fila o que um canal fechado segurava | php-amqplib, PHP puro |
| AWS SQS, **fila FIFO** | `MessageGroupId` | `SendMessageBatch` (até 10 entradas) informa cada entrada como bem-sucedida ou falha | o visibility timeout; `DeleteMessage` confirma | `ApproximateReceiveCount` | num grupo, nada posterior é devolvido enquanto uma mensagem está em voo; devolvê-la (visibilidade 0, conferir) a mantém primeiro | a redrive policy move uma mensagem depois de `maxReceiveCount` recebimentos; a documentação dela não descreve motivo (conferir): envie você mesmo para a fila de mensagens mortas, com o motivo, e depois apague | o visibility timeout expira e a mensagem é recebida de novo | AWS SDK for PHP (aws/aws-sdk-php) |
| AWS SQS, **fila Standard** | nenhuma | | | | | | | **não conforme**: sem ordem |
| Apache Kafka | a chave escolhe a partição; ordem por partição | um delivery report por mensagem (`acks=all`, produtor idempotente) | offsets não commitados das partições atribuídas ao consumidor | nenhuma nativa: mantenha você mesmo | offsets são commitados por partição, em ordem: devolver significa voltar o offset (seek) e pausar a partição | um tópico de mensagens mortas para o qual o satélite produz, o motivo num header | um rebalance do consumer group entrega a partição, a partir do último offset commitado, a outro membro | a extensão `rdkafka` (php-rdkafka, sobre o librdkafka) |
| Azure Service Bus | sessões (`SessionId`, o group-id do AMQP) numa fila ou assinatura com sessões | a operação de envio; conferir a resposta por mensagem de um lote | peek-lock; `Complete` remove | `DeliveryCount`; `MaxDeliveryCount` (padrão 10) move para a fila de mensagens mortas como `MaxDeliveryCountExceeded` | numa sessão, uma mensagem abandonada é servida de novo pelo próximo receive | `DeadLetter` para `<queue>/$deadletterqueue` com `DeadLetterReason` e `DeadLetterErrorDescription` | um lock expirado reentrega, a contagem sobe | conferir: o protocolo nativo é AMQP 1.0, que o php-amqplib (AMQP 0-9-1) não fala; há uma API REST por HTTP |
| Google Cloud Pub/Sub | ordering keys, com a ordenação de mensagens ligada na assinatura | o resultado da publicação de cada mensagem (conferir) | o prazo de acknowledgement | `delivery_attempt`, só quando a assinatura tem um tópico de mensagens mortas | uma reentrega de uma mensagem reentrega toda mensagem posterior da sua chave, mesmo as confirmadas | um tópico de mensagens mortas (5 a 100 tentativas); a mensagem encaminhada leva contagens, não um motivo: publique você mesmo com o motivo | um prazo de acknowledgement expirado reentrega | google/cloud-pubsub (conferir o transporte: REST ou a extensão gRPC) |
| NATS JetStream | um subject por chave, ou a ordem do stream (conferir) | o acknowledgement de publicação do JetStream (conferir) | um pull consumer com acknowledgement explícito; `AckWait` | a contagem de entregas nos metadados da mensagem (conferir o nome no cliente) | `Nak` reentrega, na hora ou depois de um atraso; conferir onde ela vem em relação às mensagens posteriores (`MaxAckPending`) | sem fila de mensagens mortas embutida: `Term` para a reentrega e um advisory é publicado; o satélite publica a mensagem morta com o motivo por conta própria | `AckWait` expira e a mensagem é reentregue | conferir: basis-company/nats (PHP) é o cliente mais usado |

Os pontos difíceis, broker por broker:

- **SQS**: só a fila FIFO mantém ordem, por grupo de mensagens; a fila Standard pode entregar fora de ordem e não está conforme. Uma fila FIFO deduplica um `MessageDeduplicationId` repetido dentro de 5 minutos: a bateria envia cópias de propósito (quem deduplica é a inbox, não o broker), então um id de deduplicação por tentativa de envio mantém esses cenários honestos, e confira o que isso custa em produção. Um short poll pode devolver menos mensagens do que existem, ou nenhuma: use long polling (`WaitTimeSeconds`) e declare isso em `receiveWaitMs()`. Para o motivo da mensagem morta, um message attribute na cópia que você envia para a fila de mensagens mortas.
- **Kafka**: o reject por mensagem é a parte difícil, porque um consumidor commita offsets, não mensagens. O satélite mantém em memória as entregas em voo de uma partição, volta o offset até uma devolvida (e pausa a partição para que nada posterior da sua chave vá primeiro), conta as entregas por conta própria (um header ou um armazenamento ao lado, já que o Kafka não tem) e produz as mensagens mortas num tópico. O único cliente PHP estabelecido é uma extensão.
- **Azure Service Bus**: a ordem precisa de sessões, e uma sessão fica travada por um receptor de cada vez; confira se a API REST cobre sessões, e qual cliente PHP existe hoje, antes de escolher entre AMQP 1.0 e HTTP.
- **Google Cloud Pub/Sub**: com ordering keys, uma reentrega traz de volta toda mensagem posterior da chave, inclusive as confirmadas; a inbox as absorve, mas a bateria as veria como entregas `duplicate` a mais, então confira como um satélite mantém exata a transcrição de uma chave. `delivery_attempt` só existe com um tópico de mensagens mortas, e o mínimo de 5 tentativas precisa ficar acima do `maxDeliveries` dos consumidores, para que quem decide a mensagem morta seja o consumidor, não o Pub/Sub.
- **NATS JetStream**: confira como um `Nak` é ordenado em relação às mensagens posteriores do subject quando há várias pendentes (`MaxAckPending`), e como uma mensagem morta é guardada com o motivo, já que o JetStream não tem uma embutida.

### 27. Rodando a Bateria Primeiro Contra a Referência (Módulo 49)

Antes de ligar o broker, rode a bateria contra a referência em memória do núcleo: uma rodada verde prova que a suíte de testes do satélite está ligada (PHPUnit, autoloader, núcleo), e a primeira rodada vermelha contra o broker é sobre o broker.

```php
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\InMemoryMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;

final class InMemoryContractTest extends MessagingContractTestCase
{
    protected function createBench(): IMessagingBench
    {
        return new InMemoryMessagingBench();
    }
}
```

- **`MessagingContractTestCase`** é um `TestCase` abstrato do PHPUnit com 22 cenários (um deles, a sequência de propriedade, roda uma vez por semente: 27 testes). Sobrescreva `createBench()`; sobrescreva `propertySteps()` para rodar mais passos da sequência de propriedade (nunca menos que `MIN_PROPERTY_STEPS`, 150: o cenário reprova); uma subclasse que sobrescreve `setUp()` ou `tearDown()` chama o pai. As sementes (`PROPERTY_SEEDS`) e o mínimo são constantes finais.
- **`InMemoryMessagingBench`** é a bancada do núcleo: `InMemoryMessageTransport` e `InMemoryMessageSource`, sem mudança.
- **O PHPUnit** é um `suggest` do núcleo, não um `require`: uma aplicação que nunca roda a bateria nunca o instala, e nada mais da biblioteca carrega essas classes.

### 27.1. O Transporte

O broker didático são três tabelas: o log de entradas, o cursor de cada consumidor e as entregas de cada consumidor. O transporte grava o lote numa transação e só confirma depois do commit.

```php
use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use PDO;
use PDOException;

/**
 * DIDACTIC: a "broker" over SQLite tables, to show what a transport does. Not for production.
 */
final class SqliteMessageTransport implements IMessageTransport
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** The tables of the broker: the log, the cursor of each consumer, the deliveries of each consumer. */
    public static function declareTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_entry (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, tenant TEXT NULL, body TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_consumer (consumer TEXT PRIMARY KEY, cursor INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_delivery (consumer TEXT NOT NULL, entry_id INTEGER NOT NULL, state TEXT NOT NULL, member TEXT NULL, delivery_count INTEGER NOT NULL, reason TEXT NULL, dead_seq INTEGER NULL, PRIMARY KEY (consumer, entry_id))');
    }

    public function send(array $batch): TransportSendResult
    {
        $stored = [];
        try {
            $this->pdo->beginTransaction();
            $insert = $this->pdo->prepare('INSERT INTO broker_entry (name, tenant, body) VALUES (?, ?, ?)');
            foreach ($batch as $message) {
                // The body is the wire form; the tenant travels beside it, where a consumer could filter on it.
                $insert->execute([$message->name, $message->tenant, $message->toJson()]);
                $stored[$message->messageId] = (string) $this->pdo->lastInsertId();
            }
            $this->pdo->commit(); // stored durably: now, and only now, the batch is confirmed
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // The message of a driver may quote a value: name the cause by its code, never the payload.
            throw new MessagingException(sprintf('The SQLite broker did not store the batch of %d message(s) (SQLSTATE %s).', count($batch), $e->getCode()));
        }
        $result = new TransportSendResult();
        foreach ($stored as $messageId => $brokerId) {
            $result = $result->withConfirmed($messageId, $brokerId);
        }

        return $result;
    }
}
```

- **A confirmação** vem depois do `commit()`: um lote cujo commit falhou não confirma nada, e a exceção que ele lança nomeia o lote e o SQLSTATE, nunca o texto do driver.
- **O id do broker** é o id da entrada (`TransportSendResult::withConfirmed($messageId, $brokerId)`); um broker sem id confirma com `null`.

### 27.2. A Fonte

Uma fonte é um membro de um consumidor. Ela entrega primeiro o que foi devolvido (com requeue, ou deixado em voo por um membro morto), na ordem gravada, e depois o que o consumidor ainda não viu, a partir do seu cursor; todo corpo passa por `ReceivedMessage::decode()`.

```php
use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use PDO;
use PDOStatement;
use Throwable;

/**
 * DIDACTIC: one member of one consumer reading the SQLite "broker" of SqliteMessageTransport.
 */
final class SqliteMessageSource implements IMessageSource
{
    /** @param list<string> $names The message names the consumer reads */
    public function __construct(
        private readonly PDO $pdo,
        public readonly string $consumer,
        public readonly array $names,
        public readonly string $member
    ) {
        // Declares the consumer: it reads what is stored from now on (an existing consumer keeps its cursor).
        $this->execute('INSERT OR IGNORE INTO broker_consumer (consumer, cursor) VALUES (?, (SELECT COALESCE(MAX(id), 0) FROM broker_entry))', [$consumer]);
        // A process that starts again gets back what its previous run left in flight.
        $this->recoverFrom($member);
    }

    public function receive(int $max, int $waitMs): array
    {
        $deadline = microtime(true) + $waitMs / 1000;
        while (true) {
            $deliveries = $this->take($max);
            if ($deliveries !== [] || microtime(true) >= $deadline) {
                return $deliveries;
            }
            usleep(10000);
        }
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->answer($message, 'DELETE FROM broker_delivery', []);
    }

    public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
    {
        if ($requeue) {
            $this->answer($message, "UPDATE broker_delivery SET state = 'ready', member = NULL", []);

            return;
        }
        // The dead letter queue keeps the reason and the count; the reason never carries the payload.
        $this->answer($message, "UPDATE broker_delivery SET state = 'dead', member = NULL, reason = ?, dead_seq = (SELECT COALESCE(MAX(dead_seq), 0) + 1 FROM broker_delivery)", [$reason]);
    }

    /** What member $member of this consumer left in flight goes back to the queue (XAUTOCLAIM, a closed channel). */
    public function recoverFrom(string $member): int
    {
        return $this->execute("UPDATE broker_delivery SET state = 'ready', member = NULL WHERE consumer = ? AND state = 'in_flight' AND member = ?", [$this->consumer, $member])->rowCount();
    }

    /** @return list<array{messageId: ?string, reason: string, deliveryCount: int}> */
    public function deadLetters(): array
    {
        $rows = $this->execute("SELECT e.id, e.body, d.reason, d.delivery_count FROM broker_delivery d JOIN broker_entry e ON e.id = d.entry_id WHERE d.consumer = ? AND d.state = 'dead' ORDER BY d.dead_seq", [$this->consumer]);
        $dead = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delivery = ReceivedMessage::decode((string) $row['body'], (int) $row['delivery_count'], (string) $row['id']);
            $dead[] = ['messageId' => $delivery->isValid() ? $delivery->message()->messageId : null, 'reason' => (string) $row['reason'], 'deliveryCount' => (int) $row['delivery_count']];
        }

        return $dead;
    }

    /** @return list<ReceivedMessage> */
    private function take(int $max): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. What was given back (rejected with requeue, left by a dead member) comes first, in the order stored.
            $ids = $this->execute("SELECT entry_id FROM broker_delivery WHERE consumer = ? AND state = 'ready' ORDER BY entry_id LIMIT {$max}", [$this->consumer])->fetchAll(PDO::FETCH_COLUMN);
            // 2. Then what this consumer has not seen yet, from its cursor on.
            if (count($ids) < $max) {
                $cursor = (int) $this->execute('SELECT cursor FROM broker_consumer WHERE consumer = ?', [$this->consumer])->fetchColumn();
                $names = implode(', ', array_fill(0, count($this->names), '?'));
                $fresh = $this->execute("SELECT id FROM broker_entry WHERE id > ? AND name IN ({$names}) ORDER BY id LIMIT " . ($max - count($ids)), [$cursor, ...$this->names])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($fresh as $id) {
                    $this->execute("INSERT INTO broker_delivery (consumer, entry_id, state, delivery_count) VALUES (?, ?, 'ready', 0)", [$this->consumer, $id]);
                    $this->execute('UPDATE broker_consumer SET cursor = ? WHERE consumer = ?', [$id, $this->consumer]);
                }
                $ids = [...$ids, ...$fresh];
            }
            $deliveries = [];
            foreach ($ids as $id) {
                $this->execute("UPDATE broker_delivery SET state = 'in_flight', member = ?, delivery_count = delivery_count + 1 WHERE consumer = ? AND entry_id = ?", [$this->member, $this->consumer, $id]);
                $row = $this->execute('SELECT e.body, d.delivery_count FROM broker_entry e JOIN broker_delivery d ON d.entry_id = e.id WHERE d.consumer = ? AND e.id = ?', [$this->consumer, $id])->fetch(PDO::FETCH_ASSOC);
                // Whatever the body is, it is delivered: decode() turns what is not a message into an invalid delivery.
                $deliveries[] = ReceivedMessage::decode((string) $row['body'], (int) $row['delivery_count'], (string) $id);
            }
            $this->pdo->commit();

            return $deliveries;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Answers a delivery this member holds in flight; anything else is a bug of the consumer, made visible. */
    private function answer(ReceivedMessage $message, string $statement, array $values): void
    {
        $id = ctype_digit($message->brokerId) ? (int) $message->brokerId : 0;
        $answered = $this->execute("{$statement} WHERE consumer = ? AND entry_id = ? AND state = 'in_flight' AND member = ?", [...$values, $this->consumer, $id, $this->member])->rowCount();
        if ($answered !== 1) {
            throw new MessagingException(sprintf('Delivery %s is not in flight on member "%s" of consumer "%s": it was already answered, or it came from another one.', $message->brokerId, $this->member, $this->consumer));
        }
    }

    private function execute(string $sql, array $values): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement;
    }
}
```

- **A ordem de uma chave** vem de ler o que foi devolvido antes de qualquer coisa nova: uma entrega devolvida com requeue vem de novo antes de toda entrega posterior da sua chave, como as entradas pendentes de um consumer group do Redis ou uma mensagem devolvida do RabbitMQ.
- **Em voo** é uma linha com o membro que a segura; `ack()` e `reject()` respondem só uma entrega que este membro segura em voo, e qualquer outra coisa lança `MessagingException` (um consumidor que responde duas vezes é um bug que a bateria torna visível).
- **Recuperação**: um processo que reinicia recupera as suas próprias entregas em voo (o construtor); um sucessor recupera as do membro morto (`recoverFrom()`). Como um broker real faz isso varia (um tempo ocioso, um canal fechado, um visibility timeout, um rebalance): a bancada do exemplo 27.3 é onde o satélite provoca isso.

### 27.3. A Bancada

A bancada dá à bateria um broker próprio por instância e as operações que ela provoca: conexão perdida, sucessor, processo reiniciado, corpo cru, a fila de mensagens mortas lida de volta.

```php
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use LogicException;
use PDO;

/**
 * DIDACTIC: the bench of the SQLite "broker" under the contract battery of the core.
 */
final class SqliteMessagingBench implements IMessagingBench
{
    private readonly PDO $pdo;

    public function __construct()
    {
        // A database in memory per bench: every scenario gets a broker of its own (isolation).
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        SqliteMessageTransport::declareTables($this->pdo);
    }

    public function transport(): IMessageTransport
    {
        return new SqliteMessageTransport($this->pdo);
    }

    public function transportWithLostConnection(): IMessageTransport
    {
        // A database without the tables of the broker: every send fails, and nothing is stored.
        return new SqliteMessageTransport(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    }

    public function source(string $consumer, array $names, string $member = 'm1'): IMessageSource
    {
        return new SqliteMessageSource($this->pdo, $consumer, $names, $member);
    }

    public function successor(IMessageSource $source): IMessageSource
    {
        $dead = self::own($source);
        $next = new SqliteMessageSource($this->pdo, $dead->consumer, $dead->names, $dead->member . '-next');
        $next->recoverFrom($dead->member); // Redis: XAUTOCLAIM after an idle time; RabbitMQ: the closed channel

        return $next;
    }

    public function restarted(IMessageSource $source): IMessageSource
    {
        $old = self::own($source);

        return new SqliteMessageSource($this->pdo, $old->consumer, $old->names, $old->member); // recovers on start
    }

    public function storeRaw(string $name, string $body): void
    {
        $this->pdo->prepare('INSERT INTO broker_entry (name, tenant, body) VALUES (?, NULL, ?)')->execute([$name, $body]);
    }

    public function deadLetters(IMessageSource $source): array
    {
        return self::own($source)->deadLetters();
    }

    public function receiveWaitMs(): int
    {
        return 0; // what send() stored is readable at once
    }

    public function close(): void
    {
        // The database lives in memory and goes away with the bench.
    }

    private static function own(IMessageSource $source): SqliteMessageSource
    {
        return $source instanceof SqliteMessageSource ? $source : throw new LogicException('Not a source of this bench.');
    }
}
```

- **Isolamento**: um banco em memória por bancada. Contra um broker real, use um prefixo único de nomes por instância (streams, filas, tópicos, assinaturas) e remova tudo em `close()`; a bateria verifica que duas bancadas vivas ao mesmo tempo não veem as mensagens uma da outra.
- **`successor()` e `restarted()`** podem levar tempo contra um broker real (esperar um tempo ocioso, cortar o socket e esperar o broker devolver à fila): esse tempo é da bancada, nunca da bateria.
- **`receiveWaitMs()`** é a maior espera que a bateria passa para `receive()` e `consume()`: 0 quando o que o `send()` gravou pode ser lido na hora, o atraso de visibilidade do broker caso contrário.

### 27.4. Ligando a Bateria na Suíte do Satélite

```php
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;

final class SqliteContractTest extends MessagingContractTestCase
{
    protected function createBench(): IMessagingBench
    {
        return new SqliteMessagingBench();
    }

    protected function propertySteps(): int
    {
        return 300; // more than the minimum of the battery (150); fewer fails the scenario
    }
}
```

- **Uma classe por bancada**: a em memória do exemplo 27 e esta, lado a lado na suíte do satélite. As duas rodam no núcleo também (Módulo 49, por `tests/run_all.php` e pelo PHPUnit), o que mantém esta página e a bateria em sincronia.
- **Os testes específicos do broker** (topologia, opções de conexão, falhas próprias) ficam nas classes do próprio satélite, ao lado da bateria.

## Integração contínua com um broker real

Rode a bateria contra o broker num service container, a cada push, e faça de um broker inalcançável uma falha ali: a bateria nunca pula nada, e a bancada do satélite também não deveria, fora da máquina de quem desenvolve.

```yaml
# .github/workflows/tests.yml (an example with Redis; any broker with a container image works the same way)
jobs:
  contract:
    runs-on: ubuntu-latest
    services:
      broker:
        image: redis:7
        ports: ["6379:6379"]
        options: >-
          --health-cmd "redis-cli ping" --health-interval 2s --health-timeout 2s --health-retries 15
    env:
      BROKER_URL: tcp://127.0.0.1:6379
      BROKER_REQUIRED: "1"
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
      - run: composer install --no-progress
      - run: vendor/bin/phpunit
```

Para um serviço gerenciado sem imagem de contêiner (SQS, Service Bus, Pub/Sub), use o emulador que o fornecedor oferece, ou uma conta para a CI com nomes únicos por execução, removidos em `close()`.

## Empacotamento

```json
{
    "name": "vendor/aspecification-broker",
    "description": "IMessageTransport and IMessageSource of antevemus/aspecification over Broker",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "antevemus/aspecification": "^2.15",
        "vendor/broker-client": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "suggest": {
        "ext-broker": "a faster client, when the broker offers one"
    }
}
```

- **O nome** é `vendor/aspecification-<broker>`, no seu próprio vendor: um satélite não toca no núcleo nem nos outros satélites.
- **A licença** é escolha sua; o núcleo é MIT.
- **`antevemus/aspecification: ^2.15`** é a primeira versão que traz a bateria.
- **Um cliente em PHP puro** quando o broker tem um bom (predis, php-amqplib): nada para compilar no servidor da aplicação. Quando o único cliente bom é uma extensão (Kafka), diga isso no README e exija a extensão (`ext-rdkafka`).
- **`suggest`** no próprio satélite, para o que é opcional (uma extensão mais rápida, um logger PSR-3).

## Versionamento

A bateria mora no núcleo e acompanha a versão dele. Um cenário novo só verifica algo que o contrato já prometia (uma MINOR nunca muda o contrato de um satélite, e uma MAJOR do núcleo nunca quebra a API que um módulo usa), então um satélite que reprova num cenário novo já não estava conforme naquele item. O satélite adota os cenários de um núcleo mais novo subindo o seu piso (`^2.15` para `^2.16`); rode a CI dele também contra o núcleo mais novo que a restrição permite (uma execução agendada com `composer update`), para que um cenário novo apareça ali antes de aparecer para um usuário.
