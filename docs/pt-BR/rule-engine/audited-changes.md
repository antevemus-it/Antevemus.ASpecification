# Mudanças Auditadas no Catálogo de Regras: Versões, Fotos e Histórico

### 25. Versões, Fotos e Histórico (Módulo 47)

Desde a v2.13.0 o catálogo que o `PdoRuleCatalog` lê tem um caminho de escrita com trilha de auditoria, no formato das feature flags (exemplo 19): o `RuleCatalogEditor`. Cada mudança de uma regra cria uma nova **versão** dela, com a **foto** completa da regra depois da mudança, o hash dessa foto e o **motivo**, numa transação. "A versão 3 da regra `max_rent`" passa a ser uma linha do histórico, que uma análise ou uma cotação pode citar: a regra como era quando valeu, não como está hoje.

```php
use Antevemus\ASpecification\Engine\Exceptions\StaleRuleChangeException;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalogStore;
use Antevemus\ASpecification\Engine\RuleCatalogEditor;

// $pdo: as tabelas de resources/sql/rule_catalog.ansi.sql, depois da migração 2.13.0 do cabeçalho
$catalog = new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_13);
$editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($pdo, $catalog));

$v1 = $editor->create(
    ['code' => 'max_rent', 'name' => 'Maximum rent', 'rule_type' => 'max_rent', 'scope' => 'contract', 'int_value' => 5000],
    'Ceiling approved by the credit committee',
    actor: 'ana'
);
$v2 = $editor->update('max_rent', ['int_value' => 6000], 'Ceiling raised after the market review', actor: 'bia', expectedVersion: $v1->version);

$r1 = $v2->version;                                                   // 2
$r2 = $v2->changedFields;                                             // ['int_value']
$r3 = $editor->revision('max_rent', 1)?->definition()->getIntValue(); // 5000: a versão 1 como era
$r4 = $v2->isIntact();                                                // true: o hash é o hash da foto
$r5 = $catalog->findRuleByCode('max_rent')?->getVersion();            // 2

try {
    $editor->update('max_rent', ['int_value' => 7000], 'A screen opened before the last change', expectedVersion: 1);
} catch (StaleRuleChangeException $e) {
    $r6 = $e->getMessage(); // Rule "max_rent" changed since it was read (version 1, now 2): reload and try again.
}
```

- **A migração** é aditiva e opcional, no cabeçalho de `resources/sql/rule_catalog.ansi.sql`: `ALTER TABLE business_rule ADD COLUMN version INTEGER DEFAULT 1 NOT NULL CHECK (version >= 1)` e `CREATE TABLE IF NOT EXISTS business_rule_change (...)`. Sem ela nada da biblioteca muda: o `PdoRuleCatalog` lê as mesmas colunas com o mesmo SQL, e toda falha leva os mesmos metadados. O editor confere as duas antes de cada chamada e recusa com uma mensagem que aponta esse cabeçalho. O `PdoRuleCatalog` só lê a versão quando mandado (`COLUMNS_SINCE_2_13`, mesclado com qualquer outro mapa, por exemplo `[...PdoRuleCatalog::COLUMNS_SINCE_2_4, ...PdoRuleCatalog::COLUMNS_SINCE_2_13]`), e o `PdoRuleCatalogStore::forCatalog()` grava a tabela e as colunas que esse catálogo lê. O `InMemoryRuleCatalogStore` é o mesmo contrato em memória.
- **Escrita.** `create($row, $reason)` cria a versão 1; `update($code, $changes, $reason)` mescla as colunas dadas sobre a linha atual; `setActive($code, $active, $reason)` põe a regra em uso ou a tira de uso. Não há exclusão: a regra sai de uso por `setActive(false)`, e o histórico fica. A linha usa as chaves de `RuleDefinition::fromArray()` (`RuleCatalogEditor::INPUT_COLUMNS`): chave desconhecida, chave em português da 1.x, valor do tipo errado, `code` alterado, `active` dado ao `update()` ou `version` dada são `InvalidArgumentException`. Produto, plano e vigência moram nas suas colunas, nunca dentro de `parameters`.
- **Compare-and-set da versão.** Toda mudança é `UPDATE ... SET ..., version = version + 1 WHERE code = ? AND version = ?`: de duas mudanças da mesma versão, uma vence e a outra recebe `StaleRuleChangeException`, nunca as duas. O `expectedVersion` é a versão que a tela mostrou (null = a lida na transação). Uma mudança que deixa a linha como está, e uma regra já no estado pedido, são recusadas do mesmo jeito: nada é gravado.
- **O motivo** segue as regras das feature flags: sequências de espaço viram um, as pontas são aparadas, de 10 a 1000 caracteres por padrão (`InvalidRuleChangeReasonException`). A origem é `ORIGIN_UI`, `ORIGIN_MIGRATION`, `ORIGIN_SCRIPT` ou o texto da própria aplicação; o autor é gravado como veio (quem pode mudar uma regra é decisão da aplicação).
- **A foto** (`RuleSnapshot`) é o JSON de todas as colunas da regra, `version` e `active` inclusive, chaves em ordem alfabética, `parameters` com as chaves de cada objeto em ordem, o decimal como texto canônico, datas como `YYYY-MM-DD`, null escrito; o hash é `sha256:` e 64 dígitos hexadecimais. A mesma linha dá o mesmo texto e o mesmo hash no PostgreSQL, no MySQL, no MariaDB, no SQLite e na memória. A forma é contrato: mudá-la é MAJOR. O `RuleRevision::isIntact()` recalcula o hash: false quer dizer que a linha do histórico foi editada à mão. O `definition()` dá a regra daquela versão como o catálogo a lê.
- **As mensagens** citam a regra, a coluna e a versão, nunca um valor de coluna, o motivo ou o valor recebido; a mensagem do driver de um comando que falhou fica na exceção anterior (`RuleCatalogStoreException`, com o SQLSTATE).
- **Histórico.** `current($code)` é a última revisão, `revision($code, $version)` uma versão, `history($code, $limit)` a trilha, da mais recente para a mais antiga. Cada linha tem o tipo da mudança (`create`, `update`, `activate`, `deactivate`, `baseline`) e as colunas que mudou, em ordem alfabética.

### 25.1. Regras de Antes da Migração e Mudanças Feitas à Mão

Uma regra que existia antes da migração tem a versão 1 e nenhum histórico. A primeira mudança dela pelo editor grava, na mesma transação e antes da linha da mudança, uma linha `baseline` com a foto da versão atual, para que toda versão dali em diante tenha foto; o `adopt()` grava essas linhas de base sem mudar nada. O `findDrift()` é a rede de segurança portável contra uma mudança feita fora do editor.

```php
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogDriftException;
use Antevemus\ASpecification\Engine\RuleDrift;
use Antevemus\ASpecification\Engine\RuleRevision;

// min_income já estava em business_rule antes da migração: versão 1, sem histórico
$adopted = $editor->adopt(null, 'Rules of the catalog before the audited path');
$r1 = array_map(static fn(RuleRevision $baseline): string => $baseline->code . '@' . $baseline->version, $adopted); // ['min_income@1']

$pdo->exec("UPDATE business_rule SET int_value = 4 WHERE code = 'min_income'"); // um UPDATE à mão
$r2 = array_map(static fn(RuleDrift $drift): string => $drift->code . ':' . $drift->kind->value, $editor->findDrift()); // ['min_income:modified']
try {
    $editor->update('min_income', ['int_value' => 5], 'Raise the minimum income');
} catch (RuleCatalogDriftException $e) {
    $r3 = $e->getDrift()->kind; // RuleDriftKind::Modified: a linha não é a foto da sua versão
}
$pdo->exec("UPDATE business_rule SET version = version + 1 WHERE code = 'min_income'"); // a mudança à mão ganha versão própria
$r4 = $editor->update('min_income', ['int_value' => 5], 'Raise the minimum income')->version; // 3: a linha de base da versão 2 vem antes
```

- **Os quatro tipos** de `RuleDriftKind`: `no_history` (nunca mudada nem adotada pelo editor), `version_ahead` (a versão subiu fora do editor), `modified` (a linha não é a foto da sua versão: um `UPDATE` à mão que manteve ou baixou a versão) e `deleted` (o histórico tem a regra, a tabela não). O `adopt()` e a próxima mudança resolvem os dois primeiros com uma linha de base da versão atual.
- **Linha modificada não é mudada.** Gravar por cima esconderia a mudança feita à mão dentro da próxima versão: o editor recusa (`RuleCatalogDriftException`) até a versão subir à mão, o que transforma aquela mudança numa versão própria cuja linha de base a próxima mudança grava. Uma regra apagada à mão não pode ser criada de novo com o mesmo código.
- **O que fica com a aplicação**: um gatilho que proíbe gravar `business_rule` fora do editor, ou que torna o histórico só-inclusão no banco (não é portável; o cabeçalho de `resources/sql/feature_flags.ansi.sql` mostra um para PostgreSQL), e o alerta quando o `findDrift()` encontra algo.

### 25.2. Uma Guarda da Mudança

O `withChangeGuard($specification)` avalia uma especificação sobre a mudança antes de qualquer gravação, dentro da transação: o `RuleChangeCandidate` traz o tipo, a regra antes (null numa criação) e depois, a versão que ela deixa e a que cria, as colunas que muda, o autor, a origem e o motivo. Não satisfeita, ou sem como avaliar, a mudança é recusada (`RuleChangeRefusedException`, com o resultado da guarda) e nada é gravado, linha de base inclusive. É onde a aplicação pluga uma regra sua, como "uma regra que exige parecer jurídico não é ativada sem um parecer favorável para esta versão", sem a biblioteca saber o que é um parecer.

```php
use Antevemus\ASpecification\Engine\Exceptions\RuleChangeRefusedException;
use Antevemus\ASpecification\Engine\RuleChangeCandidate;
use Antevemus\ASpecification\Engine\RuleChangeKind;
use Antevemus\ASpecification\Spec;

$favourable = ['credit_profile' => [3]]; // os pareceres da aplicação: código da regra => versões com parecer favorável
$guarded = $editor->withChangeGuard(
    Spec::specify(RuleChangeCandidate::class)->must(
        static fn(RuleChangeCandidate $change): bool => $change->kind !== RuleChangeKind::Activate
            || in_array($change->toVersion, $favourable[$change->code] ?? [], true),
        'LEGAL_OPINION_REQUIRED',
        'This version of the rule has no favourable legal opinion'
    )
);

$guarded->create(['code' => 'credit_profile', 'name' => 'Credit profile', 'rule_type' => 'profile', 'scope' => 'contract', 'active' => false], 'Drafted by the risk team');
try {
    $guarded->setActive('credit_profile', true, 'Go live before the opinion');
} catch (RuleChangeRefusedException $e) {
    $r1 = $e->getResult()->getCodes(); // ['LEGAL_OPINION_REQUIRED']: nada foi gravado
}
$guarded->update('credit_profile', ['description' => 'Score of 600 or more'], 'Wording asked by the legal team');
$r2 = $guarded->setActive('credit_profile', true, 'Favourable opinion for version 3')->version; // 3
```

- O `withChangeGuard()` devolve uma cópia; o editor original fica como estava, e uma segunda chamada substitui a guarda (componha várias com `and()`). A mensagem da exceção lista os códigos da guarda; as mensagens dela estão no `getResult()`.
- Rascunho, publicação, aprovação em dois passos e o próprio parecer não estão na biblioteca: a guarda é o gancho onde cada aplicação põe os seus.

### 25.3. As Versões que Valeram

Um catálogo que lê a versão a entrega ao motor: toda falha de uma regra versionada leva `metadata['rule_version']`, depois das outras chaves da regra, e o `DynamicSpecificationEngine::getCompiledRules()` lista toda regra compilada pela última validação, as satisfeitas inclusive, para a aplicação gravar o código e a versão de toda regra que valeu.

```php
use Antevemus\ASpecification\Contracts\Engine\IVersionedRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;

$registry = new RuleSpecificationRegistry();
$registry->registerClosure('max_rent', static fn($rule): ISpecification => Spec::property('rent', Spec::lessThanOrEqualTo($rule->getIntValue())));
$registry->registerClosure('min_income', static fn($rule): ISpecification => Spec::property('income', Spec::greaterThanOrEqualTo($rule->getIntValue() * 1000)));
$registry->registerClosure('profile', static fn($rule): ISpecification => Spec::property('score', Spec::greaterThanOrEqualTo(600)));
$engine = new DynamicSpecificationEngine($catalog, $registry);

$verdict = $engine->validate(['rent' => 6500, 'income' => 9000, 'score' => 720], 'contract');
$r1 = $verdict->getBlockingFailures()[0]->metadata['rule_version']; // 2: max_rent falhou na versão 2
$r2 = array_map(
    static fn(IVersionedRuleDefinition $rule): string => $rule->getCode() . '@' . $rule->getVersion(),
    $engine->getCompiledRules()
); // ['credit_profile@3', 'max_rent@2', 'min_income@3']: toda regra que valeu, as satisfeitas também
```

- O `IVersionedRuleDefinition` estende o `IRuleDefinition` com `getVersion()`; o `RuleDefinition` o implementa. O `IRuleDefinition` não muda, então uma classe que o implementa direto continua funcionando, e uma regra sem versão (catálogo sem o mapa, regra montada no código) carimba exatamente os metadados da 2.12.0. O `getCompiledRules()` é método da classe do motor, preenchido a cada compilação como o `getSkippedRules()` (as regras só consulta continuam lá).
- **Cache.** O `CachedRuleCatalog` guarda a versão de cada regra. Dê ao `PdoCatalogVersion::forRuleCatalog()` a tabela de histórico do editor (`changeTable: 'business_rule_change'`): toda mudança pelo editor acrescenta uma linha a ela, então o cache vê a mudança na hora em todo servidor. O maior `version` da tabela de regras não serviria: mudar uma regra que não é a de maior versão o deixa como estava. Veja [Cache](../caching/index.md).
- A descrição completa do motor está em [Regras dinâmicas](dynamic-rules.md); os tipos só consulta e a precedência em [Um consumidor real](real-consumer.md).
