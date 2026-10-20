# Dynamic Rule Engine & Requisitos Documentais (Catálogo de Banco de Dados)

### 7. Dynamic Rule Engine & Requisitos Documentais (Catálogo de Banco de Dados)

Permite compilar regras de negócio dinâmicas e matrizes de documentos obrigatórios diretamente a partir de tabelas relacionais de catálogo (ex.: PostgreSQL, MySQL):

> [!NOTE]
> **Nota de vocabulário.** O motor de regras é inteiro em inglês: getters (`getCode()`, `getIntValue()`, `getViolationMessage()`, `getLegalBasis()`), parâmetros nomeados (`scope:`, `scenario:`), chaves do `fromArray()` e colunas do catálogo (`code`, `rule_type`, `violation_action`, `product_code`, `valid_from`...), chaves de contexto (`product_code`, `plan_code`, `reference_date`), metadados das falhas (`action`, `rule_code`, `legal_basis`...) e os valores de ação `block`/`warn`/`log`. O vocabulário em português da v1.6, legado da v1.7.0 à v1.14.x, é recusado desde a v2.0.0 com `LegacyVocabularyException`, nunca ignorado: chave ou valor de ação em português num catálogo, chave de contexto, papel de tabela, chave de metadado de um handler. O [UPGRADE-2.0.pt-BR.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/UPGRADE-2.0.pt-BR.md) tem a tabela de chaves e o SQL que migra um catálogo; o `PdoRuleCatalog` continua lendo tabelas criadas com o esquema antigo por dois presets (abaixo), que mapeiam só os nomes.

```php
use Antevemus\ASpecification\Spec;

// 1. Configura o Registry com Handlers plugáveis para os tipos de regra
$registry = Spec::ruleRegistry();
$registry->registerClosure('max_occurrences_per_contract', function ($rule) {
    return Spec::specify(Contract::class)
        ->must(fn($c) => $c->getOccurrencesCount() <= $rule->getIntValue(), $rule->getCode(), $rule->getViolationMessage());
});

// 2. Cria a Engine dinâmica conectada ao Catálogo
$engine = Spec::engine($myCatalogRepository, $registry);

// 3. Valida a entidade para o escopo e cenário desejados
$verdict = $engine->validate(
    target: $contract,
    scope: 'rental_contract',
    scenario: 'activation'
);

if ($verdict->hasBlockingErrors()) {
    // Violações graves com ação 'block' (ex: HTTP 403 Forbidden)
    return response()->json([
        'status' => 403,
        'errors' => $verdict->getBlockingFailures(),
        'legal_bases' => $verdict->getLegalBases(),
    ], 403);
}

if ($verdict->hasWarnings()) {
    // Alertas não impeditivos com ação 'warn'
    NotificationService::dispatch($verdict->getWarningFailures());
}
```

O catálogo pode morar em tabelas relacionais: o `PdoRuleCatalog` lê as tabelas do DDL de referência [`resources/sql/rule_catalog.ansi.sql`](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/resources/sql/rule_catalog.ansi.sql) (`business_rule`, `document_requirement_group`, `document_requirement`) em SQL ANSI portável e parametrizado, e responde exatamente como o catálogo em memória. Nomes de tabela e de coluna são configuráveis (`$tables`, `$columns`: coluna canônica => coluna real). Desde a v2.4.0 o corpo de uma regra pode ser uma especificação serializada (`spec_json`, lida com `columns: PdoRuleCatalog::COLUMNS_SINCE_2_4`), montada pelo codec em vez de um handler, e o `findRuleByCode()` acha uma regra pelo código (exemplo 21):

```php
use Antevemus\ASpecification\Engine\PdoRuleCatalog;

// Opcional: lê só as regras vigentes numa data (janela de vigência filtrada no SQL)
$catalog = (new PdoRuleCatalog($pdo))->withAsOf(new DateTimeImmutable('2026-10-09'));

// Tabelas criadas com o esquema em português da v1.5/v1.6 (regra_negocio...) são lidas pelos nomes; as ações precisam estar em inglês desde a v2.0 (UPGRADE-2.0.pt-BR.md)
$legacyCatalog = new PdoRuleCatalog(
    $pdo,
    'rental_guarantee',
    PdoRuleCatalog::legacyPortugueseTables(),
    PdoRuleCatalog::legacyPortugueseColumns()
);

$verdict = Spec::engine($catalog, $registry)->validate(
    target: $contract,
    scope: 'rental_contract',
    context: ['product_code' => 'PROD_A']
);
```

---

Desde a v2.5.0: tipos de regra que só guardam um valor lido pela aplicação (`registerLookupOnly()`, pulados e listados pelo `getSkippedRules()` em vez de `MissingRuleHandlerException`), e a regra mais específica de um tipo (`RulePrecedence`, `findRulesByType()`: o cenário antes da genérica do escopo, depois plano antes de produto antes de nenhum, depois prioridade, depois `code`); veja o exemplo 22.
