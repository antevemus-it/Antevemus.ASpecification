# Antevemus ASpecification

<p align="left">
  <a href="README.md">🇺🇸 English</a> &nbsp;|&nbsp; <strong>🇧🇷 Português (Brasil)</strong>
</p>

[![CI](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Latest Version](https://img.shields.io/badge/Release-v2.2.0-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v2.2.0)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-28%2F28%20Suites%20Pass%20(12307%20Assertions)%20%2B%20Domian%20Parity%20219-success)](tests/run_all.php)
[![Architecture](https://img.shields.io/badge/Architecture-DDD%20%7C%20Evans%20%26%20Fowler%20Specification-orange)](http://www.martinfowler.com/apsupp/spec.pdf)
[![Upstream: Domian](https://img.shields.io/badge/Origin-Domian%20(Apache%202.0)-brightgreen)](https://domian.sourceforge.net/index.html)
[![Synergy: ALinq](https://img.shields.io/badge/Synergy-Antevemus.AlinqCollection-purple)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **Framework Corporativo do Padrão Specification para PHP 8.2+** (PHP 8.4 só para a integração opcional com o ALinq)  
> Reimplementação PHP independente e não oficial, com evolução moderna, do renomado framework Java [Domian](https://domian.sourceforge.net/index.html), fundamentado no paper seminal [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) de Eric Evans e Martin Fowler. Enriquecido com Fluent Chaining em linguagem natural, Notification Pattern com diagnóstico rico de falhas, Dynamic Rule Engine para catálogos relacionais, SQL Query Visitor multi-SGBD (15 drivers, 10 dialetos SQL), TCriteria Builder para Adianti Framework, repositórios particionados em Grafo Acíclico Dirigido (DAG), persistência híbrida, primitivas avançadas de concorrência, máquina de estados dirigida por especificações, casos de uso com resultado comum aos dois, resolução concorrente dos dados externos de que as regras de I/O dependem, repositório sobre PDO cujas consultas são especificações compiladas para SQL (com colunas de ciclo de vida, operações em lote e sobre conjuntos e relacionamentos carregados em lote), eventos de domínio emitidos quando uma entidade passa a satisfazer ou deixa de satisfazer uma especificação, numa escrita ou só pela passagem do tempo, feature flags declaradas com alvo e histórico, e comparação de texto que ignora maiúsculas e acentos com a mesma resposta em memória e no SQL. Toda promessa da sua álgebra é conferida contra a avaliação por uma bancada de solidez.

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
- 📐 **Álgebra Booleana Completa & Subsunção**: Composição lógica rigorosa (`AND`, `OR`, `NOT`, `NOR / Joint Denial`), cálculo de subsunção (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`) e detecção estrutural de tautologia/contradição (`isTautology()`, `isContradiction()`): prova `A ∧ ¬A`, `A ∨ ¬A`, conjunções de operandos disjuntos, `in([])` e as identidades absorventes (`alwaysTrue()`/`alwaysFalse()`); é conservadora (`false` significa "não provado", não há resolução SAT), e o motor de regras aponta regras degeneradas do catálogo como `RuleCompilationWarning`; `simplify()` devolve uma árvore equivalente e menor (identidade, dupla negação, idempotência, subsunção, contradições e tautologias provadas) sem mexer nos nós anotados, com opção no motor de regras (exemplo 12). Desde a v2.1.0 uma bancada de solidez confere toda promessa da álgebra contra a avaliação de toda classe concreta de especificação (Qualidade de Código).
- 🗄️ **Arquitetura de Repositórios & Particionamento em Grafo (DAG)**:
  - Descarte antecipado $O(1)$ de ramos em árvores de consulta através de disjunção de especificações, sobre um índice topológico materializado do DAG (`findAll` 45 % a 77 % mais rápido que a caminhada recursiva, ver `tests/Benchmark/REPORT-dag-index.md`).
  - TTL e auto-prune em repositórios voláteis (`withTtl()`, `prune()`, relógio injetável), herdados pelas partições.
  - Implementações em memória (`InMemoryRepository`), nulas (`NullRepository`) e fake (`FakePartitionRepository`), e uma de banco, o `PdoRepository` (Módulo 22).
  - Persistência desacoplada em disco (`FilePerEntityRepository`, `SingleFileRepository`) com serialização plugável de entidade (`JsonEntitySerializer`) dentro de um documento JSON que é do repositório, com os metadados de persistência de cada entidade (leituras, gravações, primeira e última de cada) gravados ao lado dela e hidratados na carga. Zero `unserialize()` desde a v2.0.0: o serializer nativo do PHP saiu, e um arquivo `.bin` da 1.x é recusado com a receita de conversão do [UPGRADE-2.0.pt-BR.md](UPGRADE-2.0.pt-BR.md), para rodar na 1.14.x.
  - Cache híbrido $L1$ (RAM) + $L2$ (Disco) via `InMemoryAndFileRepository`.
- 🔒 **Controle de Concorrência & Locks Atômicos**: Primitivas `ISynchronizer` ligadas a todo repositório (leitores compartilhados, escritor exclusivo, reentrante por contexto de execução): o `SemaphoreSynchronizer` em processo (contador de permissões, ciente de Fibers) e locks atômicos de arquivo (`flock`) entre processos nos repositórios de arquivo. O `SysVSemaphoreSynchronizer` (`ext-sysvsem`) compartilha o mesmo lock leitores/escritor entre processos por semáforos SysV IPC.
- ⏱️ **Telemetria de Alta Precisão & Benchmarking**: Cronômetro em nanossegundos (`StopWatch` via `hrtime`) e utilitários de diagnóstico hierárquico e consumo de memória (`InstrumentationUtils`).
- 🚦 **Máquina de Estados Dirigida por Especificações (Módulos 17 e 18)**: todo guard é uma `ISpecification`, então a transição negada diz por quê (código, mensagem, propriedade, severidade); regiões paralelas, transições de região, ações nomeadas (`exit` → transição → `entry`), prazos persistidos (duração fixa, ou duração ou instante absoluto lidos do contexto, pausáveis com o saldo guardado enquanto uma condição vale); a definição é dado (builder ou JSON, versionada, com fingerprint, validada na carga); núcleo puro `transition()`/`can()`/`nextEvents()` e uma única porta de escrita, o `MachineRunner` (compare-and-set, chave de idempotência, histórico append-only, outbox transacional), sobre stores InMemory ou PDO com DDL ANSI de referência (exemplo 14).
- 🧭 **Casos de Uso (Módulo 19)**: `IUseCase` + `UseCaseRunner` (middlewares, `TransactionMiddleware`, validação do request em que só `ERROR` bloqueia), `UseCaseResult` com status de domínio fechado e HTTP só na borda (`toHttpStatus()`), nenhuma exceção ou stack trace no resultado, e `StateMachineEventUseCase` expondo um evento da máquina como caso de uso pelo `IOutcome` comum (exemplo 15).
- ⚡ **Resolução Concorrente de Dados (Módulo 21)**: uma regra que depende de dado externo (score de bureau, checagem de fraude, saldo) o declara com `Spec::external()` em vez de chamar o serviço; um `DataResolver` junta as declarações da árvore inteira, busca os dados em concorrência por `curl_multi` (sem dependência, funciona no PHP-FPM) e então avalia a árvore de forma síncrona. Uma política de custo (`concurrent()`, `tiered()` com lógica de três valores, `sequential()`) pula as fontes pagas que já não mudam o veredito; os pedidos são deduplicados; uma busca que falha é erro de avaliação da folha; parâmetros não mudam o host nem o caminho da chamada (exemplo 16).
- 🗄️ **Repositório Sobre PDO (Módulo 22)**: o `PdoRepository` implementa o `IRepository` sobre uma tabela: a consulta é uma especificação compilada para SQL parametrizado pelo visitor de SQL (qualquer dialeto), a escrita é um statement preparado numa transação, o `iterate()` lê o cursor em memória constante e o `count()` não monta entidade. Mapeamento por atributos (`#[Table]`, `#[Column]`, `#[Id]`, `#[Version]`) ou por builder; identificadores `auto`, `ulid`, `uuid7`, `assigned` e `max`; bloqueio otimista; especificação não traduzível é recusada em vez de filtrar a tabela em memória (`withInMemoryFallback()` é o opt-in explícito); só se consultam propriedades que uma especificação pode ler por nome. A porta de transação (`Persistence\ITransactionManager`) é compartilhada com os stores da máquina de estados (exemplo 17).
- 🧱 **Continuações do Repositório Sobre PDO (Módulos 25 e 26)**: colunas de ciclo de vida (`#[CreatedAt]`, `#[UpdatedAt]`, `#[SoftDelete]` com `withTrashed()`/`onlyTrashed()`/`forceRemove()`), ganchos no repositório (`beforePut`, `afterPut`, `beforeRemove`, `afterRemove`), log de SQL sem os valores dos parâmetros por padrão (`IQueryLogger`), booleanos gravados como 1/0 e fusos padrão por mapeamento e por repositório; `insertAll()` com `INSERT` de várias linhas e `putAll()` com um `SELECT` de existência por lote; `updateWhere()`, projeções com `select()`, `aggregate()->groupBy()->sum()/avg()/min()/max()/count()` e subconsultas com `Spec::inQuery()`; relacionamentos `#[ManyToOne]`/`#[OneToMany]` carregados explicitamente em lote com `with()` (uma consulta por relação, nunca N+1), composições gravadas e removidas com o pai, e órfãos removidos só de coleções que foram carregadas; associações `#[ManyToMany]` por tabela de junção (só os vínculos são gravados), `markLoaded()` para agregados montados à mão, `iterate()` com relações em páginas que funcionam com consultas sem buffer do MySQL, e booleanos `CHAR(1)` `'Y'`/`'N'` lidos de forma estrita (exemplos 17.1 a 17.3).
- 📡 **Eventos de Domínio a Partir de Especificações (Módulo 23)**: o `SpecificationTrigger` liga uma especificação a um evento de entrada e um de saída; o `TriggeringRepository` decora qualquer repositório e publica as travessias de cada escrita numa outbox (em memória ou PDO, DDL de referência `resources/sql/domain_events.ansi.sql`) gravada na mesma transação, entregue depois do commit pelo `DomainEventDispatcher` com chave de idempotência determinística e payload sem dado pessoal; o `MachineEventBridge` envia um evento à máquina de estados (exemplo 18).
- ⏰ **Eventos de Domínio pela Passagem do Tempo (Módulo 24)**: o `SpecificationTrigger::timed()` monta a especificação a partir do "agora"; o `TriggerSweeper`, chamado por um cron, acha as entidades que cruzaram desde o último estado gravado (`ITriggerStateStore`, em memória ou PDO, tabela `domain_event_trigger_state`) e publica pela mesma outbox, uma transação e um compare-and-set por travessia; o `baseline()` introduz um gatilho sobre dados antigos sem uma enxurrada de eventos; o `TriggeringRepository` mantém o mesmo estado, então escrita e varredura nunca publicam a mesma travessia duas vezes (exemplo 18.1).
- 🚩 **Feature Flags Declaradas (Módulo 27)**: a flag é declarada uma vez no código (`FeatureFlagRegistry`: textos, origem, alvo) e ligada no banco (`PdoFeatureFlagStore` sobre o DDL de referência `resources/sql/feature_flags.ansi.sql` ou sobre tabelas existentes); nasce desligada; ligada = chave ligada, dentro da vigência e o sujeito no alvo (uma lista de sujeitos editável sem deploy e uma especificação em código); toda resposta tem um motivo mapeado para os do OpenFeature; store ilegível dá desligada e é reportado, nunca em silêncio; a troca exige motivo, é compare-and-set e grava o histórico na mesma transação; `spec('flag')` compõe com qualquer especificação (exemplo 19).
- 🔤 **Comparação de Texto Sem Acento (Módulo 28)**: `TextComparison::IgnoreCaseAndAccents` em `contains()`, `startsWith()`, `endsWith()` e `equalIgnoreCaseAndAccents()` (`configuracao` acha `Configuração`, `ß` continua `ß`), com a mesma resposta em memória, no ALinq, no PostgreSQL, no MySQL e no MariaDB, e recusada onde o banco não consegue dá-la (exemplo 20).

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
| **Navegação em Propriedades Aninhadas** | ⚠️ Reflexão estrita | ⚠️ Apenas métodos públicos | ✅ **Dot-notation** (`PropertyAccess`, compartilhado com o ALinq) em objetos profundos e arrays associativos |
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
  - `ext-intl` *(opcional: `TextComparison::IgnoreCaseAndAccents`, a comparação de texto que também ignora acentos, exemplo 20; sem ela essa opção é recusada com `MissingExtensionException` e todo o resto funciona)*
  - `ext-sysvsem` *(opcional: `SysVSemaphoreSynchronizer`, o lock leitores/escritor compartilhado entre processos por semáforos SysV IPC, Linux/Unix)*
  - `ext-curl` *(opcional: `HttpDataSource`, a busca HTTP concorrente do `DataResolver`, Módulo 21; sem ela uma fonte HTTP é recusada ao ser registrada e o `CallableDataSource` continua funcionando)*
  - `ext-pdo` *(opcional, com o driver do seu banco: `PdoRepository` (Módulos 22, 25 e 26), os stores PDO da máquina de estados, o `PdoRuleCatalog` e a outbox PDO e o store PDO de estado dos gatilhos de eventos de domínio (Módulos 23 e 24))*
- **Dependência**: uma, do mesmo autor, sem dependências próprias: [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess) `^1.0` (desde a v2.0.0), o acessor de propriedades compartilhado com o ALinq, para que as duas bibliotecas leiam o mesmo valor do mesmo candidato (exemplo 10.1). O Composer a instala.
- **Pacote opcional**: `antevemus/alinq-collection` `^2.1.0` para `ALinqBridge` (retornos tipados `IALinqCollection`/`IALinqLazyCollection`), `ALinqSpecificationVisitor` e streaming lazy O(1) sobre `IRepository::iterate()` (exige PHP 8.4; o ALinq só lê propriedade não pública marcada com `#[Specifiable]`, a mesma regra deste pacote, e a 2.0 dele resolve propriedades pelo mesmo acessor compartilhado).

> **Vindo da 1.x?** Leia antes o [UPGRADE-2.0.pt-BR.md](UPGRADE-2.0.pt-BR.md): arquivos `.bin` do serializer nativo e catálogos de regras com valores em português são convertidos na 1.14.x, antes da atualização.

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

O documento é JSON qualquer que seja o serializer, e cada entidade leva os seus metadados de persistência (`__persistence_metadata`: contadores de leitura e gravação, primeira e última leitura e gravação) ao lado dos dados, então `getEntityMetaData()` responde igual em outro processo (desde a v2.0.0).

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

> **Nota de vocabulário.** O motor de regras é inteiro em inglês: getters (`getCode()`, `getIntValue()`, `getViolationMessage()`, `getLegalBasis()`), parâmetros nomeados (`scope:`, `scenario:`), chaves do `fromArray()` e colunas do catálogo (`code`, `rule_type`, `violation_action`, `product_code`, `valid_from`...), chaves de contexto (`product_code`, `plan_code`, `reference_date`), metadados das falhas (`action`, `rule_code`, `legal_basis`...) e os valores de ação `block`/`warn`/`log`. O vocabulário em português da v1.6, legado da v1.7.0 à v1.14.x, é recusado desde a v2.0.0 com `LegacyVocabularyException`, nunca ignorado: chave ou valor de ação em português num catálogo, chave de contexto, papel de tabela, chave de metadado de um handler. O [UPGRADE-2.0.pt-BR.md](UPGRADE-2.0.pt-BR.md) tem a tabela de chaves e o SQL que migra um catálogo; o `PdoRuleCatalog` continua lendo tabelas criadas com o esquema antigo por dois presets (abaixo), que mapeiam só os nomes.

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

// Tabelas criadas com o esquema em português da v1.5/v1.6 (regra_negocio...) são lidas pelos nomes; as ações precisam estar em inglês desde a v2.0 (UPGRADE-2.0.pt-BR.md)
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
// '(("st_ativo" IS NOT NULL AND "st_ativo" = TRUE) AND (("vl_salario" IS NOT NULL AND "vl_salario" > :p1) OR ("ds_cidade" IS NOT NULL AND "ds_cidade" LIKE :p2)))'
// (booleanos são renderizados pelo dialeto; os demais valores vão por binding; cada comparação leva a
// guarda IS NOT NULL, então um NOT sobre coluna NULL dá a resposta da memória)

print_r($whereClause->getBindings());
// [':p1' => 5000, ':p2' => 'São%']

// 3. Suporte a 15 drivers em 10 dialetos SQL (SQL Server, Oracle, Firebird, MySQL, SQLite, Db2, Informix, DuckDB, etc.)
$whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
// '(([ativo] IS NOT NULL AND [ativo] = 1) AND (([salario] IS NOT NULL AND [salario] > :p1) OR ([cidade] IS NOT NULL AND [cidade] LIKE :p2)))'

$whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
// '((`ativo` IS NOT NULL AND `ativo` = 1) AND ((`salario` IS NOT NULL AND `salario` > :p1) OR (`cidade` IS NOT NULL AND BINARY `cidade` LIKE :p2)))'
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

#### 10.1 Resolução Avançada de Propriedades com Dot Notation (`PropertyAccess`)
Avalie propriedades em objetos com getters ou métodos booleanos, arrays associativos e caminhos aninhados profundos. Desde a v2.0.0 a resolução mora no [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess), compartilhado com o ALinq (um acessor, um teste de contrato); `Helpers\PropertyAccessor` fica como fachada depreciada até a v3.0:

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

Cada segmento é resolvido nesta ordem, vence o primeiro que casar: chave de array ou `ArrayAccess`; método público `getX()`, `x()`, `isX()`, `hasX()` sem parâmetro obrigatório (nunca `__call()`, nunca nome começado por `__`); propriedade pública inicializada ou `__get` guardado por `__isset`; propriedade pública; e, por último, propriedade privada ou protegida **marcada com `#[Specifiable]`** pelo autor da classe (desde a v1.6.1). O nome da propriedade pode vir do request (um filtro ou uma ordenação montados pela tela), então o estado privado só é alcançado por nome quando o autor opta por isso. Propriedade não pública sem a marca se comporta como inexistente: a especificação de propriedade é erro de avaliação, `PropertyAccess::hasProperty()` responde `false` e um delta (`updateWithDelta()`) que a nomeie lança `InvalidArgumentException`, a menos que a entidade tenha setter público para ela.

```php
use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\Attributes\Specifiable;
use Antevemus\ASpecification\Spec;

final class Account
{
    #[Specifiable]
    private string $status = 'active';      // opt-in: legível por nome, gravável por delta
    private string $passwordHash = 'h4sh';  // sem a marca: invisível para um nome
}

$account = new Account();

Spec::property('status', Spec::equalTo('active'))->isSatisfiedBy($account);        // true
PropertyAccess::hasProperty($account, 'passwordHash');                              // false
Spec::property('passwordHash', Spec::startsWith('h'))->evaluate($account)->isError; // true, como propriedade inexistente
```

`#[Specifiable]` na classe vale para todas as propriedades **declaradas naquela classe** (não para as da superclasse nem das subclasses). `#[Antevemus\ALinq\Attributes\Specifiable]` e `#[Antevemus\ADeepPropertyAccess\Attributes\Specifiable]` são aceitos como a mesma marca, pelo nome.

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

**A álgebra nunca promete mais do que a avaliação entrega** (desde a v2.1.0). `equals()`, subsunção, disjunção, `isTautology()`/`isContradiction()`, `simplify()`, a deduplicação de `and()`/`or()` e a busca de partição são conferidos por uma bancada de solidez contra a avaliação de toda classe concreta de especificação sobre candidatos de fronteira (Qualidade de Código); onde discordavam, a álgebra agora prova menos. Duas consequências valem saber. **Datas são comparadas pelo instante**: `equalTo()`, `notEqual()` e `in()` comparam um `DateTimeInterface` pelo instante, qualquer que seja a classe e o fuso, como as folhas relacionais, `at()` e o visitor de SQL já faziam, então `Spec::equalTo(new DateTimeImmutable('2025-01-01 10:00', new DateTimeZone('UTC')))` aceita `new DateTime('2025-01-01 07:00', new DateTimeZone('America/Sao_Paulo'))` em memória como no banco (até a 2.0.0 a memória comparava instâncias); outros objetos são comparados por identidade, na avaliação e no `equals()`. E **limites inteiros não são fechados**: as folhas relacionais aceitam qualquer número, então `greaterThan(10)` e `lessThan(11)` não são disjuntas (`10.5` satisfaz as duas) e a conjunção delas não some na simplificação; um modo inteiro opcional está no roadmap (marco 25).

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

Os visitors SQL e TCriteria recusam a chamada de método como folha não traduzível (como `must()`); o visitor ALinq a compila. Um argumento de data é passado pelo instante (desde a v2.1.0): o método recebe uma cópia da mesma classe no mesmo instante, no fuso padrão do PHP (`date_default_timezone_get()`), qualquer que seja o fuso em que foi declarado, então duas chamadas que o `equals()` declara iguais também respondem igual.

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

### 16. Resolução Concorrente de Dados para Especificações Dependentes de I/O (Módulo 21)

Uma regra que depende de dado guardado em outro lugar (o score de um bureau de crédito, uma checagem de fraude, o saldo no core bancário) não chama o serviço. `Spec::external()` **declara** o dado de que precisa: o nome de uma fonte, parâmetros lidos do candidato (caminhos com ponto) e uma especificação comum que decide sobre o valor, com seus próprios `because()`/`withCode()`. Um `DataResolver` junta as declarações da árvore inteira, busca os dados em concorrência (`curl_multi`, sem dependência de pacote, funciona no PHP-FPM) e só então avalia a árvore, de forma síncrona, como toda especificação é avaliada. As fontes são registradas por nome: `HttpDataSource` (URL base fixa, um modelo de caminho com `{marcadores}`, cabeçalhos, tempo-limite próprio, custo e TTL de cache) e `CallableDataSource` (qualquer callable síncrono: um repositório, um SDK, um cliente gRPC).

```php
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;

$serasaUrl = 'https://bureau.example.com';         // da fonte, nunca do candidato
$fraudUrl  = 'https://fraud.example.com';
$bankUrl   = 'https://core-banking.example.com';

$registry = DataSourceRegistry::default()
    ->register('serasa.score', new HttpDataSource(
        baseUrl: $serasaUrl,
        path: '/serasa/score/{document}',
        parse: fn(int $status, array $headers, string $body): int => json_decode($body, true)['score'],
        headers: ['Authorization' => 'Bearer bureau-token'],
        timeout: 2.0,
        cost: 10,                                 // consulta paga ao bureau
    ))
    ->register('fraud.flag', new HttpDataSource(
        baseUrl: $fraudUrl,
        path: '/fraud/check/{document}',
        parse: fn(int $status, array $headers, string $body): bool => json_decode($body, true)['flagged'],
        cost: 5,
    ))
    ->register('bank.balance', new HttpDataSource(
        baseUrl: $bankUrl,
        path: '/bank/balance/{account}',
        parse: fn(int $status, array $headers, string $body): float => json_decode($body, true)['balance'],
        cost: 1,                                  // o nosso core bancário: barato
    ));

// A regra declara os dados de que precisa; não chama nada
$credit = Spec::external('bank.balance', ['account' => 'account.number'],
        Spec::greaterThanOrEqualTo(1000)->because('Balance below 1,000.')->withCode('LOW_BALANCE'))
    ->and(Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isFalse())->withCode('FRAUD_SUSPECTED'))
    ->and(Spec::external('serasa.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))
        ->because('Credit score below 700.')->withCode('SCORE_LOW'));

$customer = (object) ['cpf' => '111.444.777-35', 'account' => (object) ['number' => 'A-1']];
$resolver = new DataResolver($registry);

// concurrent() (o padrão): as três chamadas de uma vez, mais ou menos o tempo da mais lenta
$resolver->evaluate($credit, $customer, ResolutionPolicy::concurrent())->isSatisfied;      // true

// tiered() (escolha explícita): da mais barata para a mais cara, parando assim que o veredito está decidido
$poor = (object) ['cpf' => '222.333.444-05', 'account' => (object) ['number' => 'A-2']];
$data = $resolver->resolve($credit, $poor, ResolutionPolicy::tiered()); // saldo 10: o AND já é falso
count($data->getSkippedRequests());                   // 2: a checagem de fraude e o bureau não foram chamados
$data->evaluate($credit, $poor)->getCodes();          // ['LOW_BALANCE']

// Mesmo veredito em todas as políticas; concurrent() pagou todas as fontes e relata todas as falhas
$resolver->evaluate($credit, $poor, ResolutionPolicy::concurrent())->getCodes();          // ['LOW_BALANCE', 'SCORE_LOW']
$resolver->evaluate($credit, $poor, ResolutionPolicy::sequential())->isSatisfied;         // false
```

As políticas: `concurrent()` (o padrão) busca todos os pedidos da árvore numa etapa e devolve todas as falhas, como a avaliação fora do resolvedor; `tiered()` (escolha explícita, quando a chamada custa dinheiro) busca por custo, do mais barato ao mais caro, e depois de cada etapa avalia a árvore em lógica de três valores (verdadeiro, falso, desconhecido): quando o veredito não pode mais mudar (um `and` com um operando falso, um `or` cujo operando da esquerda é verdadeiro), as fontes mais caras não são chamadas; `sequential()` não busca nada antes e cada folha busca quando a avaliação chega nela. O veredito é o mesmo em todas as políticas e fora do resolvedor; no `tiered()`, um ramo que não foi buscado porque não podia mudar o veredito não relata falha, então a lista de falhas pode ser menor que a do `concurrent()`. Um `or` só para cedo pelo operando da esquerda: no núcleo, um erro no operando da esquerda aborta a disjunção, então um operando da esquerda desconhecido não pode ser pulado. Duas folhas que pedem o mesmo dado (mesma fonte, mesmos parâmetros) geram uma chamada só. Uma busca que falhou é desconhecida, nunca decide sozinha.

```php
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// Fora do resolvedor a mesma regra continua funcionando: cada folha busca o próprio dado pelo
// registro padrão, de forma síncrona (uma chamada depois da outra, sem deduplicação)
$credit->evaluate($customer)->isSatisfied;                          // true

// Uma fonte que falha é erro de avaliação da folha, nunca um "false" silencioso
$limitsUrl = 'https://limits.example.com';
$registry->register('credit.limit', new HttpDataSource(baseUrl: $limitsUrl, path: '/limits/{document}', cost: 2));
$limit = Spec::external('credit.limit', ['document' => 'cpf'], Spec::greaterThan(0));

$result = $resolver->evaluate($limit, $customer);                   // o serviço responde 503
$result->isError;                                                   // true
$result->failures[0]->message;                                      // 'The rule could not be evaluated.'
$result->exception->getStatusCode();                                // 503, para o log (o corpo nunca é guardado)

// Os visitors recusam a folha: o dado só existe quando a árvore é avaliada em PHP
try {
    $credit->toSql('pgsql');
} catch (NonTranslatableSpecificationException $e) {
    // filtre no SQL com as outras regras e avalie a parte externa em PHP
}
```

Os erros seguem a regra da 1.8.0: uma busca que falha (status HTTP fora de 2xx, erro de transporte, o tempo-limite da fonte ou da resolução inteira com `ResolutionPolicy::tiered(timeout: 3.0)`, um parser que lança, uma fonte desconhecida) é erro de avaliação da folha: falha com a mensagem genérica, `evaluation_error = true` e uma `DataFetchException` em `$result->exception` com o status, nunca o corpo da resposta. Não há retry. O valor de um parâmetro entra codificado no modelo de URL, então `/`, `?`, `#`, `..` ou uma URL inteira num número de documento não mudam o host nem o caminho da chamada; redirecionamentos não são seguidos. O cache entre resoluções é opcional (`new DataResolver($registry, new InMemoryDataCache())`, com o TTL de cada fonte; falhas nunca vão para o cache). O `ext-curl` é um `suggest`: sem ele, um `HttpDataSource` é recusado ao ser registrado e um `CallableDataSource` continua funcionando. Os visitors de SQL, TCriteria e ALinq recusam a folha com a exceção de "não traduzível". Os guards da máquina de estados continuam puros: o caso de uso resolve o dado antes de mandar o evento. Concorrência para fontes que não são HTTP (Fibers/Revolt) é um pacote satélite planejado.

### 17. Repositório Sobre PDO (Módulo 22)

O `PdoRepository` é o `IRepository` de uma tabela do banco: a consulta é uma especificação compilada para SQL pelo visitor de SQL (em qualquer dos seus dialetos), a escrita é um `INSERT`/`UPDATE`/`DELETE` preparado e o `iterate()` lê o cursor em streaming. Quem escreve a entidade a mapeia para a tabela, com atributos ou com um builder; uma propriedade mapeada é lida e escrita por reflexão mesmo sendo privada (o mapeamento é o opt-in do autor da classe). A biblioteca não gera DDL; relacionamentos são declarados na entidade e carregados explicitamente, em lote (exemplo 17.3).

```php
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;

#[Table('product')]
#[Version(property: 'version')]                   // bloqueio otimista no contador que o AbstractEntity declara
class Product extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]             // identity/auto-increment, lido de volta depois do INSERT
    private ?int $id = null;

    public function __construct(
        #[Column] public string $name,
        #[Column('unit_price', type: ColumnType::Decimal)] public string $price,   // decimal exato, como string
        #[Column] public bool $active = true,
        #[Column] private string $cost = '0.00',  // mapeada, mas privada sem getter: nunca consultável por nome
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80) NOT NULL,
    unit_price TEXT NOT NULL, active INTEGER NOT NULL, cost TEXT NOT NULL, version INTEGER NOT NULL)');

$products = PdoRepository::forEntity($pdo, Product::class);   // o dialeto vem do driver do PDO

$keyboard = new Product('Keyboard', '149.90', cost: '90.00');
$products->put($keyboard);                        // INSERT; o id gerado volta para a entidade
$keyboard->getEntityId();                         // 1
$keyboard->getVersion();                          // 1
$products->putAll([new Product('Mouse', '59.90'), new Product('Cable', '19.90', active: false)]); // uma transação

$active = Spec::property('active', Spec::isTrue());
$products->count($active);                        // 2: SELECT COUNT(*), nenhuma entidade montada
$products->findSingle(Spec::property('name', Spec::equalTo('Mouse')))->price;   // '59.90'
foreach ($products->iterate($active) as $product) {
    echo $product->name, PHP_EOL;                 // Keyboard, Mouse: uma linha por vez, do cursor
}
array_map(fn(Product $p): string => $p->name,
    $products->findPage($active, limit: 1, offset: 1, orderBy: ['name' => 'DESC']));   // ['Keyboard']
$products->toWhereClause($active)->toSql();       // '("active" IS NOT NULL AND "active" = 1)'
```

Bloqueio otimista: com um contador `#[Version]`, o `INSERT` grava 1 e cada `UPDATE` o incrementa e só casa com a linha enquanto ela ainda guarda a versão com que a entidade foi lida.

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;

$mine   = $products->findById(1);                 // as duas cópias têm a versão 1
$theirs = $products->findById(1);
$theirs->price = '139.90';
$products->update($theirs);                       // UPDATE ... WHERE id = 1 AND version = 1
$theirs->getVersion();                            // 2

$mine->price = '129.90';
try {
    $products->update($mine);                     // ainda na versão 1: o UPDATE não casa com nenhuma linha
} catch (OptimisticLockException $e) {
    // nada foi gravado: recarregue e aplique a mudança de novo
}
$products->findById(1)->price;                    // '139.90'
```

As regras que importam, e o builder para uma classe que não pode receber atributos:

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// Especificação que o visitor de SQL não traduz é recusada: nunca uma varredura da tabela em silêncio
$cheap = new PredicateSpecification(fn(Product $p): bool => (float) $p->price < 100);
try {
    $products->find($cheap);
} catch (NonTranslatableSpecificationException $e) {
    // escreva a regra com especificações de propriedade, ou faça o opt-in abaixo
}
count(PdoRepository::forEntity($pdo, Product::class)->withInMemoryFallback()->find($cheap)); // 2: lê a tabela inteira

// Só propriedade mapeada que uma especificação pode ler por nome (pública, getter público ou #[Specifiable])
try {
    $products->find(Spec::property('cost', Spec::greaterThan('50')));
} catch (NonTranslatableSpecificationException $e) {
    // "cost" é mapeada, mas privada sem getter: recusada no SQL como é recusada em memória
}

// O mesmo mapeamento, montado explicitamente
$mapping = EntityMapping::for(Product::class)->table('product')->id('id', IdStrategy::Auto)->version('version')
    ->column('name')->column('price', 'unit_price', ColumnType::Decimal)->column('active')->column('cost');
$mapping->equals(EntityMapping::fromAttributes(Product::class));  // true
$products = new PdoRepository($pdo, $mapping);
```

- **Consultas.** Todo valor vai por parâmetro preparado; nome de tabela e de coluna só vem do mapeamento, validado como identificador simples e citado pelo dialeto. Especificação que não se traduz (closure, chamada de método, dado externo) lança `NonTranslatableSpecificationException`; nada é filtrado em memória, a não ser que `withInMemoryFallback()` tenha sido chamado, e aí a tabela inteira é lida, então deixe isso para tabelas pequenas. Só se consulta propriedade mapeada **e** legível por nome (pública, getter público ou `#[Specifiable]`), a regra da avaliação em memória, então uma coluna privada nunca vira oráculo de leitura pelo SQL. `entityId` sempre significa a coluna do identificador.
- **Leituras.** `find*()`, `iterate*()` e `findPage()` ordenam pelo identificador (o `findPage()` o acrescenta ao fim da ordem pedida, então as páginas nunca se sobrepõem); `iterate()` é um generator sobre o cursor (memória constante); `count()` não monta entidade; `findSingle()` pede no máximo duas linhas e lança quando há mais de uma; `findById()` lê uma linha. As entidades são montadas sem chamar o construtor. O MySQL só faz streaming com `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` em `false` na conexão; no modo bufferizado padrão o driver guarda o resultado inteiro antes da primeira linha.
- **Escritas** rodam numa transação do `ITransactionManager` do repositório (por padrão um `PdoTransactionManager` na mesma conexão, que entra numa transação já aberta): `put()`/`update()` inserem ou atualizam pela presença do id e da linha, `putAll()` é tudo ou nada, `removeBy()` é um `DELETE ... WHERE`. Atualização ou remoção a partir de cópia desatualizada lança `OptimisticLockException` e não grava nada; uma cópia versionada cuja linha sumiu nunca é reinserida.
- **Identificadores**: `auto` (identity, serial, auto-increment; no PostgreSQL `INSERT ... RETURNING`, ou `lastInsertId()` da sequência declarada), `ulid`, `uuid7`, `assigned` e `max` (`SELECT COALESCE(MAX(id), 0) + 1` dentro da transação da escrita, compatível com o `IDPOLICY = 'max'` do `TRecord` do Adianti). O `max` está sujeito a corrida entre escritores concorrentes: duas transações podem ler o mesmo máximo e a chave primária recusa o segundo `INSERT`. Prefira `auto`, `ulid` ou `uuid7` em tabelas novas.
- **Valores nulos.** Uma coluna `NULL` é um valor nulo, e o banco responde para ela o que a avaliação em memória responde: `property('note', isNull())` casa com as linhas cuja `note` é `NULL`; uma comparação (`greaterThan()`, `startsWith()`, `equalTo('x')`, `in('a', 'b')`, ...) nunca casa com elas, e a sua negação `not(property('views', greaterThan(10)))` casa. Cada comparação é compilada como `(coluna IS NOT NULL AND ...)`, então é falsa ali, nunca o desconhecido do SQL, e um `NOT` sobre ela mantém a resposta da memória. Um `and`/`or`/`not` escrito *dentro* de uma propriedade recusa o valor nulo, como recusa qualquer candidato nulo: "sem nota, ou nota n2" é `anyOf(property('note', isNull()), property('note', equalTo('n2')))`, não `property('note', anyOf(isNull(), equalTo('n2')))`. O `Spec::toSql()` e o `Spec::toCriteria()` seguem a mesma regra (mudou na 1.11.0, ver o CHANGELOG).
- **Operadores de texto seguem a memória; a igualdade simples segue a coluna.** Desde a v1.14.0, `startsWith`/`endsWith`/`contains`, os curingas, `equalIgnoreCase` e `regex` selecionam no banco as linhas que a avaliação em memória aceita, em qualquer collation da coluna: no SQLite a forma sensível a maiúsculas usa `GLOB` (o `LIKE` dele ignora maiúsculas ASCII), e MySQL/MariaDB comparam por `utf8mb4_bin` e passam ao `REGEXP` a regra de maiúsculas como flag embutida; assim `startsWith('mo')` não casa com `Mouse` em lugar nenhum. Restam dois limites. A comparação sem distinção de maiúsculas de letras não ASCII (`É` e `é`) segue o banco onde ele só dobra ASCII (SQLite, colunas PostgreSQL com a collation `"C"`). O `=` simples (`equals`) é a comparação do próprio banco, então as collations `_ci` padrão do MySQL ignoram maiúsculas e acentos ali: declare a coluna com collation binária (`utf8mb4_bin`) para igualdade exata. Escreva a regex com o modificador `u` quando ela casar texto não ASCII (em memória, `.` sem `u` é um byte). O SQLite dá afinidade numérica a uma coluna `DECIMAL` (`'59.90'` volta como `'59.9'`), por isso o exemplo a declara `TEXT`; comparações nessa coluna passam a ser textuais.
- **A porta de transação é compartilhada** (`Persistence\ITransactionManager`): o `PdoTransactionManager` da máquina de estados é uma delas, então uma instância dá a mesma transação ao repositório, à outbox de eventos de domínio (exemplo 18) e aos stores da máquina.
- **Fora desta versão:** joins (relacionamentos carregam em lote por `IN`, exemplo 17.3), geração de DDL, cache de segundo nível, partições (`makePartition()` lança `PartitioningNotSupportedException`). Colunas de ciclo de vida, exclusão lógica, ganchos e o log de SQL estão no exemplo 17.1, operações em lote e sobre conjuntos no 17.2.

### 17.1. Ciclo de Vida: Carimbos de Tempo, Exclusão Lógica, Ganchos e o Log de SQL (Módulo 25)

A contabilidade que uma aplicação costuma escrever à mão em volta de uma tabela, como opções do mapeamento e do repositório: carimbos de criação e de alteração, uma remoção que marca a linha em vez de apagá-la, callables em volta de cada escrita, o log de cada comando, booleanos gravados como inteiros ou como texto e o fuso de colunas que guardam hora local sem deslocamento (as tabelas do Adianti, por exemplo). Sem elas nada muda.

```php
use Antevemus\ASpecification\Persistence\InMemoryQueryLogger;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\CreatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;

#[Table('ticket', timezone: 'America/Sao_Paulo', booleanAs: BooleanStorage::Integer)]   // hora local sem deslocamento, flags como 1/0
#[SoftDelete('deleted_at')]                       // remove() marca a linha em vez de apagá-la
class Ticket extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[CreatedAt] public ?DateTimeImmutable $createdAt = null;   // preenchido pelo INSERT, nunca atualizado
    #[UpdatedAt] public ?DateTimeImmutable $updatedAt = null;   // carimbado por toda escrita da linha

    public function __construct(
        #[Column] public string $subject,
        #[Column] public bool $urgent = false,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

// $pdo do exemplo 17
$pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, subject VARCHAR(80) NOT NULL,
    urgent SMALLINT NOT NULL, created_at TEXT, updated_at TEXT, deleted_at TEXT)');

$now = new DateTimeImmutable('2026-03-10 14:30:00.750 UTC');
$log = new InMemoryQueryLogger();
$tickets = PdoRepository::forEntity($pdo, Ticket::class)
    ->withClock(function () use (&$now): DateTimeImmutable { return $now; })   // o relógio do sistema por padrão
    ->withQueryLogger($log)                       // valores dos parâmetros ficam de fora, a não ser com logParameters: true
    ->beforePut(function (Ticket $ticket): void { // também afterPut(), beforeRemove(), afterRemove()
        if (trim($ticket->subject) === '') {
            throw new InvalidArgumentException('A ticket needs a subject.');
        }
    });

$ticket = new Ticket('Printer on fire', urgent: true);
$tickets->put($ticket);
$ticket->createdAt->format('Y-m-d H:i:s P');      // '2026-03-10 11:30:00 -03:00': no fuso do mapeamento, ao segundo
$pdo->query('SELECT created_at, urgent FROM ticket')->fetch(PDO::FETCH_NUM);   // ['2026-03-10 11:30:00', 1]
$log->entries()[0]['parameterCount'];             // 5
$log->entries()[0]['parameters'];                 // null: valores podem ser dado pessoal

$now = $now->modify('+2 hours');
$ticket->subject = 'Printer on fire, 3rd floor';
$tickets->put($ticket);
$ticket->updatedAt->format('H:i');                // '13:30' (createdAt continua '11:30')
```

A remoção marca a linha; toda leitura deixa as linhas marcadas de fora, e duas visões as enxergam:

```php
try {
    $tickets->put(new Ticket('  '));
} catch (InvalidArgumentException $e) {
    // o gancho beforePut recusou dentro da transação: nada foi gravado
}

$tickets->remove($ticket);                        // UPDATE ... SET deleted_at: a linha fica
$tickets->count(Spec::alwaysTrue());              // 0: find, iterate, count, findById, select e aggregate a ignoram
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 1: uma visão que também enxerga as linhas marcadas
$tickets->onlyTrashed()->findById(1)->subject;    // 'Printer on fire, 3rd floor'
$tickets->forceRemove($ticket);                   // DELETE: some de verdade
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 0
```

Flags legados gravados como texto, como as colunas `CHAR(1)` `'Y'`/`'N'` das tabelas do Adianti, são lidos de forma estrita:

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\DataIntegrityException;

#[Table('member')]
class Member extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column(booleanAs: BooleanStorage::Char)] public bool $active = true,   // 'Y' / 'N'
        #[Column(booleanAs: BooleanStorage::Char, trueValue: 'S', falseValue: 'N')] public ?bool $admin = null,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE member (id INTEGER PRIMARY KEY, active CHAR(1) NOT NULL, admin CHAR(1))');
$members = PdoRepository::forEntity($pdo, Member::class);
$members->putAll([new Member(1), new Member(2, active: false, admin: true)]);
$pdo->query('SELECT active, admin FROM member WHERE id = 2')->fetch(PDO::FETCH_NUM);   // ['N', 'S']
$members->toWhereClause(Spec::property('active', Spec::isTrue()))->toSql();
// ("active" IS NOT NULL AND "active" IN ('Y', 'y')): toda forma de caixa que a leitura aceita

$pdo->exec("UPDATE member SET active = 'X' WHERE id = 1");   // um valor que o mapeamento não declara
try {
    $members->findById(1);
} catch (DataIntegrityException $e) {
    $e->getStoredValue();                         // 'X': erro de integridade, nunca lido como false
}
```

- **Fusos.** Uma coluna temporal é gravada e lida no fuso que ela declara (`#[Column(timezone: ...)]`), senão no padrão do mapeamento (`#[Table(timezone: ...)]`, `EntityMapping::timezone()`), senão no padrão do repositório (`withTimezone()`), senão em UTC.
- **Carimbos.** `#[CreatedAt]` é preenchido pelo `INSERT` quando a entidade não traz valor (um valor importado é mantido) e nunca é atualizado; `#[UpdatedAt]` é carimbado pelo `INSERT`, por todo `UPDATE` e pela remoção lógica. O instante vem do relógio do repositório (`withClock()`, lido uma vez por chamada) e é truncado ao segundo, então a entidade guarda exatamente o que qualquer coluna de data e hora guarda.
- **Exclusão lógica.** `#[SoftDelete('deleted_at')]` na classe (só a coluna; acrescente `property:` para hidratá-la) ou numa propriedade de data anulável. `remove()` e `removeBy()` marcam só linhas vivas e incrementam o contador `#[Version]` como qualquer escrita; `withTrashed()` e `onlyTrashed()` devolvem visões (cópias do repositório); `forceRemove()` apaga. Uma linha marcada está removida para todo leitor: o `TriggeringRepository` publica a saída e o `TriggerSweeper` a vê sumir. `put()` de uma entidade cuja linha está marcada se comporta como depois de um `DELETE`: uma cópia versionada lança `OptimisticLockException`, uma sem versão é gravada de volta e volta a ser visível. O `toWhereClause()` não mostra a condição da exclusão lógica.
- **Ganchos** pertencem ao repositório (a entidade continua um objeto simples) e rodam dentro da transação da escrita: uma exceção num gancho `before*` ou `after*` desfaz a escrita e se propaga. O `beforePut` roda antes de o identificador ser gerado e de os carimbos serem preenchidos. Com um gancho de remoção, o `removeBy()` carrega as entidades que casam e as remove uma a uma, então um gancho nunca é contornado; sem gancho continua um comando só.
- **Log de SQL.** `IQueryLogger::log(string $sql, int $parameterCount, float $milliseconds, ?array $parameters)` recebe cada comando depois que ele rodou ou falhou; os valores só são passados com `withQueryLogger($logger, logParameters: true)`. Uma exceção lançada pelo logger é ignorada. O `InMemoryQueryLogger` guarda no máximo 10 000 entradas por padrão (`entries()`, `statements()`, `clear()`).
- **Booleanos.** `BooleanStorage::Integer`, por coluna (`#[Column(booleanAs: ...)]`) ou como padrão do mapeamento, grava 1/0 em todo driver, PostgreSQL inclusive (os flags `SMALLINT` de esquemas legados), e uma especificação compila para `"urgent" = 1`; a leitura aceita as duas formas. `BooleanStorage::Char` grava texto, `'Y'`/`'N'` a menos que `trueValue:`/`falseValue:` digam outro (na coluna, ou em `#[Table]`/`EntityMapping::booleanAs()` como padrão; de 1 a 16 letras ou dígitos, diferentes em qualquer caixa). Lê os dois textos em qualquer caixa (e sem os espaços com que uma coluna `CHAR` completa o valor) e `NULL` como `null`; qualquer outro valor gravado lança `DataIntegrityException` com a coluna e o valor. Uma comparação compila para toda forma de caixa do texto (`IN ('Y', 'y')`; `LOWER(col) = 'false'` para um texto de mais de duas letras), então o banco acha exatamente as linhas que a leitura aceita, e a semântica de nulos da 1.11.0 continua valendo. `Native` continua o padrão.
- **`extract()` e `hydrate()`.** `EntityMapping::extract($entity)` dá a linha de uma entidade (coluna => valor da propriedade) e `hydrate($row)` monta uma entidade a partir de uma linha sem chamar o construtor, ambos sem banco: o `toArray()`/`fromArray()` de um active record.

### 17.2. Escrita em Lote e Operações Sobre Conjuntos (Módulo 25)

Escritas e leituras sobre muitas linhas que não montam uma entidade por vez: um `INSERT` de várias linhas, um `UPDATE` por especificação, uma projeção em arrays, agregados agrupados calculados pelo banco e uma subconsulta sobre outro repositório.

```php
use Antevemus\ASpecification\Entities\AbstractUlidEntity;

#[Table('sale')]
#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]   // gerado no PHP: as linhas vão num INSERT só
class Sale extends AbstractUlidEntity
{
    public function __construct(
        #[Column('product_id')] public int $productId,
        #[Column] public ?string $region,
        #[Column(type: ColumnType::Decimal)] public string $amount,
        #[Column] public int $quantity = 1,
    ) {
        parent::__construct();
    }
}

$pdo->exec('CREATE TABLE sale (id CHAR(26) PRIMARY KEY, product_id INTEGER NOT NULL, region VARCHAR(10),
    amount TEXT NOT NULL, quantity INTEGER NOT NULL)');
$log->clear();
$sales = PdoRepository::forEntity($pdo, Sale::class)->withQueryLogger($log);

$sales->insertAll([                               // entidades novas, sem o SELECT de existência do put()
    new Sale(1, 'north', '139.90'),
    new Sale(1, 'south', '279.80', quantity: 2),
    new Sale(2, 'north', '59.90'),
    new Sale(3, null, '19.90'),
]);                                               // 4
count($log->statements());                        // 1: um INSERT de várias linhas

$sales->updateWhere(Spec::property('region', Spec::isNull()), ['region' => 'west']);   // 1: um UPDATE, nenhuma entidade carregada

iterator_to_array($sales->select(Spec::property('region', Spec::equalTo('north')), ['productId', 'amount']), false);
// [['productId' => 1, 'amount' => '139.90'], ['productId' => 2, 'amount' => '59.90']]: arrays, nenhuma entidade montada

$sales->aggregate(Spec::alwaysTrue())->groupBy('region')->sum('amount')->sum('quantity')->count()->get();
// [['region' => 'north', 'sum_amount' => '199.8', 'sum_quantity' => 2, 'count' => 2],
//  ['region' => 'south', 'sum_amount' => '279.8', 'sum_quantity' => 2, 'count' => 1],
//  ['region' => 'west', 'sum_amount' => '19.9', 'sum_quantity' => 1, 'count' => 1]]

// As vendas de produtos ativos ($products do exemplo 17): product_id IN (SELECT id FROM product WHERE ...)
$sales->count(Spec::inQuery('productId', $products, Spec::property('active', Spec::isTrue()), 'entityId'));   // 3
```

- **`insertAll()`** grava entidades novas numa transação, em lotes de `withBatchSize()` linhas (1 000 por padrão) que nunca passam do limite de parâmetros do driver; uma linha que já existe falha na chave e nada da chamada fica. Identificadores gerados no PHP vão no lote; identificadores `auto` voltam por `RETURNING` no PostgreSQL e são inseridos linha a linha nos outros drivers (o MySQL não garante valores consecutivos num `INSERT` de várias linhas). Medido com 10 000 entidades: cerca de 3 vezes mais rápido que o `put()` linha a linha no SQLite, de 20 a 40 vezes no PostgreSQL e no MySQL. O **`putAll()`** mantém o upsert, mas lê a existência de um lote inteiro com um `SELECT ... IN`.
- **`updateWhere()`** converte cada valor pelo tipo da coluna e recusa o identificador, o contador de versão, as colunas de ciclo de vida e propriedades não mapeadas antes de qualquer SQL. Ele carimba o `#[UpdatedAt]`, incrementa o `#[Version]` (uma cópia carregada antes fica desatualizada) e só toca linhas vivas. Ele **não roda gancho por entidade**, e o `TriggeringRepository` não o vê (um `TriggerSweeper` percebe as mudanças): use `putAll()` quando ganchos ou gatilhos precisam rodar. No MySQL a contagem devolvida é a das linhas cujos valores mudaram (a não ser que a conexão ligue `PDO::MYSQL_ATTR_FOUND_ROWS`), e o MySQL recusa subconsulta sobre a tabela sendo atualizada.
- **`select()`** é um generator de arrays indexados pelo nome da propriedade, cada valor convertido como um valor lido; **`aggregate()`** é imutável (`groupBy()`, `count()`, `sum()`, `avg()`, `min()`, `max()`, cada um com apelido opcional) e segue as regras de nulo do SQL: `sum`/`avg`/`min`/`max` ignoram `NULL`, a soma de nada é `null`, não 0, `count()` conta linhas e `count('prop')` valores não nulos, o grupo `NULL` vem primeiro. Uma coluna `Decimal` dá texto decimal canônico (`'199.8'`, não `'199.80'`; o SQLite não tem aritmética decimal e calcula em ponto flutuante, arredondado a 15 dígitos significativos). Os dois aplicam o escopo de leitura (exclusão lógica); um agregado exige especificação traduzível mesmo com o fallback em memória.
- **`Spec::inQuery($property, $other, $where, $otherProperty)`** compila para `col IN (SELECT othercol FROM other WHERE ... AND othercol IS NOT NULL)` quando os dois repositórios compartilham a conexão, com o escopo de leitura de `$other`; senão não é traduzível. Em memória a subconsulta roda uma vez por instância da especificação e os valores ficam guardados: monte uma nova para ver dados mais novos. O `whereRelation()` (exemplo 17.3) a monta a partir de um relacionamento.

### 17.3. Relacionamentos: Referências, Composições e Carga Ansiosa (Módulo 26)

Relacionamentos são declarados na entidade e carregados **explicitamente**, nunca de forma preguiçosa e sem proxies. Uma referência (`#[ManyToOne]`) é uma agregação: nunca é gravada junto com a entidade. Uma coleção (`#[OneToMany]`) é só leitura, a não ser que `cascade: true` a torne uma composição, gravada e removida com o pai. Uma associação (`#[ManyToMany]`) passa por uma tabela de junção: gravar o dono grava só os vínculos. Os repositórios das classes relacionadas são dados com `withRelated()`.

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToOne;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\OneToMany;

#[Table('customer')]
class Customer extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column] public string $name,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

#[Table('orders')]
#[SoftDelete('deleted_at')]
class Order extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('customer_id')] public ?int $customerId = null;                       // a verdade gravada da referência
    #[ManyToOne(Customer::class, 'customer_id')] public ?Customer $customer = null;   // agregação: nunca em cascata

    /** @var list<OrderLine> */
    #[OneToMany(OrderLine::class, mappedBy: 'orderId', cascade: true)] public array $lines = [];   // composição

    public function __construct(#[Column] public string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

#[Table('order_line')]
class OrderLine extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('order_id')] public ?int $orderId = null;   // preenchida pela cascata

    public function __construct(
        #[Column] public string $sku,
        #[Column] public int $quantity,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE customer (id INTEGER PRIMARY KEY, name VARCHAR(80) NOT NULL)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR(20) NOT NULL,
    customer_id INTEGER REFERENCES customer (id), deleted_at TEXT)');
$pdo->exec('CREATE TABLE order_line (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders (id),
    sku VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL)');

$customers = PdoRepository::forEntity($pdo, Customer::class);
$lines = PdoRepository::forEntity($pdo, OrderLine::class);
$orders = PdoRepository::forEntity($pdo, Order::class)->withRelated($customers, $lines);

$customers->putAll([new Customer(1, 'Ana'), new Customer(2, 'Bruno')]);
foreach (range(1, 100) as $n) {
    $order = new Order("PO-{$n}");
    $order->customer = $customers->findById($n % 2 + 1);   // uma referência precisa de identificador: grave-a antes
    $order->lines = [new OrderLine('KB-1', 1), new OrderLine('MS-2', $n)];
    $orders->put($order);                         // o pedido, depois as linhas (order_id preenchido), numa transação
}
```

A carga ansiosa é uma visão, `with()`; cada relação custa uma consulta por lote de entidades, nunca uma por entidade:

```php
$log->clear();
foreach ([$orders, $customers, $lines] as $repository) {
    $repository->withQueryLogger($log);
}
$all = $orders->with('lines', 'customer')->find(Spec::alwaysTrue());
count($all);                                      // 100
count($log->statements());                        // 3: os pedidos, depois as linhas e os clientes deles por IN (...)
$all[0]->customer->name;                          // 'Bruno'
$all[0]->customer === $all[2]->customer;          // true: uma instância por cliente
count($all[0]->lines);                            // 2

// O filtro por um relacionamento é uma subconsulta sobre a chave estrangeira
$orders->count($orders->whereRelation('customer', Spec::property('name', Spec::equalTo('Ana'))));   // 50
$orders->count($orders->whereRelation('lines', Spec::property('quantity', Spec::greaterThan(95))));   // 5
```

Uma coleção não carregada nunca é tomada como vazia, e só uma carregada tem órfãos:

```php
$first = $orders->with('lines')->findById(1);
array_pop($first->lines);                         // coleção carregada: a linha que saiu dela é órfã
$orders->put($first);                             // ...e é apagada
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$bare = $orders->findById(1);                     // sem with(): linhas e cliente não carregados
$orders->isLoaded($bare, 'lines');                // false: [] aqui quer dizer "não sei", não "nenhuma"
$orders->put($bare);                              // não remove linha nenhuma e mantém customer_id
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$orders->remove($first);                          // Order tem #[SoftDelete]: marcado; OrderLine não tem, as linhas ficam
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1
$orders->forceRemove($first);                     // uma remoção física apaga antes todas as linhas
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 0
```

Uma associação nunca é composição: os alvos vivem por conta própria, e uma coleção carregada só acrescenta e tira vínculos:

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToMany;

#[Table('tag')]
class Tag extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column] public string $name,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

#[Table('article')]
class Article extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    /** @var list<Tag> */
    #[ManyToMany(Tag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]
    public array $tags = [];                          // associação: só os vínculos são gravados

    public function __construct(#[Column] public string $title)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE tag (id INTEGER PRIMARY KEY, name VARCHAR(30) NOT NULL)');
$pdo->exec('CREATE TABLE article (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(80) NOT NULL)');
$pdo->exec('CREATE TABLE article_tag (article_id INTEGER NOT NULL REFERENCES article (id),
    tag_id INTEGER NOT NULL REFERENCES tag (id), PRIMARY KEY (article_id, tag_id))');

$tags = PdoRepository::forEntity($pdo, Tag::class);
$articles = PdoRepository::forEntity($pdo, Article::class)->withRelated($tags);
[$php, $sql, $orm] = [new Tag(1, 'php'), new Tag(2, 'sql'), new Tag(3, 'orm')];
$tags->putAll([$php, $sql, $orm]);                // os alvos são gravados pelo repositório deles

$article = new Article('Specifications over PDO');
$article->tags = [$php, $sql];
$articles->put($article);                         // o artigo, depois duas linhas de article_tag

$log->clear();
$tags->withQueryLogger($log);
$articles->withQueryLogger($log);
$loaded = $articles->with('tags')->findById(1);
count($log->statements());                        // 3: o artigo, os vínculos, as etiquetas
$loaded->tags = [$sql, $orm];
$articles->put($loaded);                          // desvincula php, vincula orm: nenhuma etiqueta é gravada
$articles->count($articles->whereRelation('tags', Spec::property('name', Spec::equalTo('orm'))));   // 1
```

Uma coleção montada à mão pode ser declarada carregada, e `iterate()` carrega relações página a página:

```php
$edited = $articles->findById(1);                 // sem with(): etiquetas não carregadas, put() não as tocaria
$edited->tags = [$php];
$articles->markLoaded($edited, 'tags');           // PERIGO: declarada INTEIRA, o que faltar é removido
$articles->put($edited);                          // vincula php, desvincula sql e orm
count($articles->with('tags')->findById(1)->tags);   // 1

$view = $articles->with('tags')->withBatchSize(500);
foreach ($view->iterate(Spec::alwaysTrue()) as $one) {
    $one->tags[0]->name;                          // 'php': páginas de 500 por id, cada uma lida antes das etiquetas dela
}
```

- **Carga ansiosa.** `with('lines', 'customer', 'lines.product')` devolve uma visão cujas leituras (`find*()`, `iterate*()`, `findSingle()`, `findPage()`, `findById()`) carregam essas relações para cada lote de `withBatchSize()` entidades com um `SELECT ... WHERE fk IN (...)` por relação (dois numa associação: a tabela de junção, depois os alvos; mais só além do limite de parâmetros do driver), no escopo de leitura do repositório relacionado (as linhas dele removidas logicamente não são carregadas). Um caminho com pontos vai tão fundo quanto as relações vão, uma consulta a mais por segmento e lote. Com 1 000 pedidos de 10 itens cada: 2 consultas para `with('items')` e 4 para `with('items.product', 'customer')`, contra 1 001 de um laço por pedido, no SQLite, no PostgreSQL e no MySQL.
- **Não carregado não é vazio.** Uma relação não carregada mantém o seu padrão (`[]`, `null`) e o repositório sabe disso (`isLoaded()`; o estado fica fora da entidade). O `put()` de um pai cuja coleção não foi carregada não remove filho nenhum, e uma referência não carregada nunca limpa a chave estrangeira: só uma referência carregada não nula e depois posta em `null` a limpa.
- **Referências** nunca vão em cascata: o `put()` grava o identificador do objeto referenciado na propriedade da chave estrangeira e recusa objeto sem identificador.
- **Composições.** `put()`, `putAll()` e `insertAll()` gravam os filhos depois do pai, na transação dele (mesma conexão obrigatória), preenchendo a chave estrangeira (e a referência de volta do filho quando o `mappedBy` nomeia uma). Órfãos só são removidos quando a coleção foi carregada (ou o pai acabou de ser inserido), e só os filhos carregados com ela que ainda pertencem a ele, cada um com a versão conferida: um filho acrescentado por outro nesse meio-tempo nunca é removido. Mover um filho entre dois pais carregados é seguro dentro de um `putAll()`.
- **Remover um pai** remove antes os filhos. Um pai com `#[SoftDelete]` marca os filhos que têm `#[SoftDelete]` e mantém os outros (a linha dele fica, então, se o pai for revivido, eles continuam lá); uma remoção física (`forceRemove()`, ou um pai sem exclusão lógica) apaga todas as linhas dos filhos, marcadas ou não, já que nenhuma linha filha pode sobreviver à do pai.
- **Eventos de domínio.** Registre um `TriggeringRepository` com `withRelated()` e as cascatas e os órfãos passam por ele, publicando as entradas e saídas dos filhos (ele lê pelo seu `PdoRepository`); um `PdoRepository` sem decorador não publica nada e um `TriggerSweeper` percebe. Um `TriggeringRepository` sobre o pai lê o "antes" sem relações: os gatilhos dele devem ler colunas.
- **`whereRelation('customer', $spec)`** é `customer_id IN (SELECT id FROM customer WHERE ...)` e **`whereRelation('lines', $spec)`** é `id IN (SELECT order_id FROM order_line WHERE ...)`, com o escopo de leitura do repositório relacionado; a propriedade da chave estrangeira precisa ser legível por uma especificação. Uma propriedade de relação não é coluna: uma especificação não a lê.
- **Associações.** `#[ManyToMany(Tag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]` (ou `EntityMapping::manyToMany()`); os nomes da tabela e das colunas vêm só do mapeamento, validados como identificadores, e o repositório do alvo tem de usar a mesma conexão. `put()`, `putAll()` e `insertAll()` de um dono cuja coleção foi carregada (ou que acabou de ser inserido) leem os vínculos gravados dele uma vez por lote, inserem os novos (várias linhas por comando) e apagam os dos alvos que saíram da coleção (só alvos carregados com ela), sem nunca gravar nem apagar um alvo; um alvo sem identificador é recusado. Uma coleção **não** carregada nunca toca a tabela de junção, mesmo trazendo alvos. Um alvo removido logicamente fica fora da coleção carregada e o vínculo dele permanece. A remoção física do dono apaga antes os vínculos dele; a remoção lógica os mantém. `whereRelation('tags', $spec)` é `id IN (SELECT article_id FROM article_tag WHERE tag_id IN (SELECT id FROM tag WHERE ...))`. Com 1 000 donos de 5 alvos cada, `with('tags')` são 3 consultas no SQLite, no PostgreSQL e no MySQL.
- **`markLoaded($entity, 'lines', 'tags')`** declara coleções montadas à mão (de um formulário, de um cache) como carregadas e **inteiras**: o próximo `put()` remove os órfãos (composição `#[OneToMany]`) ou os vínculos (`#[ManyToMany]`, fora os alvos removidos logicamente) que faltam na coleção, relativos ao que ela traz nesse `put()`. **Declarar uma coleção incompleta apaga o que falta nela**, inclusive um filho ou vínculo que outro acrescentou: uma coleção carregada por `with()` nunca remove esses. Depois da gravação a coleção fica carregada como de costume. `markUnloaded()` faz uma coleção voltar a não dizer nada.
- **`iterate()` com relações** pagina por chave, `WHERE ... AND id > :last ORDER BY id LIMIT lote`: cada página é lida inteira e o cursor dela fechado antes de as relações dela carregarem, então funciona com consultas sem buffer do MySQL (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false`) e guarda uma página de entidades por vez (10 000 linhas com `with('tags')` em páginas de 500: cerca de 1 MB no pico, contra 20 MB do `find()`, no SQLite). `find*()` e `findPage()` terminam a leitura antes de carregar. Sem relações, `iterate()` lê um cursor só, como antes.

### 18. Eventos de Domínio a Partir de Especificações (Módulo 23)

Um gatilho liga uma especificação a eventos de domínio: quando uma entidade **passa a** satisfazê-la, o gatilho emite o evento de entrada; quando **deixa de** satisfazer, o de saída. O `TriggeringRepository` decora qualquer `IRepository`: em `put()`/`update()`/`updateWithDelta()` lê o estado guardado da entidade pela identidade, grava, avalia cada gatilho no antes e no depois e publica as travessias; em `remove()`/`removeBy()` emite as saídas. O publicador de produção grava numa outbox na mesma transação da escrita, e o `DomainEventDispatcher` a entrega depois do commit, como os efeitos da máquina de estados.

```php
use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\DomainEvents\Outbox\InMemoryDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\OutboxDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\SpecificationTrigger;
use Antevemus\ASpecification\DomainEvents\TriggeringRepository;
use Antevemus\ASpecification\DomainEvents\TriggerSet;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Spec;

class Policy extends AbstractEntity
{
    public int $daysOverdue = 0;
    public bool $documentsValidated = false;
    public string $holderName = 'Maria Silva';    // dado pessoal: nunca copiado para um evento

    public function __construct(private string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}

$triggers = new TriggerSet(
    new SpecificationTrigger('policy.delinquent', Spec::property('daysOverdue', Spec::greaterThan(30)),
        onEnter: 'delinquency.started', onExit: 'delinquency.ended'),
    new SpecificationTrigger('policy.documents', Spec::property('documentsValidated', Spec::isTrue()),
        onEnter: 'documents.validated'),
);

$outbox = new InMemoryDomainEventOutbox();        // PdoDomainEventOutbox em produção
$policies = new TriggeringRepository(new InMemoryRepository(), $triggers, new OutboxDomainEventPublisher($outbox));

$policy = new Policy('P-1');
$policies->put($policy);                          // não satisfaz nenhum gatilho: nenhum evento
$policy->daysOverdue = 45;
$policies->update($policy);                       // passa a satisfazer policy.delinquent
$policies->update($policy);                       // nenhuma travessia, nenhum evento
$policy->daysOverdue = 0;
$policies->update($policy);                       // deixa de satisfazer
$outbox->count();                                 // 2

$event = $outbox->findByEntity(Policy::class, 'P-1')[0]->event;
$event->name;                                     // 'delinquency.started'
$event->getPayload();                             // ['trigger' => 'policy.delinquent', 'entity_id' => 'P-1']

// Depois do commit (um worker ou um cron): cada evento aos seus handlers, na ordem de escrita por entidade
$seen = [];
$handlers = (new DomainEventHandlerRegistry())
    ->register('delinquency.started', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; })
    ->register('delinquency.ended', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; });
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 2
$seen;                                            // ['delinquency.started', 'delinquency.ended']
```

Um evento pode mover uma máquina de estados (exemplo 14): o `MachineEventBridge` é um handler pronto que o envia a um `MachineRunner` como evento interno com a chave de idempotência do evento de domínio, então uma reentrega devolve o resultado original e não move a instância de novo.

```php
use Antevemus\ASpecification\DomainEvents\MachineEventBridge;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

// "Todos os documentos validados" move a análise sem clique
$review = Spec::machine('policy_review', 1)
    ->region('lifecycle', 'waiting_documents')
        ->state('waiting_documents')
            ->on('VALIDATE', 'under_review')
        ->finalState('under_review')
    ->build();
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$review]), MachinePersistence::inMemory());
$runner->start('review-P-1', 'policy_review', 1, null, new DateTimeImmutable('2026-10-11 09:00'));

(new MachineEventBridge($runner, ['documents.validated' => 'VALIDATE'], fn(DomainEvent $e): ?string => 'review-' . $e->entityId))
    ->registerIn($handlers);

$policy->documentsValidated = true;
$policies->update($policy);                       // documents.validated vai para a outbox
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 1
$runner->load('review-P-1')->snapshot->stateOf('lifecycle');                           // 'under_review'
```

Sobre o `PdoRepository` do exemplo 17, a linha e os seus eventos são confirmados juntos quando o repositório e a outbox compartilham a conexão e o gerenciador de transação. O decorador então lê o "antes" com `Spec::property('entityId', Spec::equalTo($id))`, compilado para SQL, sem configuração:

```php
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;

// Uma vez, com a sua ferramenta de migração: resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);  // uma unidade de trabalho para a linha e os seus eventos
$catalog = new TriggeringRepository(
    PdoRepository::forEntity($pdo, Product::class, $transactions),
    new TriggerSet(new SpecificationTrigger('product.withdrawn', Spec::property('active', Spec::isFalse()), onEnter: 'product.withdrawn')),
    new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo)),
    $transactions,
);

$mouse = $catalog->findSingle(Spec::property('name', Spec::equalTo('Mouse')));
$mouse->active = false;
$catalog->update($mouse);                         // UPDATE product e INSERT INTO domain_event_outbox, um commit
$events->count();                                 // 1
```

- **O payload é mínimo de propósito**: o nome do gatilho e o id da entidade, nunca a entidade, então nenhum dado pessoal chega a uma fila ou a um log; o handler que precisa de mais lê a entidade pelo id. Cada evento leva uma chave de idempotência determinística (`de:` + SHA-256 de gatilho, classe da entidade, id da entidade, tipo e instante): a outbox guarda uma travessia uma vez só, então reaplicar a mesma escrita não a duplica.
- **Ler o "antes" custa uma leitura por escrita** (uma varredura nos repositórios em memória). Sobre um `PdoRepository` a busca pela identidade é a de SQL mostrada acima; para outro repositório que traduz especificações para SQL, passe `identitySpecification: TriggeringRepository::byIdProperty('id')`. Os repositórios em memória guardam o próprio objeto de quem chama, então o decorador compara com os estados que registrou na última escrita daquele objeto por ele. **Limitação conhecida:** nos repositórios em memória, uma entidade guardada antes de o decorador existir (ou gravada direto no repositório interno) e depois alterada no próprio objeto não tem "antes", e essa escrita não emite nada: escreva pelo decorador desde o início. Com um store de estado dos gatilhos (exemplo 18.1) o "antes" é o estado gravado, e a limitação some para toda entidade que tem um.
- **A entrega** é pelo menos uma vez, na ordem de escrita por entidade: os handlers deduplicam pela chave de idempotência que recebem. Um handler que falha nunca desfaz a escrita; a entrada fica marcada como falha (sem stack trace) e é tentada de novo, segurando os eventos seguintes daquela entidade até dar certo ou, com `maxAttempts`, atingir o limite. Evento sem handler é tentativa falha, não descarte silencioso.
- **Erros:** uma especificação que lança ao ser avaliada não emite evento; por padrão a escrita segue e o erro vai para um `IDomainEventErrorReporter`, e com `failOnEvaluationError: true` a escrita lança. Falha do publicador (a outbox) sempre propaga.
- **A travessia causada só pela passagem do tempo**, sem escrita (uma apólice que vence à meia-noite sem ninguém gravá-la), o decorador não vê: é o varredor por tempo do exemplo 18.1. Event sourcing e cliente de barramento de mensagens estão fora do escopo: publique no Kafka ou no RabbitMQ a partir de um handler.

### 18.1. Eventos de Domínio pela Passagem do Tempo (Módulo 24)

"Não paga e vencida há mais de 30 dias" vira verdade à meia-noite sem ninguém gravar a fatura: só o relógio andou, então os gatilhos de escrita do exemplo 18 nunca a veem. Um **gatilho temporal** monta a especificação a partir do "agora"; um **store de estado** guarda, para cada gatilho e entidade, a última resposta conhecida (`satisfied`, `evaluated_at`); um **varredor**, chamado pelo seu cron com o instante injetado, compara os dois, grava cada travessia e a publica pelo mesmo publicador e pela mesma outbox, com o mesmo tipo de chave de idempotência. O `TriggeringRepository` mantém o mesmo store a cada escrita, então escrita e varredura nunca publicam a mesma travessia duas vezes.

```php
use Antevemus\ASpecification\DomainEvents\State\InMemoryTriggerStateStore;
use Antevemus\ASpecification\DomainEvents\TriggerSweeper;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

#[Table('invoice')]                               // mapeada para o bloco PDO abaixo; usada como está em memória
class Invoice extends AbstractEntity
{
    #[Column] public bool $paid = false;

    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private string $number,
        #[Column('due_date')] public DateTimeImmutable $dueDate,
    ) {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}

$overdue = new TriggerSet(SpecificationTrigger::timed(
    'invoice.overdue',
    fn(DateTimeImmutable $now) => Spec::property('paid', Spec::isFalse())
        ->and(Spec::property('dueDate', Spec::lessThan($now->modify('-30 days')))),
    onEnter: 'collection.started',
    onExit: 'collection.ended',
    crossedAt: fn(Invoice $invoice) => $invoice->dueDate->modify('+30 days'),   // o instante real da travessia
));

$today = new DateTimeImmutable('2026-01-01');
$clock = function () use (&$today): DateTimeImmutable { return $today; };   // o relógio do sistema por padrão

$states = new InMemoryTriggerStateStore();        // PdoTriggerStateStore em produção
$outbox = new InMemoryDomainEventOutbox();
$repository = new InMemoryRepository();
$invoices = new TriggeringRepository($repository, $overdue, new OutboxDomainEventPublisher($outbox), clock: $clock, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, new OutboxDomainEventPublisher($outbox));

$invoice = new Invoice('INV-1', new DateTimeImmutable('2026-01-05'));
$invoices->put($invoice);                         // vence em 4 dias: nenhum evento; o estado é gravado
$sweeper->sweep(new DateTimeImmutable('2026-01-20'))->enteredCount();   // 0
$sweeper->sweep(new DateTimeImmutable('2026-02-05'))->enteredCount();   // 1: ninguém gravou, o tempo passou
$sweeper->sweep(new DateTimeImmutable('2026-02-06'))->enteredCount();   // 0: já anunciada
$outbox->findByEntity(Invoice::class, 'INV-1')[0]->event->occurredAt == $invoice->dueDate->modify('+30 days');   // true

$today = new DateTimeImmutable('2026-02-09');
$invoice->paid = true;
$invoices->update($invoice);                      // collection.ended, publicado pela escrita
$sweeper->sweep(new DateTimeImmutable('2026-02-10'))->exitedCount();    // 0: a escrita já publicou
$outbox->count();                                 // 2
```

> ⚠️ **Vai introduzir um gatilho sobre dados antigos? Rode `baseline()` antes.** Entidade sem estado gravado conta como "não satisfazia", então a primeira varredura publica uma entrada para cada fatura que já está vencida. É a verdade (elas estão do lado de dentro e ninguém foi avisado), mas raramente o que um disparo de e-mails quer. `baseline($now)` grava o estado atual de todos os gatilhos para todas as entidades e não publica nada; as varreduras seguintes publicam só as travessias novas.

```php
// Gatilho introduzido sobre dados antigos: baseline() antes, ou a primeira varredura anuncia tudo o que já estava dentro
$archive = new InMemoryRepository([
    new Invoice('INV-2', new DateTimeImmutable('2025-11-03')),   // vencida desde dezembro
    new Invoice('INV-3', new DateTimeImmutable('2026-03-02')),
]);
$catchUp = new TriggerSweeper($archive, Invoice::class, $overdue, new InMemoryTriggerStateStore(), new OutboxDomainEventPublisher($outbox));
$catchUp->baseline(new DateTimeImmutable('2026-02-10'))->recordedCount();   // 2: um estado por fatura, nenhum evento
$catchUp->sweep(new DateTimeImmutable('2026-02-11'))->enteredCount();      // 0: a INV-2 já estava dentro
$catchUp->sweep(new DateTimeImmutable('2026-04-02'))->eventNames();        // ['collection.started']: a INV-3, travessia nova

// O cron (de hora em hora, toda noite): o instante é o do cron, o limite fatia um acúmulo grande
do {
    $report = $catchUp->sweep(new DateTimeImmutable(), limit: 500);
} while ($report->limitReached());
```

Em produção o store é uma tabela, `domain_event_trigger_state`, no mesmo `resources/sql/domain_events.ansi.sql` (rodar o script de novo num banco da 1.11 só a acrescenta). Dê ao store, à outbox e ao repositório a mesma conexão e o mesmo gerenciador de transação, para o estado de uma travessia e o evento dela serem confirmados juntos:

```php
use Antevemus\ASpecification\DomainEvents\State\PdoTriggerStateStore;

// $pdo do exemplo 17; uma vez, com sua ferramenta de migração: a tabela invoice e resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);
$repository = PdoRepository::forEntity($pdo, Invoice::class, $transactions);
$states = new PdoTriggerStateStore($pdo);         // tabela domain_event_trigger_state
$publisher = new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo));
$invoices = new TriggeringRepository($repository, $overdue, $publisher, $transactions, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, $publisher, $transactions);

$invoices->put(new Invoice('INV-4', new DateTimeImmutable('2026-01-05')));   // já vencida: a escrita publica a entrada
$sweeper->sweep(new DateTimeImmutable())->enteredCount();                   // 0: o estado dela diz "dentro"
count($events->findByEntity(Invoice::class, 'INV-4'));                      // 1
```

- **Como a varredura acha as travessias, e quanto custa.** Para cada gatilho temporal, os estados gravados como "dentro" são lidos em páginas de 500 (pelo id da entidade) e, por página, um `COUNT(*)` de `spec(now) AND id IN (página)` diz se todos continuam dentro; só quando não continuam essas entidades são carregadas (uma removida é saída). Depois, quando o número confirmado dentro é igual ao `COUNT(*)` de `spec(now)`, nada pode entrar e a passada de entradas é pulada; senão as entidades que satisfazem `spec(now)` são lidas em streaming (em SQL com um `PdoRepository`) e as que não estão gravadas como dentro são entradas. A varredura que não acha travessia não carrega entidade: com 10 000 faturas no SQLite, 5 000 vencidas, são 22 comandos, algumas dezenas de milissegundos, e o mesmo pico de memória (uma página) que com 1 000 faturas. O SQL só estreita: cada travessia é confirmada em PHP na entidade carregada, como numa escrita.
- **Uma transação por travessia, compare-and-set no estado.** O estado só é gravado se ainda está na versão que a varredura leu, e o evento só é publicado quando essa gravação venceu; então dois varredores (dois hosts de cron, uma rodada sobreposta), ou um varredor e uma escrita disputando a mesma travessia, a publicam uma vez; a perdedora conta em `conflictCount()`. A chave de idempotência única da outbox é a segunda barreira. Dois varredores juntos são corretos, não mais rápidos: o trabalho não é dividido entre eles, e não há agendador embutido (o cron é seu, como no `deliverDue()` da máquina de estados).
- **O instante.** Um evento achado pela varredura leva o `$now` dela, a não ser que o gatilho declare `crossedAt` (recebe a entidade, o `$now` e o tipo da travessia): o instante dele é usado quando fica depois da última avaliação gravada e não depois do `$now`, o que mantém a chave de idempotência igual em toda varredura e todo processo, enquanto uma reentrada depois de uma saída ganha chave própria. Uma travessia vista por uma escrita leva o instante da escrita.
- **`limit`** limita as travessias tratadas por chamada: repita enquanto `limitReached()`. O `SweepReport` traz as contagens, os eventos, os compare-and-set perdidos, os erros de avaliação (também enviados ao `IDomainEventErrorReporter`) e dois contadores de custo (`entitiesLoaded()`, `statesRead()`).
- **Limites:** um varredor por classe concreta de entidade (os estados são chaveados por ela; uma entidade de outra classe achada no repositório é reportada, nunca gravada); no MySQL com consultas sem buffer, dê ao varredor um repositório numa conexão própria (ele lê estados com o cursor das entidades aberto); os gatilhos comuns do conjunto não são varridos (só mudam em escritas), mas o `baseline()` os grava também.

### 19. Feature Flags Declaradas, com Alvo e Histórico (Módulo 27)

`Antevemus\ASpecification\FeatureFlags` transforma "esta funcionalidade está ligada para este cliente?" numa decisão declarada e auditada. A flag é declarada uma vez, no código: o código dela, os textos que uma tela de configurações mostra (nome, descrição, categoria, público, o que ligar e desligar faz), de onde veio e, opcionalmente, o alvo. O banco guarda só o que muda em tempo de execução: a chave, a vigência, a lista de sujeitos e o histórico (DDL de referência [`resources/sql/feature_flags.ansi.sql`](resources/sql/feature_flags.ansi.sql), SQL portável nas convenções dos outros DDLs do pacote). Toda flag nasce desligada, e toda resposta tem um motivo.

```php
use Antevemus\ASpecification\FeatureFlags\Exceptions\StaleFeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagReason;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Specifications\PredicateSpecification;

final class Agency
{
    public function __construct(public readonly int $unitId, public readonly string $plan = 'basic') {}
}

// $pdo: uma vez, com a sua ferramenta de migração, resources/sql/feature_flags.ansi.sql
$registry = new FeatureFlagRegistry([
    new FeatureFlagDefinition(
        'renewal_notice',
        'Renewal notice to agencies',
        impactOn: 'Agencies start receiving the renewal e-mail.',
        impactOff: 'Agencies stop receiving it.',
        origin: 'task 0033',
        subjectKey: 'unitId',
    ),
]);
$flags = new FeatureFlags($registry, new PdoFeatureFlagStore($pdo));

$seven = new Agency(unitId: 7);
$flags->isEnabled('renewal_notice', $seven);                              // false: toda flag nasce desligada

// O piloto: ligada, mas só para a imobiliária 7 (lista de sujeitos, trocada sem deploy)
$flags->toggle('renewal_notice', true, 'Pilot approved by the board', actor: 42);
$flags->addSubject('renewal_notice', 7, 'Pilot: agency 7 only', actor: 42);

$flags->isEnabled('renewal_notice', $seven);                              // true
$flags->evaluate('renewal_notice', new Agency(unitId: 8))->reason === FeatureFlagReason::NOT_IN_TARGET; // true
$flags->isEnabled('not_declared');                                        // false (UNKNOWN_FLAG), nunca exceção

// A flag é uma especificação: compõe com qualquer outra regra
$notify = $flags->spec('renewal_notice')->and(new PredicateSpecification(fn(Agency $a): bool => $a->plan !== 'trial'));
$notify->isSatisfiedBy($seven);                                           // true

try {
    $flags->toggle('renewal_notice', true, 'Second click on a stale screen');
} catch (StaleFeatureFlagException $e) {
    // já está ligada: toda troca é compare-and-set, e nada foi gravado
}
$flags->history('renewal_notice')[0]->reason;                             // 'Pilot: agency 7 only' (mais recente primeiro)
```

- **Quando a flag está ligada.** A chave está ligada, **e** o instante de referência está dentro da vigência (`valid_from`/`valid_until`, os dois inclusivos, um limite só de data cobrindo o dia inteiro; `withAsOf()` avalia em outro instante), **e** o sujeito está no alvo. O alvo tem duas partes, ambas opcionais e combinadas com E: a lista de sujeitos gravada com a flag (`addSubject()`/`removeSubject()`; lista vazia não restringe; a chave é lida do sujeito pelo `subjectKey` com o acessor compartilhado, e um sujeito texto ou inteiro é a própria chave) e uma especificação declarada no código (`target: Spec::property('plan', Spec::in('pro', 'enterprise'))`). Com alvo, sujeito nulo está fora.
- **Toda resposta tem um motivo.** `evaluate()` devolve um `FeatureFlagEvaluation` (valor, `FeatureFlagReason`, chave do sujeito, erro; `toMetadata()`), e a falha do `spec()` leva os mesmos metadados. Os motivos mapeiam para os do [OpenFeature](https://openfeature.dev), para um provider poder ser escrito sobre o serviço depois: `ENABLED` → `STATIC`, `TARGETING_MATCH` → `TARGETING_MATCH`, `DISABLED` → `DISABLED`, `NOT_IN_TARGET` e `OUTSIDE_VALIDITY` → `DEFAULT`, `UNKNOWN_FLAG` → `ERROR` (`FLAG_NOT_FOUND`), `ERROR` → `ERROR` (`GENERAL`).
- **A leitura nunca lança e nunca falha em silêncio.** Código não declarado é desligado, diga o banco o que disser. Quando o store não pode ser lido (tabela ainda não criada, conexão fora) ou o alvo lança, a flag fica desligada com o motivo `ERROR` (falha fechada) e a exceção vai para o `IFeatureFlagErrorReporter` dado ao serviço, uma vez por carga; `evaluate()->error` e `lastError()` a guardam para uma tela de configurações. Como especificação, essa flag é uma falha comum com o motivo `error`, então o `not()` a vê desligada, e os metadados nunca levam a mensagem da exceção.
- **Trocas.** `toggle()`, `addSubject()` e `removeSubject()` rodam cada uma numa transação com a sua linha de histórico e exigem motivo (espaços colapsados, de 10 a 1 000 caracteres por padrão, `InvalidFeatureFlagReasonException`). Cada uma é compare-and-set: ligar espera desligada, então o segundo clique numa tela desatualizada lança `StaleFeatureFlagException` e não grava nada. Ligar flag não declarada lança `UndeclaredFeatureFlagException` (não teria efeito); desligar continua permitido, para limpar uma linha órfã. Flag declarada sem linha ganha uma, desligada, na primeira troca. O serviço grava o ator e a origem (`FeatureFlags::ORIGIN_UI`, `ORIGIN_MIGRATION`, `ORIGIN_SCRIPT`, ou um texto seu); quem pode trocar uma flag é decisão da sua aplicação.
- **Uma consulta por instância do serviço**: o estado de todas as flags e as listas de sujeitos são lidos na primeira avaliação (flags `LEFT JOIN` sujeitos) e guardados; `refresh()` lê de novo, e uma troca feita pela instância a atualiza. Monte um serviço por requisição.
- **Listagem.** `all()` é o que uma tela de configurações precisa: as flags declaradas na ordem da declaração, depois as que só existem no banco (marcadas como não declaradas), cada uma com os textos (os do código vencem), o estado, os sujeitos e a última troca; `history($code, $limit)` é a trilha, mais recente primeiro.

O `InMemoryFeatureFlagStore` tem o mesmo contrato (a mesma bateria de testes roda contra os dois, e contra PostgreSQL 17, MySQL 8.4 e MariaDB 11.4). Tabelas existentes são lidas onde estão, nas convenções do `PdoRuleCatalog`: nomes de tabela por papel, nomes de coluna por papel (coluna canônica => coluna real; `null` para um recurso que a tabela não tem), o armazenamento booleano de cada papel e uma tabela de histórico compartilhada com outras trilhas de auditoria:

```php
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;

// Chave inteira com exclusão lógica, sem vigência, sem lista de sujeitos, e uma tabela de auditoria
// compartilhada com outras trilhas (chave identity, 'true'/'false' como texto, hora local sem fuso)
$store = new PdoFeatureFlagStore(
    $pdo,
    tables: ['flag' => 'app_feature_flags', 'change' => 'audit_trail', 'subject' => null],
    columns: [
        'flag' => ['valid_from' => null, 'valid_until' => null, 'version' => null, 'deleted_at' => 'deleted_at'],
        'change' => ['change_id' => 'id', 'flag_code' => 'entity_key', 'subject_key' => null, 'old_enabled' => 'old_value', 'new_enabled' => 'new_value'],
    ],
    changeScope: ['entity_type' => 'feature_flag', 'field' => 'enabled'],  // gravado em toda linha, exigido em toda leitura
    booleans: ['flag' => BooleanStorage::Integer, 'change' => ['true', 'false']],
    timestampFormat: 'Y-m-d H:i:s',
    timezone: 'America/Sao_Paulo',
    databaseGeneratedChangeId: true
);
```

Booleano gravado fora dos valores declarados é `FeatureFlagStoreException`, nunca um `false` silencioso; linha com `deleted_at` preenchido não é lida nem trocada. A vigência é gravada por SQL ou migração (ainda não há API para ela), e o DDL traz, comentada, uma trigger do PostgreSQL que torna o histórico só de inserção também no banco. Fora desta versão: alvo por uma regra do catálogo e rollout por porcentagem (roadmap 24b e 24c).

### 20. Comparação de Texto: Maiúsculas e Acentos (Módulo 28)

Os operadores de texto `contains()`, `startsWith()` e `endsWith()` recebem um segundo argumento, a comparação: `bool|TextComparison` desde a v2.1.0. `true` (o padrão) é `TextComparison::CaseSensitive`, `false` é `TextComparison::IgnoreCase`, e `TextComparison::IgnoreCaseAndAccents` também ignora acentos, na fachada `Spec`, nas funções do DSL e na fábrica; `Spec::equalIgnoreCaseAndAccents()` é o par do `equalIgnoreCase()`. Ignorar acentos é uma escolha declarada, nunca efeito colateral da collation da coluna.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

$search = Spec::property('title', Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents));

$search->isSatisfiedBy((object) ['title' => 'Configuração do servidor']); // true
$search->isSatisfiedBy((object) ['title' => 'CONFIGURAÇÃO']);             // true
Spec::contains('configuracao', false)->isSatisfiedBy('Configuração');     // false: IgnoreCase compara acentos
Spec::contains('strasse', TextComparison::IgnoreCaseAndAccents)
    ->isSatisfiedBy('Straße');                                            // false: ß é uma letra, não s + marca
Spec::equalIgnoreCaseAndAccents('sao paulo')->isSatisfiedBy('SÃO PAULO');  // true

Spec::toSql($search, 'mysql')->getSql();
// (`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)
try {
    Spec::toSql($search, 'sqlite');
} catch (NonTranslatableSpecificationException $e) {
    // o SQLite não consegue responder como a memória: recusa, nunca uma resposta diferente
}
```

- **A dobra.** Os dois lados são dobrados do mesmo jeito: decomposição canônica (NFD), remoção das marcas não espaçantes (`\p{Mn}`), composição canônica (NFC) do que sobra e `mb_strtolower()`. `ç`, `ã`, `õ`, `é`, `ê`, `ñ`, `ü` e as maiúsculas viram a letra base. **Limite:** letra que não é letra base mais marca continua ela mesma, como no `CompareOptions.IgnoreCase | IgnoreNonSpace` do .NET: `ß` não é `ss`, `æ` não é `ae`, `œ` não é `oe`, e `ø`, `đ`, `ł` não são `o`, `d`, `l`. O visitor do ALinq usa a mesma dobra.
- **A `ext-intl` é exigida só por essa opção** (a classe `Normalizer`; polyfill não é aceito). Sem ela, montar uma especificação com `IgnoreCaseAndAccents` lança `MissingExtensionException`, que cita a extensão; `CaseSensitive` e `IgnoreCase` não precisam de nada novo, e `TextComparison::isAccentFoldingAvailable()` diz se o runtime a tem.
- **SQL, com a resposta da memória ou nenhuma.** O PostgreSQL dobra os dois lados do `LIKE` com uma expressão igual à dobra em memória (`lower(normalize(regexp_replace(normalize(x, NFD), <marcas>, '', 'g'), NFC))`: sem extensão, `IMMUTABLE`, banco UTF8; medido no PostgreSQL 17 sobre as 50 303 letras e marcas do BMP). `new PostgreSqlDialect('unaccent')` usa a função dessa extensão (que leva `ß` a `ss` e `æ` a `ae`, então responde diferente da memória para essas letras), ou o nome de um invólucro seu. MySQL 8.4 e MariaDB 11.4 comparam `CONVERT(col USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p` (`new MySqlDialect('utf8mb4_uca1400_ai_ci')` para o MariaDB de 10.10 a 11.4.4). SQL Server e Oracle são recusados (medido: as collations sem acento deles igualam `ß` a `ss` e `æ` a `ae`), assim como SQLite, DuckDB, Db2, Informix, Firebird e ANSI, com `NonTranslatableSpecificationException`; um `PdoRepository` nesses bancos recusa a consulta a menos que `withInMemoryFallback()` tenha sido chamado. O `Spec::toCriteria()` também recusa (`NonTranslatableCriteriaException`): o `TFilter` do Adianti concatena o valor que recebe, então a dobra não pode ser aplicada a ele.
- **Limites medidos nos bancos.** No MySQL/MariaDB, `ø`, `đ`, `ł` e `ð` casam com `o`, `d`, `l` e `d`, `й` não casa com `и`, e katakana casa com hiragana (a memória responde o contrário em cada caso); no PostgreSQL, uma coluna com a collation `"C"` só passa para minúsculas as letras ASCII, o limite do `IgnoreCase` desde a v1.14.0.
- **Índices.** A comparação não usa índice comum da coluna, e a biblioteca não cria nenhum. No PostgreSQL o caminho indexável é uma função: declare um invólucro `IMMUTABLE` cujo corpo é `PostgreSqlDialect::builtInAccentFolding('t')` (`CREATE FUNCTION f_fold(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN ...`), dê o nome dele ao dialeto (`new PostgreSqlDialect('f_fold')`, que passa a emitir `f_fold(lower(col)) LIKE f_fold(lower(:p))`) e apoie com um índice de expressão sobre `f_fold(lower(col))` (um `pg_trgm` para o `contains()`). No MySQL/MariaDB a collation é aplicada à coluna convertida, então a condição não é indexável.
- Curingas e `regex()` continuam só com a opção de maiúsculas (roadmap 22b).

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
│   └── Sql/                  # ISqlDialect, IFieldMapper, ISqlWhereClause, ISqlPagination, ISqlAccentInsensitiveLike (v2.1.0)
├── Entities/                  # Classes base abstratas de entidades, UUIDs e geradores de ID aleatórios
├── Specifications/            # Implementações concretas de regras
│   ├── SpecificationSimplifier.php # simplify(): árvore equivalente e menor (v1.9.0)
│   ├── Comparison/           # Equal, GreaterThan, LessThan, RelationalOperator
│   ├── Logical/              # AlwaysTrue, AlwaysFalse, JointDenial (NOR), DefaultValue
│   ├── String/               # Regex, Wildcard, DateString, EnumName; TextComparison, EqualIgnoreCaseAndAccents (v2.1.0)
│   ├── Collection/           # CollectionSpecification, AllEntities, Unique
│   └── Reflection/           # MethodParameterizedSpecification (chamada de método declarativa; acesso a campo é where())
├── Repositories/              # Repositórios concretos e particionamento
│   ├── File/                 # SingleFileRepository, FilePerEntityRepository
│   ├── Pdo/                  # PdoRepository; Mapping/ (Table, Column, Id, Version, EntityMapping, IdStrategy, ColumnType) (Módulo 22, v1.11.0)
│   │                         # v1.13.0: PdoAggregateQuery, InQuerySpecification; Mapping/ CreatedAt, UpdatedAt, SoftDelete, BooleanStorage, ManyToOne, OneToMany, RelationMapping (Módulos 25 e 26)
│   │                         # v1.14.0: JoinTableHop; Mapping/ ManyToMany; Exceptions/ DataIntegrityException (Módulos 25 e 26)
│   └── Serialization/        # JsonEntitySerializer (o serializer nativo do PHP saiu na v2.0.0)
├── Concurrent/                # SemaphoreSynchronizer, FileLockSynchronizer
├── Engine/                    # DynamicSpecificationEngine, RuleEngineVerdict, RuleCompilationWarning, PdoRuleCatalog, Builders
│   ├── Exceptions/           # RuleEngineException, MissingRuleHandlerException, LegacyVocabularyException (v2.0.0)
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
│   ├── Exceptions/           # NonTranslatableLinqException (v1.10.0)
│   ├── ALinqBridge.php       # Ponte fluente entre repositórios e coleções ALinq
│   └── ALinqSpecificationVisitor.php # Compilador de AST para predicados funcionais LINQ
├── Factory/                   # SpecificationFactory unificada (~140 métodos)
│   └── Traits/               # 8 Traits modulares (Type, Comparison, Logical, Special, String, Date, Collection, Wrapper)
├── Results/                   # SpecificationResult, SpecificationFailure, FailureSeverity, IOutcome
├── StateMachine/              # Máquina de estados dirigida por especificações (Módulos 17 e 18): builder, definição, validador, núcleo puro
│   ├── Persistence/          # Portas, MachinePersistence, stores InMemory/ e Pdo/ (resources/sql/state_machine.ansi.sql)
│   └── Runner/               # MachineRunner, EffectDispatcher, EffectHandlerRegistry, migrações
├── UseCase/                   # IUseCase, UseCaseRunner, UseCaseResult, UseCaseStatus, middlewares (Módulo 19)
├── Persistence/               # ITransactionManager, PdoTransactionManager: a porta de transação comum ao repositório, às outboxes e aos stores da máquina (v1.11.0); IQueryLogger, InMemoryQueryLogger (v1.13.0)
├── DomainEvents/              # Eventos de domínio a partir de especificações (Módulo 23, v1.11.0) e pela passagem do tempo (Módulo 24, v1.12.0)
│   ├── Outbox/               # IDomainEventOutbox, outboxes InMemory e Pdo (resources/sql/domain_events.ansi.sql)
│   ├── State/                # ITriggerStateStore, stores InMemory e Pdo do último estado conhecido de cada gatilho (v1.12.0)
│   ├── SpecificationTrigger.php # Especificação ligada a um evento de entrada e um de saída (timed(): montada a partir do "agora"); TriggerSet, TriggerEvaluator
│   ├── TriggeringRepository.php # Decorador de IRepository que publica as travessias de cada escrita
│   ├── TriggerSweeper.php    # sweep() para as travessias causadas só pelo tempo, baseline(); SweepReport (v1.12.0)
│   ├── DomainEventDispatcher.php # Entrega depois do commit; DomainEventHandlerRegistry
│   └── MachineEventBridge.php # Evento de domínio enviado a um MachineRunner
├── Resolution/                # Resolução concorrente de dados (Módulo 21, v1.10.0)
│   ├── Exceptions/           # DataResolutionException, DataFetchException, DataSourceNotFoundException
│   ├── ExternalDataSpecification.php # Spec::external(): a folha que declara o dado externo
│   ├── DataResolver.php      # resolve()/evaluate(); ResolutionPolicy concurrent()/tiered()/sequential()
│   ├── HttpDataSource.php    # Modelo de URL com parâmetros codificados, buscado por curl_multi
│   └── CallableDataSource.php # Qualquer callable síncrono; DataSourceRegistry, IDataCache, InMemoryDataCache
├── FeatureFlags/              # Feature flags declaradas (Módulo 27, v2.1.0): FeatureFlags, FeatureFlagRegistry, FeatureFlagDefinition, FeatureEnabledSpecification, FeatureFlagReason
│   ├── Exceptions/           # FeatureFlagException, FeatureFlagStoreException, StaleFeatureFlagException, UndeclaredFeatureFlagException...
│   └── PdoFeatureFlagStore.php # IFeatureFlagStore sobre as tabelas de resources/sql/feature_flags.ansi.sql (ou existentes); InMemoryFeatureFlagStore
├── Helpers/                   # PropertyAccessor (fachada depreciada do antevemus/adeep-property-access, v2.0.0), StopWatch, InstrumentationUtils
├── DSL/                       # Funções globais de apoio para syntax fluente
└── Spec.php                   # Facade principal da biblioteca
```

---

## 🧪 Qualidade de Código & Testes

A biblioteca possui ampla cobertura de testes unitários e de integração, garantindo zero regressões:

```bash
php tests/run_all.php
```

Relatório abaixo como o runner imprime; o tempo depende da máquina. O Módulo 22 também imprime o seu benchmark (10 000 linhas no SQLite) e, sem as variáveis `ASPEC_PDO_*`, que as rodadas em PostgreSQL e MySQL foram puladas, o Módulo 24 as suas varreduras sobre 1 000 e 10 000 linhas, o Módulo 25 as suas medições de lote, de `updateWhere()` e as paridades de nulos com subconsultas e com `Char`, o Módulo 26 os benchmarks de carga ansiosa (1 000 pais × 10 filhos, 1 000 donos × 5 alvos) e o pico de memória de `iterate()` com relações em 2 000 e 10 000 linhas, o Módulo 27 que a rodada em PostgreSQL foi pulada sem `ASPEC_PDO_PGSQL_DSN`, e o Módulo 28 que a `ext-intl` está ausente, então só a recusa foi verificada (com a `ext-intl` ele roda a bateria sem acento: cerca de 650 asserções no PHP 8.4); essas linhas foram omitidas aqui:

```text
====================================================================
 ANTEVEMUS ASPECIFICATION - MASTER TEST RUNNER & REGRESSION WATCH
====================================================================

• [SUITE] Módulo 1: Especificações e Álgebra Booleana... ✅ PASS (285 asserções)
• [SUITE] Módulo 2: Entidades e Identificadores... ✅ PASS (59 asserções)
• [SUITE] Módulo 3: Repositórios em Memória e Base... ✅ PASS (90 asserções)
• [SUITE] Módulo 4: Arquitetura de Particionamento DAG... ✅ PASS (94 asserções)
• [SUITE] Módulo 5: Persistência em Arquivo e Decorator Híbrido... ✅ PASS (214 asserções)
• [SUITE] Módulo 6: Utilitários de Concorrência e RW-Lock... ✅ PASS (97 asserções)
• [SUITE] Módulo 7: Predicados, Fábricas, Helpers e Visitor... ✅ PASS (61 asserções)
• [SUITE] Módulo 8: Notification Pattern & SpecificationResult... ✅ PASS (151 asserções)
• [SUITE] Módulo 9: Facade Spec, Chaining Fluente & DSL... ✅ PASS (204 asserções)
• [SUITE] Módulo 10: Paridade Java, Telemetria & Remainder... ✅ PASS (65 asserções)
• [SUITE] Módulo 11: Dynamic Rule Engine & Requisitos Documentais... ✅ PASS (859 asserções)
• [SUITE] Módulo 12: SQL Query Visitor & Multi-SGBD Dialects... ✅ PASS (516 asserções)
• [SUITE] Módulo 13: TCriteria Builder & Adianti Database Bridge... ✅ PASS (209 asserções)
• [SUITE] Módulo 14: ALinq Synergy & Coleções Fluentes LINQ... ✅ PASS (2083 asserções)
• [SUITE] Módulo 15: Attributes Declarativos PHP 8.4 (#[AssertSpec])... ✅ PASS (221 asserções)
• [SUITE] Módulo 16: Catálogo Relacional de Regras (PdoRuleCatalog)... ✅ PASS (1252 asserções)
• [SUITE] Módulo 17: Máquina de Estados Dirigida por Especificações... ✅ PASS (618 asserções)
• [SUITE] Módulo 18: Runner e Persistência da Máquina de Estados... ✅ PASS (700 asserções)
• [SUITE] Módulo 19: Caso de Uso e Resultado Comum (UseCaseResult)... ✅ PASS (408 asserções)
• [SUITE] Módulo 20: Simplificação de Especificações (simplify)... ✅ PASS (116 asserções)
• [SUITE] Módulo 21: Resolução Concorrente de Dados (DataResolver)... ✅ PASS (864 asserções)
• [SUITE] Módulo 22: Repositório sobre PDO (PdoRepository)... ✅ PASS (571 asserções)
• [SUITE] Módulo 23: Eventos de Domínio por Especificação... ✅ PASS (331 asserções)
• [SUITE] Módulo 24: Eventos de Domínio pela Passagem do Tempo... ✅ PASS (310 asserções)
• [SUITE] Módulo 25: Complementos do PdoRepository (log, tipos, ciclo de vida, lote, consultas em conjunto)... ✅ PASS (608 asserções)
• [SUITE] Módulo 26: Relacionamentos do PdoRepository (ManyToOne, OneToMany, ManyToMany, carga ansiosa, composição)... ✅ PASS (355 asserções)
• [SUITE] Módulo 27: Feature Flags Declaradas, com Alvo e Histórico... ✅ PASS (535 asserções)
• [SUITE] Módulo 28: Comparação de Texto sem Acento (TextComparison::IgnoreCaseAndAccents)... ✅ PASS (64 asserções)

====================================================================
 RESULTADO FINAL: 28/28 SUÍTES APROVADAS (100% PASS)
 TOTAL DE ASSERÇÕES: 11940 | TEMPO: ~21s | REGRESSÕES: 0
====================================================================
```

- **Suíte de paridade Domian:** `tests/Parity/` transcreve os testes do Domian (Java) para a álgebra de especificações, o DAG de partições, os synchronizers, entidades e utilitários (219 testes PHPUnit, 2.106 asserções; `vendor/bin/phpunit` roda 307 testes e 70.096 asserções no total, um deles pulado sem a `ext-intl`). A auditoria por trás dela está em `docs/PARIDADE-DOMIAN-2026-10-09.md`. Desde a v2.1.0, sete das suas asserções afirmam que limites inteiros não são fechados (as folhas do Domian são tipadas `Integer`, estas aceitam qualquer número), cada uma com nota.
- **Bancada de solidez da álgebra (v2.1.0):** `tests/PhpUnit/AlgebraSoundnessTest.php` confere toda promessa da álgebra contra a avaliação: `equals()` (mesmas respostas e mesmas falhas anotadas), `isGeneralizationOf()`/`isSpecialCaseOf()`, `isDisjointWith()`, `isTautology()`/`isContradiction()`, `simplify()`, a deduplicação de `and()`/`or()` e a busca de partição. O pool de mais de 700 especificações cobre toda classe concreta de especificação de `src/` (comparação, `in`, nulos, texto com os três casos de `TextComparison`, datas, objetos-valor, coleções e entidades, chamadas de método, os invólucros de `a()`, subclasses de usuário com predicado próprio, variantes anotadas) e compostos gerados com semente fixa, testados par a par contra cerca de 80 candidatos de fronteira (o mesmo valor em instâncias distintas, o mesmo instante em outros fusos e classes, `'1'`/`1`/`true`, `null`, frações, acentos, entidades de tipos irmãos). Uma guarda reprova a suíte quando uma classe concreta de especificação fica fora do pool. A parte fixa roda na suíte padrão em cerca de 10 segundos; `ASPEC_SOUNDNESS_EXTENDED=1` a amplia para cerca de 3 milhões de pares e `ASPEC_SOUNDNESS_DUMP=<arquivo>` grava as violações como linhas JSON. O `AlgebraSoundnessCasesTest` guarda uma reprodução de cada defeito que ela achou, e o `DateSqlParityTest` compara memória e SQL para as folhas de data.
- **Mapeamento de APIs Públicas:** 1.200+ métodos documentados via PHPDoc corporativo padronizado.
- **Rastreabilidade Java (Domian):** paridade conceitual e arquitetural com o framework original (API pública e nomes de classe seguem o Domian; ver a tabela de procedência em [NOTICE.md](NOTICE.md)).
- **Decomposição Modular com Traits:** `SpecificationFactory` modularizada em 8 Traits especializados por domínio de regras.
- **ALinq Synergy & Coleções Fluentes:** Sinergia nativa com `Antevemus.AlinqCollection`, com compilador `ALinqSpecificationVisitor`, o acessor de propriedades compartilhado com dot notation (`antevemus/adeep-property-access`) e integração em `InMemoryRepository`.
- **Multi-SGBD SQL Translator:** 15 drivers (10 dialetos SQL) homologados com quoting de identificadores e prepared statements. O `PdoRepository` roda a sua bateria de contrato em SQLite na suíte e, por `tests/Fixtures/pdo_repository/run_portability.sh`, em PostgreSQL 17 e MySQL 8.4 em contêineres descartáveis; o mesmo script roda o store de feature flags (Módulo 27), a paridade de datas e a bateria sem acento (Módulo 28, em contêineres PHP 8.2 e 8.4 com `ext-intl`) contra PostgreSQL 17, MySQL 8.4 e MariaDB 11.4.
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
13. **Aposentadoria do serializer PHP nativo, etapa 2: remoção e envelope só JSON** (metadados por entidade gravados) (entregue na v2.0.0)
14. **Acessor de propriedades compartilhado com o ALinq** (`antevemus/adeep-property-access`) (entregue na v2.0.0)
15. **Resolução Concorrente de Dados para Especificações Dependentes de I/O** (núcleo entregue na v1.10.0)
15b. **Adaptador Fibers/Revolt para Fontes Que Não São HTTP** (pacote satélite `antevemus/aspecification-async`)
16. **Cache Distribuído de Especificações** (PSR-6 / PSR-16 / Redis)
17. **Compiladores AST para GraphQL & OpenAPI 3.1**
18. **Doctrine ORM & Laravel Eloquent Query Visitors**
19. **Disparo de Eventos de Domínio por Especificação** (entregue na v1.11.0)
19b. **Gatilhos de Eventos de Domínio por Tempo** (varredura agendada para travessias sem escrita) (entregue na v1.12.0)
20. **Sintetizador de Especificações Assistido por IA**
21. **Repositório sobre PDO** (o `HibernateRepository` do Domian) (entregue na v1.11.0)
21b. **Continuações do `PdoRepository`** (colunas de ciclo de vida e exclusão lógica, ganchos, log de SQL, inserção em lote, atualização em lote por especificação, projeções e agregações, subconsultas, relacionamentos `ManyToOne`/`OneToMany` com carga ansiosa em lote) (entregue na v1.13.0)
21c. **Continuações do `PdoRepository` II** (`ManyToMany`, declarar como carregada uma coleção montada à mão, `iterate()` com relações sobre MySQL sem buffer, booleanos `CHAR(1)`) (entregue na v1.14.0)
22. **Comparação de Texto Sem Acento** (opção explícita que ignora maiúsculas e acentos, com a mesma resposta em memória e no SQL) (entregue na v2.1.0)
22b. **Curingas e Expressões Regulares Sem Acento**
23. **Repositório Híbrido com Gravação Adiada** (o modo `INMEMORY_AND_FILE` do Domian: memória agora, disco no `persist()`)
24. **Feature Flags Declaradas, com Alvo e Histórico** (entregue na v2.1.0)
24b. **Alvo de Feature Flag por Regra do Catálogo**
24c. **Rollout de Feature Flag por Porcentagem**
24d. **API para a Vigência de uma Feature Flag**
25. **Modo Inteiro Opcional para as Folhas Relacionais** (`asInteger()`, `Spec::int()`: limites inteiros fechados onde for correto)
26. **Auditoria de Solidez da Álgebra de Especificações** (bancada por força bruta sobre toda classe de especificação; a álgebra nunca promete mais do que a avaliação entrega) (entregue na v2.1.0)

26b. **Decisões pendentes da bancada de solidez** (um valor-padrão tipado, `isBlankString()`/`defaultNumber()`/`defaultValueOfType()`, é "um valor daquele tipo, e o seu padrão" e recusa `null`, que só o `defaultValue()` sem tipo aceita; `NAN` é recusado pelas folhas ordenadas como candidato e como limite, de modo que `not(lessThan(v))` e `greaterThanOrEqualTo(v)` concordam em todo candidato que respondem) (Entregue na v2.2.0)

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
