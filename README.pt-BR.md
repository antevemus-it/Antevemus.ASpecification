# Antevemus ASpecification

<p align="left">
  <a href="README.md">🇺🇸 English</a> &nbsp;|&nbsp; <strong>🇧🇷 Português (Brasil)</strong>
</p>

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Latest Version](https://img.shields.io/badge/Release-v1.9.0-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v1.9.0)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-20%2F20%20Suites%20Pass%20(5142%20Assertions)%20%2B%20Domian%20Parity%20219-success)](tests/run_all.php)
[![Architecture](https://img.shields.io/badge/Architecture-DDD%20%7C%20Evans%20%26%20Fowler%20Specification-orange)](http://www.martinfowler.com/apsupp/spec.pdf)
[![Upstream: Domian](https://img.shields.io/badge/Origin-Domian%20(Apache%202.0)-brightgreen)](https://domian.sourceforge.net/index.html)
[![Synergy: ALinq](https://img.shields.io/badge/Synergy-Antevemus.AlinqCollection-purple)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **Framework Corporativo do Padrão Specification para PHP 8.2+** (PHP 8.4 só para a integração opcional com o ALinq)  
> Reimplementação PHP independente e não oficial, com evolução moderna, do renomado framework Java [Domian](https://domian.sourceforge.net/index.html), fundamentado no paper seminal [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) de Eric Evans e Martin Fowler. Enriquecido com Fluent Chaining em linguagem natural, Notification Pattern com diagnóstico rico de falhas, Dynamic Rule Engine para catálogos relacionais, SQL Query Visitor multi-SGBD (15 drivers, 10 dialetos SQL), TCriteria Builder para Adianti Framework, repositórios particionados em Grafo Acíclico Dirigido (DAG), persistência híbrida, primitivas avançadas de concorrência, máquina de estados dirigida por especificações e casos de uso com resultado comum aos dois.

---

## 🏛️ Origem & Fundamentação Teórica

O **Antevemus ASpecification** foi concebido sobre sólidos pilares de engenharia de software e Domain-Driven Design (DDD):

1. **O Paper Seminal Original**:
   Baseado no artigo clássico [The Specifications Pattern (PDF)](http://www.martinfowler.com/apsupp/spec.pdf), de **Eric Evans e Martin Fowler (2002)**, que formalizou o encapsulamento de predicados e regras de negócio em objetos combináveis de primeira classe para:
   - **Validação de Objetos**: Verificar se um objeto atende a critérios específicos.
   - **Seleção e Filtragem**: Consultar entidades em repositórios sem vazar SQL ou detalhes de infraestrutura.
   - **Construção e Satisfação de Restrições**: Especificar o que é necessário para instanciar ou transicionar entidades.

2. **A Biblioteca de Origem: Domian (Java)**:
   Este projeto é uma portagem PHP independente e não oficial, com evolução moderna, do framework [Domian (Domain-Driven Design for Java)](https://domian.sourceforge.net/index.html), criado originalmente por **Eirik Torske** e **Bjørn Nordlund**, distribuído sob a **Apache License, Version 2.0**. Não é afiliado, mantido nem endossado pelos autores do Domian: o desenho, a API pública e a semântica seguem as fontes públicas do Domian, e o código PHP foi escrito pela Antevemus. O Domian foi pioneiro em unificar álgebra booleana, teoria dos conjuntos (Venn), especificações de coleções, arquitetura de repositórios particionados em Grafo Acíclico Dirigido (DAG) e sincronização concorrente.

---

## 🌟 Principais Recursos

- 🎯 **Fluent Chaining & DSL em Linguagem Natural**: Escreva especificações expressivas e legíveis como sentenças de domínio (`Spec::specify(Customer::class)->where('gender', is('FEMALE'))->and('membershipDate', isBefore($oneYearAgo))`).
- 🛡️ **Notification Pattern & Diagnóstico Rico (Zero Exceptions)**: Avalie regras sem lançar exceções de fluxo com `evaluate()`, obtendo `SpecificationResult` com lista detalhada de `SpecificationFailure`, códigos de erro (`withCode()`), mensagens de negócio (`because()`) e metadados.
- ⚡ **SQL Query Visitor & Multi-SGBD (Módulo 12)**: Tradução direta da AST de especificações para cláusulas `WHERE` parametrizadas e seguras (`:p1`, `:p2`) com suporte a 15 drivers mapeados em 10 dialetos SQL (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, IBM Db2, IBM Informix, DuckDB e ANSI), com `paginate()` em todo dialeto.
- 🔗 **TCriteria Builder & Adianti Database Bridge (Módulo 13)**: Compilação direta de regras de domínio puro em objetos nativos `TCriteria` e `TFilter` do Adianti Framework, com inversão lógica de De Morgan para negações, preservação rigorosa de parênteses e precedência, e suporte fluente a paginação (`limit`, `offset`), ordenação (`orderBy`, `direction`) e agrupamento (`groupBy`).
- 🧩 **Dynamic Rule Engine & Requisitos Documentais (Módulo 11)**: Compilação dinâmica a partir de catálogos relacionais de banco de dados (`RuleDefinition`), triagem operacional de vereditos por severidade (`BLOCK`, `WARN`, `LOG`), e álgebra de requisitos documentais (`ALL`, `ANY`, `ONE_OF_SET`).
- ✂️ **Satisfação Parcial (`remainderUnsatisfiedBy`)**: Isole cirurgicamente em tempo de execução quais cláusulas específicas falharam para um determinado candidato.
- 📐 **Álgebra Booleana Completa & Subsunção**: Composição lógica rigorosa (`AND`, `OR`, `NOT`, `NOR / Joint Denial`), cálculo de subsunção (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`) e detecção estrutural de tautologia/contradição (`isTautology()`, `isContradiction()`): prova `A ∧ ¬A`, `A ∨ ¬A`, conjunções de operandos disjuntos, `in([])` e as identidades absorventes (`alwaysTrue()`/`alwaysFalse()`); é conservadora (`false` significa "não provado", não há resolução SAT), e o motor de regras aponta regras degeneradas do catálogo como `RuleCompilationWarning`; `simplify()` devolve uma árvore equivalente e menor (identidade, dupla negação, idempotência, subsunção, contradições e tautologias provadas) sem mexer nos nós anotados, com opção no motor de regras (exemplo 12).
- 🗄️ **Arquitetura de Repositórios & Particionamento em Grafo (DAG)**:
  - Descarte antecipado $O(1)$ de ramos em árvores de consulta através de disjunção de especificações, sobre um índice topológico materializado do DAG (`findAll` 45 % a 77 % mais rápido que a caminhada recursiva, ver `tests/Benchmark/REPORT-dag-index.md`).
  - TTL e auto-prune em repositórios voláteis (`withTtl()`, `prune()`, relógio injetável), herdados pelas partições.
  - Implementações em memória (`InMemoryRepository`), nulas (`NullRepository`) e fake (`FakePartitionRepository`).
  - Persistência desacoplada em disco (`FilePerEntityRepository`, `SingleFileRepository`) com serialização plugável (`JsonEntitySerializer`; o `PhpNativeEntitySerializer` está depreciado desde a v1.5.0 e sai na v2.0.0, por ser o único `unserialize()` da biblioteca: para migrar, carregue o `.bin` existente com ele e grave com o serializer JSON).
  - Cache híbrido $L1$ (RAM) + $L2$ (Disco) via `InMemoryAndFileRepository`.
- 🔒 **Controle de Concorrência & Locks Atômicos**: Primitivas `ISynchronizer` ligadas a todo repositório (leitores compartilhados, escritor exclusivo, reentrante por contexto de execução): o `SemaphoreSynchronizer` em processo (contador de permissões, ciente de Fibers) e locks atômicos de arquivo (`flock`) entre processos nos repositórios de arquivo. O `SysVSemaphoreSynchronizer` (`ext-sysvsem`) compartilha o mesmo lock leitores/escritor entre processos por semáforos SysV IPC.
- ⏱️ **Telemetria de Alta Precisão & Benchmarking**: Cronômetro em nanossegundos (`StopWatch` via `hrtime`) e utilitários de diagnóstico hierárquico e consumo de memória (`InstrumentationUtils`).
- 🚦 **Máquina de Estados Dirigida por Especificações (Módulos 17 e 18)**: todo guard é uma `ISpecification`, então a transição negada diz por quê (código, mensagem, propriedade, severidade); regiões paralelas, transições de região, ações nomeadas (`exit` → transição → `entry`), prazos persistidos (duração fixa, ou duração ou instante absoluto lidos do contexto, pausáveis com o saldo guardado enquanto uma condição vale); a definição é dado (builder ou JSON, versionada, com fingerprint, validada na carga); núcleo puro `transition()`/`can()`/`nextEvents()` e uma única porta de escrita, o `MachineRunner` (compare-and-set, chave de idempotência, histórico append-only, outbox transacional), sobre stores InMemory ou PDO com DDL ANSI de referência (exemplo 14).
- 🧭 **Casos de Uso (Módulo 19)**: `IUseCase` + `UseCaseRunner` (middlewares, `TransactionMiddleware`, validação do request em que só `ERROR` bloqueia), `UseCaseResult` com status de domínio fechado e HTTP só na borda (`toHttpStatus()`), nenhuma exceção ou stack trace no resultado, e `StateMachineEventUseCase` expondo um evento da máquina como caso de uso pelo `IOutcome` comum (exemplo 15).

---

## 🎯 Nossos Diferenciais

Enquanto a maioria das bibliotecas de *Specification* no ecossistema PHP se limita a verificações booleanas primitivas (`isSatisfiedBy: bool`) e o framework Java Domian original concentrava-se em reflexão em tempo de execução e coleções em memória, o **Antevemus ASpecification** foi concebido para os desafios de alta complexidade de microsserviços modernos, APIs corporativas e sistemas legados de missão crítica:

| Recurso / Capacidade | Domian (Java Original) | Bibliotecas Comuns de Specification (PHP) | **Antevemus ASpecification** |
| :--- | :---: | :---: | :---: |
| **Notification Pattern (Zero Exceptions)** | ❌ Apenas booleano | ❌ Apenas booleano ou exceptions | ✅ `evaluate()`, lista agregada de `SpecificationFailure`, códigos e razões |
| **Classificação por Severidade** | ❌ Não possui | ❌ Não possui | ✅ `BLOCK` (impeditivo), `WARN` (alerta operacional) e `LOG` (auditoria) |
| **Compilador SQL Multi-SGBD** | ❌ Não possui | ❌ Raro / restrito a 1 banco | ✅ **15 drivers, 10 dialetos** (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, Db2, Informix, DuckDB, ANSI) |
| **Adianti Framework Database Bridge** | ❌ Não aplicável | ❌ Não possui | ✅ `TCriteria` & `TFilter` nativos, De Morgan automático e parênteses estritos |
| **ALinq Synergy & Coleções Funcionais** | ❌ Não possui | ❌ Não possui | ✅ Compilador GoF de AST para predicados LINQ de curto-circuito e `ALinqBridge` |
| **Navegação em Propriedades Aninhadas** | ⚠️ Reflexão estrita | ⚠️ Apenas métodos públicos | ✅ **Dot-notation** (`PropertyAccessor`) em objetos profundos e arrays associativos |
| **Dynamic Rule Engine (Banco de Dados)** | ❌ Não possui | ❌ Não possui | ✅ Compilação dinâmica a partir de catálogos relacionais (`RuleDefinition`) |
| **Álgebra de Requisitos Documentais** | ❌ Não possui | ❌ Não possui | ✅ Modos `ALL`, `ANY` e `ONE_OF_SET` com vereditos agregados |
| **Fluent Chaining & DSL de Domínio** | ⚠️ Básico | ⚠️ Parcial | ✅ Sintaxe em prosa inglesa (`Spec::specify()->where()->and()->or()`) + helpers funcionais (`prop()`, `is()`, `not()`) |
| **Álgebra Booleana de Venn & Remainder** | ✅ Completo | ❌ Inexistente na maioria | ✅ `isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith` e `remainderUnsatisfiedBy` |
| **Particionamento DAG $O(1)$** | ✅ Presente | ❌ Raro | ✅ Repositórios com descarte antecipado de ramos disjuntos |
| **Máquina de Estados com Negação Explicada** | ❌ Não possui | ❌ Não possui | ✅ Guards são especificações: a rejeição traz código, mensagem e severidade; outbox, idempotência e prazos persistidos |
| **Concorrência & Locks** | ⚠️ Java Threads / Locks | ❌ Não suportado | ✅ Repositórios sincronizados (`SemaphoreSynchronizer` reentrante, ciente de Fibers) `SysVSemaphoreSynchronizer` sobre SysV IPC entre processos, e locks `flock` atômicos |
| **Tipagem Estrita e Recursos Modernos** | ⚠️ Java 6/7 Generics | ⚠️ PHP 7.x legado | ✅ **PHP 8.2+** nativo (Enums, First-class callables, Readonly, 8 Traits segregados) |

---

## 📋 Requisitos

- **PHP**: `^8.2` (testado no PHP 8.2 e 8.4). O PHP 8.4 só é exigido pela integração opcional com o ALinq (`Antevemus.AlinqCollection`, Módulo 14), detectada em tempo de execução.
- **Extensões PHP**:
  - `ext-json` (para serialização JSON; obrigatória)
  - `ext-mbstring` (obrigatória desde a v1.5.0: especificações de string case-insensitive com Unicode, `mb_strtolower`)
  - `ext-sysvsem` *(opcional: `SysVSemaphoreSynchronizer`, o lock leitores/escritor compartilhado entre processos por semáforos SysV IPC, Linux/Unix)*
- **Pacote opcional**: `antevemus/alinq-collection` `^1.4.2` para `ALinqBridge` (retornos tipados `IALinqCollection`/`IALinqLazyCollection`), `ALinqSpecificationVisitor` e streaming lazy O(1) sobre `IRepository::iterate()` (exige PHP 8.4; a partir da 1.4.1, o ALinq só lê propriedade não pública marcada com `#[Specifiable]`, a mesma regra deste pacote).

---

## 🚀 Instalação

```bash
composer require antevemus/aspecification
```

---

## 💡 Exemplos de Uso

### 1. Fluent Chaining em Linguagem Natural

Inspirado na sintaxe do Domian em Java, combine regras encadeadas de forma limpa e intuitiva:

```php
use function Antevemus\ASpecification\DSL\specify;
use function Antevemus\ASpecification\DSL\is;
use function Antevemus\ASpecification\DSL\not;
use function Antevemus\ASpecification\DSL\isBefore;
use function Antevemus\ASpecification\DSL\isAfterOrAt;
use function Antevemus\ASpecification\DSL\anyOf;

// Especificação: Clientes do sexo feminino associados há mais de 1 ano,
// OU clientes masculinos com mais de 10 anos de idade.
$loyalFemale = specify(Customer::class)
    ->where('gender', is('FEMALE'))
    ->and('membershipDate', isBefore($oneYearAgo));

$seniorMale = specify(Customer::class)
    ->where('gender', is('MALE'))
    ->and('birthDate', not(isAfterOrAt($tenYearsAgo)));

$spec = anyOf($loyalFemale, $seniorMale);

// Avaliação booleana tradicional
if ($spec->isSatisfiedBy($customer)) {
    // Regra atendida com sucesso!
}
```

---

### 2. Notification Pattern & Diagnóstico Rico (Sem Exceções)

Em cenários corporativos e validação de formulários/APIs, use `evaluate()` para capturar todas as violações sem interromper o fluxo com exceções:

```php
$adultSpec = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('O cliente deve ter atingido a maioridade legal.')
    ->withCode('CLI_001');

$activeSpec = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Apenas cadastros ativos podem receber crédito.')
    ->withCode('CLI_002');

$approvalRule = $adultSpec->and($activeSpec);

$result = $approvalRule->evaluate($customer);

if (!$result->isSatisfied) {
    echo "Falha na validação (" . count($result->failures) . " erros encontrados):\n";
    foreach ($result->failures as $failure) {
        echo sprintf(" - [%s] %s: %s\n", $failure->code, $failure->property, $failure->message);
    }
}
```

**Erros de avaliação e o que mostrar.** Quando uma regra não pode ser avaliada (uma especificação, um handler ou um guard lança), `isError` é `true`, a falha traz a mensagem genérica `SpecificationResult::EVALUATION_ERROR_MESSAGE` com o código da regra, e a exceção fica em `$result->exception`, nunca nas falhas. A biblioteca não faz log por você. Boa prática: mande `$result->exception` para o seu logger, com cuidado para não registrar mais do que o necessário (mensagens de exceção podem conter credenciais, dados pessoais ou SQL); mostre ao usuário só `$failure->message`, que é a sua mensagem de negócio ou a genérica; e nunca mostre a `metadata`, que é diagnóstica e pode conter o valor avaliado (`metadata['actual']`).

---

### 3. Satisfação Parcial (`remainderUnsatisfiedBy`)

Descubra exatamente qual subconjunto de regras falhou para uma entidade:

```php
$onboardingSpec = Spec::specify(User::class)
    ->where('emailVerified', Spec::isTrue())
    ->and('termsAccepted', Spec::isTrue())
    ->and('profileComplete', Spec::isTrue());

$remainder = $onboardingSpec->remainderUnsatisfiedBy($user);

if ($remainder !== null) {
    // $remainder contém APENAS as cláusulas não atendidas pelo usuário!
    echo "Pendências do onboarding: " . (string) $remainder;
}
```

---

### 4. Repositórios Particionados em Grafo (DAG)

Crie repositórios que subdividem coleções em partições lógicas indexadas por especificações:

```php
use Antevemus\ASpecification\Repositories\InMemoryRepository;

$rootRepo = new InMemoryRepository();

// Cria uma partição exclusiva para clientes VIP
$vipSpec = Spec::property('vip', Spec::equalTo(true));
$vipPartition = $rootRepo->makePartition($vipSpec);

// Inserções e consultas no repositório particionado
// ($vipCustomer implementa IEntity, por exemplo estendendo Entities\AbstractUUIDEntity)
$vipPartition->put($vipCustomer);

// Consultas aproveitam descarte O(1) de ramos disjuntos
$results = $vipPartition->findAll(Spec::property('balance', Spec::greaterThan(1000)));
```

---

### 5. Persistência Desacoplada e Cache Híbrido L1/L2

```php
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;

// Repositório com velocidade de leitura em RAM (L1) e durabilidade em disco (L2)
$repo = InMemoryAndFileRepository::create(
    storagePath: '/var/data/customers.json',
    serializer: new JsonEntitySerializer(Customer::class)
);

$repo->put($newCustomer); // Salva em memória e sincroniza no arquivo
```

---

### 6. Telemetria e Benchmarking

```php
use Antevemus\ASpecification\Helpers\StopWatch;
use Antevemus\ASpecification\Helpers\InstrumentationUtils;

$watch = StopWatch::createStarted();

// Executa operação de alta volumetria
$entities = $repo->findAll($complexSpec);

$watch->stop();
echo "Consulta executada em: " . $watch->formatElapsed() . "\n";
echo InstrumentationUtils::formatMemoryUsage() . "\n";
```

---

### 7. Dynamic Rule Engine & Requisitos Documentais (Catálogo de Banco de Dados)

Permite compilar regras de negócio dinâmicas e matrizes de documentos obrigatórios diretamente a partir de tabelas relacionais de catálogo (ex.: PostgreSQL, MySQL):

> **Nota de vocabulário.** Desde a v1.7.0 o motor de regras é inteiro em inglês: getters (`getCode()`, `getIntValue()`, `getViolationMessage()`, `getLegalBasis()`), parâmetros nomeados (`scope:`, `scenario:`), chaves do `fromArray()` e colunas do catálogo (`code`, `rule_type`, `violation_action`, `product_code`, `valid_from`...), chaves de contexto (`product_code`, `plan_code`, `reference_date`) e os valores de ação `block`/`warn`/`log`. O vocabulário em português da v1.6 é legado até a v2.0.0: os getters em português continuam em `RuleDefinition`/`DocumentRuleDefinition` como aliases depreciados, o `fromArray()`, os catálogos e o `RuleAction::fromOrDefault()` ainda leem as chaves e os valores de ação em português, e o `PdoRuleCatalog` lê tabelas criadas com o esquema antigo por dois presets (abaixo). Argumentos nomeados em português (`escopo:`) deixaram de funcionar, porque o PHP não tem alias de parâmetro; a tabela completa está no [CHANGELOG](CHANGELOG.md).

```php
use Antevemus\ASpecification\Spec;

// 1. Configura o Registry com Handlers plugáveis para os tipos de regra
$registry = Spec::ruleRegistry();
$registry->registerClosure('max_ocorrencias_por_contrato', function ($rule) {
    return Spec::specify(Contrato::class)
        ->must(fn($c) => $c->getOcorrenciasCount() <= $rule->getIntValue(), $rule->getCode(), $rule->getViolationMessage());
});

// 2. Cria a Engine dinâmica conectada ao Catálogo
$engine = Spec::engine($meuCatalogoRepository, $registry);

// 3. Valida a entidade para o escopo e cenário desejados
$verdict = $engine->validate(
    target: $contrato,
    scope: 'contrato_locacao',
    scenario: 'contrato_locacao_ativacao'
);

if ($verdict->hasBlockingErrors()) {
    // Violações graves com ação 'block' (ex: HTTP 403 Forbidden)
    return response()->json([
        'status' => 403,
        'erros' => $verdict->getBlockingFailures(),
        'fundamentos_legais' => $verdict->getLegalBases(),
    ], 403);
}

if ($verdict->hasWarnings()) {
    // Alertas não impeditivos com ação 'warn'
    NotificationService::dispatch($verdict->getWarningFailures());
}
```

O catálogo pode morar em tabelas relacionais: o `PdoRuleCatalog` lê as tabelas do DDL de referência [`resources/sql/rule_catalog.ansi.sql`](resources/sql/rule_catalog.ansi.sql) (`business_rule`, `document_requirement_group`, `document_requirement`) em SQL ANSI portável e parametrizado, e responde exatamente como o catálogo em memória. Nomes de tabela e de coluna são configuráveis (`$tables`, `$columns`: coluna canônica => coluna real):

```php
use Antevemus\ASpecification\Engine\PdoRuleCatalog;

// Opcional: lê só as regras vigentes numa data (janela de vigência filtrada no SQL)
$catalogo = (new PdoRuleCatalog($pdo))->withAsOf(new DateTimeImmutable('2026-10-09'));

// Tabelas criadas com o esquema em português da v1.5/v1.6 (regra_negocio...) continuam legíveis, sem migração
$catalogoLegado = new PdoRuleCatalog(
    $pdo,
    'rental_guarantee',
    PdoRuleCatalog::legacyPortugueseTables(),
    PdoRuleCatalog::legacyPortugueseColumns()
);

$verdict = Spec::engine($catalogo, $registry)->validate(
    target: $contrato,
    scope: 'contrato_locacao',
    context: ['product_code' => 'PROD_A']
);
```

---

### 8. SQL Query Visitor & Multi-SGBD (WHERE Parametrizado)

Traduz diretamente árvores complexas de especificações de domínio para cláusulas `WHERE` parametrizadas, imunes a injeção SQL, com quoting automático de identificadores e funções nativas por SGBD:

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\SqlDialect;

// 1. Constrói a regra de domínio puro
$spec = Spec::property('ativo', Spec::equalTo(true))
    ->and(
        Spec::property('salario', Spec::greaterThan(5000))
            ->or(Spec::property('cidade', Spec::wildcard('São*')))
    );

// 2. Compila para PostgreSQL com mapeamento de colunas
$whereClause = Spec::toSql(
    specification: $spec,
    dialect: SqlDialect::POSTGRESQL,
    fieldMapper: [
        'ativo'   => 'st_ativo',
        'salario' => 'vl_salario',
        'cidade'  => 'ds_cidade'
    ]
);

echo $whereClause->getSql();
// '("st_ativo" = TRUE AND ("vl_salario" > :p1 OR "ds_cidade" LIKE :p2))'
// (booleanos são renderizados pelo dialeto; os demais valores vão por binding)

print_r($whereClause->getBindings());
// [':p1' => 5000, ':p2' => 'São%']

// 3. Suporte a 15 drivers em 10 dialetos SQL (SQL Server, Oracle, Firebird, MySQL, SQLite, Db2, Informix, DuckDB, etc.)
$whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
// '([ativo] = 1 AND ([salario] > :p1 OR [cidade] LIKE :p2))'

$whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
// '(`ativo` = 1 AND (`salario` > :p1 OR BINARY `cidade` LIKE :p2))'
```

---

### 9. TCriteria Builder (Adianti Framework Database Bridge - Módulo 13)

Traduza qualquer árvore de especificações de domínio puro para instâncias nativas de `Adianti\Database\TCriteria` e `TFilter`, preservando a precedência booleana por aninhamento e aplicando as **Leis de De Morgan** para negações:

```php
use Antevemus\ASpecification\Spec;

// 1. Especificação de domínio com conjunção, disjunção e negação
$spec = Spec::property('ativo', Spec::equalTo(true))
    ->and(
        Spec::property('salario', Spec::greaterThan(5000))
            ->or(Spec::property('cidade', Spec::wildcard('São*')))
    )
    ->and(Spec::property('status', Spec::not(Spec::equalTo('CANCELADO'))));

// 2. Compilação direta via Facade estática com mapeamento de colunas
$criteria = Spec::toCriteria(
    specification: $spec,
    fieldMap: [
        'ativo'   => 'st_ativo',
        'salario' => 'vl_salario',
        'cidade'  => 'ds_cidade',
        'status'  => 'tp_status'
    ],
    properties: [
        'order'     => 'vl_salario',
        'direction' => 'desc',
        'limit'     => 50,
        'offset'    => 0
    ]
);

echo $criteria->dump();
// '((st_ativo = TRUE AND (vl_salario > 5000 OR ds_cidade LIKE 'São%')) AND tp_status <> 'CANCELADO')'

// 3. Compilação fluente com paginação e ordenação encadeadas
$criteriaFluent = Spec::criteriaBuilder($spec)
    ->withFieldMapping(['salario' => 'vl_salario'])
    ->orderBy('vl_salario', 'desc')
    ->limit(20)
    ->offset(40)
    ->groupBy('departamento_id')
    ->toCriteria();

// 4. Invocação direta a partir de qualquer instância de ISpecification
$criteriaFromInstance = $spec->toCriteria();
```

---

### 10. ALinq Synergy & Coleções Fluentes LINQ (Módulo 14)

Interoperabilidade nativa de alto desempenho com a biblioteca **[`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection)**:

#### 10.1 Resolução Avançada de Propriedades com Dot Notation (`PropertyAccessor`)
Avalie propriedades em objetos com getters ou métodos booleanos, arrays associativos e caminhos aninhados profundos:

```php
use Antevemus\ASpecification\Spec;

// Regra navegando por objetos/arrays aninhados
$vipInSP = Spec::property('address.city', Spec::equalTo('São Paulo'))
    ->and(Spec::property('profile.score', Spec::greaterThan(90)));

$user = (object)[
    'address' => (object)['city' => 'São Paulo'],
    'profile' => ['score' => 95]
];

$result = $vipInSP->evaluate($user);
// $result->isSatisfied === true
```

Cada segmento é resolvido nesta ordem, vence o primeiro que casar: chave de array ou `ArrayAccess`; método público `getX()`, `x()`, `isX()`, `hasX()`; propriedade pública inicializada ou `__get` guardado por `__isset`; propriedade pública; e, por último, propriedade privada ou protegida **marcada com `#[Specifiable]`** pelo autor da classe (desde a v1.6.1). O nome da propriedade pode vir do request (um filtro ou uma ordenação montados pela tela), então o estado privado só é alcançado por nome quando o autor opta por isso. Propriedade não pública sem a marca se comporta como inexistente: a especificação de propriedade é erro de avaliação, `PropertyAccessor::hasProperty()` responde `false` e um delta (`updateWithDelta()`) que a nomeie lança `InvalidArgumentException`, a menos que a entidade tenha setter público para ela.

```php
use Antevemus\ASpecification\Attributes\Specifiable;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Spec;

final class Account
{
    #[Specifiable]
    private string $status = 'active';      // opt-in: legível por nome, gravável por delta
    private string $passwordHash = 'h4sh';  // sem a marca: invisível para um nome
}

$account = new Account();

Spec::property('status', Spec::equalTo('active'))->isSatisfiedBy($account);        // true
PropertyAccessor::hasProperty($account, 'passwordHash');                            // false
Spec::property('passwordHash', Spec::startsWith('h'))->evaluate($account)->isError; // true, como propriedade inexistente
```

`#[Specifiable]` na classe vale para todas as propriedades **declaradas naquela classe** (não para as da superclasse nem das subclasses). `#[Antevemus\ALinq\Attributes\Specifiable]` é aceito como a mesma marca, pelo nome.

#### 10.2 Compilador de AST para Predicados LINQ (`ALinqSpecificationVisitor`)
Converta árvores de especificações em um `Closure(mixed $candidate): bool` compilado com operadores de curto-circuito (`&&`, `||`, `!`), sem overhead:

```php
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;

// Compila a especificação em um predicado executável
$predicate = ALinqSpecificationVisitor::createPredicate($vipInSP);

// Diretamente utilizável em coleções ALinq ou array_filter nativo
$aprovados = $minhaColecaoAlinq->where($predicate);
```

#### 10.3 Repositórios em Memória Fluentes com `ALinqBridge`
Conecte repositórios em memória e iteráveis diretamente a pipelines LINQ (ordenação, paginação, agrupamento e agregações):

```php
use Antevemus\ASpecification\Linq\ALinqBridge;

// 1. Filtragem fluente a partir de qualquer iterável
$techItems = ALinqBridge::filter($produtos, $specTech)
    ->orderByDescending(fn($p) => $p->price)
    ->take(10)
    ->toArray();

// 2. Consulta tipada direta no InMemoryRepository retornando ALinqCollection
$topCustomers = $inMemoryRepo->findAsLinqCollection($specApproved)
    ->orderBy(fn($c) => $c->getPoints())
    ->take(5)
    ->toArray();

// 3. Conversão completa do repositório em memória para ALinqCollection
$mediaPontos = $inMemoryRepo->asLinqCollection()->average(fn($c) => $c->getPoints());
```

#### 10.4 Streaming Baseado em Generators & Avaliação Lazy (`ALinqLazyCollection`) com O(1) de RAM
Processe conjuntos massivos de dados, arquivos ou streams infinitas com consumo de memória estritamente constante \(O(1)\), combinando especificações e generators:

```php
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Spec;

// 1. Processamento sob demanda de grandes volumes com consumo O(1) de RAM
$streamLogsGrandes = static function(): \Generator {
    $handle = fopen('eventos_sistema.log', 'rb');
    while (($linha = fgets($handle)) !== false) {
        yield json_decode($linha, true);
    }
    fclose($handle);
};

// Elementos são lidos e testados sob demanda sem carregar o arquivo na memória
$eventosCriticos = Spec::filterLazy($streamLogsGrandes, $specAltaGravidade)
    ->take(100)
    ->toArray();

// 2. Stream preguiçoso diretamente a partir do InMemoryRepository com findAsLazyCollection()
$clientesAtivos = $inMemoryRepo->findAsLazyCollection($specAtivo)
    ->select(fn($c) => $c->getEmail())
    ->take(50)
    ->toArray();
```

---

### 11. Attributes Declarativos no PHP 8 (`#[AssertSpec]`, `#[ValidateRule]`) (Módulo 15)

No PHP 8.2+, anote Data Transfer Objects (DTOs), Form Requests, Value Objects e Entidades diretamente com especificações:

```php
use Antevemus\ASpecification\Attributes\AssertSpec;
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Spec;

#[AssertSpec(CustomerMustBeActiveSpec::class, message: 'Conta do cliente está suspensa', code: 'CUST_SUSPENDED')]
class RegisterCustomerDto
{
    #[ValidateRule('not_blank', message: 'Nome não pode ser vazio')]
    public string $name;

    #[ValidateRule('>=', value: 18, message: 'Cliente deve ter pelo menos 18 anos', code: 'UNDERAGE')]
    public int $age;

    #[ValidateRule('email', message: 'Formato de e-mail corporativo inválido')]
    public string $email;

    public function __construct(string $name, int $age, string $email)
    {
        $this->name = $name;
        $this->age = $age;
        $this->email = $email;
    }
}

$dto = new RegisterCustomerDto('Alice Smith', 16, 'alice@example.com');

// 1. Validação com Notification Pattern (sem exceções)
$result = Spec::validateAttributes($dto);
if (!$result->isSatisfied) {
    foreach ($result->failures as $failure) {
        echo "Violação [{$failure->code}]: {$failure->message} (em {$failure->ruleName})\n";
    }
}

// 2. Asserção estrita com exceção
try {
    Spec::assertAttributes($dto);
} catch (\Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException $e) {
    // Lançada automaticamente em caso de falha com detalhes completos
    $failures = $e->getResult()->failures;
}
```

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

### 13. Chamadas de Método Declarativas (`MethodParameterizedSpecification`)

`where()` lê uma propriedade; `whereMethod()` / `Spec::calling()` chamam um método público do candidato com argumentos declarados como dados e aplicam uma especificação ao resultado. Diferente de `must(closure)`, nome do método e argumentos são dados: a especificação tem `equals()` estrutural, nome de regra e pode ser descrita num catálogo. Método ausente é erro de avaliação, nunca um `false` silencioso.

```php
use Antevemus\ASpecification\Spec;

final class Contract
{
    public function __construct(private DateTimeImmutable $endsAt, private int $installments) {}
    public function isEligibleFor(DateTimeInterface $date): bool { return $date <= $this->endsAt; }
    public function installmentsLeft(int $paid): int { return $this->installments - $paid; }
}

$eligible = Spec::specify(Contract::class)
    ->whereMethod('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
    ->and(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0)));

$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2027-06-30'), 12)); // true
$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2026-06-30'), 12)); // false (not eligible)
$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2027-06-30'), 3));  // false (nothing left)

// Declarative: method name and arguments are data, so equality is structural
Spec::calling('installmentsLeft', [3], Spec::greaterThan(0))
    ->equals(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0)));      // true

// A missing method is an evaluation error, never a silent false
Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
    ->evaluate(new stdClass())->isError;                                          // true
```

Os visitors SQL e TCriteria recusam a chamada de método como folha não traduzível (como `must()`); o visitor ALinq a compila.

### 14. Máquina de Estados Dirigida por Especificações (Módulos 17 e 18)

`Antevemus\ASpecification\StateMachine` é uma máquina de estados em que **todo guard é uma `ISpecification`**: quando uma transição é negada, o resultado diz por quê, com o código, a mensagem, a propriedade e a severidade da especificação que barrou. A API é dirigida por evento (`send(evento)`, o alvo é consequência), a definição é dado (chave estável e versão inteira, montada por builder fluente ou carregada de JSON), `transition()` é puro (calcula o snapshot novo, as transições tomadas e os efeitos, e não executa nada) e o `MachineRunner` é a única porta de escrita (snapshot, histórico e outbox numa transação). Os trechos desta seção se apoiam uns nos outros.

#### 14.1 Máquina Mínima: Guards São Especificações

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Event;

// Guards nomeados: nome => fábrica(array $params): ISpecification, avaliada contra um TransitionCandidate
// (context = seu objeto de domínio ou array, event = tipo + payload + occurredAt, snapshot = estados atuais)
$guards = Spec::guardRegistry()->register('hasRole', fn(array $params) =>
    Spec::property('context.role', Spec::in(...$params['roles']))
        ->because('Only a manager can approve.')
        ->withCode('ROLE_NOT_ALLOWED'));

$order = Spec::machine('purchase_order', 1)
    ->region('lifecycle', 'draft')
        ->state('draft')
            ->on('SUBMIT', 'submitted')
        ->state('submitted')
            ->on('APPROVE', 'approved')->guard('hasRole', ['roles' => ['manager']])
            ->on('SEND_BACK', 'draft')->guard('payloadHas', ['field' => 'motive'])   // guard embutido
        ->finalState('approved')
    ->build();

Spec::validateMachine($order, $guards);              // [] (lança nos erros, devolve os avisos)

$machine  = Spec::stateMachine($guards);
$now      = new DateTimeImmutable('2026-10-10 09:00'); // a máquina nunca lê o relógio
$snapshot = $machine->initialSnapshot($order, null, $now)->getSnapshot();

$result = $machine->transition($order, $snapshot, Event::external('SUBMIT', $now));
$result->isAccepted();                               // true
$snapshot = $result->getSnapshot();
$snapshot->stateOf('lifecycle');                     // 'submitted'

$denied = $machine->transition($order, $snapshot, Event::external('APPROVE', $now), ['role' => 'clerk']);
$denied->isRejected();                               // true: negação é resultado, nunca exceção
$denied->getSnapshot() === $snapshot;                // true: nada mudou
$denied->getReason()->failures[0]->code;             // 'ROLE_NOT_ALLOWED'
$denied->getReason()->failures[0]->message;          // 'Only a manager can approve.'

$machine->transition($order, $snapshot, Event::external('APPROVE', $now), ['role' => 'manager'])
    ->getSnapshot()->isDone();                       // true: a região está num estado final
```

Evento que nenhuma transição do estado atual atende é `unhandled` (sem mudança e sem exceção). Quando várias transições atendem o mesmo evento, são testadas na ordem declarada e vence a primeira cujo guard passa; guard que lança vira falha `ERROR` marcada `evaluation_error`.

#### 14.2 Regiões Paralelas: Pendência Que Nunca Perde o Andamento

A máquina tem uma ou mais regiões ortogonais, cada uma com seu estado atual. A pendência modelada como região própria (`none ⇄ open`) pode ser registrada e resolvida em qualquer etapa sem um estado "pendente" no fluxo principal, então resolvê-la nunca precisa adivinhar onde o processo estava. `inState('região.estado')` lê outra região, e uma expressão de guard pode trazer a própria mensagem e o próprio código:

```php
use Antevemus\ASpecification\StateMachine\GuardExpression;

$quote = Spec::machine('quote', 1)
    ->region('progress', 'registering')
        ->state('registering')
            ->on('SEND_PROPOSAL', 'proposal_sent')
        ->state('proposal_sent')
            ->on('APPROVE', 'approved')
                ->guard(GuardExpression::not(GuardExpression::ref('inState', ['state' => 'pending.open']))
                    ->because('Resolve the open pending item first.')
                    ->withCode('QUOTE_PENDING_OPEN'))
            ->on('CANCEL', 'cancelled')
        ->finalState('approved')
        ->finalState('cancelled')
    ->region('pending', 'none')
        ->state('none')
            ->on('REGISTER_PENDING', 'open')->guard('payloadHas', ['field' => 'motive'])
        ->state('open')
            ->on('RESOLVE_PENDING', 'none')
    ->build();

$machine = Spec::stateMachine();
$state = $machine->initialSnapshot($quote, null, $now)->getSnapshot();
$state = $machine->transition($quote, $state, Event::external('SEND_PROPOSAL', $now))->getSnapshot();
$state = $machine->transition($quote, $state, Event::external('REGISTER_PENDING', $now, ['motive' => 'Missing ID']))->getSnapshot();

$state->stateOf('progress');                         // 'proposal_sent': registrar a pendência só moveu a região dela
$state->isIn('pending.open');                        // true
$machine->transition($quote, $state, Event::external('APPROVE', $now))
    ->getReason()->failures[0]->code;                // 'QUOTE_PENDING_OPEN'
$machine->transition($quote, $state, Event::external('CANCEL', $now))->isAccepted(); // true: CANCEL não é barrado

$state = $machine->transition($quote, $state, Event::external('RESOLVE_PENDING', $now))->getSnapshot();
$state->stateOf('progress');                         // 'proposal_sent': de volta onde estava, nada a lembrar
$machine->transition($quote, $state, Event::external('APPROVE', $now))->isAccepted(); // true
```

Os guards leem o snapshot **anterior** ao evento (a ordem das regiões nunca muda o resultado), e o evento é tudo ou nada: se alguma região o rejeita, nenhuma se move. A máquina fica `done` quando toda região que declara estado final está num deles; região sem estado final (a pendência) não segura a máquina aberta, e máquina `done` responde `unhandled` a todo evento.

#### 14.3 `can()` e `nextEvents()`: Botão Desabilitado Com o Motivo

`can()` é o próprio `transition()`, sem efeito colateral; `nextEvents()` testa cada evento que os estados atuais atendem (como evento externo com payload vazio), para o painel de ações mostrar o botão desabilitado junto com o motivo. Continuando com a pendência ainda aberta:

```php
foreach ($machine->nextEvents($quote, $state, null, $now) as $type => $answer) {
    echo $type, ': ', $answer->isAccepted() ? 'enabled' : 'disabled (' . $answer->getReason()->failures[0]->message . ')', PHP_EOL;
}
// APPROVE: disabled (Resolve the open pending item first.)
// CANCEL: enabled
// RESOLVE_PENDING: enabled

$machine->can($quote, $state, Event::external('APPROVE', $now))
    ->getRejections()['progress']->failures[0]->code; // 'QUOTE_PENDING_OPEN' (o motivo por região que rejeitou)
```

#### 14.4 A Definição É Dado: JSON e o Validador

Guards e ações são referenciados por nome e parâmetros, nunca por closures, então a definição faz ida e volta por array e JSON sem perda e tem fingerprint determinístico (SHA-256 da forma canônica) para detectar definição alterada sem troca de versão. O validador roda na carga: recusa a definição (uma exceção com a lista de todos os erros) por região sem estado inicial, alvo ou caminho de `inState` inexistente, guard sem fábrica (ou ação sem handler, quando os nomes de ação conhecidos são informados), estado final com transição de saída; avisa, sem bloquear, sobre estados inalcançáveis, estados não finais sem saída, guards sobrepostos ou encobertos e guards que sempre passam. O requisito documental abaixo (pendente → entregue → validado, ou vencido após 30 dias) é o `RequirementState` que o changelog da 1.0.0 anunciou, agora expresso como máquina:

```php
use Antevemus\ASpecification\StateMachine\MachineDefinition;

$requirement = MachineDefinition::fromJson(<<<'JSON'
{
  "key": "document_requirement",
  "version": 1,
  "regions": [{
    "name": "lifecycle",
    "initial": "pending",
    "states": [
      {"name": "pending", "transitions": [
        {"event": "DELIVER", "target": "delivered", "guard": {"guard": "payloadHas", "params": {"field": "file"}}}
      ]},
      {"name": "delivered", "deadline": {"after": "P30D", "event": "EXPIRE"}, "transitions": [
        {"event": "VALIDATE", "target": "validated"},
        {"event": "EXPIRE", "target": "expired"}
      ]},
      {"name": "on_hold"},
      {"name": "validated", "final": true},
      {"name": "expired", "final": true}
    ]
  }]
}
JSON);

MachineDefinition::fromArray($requirement->toArray()) == $requirement;   // true: ida e volta sem perda
$requirement->fingerprint();                         // 64 caracteres hexadecimais, estável entre execuções e ordem de chaves
$requirement->getTransition('lifecycle.pending.DELIVER') !== null;       // true: ids gerados como região.estado.EVENTO

foreach (Spec::validateMachine($requirement) as $warning) {
    echo $warning->kind, ': ', $warning->state, PHP_EOL;
}
// unreachable_state: on_hold
// dead_end_state: on_hold
```

#### 14.5 O Runner: Uma Transação, Idempotência, Outbox e Prazos Persistidos

`MachineRunner::send()` carrega a instância e a versão da definição em que ela nasceu, confere a chave de idempotência, calcula a transição e grava o snapshot novo (compare-and-set pela versão: mudança concorrente lança `ConcurrencyConflictException` e nunca é repetida em silêncio), uma linha de histórico por região movida e os efeitos na outbox, em **uma** transação. Os efeitos (as ações nomeadas: `exit` → transição → `entry`) são entregues **depois** do commit por um `EffectDispatcher`, cada um com sua chave de idempotência, na ordem de gravação por instância; handler que falha nunca desfaz a transição. Prazo é persistido (`due_at`), nunca timer em memória: um cron pede os vencidos e os entrega pelo mesmo runner, e entrega atrasada ou repetida é inofensiva.

```php
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

$document = Spec::machine('document', 1)
    ->region('lifecycle', 'pending')
        ->state('pending')
            ->on('DELIVER', 'delivered')->guard('payloadHas', ['field' => 'file'])->action('notify_reviewer')
        ->state('delivered')->deadline('P3D', 'EXPIRE')
            ->on('VALIDATE', 'validated')
            ->on('EXPIRE', 'expired')->action('notify_owner')
        ->finalState('validated')
        ->finalState('expired')
    ->build();

$sent = [];
$handlers = (new EffectHandlerRegistry())
    ->register('notify_reviewer', function (Effect $effect, string $idempotencyKey) use (&$sent): void { $sent[] = $effect->action; })
    ->register('notify_owner', function (Effect $effect, string $idempotencyKey) use (&$sent): void { $sent[] = $effect->action; });

$persistence = MachinePersistence::inMemory();
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$document]), $persistence, $handlers);
$runner->start('doc-42', 'document', 1, null, new DateTimeImmutable('2026-10-10 09:00'));

$deliver = Event::external('DELIVER', new DateTimeImmutable('2026-10-10 10:00'), ['file' => 'id.pdf'], 'upload-7f3a');
$runner->send('doc-42', $deliver, actor: 'alice')->isAccepted();  // true
$runner->send('doc-42', $deliver, actor: 'alice')->isAccepted();  // true: mesma chave, o resultado original, nada gravado duas vezes
$runner->load('doc-42')->version;                                 // 2 (start + um evento aceito)
count($persistence->history->findByInstance('doc-42'));           // 2 (append-only: start + DELIVER)

// Depois do commit (um worker ou um cron): entrega os efeitos
(new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending()->deliveredCount(); // 1
$sent;                                                            // ['notify_reviewer']

// Prazos: acha e entrega os vencidos (o evento EXPIRE é interno: não pode ser enviado de fora)
$runner->findDue(new DateTimeImmutable('2026-10-12 10:00'));       // [] (vence em 2026-10-13 10:00)
$deliveries = $runner->deliverDue(new DateTimeImmutable('2026-10-13 10:00'), actor: 'scheduler');
$deliveries[0]->isDelivered();                                    // true
$runner->load('doc-42')->snapshot->stateOf('lifecycle');          // 'expired'
```

`EffectHandlerRegistry::registerSync()` declara o efeito raro que precisa poder vetar: roda dentro da transação, antes do commit, e uma exceção desfaz o passo inteiro. Cada instância fica presa à versão da definição em que nasceu; levá-la a uma versão nova é explícito (`registerMigration()` + `migrate()`).

#### 14.6 Persistência em PDO e o DDL de Referência

`MachinePersistence::pdo()` dá as mesmas portas sobre as tabelas de [`resources/sql/state_machine.ansi.sql`](resources/sql/state_machine.ansi.sql): SQL portável, `CREATE TABLE IF NOT EXISTS` (aceito por PostgreSQL, MySQL/MariaDB e SQLite), chave primária em toda tabela, `UNIQUE (instance_id, idempotency_key)`, instantes como texto UTC de largura fixa. Os prazos vencidos são buscados por uma especificação compilada para SQL pelo SQL visitor. Os stores InMemory e PDO rodam a mesma bateria de testes (SQLite em memória na suíte).

```php
// Uma vez, com sua ferramenta de migração: resources/sql/state_machine.ansi.sql
$runner = new MachineRunner(
    Spec::stateMachine(),
    new InMemoryMachineDefinitionRepository([$document]),  // as definições vão junto com a aplicação
    MachinePersistence::pdo($pdo),                         // mesmo contrato do inMemory()
    $handlers
);

// Tabelas com outros nomes: nome por papel (instance, region, history, outbox, idempotency,
// rejection), um schema opcional e, quando as colunas diferem, coluna canônica => coluna real
$persistence = MachinePersistence::pdo($pdo, tables: ['instance' => 'quote_state', 'history' => 'quote_state_history']);
```

> **Colunas JSON.** `snapshot`, `payload`, `params`, `reason` e `transition_result` são JSON guardado em `VARCHAR(4000)`, o tamanho portável que cabe também no Oracle. Payload de evento maior exige alargar a coluna para `TEXT`/`JSONB`/`CLOB` na sua cópia do DDL. Se a conexão PDO já estiver numa transação, o runner entra nela e quem a abriu faz o commit ou o rollback.

> **Atualizando da 1.8.** Desde a 1.9.0 a `state_machine_region` tem a coluna anulável `paused_remaining` (o saldo de um prazo pausado, exemplo 14.7). `CREATE TABLE IF NOT EXISTS` não acrescenta coluna a uma tabela que já existe, então um banco criado com o script da 1.8 precisa, uma vez, antes da primeira pausa de prazo: `ALTER TABLE state_machine_region ADD COLUMN paused_remaining INTEGER;`. Até lá, toda definição que nunca pausa um prazo continua funcionando na tabela antiga; a primeira pausa falha e o passo dela é desfeito.

#### 14.7 Prazos Vindos do Contexto: um SLA Que Pausa Enquanto Há Pendência Aberta

Um prazo tem exatamente uma fonte: uma duração fixa (`deadline('P3D', 'EXPIRE')`, como em 14.5), uma duração lida do contexto (`afterFrom: 'context.caminho'`, texto ISO-8601 ou `DateInterval`) ou o próprio instante de vencimento lido do contexto (`atFrom: 'context.caminho'`, `DateTimeInterface` ou texto ISO-8601 com data, hora e offset). O caminho é lido com a notação de ponto do `PropertyAccessor` na entrada do estado, a partir do contexto passado a essa chamada, então o cálculo continua puro. Fonte ausente ou inválida nunca vira prazo silenciosamente vazio: o evento que entra no estado é rejeitado com `SM_DEADLINE_SOURCE_INVALID` (um `ERROR` que cita o caminho, nunca o valor), e o início também, que então não grava nada. `pausedWhile` recebe uma expressão de guard (um texto `'região.estado'` é atalho de `inState`), avaliada depois de cada passo aceito: quando passa a valer, o prazo pausa (sem instante de vencimento, então nenhum cron o encontra, e os segundos inteiros restantes ficam guardados); quando deixa de valer, o prazo retoma a partir desse saldo. Reentrar no estado reinicia o prazo e descarta o saldo. Abaixo, o SLA é uma região própria (um instante absoluto do contrato), pausado enquanto uma pendência (a região paralela do exemplo 14.2) está aberta, e cada etapa dura o que a tabela do cliente diz:

```php
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;

$claim = Spec::machine('claim', 1)
    ->region('progress', 'triage')
        ->state('triage')->deadline(event: 'TRIAGE_CLOSED', afterFrom: 'context.deadlines.triage')
            ->on('START_ANALYSIS', 'analysis')
            ->on('TRIAGE_CLOSED', 'analysis')
        ->state('analysis')->deadline(event: 'ANALYSIS_OVERDUE', afterFrom: 'context.deadlines.analysis')
            ->on('APPROVE', 'approved')
            ->on('ANALYSIS_OVERDUE', 'late')
        ->state('late')
            ->on('APPROVE', 'approved')
        ->finalState('approved')
    ->region('pending', 'none')
        ->state('none')->on('REGISTER_PENDING', 'open')
        ->state('open')->on('RESOLVE_PENDING', 'none')
    ->region('sla', 'running')
        ->state('running')->deadline(event: 'SLA_BREACHED', atFrom: 'context.sla_due_at', pausedWhile: 'pending.open')
            ->on('SLA_BREACHED', 'breached')
        ->finalState('breached')
    ->build();

// O contexto de cada sinistro: o instante do SLA do contrato e as durações de etapa do cliente
$contexts = ['claim-7' => ['sla_due_at' => '2026-11-09T18:00:00-03:00', 'deadlines' => ['triage' => 'P2D', 'analysis' => 'P5D']]];

$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), MachinePersistence::inMemory());
$runner->start('claim-7', 'claim', 1, $contexts['claim-7'], new DateTimeImmutable('2026-10-10T09:00:00-03:00'))->isAccepted(); // true
$runner->load('claim-7')->snapshot->dueAt('sla')->format(DATE_ATOM);      // '2026-11-09T18:00:00-03:00' (atFrom)
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-10-12T09:00:00-03:00' (início + P2D, afterFrom)

// Fonte ausente é rejeição, nunca SLA silenciosamente vazio: o início não grava nada
$missing = $runner->start('claim-8', 'claim', 1, ['deadlines' => ['triage' => 'P2D']], new DateTimeImmutable('2026-10-10T09:00:00-03:00'));
$missing->isRejected();                                                   // true
$missing->getReason()->failures[0]->code;                                 // 'SM_DEADLINE_SOURCE_INVALID'
$missing->getReason()->failures[0]->property;                             // 'context.sla_due_at'

// O cron: TRIAGE_CLOSED entra em 'analysis', cujo prazo lê o contexto, então o cron o fornece
$deliveries = $runner->deliverDue(
    new DateTimeImmutable('2026-10-12T09:00:00-03:00'),
    contextProvider: fn(DueDeadline $due, MachineInstance $instance) => $contexts[$instance->instanceId]
);
$deliveries[0]->isDelivered();                                            // true (sem o provedor: rejeitado, tentado de novo na próxima rodada)
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-10-17T09:00:00-03:00'

// Uma pendência aberta 20 dias antes do SLA: o SLA pausa e guarda o saldo
$runner->send('claim-7', Event::external('REGISTER_PENDING', new DateTimeImmutable('2026-10-20T18:00:00-03:00')), $contexts['claim-7']);
$snapshot = $runner->load('claim-7')->snapshot;
$snapshot->isPaused('sla');                                               // true
$snapshot->dueAt('sla');                                                  // null: prazo pausado nunca vence
$snapshot->pausedRemaining('sla');                                        // 1728000 (20 dias, em segundos inteiros)
array_map(fn(DueDeadline $due) => $due->region, $runner->findDue(new DateTimeImmutable('2027-01-01T00:00:00-03:00'))); // ['progress']

// Resolvida 5 dias depois: o SLA retoma com o saldo, 5 dias depois do instante do contrato
$runner->send('claim-7', Event::external('RESOLVE_PENDING', new DateTimeImmutable('2026-10-25T18:00:00-03:00')), $contexts['claim-7']);
$runner->load('claim-7')->snapshot->dueAt('sla')->format(DATE_ATOM);      // '2026-11-14T18:00:00-03:00'
```

O `deliverDue()` envia contexto nulo, a menos que receba um `contextProvider` (que recebe o prazo vencido e a instância carregada): uma transição de prazo que entra num estado cujo prazo lê o contexto precisa do contexto da instância, como aqui. A condição de pausa é qualquer expressão de guard; uma que lança exceção rejeita o passo. O validador recusa `pausedWhile` com guard desconhecido ou caminho de `inState` inexistente, e avisa quando ele referencia a própria região do prazo (a pausa nunca terminaria). O snapshot guarda o saldo (`pausedRemaining()`; `paused_remaining` na forma de array só enquanto alguma região está pausada, então um snapshot da 1.8 carrega sem mudança), e o `migrate()` recusa uma migração que deixaria saldo pausado num estado cujo prazo não declara `pausedWhile`, porque ele nunca retomaria.

Fora do escopo de propósito (cada um pode ser modelado sem ele): atores, `invoke`/`spawn` (trabalho assíncrono é job externo que devolve um evento), transições sem evento (`always`) e `raise` (quem chama envia o próximo evento), estados aninhados dentro de região e history state (uma região paralela, como a pendência acima, cobre os casos reais) e contexto da máquina (`assign`): o contexto é o objeto de domínio de quem chama. O histórico é trilha; o snapshot é a verdade (sem event sourcing).

### 15. Casos de Uso Com Resultado Comum à Máquina (Módulo 19)

Um caso de uso é uma classe com um método, `execute(object $request): UseCaseResult`. O `UseCaseRunner` o executa pelos middlewares, valida antes o request (atributos `#[ValidateRule]`/`#[AssertSpec]` mais as especificações registradas para a classe dele) e só falha `ERROR` bloqueia: `WARNING` e `INFO` voltam junto do sucesso. O status é um enum de domínio fechado (`SUCCESS`, `CREATED`, `ACCEPTED`, `NO_CHANGE`, `INVALID`, `REJECTED`, `NOT_FOUND`, `CONFLICT`, `FORBIDDEN`, `FAILED`); o sucesso deriva só dele, e HTTP existe só num método de borda, `toHttpStatus()`. Exceção não tratada vira `FAILED` com mensagem genérica e vai para um `IUseCaseErrorReporter` opcional: nenhuma mensagem, classe, arquivo, linha ou stack trace chega ao resultado. Nenhum mediator, contêiner ou descoberta de handlers vem embutido.

#### 15.1 `IUseCase`, `UseCaseRunner` e `TransactionMiddleware`

```php
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\UseCase\IUseCase;
use Antevemus\ASpecification\UseCase\TransactionMiddleware;
use Antevemus\ASpecification\UseCase\UseCaseResult;
use Antevemus\ASpecification\UseCase\UseCaseStatus;

final class RegisterTenantRequest
{
    public function __construct(
        #[ValidateRule('not_blank', code: 'NAME_REQUIRED', message: 'The tenant name is required.')]
        public readonly string $name,
        #[ValidateRule('<=', value: 5000, code: 'RENT_REVIEW', message: 'Rent above 5 000 goes to manual review.', severity: 'WARNING')]
        public readonly int $rent,
    ) {}
}

/** @implements IUseCase<RegisterTenantRequest, int> */
final class RegisterTenant implements IUseCase
{
    public function __construct(private PDO $pdo) {}

    public function execute(object $request): UseCaseResult
    {
        $this->pdo->prepare('INSERT INTO tenant (name, rent) VALUES (?, ?)')->execute([$request->name, $request->rent]);

        return UseCaseResult::created((int) $this->pdo->lastInsertId());
    }
}

$useCases = Spec::useCaseRunner(middlewares: [
    new TransactionMiddleware($pdo->beginTransaction(...), $pdo->commit(...), $pdo->rollBack(...)),
]);

$created = $useCases->run(new RegisterTenant($pdo), new RegisterTenantRequest('Alice', 6200));
$created->isSuccess();                       // true: WARNING nunca bloqueia (commit feito)
$created->getStatus();                       // UseCaseStatus::CREATED
$created->getWarnings()[0]->code;            // 'RENT_REVIEW'

$invalid = $useCases->run(new RegisterTenant($pdo), new RegisterTenantRequest('', 1200));
$invalid->getStatus();                       // UseCaseStatus::INVALID: execute() não foi chamado (rollback)
$invalid->getCodes();                        // ['NAME_REQUIRED']

// HTTP só na borda da aplicação
$invalid->getStatus()->toHttpStatus();       // 400 (CREATED → 201, REJECTED → 422, CONFLICT → 409 ...)
```

O `TransactionMiddleware` só faz commit de sucesso e faz rollback de status de falha ou de exceção (que ele relança para o runner transformar em `FAILED`). `UseCaseResult` e o `TransitionResult` da máquina implementam o mesmo `Results\IOutcome` (`isSuccess()`, `getReason()`), e `UseCaseResult::fromTransition()` converte um no outro.

#### 15.2 Evento da Máquina Como Caso de Uso

`StateMachineEventUseCase` (ou `Spec::machineEventUseCase()`) expõe um evento da máquina como caso de uso: o request é um `MachineEventRequest` (id da instância, `occurredAt`, payload, chave de idempotência, ator e versão esperada opcionais) e o resultado é o do runner convertido: aceito → `SUCCESS` (payload = o snapshot novo), rejeitado → `REJECTED` com os códigos dos guards, unhandled → `CONFLICT`, versão velha → `CONFLICT` com `SM_CONCURRENCY_CONFLICT`, instância inexistente → `NOT_FOUND`. Com a máquina da cotação do 14.2:

```php
use Antevemus\ASpecification\UseCase\MachineEventRequest;

$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$quote]), MachinePersistence::inMemory());
$runner->start('quote-7', 'quote', 1, null, $now);

$useCases = Spec::useCaseRunner();
$send = fn(string $event, array $payload = []): UseCaseResult => $useCases->run(
    Spec::machineEventUseCase($runner, $event),
    new MachineEventRequest('quote-7', $now, $payload, actor: 'alice')
);

$send('SEND_PROPOSAL')->getStatus();                          // UseCaseStatus::SUCCESS
$send('REGISTER_PENDING', ['motive' => 'Missing ID'])->isSuccess(); // true
$send('APPROVE')->getCodes();                                 // ['QUOTE_PENDING_OPEN'] (status REJECTED, HTTP 422)
$send('SEND_PROPOSAL')->getStatus();                          // UseCaseStatus::CONFLICT: o evento não cabe no estado atual
$send('RESOLVE_PENDING')->getPayload()->stateOf('progress');  // 'proposal_sent'

$useCases->run(Spec::machineEventUseCase($runner, 'CANCEL'), new MachineEventRequest('quote-99', $now))
    ->getStatus();                                            // UseCaseStatus::NOT_FOUND
```

---

## 🏗️ Estrutura de Diretórios

```
src/
├── Attributes/                # Engine de Attributes Declarativos PHP 8 (Módulo 15)
│   ├── AssertSpec.php        # Attribute de referência a Specifications
│   ├── ValidateRule.php      # Attribute de validação inline rápida
│   ├── AttributeValidator.php # Avaliador de reflexão de alta performance
│   ├── Specifiable.php       # Opt-in: propriedade não pública alcançável por nome (v1.6.1)
│   └── Exceptions/           # AttributeValidationException
├── Contracts/                 # Interfaces formais segregadas (ISP)
│   ├── Entities/             # IEntity, ITransientEntity
│   ├── Factory/              # ITypeSpecificationFactory, IComparison..., ILogical...
│   ├── Repositories/         # IRepository, IPartitionRepository, IPersistent...
│   ├── Concurrent/           # ISynchronizer
│   ├── Helpers/              # IStopWatch, IInstrumentationUtils
│   ├── Engine/               # IRuleDefinition, IDocumentRuleDefinition, IRuleCatalog...
│   └── Sql/                  # ISqlDialect, IFieldMapper, ISqlWhereClause
├── Entities/                  # Classes base abstratas de entidades, UUIDs e geradores de ID aleatórios
├── Specifications/            # Implementações concretas de regras
│   ├── SpecificationSimplifier.php # simplify(): árvore equivalente e menor (v1.9.0)
│   ├── Comparison/           # Equal, GreaterThan, LessThan, RelationalOperator
│   ├── Logical/              # AlwaysTrue, AlwaysFalse, JointDenial (NOR), DefaultValue
│   ├── String/               # Regex, Wildcard, DateString, EnumName
│   ├── Collection/           # CollectionSpecification, AllEntities, Unique
│   └── Reflection/           # MethodParameterizedSpecification (chamada de método declarativa; acesso a campo é where())
├── Repositories/              # Repositórios concretos e particionamento
│   ├── File/                 # SingleFileRepository, FilePerEntityRepository
│   └── Serialization/        # JsonEntitySerializer, PhpNativeEntitySerializer
├── Concurrent/                # SemaphoreSynchronizer, FileLockSynchronizer
├── Engine/                    # DynamicSpecificationEngine, RuleEngineVerdict, RuleCompilationWarning, PdoRuleCatalog, Builders
│   ├── Exceptions/           # RuleEngineException, MissingRuleHandlerException
│   ├── RuleAction.php        # Enum: BLOCK, WARN, LOG
│   └── DocumentRequirementMode.php # Enum: ALL, ANY, ONE_OF_SET
├── Sql/                       # SQL Query Visitor & Multi-SGBD Engine (Módulo 12)
│   ├── Dialects/             # Abstract, Ansi, PostgreSql, MySql, SqlServer, Oracle, Firebird, Sqlite
│   ├── Exceptions/           # SqlVisitorException, NonTranslatableSpecificationException...
│   ├── SqlDialect.php        # Enum com todos os drivers: sqlsrv, mssql, oracle, oci, mysql, etc.
│   ├── SqlQueryVisitor.php   # Visitor GoF que compila a AST em SQL parametrizado
│   ├── SqlWhereClause.php    # Cláusula WHERE segura com bindings
│   └── FieldMapper.php       # Mapeador de propriedades para colunas físicas
├── Criteria/                  # TCriteria Builder & Adianti Database Bridge (Módulo 13)
│   ├── Exceptions/           # CriteriaBuilderException, NonTranslatableCriteriaException
│   ├── CriteriaSpecificationVisitor.php # Visitor GoF que compila a AST para TCriteria/TFilter
│   └── TCriteriaBuilder.php  # Builder fluente com paginação, ordenação e mapeamento de campos
├── Linq/                      # ALinq Synergy & Coleções Fluentes (Módulo 14)
│   ├── ALinqBridge.php       # Ponte fluente entre repositórios e coleções ALinq
│   └── ALinqSpecificationVisitor.php # Compilador de AST para predicados funcionais LINQ
├── Factory/                   # SpecificationFactory unificada (~140 métodos)
│   └── Traits/               # 8 Traits modulares (Type, Comparison, Logical, Special, String, Date, Collection, Wrapper)
├── Results/                   # SpecificationResult, SpecificationFailure, FailureSeverity, IOutcome
├── StateMachine/              # Máquina de estados dirigida por especificações (Módulos 17 e 18): builder, definição, validador, núcleo puro
│   ├── Persistence/          # Portas, MachinePersistence, stores InMemory/ e Pdo/ (resources/sql/state_machine.ansi.sql)
│   └── Runner/               # MachineRunner, EffectDispatcher, EffectHandlerRegistry, migrações
├── UseCase/                   # IUseCase, UseCaseRunner, UseCaseResult, UseCaseStatus, middlewares (Módulo 19)
├── Helpers/                   # PropertyAccessor (dot notation), StopWatch, InstrumentationUtils
├── DSL/                       # Funções globais de apoio para syntax fluente
└── Spec.php                   # Facade principal da biblioteca
```

---

## 🧪 Qualidade de Código & Testes

A biblioteca possui ampla cobertura de testes unitários e de integração, garantindo zero regressões:

```bash
php tests/run_all.php
```

Relatório abaixo como o runner imprime; o tempo depende da máquina:

```text
====================================================================
 ANTEVEMUS ASPECIFICATION - MASTER TEST RUNNER & REGRESSION WATCH
====================================================================

• [SUITE] Módulo 1: Especificações e Álgebra Booleana... ✅ PASS (284 asserções)
• [SUITE] Módulo 2: Entidades e Identificadores... ✅ PASS (59 asserções)
• [SUITE] Módulo 3: Repositórios em Memória e Base... ✅ PASS (90 asserções)
• [SUITE] Módulo 4: Arquitetura de Particionamento DAG... ✅ PASS (94 asserções)
• [SUITE] Módulo 5: Persistência em Arquivo e Decorator Híbrido... ✅ PASS (115 asserções)
• [SUITE] Módulo 6: Utilitários de Concorrência e RW-Lock... ✅ PASS (97 asserções)
• [SUITE] Módulo 7: Predicados, Fábricas, Helpers e Visitor... ✅ PASS (61 asserções)
• [SUITE] Módulo 8: Notification Pattern & SpecificationResult... ✅ PASS (143 asserções)
• [SUITE] Módulo 9: Facade Spec, Chaining Fluente & DSL... ✅ PASS (204 asserções)
• [SUITE] Módulo 10: Paridade Java, Telemetria & Remainder... ✅ PASS (65 asserções)
• [SUITE] Módulo 11: Dynamic Rule Engine & Requisitos Documentais... ✅ PASS (409 asserções)
• [SUITE] Módulo 12: SQL Query Visitor & Multi-SGBD Dialects... ✅ PASS (459 asserções)
• [SUITE] Módulo 13: TCriteria Builder & Adianti Database Bridge... ✅ PASS (186 asserções)
• [SUITE] Módulo 14: ALinq Synergy & Coleções Fluentes LINQ... ✅ PASS (263 asserções)
• [SUITE] Módulo 15: Attributes Declarativos PHP 8.4 (#[AssertSpec])... ✅ PASS (221 asserções)
• [SUITE] Módulo 16: Catálogo Relacional de Regras (PdoRuleCatalog)... ✅ PASS (550 asserções)
• [SUITE] Módulo 17: Máquina de Estados Dirigida por Especificações... ✅ PASS (618 asserções)
• [SUITE] Módulo 18: Runner e Persistência da Máquina de Estados... ✅ PASS (700 asserções)
• [SUITE] Módulo 19: Caso de Uso e Resultado Comum (UseCaseResult)... ✅ PASS (408 asserções)
• [SUITE] Módulo 20: Simplificação de Especificações (simplify)... ✅ PASS (116 asserções)

====================================================================
 RESULTADO FINAL: 20/20 SUÍTES APROVADAS (100% PASS)
 TOTAL DE ASSERÇÕES: 5142 | TEMPO: ~4s | REGRESSÕES: 0
====================================================================
```

- **Suíte de paridade Domian:** `tests/Parity/` transcreve os testes do Domian (Java) para a álgebra de especificações, o DAG de partições, os synchronizers, entidades e utilitários (219 testes PHPUnit, 2.106 asserções; `vendor/bin/phpunit` roda 241 testes e 7.275 asserções no total). A auditoria por trás dela está em `docs/PARIDADE-DOMIAN-2026-10-09.md`.
- **Mapeamento de APIs Públicas:** 1.200+ métodos documentados via PHPDoc corporativo padronizado.
- **Rastreabilidade Java (Domian):** paridade conceitual e arquitetural com o framework original (API pública e nomes de classe seguem o Domian; ver a tabela de procedência em [NOTICE.md](NOTICE.md)).
- **Decomposição Modular com Traits:** `SpecificationFactory` modularizada em 8 Traits especializados por domínio de regras.
- **ALinq Synergy & Coleções Fluentes:** Sinergia nativa com `Antevemus.AlinqCollection`, com compilador `ALinqSpecificationVisitor`, resolução flexível de propriedades `PropertyAccessor` (dot notation) e integração em `InMemoryRepository`.
- **Multi-SGBD SQL Translator:** 15 drivers (10 dialetos SQL) homologados com quoting de identificadores e prepared statements.
- **Adianti Database Bridge:** Conversão completa para `TCriteria`/`TFilter` com De Morgan e precedência.

---

## 🗺️ Roadmap & Próximos Passos

O **Antevemus ASpecification** continua em evolução contínua com marcos de curto, médio e longo prazo. Para a lista completa e detalhada de iniciativas, consulte o documento oficial:

👉 **[Consulte o ROADMAP.md completo](ROADMAP.md)**

Principais destaques:
1. **Internacionalização das DocBlocks em PHP (EN)** (entregue na v1.1.0)
2. **PHP 8.4 Attributes Declarativos** (`#[AssertSpec]`, `#[ValidateRule]`) (entregue na v1.1.0)
3. **Pipeline de Streaming Lazy com ALinq e O(1) de RAM** (entregue na v1.1.0)
4. **Endurecimento pós-revisão: estado de erro, tipagem estrita, vínculo de regra e matriz documental** (entregue na v1.2.0)
5. **Promessas do README I: todo exemplo documentado roda** (entregue na v1.3.0)
5a. **Paridade Domian: álgebra de subsunção, DAG de partições, repositórios sincronizados, entidades e utilitários** (entregue na v1.4.4)
6. **Promessas do README II: IPC entre processos, Unicode, fontes lazy, catálogo relacional e `IN`** (entregue na v1.5.0)
7. **Backlog anunciado e não entregue I: identidades ULID / UUID v7 e severidade da falha** (entregue na v1.5.0)
8. **Aposentadoria do serializer PHP nativo, etapa 1: depreciação** (entregue na v1.5.0)
9. **`MethodParameterizedSpecification`: chamadas de método declarativas** (entregue na v1.6.0)
10. **Detecção de tautologia e contradição** (entregue na v1.6.0)
11. **Backlog anunciado e não entregue II: TTL, índice do DAG de partições e três dialetos SQL** (entregue na v1.6.0)
11a. **Máquina de estados dirigida por especificações** (o `RequirementState` reservado, agora expressável como máquina) (entregue na v1.8.0)
11b. **Casos de uso com resultado comum à máquina de estados** (entregue na v1.8.0)
12. **`simplify()` de especificações** (entregue na v1.9.0)
13. **Aposentadoria do serializer PHP nativo, etapa 2: remoção e envelope só JSON** (v2.0.0)
14. **Accessor de propriedades compartilhado com o ALinq** (v2.0.0)
15. **Resolução Concorrente de Dados para Especificações Dependentes de I/O** (v2.0.0)
16. **Cache Distribuído de Especificações** (PSR-6 / PSR-16 / Redis)
17. **Compiladores AST para GraphQL & OpenAPI 3.1**
18. **Doctrine ORM & Laravel Eloquent Query Visitors**
19. **Disparo de Eventos de Domínio por Especificação**
20. **Sintetizador de Especificações Assistido por IA**
21. **Repositório sobre PDO** (o `HibernateRepository` do Domian)

---

## ⚖️ Atribuição, Licença Upstream & Agradecimentos

O **Antevemus ASpecification** expressa seu mais profundo respeito e agradecimento aos autores originais que estabeleceram os fundamentos teóricos e práticos deste padrão:

- **Eirik Torske** (Administrador do Projeto & Desenvolvedor) e **Bjørn Nordlund** (Contribuidor), criadores do framework **[Domian (Java)](https://domian.sourceforge.net/)**, cujo trabalho pioneiro em álgebra booleana, operações de teoria dos conjuntos de Venn (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`) e arquitetura de repositórios particionados serviu como base arquitetural inspiradora para este projeto.
- **Eric Evans** e **Martin Fowler**, pela autoria do seminal paper *[Specifications (2002)](http://www.martinfowler.com/apsupp/spec.pdf)* e pelas obras fundamentais sobre Domain-Driven Design (DDD).

### Conformidade com a Licença Apache 2.0
O framework original **Domian** é distribuído sob os termos da **[Apache License, Version 2.0](http://www.apache.org/licenses/LICENSE-2.0)** (Copyright 2006-2010 the original author or authors, como consta nas fontes do Domian; desenvolvedores Eirik Torske e Bjørn Nordlund). Em plena conformidade com a Seção 4 da referida licença:
- As atribuições de autoria e direitos autorais do projeto original são integralmente preservadas.
- O arquivo [NOTICE.md](NOTICE.md) e [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) contêm a declaração formal de procedência, avisos e textos integrais das licenças de terceiros.
- Esta implementação PHP independente é disponibilizada à comunidade sob a licença **MIT**. Não é uma reescrita clean-room: foi escrita com as fontes públicas do Domian em mãos, e os arquivos que seguem uma classe específica do Domian ou reaproveitam uma frase da documentação dele dizem isso no DocBlock, preservando a atribuição de origem como exige a Seção 4 da Apache License.

---

## 📚 Referências & Bibliografia

- **[Domian Specification Framework](https://domian.sourceforge.net/index.html)**: Projeto Java original criado por Eirik Torske.
- **[The Specifications Pattern - Eric Evans & Martin Fowler](http://www.martinfowler.com/apsupp/spec.pdf)**: Artigo fundamental que introduziu o padrão.
- **[Domain-Driven Design: Tackling Complexity in the Heart of Software](https://www.domainlanguage.com/ddd/)**: Obra de referência de Eric Evans sobre modelagem de domínio rica.
- **[Design Patterns: Elements of Reusable Object-Oriented Software](https://en.wikipedia.org/wiki/Design_Patterns)**: Padrões GoF de referência (Composite, Decorator, Visitor, Factory).

---

## 📄 Licença

Distribuído sob a licença **MIT**. Consulte os arquivos [LICENSE](LICENSE), [NOTICE.md](NOTICE.md) e [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) para obter mais informações.

---

## 👨‍💻 Autor & Manutenção

**Heliton Junior (CTO)**  
- E-mail: [contato@antevemus.com.br](mailto:contato@antevemus.com.br)  
- Website: [antevemus.com.br](https://antevemus.com.br)  
- Organização: **Antevemus Soluções Inovadoras em TI Ltda.**
