# Adotando a Máquina num Sistema em Produção

### 24. Adotando a Máquina num Sistema em Produção: Importação, Contexto do Efeito, Painel de Ações e Lotes (Módulo 45)

Desde a v2.10.0 o runner do [exemplo 14](index.md) cobre o que uma aplicação encontra ao levar um fluxo vivo para a máquina: **processos que já existem** entram onde estão, os **handlers de efeito** leem o motivo e o ator do evento que os gerou, um **painel de ações** separa "informe o dado antes" de "não é com você" e mostra um só motivo quando várias faixas de alçada recusam, e **arquivos de milhares de linhas** são enviados em blocos. Tudo é aditivo: todo resultado da 2.9.0 mantém a forma, `send()` não muda, um handler com a assinatura de três parâmetros continua funcionando e uma tabela criada pelo DDL da 2.9.0 continua funcionando sem a coluna nova.

#### 24.1 Importando Processos em Andamento

`startAt()` cria uma instância já nos estados que ela tem no outro sistema, com uma linha de histórico por região do tipo reservado `machine.import` (com o ator e o payload da importação), **sem entry action, sem guard e sem efeito**: os estados são um fato, não uma transição. Uma região omitida começa no estado inicial; um estado que a região não declara, ou uma região desconhecida, é recusado (`InvalidSnapshotException`) antes de qualquer gravação. Os prazos dos estados dados contam a partir do instante da importação, exatamente como num início (uma fonte de prazo ausente recusa a importação e nada é gravado). Um estado final exige `allowFinal: true`; a instância nasce então concluída. O tenant vem por último, como em todas as outras chamadas.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

$claim = Spec::machine('claim', 1)
    ->region('progress', 'triage')
        ->state('triage')
            ->on('START_ANALYSIS', 'analysis')->action('notify_analyst')
        ->state('analysis')->deadline('P5D', 'ANALYSIS_OVERDUE')
            ->on('APPROVE', 'approved')
            ->on('CANCEL', 'cancelled')->guard('payloadHas', ['field' => 'motive'])->action('mail_cancellation')
            ->on('ANALYSIS_OVERDUE', 'late')
        ->state('late')
            ->on('APPROVE', 'approved')
        ->finalState('approved')
        ->finalState('cancelled')
    ->region('pending', 'none')
        ->state('none')->on('REGISTER_PENDING', 'open')
        ->state('open')->on('RESOLVE_PENDING', 'none')
    ->build();

$persistence = MachinePersistence::inMemory();
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), $persistence);
$at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

// Uma ocorrência do sistema antigo, já em análise, com uma pendência aberta
$runner->startAt('claim-4711', 'claim', null, ['progress' => 'analysis', 'pending' => 'open'], null, $at, actor: 'import', payload: ['legacy_id' => 4711]);
$instance = $runner->load('claim-4711');
$instance->snapshot->getStates();                                   // ['progress' => 'analysis', 'pending' => 'open']
$instance->snapshot->dueAt('progress')->format('Y-m-d');            // '2026-10-26': o prazo conta da importação
$persistence->history->findByInstance('claim-4711')[0]->eventType;  // 'machine.import'
count($persistence->outbox->findByInstance('claim-4711'));          // 0: nenhuma entry action rodou

$runner->startAt('claim-4712', 'claim', null, ['progress' => 'approved'], null, $at, allowFinal: true);
$runner->load('claim-4712')->snapshot->isDone();                    // true
```

- **`machine.import` é reservado**, como `machine.start` e `machine.migrate`: `send()` recusa um evento desse tipo, então uma linha de importação do histórico sempre vem de `startAt()`.
- **Um id já usado** é `MachineInstanceAlreadyExistsException`, como em `start()`: uma importação rodada duas vezes é detectada antes de gravar (derive o id da linha de origem).
- **O núcleo puro** dá o mesmo snapshot sem persistir: `StateMachine::snapshotAt($definition, $statesByRegion, $context, $at, $allowFinal)`.

#### 24.2 Handlers de Efeito que Leem o Evento

Toda entrada da outbox agora leva o evento do seu passo (`EventSnapshot`: tipo, payload, ator, origem, instante). Um handler que declara um quarto parâmetro, `EffectContext $context`, o recebe, junto com as versões da máquina e da instância do passo; o registro lê a assinatura uma vez, ao registrar o handler, e chama um handler de três parâmetros exatamente como antes. Handlers síncronos (rodados pelo runner) e assíncronos (rodados pelo `EffectDispatcher`) recebem o mesmo contexto.

```php
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use Antevemus\ASpecification\StateMachine\Runner\EffectContext;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;

$mails = [];
$handlers = (new EffectHandlerRegistry())
    // Um quarto parâmetro pede o contexto; os handlers de três parâmetros de antes continuam funcionando
    ->register('mail_cancellation', function (Effect $effect, string $key, string $instanceId, EffectContext $context) use (&$mails): void {
        $mails[] = sprintf('%s cancelled by %s: %s', $instanceId, $context->actor(), $context->payloadValue('motive'));
    })
    ->register('notify_analyst', function (Effect $effect, string $key, string $instanceId): void {
    });

$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), $persistence, $handlers);
$runner->send('claim-4711', Event::external('CANCEL', $at->modify('+1 hour'), ['motive' => 'Duplicate claim']), actor: 'ana')->isAccepted(); // true
(new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending()->deliveredCount(); // 1
$mails;                                                             // ['claim-4711 cancelled by ana: Duplicate claim']

// Sobre PDO, o evento fica na coluna opt-in da 2.10.0 (resources/sql/state_machine.ansi.sql)
$pdoPersistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_10);
```

- **A coluna é opt-in.** O DDL da 2.10.0 acrescenta `event_snapshot TEXT` a `state_machine_outbox`; a outbox PDO a lê e escreve só com `columns: PdoMachineSchema::COLUMNS_SINCE_2_10` (junte com `COLUMNS_SINCE_2_7` quando quiser as duas). Uma tabela existente a ganha com o único `ADD COLUMN` comentado no cabeçalho do DDL. Sem ela o handler vê `$context->hasEvent()` falso, `payload()` vazio e `actor()` e `occurredAt()` nulos; a outbox em memória sempre guarda o evento.
- **`EffectHandlerRegistry::invoke(EffectContext)`** é o que o runner e o dispatcher chamam; `takesContext($action)` diz qual forma o handler tem.
- **O início de uma instância** grava seus efeitos de entrada com o evento `machine.start`, payload vazio e o ator de `start()`.

#### 24.3 O Painel de Ações: "Informe o Dado" Versus "Não É com Você", e Um Motivo para Mostrar

`nextEvents()` testa cada evento com payload vazio, então um evento com guard `payloadHas` parecia recusado. Cada resultado listado agora traz `getCandidateReason()`: `ALLOWED`, `MISSING_PAYLOAD` (aceito quando o payload trouxer os campos de `getMissingPayload()`; mantenha o botão e peça os dados), `ROLE_REFUSED` (um guard marcado como guard de papel recusa quem chama em todas as candidatas de uma região; esconda) ou `GUARD_REFUSED` (qualquer outra recusa; mostre desabilitado com o motivo). O status e o motivo de cada resultado são os de `can()`, inalterados.

Quando várias transições atendem o mesmo evento (alçada por faixa) e todas recusam, o motivo lista as falhas de todas as candidatas. `getPrimaryReason()` é o que mostrar: a candidata que chegou mais perto (primeiro um guard que não pôde ser avaliado; depois as candidatas não recusadas por guard de papel nem só internas; depois a de menos falhas; depois a ordem de prioridade, a mais específica primeiro), e `getDetails()` guarda as demais, um resultado por candidata.

```php
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\StateMachine\CandidateReason;
use Antevemus\ASpecification\StateMachine\GuardExpression as G;
use Antevemus\ASpecification\StateMachine\TransitionCandidate;

$guards = Spec::guardRegistry()
    ->register('hasAnyRole', fn(array $p) => (new PredicateSpecification(
        fn(TransitionCandidate $c): bool => array_intersect($c->context['roles'] ?? [], $p['roles']) !== []
    ))->because('Your role cannot do this.')->withCode('ROLE_NOT_ALLOWED'))
    ->register('amountUpTo', fn(array $p) => Spec::property('context.amount', Spec::lessThanOrEqualTo($p['max']))
        ->because('The amount is above your approval limit.')->withCode('ABOVE_APPROVAL_LIMIT'))
    ->markRoleGuards('hasAnyRole');                                 // quem age: as recusas dele são "não é com você"

$approval = Spec::machine('approval', 1)
    ->region('flow', 'awaiting_approval')
        ->state('awaiting_approval')
            ->on('APPROVE', 'approved', 'approve_analyst')
                ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst']]), G::ref('amountUpTo', ['max' => 10000])))
            ->on('APPROVE', 'approved', 'approve_manager')
                ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['manager']]), G::ref('amountUpTo', ['max' => 50000])))
            ->on('APPROVE', 'approved', 'approve_board')->guard('hasAnyRole', ['roles' => ['board']])
            ->on('REJECT', 'rejected')
                ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst', 'manager']]), G::ref('payloadHas', ['field' => 'motive'])))
        ->finalState('approved')
        ->finalState('rejected')
    ->build();

$machine = Spec::stateMachine($guards);
$start = $machine->initialSnapshot($approval, null, $at)->snapshot;

$result = $machine->can($approval, $start, Event::external('APPROVE', $at), ['roles' => ['manager'], 'amount' => 90000]);
$result->getReason()->getCodes();           // ['ROLE_NOT_ALLOWED', 'ABOVE_APPROVAL_LIMIT', 'ABOVE_APPROVAL_LIMIT', 'ROLE_NOT_ALLOWED']
$result->getPrimaryReason()->getCodes();    // ['ABOVE_APPROVAL_LIMIT']: a faixa do próprio gerente
count($result->getDetails());               // 2: as outras duas candidatas

$panel = $machine->nextEvents($approval, $start, ['roles' => ['analyst'], 'amount' => 8000], $at);
$panel['APPROVE']->getCandidateReason();    // CandidateReason::ALLOWED
$panel['REJECT']->getCandidateReason();     // CandidateReason::MISSING_PAYLOAD: mostre o botão, peça o motivo
$panel['REJECT']->getMissingPayload();      // ['motive']
$panel['REJECT']->isRejected();             // true: status e motivo como can() os dá

$board = $machine->nextEvents($approval, $start, ['roles' => ['board'], 'amount' => 8000], $at);
$board['REJECT']->getCandidateReason();     // CandidateReason::ROLE_REFUSED: não cabe à diretoria
```

- **Marcar não muda nenhuma avaliação**: `GuardRegistry::markRoleGuards()` só diz à classificação e ao motivo principal quais guards conferem quem age. Sem marcas, nada é `ROLE_REFUSED` e o motivo principal ainda prefere a candidata com menos falhas.
- **"Requer" é lido do guard**: as folhas `payloadHas` que precisam valer (não as que estão sob um `not`) são supostas presentes; o evento é `MISSING_PAYLOAD` quando o resto do guard passa, e os campos que faltam vêm na ordem do guard.
- **Uma candidata** dá um motivo principal igual ao motivo e nenhum detalhe; um resultado aceito ou não tratado tem o próprio motivo como principal. Nenhum dos membros novos entra em `toArray()`.

#### 24.4 Arquivos de Milhares de Linhas: `sendMany()`

`sendMany()` faz o trabalho de um `send()` por evento, na ordem, com uma transação por bloco (100 eventos por padrão) em vez de uma por evento, e as chaves de idempotência de um bloco consultadas juntas (uma consulta `IN (...)` no store PDO). Responde um `BatchEventOutcome` por evento: o resultado que `send()` teria devolvido, ou a exceção que ele teria lançado só para aquele evento (instância desconhecida, versão mudou, veto de efeito síncrono), sem nada daquele evento gravado. O próprio `send()` não muda.

```php
use Antevemus\ASpecification\StateMachine\Runner\BatchEvent;

// $pdo: as tabelas de resources/sql/state_machine.ansi.sql; uma linha do arquivo por evento, chaves derivadas da linha
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), MachinePersistence::pdo($pdo));
$rows = [['id' => 'claim-1'], ['id' => 'claim-2'], ['id' => 'claim-3']];
foreach ($rows as $row) {
    $runner->startAt($row['id'], 'claim', null, ['progress' => 'triage'], null, $at, actor: 'import');
}
$events = array_map(
    fn(array $row): BatchEvent => new BatchEvent($row['id'], Event::external('START_ANALYSIS', $at, [], 'file-7:' . $row['id'] . ':START_ANALYSIS')),
    $rows
);
$outcomes = $runner->sendMany($events, actor: 'import', chunkSize: 100);  // uma transação, uma consulta de idempotência
count(array_filter($outcomes, fn($outcome) => $outcome->isAccepted()));  // 3
$again = $runner->sendMany($events, actor: 'import');                    // o mesmo arquivo de novo: nada gravado
$again[0]->result->getTransitions()[0]->to;                              // 'analysis': o resultado original
$runner->sendMany([new BatchEvent('claim-9', Event::external('START_ANALYSIS', $at))])[0]->errorMessage(); // 'Machine instance "claim-9" does not exist.'
```

- **Uma falha depois da primeira gravação de um evento** (veto de efeito síncrono, chave gravada no meio-tempo, erro do banco) não se desfaz sozinha dentro do bloco. Quando o runner abriu a transação do bloco, ele a desfaz e reenvia aquele bloco um evento por vez por `send()`, então todo resultado continua sendo o de `send()`. Quando a aplicação abriu a transação (o runner entra nela), a exceção se propaga, como sairia de `send()`, e a aplicação desfaz. Os blocos anteriores ficam confirmados.
- **Um store próprio** ganha a consulta em lote implementando `IBatchIdempotencyStore` (`findMany()`); sem ele `sendMany()` chama `find()` por evento. Nenhuma interface existente ganhou método.
- **Dentro de uma transação da aplicação** (uma por bloco do arquivo, com seu checkpoint), `sendMany()` entra nela como `send()`; o ganho passa a ser a consulta de idempotência por bloco em vez de por evento.

#### Colunas JSON Largas

As colunas JSON do DDL de referência (`payload`, `transition_result`, `snapshot`, `params`, `reason`) são `VARCHAR(4000)`, para o script rodar em qualquer banco. Os stores PDO nunca truncam nem conferem o tamanho: um valor maior é recusado pelo próprio banco (PostgreSQL `22001` "value too long"; MySQL e MariaDB em modo estrito, `1406` "Data too long"; o SQLite não impõe o tamanho), e o passo é desfeito. Quando um payload pode ser maior (uma observação em texto livre, um corpo HTML), alargue uma vez as colunas que o carregam, com a ferramenta de migration da aplicação; o cabeçalho do DDL lista o comando de cada banco:

```sql
-- PostgreSQL
ALTER TABLE state_machine_history ALTER COLUMN payload TYPE TEXT;
ALTER TABLE state_machine_idempotency ALTER COLUMN transition_result TYPE TEXT;
-- MySQL, MariaDB (TEXT guarda 64 KiB, MEDIUMTEXT 16 MiB)
ALTER TABLE state_machine_history MODIFY payload MEDIUMTEXT;
ALTER TABLE state_machine_idempotency MODIFY transition_result MEDIUMTEXT NOT NULL;
```

O SQL Server usa `NVARCHAR(MAX)`, Oracle e DB2 um `CLOB`; o mesmo vale para `state_machine_rejection.payload` e `reason` e para `state_machine_instance.snapshot`.
