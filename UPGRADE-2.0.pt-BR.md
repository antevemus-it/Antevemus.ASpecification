# Atualizando do ASpecification 1.x para a 2.0

<p align="center">
  <a href="UPGRADE-2.0.md">🇺🇸 English</a> &nbsp;|&nbsp; <strong>🇧🇷 Português (Brasil)</strong>
</p>

A versão 2.0.0 remove o que a 1.x anunciou como depreciado e nada além disso: o serializer nativo do PHP (depreciado desde a 1.5.0, o único `unserialize()` da biblioteca) e o vocabulário em português do motor de regras (legado desde a 1.7.0). Ela também leva a resolução de propriedades para um pacote compartilhado, `antevemus/adeep-property-access`, e mantém `Helpers\PropertyAccessor` como fachada depreciada até a 3.0. O [CHANGELOG](CHANGELOG.md) lista cada mudança; este guia é o procedimento.

A versão 1.14.x continua publicada no Packagist: é a versão que lê os formatos antigo e novo, então as conversões abaixo rodam nela, **antes** da atualização.

## Roteiro

1. **Ainda na 1.14.x:** converta para JSON cada arquivo `.bin` de repositório de arquivo ([seção 1](#1-convertendo-arquivos-bin-para-json)). A 2.0.0 os recusa.
2. **Ainda na 1.14.x (ou depois, são só dados):** migre os catálogos de regras que ainda guardam valores ou chaves em português ([seção 3](#3-motor-de-regras-vocabulário-em-português)). A 1.14.x já lê o vocabulário em inglês, então migrar antes permite atualizar sem parada.
3. Corrija o código que usa nomes removidos ([seções 3.4](#34-código) e [4](#4-acessor-de-propriedades)).
4. `composer require antevemus/aspecification:^2.0`. Ele traz uma dependência, do mesmo autor, sem dependências próprias: `antevemus/adeep-property-access`.

## 1. Convertendo arquivos `.bin` para JSON

O `PhpNativeEntitySerializer` foi removido na 2.0.0. Um arquivo gravado com ele é recusado pela 2.0.0 com uma `RepositoryException` que nomeia o arquivo e aponta esta seção: o envelope `serialize()` de um `SingleFileRepository` (qualquer extensão; a 1.x também o gravava para um serializer seu cuja extensão não fosse `json`, veja o fim desta seção) e os arquivos `<id>.bin` de um diretório de `FilePerEntityRepository`. Nada é lido como vazio e nada é sobrescrito.

Você é afetado se algum repositório seu foi construído com `new PhpNativeEntitySerializer(...)` (direto, ou por `InMemoryAndFileRepository::create()`). Repositórios construídos com `JsonEntitySerializer` não precisam de nada: a 2.0.0 lê os documentos deles como estão.

Rode isto na **1.14.x**, que lê os dois formatos. Carrega as entidades com o serializer nativo, grava com o JSON e confere que cada entidade está no arquivo novo:

```php
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Repositories\File\FilePerEntityRepository;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Repositories\Serialization\PhpNativeEntitySerializer;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;

/** O serializer nativo da 1.14 (depreciado: o construtor emite E_USER_DEPRECATED, silenciado aqui). */
function nativeSerializer(string $entityClass, array $extraClasses): PhpNativeEntitySerializer
{
    set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
    try {
        return new PhpNativeEntitySerializer($entityClass, $extraClasses);
    } finally {
        restore_error_handler();
    }
}

/** SingleFileRepository: um documento .bin -> um documento .json. Devolve o número de entidades. */
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
 * FilePerEntityRepository: <dir>/<id>.bin -> <dir>/<id>.json; depois os .bin vão para
 * $backupDirectory (a 2.0.0 recusa um diretório que ainda os tenha). Devolve o número de entidades.
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

// Uma chamada por arquivo ou diretório, com a sua classe de entidade:
convertSingleFile('storage/orders.bin', 'storage/orders.json', Order::class);
convertDirectory('storage/customers', Customer::class, 'storage/customers-bin-backup');
```

- `$extraClasses` lista cada classe guardada nas entidades além de `DateTime`/`DateTimeImmutable` (value objects, enums), como a whitelist do serializer nativo sempre exigiu; uma classe que falte nela faz a carga falhar na 1.14 também.
- O documento JSON guarda as mesmas entidades, ids e valores (propriedades privadas e readonly, datas com o fuso, objetos aninhados, arrays). Aponte o repositório para o caminho `.json` e troque o serializer dele por `JsonEntitySerializer` antes de atualizar.
- Guarde os `.bin` (o diretório de backup, ou o documento original) até a aplicação rodar na 2.0.0.
- Esta receita foi executada na v1.14.0 real sobre arquivos gravados pela v1.14.0; a 2.0.0 lê os arquivos convertidos com as mesmas entidades (fixtures em `tests/Fixtures/file_repository/v1.14.0/`, conferidas pelo Módulo 5).

**Um serializer seu cuja extensão não é `json`.** A 1.x gravava o envelope `serialize()` de um `SingleFileRepository` para todo serializer assim, não só para o nativo, e a 2.0.0 o recusa do mesmo jeito. O seu serializer continua funcionando na 2.0.0 (o envelope é JSON qualquer que seja o serializer); converta o documento na 1.14.x apresentando-o como JSON em uma gravação:

```php
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;

/** Na 1.14.x: apresenta um serializer como JSON, para o SingleFileRepository gravar o envelope JSON. */
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

/** SingleFileRepository com um serializer seu: envelope serialize() -> envelope JSON. */
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

Na 2.0.0, abra `storage/orders.json` com o próprio `new MySerializer()` (sem o adaptador). Esta receita foi executada na v1.14.0 real com um serializer de linhas; a 2.0.0 leu o documento convertido com as mesmas entidades.

## 2. Repositórios de arquivo: o que mais mudou

Nada a fazer aqui, mas comportamento que você pode notar depois da atualização:

- **O documento de arquivo único é sempre JSON**, qualquer que seja o serializer: o repositório é dono do envelope (`__meta`, `entities`) e o serializer só serializa cada entidade. A saída de um serializer que não produz objeto JSON é guardada como string JSON.
- **Os metadados de persistência por entidade vão para o disco**, ao lado dos dados da entidade, em `__persistence_metadata` (`read_count`, `write_count`, `first_read_at`, `last_read_at`, `first_write_at`, `last_write_at`; datas ISO 8601 com microssegundos), e são hidratados na carga: `getEntityMetaData()` devolve o registro gravado em outra instância ou processo. Até a 1.14 os metadados viviam só na sessão corrente. Documentos da 1.x não têm metadados e são lidos como estão; os metadados aparecem na próxima gravação.
- **`write_count` conta a primeira gravação** (1 depois do primeiro `put()`, como no Domian; 0 na 1.x). Uma entidade lida pela primeira vez de um documento da 1.x tem histórico de gravação desconhecido (datas de gravação nulas).
- **As leituras chegam ao arquivo** com a próxima gravação, `store()` ou `close()`. No modo `ReadWrite`, `SingleFileRepository::close()` relê o documento sob o lock antes de gravá-lo, então não sobrescreve mais o que outra instância ou processo gravou nesse meio-tempo (a 1.x gravava o mapa em memória como estava). Os contadores são estatística, não razão contábil: entre processos, vence quem gravou a entidade por último.
- **Um documento de arquivo único que não seja um documento JSON de entidades é recusado** com `RepositoryException`; a 1.x o lia como repositório vazio e o sobrescrevia na próxima gravação. Arquivo vazio continua sendo repositório vazio. Um bloco `__persistence_metadata` malformado também é recusado.

## 3. Motor de regras: vocabulário em português

O vocabulário em inglês é o canônico desde a 1.7.0; até a 1.14.x o português da 1.6.x ainda era lido. Desde a 2.0.0 ele é recusado com `Engine\Exceptions\LegacyVocabularyException` (uma `RuleEngineException`), que nomeia o nome ou valor em português, o inglês e esta seção. Nunca é ignorado: uma chave que não é lida muda quais regras se aplicam, e um valor de ação trocado pelo padrão transforma um alerta em bloqueio.

| Recusado desde a 2.0.0 | Onde | Use no lugar |
|---|---|---|
| `bloquear`, `alertar`, `apenas_log` (qualquer caixa, espaços em volta) | coluna da ação, `RuleAction::fromOrDefault()`, metadado de falha | `block`, `warn`, `log` |
| `codigo`, `nome`, `descricao`, `tipo_regra`/`tipo`, `acao_ao_violar`/`acao`, `valor_inteiro`, `valor_decimal`, `valor_texto`, `fundamento_legal`, `mensagem_violacao`, `condicional_expressao`, `prioridade`, `escopo`, `cenario`, `parametros`, `ativo`, `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia` | `RuleDefinition::fromArray()` | as chaves em inglês (tabela da 3.3) |
| `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia` | dentro de `parameters` (regras lidas pelo `InMemoryRuleCatalog`) | `product_code`, `plan_code`, `valid_from`, `valid_until` |
| `grupo_codigo`/`grupo`, `codigo_tipo_documento`/`tipo_documento`, `escopo`, `cenario`, `regra_obrigatoriedade`/`regra`, `codigo_set_alternativas`/`set_alt`, `condicional_expressao`/`condicional`, `ordem`, `ativo` | `DocumentRuleDefinition::fromArray()` | as chaves em inglês (tabela da 3.3) |
| `codigo_produto`, `codigo_plano`, `data_referencia` | contexto da validação (`validate(context:)`, `findRules(filters:)`) | `product_code`, `plan_code`, `reference_date` |
| `regra`, `grupo`, `grupo_tipo` | papéis de tabela de `new PdoRuleCatalog(..., $tables)` | `rule`, `group`, `requirement` |
| `acao`, `acao_ao_violar`, `fundamento_legal` | metadado de falha gravado por um handler de regra | `action`, `violation_action`, `legal_basis` |
| `hasDocumento()`, `getDocumentos()`, `->documentos`, `['documentos']` | portador de documentos lido pelo avaliador padrão | `hasDocument()`, `getDocuments()`, `->documents`, `['documents']` |

Os únicos **valores** em português que uma tabela de catálogo pode guardar são as ações: `requirement_mode` sempre foi `all`/`any`/`one_of_set`. Chaves de aplicabilidade dentro da coluna JSON `parameters` continuam descartadas pelo `PdoRuleCatalog`, em qualquer língua, como na 1.x: as colunas são a fonte.

### 3.1 Tabelas do DDL de referência com ações em português

O DDL de referência da 1.7.0+ ([`resources/sql/rule_catalog.ansi.sql`](resources/sql/rule_catalog.ansi.sql)) tem um `CHECK` que só aceita `block`/`warn`/`log`, então as tabelas dele não guardam valores em português. Uma tabela criada sem essa restrição pode guardar; um `UPDATE` por coluna resolve (SQL ANSI portável, qualquer banco):

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

| Tabela | Coluna | Valor em português → valor em inglês |
|---|---|---|
| `business_rule` | `violation_action` | `bloquear` → `block`, `alertar` → `warn`, `apenas_log` → `log` |
| `document_requirement` | `requirement_mode` | nenhum (sempre `all`, `any`, `one_of_set`) |

### 3.2 Tabelas do esquema em português da 1.5/1.6

Tabelas criadas com o DDL de referência da 1.5.0/1.6.x (`regra_negocio`, `grupo_documento_obrigatorio`, `grupo_documento_obrigatorio_tipo`) e lidas pelos presets `PdoRuleCatalog::legacyPortugueseTables()`/`legacyPortugueseColumns()` guardam as ações em português, e o `CHECK` delas (`chk_regra_negocio_acao`) só aceita os valores em português. Dois caminhos:

**A. Copiar para o DDL de referência (recomendado; qualquer banco, SQLite inclusive).** Crie as tabelas de [`resources/sql/rule_catalog.ansi.sql`](resources/sql/rule_catalog.ansi.sql) e então:

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

Depois construa o catálogo sem os presets (`new PdoRuleCatalog($pdo, $schema)`) e apague as tabelas antigas quando a aplicação estiver rodando nas novas. Um valor de ação que a 1.x não reconhecia (lido como `block`) faz o primeiro `INSERT` falhar no `CHECK`: corrija-o na tabela antiga e rode de novo.

**B. Manter os nomes em português (PostgreSQL, MySQL 8.0.19+, MariaDB 10.2+; não o SQLite, que não remove restrição).** Os presets mapeiam só nomes de tabela e coluna, então continuam funcionando depois que os valores estiverem em inglês:

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

| Tabela | Coluna | Valor em português → valor em inglês |
|---|---|---|
| `regra_negocio` | `acao_ao_violar` | `bloquear` → `block`, `alertar` → `warn`, `apenas_log` → `log` (e o default da coluna) |
| `grupo_documento_obrigatorio_tipo` | `regra_obrigatoriedade` | nenhum (sempre `all`, `any`, `one_of_set`) |

Os comandos da 3.1 e do caminho A rodam na suíte em SQLite (Módulo 16); os caminhos A e B também foram executados no PostgreSQL 17, no MySQL 8.4 e no MariaDB 11.4 antes da publicação.

### 3.3 Catálogos montados em código, arrays ou JSON

Linhas entregues a `RuleDefinition::fromArray()`/`DocumentRuleDefinition::fromArray()` (um `SELECT *` seu, um arquivo JSON, um seeder) precisam das chaves em inglês:

| Chave em português (1.6.x) | Chave em inglês |
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

Uma linha que traga as duas chaves também é recusada (a 1.x deixava o inglês vencer e ignorava a outra): tire a chave em português.

### 3.4 Código

- **Getters removidos** (depreciados desde a 1.7.0): `RuleDefinition::getCodigo()` → `getCode()`, `getNome()` → `getName()`, `getDescricao()` → `getDescription()`, `getTipoRegra()` → `getRuleType()`, `getAcaoAoViolar()` → `getViolationAction()`, `getValorInteiro()` → `getIntValue()`, `getValorDecimal()` → `getDecimalValue()`, `getValorTexto()` → `getTextValue()`, `getFundamentoLegal()` → `getLegalBasis()`, `getMensagemViolacao()` → `getViolationMessage()`, `getCondicionalExpressao()` → `getConditionExpression()`, `getPrioridade()` → `getPriority()`, `getEscopo()` → `getScope()`, `getCenario()` → `getScenario()`, `getParametros()` → `getParameters()`; `DocumentRuleDefinition::getGrupoCodigo()` → `getGroupCode()`, `getCodigoTipoDocumento()` → `getDocumentTypeCode()`, `getEscopo()` → `getScope()`, `getCenario()` → `getScenario()`, `getRegraObrigatoriedade()` → `getRequirementMode()`, `getCodigoSetAlternativas()` → `getAlternativeSetCode()`, `getCondicionalExpressao()` → `getConditionExpression()`, `getOrdem()` → `getSortOrder()`.
- **Chaves de metadado das falhas**: as duplicatas em português não são mais gravadas. Leia `action` (não `acao`), `rule_code` (`regra`), `rule_type` (`tipo_regra`), `rule_name` (`nome_regra`), `priority` (`prioridade`), `original_message` (`mensagem_original`), `legal_basis` (`fundamento_legal`), `original_messages` (`mensagens_originais`), `original_failures` (`falhas_originais`); nas falhas de documento `document_type` (`tipo_documento`), `group_code` (`grupo`), `requirement_mode` (`regra`); nas de conjunto de documentos `alternative_set` (`set_alternativas`), `mode` (`modo`), `accepted_documents` (`documentos_aceitos`), `codes` (`codigos`), `present` (`presentes`), `expected` (`esperado`), `received` (`recebido`). Um handler que grava a sua ação ou o fundamento legal usa `action`/`violation_action` e `legal_basis`.
- **Chaves de contexto**: `product_code`, `plan_code`, `reference_date` (não `codigo_produto`, `codigo_plano`, `data_referencia`).
- **Papéis de tabela do `PdoRuleCatalog`**: `rule`, `group`, `requirement` (não `regra`, `grupo`, `grupo_tipo`). `legacyPortugueseTables()` já devolve os papéis em inglês.
- **Portadores de documentos** lidos pelo avaliador padrão: `hasDocument()`, `getDocuments()`, `->documents`, `['documents']`. Um portador que só expõe os nomes em português agora é erro de avaliação (falha bloqueante que carrega a `LegacyVocabularyException`), nunca "documento ausente".
- `RuleAction::LEGACY_PORTUGUESE_VALUES`, `RuleDefinition::LEGACY_APPLICABILITY_COLUMNS`, `InMemoryRuleCatalog::LEGACY_FILTER_KEYS` e `PdoRuleCatalog::LEGACY_TABLE_ROLES` ficam: listam os nomes recusados. `PdoRuleCatalog::legacyPortugueseTables()`/`legacyPortugueseColumns()` também ficam (mapeiam nomes, não valores).

## 4. Acessor de propriedades

A resolução de propriedades da biblioteca (e do ALinq) mora no `antevemus/adeep-property-access` 1.x, um pacote do mesmo autor, sem dependências. Todo leitor da biblioteca o chama direto.

- **`Helpers\PropertyAccessor` é uma fachada depreciada** (`@deprecated 2.0.0`, removida na 3.0.0) com a mesma API: `getValue()`, `hasProperty()`, `getAccessor()`. Ela não emite aviso em tempo de execução (fica no caminho quente). Troque por `Antevemus\ADeepPropertyAccess\PropertyAccess::getValue()`, `hasProperty()` e `accessor()` (atenção ao nome: `getAccessor()` → `accessor()`).
- **`#[Specifiable]`** desta biblioteca continua funcionando e continua sendo o que se usa; `#[Antevemus\ADeepPropertyAccess\Attributes\Specifiable]` e o do ALinq valem como o mesmo marcador, na leitura e na gravação (`updateWithDelta()`).
- **Respostas que mudaram**, todas nas bordas (as duas cópias do acessor na 1.x davam a mesma resposta nos 35 casos comparados antes da extração):
  - nome vazio em objeto dá `null` em `getValue()` (a 1.x lançava `InvalidArgumentException`); `hasProperty()` continua respondendo `false`, e nome vazio nunca chama um `get()`, `is()` ou `has()` públicos;
  - um objeto `ArrayAccess` responde só pelos offsets: `hasProperty()` não vê mais as propriedades públicas dele (a 1.x olhava pelo cast `(array)`); uma chave que guarda `null` existe (`offsetExists()`);
  - propriedade pública removida com `unset` e `__get()` sem `__isset()` dá `null` (a 1.x caía no `__get()`);
  - método público com parâmetro obrigatório não é getter: é pulado, e o passo seguinte responde (a 1.x chamava `getName()` sem argumento e lançava `ArgumentCountError`, enquanto `hasProperty()` respondia `true`); parâmetro opcional ou variádico continua getter;
  - método alcançável só por `__call()` nunca é chamado, e nome começado por `__` (`__construct`...) nunca é candidato.
- Três leitores internos que tinham resolução própria agora usam a compartilhada: `AbstractCompositeSpecification::getPropertyValue()` (também tenta `x()` e propriedades `#[Specifiable]` de ancestrais); a guarda (`condition_expression`) de um requisito documental, que agora tenta o getter antes da propriedade pública e também lê `isX()`, `hasX()`, `x()` e propriedades `#[Specifiable]`; e a checagem do `PdoRepository` que decide se uma especificação pode nomear uma coluna mapeada, então uma coluna privada cujo único getter tem parâmetro obrigatório é recusada no SQL como em memória.

## 5. ALinq

A integração opcional aceita `antevemus/alinq-collection` `^1.5.1 || ^2.0`. O ALinq 2.0 só remove `extract()` e passa a usar o mesmo acessor compartilhado; a ponte desta biblioteca não usa nenhum dos dois.
