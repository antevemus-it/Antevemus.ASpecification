# Motor de Regras para um Consumidor Real: Tipos Só Consulta, a Regra Mais Específica e Rótulos do Alvo

### 22. Motor de Regras para um Consumidor Real: Tipos Só Consulta, a Regra Mais Específica e Rótulos do Alvo (Módulo 36)

Desde a v2.5.0 o motor de regras cobre três coisas que um consumidor do catálogo escrevia à mão (forward 048, medido na Virttus). **Tipos de regra "só consulta"**: um catálogo mistura regras que validam com linhas que só guardam um valor lido pela aplicação (janela do aviso, dia de pagamento, chave liga/desliga); o `registerLookupOnly()` declara esses tipos no código, o motor pula as regras deles em vez de derrubar o escopo inteiro com `MissingRuleHandlerException`, e o `getSkippedRules()` as lista. **A regra mais específica de um tipo**: o `RulePrecedence` escolhe, entre as regras ativas de um tipo, a do cenário pedido antes da genérica do escopo, depois restrição de plano antes de produto antes de nenhuma, depois a prioridade maior, depois o `code` menor; o `findRulesByType()` lê todos os cenários de um tipo numa consulta. **Um rótulo humano para o alvo**: o `labelled()` reporta cada falha contra um texto feito para o usuário, sem subclasse.

```php
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RulePrecedence;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\Spec;

$catalog = new InMemoryRuleCatalog([
    new RuleDefinition(code: 'max_claims', name: 'Ongoing claims', ruleType: 'claim_max', intValue: 2, violationMessage: 'Too many ongoing claims.', scope: 'claim'),
    // Valores que a aplicação lê: não validam nada
    new RuleDefinition(code: 'notice_days', name: 'Notice window', ruleType: 'claim_notice', intValue: 30, scope: 'claim'),
    new RuleDefinition(code: 'notice_days_vacant', name: 'Notice window, vacant', ruleType: 'claim_notice', intValue: 45, scope: 'claim', scenario: 'vacant'),
]);
$facts = ['ongoing' => 3];

$registry = (new RuleSpecificationRegistry())
    ->registerClosure('claim_max', fn(RuleDefinition $rule) => Spec::property('ongoing', Spec::lessThanOrEqualTo($rule->getIntValue()))
        ->withCode($rule->getCode())
        ->because($rule->getViolationMessage())
        ->labelled("Ongoing claims: {$facts['ongoing']} (maximum {$rule->getIntValue()})"))
    ->registerLookupOnly('claim_notice');                       // sem isto: MissingRuleHandlerException

$engine = new DynamicSpecificationEngine($catalog, $registry);
$verdict = $engine->validate($facts, 'claim');
$failure = $verdict->getBlockingFailures()[0];
$failure->property;                                             // 'Ongoing claims: 3 (maximum 2)'
$failure->metadata['property_path'];                            // 'ongoing'
$engine->getSkippedRules()[0]->getCode();                       // 'notice_days'

// A regra mais específica de um tipo: a do cenário, senão a genérica do escopo
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();     // 45
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'occupied')->getIntValue();   // 30
$byScenario = RulePrecedence::byScenario($catalog->findRulesByType('claim', 'claim_notice'), 'claim_notice');
array_map(fn(RuleDefinition $rule): int => $rule->getIntValue(), $byScenario);           // ['vacant' => 45]: uma leitura
```

- **Tipos só consulta** (`ILookupOnlyRuleTypes`, implementada pelo `RuleSpecificationRegistry`). A declaração fica no código, nunca no catálogo: uma coluna "este tipo não valida nada" deixaria quem edita uma linha desligar uma validação. Um tipo que ninguém declarou e ninguém trata continua `MissingRuleHandlerException` (um `rule_type` digitado errado, ou o handler esquecido de uma regra de bloqueio, nunca vira aprovação). Um tipo não pode ser só consulta e ter handler ao mesmo tempo: closure para tipo só consulta, ou o contrário, é recusada no registro (`RuleEngineException`), e um handler de classe cujo `supports()` aceite um tipo só consulta é "tipo de regra ambíguo" quando uma regra dele é compilada. Uma regra de tipo só consulta que tenha `spec_json` (exemplo 21) é validação: o documento vence o tipo. O `getSkippedRules()` é preenchido em toda compilação, em qualquer modo de validação do catálogo, na ordem do catálogo.
- **Precedência** (`RulePrecedence`, estática e pura). `mostSpecific($rules, $type, $scenario)` sobre regras já lidas; `findRule($catalog, $scope, $type, $scenario, $filters)` as lê numa consulta de um catálogo que implemente `IRuleTypeCatalog` (`InMemoryRuleCatalog`, `PdoRuleCatalog`), ou pelo `findRules()` filtrado pelo tipo em qualquer outro catálogo (mesma resposta, mais linhas lidas); `byScenario()` dá a regra mais específica de cada cenário (sem as genéricas) e `scenariosOf()` os cenários, em ordem de bytes. O desempate pelo `code` (a chave primária) torna a resposta independente da ordem em que as regras foram adicionadas ou lidas: antes, o catálogo em memória desempatava pela inserção e o PDO pelo `code`. O `findRulesByType($scope, $type, $filters)` aplica os mesmos filtros de escopo, produto, plano e vigência do `findRules()`, sem o cenário, em ordem de prioridade e depois `code`. A `IRuleCatalog` não ganha método, e o `findRules()` mantém a ordem dele (a ordem das falhas num veredito).
- **Rótulos** (`LabelledSpecification`; `$spec->labelled($label)` ou `Spec::labelled($label, $spec)`). Cada falha do resultado, de regra ou de erro de avaliação, recebe `property` = o rótulo e `metadata['label']`; a propriedade técnica vai para `metadata['property_path']`. A resposta, o `isError` e a exceção nunca mudam, e o rótulo nunca é SQL: o `toSql()`, o `toCriteria()`, o compilador ALinq e a álgebra veem a especificação rotulada. Rótulos aninhados: vence o de dentro (`allOf($a->labelled('A'), $b->labelled('B'))->labelled('Group')` reporta A e B, e Group só nomeia as falhas sem rótulo). `because()`/`withCode()` sobre o rótulo trocam a mensagem e o código de cada falha de regra, nunca de um erro de avaliação. O `simplify()` mantém o nó, o codec o grava como `{"t": "labelled", "label": "...", "s": {...}}` (documento que a 2.4.0 não lê), e uma regra vinculada reportada uma vez só (nenhuma falha com código) lista dois ou mais rótulos distintos em `metadata['labels']`.
