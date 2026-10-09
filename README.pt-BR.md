# Antevemus ASpecification

<p align="left">
  <a href="README.md">🇺🇸 English</a> &nbsp;|&nbsp; <strong>🇧🇷 Português (Brasil)</strong>
</p>

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Latest Version](https://img.shields.io/badge/Release-v1.4.3-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v1.4.3)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-15%2F15%20Suites%20Pass%20(1612%20Assertions)%20%2B%20Domian%20Parity%20213-success)](tests/run_all.php)
[![Architecture](https://img.shields.io/badge/Architecture-DDD%20%7C%20Evans%20%26%20Fowler%20Specification-orange)](http://www.martinfowler.com/apsupp/spec.pdf)
[![Upstream: Domian](https://img.shields.io/badge/Origin-Domian%20(Apache%202.0)-brightgreen)](https://domian.sourceforge.net/index.html)
[![Synergy: ALinq](https://img.shields.io/badge/Synergy-Antevemus.AlinqCollection-purple)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **Framework Corporativo do Padrão Specification para PHP 8.2+** (PHP 8.4 só para a integração opcional com o ALinq)  
> Reimplementação PHP independente e não oficial, com evolução moderna, do renomado framework Java [Domian](https://domian.sourceforge.net/index.html), fundamentado no paper seminal [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) de Eric Evans e Martin Fowler. Enriquecido com Fluent Chaining em linguagem natural, Notification Pattern com diagnóstico rico de falhas, Dynamic Rule Engine para catálogos relacionais, SQL Query Visitor multi-SGBD (12 drivers, 7 dialetos SQL), TCriteria Builder para Adianti Framework, repositórios particionados em Grafo Acíclico Dirigido (DAG), persistência híbrida e primitivas avançadas de concorrência.

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
- ⚡ **SQL Query Visitor & Multi-SGBD (Módulo 12)**: Tradução direta da AST de especificações para cláusulas `WHERE` parametrizadas e seguras (`:p1`, `:p2`) com suporte a 12 drivers mapeados em 7 dialetos SQL (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite e ANSI).
- 🔗 **TCriteria Builder & Adianti Database Bridge (Módulo 13)**: Compilação direta de regras de domínio puro em objetos nativos `TCriteria` e `TFilter` do Adianti Framework, com inversão lógica de De Morgan para negações, preservação rigorosa de parênteses e precedência, e suporte fluente a paginação (`limit`, `offset`), ordenação (`orderBy`, `direction`) e agrupamento (`groupBy`).
- 🧩 **Dynamic Rule Engine & Requisitos Documentais (Módulo 11)**: Compilação dinâmica a partir de catálogos relacionais de banco de dados (`RuleDefinition`), triagem operacional de vereditos por severidade (`BLOCK`, `WARN`, `LOG`), e álgebra de requisitos documentais (`ALL`, `ANY`, `ONE_OF_SET`).
- ✂️ **Satisfação Parcial (`remainderUnsatisfiedBy`)**: Isole cirurgicamente em tempo de execução quais cláusulas específicas falharam para um determinado candidato.
- 📐 **Álgebra Booleana Completa & Subsunção**: Composição lógica rigorosa (`AND`, `OR`, `NOT`, `NOR / Joint Denial`), detecção de tautologias/contradições e cálculos de subsunção (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`).
- 🗄️ **Arquitetura de Repositórios & Particionamento em Grafo (DAG)**:
  - Descarte antecipado $O(1)$ de ramos em árvores de consulta através de disjunção de especificações.
  - Implementações em memória (`InMemoryRepository`), nulas (`NullRepository`) e fake (`FakePartitionRepository`).
  - Persistência desacoplada em disco (`FilePerEntityRepository`, `SingleFileRepository`) com serialização intercambiável (`JsonEntitySerializer` e `PhpNativeEntitySerializer`).
  - Cache híbrido $L1$ (RAM) + $L2$ (Disco) via `InMemoryAndFileRepository`.
- 🔒 **Controle de Concorrência & Locks Atômicos**: Primitivas `ISynchronizer` ligadas a todo repositório (leitores compartilhados, escritor exclusivo, reentrante por contexto de execução): o `SemaphoreSynchronizer` em processo (contador de permissões, ciente de Fibers) e locks atômicos de arquivo (`flock`) entre processos nos repositórios de arquivo. O `SysVSemaphoreSynchronizer` (SysV IPC) está previsto para a v1.5.0 (ROADMAP marco 6).
- ⏱️ **Telemetria de Alta Precisão & Benchmarking**: Cronômetro em nanossegundos (`StopWatch` via `hrtime`) e utilitários de diagnóstico hierárquico e consumo de memória (`InstrumentationUtils`).

---

## 🎯 Nossos Diferenciais

Enquanto a maioria das bibliotecas de *Specification* no ecossistema PHP se limita a verificações booleanas primitivas (`isSatisfiedBy: bool`) e o framework Java Domian original concentrava-se em reflexão em tempo de execução e coleções em memória, o **Antevemus ASpecification** foi concebido para os desafios de alta complexidade de microsserviços modernos, APIs corporativas e sistemas legados de missão crítica:

| Recurso / Capacidade | Domian (Java Original) | Bibliotecas Comuns de Specification (PHP) | **Antevemus ASpecification** |
| :--- | :---: | :---: | :---: |
| **Notification Pattern (Zero Exceptions)** | ❌ Apenas booleano | ❌ Apenas booleano ou exceptions | ✅ `evaluate()`, lista agregada de `SpecificationFailure`, códigos e razões |
| **Classificação por Severidade** | ❌ Não possui | ❌ Não possui | ✅ `BLOCK` (impeditivo), `WARN` (alerta operacional) e `LOG` (auditoria) |
| **Compilador SQL Multi-SGBD** | ❌ Não possui | ❌ Raro / restrito a 1 banco | ✅ **12 drivers, 7 dialetos** (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, ANSI) |
| **Adianti Framework Database Bridge** | ❌ Não aplicável | ❌ Não possui | ✅ `TCriteria` & `TFilter` nativos, De Morgan automático e parênteses estritos |
| **ALinq Synergy & Coleções Funcionais** | ❌ Não possui | ❌ Não possui | ✅ Compilador GoF de AST para predicados LINQ de curto-circuito e `ALinqBridge` |
| **Navegação em Propriedades Aninhadas** | ⚠️ Reflexão estrita | ⚠️ Apenas métodos públicos | ✅ **Dot-notation** (`PropertyAccessor`) em objetos profundos e arrays associativos |
| **Dynamic Rule Engine (Banco de Dados)** | ❌ Não possui | ❌ Não possui | ✅ Compilação dinâmica a partir de catálogos relacionais (`RuleDefinition`) |
| **Álgebra de Requisitos Documentais** | ❌ Não possui | ❌ Não possui | ✅ Modos `ALL`, `ANY` e `ONE_OF_SET` com vereditos agregados |
| **Fluent Chaining & DSL de Domínio** | ⚠️ Básico | ⚠️ Parcial | ✅ Sintaxe em prosa inglesa (`Spec::specify()->where()->and()->or()`) + helpers funcionais (`prop()`, `is()`, `not()`) |
| **Álgebra Booleana de Venn & Remainder** | ✅ Completo | ❌ Inexistente na maioria | ✅ `isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith` e `remainderUnsatisfiedBy` |
| **Particionamento DAG $O(1)$** | ✅ Presente | ❌ Raro | ✅ Repositórios com descarte antecipado de ramos disjuntos |
| **Concorrência & Locks** | ⚠️ Java Threads / Locks | ❌ Não suportado | ✅ Repositórios sincronizados (`SemaphoreSynchronizer` reentrante, ciente de Fibers) e locks `flock` atômicos entre processos; semáforos SysV IPC previstos para a v1.5.0 |
| **Tipagem Estrita e Recursos Modernos** | ⚠️ Java 6/7 Generics | ⚠️ PHP 7.x legado | ✅ **PHP 8.2+** nativo (Enums, First-class callables, Readonly, 8 Traits segregados) |

---

## 📋 Requisitos

- **PHP**: `^8.2` (testado no PHP 8.2 e 8.4). O PHP 8.4 só é exigido pela integração opcional com o ALinq (`Antevemus.AlinqCollection`, Módulo 14), detectada em tempo de execução.
- **Extensões PHP**:
  - `ext-json` (para serialização JSON; obrigatória)
  - `ext-mbstring` *(opcional hoje: as operações de string case-insensitive com Unicode previstas para a v1.5.0 vão usá-la)*
  - `ext-sysvsem` *(opcional: os semáforos SysV IPC previstos para a v1.5.0 vão usá-la para sincronização entre processos em Linux)*
- **Pacote opcional**: `antevemus/alinq-collection` `^1.1` para `ALinqBridge`, `ALinqSpecificationVisitor` e streaming lazy O(1) (exige PHP 8.4).

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

> **Nota de vocabulário.** O contrato de dados do motor de regras é em português por desenho (`escopo`, `cenario`, `acao`, `fundamento_legal`, `getValorInteiro()`, `getMensagemViolacao()`, ações `bloquear`/`alertar`/`apenas_log`): espelha o esquema do catálogo relacional que ele hidrata, um domínio jurídico brasileiro. As APIs de orquestração e veredito (`validate()`, `hasBlockingErrors()`, `canProceed()`) são em inglês. Não há previsão de aliases em inglês para o contrato de dados.

```php
use Antevemus\ASpecification\Spec;

// 1. Configura o Registry com Handlers plugáveis para os tipos de regra
$registry = Spec::ruleRegistry();
$registry->registerClosure('max_ocorrencias_por_contrato', function ($rule) {
    return Spec::specify(Contrato::class)
        ->must(fn($c) => $c->getOcorrenciasCount() <= $rule->getValorInteiro(), $rule->getCodigo(), $rule->getMensagemViolacao());
});

// 2. Cria a Engine dinâmica conectada ao Catálogo
$engine = Spec::engine($meuCatalogoRepository, $registry);

// 3. Valida a entidade para o escopo e cenário desejados
$verdict = $engine->validate(
    target: $contrato,
    escopo: 'contrato_locacao',
    cenario: 'contrato_locacao_ativacao'
);

if ($verdict->hasBlockingErrors()) {
    // Violações graves com ação 'bloquear' (ex: HTTP 403 Forbidden)
    return response()->json([
        'status' => 403,
        'erros' => $verdict->getBlockingFailures(),
        'fundamentos_legais' => $verdict->getLegalBases(),
    ], 403);
}

if ($verdict->hasWarnings()) {
    // Alertas não impeditivos com ação 'alertar'
    NotificationService::dispatch($verdict->getWarningFailures());
}
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

// 3. Suporte a 12 drivers em 7 dialetos SQL (SQL Server, Oracle, Firebird, MySQL, SQLite, etc.)
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

---

## 🏗️ Estrutura de Diretórios

```
src/
├── Attributes/                # Engine de Attributes Declarativos PHP 8 (Módulo 15)
│   ├── AssertSpec.php        # Attribute de referência a Specifications
│   ├── ValidateRule.php      # Attribute de validação inline rápida
│   ├── AttributeValidator.php # Avaliador de reflexão de alta performance
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
│   ├── Comparison/           # Equal, GreaterThan, LessThan, RelationalOperator
│   ├── Logical/              # AlwaysTrue, AlwaysFalse, JointDenial (NOR), DefaultValue
│   ├── String/               # Regex, Wildcard, DateString, EnumName
│   ├── Collection/           # CollectionSpecification, AllEntities, Unique
│   └── Reflection/           # FieldParameterized, MethodParameterized
├── Repositories/              # Repositórios concretos e particionamento
│   ├── File/                 # SingleFileRepository, FilePerEntityRepository
│   └── Serialization/        # JsonEntitySerializer, PhpNativeEntitySerializer
├── Concurrent/                # SemaphoreSynchronizer, FileLockSynchronizer
├── Engine/                    # DynamicSpecificationEngine, RuleEngineVerdict, Builders
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
├── Results/                   # SpecificationResult, SpecificationFailure
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

• [SUITE] Módulo 1: Especificações e Álgebra Booleana... ✅ PASS (71 asserções)
• [SUITE] Módulo 2: Entidades e Identificadores... ✅ PASS (8 asserções)
• [SUITE] Módulo 3: Repositórios em Memória e Base... ✅ PASS (17 asserções)
• [SUITE] Módulo 4: Arquitetura de Particionamento DAG... ✅ PASS (36 asserções)
• [SUITE] Módulo 5: Persistência em Arquivo e Decorator Híbrido... ✅ PASS (85 asserções)
• [SUITE] Módulo 6: Utilitários de Concorrência e RW-Lock... ✅ PASS (44 asserções)
• [SUITE] Módulo 7: Predicados, Fábricas, Helpers e Visitor... ✅ PASS (61 asserções)
• [SUITE] Módulo 8: Notification Pattern & SpecificationResult... ✅ PASS (133 asserções)
• [SUITE] Módulo 9: Facade Spec, Chaining Fluente & DSL... ✅ PASS (96 asserções)
• [SUITE] Módulo 10: Paridade Java, Telemetria & Remainder... ✅ PASS (65 asserções)
• [SUITE] Módulo 11: Dynamic Rule Engine & Requisitos Documentais... ✅ PASS (197 asserções)
• [SUITE] Módulo 12: SQL Query Visitor & Multi-SGBD Dialects... ✅ PASS (239 asserções)
• [SUITE] Módulo 13: TCriteria Builder & Adianti Database Bridge... ✅ PASS (153 asserções)
• [SUITE] Módulo 14: ALinq Synergy & Coleções Fluentes LINQ... ✅ PASS (194 asserções)
• [SUITE] Módulo 15: Attributes Declarativos PHP 8.4 (#[AssertSpec])... ✅ PASS (213 asserções)

====================================================================
 RESULTADO FINAL: 15/15 SUÍTES APROVADAS (100% PASS)
 TOTAL DE ASSERÇÕES: 1612 | TEMPO: ~200ms | REGRESSÕES: 0
====================================================================
```

- **Suíte de paridade Domian:** `tests/Parity/` transcreve os testes do Domian (Java) para a álgebra de especificações, o DAG de partições, os synchronizers, entidades e utilitários (213 testes PHPUnit, 2.043 asserções; `vendor/bin/phpunit` roda 228 testes e 3.670 asserções no total). A auditoria por trás dela está em `docs/PARIDADE-DOMIAN-2026-10-09.md`.
- **Mapeamento de APIs Públicas:** 1.200+ métodos documentados via PHPDoc corporativo padronizado.
- **Rastreabilidade Java (Domian):** paridade conceitual e arquitetural com o framework original (API pública e nomes de classe seguem o Domian; ver a tabela de procedência em [NOTICE.md](NOTICE.md)).
- **Decomposição Modular com Traits:** `SpecificationFactory` modularizada em 8 Traits especializados por domínio de regras.
- **ALinq Synergy & Coleções Fluentes:** Sinergia nativa com `Antevemus.AlinqCollection`, com compilador `ALinqSpecificationVisitor`, resolução flexível de propriedades `PropertyAccessor` (dot notation) e integração em `InMemoryRepository`.
- **Multi-SGBD SQL Translator:** 12 drivers (7 dialetos SQL) homologados com quoting de identificadores e prepared statements.
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
5a. **Paridade Domian: álgebra de subsunção, DAG de partições, repositórios sincronizados, entidades e utilitários** (v1.4.4, em andamento)
6. **Promessas do README II: IPC entre processos, Unicode, fontes lazy, catálogo relacional e `IN`** (v1.5.0)
7. **Backlog anunciado e não entregue I: identidades ULID / UUID v7 e severidade da falha** (v1.5.0)
8. **Aposentadoria do serializer PHP nativo, etapa 1: depreciação** (v1.5.0)
9. **`MethodParameterizedSpecification`: chamadas de método declarativas** (v1.6.0)
10. **Detecção de tautologia e contradição** (v1.6.0)
11. **Backlog anunciado e não entregue II: TTL, índice do DAG de partições e três dialetos SQL** (v1.6.0)
12. **`simplify()` de especificações** (v1.6.x)
13. **Aposentadoria do serializer PHP nativo, etapa 2: remoção e envelope só JSON** (v2.0.0)
14. **Accessor de propriedades compartilhado com o ALinq** (v2.0.0)
15. **PHP Fibers & Runner de Especificações Assíncronas Não-Bloqueantes** (v2.0.0)
16. **Cache Distribuído de Especificações** (PSR-6 / PSR-16 / Redis)
17. **Compiladores AST para GraphQL & OpenAPI 3.1**
18. **Doctrine ORM & Laravel Eloquent Query Visitors**
19. **Disparo Reativo de Domain Events**
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
