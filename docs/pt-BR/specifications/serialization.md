# Serialização de Especificações

### 21. Serialização de Especificações (Módulo 31)

Desde a v2.4.0 uma especificação pode ser gravada como dado e lida de volta: o `SpecificationCodec` transforma a especificação num documento JSON canônico e o documento na mesma especificação, sem `unserialize()` e sem nunca tirar um nome de classe da entrada. O corpo de uma regra do catálogo pode ser esse documento (`business_rule.spec_json`), e uma feature flag pode apontar essa regra como alvo (`targetRuleCode`), então um operador troca quem vê uma funcionalidade trocando uma linha, sem deploy.

```php
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;

final class RentalContract
{
    public function __construct(public readonly int $id, public readonly string $state, public readonly int $rent) {}
}

// $pdo: as tabelas de resources/sql/rule_catalog.ansi.sql (2.4.0), ou uma business_rule anterior depois da migração do cabeçalho dele
$codec = new SpecificationCodec();
$pilot = Spec::property('state', Spec::in('SP', 'RJ'))
    ->and(Spec::property('rent', Spec::lessThanOrEqualTo(5000)))
    ->because('Pilot: SP and RJ, rent up to 5000');

$json = $codec->toJson($pilot);
// {"v":1,"spec":{"t":"and","s":[{"t":"property","p":"state","s":{"t":"in","v":["SP","RJ"]}},
//  {"t":"property","p":"rent","s":{"t":"lte","v":5000}}],"because":"Pilot: SP and RJ, rent up to 5000"}}
$codec->fromJson($json)->equals($pilot);                                  // true: a mesma especificação

// O corpo de uma regra do catálogo é esse documento; uma feature flag aponta a regra pelo código
$pdo->prepare("INSERT INTO business_rule (code, name, rule_type, spec_json) VALUES ('renewal_pilot', 'Renewal pilot', 'spec', ?)")
    ->execute([$json]);
$flags = new FeatureFlags(
    new FeatureFlagRegistry([
        new FeatureFlagDefinition('renewal_notice', 'Renewal notice', subjectKey: 'id', targetRuleCode: 'renewal_pilot'),
    ]),
    new InMemoryFeatureFlagStore(),                                       // ou um PdoFeatureFlagStore (exemplo 19)
    ruleCatalog: new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_4),
);
$flags->toggle('renewal_notice', true, 'Pilot approved by the board');

$flags->isEnabled('renewal_notice', new RentalContract(id: 1, state: 'SP', rent: 3200));         // true
$flags->evaluate('renewal_notice', new RentalContract(id: 2, state: 'MG', rent: 3200))->reason; // FeatureFlagReason::NOT_IN_TARGET

// A regra muda no catálogo: a flag a acompanha depois do refresh(), sem deploy
$pdo->prepare("UPDATE business_rule SET spec_json = ? WHERE code = 'renewal_pilot'")
    ->execute([$codec->toJson(Spec::property('state', Spec::in('SP', 'RJ', 'MG')))]);
$flags->refresh();
$flags->isEnabled('renewal_notice', new RentalContract(id: 2, state: 'MG', rent: 3200));         // true
```

- **O formato.** Um envelope com a versão do formato, `{"v": 1, "spec": {...}}`, em volta de uma árvore de nós `{"t": "<nome>", ...parâmetros}`. O `t` é um nome curto e estável, nunca um nome de classe: renomear uma classe não quebra um documento gravado, e um documento nunca escolhe uma classe. `and`/`or` guardam uma lista de dois ou mais operandos (`"s": [...]`; uma cadeia de conjunções é uma lista só, lida de volta como a árvore aninhada à esquerda que era), `not` guarda um nó, e `because`/`code` levam as anotações `because()`/`withCode()` do nó. O documento é canônico: chaves em ordem fixa, inteiro continua inteiro e `1.0` continua float, então a mesma especificação dá sempre o mesmo texto (`encode(decode(encode($s))) === encode($s)`), e `decode(encode($s))->equals($s)`. `encode()`/`decode()` trabalham com arrays, `toJson()`/`fromJson()` com texto.
- **Valores.** Escalares como são; data e hora como `{"$date": "2026-01-31T09:30:00.000000-03:00"}` (RFC 3339 com microssegundos e o offset; lida de volta como `DateTimeImmutable` no mesmo instante e offset); caso de enum como `{"$enum": "App\\Status", "case": "Active"}` **só** para enum da lista permitida do codec (`new SpecificationCodec(allowedEnums: [Status::class])` ou `allowEnum()`; o `TextComparison` é permitido por padrão, e a comparação de uma folha de texto é gravada pelo valor, `"cmp": "ignore_case"`); listas desses. Closure, qualquer outro objeto, recurso, array associativo ou float não finito não têm forma serializada e são recusados, com o lugar na árvore (`property.eq.v`).
- **Uma chamada de método** (`Spec::calling()`, exemplo 13) é serializada pelo nome do método e pelos argumentos declarativos: `{"t":"method","m":"installmentsLeft","args":[3],"s":{"t":"gt","v":0}}`.
- **Uma folha inteira** (`asInteger()`, `Spec::int()`, desde a v2.9.0, exemplo 1.1) leva `"int": true` depois do valor, `{"t":"gte","v":12,"int":true}`, e é lida de volta inteira; `"int"` é `true` ou ausente, e o limite é conferido como na construção (`{"t":"gt","v":10.5,"int":true}` é recusado). Uma folha que não é inteira é gravada como antes, então os documentos da 2.4.0 à 2.8.x são lidos do mesmo jeito.

Os 45 nomes da biblioteca (`SpecificationCodec::coreTypes()`; `types()` lista os de um codec, os seus inclusive):

| Família | Nomes |
|---|---|
| Composições | `and`, `or`, `not`, `nor` (`JointDenialSpecification`) |
| Apresentação (2.5.0) | `labelled` (`LabelledSpecification`, o rótulo humano de um alvo, exemplo 22) |
| Navegação e tipo | `property`, `method` (`MethodParameterizedSpecification`), `type` (`Spec::specify(T)`), `a` (o invólucro de `a()`) |
| Constantes e valores padrão | `true`, `false`, `defaultValue` |
| Comparação | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `looseEq`, `at` (`SameInstantSpecification`), `in`, `isNull`, `notNull` |
| Texto | `contains`, `startsWith`, `endsWith`, `regex`, `wildcard`, `wildcardIgnoreCase`, `equalIgnoreCase`, `equalIgnoreCaseAndAccents`, `dateString`, `enumName` |
| Coleções e entidades | `all`, `any`, `none`, `hasSize`, `hasLength`, `include`, `includePercentageOf`, `allEntities`, `entity` (`UniqueEntitySpecification`) |
| Datas relativas | `isPast`, `isFuture`, `isToday` |

- **O que não serializa, e por quê.** Cada um é recusado com `NonSerializableSpecificationException`, o motivo e o lugar na árvore (`and[1].callback`): callback (`must()`, `PredicateSpecification`) é código, não dado; `MethodParameterizedSpecification` cujo resultado vai para uma closure, pelo mesmo motivo (`method.callback`; os argumentos já recusam closure); `Spec::external()` depende de uma fonte de dados e do resolvedor; `FeatureEnabledSpecification` (`$flags->spec()`) depende do serviço de flags; os guards da máquina de estados (`inState`, `eventOrigin`, `payloadHas`) são referenciados por nome no `GuardRegistry` dela; `Spec::inQuery()` segura um repositório; as subqueries de `Spec::inSubquery()`/`Spec::existsIn()` (2.5.0) nomeiam tabelas, e um documento de regra de um catálogo editável não pode ler outras tabelas; as regras vinculadas e os requisitos documentais do motor de regras pertencem ao motor. Uma especificação sua sem o contrato também é recusada.
- **Uma folha sua.** Implemente `ISerializableSpecification` (`toArray(): array`, os parâmetros sem o `t`; `static fromArray(array $data, SpecificationCodec $codec): static`, com os auxiliares `expectKeys()`, `string()`, `value()`, `specification()`...) e registre-a no codec: `$codec->register('minScore', MinScoreSpecification::class)`. O registro é por classe exata: uma subclasse de folha serializável pode redefinir o predicado, então é recusada até ser registrada com nome próprio.
- **Segurança.** A leitura nunca chama `unserialize()` e nunca instancia uma classe nomeada pelo documento: a classe de um nó é a registrada no código para o seu `t`, então `{"t": "App\\Evil"}` é um nome desconhecido. Enum fora da lista permitida é recusado antes de a classe ser tocada; os nomes de tipo de `type`, `allEntities`, `entity` e `enumName` são checados como os construtores deles checam (`class_exists()`, `enum_exists()`) e nunca instanciados nem chamados. A árvore é limitada a 64 níveis e 10 000 nós por padrão (`new SpecificationCodec(maxDepth: 32, maxNodes: 500)` para uma entrada mais restrita), e o texto JSON é limitado antes de ser percorrido. Toda falha é `SpecificationCodecException` (ou a subclasse `NonSerializableSpecificationException`) com `$reason` e `$path`. Um teste monta um documento para cada caso hostil (JSON malformado, nome desconhecido, nome de classe no `t`, enum fora da lista, 65 níveis, 10 001 nós) contra uma folha cujo construtor grava um arquivo marcador, e o arquivo nunca aparece a menos que o codec tenha registrado essa folha.
- **Uma regra do catálogo com corpo.** `business_rule.spec_json` guarda o documento; o `RuleSpecificationRegistry` (e portanto o `DynamicSpecificationEngine`) monta a regra que tem um a partir dele, antes e no lugar do handler do `rule_type`, e um documento que ele não lê é `RuleEngineException` citando a regra. `IRuleCatalog::findRuleByCode($code)` acha uma regra ativa pelo código, qualquer que seja o escopo, o cenário ou o produto (o `PdoRuleCatalog` respeita o `withAsOf()`). A coluna é opt-in: o `PdoRuleCatalog` só a lê com `columns: PdoRuleCatalog::COLUMNS_SINCE_2_4`, para uma tabela criada por um DDL anterior continuar funcionando sem mudança; essa tabela roda uma vez a migração aditiva comentada no cabeçalho do DDL (`ALTER TABLE business_rule ADD COLUMN spec_json VARCHAR(4000);`). `VARCHAR(4000)` é o tamanho portável que os DDLs de referência usam para JSON; para árvores maiores, use uma coluna mais larga (`TEXT`, `CLOB`) e mapeie-a: `columns: ['spec_json' => 'spec_body']`.
- **Uma flag com alvo numa regra.** `FeatureFlagDefinition` recebe `targetRuleCode:` (exclusivo com `target:`); `FeatureFlags` recebe o catálogo (`ruleCatalog:`) e, opcionalmente, o codec que o lê (`specificationCodec:`). A regra é buscada e decodificada uma vez por instância do serviço, na primeira avaliação que precisa dela, e avaliada como um alvo em código; o `refresh()` a lê de novo. Sem catálogo, sem regra ativa com esse código, regra sem `spec_json` ou documento que não decodifica: a flag fica desligada com o motivo `ERROR` (falha fechada), relatado uma vez até o `refresh()`.
- **Quebra para uma classe sua:** `IRuleCatalog` ganha `findRuleByCode()` e `IRuleDefinition` ganha `getSpecJson()` (as classes da biblioteca os implementam; nenhum implementador fora dela foi encontrado nos consumidores medidos), e uma subclasse de especificação da biblioteca que já declare `toArray()` ou `fromArray()` com outra assinatura deixa de carregar.
