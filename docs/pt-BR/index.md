# Antevemus ASpecification

A documentação oficial da biblioteca, uma página por tema. As mesmas páginas existem em inglês, em `docs/en`; os exemplos numerados (`### 17.1.` e assim por diante) mantêm o número que tinham no README, e todo bloco PHP destas páginas é conferido pela suíte de testes.

> **Framework Corporativo do Padrão Specification para PHP 8.2+** (PHP 8.4 só para a integração opcional com o ALinq)  
> Reimplementação PHP independente e não oficial, com evolução moderna, do renomado framework Java [Domian](https://domian.sourceforge.net/index.html), fundamentado no paper seminal [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) de Eric Evans e Martin Fowler. Enriquecido com Fluent Chaining em linguagem natural, Notification Pattern com diagnóstico rico de falhas, Dynamic Rule Engine para catálogos relacionais, SQL Query Visitor multi-SGBD (15 drivers, 10 dialetos SQL), TCriteria Builder para Adianti Framework, repositórios particionados em Grafo Acíclico Dirigido (DAG), persistência híbrida, primitivas avançadas de concorrência, máquina de estados dirigida por especificações, casos de uso com resultado comum aos dois, resolução concorrente dos dados externos de que as regras de I/O dependem, repositório sobre PDO cujas consultas são especificações compiladas para SQL (com colunas de ciclo de vida, operações em lote e sobre conjuntos e relacionamentos carregados em lote), eventos de domínio emitidos quando uma entidade passa a satisfazer ou deixa de satisfazer uma especificação, numa escrita ou só pela passagem do tempo, feature flags declaradas com alvo e histórico, comparação de texto que ignora maiúsculas e acentos com a mesma resposta em memória e no SQL, e especificações gravadas como JSON canônico e lidas de volta sem `unserialize()` nem nome de classe vindo da entrada. Toda promessa da sua álgebra é conferida contra a avaliação por uma bancada de solidez.

## Mapa da Documentação

### Primeiros passos

- [Instalação e primeiro uso](getting-started/index.md): Requisitos, instalação e uma primeira regra avaliada e compilada para SQL.

### Conceitos

- [Origem e fundamentação](concepts/origins.md): O paper de Evans & Fowler, a origem no Domian (Java) e as referências.
- [Principais recursos](concepts/core-features.md): Todos os recursos da biblioteca numa lista, com o módulo e o exemplo de cada um.
- [Nossos diferenciais](concepts/what-sets-us-apart.md): Comparação com o Domian e com as bibliotecas PHP de specification típicas.

### Especificações

- [Fluent chaining](specifications/fluent-chaining.md) (exemplo 1): Regras de negócio compostas como frases em linguagem natural.
- [Notification pattern](specifications/notification-pattern.md) (exemplo 2): `evaluate()`: todas as falhas com código, mensagem e propriedade, sem exceções.
- [Satisfação parcial](specifications/partial-satisfaction.md) (exemplo 3): `remainderUnsatisfiedBy()`: só as cláusulas que o candidato ainda não satisfaz.
- [Tautologia e simplificação](specifications/tautology-and-simplification.md) (exemplo 12): `isTautology()`, `isContradiction()` e `simplify()`.
- [Chamadas de método](specifications/method-calls.md) (exemplo 13): `MethodParameterizedSpecification`: uma regra que chama um método do candidato.
- [Comparação de texto](specifications/text-comparison.md) (exemplos 20 e 20.1): Comparação ignorando maiúsculas e acentos, curingas e expressões regulares, em memória e no SQL.
- [Serialização](specifications/serialization.md) (exemplo 21): `SpecificationCodec`: especificações como JSON canônico, lidas de volta sem `unserialize()`.

### Repositórios

- [DAG de partições](repositories/partitioned-dag.md) (exemplo 4): Repositórios particionados por especificações, com poda antecipada de ramos disjuntos.
- [Persistência e cache](repositories/persistence-and-cache.md) (exemplo 5): Repositórios em arquivo e a camada híbrida L1 (RAM) + L2 (disco).
- [Repositório PDO](repositories/pdo.md) (exemplo 17): `PdoRepository`: consultas são especificações compiladas para SQL.
- [Ciclo de vida](repositories/pdo-lifecycle.md) (exemplo 17.1): Carimbos de tempo, exclusão lógica, ganchos e o log de SQL.
- [Escrita em lote](repositories/pdo-batch.md) (exemplo 17.2): Escrita em lote e operações sobre conjuntos.
- [Relacionamentos](repositories/pdo-relationships.md) (exemplo 17.3): Referências, composições e carga ansiosa em lote, nunca N+1.

### Motor de regras

- [Regras dinâmicas](rule-engine/dynamic-rules.md) (exemplo 7): Regras compiladas em tempo de execução a partir de catálogos relacionais, triagem por severidade e a matriz documental.
- [Um consumidor real](rule-engine/real-consumer.md) (exemplo 22): Tipos de regra só consulta, a regra mais específica de um tipo e rótulos do alvo.

### SQL

- [SQL Query Visitor](sql/query-visitor.md) (exemplo 8): Cláusulas `WHERE` parametrizadas para 15 drivers e 10 dialetos SQL.
- [TCriteria Builder](sql/tcriteria.md) (exemplo 9): Especificações compiladas para `TCriteria` e `TFilter` do Adianti.
- [TCriteria: acentos e subqueries](sql/tcriteria-accents-and-subqueries.md) (exemplo 22.1): `withAccentFolding()`, `inSubquery()` e `existsIn()`.

### Integrações

- [ALinq](integrations/alinq.md) (exemplo 10): A ponte com o ALinq, o compilador de predicados LINQ e o streaming lazy.
- [Attributes](integrations/attributes.md) (exemplo 11): `#[AssertSpec]` e `#[ValidateRule]` em classes PHP 8.
- [Adianti como host](integrations/adianti-host.md) (exemplo 22.3): Telas do Adianti como host: a transação da tela e um `UseCaseResult` no `TMessage`/`TToast`.

### Máquina de estados

- [Máquina de estados](state-machine/index.md) (exemplo 14): Guards são especificações: regiões paralelas, definição em JSON, o runner e prazos persistidos.
- [Prazos em tempo útil](state-machine/business-time.md) (exemplo 14.11): dias úteis, horário de trabalho e feriados; uma pausa que não gasta o fim de semana.
- [Casos de uso](state-machine/use-cases.md) (exemplo 15): `IUseCase`, `UseCaseRunner` e o resultado comum à máquina.
- [Um consumidor real](state-machine/real-consumer.md) (exemplo 23): códigos legados dos estados, definições versionadas no banco com rascunho e publicação, e tenants compartilhando uma máquina.

### Resolução de dados

- [Resolução concorrente](resolution/concurrent.md) (exemplo 16): `Spec::external()` e o `DataResolver`: dados externos buscados em paralelo.
- [Fontes assíncronas](resolution/async.md) (exemplo 22.2): `AsyncDataSource`: envia uma vez, consulta depois.
- [Fontes adiadas](resolution/deferred.md) (exemplo 16.1): `IDeferredDataSource` e `IStageTransport`: o gancho pelo qual um pacote satélite busca juntas as fontes não HTTP de um estágio.

### Eventos de domínio

- [A partir de especificações](events/from-specifications.md) (exemplo 18): Eventos de domínio quando uma entidade passa a satisfazer ou deixa de satisfazer uma especificação.
- [Pela passagem do tempo](events/time-based.md) (exemplo 18.1): `SpecificationTrigger::timed()` e o `TriggerSweeper`.

### Feature flags

- [Feature flags](feature-flags/index.md) (exemplo 19): Flags declaradas no código e ligadas no banco, com alvo e histórico.
- [Vigência e rollout](feature-flags/window-and-rollout.md) (exemplo 19.1): A vigência e o rollout por porcentagem de uma flag.

### Benchmarking

- [Benchmarking](benchmarking/index.md) (exemplo 6): `StopWatch` e `InstrumentationUtils`.

### Qualidade

- [Testes](quality/tests.md): O relatório do runner, a suíte de paridade com o Domian e a bancada de solidez.
- [Garantias](quality/guarantees.md): As cinco verificações sem as quais nenhuma versão sai.

### Projeto

- [Estrutura de diretórios](project/directory-structure.md): A árvore de `src/`, pasta a pasta.
- [Roadmap](project/roadmap.md): Marcos entregues e planejados; o plano completo está no ROADMAP.md.
- [Atribuição e licença](project/attribution-and-license.md): Atribuição ao Domian, conformidade com a Apache 2.0 e a licença MIT.
- [Autores](project/authors.md): Mantenedor e contato.

## Onde Está Cada Coisa

| O quê | Onde |
| :--- | :--- |
| Visão geral, badges e o primeiro uso | [README.pt-BR.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/README.pt-BR.md) |
| O que mudou em cada versão | [CHANGELOG.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/CHANGELOG.md) |
| Marcos entregues e planejados | [ROADMAP.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/ROADMAP.md) |
| Vindo da 1.x | [UPGRADE-2.0.pt-BR.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/UPGRADE-2.0.pt-BR.md) |
| Procedência e licenças de terceiros | [NOTICE.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/NOTICE.md), [THIRD_PARTY_NOTICES.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/THIRD_PARTY_NOTICES.md) |
