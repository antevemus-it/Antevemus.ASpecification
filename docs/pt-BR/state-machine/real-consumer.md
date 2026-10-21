# Máquina de Estados para um Consumidor Real: Códigos Legados, Definições Versionadas e Tenants

### 23. Máquina de Estados para um Consumidor Real: Códigos Legados, Definições Versionadas e Tenants (Módulo 40)

Desde a v2.7.0 a máquina do [exemplo 14](index.md) cobre três coisas de que precisa uma aplicação que substitui as próprias tabelas de workflow: os **ids inteiros dos estados legados** guardados como dado da definição, uma **definição editada por tela** (rascunhos, validação, publicação de uma versão imutável, migração das instâncias vivas) guardada no banco, e **vários tenants** (imobiliárias, unidades, clientes) compartilhando uma definição com as instâncias separadas. Tudo é aditivo: uma definição sem códigos mantém o fingerprint, um store sem as colunas novas funciona como antes, e todo método mantém a assinatura (os parâmetros novos vêm no fim).

#### 23.1 Códigos Legados dos Estados

Um estado pode ter um `code`: o id da linha da tabela legada que ele substitui (um inteiro, ou um texto). Os códigos são únicos na máquina inteira (duplicata é recusada com `KIND_DUPLICATE` ao montar a definição), e um texto inteiro canônico é o mesmo código que o inteiro (`"20"` acha `20`), porque vários drivers PDO leem coluna inteira como texto. O código é dado da definição, versionado com ela; não muda nada no funcionamento da máquina.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Event;

$quote = Spec::machine('quote', 1)
    ->region('progress', 'registering')
        ->state('registering')->code(1)
            ->on('COMPLETE_CALCULATION', 'calculation_done')
        ->state('calculation_done')->code(2)->deadline('P2D', 'EXPIRE')
            ->on('SEND_PROPOSAL', 'proposal_sent')
            ->on('EXPIRE', 'expired')
        ->finalState('proposal_sent')->code(8)
        ->finalState('expired')
    ->region('pending', 'none')
        ->state('none')
            ->on('REGISTER_PENDING', 'open')
        ->state('open')->code(20)
            ->on('RESOLVE_PENDING', 'none')
    ->build();

$quote->stateByCode(20)['region'];               // 'pending'
$quote->stateByCode('20')['state']->name;        // 'open': o texto "20" é o mesmo código que 20
$quote->codeOf('progress', 'calculation_done');  // 2

$machine = Spec::stateMachine();
$start = $machine->initialSnapshot($quote, null, new DateTimeImmutable('2026-10-21T09:00:00-03:00'));
$step = $machine->transition($quote, $start->snapshot, Event::external('COMPLETE_CALCULATION', new DateTimeImmutable('2026-10-21T10:00:00-03:00')));
$quote->codesOf($step->snapshot);                // ['progress' => 2, 'pending' => null]
```

- **Forma de array.** `"code": 20` só aparece no estado que tem código, então o fingerprint de toda definição escrita antes da 2.7.0 não muda. Float, booleano, texto vazio ou com espaço nas pontas é recusado (`KIND_MALFORMED`); `"020"` e `"20 "` são outros códigos.
- **As transições mantêm o id em texto** (a chave legada cabe nele); não há código de transição.
- **No banco**, os stores PDO projetam os códigos ao lado dos nomes dos estados quando mandados (exemplo 23.3): `state_machine_region.current_state_code` e `state_machine_history.from_state_code`/`to_state_code`, gravados como inteiros quando os códigos são, para um relatório juntar as tabelas legadas em SQL puro. O snapshot guarda os nomes: eles são a verdade.

#### 23.2 Uma Definição Editada por Tela: Rascunhos, Publicação e Migração

O `PdoMachineDefinitionRepository` guarda as definições no banco (tabelas `state_machine_definition` e `state_machine_definition_draft` do DDL de referência, a definição como `TEXT`). É a mesma porta que o runner já lê (`register()`, `get()`, `has()`, `latestVersion()`): o `get()` lembra cada (chave, versão) lida, porque uma versão publicada nunca muda, e confere o fingerprint da linha com o conteúdo, então uma linha editada à mão nunca roda. Por cima dele, o `IMachineDefinitionDrafts` (implementado também pelo `InMemoryMachineDefinitionRepository`) dá a uma tela um rascunho por máquina, guardado como o array que ela salvou, um relatório de validação para mostrar e a publicação da versão seguinte.

```php
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Runner\MachineMigrations;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

// $pdo: as tabelas do resources/sql/state_machine.ansi.sql (2.7.0); $quote: a máquina do 23.1
$definitions = new PdoMachineDefinitionRepository($pdo);
$definitions->register($quote);                     // versão 1, entregue com a aplicação (não faz nada se já estiver lá)
$guards = Spec::guardRegistry()->enableSpecGuard(); // o guard `spec` é opt-in
$at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

$runner = new MachineRunner(Spec::stateMachine($guards), $definitions, MachinePersistence::pdo($pdo));
$runner->start('q-1', 'quote', 1, null, $at);

// A tela começa um rascunho a partir da versão 1 e acrescenta uma transição cuja condição lê o contexto
$info = $definitions->draftFrom('quote', 1, null, 'ana', $at);
$draft = $definitions->draft('quote', $guards)->definition;
$draft['regions'][0]['states'][0]['transitions'][] = [
    'id' => 'fast_track',
    'event' => 'FAST_TRACK',
    'target' => 'proposal_sent',
    'guard' => GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(10000)))->toArray(),
];
$draft['regions'][0]['states'][0]['transitions'][] = ['id' => 'broken', 'event' => 'BROKEN', 'target' => 'nowhere'];
$info = $definitions->saveDraft('quote', $draft, $info->revision, 'ana', $at);
$definitions->draft('quote', $guards)->report->errorKinds(); // ['unknown_target']: salvo, reportado, nunca publicado

array_pop($draft['regions'][0]['states'][0]['transitions']);
$info = $definitions->saveDraft('quote', $draft, $info->revision, 'ana', $at);
$published = $definitions->publish('quote', $info->revision, $guards, null, 'Fast track for small amounts', 'ana', $at);
$published->version();                               // 2: o repositório dá a versão, nunca a tela
$definitions->get('quote', 1)->diff($published->definition)->addedTransitions; // ['fast_track']

// As instâncias vivas ficam na versão 1 até serem migradas; nenhum estado saiu, então por nome
$runner->registerMigration('quote', 1, 2, MachineMigrations::byStateName());
$report = $runner->migrateAll('quote', 1, 2, $at->modify('+1 hour'), actor: 'ana');
$report[0]->isMigrated();                            // true
$runner->send('q-1', Event::external('FAST_TRACK', $at->modify('+2 hours')), ['amount' => 8000])->isAccepted(); // true
```

- **Toda escrita é um compare-and-set na revisão do rascunho**: `$expectedRevision` é a revisão que a tela leu (`null` quando ela cria o rascunho). Um salvamento, descarte ou publicação de outra pessoa no meio lança `DefinitionDraftConflictException` (uma `ConcurrencyConflictException`) e nada é gravado.
- **`publish()`** roda numa transação (entrando na que já estiver aberta na conexão): a conferência da revisão, a definição montada do rascunho com a versão seguinte (`latestVersion + 1`; o `version` do array é ignorado), o validador com os guards (e as ações) com que a máquina roda (um erro recusa e nada é gravado; os avisos voltam em `PublishedMachineDefinition::$warnings`); uma definição cujos prazos contam num calendário nomeado ([tempo útil](business-time.md)) também é conferida contra os calendários quando `publish()` e `draft()` recebem o `DeadlineCalendarRegistry` como último argumento (`unknown_calendar` para um nome que o registro não conhece; sem o registro essa conferência é pulada, como no validador), o insert (uma publicação que pegou a versão antes é conflito), a remoção do rascunho. O motivo é obrigatório; o instante e o autor vêm de quem chama (a biblioteca não lê relógio). Uma versão publicada nunca é alterada nem apagada.
- **Rascunhos são arrays**: um grafo pela metade (sem estado inicial, um alvo que ainda não existe) é salvo e reportado (`MachineDefinitionDraft::$report`, os erros e avisos do `MachineDefinitionValidator::analyze()` com região, estado e transição), nunca recusado na carga.
- **`MachineDefinition::diff()`** lista as regiões, estados e transições acrescentados, removidos e alterados entre duas versões. **`MachineMigrations::byStateName()`** mantém cada região no seu estado (pelo nome) e leva o snapshot para a versão de destino, mantendo os prazos como estão; o runner a recusa para uma instância cujo estado não existe mais. **`migrateAll()`** migra as instâncias de uma versão em lotes (`$limit`, `$after`), uma transação por instância, e reporta cada uma (`InstanceMigration`): um conflito ou um snapshot recusado é pulado e tentado de novo na próxima rodada.
- **O guard `spec`** (`GuardRegistry::enableSpecGuard()`, opt-in) recebe um documento do codec de especificações (`{"document": {"v": 1, "spec": {...}}}`, que o `GuardRegistry::specRef()` escreve) e o avalia sobre o contexto passado ao `send()`/`can()`, então uma tela edita uma condição como `amount < 10000` sem código; um documento que o codec recusa é erro de validação. Ligá-lo deixa quem edita a definição escrever qualquer condição que o codec lê sobre o contexto (os limites do codec valem; ele não lê callback, tabela nem serviço).
- **Dados de apresentação ficam fora da definição**: rótulo, cor, ícone ou a lista de motivos de uma tela pertencem à aplicação, chaveados por chave da máquina e nome do estado ou id da transição, para que trocar uma cor nunca gere versão.

#### 23.3 Tenants: Uma Máquina, Várias Imobiliárias

Uma instância pode pertencer a um tenant: um texto opaco escolhido pela aplicação (a imobiliária de id 7 vai como `"7"`), fixado no início e mantido por todo passo e migração. O tenant é de quem a instância é, não quem age sobre ela (esse é o `actor`). Uma definição serve todos os tenants. Com tenant, o runner carrega a instância pelo `ITenantScopedInstanceStore::loadForTenant()`: a instância de outro tenant é `MachineInstanceNotFoundException`, o mesmo erro de id inexistente, então a tela de uma imobiliária nunca descobre que um id de outra existe. `findDue()` e `deliverDue()` sem tenant continuam globais (a rotina do operador entrega os prazos de todos).

```php
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;

// Os mesmos $pdo, $definitions, $guards e $at; as colunas opt-in da 2.7.0 (os códigos vêm de $definitions)
$persistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $definitions);
$runner = new MachineRunner(Spec::stateMachine($guards), $definitions, $persistence);

$runner->start('q-7-1', 'quote', null, null, $at, actor: 'ana', tenant: '7');    // imobiliária 7, última versão
$runner->start('q-12-1', 'quote', null, null, $at, actor: 'bob', tenant: '12');  // imobiliária 12, a mesma definição
$calculated = $at->modify('+1 hour');
$runner->send('q-7-1', Event::external('COMPLETE_CALCULATION', $calculated), tenant: '7')->isAccepted();   // true
$runner->send('q-12-1', Event::external('COMPLETE_CALCULATION', $calculated), tenant: '12')->isAccepted(); // true

try {
    $runner->send('q-7-1', Event::external('SEND_PROPOSAL', $calculated), tenant: '12');
} catch (MachineInstanceNotFoundException $e) {
    $e->getMessage();                                // 'Machine instance "q-7-1" does not exist.'
}

$due = $runner->findDue($calculated->modify('+2 days'), tenant: '7');
array_map(fn($deadline) => $deadline->instanceId, $due); // ['q-7-1']: o prazo da imobiliária 12 não está lá
$runner->deliverDue($calculated->modify('+2 days'), tenant: '7')[0]->isDelivered(); // true

$pdo->query("SELECT current_state_code FROM state_machine_region WHERE instance_id = 'q-12-1' AND region = 'progress'")->fetchColumn(); // '2' ('2' numa coluna VARCHAR, 2 numa INTEGER)
```

A projeção dos códigos deixa os relatórios legados funcionando em SQL, com a coluna declarada `INTEGER` e chave estrangeira para a tabela legada quando todo código é inteiro:

```sql
SELECT r.instance_id, r.tenant, s.label
  FROM state_machine_region r
  JOIN legacy_states s ON s.id = r.current_state_code
 WHERE r.region = 'progress' AND r.tenant = '7';
```

- **As colunas novas são opt-in.** O DDL da 2.7.0 acrescenta `tenant` em `state_machine_instance` e `state_machine_region` (com `UNIQUE (tenant, machine_key, due_at, instance_id, region)`, para o `findDueForTenant()` ler a projeção sem `JOIN`), `current_state_code` em `state_machine_region` e `from_state_code`/`to_state_code` em `state_machine_history`. Os stores só as leem e gravam com `columns: PdoMachineSchema::COLUMNS_SINCE_2_7` (e o repositório de definições: mapear as colunas de código sem ele é recusado ao montar o store), então uma tabela criada por um DDL anterior continua funcionando intocada depois da atualização. Uma instalação nova passa a constante; uma existente roda uma vez a migration aditiva comentada no cabeçalho do DDL (cinco `ADD COLUMN` e um `CREATE UNIQUE INDEX`, para SQLite, PostgreSQL, MySQL e MariaDB) e então a passa. Sem as colunas, uma instância sem tenant é gravada como antes, enquanto `start(tenant: ...)` e uma busca por tenant são recusados com uma mensagem que nomeia a coluna.
- **Histórico, outbox, idempotência e rejeições não têm coluna de tenant**: são lidos por instância, depois de uma carga já confinada ao tenant.
- **Um store próprio** ganha o tenant implementando `ITenantScopedInstanceStore` (e `IMachineInstanceLister` para o `migrateAll()`); pedir tenant a um store sem ele é `StateMachineException`. Nenhuma interface existente ganhou método.

> [!NOTE]
> **Prazos são materializados.** O instante de vencimento é calculado na entrada do estado (e quando um prazo pausado é retomado) e então gravado; uma mudança posterior das entradas, como um feriado cadastrado depois do início, não move um prazo já em curso. A aplicação que precisar disso move as instâncias com uma versão nova e uma migração.
