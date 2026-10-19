# Serializing Specifications

### 21. Serializing Specifications (Module 31)

Since v2.4.0 a specification can be written as data and read back: `SpecificationCodec` turns it into a canonical JSON document and the document into the same specification, without `unserialize()` and without ever taking a class name from the input. The body of a rule of the catalog can be such a document (`business_rule.spec_json`), and a feature flag can name that rule as its target (`targetRuleCode`), so an operator changes who sees a feature by changing a row, without a deploy.

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

// $pdo: the tables of resources/sql/rule_catalog.ansi.sql (2.4.0), or an older business_rule after the migration in its header
$codec = new SpecificationCodec();
$pilot = Spec::property('state', Spec::in('SP', 'RJ'))
    ->and(Spec::property('rent', Spec::lessThanOrEqualTo(5000)))
    ->because('Pilot: SP and RJ, rent up to 5000');

$json = $codec->toJson($pilot);
// {"v":1,"spec":{"t":"and","s":[{"t":"property","p":"state","s":{"t":"in","v":["SP","RJ"]}},
//  {"t":"property","p":"rent","s":{"t":"lte","v":5000}}],"because":"Pilot: SP and RJ, rent up to 5000"}}
$codec->fromJson($json)->equals($pilot);                                  // true: the same specification

// The body of a catalog rule is that document; a feature flag targets the rule by its code
$pdo->prepare("INSERT INTO business_rule (code, name, rule_type, spec_json) VALUES ('renewal_pilot', 'Renewal pilot', 'spec', ?)")
    ->execute([$json]);
$flags = new FeatureFlags(
    new FeatureFlagRegistry([
        new FeatureFlagDefinition('renewal_notice', 'Renewal notice', subjectKey: 'id', targetRuleCode: 'renewal_pilot'),
    ]),
    new InMemoryFeatureFlagStore(),                                       // or a PdoFeatureFlagStore (example 19)
    ruleCatalog: new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_4),
);
$flags->toggle('renewal_notice', true, 'Pilot approved by the board');

$flags->isEnabled('renewal_notice', new RentalContract(id: 1, state: 'SP', rent: 3200));         // true
$flags->evaluate('renewal_notice', new RentalContract(id: 2, state: 'MG', rent: 3200))->reason; // FeatureFlagReason::NOT_IN_TARGET

// The rule changes in the catalog: the flag follows it after refresh(), without a deploy
$pdo->prepare("UPDATE business_rule SET spec_json = ? WHERE code = 'renewal_pilot'")
    ->execute([$codec->toJson(Spec::property('state', Spec::in('SP', 'RJ', 'MG')))]);
$flags->refresh();
$flags->isEnabled('renewal_notice', new RentalContract(id: 2, state: 'MG', rent: 3200));         // true
```

- **The format.** An envelope with the version of the format, `{"v": 1, "spec": {...}}`, around a tree of nodes `{"t": "<name>", ...parameters}`. `t` is a short, stable name, never a class name: renaming a class does not break a stored document, and a document never chooses a class. `and`/`or` hold a list of two or more operands (`"s": [...]`; a chain of conjunctions is one list, read back as the left-nested tree it was), `not` holds one node, and `because`/`code` carry the `because()`/`withCode()` annotations of a node. The document is canonical: keys in a fixed order, an integer stays an integer and `1.0` stays a float, so the same specification always gives the same text (`encode(decode(encode($s))) === encode($s)`), and `decode(encode($s))->equals($s)`. `encode()`/`decode()` work with arrays, `toJson()`/`fromJson()` with text.
- **Values.** Scalars as they are; a date-time as `{"$date": "2026-01-31T09:30:00.000000-03:00"}` (RFC 3339 with microseconds and the offset; read back as a `DateTimeImmutable` at the same instant and offset); an enum case as `{"$enum": "App\\Status", "case": "Active"}` **only** for an enum of the codec's allowlist (`new SpecificationCodec(allowedEnums: [Status::class])` or `allowEnum()`; `TextComparison` is allowed by default, and the comparison of a text leaf is written as its value, `"cmp": "ignore_case"`); lists of those. A closure, any other object, a resource, an associative array or a non-finite float has no serialized form and is refused, naming its place in the tree (`property.eq.v`).
- **A method call** (`Spec::calling()`, example 13) is serialized by its method name and its declarative arguments: `{"t":"method","m":"installmentsLeft","args":[3],"s":{"t":"gt","v":0}}`.

The 45 names of the library (`SpecificationCodec::coreTypes()`; `types()` lists those of a codec, yours included):

| Family | Names |
|---|---|
| Composites | `and`, `or`, `not`, `nor` (`JointDenialSpecification`) |
| Presentation (2.5.0) | `labelled` (`LabelledSpecification`, the human label of a target, example 22) |
| Navigation and typing | `property`, `method` (`MethodParameterizedSpecification`), `type` (`Spec::specify(T)`), `a` (the wrapper of `a()`) |
| Constants and defaults | `true`, `false`, `defaultValue` |
| Comparison | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `looseEq`, `at` (`SameInstantSpecification`), `in`, `isNull`, `notNull` |
| Text | `contains`, `startsWith`, `endsWith`, `regex`, `wildcard`, `wildcardIgnoreCase`, `equalIgnoreCase`, `equalIgnoreCaseAndAccents`, `dateString`, `enumName` |
| Collections and entities | `all`, `any`, `none`, `hasSize`, `hasLength`, `include`, `includePercentageOf`, `allEntities`, `entity` (`UniqueEntitySpecification`) |
| Relative dates | `isPast`, `isFuture`, `isToday` |

- **What does not serialize, and why.** Each is refused with `NonSerializableSpecificationException`, the reason and the place in the tree (`and[1].callback`): a callback (`must()`, `PredicateSpecification`) is code, not data; a `MethodParameterizedSpecification` whose result goes to a closure, for the same reason (`method.callback`; the arguments already refuse closures); `Spec::external()` depends on a data source and the resolver; `FeatureEnabledSpecification` (`$flags->spec()`) depends on the flag service; the guards of the state machine (`inState`, `eventOrigin`, `payloadHas`) are referenced by name through its own `GuardRegistry`; `Spec::inQuery()` holds a repository; the subqueries of `Spec::inSubquery()`/`Spec::existsIn()` (2.5.0) name tables, and a rule document of an editable catalog must not read other tables; the bound rules and document requirements of the rule engine belong to the engine. A specification of your own without the contract is refused too.
- **Your own leaf.** Implement `ISerializableSpecification` (`toArray(): array`, the parameters without `t`; `static fromArray(array $data, SpecificationCodec $codec): static`, with the helpers `expectKeys()`, `string()`, `value()`, `specification()`...) and register it on the codec: `$codec->register('minScore', MinScoreSpecification::class)`. The registry is by exact class: a subclass of a serializable leaf may redefine its predicate, so it is refused until it is registered under a name of its own.
- **Safety.** Decoding never calls `unserialize()` and never instantiates a class named by the document: the class of a node is the one registered in code for its `t`, so `{"t": "App\\Evil"}` is an unknown name. An enum outside the allowlist is refused before its class is touched; the type names of `type`, `allEntities`, `entity` and `enumName` are checked as their constructors check them (`class_exists()`, `enum_exists()`) and never instantiated nor called. The tree is limited to 64 levels and 10 000 nodes by default (`new SpecificationCodec(maxDepth: 32, maxNodes: 500)` for a stricter input), and the JSON text is bounded before it is walked. Every failure is a `SpecificationCodecException` (or its subclass `NonSerializableSpecificationException`) with `$reason` and `$path`. A test builds a document for every hostile case (malformed JSON, an unknown name, a class name in `t`, an enum outside the allowlist, 65 levels, 10 001 nodes) against a leaf whose constructor writes a marker file, and the file never appears unless the codec registered that leaf.
- **A rule of the catalog with a body.** `business_rule.spec_json` holds the document; `RuleSpecificationRegistry` (and so `DynamicSpecificationEngine`) builds a rule that has one from it, before and instead of the handler of its `rule_type`, and a document it cannot read is a `RuleEngineException` naming the rule. `IRuleCatalog::findRuleByCode($code)` finds an active rule by its code, whatever its scope, scenario or product (`PdoRuleCatalog` honours `withAsOf()`). The column is opt-in: `PdoRuleCatalog` reads it only with `columns: PdoRuleCatalog::COLUMNS_SINCE_2_4`, so a table created by an older DDL keeps working untouched; such a table runs once the additive migration commented in the DDL's header (`ALTER TABLE business_rule ADD COLUMN spec_json VARCHAR(4000);`). `VARCHAR(4000)` is the portable size the reference DDLs use for JSON; for larger trees, use a wider column (`TEXT`, `CLOB`) and map it: `columns: ['spec_json' => 'spec_body']`.
- **A flag targeted by a rule.** `FeatureFlagDefinition` takes `targetRuleCode:` (exclusive with `target:`); `FeatureFlags` takes the catalog (`ruleCatalog:`) and, optionally, the codec that reads it (`specificationCodec:`). The rule is looked up and decoded once per service instance, on the first evaluation that needs it, and evaluated as a target in code; `refresh()` reads it again. No catalog, no active rule with that code, a rule without `spec_json` or a document that does not decode: the flag is off with the reason `ERROR` (fail closed), reported once until `refresh()`.
- **Breaking for a class of your own:** `IRuleCatalog` gains `findRuleByCode()` and `IRuleDefinition` gains `getSpecJson()` (the classes of the library implement them; no implementer outside it was found in the consumers measured), and a subclass of a library specification that already declares `toArray()` or `fromArray()` with another signature no longer loads.
