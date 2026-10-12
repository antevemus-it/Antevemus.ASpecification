# Upgrading from ASpecification 1.x to 2.0

<p align="center">
  <strong>🇺🇸 English</strong> &nbsp;|&nbsp; <a href="UPGRADE-2.0.pt-BR.md">🇧🇷 Português (Brasil)</a>
</p>

Version 2.0.0 removes what 1.x announced as deprecated and nothing else: the PHP-native serializer (deprecated since 1.5.0, the only `unserialize()` of the library) and the Portuguese vocabulary of the rule engine (legacy since 1.7.0). It also moves the property resolution to a shared package, `antevemus/adeep-property-access`, keeping `Helpers\PropertyAccessor` as a deprecated facade until 3.0. The [CHANGELOG](CHANGELOG.md) lists every change; this guide is the procedure.

Version 1.14.x stays published on Packagist: it is the version that reads both the old and the new formats, so the conversions below run on it, **before** the upgrade.

## Checklist

1. **Still on 1.14.x:** convert every `.bin` file of a file repository to JSON ([section 1](#1-converting-bin-files-to-json)). 2.0.0 refuses them.
2. **Still on 1.14.x (or after, it is data only):** migrate the rule catalogs that still hold Portuguese values or keys ([section 3](#3-rule-engine-portuguese-vocabulary)). 1.14.x already reads the English vocabulary, so migrating first allows an upgrade without downtime.
3. Fix the code that uses removed names ([sections 3.4](#34-code) and [4](#4-property-accessor)).
4. `composer require antevemus/aspecification:^2.0`. It brings one dependency, from the same author, with no dependencies of its own: `antevemus/adeep-property-access`.

## 1. Converting `.bin` files to JSON

`PhpNativeEntitySerializer` was removed in 2.0.0. A file written with it is refused by 2.0.0 with a `RepositoryException` that names the file and points to this section: the `serialize()` envelope of a `SingleFileRepository` (any extension; 1.x also wrote it for a serializer of your own whose extension was not `json`, see the end of this section) and the `<id>.bin` files of a `FilePerEntityRepository` directory. Nothing is read as empty and nothing is overwritten.

You are affected if a repository of yours was built with `new PhpNativeEntitySerializer(...)` (directly, or through `InMemoryAndFileRepository::create()`). Repositories built with `JsonEntitySerializer` need nothing: 2.0.0 reads their documents as they are.

Run this on **1.14.x**, which reads both formats. It loads the entities with the native serializer and writes them with the JSON one, then checks that every entity is in the new file:

```php
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Repositories\File\FilePerEntityRepository;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Repositories\Serialization\PhpNativeEntitySerializer;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;

/** The native serializer of 1.14 (deprecated: its constructor raises E_USER_DEPRECATED, silenced here). */
function nativeSerializer(string $entityClass, array $extraClasses): PhpNativeEntitySerializer
{
    set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
    try {
        return new PhpNativeEntitySerializer($entityClass, $extraClasses);
    } finally {
        restore_error_handler();
    }
}

/** SingleFileRepository: one .bin document -> one .json document. Returns the number of entities. */
function convertSingleFile(string $binPath, string $jsonPath, string $entityClass, array $extraClasses = []): int
{
    $source = new SingleFileRepository($binPath, $entityClass, PersistenceDefinition::ReadOnly, nativeSerializer($entityClass, $extraClasses));
    $entities = $source->findAll(new AllEntitiesSpecification($entityClass));

    $target = new SingleFileRepository($jsonPath, $entityClass, PersistenceDefinition::ReadWrite, new JsonEntitySerializer($entityClass));
    $target->putAll($entities);

    $check = new SingleFileRepository($jsonPath, $entityClass, PersistenceDefinition::ReadOnly, new JsonEntitySerializer($entityClass));
    foreach ($entities as $entity) {
        if (!$check->contains($entity)) {
            throw new RuntimeException("{$jsonPath}: entity {$entity->getEntityId()} was not converted");
        }
    }

    return count($entities);
}

/**
 * FilePerEntityRepository: <dir>/<id>.bin -> <dir>/<id>.json, then the .bin files are moved to
 * $backupDirectory (2.0.0 refuses a directory that still holds them). Returns the number of entities.
 */
function convertDirectory(string $directory, string $entityClass, string $backupDirectory, array $extraClasses = []): int
{
    $source = new FilePerEntityRepository($directory, $entityClass, PersistenceDefinition::ReadOnly, nativeSerializer($entityClass, $extraClasses));
    $entities = $source->findAll(new AllEntitiesSpecification($entityClass));

    $target = new FilePerEntityRepository($directory, $entityClass, PersistenceDefinition::ReadWrite, new JsonEntitySerializer($entityClass));
    $target->putAll($entities);
    foreach ($entities as $entity) {
        if (!$target->contains($entity)) {
            throw new RuntimeException("{$directory}: entity {$entity->getEntityId()} was not converted");
        }
    }

    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0777, true)) {
        throw new RuntimeException("cannot create {$backupDirectory}");
    }
    foreach (glob(rtrim($directory, '/') . '/*.bin') as $binFile) {
        rename($binFile, rtrim($backupDirectory, '/') . '/' . basename($binFile));
    }

    return count($entities);
}

// One call per file or directory, with your entity class:
convertSingleFile('storage/orders.bin', 'storage/orders.json', Order::class);
convertDirectory('storage/customers', Customer::class, 'storage/customers-bin-backup');
```

- `$extraClasses` lists every class held by the entities besides `DateTime`/`DateTimeImmutable` (value objects, enums), as the native serializer's whitelist always required; a class missing from it fails the load on 1.14 too.
- The JSON document keeps the same entities, ids and values (private and readonly properties, dates with their offset, nested objects, arrays). Point the repository to the `.json` path and switch its serializer to `JsonEntitySerializer` before upgrading.
- Keep the `.bin` files (the backup directory, or the original document) until the application runs on 2.0.0.
- This recipe was run on the real v1.14.0 against files written by v1.14.0; 2.0.0 reads the converted files with the same entities (fixtures in `tests/Fixtures/file_repository/v1.14.0/`, checked by Module 5).

**A serializer of your own whose extension is not `json`.** 1.x wrote the `serialize()` envelope of a `SingleFileRepository` for every such serializer, not only for the native one, and 2.0.0 refuses it the same way. Your serializer keeps working in 2.0.0 (the envelope is JSON whatever the serializer); convert the document on 1.14.x by presenting it as JSON for one write:

```php
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;

/** On 1.14.x: presents a serializer as JSON, so that SingleFileRepository writes the JSON envelope. */
final class JsonEnvelopeAdapter implements IEntitySerializer
{
    public function __construct(private readonly IEntitySerializer $inner)
    {
    }

    public function serialize(IEntity $entity): string
    {
        return $this->inner->serialize($entity);
    }

    public function deserialize(string $data, string $entityClass): IEntity
    {
        return $this->inner->deserialize($data, $entityClass);
    }

    public function getContentType(): string
    {
        return $this->inner->getContentType();
    }

    public function getFileExtension(): string
    {
        return 'json';
    }
}

/** SingleFileRepository with a serializer of your own: serialize() envelope -> JSON envelope. */
function convertEnvelope(string $oldPath, string $newPath, string $entityClass, IEntitySerializer $serializer): int
{
    $source = new SingleFileRepository($oldPath, $entityClass, PersistenceDefinition::ReadOnly, $serializer);
    $entities = $source->findAll(new AllEntitiesSpecification($entityClass));

    $target = new SingleFileRepository($newPath, $entityClass, PersistenceDefinition::ReadWrite, new JsonEnvelopeAdapter($serializer));
    $target->putAll($entities);

    return count($entities);
}

convertEnvelope('storage/orders.store', 'storage/orders.json', Order::class, new MySerializer());
```

On 2.0.0, open `storage/orders.json` with `new MySerializer()` itself (no adapter). This recipe was run on the real v1.14.0 with a line-based serializer; 2.0.0 read the converted document with the same entities.

## 2. File repositories: what else changed

Nothing to do here, but behaviour you may observe after the upgrade:

- **The single-file document is always JSON**, whatever the serializer: the repository owns the envelope (`__meta`, `entities`) and the serializer only serializes each entity. The output of a serializer that does not produce a JSON object is stored as a JSON string.
- **Per-entity persistence metadata is written to disk**, next to the entity data, under `__persistence_metadata` (`read_count`, `write_count`, `first_read_at`, `last_read_at`, `first_write_at`, `last_write_at`; ISO 8601 timestamps with microseconds), and hydrated on load: `getEntityMetaData()` returns the persisted record in a new instance or process. Until 1.14 the metadata lived only in the current session. Documents of 1.x have no metadata and are read as they are; the metadata appears with the next write.
- **`write_count` counts the first write** (1 after the first `put()`, as in Domian; 0 in 1.x). An entity first read from a 1.x document has an unknown write history (null write timestamps).
- **Reads reach the file** with the next write, `store()` or `close()`. In `ReadWrite` mode, `SingleFileRepository::close()` re-reads the document under the lock before writing it back, so it no longer overwrites what another instance or process wrote meanwhile (1.x stored its in-memory map as it was). Counters are statistics, not a ledger: between processes the last writer of an entity wins.
- **A single-file document that is not a JSON document of entities is refused** with `RepositoryException`; 1.x read it as an empty repository and overwrote it on the next write. An empty file is still an empty repository. A malformed `__persistence_metadata` block is refused too.

## 3. Rule engine: Portuguese vocabulary

The English vocabulary is canonical since 1.7.0; until 1.14.x the Portuguese one of 1.6.x was still read. Since 2.0.0 it is refused with `Engine\Exceptions\LegacyVocabularyException` (a `RuleEngineException`), which names the Portuguese name or value, the English one and this section. It is never ignored: a key that is not read changes which rules apply, and an action value mapped to the default turns a warning into a block.

| Refused since 2.0.0 | Where | Use instead |
|---|---|---|
| `bloquear`, `alertar`, `apenas_log` (any case, surrounding spaces) | action column, `RuleAction::fromOrDefault()`, failure metadata | `block`, `warn`, `log` |
| `codigo`, `nome`, `descricao`, `tipo_regra`/`tipo`, `acao_ao_violar`/`acao`, `valor_inteiro`, `valor_decimal`, `valor_texto`, `fundamento_legal`, `mensagem_violacao`, `condicional_expressao`, `prioridade`, `escopo`, `cenario`, `parametros`, `ativo`, `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia` | `RuleDefinition::fromArray()` | the English keys (table in 3.3) |
| `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia` | inside `parameters` (rules read by `InMemoryRuleCatalog`) | `product_code`, `plan_code`, `valid_from`, `valid_until` |
| `grupo_codigo`/`grupo`, `codigo_tipo_documento`/`tipo_documento`, `escopo`, `cenario`, `regra_obrigatoriedade`/`regra`, `codigo_set_alternativas`/`set_alt`, `condicional_expressao`/`condicional`, `ordem`, `ativo` | `DocumentRuleDefinition::fromArray()` | the English keys (table in 3.3) |
| `codigo_produto`, `codigo_plano`, `data_referencia` | validation context (`validate(context:)`, `findRules(filters:)`) | `product_code`, `plan_code`, `reference_date` |
| `regra`, `grupo`, `grupo_tipo` | table roles of `new PdoRuleCatalog(..., $tables)` | `rule`, `group`, `requirement` |
| `acao`, `acao_ao_violar`, `fundamento_legal` | failure metadata written by a rule handler | `action`, `violation_action`, `legal_basis` |
| `hasDocumento()`, `getDocumentos()`, `->documentos`, `['documentos']` | document holder read by the default document evaluator | `hasDocument()`, `getDocuments()`, `->documents`, `['documents']` |

The only Portuguese **values** a catalog table can hold are the actions: `requirement_mode` was always `all`/`any`/`one_of_set`. Applicability keys inside the `parameters` JSON column are still dropped by `PdoRuleCatalog`, in either language, as in 1.x: the columns are the source.

### 3.1 Tables of the reference DDL with Portuguese action values

The reference DDL of 1.7.0+ ([`resources/sql/rule_catalog.ansi.sql`](resources/sql/rule_catalog.ansi.sql)) has a `CHECK` that only accepts `block`/`warn`/`log`, so its tables cannot hold Portuguese values. A table created without that constraint can; one `UPDATE` per column fixes it (portable ANSI SQL, every database):

<!-- upgrade-sql: reference-schema-values -->
```sql
UPDATE business_rule
   SET violation_action = CASE LOWER(TRIM(violation_action))
                              WHEN 'bloquear'   THEN 'block'
                              WHEN 'alertar'    THEN 'warn'
                              WHEN 'apenas_log' THEN 'log'
                          END
 WHERE LOWER(TRIM(violation_action)) IN ('bloquear', 'alertar', 'apenas_log');
```

| Table | Column | Portuguese value → English value |
|---|---|---|
| `business_rule` | `violation_action` | `bloquear` → `block`, `alertar` → `warn`, `apenas_log` → `log` |
| `document_requirement` | `requirement_mode` | none (always `all`, `any`, `one_of_set`) |

### 3.2 Tables of the Portuguese schema of 1.5/1.6

Tables created with the reference DDL of 1.5.0/1.6.x (`regra_negocio`, `grupo_documento_obrigatorio`, `grupo_documento_obrigatorio_tipo`) and read through the presets `PdoRuleCatalog::legacyPortugueseTables()`/`legacyPortugueseColumns()` hold the actions in Portuguese, and their `CHECK` constraint (`chk_regra_negocio_acao`) only accepts the Portuguese values. Two ways out:

**A. Copy into the reference DDL (recommended; every database, SQLite included).** Create the tables of [`resources/sql/rule_catalog.ansi.sql`](resources/sql/rule_catalog.ansi.sql), then:

<!-- upgrade-sql: portuguese-schema-to-reference -->
```sql
INSERT INTO business_rule (code, name, description, rule_type, violation_action, int_value, decimal_value,
                           text_value, legal_basis, violation_message, condition_expression, priority,
                           scope, scenario, parameters, product_code, plan_code, valid_from, valid_until, active)
SELECT codigo, nome, descricao, tipo_regra,
       CASE LOWER(TRIM(acao_ao_violar))
           WHEN 'bloquear'   THEN 'block'
           WHEN 'alertar'    THEN 'warn'
           WHEN 'apenas_log' THEN 'log'
           ELSE LOWER(TRIM(acao_ao_violar))
       END,
       valor_inteiro, valor_decimal, valor_texto, fundamento_legal, mensagem_violacao, condicional_expressao,
       prioridade, escopo, cenario, parametros, codigo_produto, codigo_plano, data_inicio_vigencia,
       data_fim_vigencia, active
  FROM regra_negocio;

INSERT INTO document_requirement_group (id, code, scope, scenario, sort_order, active)
SELECT id, codigo, escopo, cenario, ordem, active
  FROM grupo_documento_obrigatorio;

INSERT INTO document_requirement (id, group_id, document_type_code, requirement_mode, alternative_set_code,
                                  condition_expression, sort_order, active)
SELECT id, grupo_id, codigo_tipo_documento, regra_obrigatoriedade, codigo_set_alternativas,
       condicional_expressao, ordem, active
  FROM grupo_documento_obrigatorio_tipo;
```

Then build the catalog without the presets (`new PdoRuleCatalog($pdo, $schema)`) and drop the old tables once the application runs on the new ones. An action value that 1.x did not recognize (read as `block`) fails the first `INSERT` on the `CHECK` constraint: fix it in the old table and run it again.

**B. Keep the Portuguese table names (PostgreSQL, MySQL 8.0.19+, MariaDB 10.2+; not SQLite, which cannot drop a constraint).** The presets map table and column names only, so they keep working once the values are English:

<!-- upgrade-sql: portuguese-schema-in-place -->
```sql
ALTER TABLE regra_negocio DROP CONSTRAINT chk_regra_negocio_acao;

UPDATE regra_negocio
   SET acao_ao_violar = CASE LOWER(TRIM(acao_ao_violar))
                            WHEN 'bloquear'   THEN 'block'
                            WHEN 'alertar'    THEN 'warn'
                            WHEN 'apenas_log' THEN 'log'
                            ELSE LOWER(TRIM(acao_ao_violar))
                        END;

ALTER TABLE regra_negocio ADD CONSTRAINT chk_regra_negocio_acao CHECK (acao_ao_violar IN ('block', 'warn', 'log'));
ALTER TABLE regra_negocio ALTER COLUMN acao_ao_violar SET DEFAULT 'block';
```

| Table | Column | Portuguese value → English value |
|---|---|---|
| `regra_negocio` | `acao_ao_violar` | `bloquear` → `block`, `alertar` → `warn`, `apenas_log` → `log` (and the column default) |
| `grupo_documento_obrigatorio_tipo` | `regra_obrigatoriedade` | none (always `all`, `any`, `one_of_set`) |

The statements of 3.1 and of way A run in the suite on SQLite (Module 16); ways A and B were also run against PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 before the release.

### 3.3 Catalogs built in code, arrays or JSON

Rows handed to `RuleDefinition::fromArray()`/`DocumentRuleDefinition::fromArray()` (a `SELECT *` of your own, a JSON file, a seeder) must use the English keys:

| Portuguese key (1.6.x) | English key |
|---|---|
| `codigo` | `code` |
| `nome` | `name` |
| `descricao` | `description` |
| `tipo_regra`, `tipo` | `rule_type` |
| `acao_ao_violar`, `acao` | `violation_action` |
| `valor_inteiro` | `int_value` |
| `valor_decimal` | `decimal_value` |
| `valor_texto` | `text_value` |
| `fundamento_legal` | `legal_basis` |
| `mensagem_violacao` | `violation_message` |
| `condicional_expressao`, `condicional` | `condition_expression` |
| `prioridade` | `priority` |
| `escopo` | `scope` |
| `cenario` | `scenario` |
| `parametros` | `parameters` |
| `ativo` | `active` |
| `codigo_produto` | `product_code` |
| `codigo_plano` | `plan_code` |
| `data_inicio_vigencia` | `valid_from` |
| `data_fim_vigencia` | `valid_until` |
| `grupo_codigo`, `grupo` | `group_code` |
| `codigo_tipo_documento`, `tipo_documento` | `document_type_code` |
| `regra_obrigatoriedade`, `regra` | `requirement_mode` |
| `codigo_set_alternativas`, `set_alt` | `alternative_set_code` |
| `ordem` | `sort_order` |

A row that carries both keys is refused too (1.x let the English one win and ignored the other): remove the Portuguese key.

### 3.4 Code

- **Getters removed** (deprecated since 1.7.0): `RuleDefinition::getCodigo()` → `getCode()`, `getNome()` → `getName()`, `getDescricao()` → `getDescription()`, `getTipoRegra()` → `getRuleType()`, `getAcaoAoViolar()` → `getViolationAction()`, `getValorInteiro()` → `getIntValue()`, `getValorDecimal()` → `getDecimalValue()`, `getValorTexto()` → `getTextValue()`, `getFundamentoLegal()` → `getLegalBasis()`, `getMensagemViolacao()` → `getViolationMessage()`, `getCondicionalExpressao()` → `getConditionExpression()`, `getPrioridade()` → `getPriority()`, `getEscopo()` → `getScope()`, `getCenario()` → `getScenario()`, `getParametros()` → `getParameters()`; `DocumentRuleDefinition::getGrupoCodigo()` → `getGroupCode()`, `getCodigoTipoDocumento()` → `getDocumentTypeCode()`, `getEscopo()` → `getScope()`, `getCenario()` → `getScenario()`, `getRegraObrigatoriedade()` → `getRequirementMode()`, `getCodigoSetAlternativas()` → `getAlternativeSetCode()`, `getCondicionalExpressao()` → `getConditionExpression()`, `getOrdem()` → `getSortOrder()`.
- **Failure metadata keys**: the Portuguese duplicates are no longer written. Read `action` (not `acao`), `rule_code` (`regra`), `rule_type` (`tipo_regra`), `rule_name` (`nome_regra`), `priority` (`prioridade`), `original_message` (`mensagem_original`), `legal_basis` (`fundamento_legal`), `original_messages` (`mensagens_originais`), `original_failures` (`falhas_originais`); document failures `document_type` (`tipo_documento`), `group_code` (`grupo`), `requirement_mode` (`regra`); document-set failures `alternative_set` (`set_alternativas`), `mode` (`modo`), `accepted_documents` (`documentos_aceitos`), `codes` (`codigos`), `present` (`presentes`), `expected` (`esperado`), `received` (`recebido`). A handler that records its action or legal basis must use `action`/`violation_action` and `legal_basis`.
- **Context keys**: `product_code`, `plan_code`, `reference_date` (not `codigo_produto`, `codigo_plano`, `data_referencia`).
- **Table roles of `PdoRuleCatalog`**: `rule`, `group`, `requirement` (not `regra`, `grupo`, `grupo_tipo`). `legacyPortugueseTables()` already returns English roles.
- **Document holders** read by the default evaluator: `hasDocument()`, `getDocuments()`, `->documents`, `['documents']`. A holder that only exposes the Portuguese names is now an evaluation error (a blocking failure that carries the `LegacyVocabularyException`), never "document missing".
- `RuleAction::LEGACY_PORTUGUESE_VALUES`, `RuleDefinition::LEGACY_APPLICABILITY_COLUMNS`, `InMemoryRuleCatalog::LEGACY_FILTER_KEYS` and `PdoRuleCatalog::LEGACY_TABLE_ROLES` stay: they list the refused names. `PdoRuleCatalog::legacyPortugueseTables()`/`legacyPortugueseColumns()` stay too (they map names, not values).

## 4. Property accessor

The property resolution of the library (and of ALinq) lives in `antevemus/adeep-property-access` 1.x, a package from the same author with no dependencies. Every reader of the library calls it directly.

- **`Helpers\PropertyAccessor` is a deprecated facade** (`@deprecated 2.0.0`, removed in 3.0.0) with the same API: `getValue()`, `hasProperty()`, `getAccessor()`. It raises no runtime notice (it sits on the hot path). Replace it with `Antevemus\ADeepPropertyAccess\PropertyAccess::getValue()`, `hasProperty()` and `accessor()` (note the name: `getAccessor()` → `accessor()`).
- **`#[Specifiable]`** of this library keeps working and is still the one to use; `#[Antevemus\ADeepPropertyAccess\Attributes\Specifiable]` and ALinq's are accepted as the same marker, on reads and on writes (`updateWithDelta()`).
- **Answers that changed**, all at the edges (both 1.x copies of the accessor gave the same answer on the 35 cases compared before the extraction):
  - an empty name on an object yields `null` from `getValue()` (1.x threw `InvalidArgumentException`); `hasProperty()` still answers `false`, and an empty name never calls a public `get()`, `is()` or `has()`;
  - an `ArrayAccess` object answers through its offsets only: `hasProperty()` no longer sees its public properties (1.x looked through the `(array)` cast); a key holding `null` exists (`offsetExists()`);
  - an unset public property with `__get()` and no `__isset()` yields `null` (1.x fell into `__get()`);
  - a public method with a required parameter is not a getter: it is skipped, and the next step answers (1.x called `getName()` without arguments and threw `ArgumentCountError`, while `hasProperty()` answered `true`); optional and variadic parameters still make a getter;
  - a method reached only through `__call()` is never called, and a name starting with `__` (`__construct`...) is never a candidate.
- Three internal readers that had their own resolution now use the shared one: `AbstractCompositeSpecification::getPropertyValue()` (also tries `x()` and `#[Specifiable]` properties of ancestors); the guard (`condition_expression`) of a document requirement, which now tries the getter before the public property and also reads `isX()`, `hasX()`, `x()` and `#[Specifiable]` properties; and the check of `PdoRepository` that decides whether a specification may name a mapped column, so a private column whose only getter has a required parameter is refused in SQL as it is in memory.

## 5. ALinq

The optional integration accepts `antevemus/alinq-collection` `^1.5.1 || ^2.0`. ALinq 2.0 only removes `extract()` and switches to the same shared accessor; the bridge of this library uses neither.
