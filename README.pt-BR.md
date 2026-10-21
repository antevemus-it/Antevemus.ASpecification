# Antevemus ASpecification

<p align="left">
  <a href="README.md">🇺🇸 English</a> &nbsp;|&nbsp; <strong>🇧🇷 Português (Brasil)</strong>
</p>

[![CI](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Latest Version](https://img.shields.io/badge/Release-v2.6.1-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v2.6.1)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-37%2F37%20Suites%20Pass%20(43925%20Assertions)%20%2B%20Domian%20Parity%20230-success)](docs/pt-BR/quality/tests.md)
[![Architecture](https://img.shields.io/badge/Architecture-DDD%20%7C%20Evans%20%26%20Fowler%20Specification-orange)](http://www.martinfowler.com/apsupp/spec.pdf)
[![Upstream: Domian](https://img.shields.io/badge/Origin-Domian%20(Apache%202.0)-brightgreen)](https://domian.sourceforge.net/index.html)
[![Synergy: ALinq](https://img.shields.io/badge/Synergy-Antevemus.AlinqCollection-purple)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **Framework Corporativo do Padrão Specification para PHP 8.2+** (PHP 8.4 só para a integração opcional com o ALinq)  
> Reimplementação PHP independente e não oficial, com evolução moderna, do renomado framework Java [Domian](https://domian.sourceforge.net/index.html), fundamentado no paper seminal [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) de Eric Evans e Martin Fowler. Enriquecido com Fluent Chaining em linguagem natural, Notification Pattern com diagnóstico rico de falhas, Dynamic Rule Engine para catálogos relacionais, SQL Query Visitor multi-SGBD (15 drivers, 10 dialetos SQL), TCriteria Builder para Adianti Framework, repositórios particionados em Grafo Acíclico Dirigido (DAG), persistência híbrida, primitivas avançadas de concorrência, máquina de estados dirigida por especificações, casos de uso com resultado comum aos dois, resolução concorrente dos dados externos de que as regras de I/O dependem, repositório sobre PDO cujas consultas são especificações compiladas para SQL (com colunas de ciclo de vida, operações em lote e sobre conjuntos e relacionamentos carregados em lote), eventos de domínio emitidos quando uma entidade passa a satisfazer ou deixa de satisfazer uma especificação, numa escrita ou só pela passagem do tempo, feature flags declaradas com alvo e histórico, comparação de texto que ignora maiúsculas e acentos com a mesma resposta em memória e no SQL, e especificações gravadas como JSON canônico e lidas de volta sem `unserialize()` nem nome de classe vindo da entrada. Toda promessa da sua álgebra é conferida contra a avaliação por uma bancada de solidez.

A documentação vive em [`docs/pt-BR`](docs/pt-BR/index.md) (português) e [`docs/en`](docs/en/index.md) (inglês), uma página por tema, no formato do Docs Kit (um `docs-nav.json` por idioma). Os exemplos mantêm o número que tinham neste README (o exemplo 17.1 continua `### 17.1.`, agora na sua página), e todo bloco PHP das páginas é conferido pela suíte de testes.

---

## ⚡ Primeiro Uso

Uma regra é um objeto: componha, pergunte se um candidato a satisfaz, receba todas as falhas com código e mensagem sem exceção e compile a mesma regra para SQL (o mesmo bloco abre os [Primeiros Passos](docs/pt-BR/getting-started/index.md)):

```php
use Antevemus\ASpecification\Spec;

// Duas regras de negócio, cada uma com a sua mensagem e o seu código
$adult = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('Customer must be of legal age.')
    ->withCode('CLI_001');
$active = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Only active accounts can receive a credit line.')
    ->withCode('CLI_002');
$creditRule = $adult->and($active);

$customer = (object) ['age' => 17, 'status' => 'ACTIVE'];   // qualquer objeto: uma entidade, um DTO, um stdClass

$creditRule->isSatisfiedBy($customer);                      // false

$result = $creditRule->evaluate($customer);                 // sem exceção: um resultado com todas as falhas
count($result->failures);                                   // 1
$result->failures[0]->code;                                 // 'CLI_001'
$result->failures[0]->message;                              // 'Customer must be of legal age.'

// A mesma regra como cláusula WHERE parametrizada (15 drivers, 10 dialetos SQL)
$where = Spec::toSql($creditRule, 'pgsql');
$where->getSql();         // '(("age" IS NOT NULL AND "age" >= :p1) AND ("status" IS NOT NULL AND "status" = :p2))'
$where->getParameters();  // [':p1' => 18, ':p2' => 'ACTIVE']
```

---

## 📋 Requisitos

- **PHP**: `^8.2` (testado no PHP 8.2 e 8.4). O PHP 8.4 só é exigido pela integração opcional com o ALinq (`Antevemus.AlinqCollection`, Módulo 14), detectada em tempo de execução.
- **Extensões PHP**:
  - `ext-json` (para serialização JSON; obrigatória)
  - `ext-mbstring` (obrigatória desde a v1.5.0: especificações de string case-insensitive com Unicode, `mb_strtolower`)
  - `ext-intl` *(opcional: `TextComparison::IgnoreCaseAndAccents`, a comparação de texto que também ignora acentos, exemplos 20 e 20.1; sem ela essa opção é recusada com `MissingExtensionException` e todo o resto funciona)*
  - `ext-sysvsem` *(opcional: `SysVSemaphoreSynchronizer`, o lock leitores/escritor compartilhado entre processos por semáforos SysV IPC, Linux/Unix)*
  - `ext-curl` *(opcional: `HttpDataSource`, a busca HTTP concorrente do `DataResolver`, Módulo 21; sem ela uma fonte HTTP é recusada ao ser registrada e o `CallableDataSource` continua funcionando)*
  - `ext-pdo` *(opcional, com o driver do seu banco: `PdoRepository` (Módulos 22, 25 e 26), os stores PDO da máquina de estados, o `PdoRuleCatalog` e a outbox PDO e o store PDO de estado dos gatilhos de eventos de domínio (Módulos 23 e 24))*
- **Dependência**: uma, do mesmo autor, sem dependências próprias: [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess) `^1.0` (desde a v2.0.0), o acessor de propriedades compartilhado com o ALinq, para que as duas bibliotecas leiam o mesmo valor do mesmo candidato (exemplo 10.1). O Composer a instala.
- **Pacote opcional**: `antevemus/alinq-collection` `^2.2.0` para `ALinqBridge` (retornos tipados `IALinqCollection`/`IALinqLazyCollection`), `ALinqSpecificationVisitor` e streaming lazy O(1) sobre `IRepository::iterate()` (exige PHP 8.4; o ALinq só lê propriedade não pública marcada com `#[Specifiable]`, a mesma regra deste pacote, e a 2.0 dele resolve propriedades pelo mesmo acessor compartilhado).

> **Vindo da 1.x?** Leia antes o [UPGRADE-2.0.pt-BR.md](UPGRADE-2.0.pt-BR.md): arquivos `.bin` do serializer nativo e catálogos de regras com valores em português são convertidos na 1.14.x, antes da atualização.

---

## 🚀 Instalação

Instale pelo Composer: `composer require antevemus/aspecification`. Requisitos, instalação e o primeiro uso, em detalhe: [Primeiros Passos](docs/pt-BR/getting-started/index.md).

---

## 📚 Documentação

Uma linha por página, agrupadas como na navegação; o número entre parênteses é o exemplo do README antigo que a página abriga.

**Primeiros passos**

- [Instalação e primeiro uso](docs/pt-BR/getting-started/index.md) · [EN](docs/en/getting-started/index.md): Requisitos, instalação e uma primeira regra avaliada e compilada para SQL.

**Conceitos**

- [Origem e fundamentação](docs/pt-BR/concepts/origins.md) · [EN](docs/en/concepts/origins.md): O paper de Evans & Fowler, a origem no Domian (Java) e as referências.
- [Principais recursos](docs/pt-BR/concepts/core-features.md) · [EN](docs/en/concepts/core-features.md): Todos os recursos da biblioteca numa lista, com o módulo e o exemplo de cada um.
- [Nossos diferenciais](docs/pt-BR/concepts/what-sets-us-apart.md) · [EN](docs/en/concepts/what-sets-us-apart.md): Comparação com o Domian e com as bibliotecas PHP de specification típicas.

**Especificações**

- [Fluent chaining](docs/pt-BR/specifications/fluent-chaining.md) (exemplos 1 e 1.1) · [EN](docs/en/specifications/fluent-chaining.md): Regras de negócio compostas como frases em linguagem natural; folhas inteiras (`asInteger()`, `Spec::int()`).
- [Notification pattern](docs/pt-BR/specifications/notification-pattern.md) (exemplo 2) · [EN](docs/en/specifications/notification-pattern.md): `evaluate()`: todas as falhas com código, mensagem e propriedade, sem exceções.
- [Satisfação parcial](docs/pt-BR/specifications/partial-satisfaction.md) (exemplo 3) · [EN](docs/en/specifications/partial-satisfaction.md): `remainderUnsatisfiedBy()`: só as cláusulas que o candidato ainda não satisfaz.
- [Tautologia e simplificação](docs/pt-BR/specifications/tautology-and-simplification.md) (exemplos 12 e 12.1) · [EN](docs/en/specifications/tautology-and-simplification.md): `isTautology()`, `isContradiction()` e `simplify()`.
- [Chamadas de método](docs/pt-BR/specifications/method-calls.md) (exemplo 13) · [EN](docs/en/specifications/method-calls.md): `MethodParameterizedSpecification`: uma regra que chama um método do candidato.
- [Comparação de texto](docs/pt-BR/specifications/text-comparison.md) (exemplos 20 e 20.1) · [EN](docs/en/specifications/text-comparison.md): Comparação ignorando maiúsculas e acentos, curingas e expressões regulares, em memória e no SQL.
- [Serialização](docs/pt-BR/specifications/serialization.md) (exemplo 21) · [EN](docs/en/specifications/serialization.md): `SpecificationCodec`: especificações como JSON canônico, lidas de volta sem `unserialize()`.

**Repositórios**

- [DAG de partições](docs/pt-BR/repositories/partitioned-dag.md) (exemplo 4) · [EN](docs/en/repositories/partitioned-dag.md): Repositórios particionados por especificações, com poda antecipada de ramos disjuntos.
- [Persistência e cache](docs/pt-BR/repositories/persistence-and-cache.md) (exemplos 5 e 5.1) · [EN](docs/en/repositories/persistence-and-cache.md): Repositórios em arquivo, a camada híbrida L1 (RAM) + L2 (disco) e o seu modo de gravação adiada.
- [Repositório PDO](docs/pt-BR/repositories/pdo.md) (exemplo 17) · [EN](docs/en/repositories/pdo.md): `PdoRepository`: consultas são especificações compiladas para SQL.
- [Ciclo de vida](docs/pt-BR/repositories/pdo-lifecycle.md) (exemplo 17.1) · [EN](docs/en/repositories/pdo-lifecycle.md): Carimbos de tempo, exclusão lógica, ganchos e o log de SQL.
- [Escrita em lote](docs/pt-BR/repositories/pdo-batch.md) (exemplo 17.2) · [EN](docs/en/repositories/pdo-batch.md): Escrita em lote e operações sobre conjuntos.
- [Relacionamentos](docs/pt-BR/repositories/pdo-relationships.md) (exemplo 17.3) · [EN](docs/en/repositories/pdo-relationships.md): Referências, composições e carga ansiosa em lote, nunca N+1.

**Motor de regras**

- [Regras dinâmicas](docs/pt-BR/rule-engine/dynamic-rules.md) (exemplo 7) · [EN](docs/en/rule-engine/dynamic-rules.md): Regras compiladas em tempo de execução a partir de catálogos relacionais, triagem por severidade e a matriz documental.
- [Um consumidor real](docs/pt-BR/rule-engine/real-consumer.md) (exemplo 22) · [EN](docs/en/rule-engine/real-consumer.md): Tipos de regra só consulta, a regra mais específica de um tipo e rótulos do alvo.

**SQL**

- [SQL Query Visitor](docs/pt-BR/sql/query-visitor.md) (exemplo 8) · [EN](docs/en/sql/query-visitor.md): Cláusulas `WHERE` parametrizadas para 15 drivers e 10 dialetos SQL.
- [TCriteria Builder](docs/pt-BR/sql/tcriteria.md) (exemplo 9) · [EN](docs/en/sql/tcriteria.md): Especificações compiladas para `TCriteria` e `TFilter` do Adianti.
- [TCriteria: acentos e subqueries](docs/pt-BR/sql/tcriteria-accents-and-subqueries.md) (exemplo 22.1) · [EN](docs/en/sql/tcriteria-accents-and-subqueries.md): `withAccentFolding()`, `inSubquery()` e `existsIn()`.

**Integrações**

- [ALinq](docs/pt-BR/integrations/alinq.md) (exemplo 10) · [EN](docs/en/integrations/alinq.md): A ponte com o ALinq, o compilador de predicados LINQ e o streaming lazy.
- [Attributes](docs/pt-BR/integrations/attributes.md) (exemplo 11) · [EN](docs/en/integrations/attributes.md): `#[AssertSpec]` e `#[ValidateRule]` em classes PHP 8.
- [Adianti como host](docs/pt-BR/integrations/adianti-host.md) (exemplo 22.3) · [EN](docs/en/integrations/adianti-host.md): Telas do Adianti como host: a transação da tela e um `UseCaseResult` no `TMessage`/`TToast`.

**Máquina de estados**

- [Máquina de estados](docs/pt-BR/state-machine/index.md) (exemplo 14) · [EN](docs/en/state-machine/index.md): Guards são especificações: regiões paralelas, definição em JSON, o runner e prazos persistidos.
- [Prazos em tempo útil](docs/pt-BR/state-machine/business-time.md) (exemplo 14.11) · [EN](docs/en/state-machine/business-time.md): dias úteis, horário de trabalho e feriados; uma pausa que não gasta o fim de semana.
- [Casos de uso](docs/pt-BR/state-machine/use-cases.md) (exemplo 15) · [EN](docs/en/state-machine/use-cases.md): `IUseCase`, `UseCaseRunner` e o resultado comum à máquina.
- [Um consumidor real](docs/pt-BR/state-machine/real-consumer.md) (exemplo 23) · [EN](docs/en/state-machine/real-consumer.md): códigos legados dos estados, definições versionadas no banco com rascunho e publicação, e tenants compartilhando uma máquina.

**Resolução de dados**

- [Resolução concorrente](docs/pt-BR/resolution/concurrent.md) (exemplo 16) · [EN](docs/en/resolution/concurrent.md): `Spec::external()` e o `DataResolver`: dados externos buscados em paralelo.
- [Fontes assíncronas](docs/pt-BR/resolution/async.md) (exemplo 22.2) · [EN](docs/en/resolution/async.md): `AsyncDataSource`: envia uma vez, consulta depois.
- [Fontes adiadas](docs/pt-BR/resolution/deferred.md) (exemplo 16.1) · [EN](docs/en/resolution/deferred.md): `IDeferredDataSource` e `IStageTransport`: o gancho pelo qual um pacote satélite busca juntas as fontes não HTTP de um estágio.

**Eventos de domínio**

- [A partir de especificações](docs/pt-BR/events/from-specifications.md) (exemplo 18) · [EN](docs/en/events/from-specifications.md): Eventos de domínio quando uma entidade passa a satisfazer ou deixa de satisfazer uma especificação.
- [Pela passagem do tempo](docs/pt-BR/events/time-based.md) (exemplo 18.1) · [EN](docs/en/events/time-based.md): `SpecificationTrigger::timed()` e o `TriggerSweeper`.

**Feature flags**

- [Feature flags](docs/pt-BR/feature-flags/index.md) (exemplo 19) · [EN](docs/en/feature-flags/index.md): Flags declaradas no código e ligadas no banco, com alvo e histórico.
- [Vigência e rollout](docs/pt-BR/feature-flags/window-and-rollout.md) (exemplo 19.1) · [EN](docs/en/feature-flags/window-and-rollout.md): A vigência e o rollout por porcentagem de uma flag.

**Cache**

- [Cache](docs/pt-BR/caching/index.md) (exemplos 23 e 23.1) · [EN](docs/en/caching/index.md): O catálogo de regras, as feature flags e os clusters de roteamento de um repositório particionado lidos por um cache PSR-16 compartilhado.

**Benchmarking**

- [Benchmarking](docs/pt-BR/benchmarking/index.md) (exemplo 6) · [EN](docs/en/benchmarking/index.md): `StopWatch` e `InstrumentationUtils`.

**Qualidade**

- [Testes](docs/pt-BR/quality/tests.md) · [EN](docs/en/quality/tests.md): O relatório do runner, a suíte de paridade com o Domian e a bancada de solidez.
- [Garantias](docs/pt-BR/quality/guarantees.md) · [EN](docs/en/quality/guarantees.md): As cinco verificações sem as quais nenhuma versão sai.

**Projeto**

- [Estrutura de diretórios](docs/pt-BR/project/directory-structure.md) · [EN](docs/en/project/directory-structure.md): A árvore de `src/`, pasta a pasta.
- [Roadmap](docs/pt-BR/project/roadmap.md) · [EN](docs/en/project/roadmap.md): Marcos entregues e planejados; o plano completo está no ROADMAP.md.
- [Atribuição e licença](docs/pt-BR/project/attribution-and-license.md) · [EN](docs/en/project/attribution-and-license.md): Atribuição ao Domian, conformidade com a Apache 2.0 e a licença MIT.
- [Autores](docs/pt-BR/project/authors.md) · [EN](docs/en/project/authors.md): Mantenedor e contato.

---

## 🧪 Qualidade

- **Runner:** `php tests/run_all.php`, 37/37 suítes, 43 925 asserções, zero regressões (o relatório: [Testes](docs/pt-BR/quality/tests.md)).
- **PHPUnit:** `vendor/bin/phpunit`, 624 testes e 107.097 asserções, a suíte de paridade com o Domian (230 testes) inclusa.
- **Bancada de solidez:** toda promessa da álgebra conferida contra a avaliação sobre um pool de mais de 700 especificações, com uma guarda que reprova quando falta uma classe de especificação nele.
- **Paridade SQL:** as mesmas respostas em memória e no PostgreSQL 17, no MySQL 8.4 e no MariaDB 11.4, no CI.
- **Teste de mutação:** Infection com um mínimo que só sobe, hoje 89 (90,54% de MSI medido na 2.5.0).
- **As cinco garantias permanentes** e como rodá-las: [Garantias](docs/pt-BR/quality/guarantees.md).

---

## 🗺️ Roadmap & Próximos Passos

Marcos entregues e planejados, em resumo: [Roadmap](docs/pt-BR/project/roadmap.md). O plano completo: 👉 **[ROADMAP.md](ROADMAP.md)**.

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

## 📄 Licença

Distribuído sob a licença **MIT**. Consulte os arquivos [LICENSE](LICENSE), [NOTICE.md](NOTICE.md) e [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) para obter mais informações.

---

## 👨‍💻 Autor & Manutenção

**Heliton Junior (CTO)**  
- E-mail: [contato@antevemus.com.br](mailto:contato@antevemus.com.br)  
- Website: [antevemus.com.br](https://antevemus.com.br)  
- Organização: **Antevemus Soluções Inovadoras em TI Ltda.**
