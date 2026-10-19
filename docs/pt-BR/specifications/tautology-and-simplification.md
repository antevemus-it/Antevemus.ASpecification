# Tautologia, Contradição e Simplificação

### 12. Tautologia, Contradição e Simplificação

Toda especificação responde a duas perguntas estruturais, `isTautology()` e `isContradiction()`. Um `true` é prova pela forma da árvore; um `false` significa "não provado" (não há resolução SAT, e `evaluate()` não muda). O motor de regras pode apontar as regras do catálogo que nunca passam ou nunca falham:

```php
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Spec;

$active   = Spec::property('status', Spec::equalTo('ACTIVE'));
$inactive = Spec::property('status', Spec::equalTo('INACTIVE'));

Spec::isContradiction($active->and($active->not()));         // true  (A ∧ ¬A)
Spec::isTautology($active->or($active->not()));              // true  (A ∨ ¬A)
$active->and($inactive)->isContradiction();                  // true  (disjoint leaves, same property)
Spec::in()->isContradiction();                               // true  (the empty set)
Spec::greaterThan(5)->or(Spec::lessThan(10))->isTautology(); // false: not proven (no SAT solving)

// Rule engine: report the rules that can never pass (or never fail), without blocking
$catalog = new InMemoryRuleCatalog();
$catalog->addRule(new RuleDefinition(code: 'R-12', name: 'Active and inactive', ruleType: 'status_both', scope: 'rental_contract'));
$registry = Spec::ruleRegistry()->registerClosure('status_both', fn() => $active->and($inactive));

$engine = Spec::engine($catalog, $registry)->withCatalogValidation();
$engine->compileSpecification('rental_contract');
foreach ($engine->getCompilationWarnings() as $warning) {
    echo $warning->ruleCode . ': ' . $warning->kind, PHP_EOL;   // "R-12: contradiction"
}
```

`simplify()` (desde a 1.9.0) devolve uma especificação equivalente e menor. Aplica, de baixo para cima até nada mais mudar: identidade (`A ∧ true = A`, `A ∨ false = A`), aniquilação (`A ∧ false = false`, `A ∨ true = true`), dupla negação, idempotência (`A ∧ A = A`, por `equals()`), achatamento de `and`/`or` aninhados, as contradições e tautologias provadas acima (reduzidas a `alwaysFalse()`/`alwaysTrue()`) e subsunção (`A ∧ B = A` e `A ∨ B = B` quando `A` é caso particular de `B`). A entrada nunca é modificada, e simplificar uma árvore já simplificada a devolve inalterada. Um nó anotado com `because()`/`withCode()` é uma unidade: é simplificado por dentro, mas nunca removido, fundido ou trocado por uma constante, então o veredito e as mensagens anotadas não mudam.

```php
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Spec;

$active = Spec::property('status', Spec::equalTo('ACTIVE'));
$adult  = Spec::property('age', Spec::greaterThanOrEqualTo(18))->because('The tenant must be an adult.')->withCode('TENANT_MINOR');

// Uma árvore redundante, como regras compostas costumam ficar: ¬¬A ∧ true ∧ B ∧ (status em ACTIVE, PENDING)
$tree = Spec::not(Spec::not($active))
    ->and(Spec::alwaysTrue())
    ->and($adult)
    ->and(Spec::property('status', Spec::in('ACTIVE', 'PENDING')));  // implicado por status = ACTIVE

$simple = $tree->simplify();                                   // o mesmo que Spec::simplify($tree)
$simple->equals($active->and($adult));                         // true: saem a dupla negação, a identidade e a folha implicada
Spec::simplify($simple) === $simple;                           // true: nada mais a simplificar

// Mesmo valor de verdade, e a falha anotada continua reportada
$minor = (object) ['status' => 'ACTIVE', 'age' => 16];
$simple->isSatisfiedBy($minor) === $tree->isSatisfiedBy($minor); // true
$simple->evaluate($minor)->failures[0]->code;                  // 'TENANT_MINOR'

// Contradições e tautologias provadas viram constantes, mas um nó anotado nunca é descartado
Spec::simplify($active->and($active->not()));                  // alwaysFalse()
Spec::simplify($active->or($active->not()));                   // alwaysTrue()
Spec::simplify($adult->and(Spec::alwaysFalse()))->evaluate($minor)->getCodes(); // ['TENANT_MINOR']

// Motor de regras: opcional (desligado por padrão); cada vínculo de regra fica e é simplificado por dentro
$catalog = new InMemoryRuleCatalog();
$catalog->addRule(new RuleDefinition(code: 'R-30', name: 'Active tenant', ruleType: 'active_tenant', scope: 'rental_contract'));
$registry = Spec::ruleRegistry()->registerClosure('active_tenant', fn() => Spec::not(Spec::not($active))->and(Spec::alwaysTrue()));

$engine = Spec::engine($catalog, $registry)->withSimplification();
(string) $engine->compileSpecification('rental_contract');     // 'Rule[R-30]((AlwaysTrueSpecification WHERE status: EqualSpecification))'
$engine->validate((object) ['status' => 'CLOSED'], 'rental_contract')->getFailureCodes(); // ['R-30']
```

Dois limites, ambos declarados nas docblocks. A equivalência vale para **candidatos não nulos**, a mesma premissa de `isTautology()`/`isContradiction()`: `AND`/`OR`/`NOT` recusam `null` quaisquer que sejam os operandos, e o nó que sobra depois da simplificação pode não recusar (`alwaysTrue()->and(alwaysTrue())` recusa `null`, `alwaysTrue()` aceita). E é uma equivalência **só de valor de verdade**: remover um ramo redundante pode remover um ramo que lançaria exceção, e as mensagens padrão de nós sem anotação podem mudar; só as mensagens e os códigos anotados são garantidos. Nada simplifica sozinho: os visitors SQL, TCriteria e ALinq compilam a árvore como recebida, e o motor de regras simplifica só quando pedido.

**A álgebra nunca promete mais do que a avaliação entrega** (desde a v2.1.0). `equals()`, subsunção, disjunção, `isTautology()`/`isContradiction()`, `simplify()`, a deduplicação de `and()`/`or()` e a busca de partição são conferidos por uma bancada de solidez contra a avaliação de toda classe concreta de especificação sobre candidatos de fronteira (Qualidade de Código); onde discordavam, a álgebra agora prova menos. Duas consequências valem saber. **Datas são comparadas pelo instante**: `equalTo()`, `notEqual()` e `in()` comparam um `DateTimeInterface` pelo instante, qualquer que seja a classe e o fuso, como as folhas relacionais, `at()` e o visitor de SQL já faziam, então `Spec::equalTo(new DateTimeImmutable('2025-01-01 10:00', new DateTimeZone('UTC')))` aceita `new DateTime('2025-01-01 07:00', new DateTimeZone('America/Sao_Paulo'))` em memória como no banco (até a 2.0.0 a memória comparava instâncias); outros objetos são comparados por identidade, na avaliação e no `equals()`. E **limites inteiros não são fechados**: as folhas relacionais aceitam qualquer número, então `greaterThan(10)` e `lessThan(11)` não são disjuntas (`10.5` satisfaz as duas) e a conjunção delas não some na simplificação; um modo inteiro opcional está no roadmap (marco 25).
