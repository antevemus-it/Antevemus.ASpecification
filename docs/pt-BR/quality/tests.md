# 🧪 Qualidade de Código & Testes

A biblioteca possui ampla cobertura de testes unitários e de integração, garantindo zero regressões:

```bash
php tests/run_all.php
```

Relatório abaixo como o runner imprime; o tempo depende da máquina. O Módulo 22 também imprime o seu benchmark (10 000 linhas no SQLite) e, sem as variáveis `ASPEC_PDO_*`, que as rodadas em PostgreSQL e MySQL foram puladas, o Módulo 24 as suas varreduras sobre 1 000 e 10 000 linhas, o Módulo 25 as suas medições de lote, de `updateWhere()` e as paridades de nulos com subconsultas e com `Char`, o Módulo 26 os benchmarks de carga ansiosa (1 000 pais × 10 filhos, 1 000 donos × 5 alvos) e o pico de memória de `iterate()` com relações em 2 000 e 10 000 linhas, o Módulo 13 a sua rodada de 400 árvores sobre 320 linhas contra os filtros que o `TRepository` acrescenta, o Módulo 27 que as rodadas em PostgreSQL, MySQL e MariaDB foram puladas sem as variáveis `ASPEC_PDO_*`, os Módulos 28 e 29 que a `ext-intl` está ausente, então só a recusa foi verificada (com a `ext-intl` eles rodam as baterias sem acento: o Módulo 28 cerca de 650 asserções no PHP 8.4, o Módulo 29 cerca de 1 500 com os três bancos), o Módulo 30 a distribuição do seu rollout sobre 10 000 sujeitos e que as rodadas nos bancos foram puladas, o Módulo 31 que as rodadas em PostgreSQL, MySQL e MariaDB foram puladas, o Módulo 36 que a `ext-intl` falta (a bateria sem acento do `toCriteria()` roda nos contêineres com ela), a sua bateria de subqueries no SQLite e que as rodadas nos bancos foram puladas, e o Módulo 40 as suas propriedades (200 máquinas geradas, a ida e volta das definições, o isolamento por tenant), o replay de aceitação da máquina da cotação e que as rodadas nos bancos e o replay de uma amostra real foram pulados, e o Módulo 45 a sua propriedade `sendMany()` == `send()` em memória e no SQLite (120 sequências aleatórias em cada) e que as rodadas em PostgreSQL, MySQL e MariaDB foram puladas; essas linhas foram omitidas aqui:

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
• [SUITE] Módulo 12: SQL Query Visitor & Multi-SGBD Dialects... ✅ PASS (536 asserções)
• [SUITE] Módulo 13: TCriteria Builder & Adianti Database Bridge... ✅ PASS (576 asserções)
• [SUITE] Módulo 14: ALinq Synergy & Coleções Fluentes LINQ... ✅ PASS (2403 asserções)
• [SUITE] Módulo 15: Attributes Declarativos PHP 8.4 (#[AssertSpec])... ✅ PASS (221 asserções)
• [SUITE] Módulo 16: Catálogo Relacional de Regras (PdoRuleCatalog)... ✅ PASS (1252 asserções)
• [SUITE] Módulo 17: Máquina de Estados Dirigida por Especificações... ✅ PASS (618 asserções)
• [SUITE] Módulo 18: Runner e Persistência da Máquina de Estados... ✅ PASS (700 asserções)
• [SUITE] Módulo 19: Caso de Uso e Resultado Comum (UseCaseResult)... ✅ PASS (408 asserções)
• [SUITE] Módulo 20: Simplificação de Especificações (simplify)... ✅ PASS (116 asserções)
• [SUITE] Módulo 21: Resolução Concorrente de Dados (DataResolver)... ✅ PASS (1004 asserções)
• [SUITE] Módulo 22: Repositório sobre PDO (PdoRepository)... ✅ PASS (571 asserções)
• [SUITE] Módulo 23: Eventos de Domínio por Especificação... ✅ PASS (331 asserções)
• [SUITE] Módulo 24: Eventos de Domínio pela Passagem do Tempo... ✅ PASS (310 asserções)
• [SUITE] Módulo 25: Complementos do PdoRepository (log, tipos, ciclo de vida, lote, consultas em conjunto)... ✅ PASS (608 asserções)
• [SUITE] Módulo 26: Relacionamentos do PdoRepository (ManyToOne, OneToMany, ManyToMany, carga ansiosa, composição)... ✅ PASS (355 asserções)
• [SUITE] Módulo 27: Feature Flags Declaradas, com Alvo e Histórico... ✅ PASS (538 asserções)
• [SUITE] Módulo 28: Comparação de Texto sem Acento (TextComparison::IgnoreCaseAndAccents)... ✅ PASS (64 asserções)
• [SUITE] Módulo 29: Wildcard e Regex sem Acento (wildcard()/regex() com TextComparison)... ✅ PASS (585 asserções)
• [SUITE] Módulo 30: Feature Flags II (janela de validade pelo serviço e rollout percentual)... ✅ PASS (441 asserções)
• [SUITE] Módulo 31: Serialização de Especificações e Alvo de Flag por Regra do Catálogo... ✅ PASS (178 asserções)
• [SUITE] Módulo 36: Motor de Regras, TCriteria e Dados Assíncronos para um Consumidor Real (tipos só consulta, precedência, rótulos, sem acento, subquery, pendente)... ✅ PASS (4353 asserções)
• [SUITE] Módulo 40: Máquina de Estados para um Consumidor Real (códigos legados, definição versionada em PDO, tenant)... ✅ PASS (14335 asserções)
• [SUITE] Módulo 41: Prazos em Tempo Útil (calendários de dias úteis e de horário, feriados, pausa e paridade com o relógio de SLA)... ✅ PASS (6061 asserções)
• [SUITE] Módulo 42: Repositório Híbrido com Gravação Adiada (MemoryAsyncFile, persist(), registro do repositório)... ✅ PASS (691 asserções)
• [SUITE] Módulo 43: Cache Distribuído PSR-16 (catálogo de regras, feature flags e clusters de roteamento do DAG)... ✅ PASS (59 asserções)
• [SUITE] Módulo 44: Folhas Inteiras (asInteger(), Spec::int(), limites inteiros fechados entre folhas inteiras, tradução inalterada)... ✅ PASS (4504 asserções)
• [SUITE] Módulo 45: Adoção da Máquina de Estados num Sistema em Produção (importação, contexto do efeito, painel de ações, motivo principal, colunas largas, sendMany())... ✅ PASS (1071 asserções)
====================================================================
 RESULTADO FINAL: 38/38 SUÍTES APROVADAS (100% PASS)
 TOTAL DE ASSERÇÕES: 45068 | TEMPO: ~35s | REGRESSÕES: 0
====================================================================
```

- **Suíte de paridade Domian:** `tests/Parity/` transcreve os testes do Domian (Java) para a álgebra de especificações, o DAG de partições, os synchronizers, entidades e utilitários, e desde a v2.8.0 os testes de repositório persistente sobre o modo `INMEMORY_AND_FILE` do Domian, rodando contra o híbrido com gravação adiada (230 testes PHPUnit, 2.366 asserções; `vendor/bin/phpunit` roda 679 testes e 109.381 asserções no total, dois deles pulados sem a `ext-intl`). Desde a v2.1.0, sete das suas asserções afirmam que limites inteiros não são fechados (as folhas do Domian são tipadas `Integer`, estas aceitam qualquer número), cada uma com nota.
- **Bancada de solidez da álgebra (v2.1.0):** `tests/PhpUnit/AlgebraSoundnessTest.php` confere toda promessa da álgebra contra a avaliação: `equals()` (mesmas respostas e mesmas falhas anotadas), `isGeneralizationOf()`/`isSpecialCaseOf()`, `isDisjointWith()`, `isTautology()`/`isContradiction()`, `simplify()`, a deduplicação de `and()`/`or()` e a busca de partição. O pool de mais de 700 especificações cobre toda classe concreta de especificação de `src/` (comparação, `in`, nulos, texto com os três casos de `TextComparison`, datas, objetos-valor, coleções e entidades, chamadas de método, os invólucros de `a()`, subclasses de usuário com predicado próprio, variantes anotadas) e compostos gerados com semente fixa, testados par a par contra cerca de 80 candidatos de fronteira (o mesmo valor em instâncias distintas, o mesmo instante em outros fusos e classes, `'1'`/`1`/`true`, `null`, frações, acentos, entidades de tipos irmãos). Uma guarda reprova a suíte quando uma classe concreta de especificação fica fora do pool. A parte fixa roda na suíte padrão em cerca de 10 segundos; `ASPEC_SOUNDNESS_EXTENDED=1` a amplia para cerca de 3 milhões de pares e `ASPEC_SOUNDNESS_DUMP=<arquivo>` grava as violações como linhas JSON. O `AlgebraSoundnessCasesTest` guarda uma reprodução de cada defeito que ela achou, e o `DateSqlParityTest` compara memória e SQL para as folhas de data. Desde a v2.4.0 a bancada também confere a serialização (Módulo 31): toda árvore serializável do pool (505 de 746 sem a `ext-intl`, 518 de 756 com ela, na 2.5.0) volta `equals()` nos dois sentidos, com texto canônico e as mesmas respostas, e traduz para o mesmo SQL (ANSI, PostgreSQL, MySQL) e o mesmo `TCriteria`; toda outra árvore é recusada por motivo do contrato (callbacks, dados externos, flags, guards da máquina, subqueries, folhas de usuário sem o contrato). Desde a v2.5.0 o pool tem alvos rotulados e as subqueries de `inSubquery()`/`existsIn()` (erro de avaliação em todo candidato), e rotular qualquer especificação do pool mantém as respostas, as falhas (reportadas contra o rótulo), o SQL e o `TCriteria` dela. Desde a v2.9.0 o pool tem folhas inteiras dos seis operadores (`asInteger()`, `Spec::int()`), árvores que as misturam com as folhas numéricas (de uma semente própria, então o pool da 2.5.0 não muda) e os candidatos `10`, `10.0`, `10.5`, `'10'`, `11` e `12`; uma guarda reprova quando falta inteira uma classe que `asInteger()` aceita. O `SpecificationCodecTest` cobre o formato de todo nó, os valores, os limites e cerca de 46 documentos malformados.
- **Mapeamento de APIs Públicas:** 1.200+ métodos documentados via PHPDoc corporativo padronizado.
- **Rastreabilidade Java (Domian):** paridade conceitual e arquitetural com o framework original (API pública e nomes de classe seguem o Domian; ver a tabela de procedência em [NOTICE.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/NOTICE.md)).
- **Decomposição Modular com Traits:** `SpecificationFactory` modularizada em 8 Traits especializados por domínio de regras; desde a v2.11.0 o `PdoRepository` decomposto em 11 traits e no colaborador `PdoDriverLimits`, com a mesma API pública e os mesmos corpos de método (um retrato por Reflection e um gate na AST, rodados antes da release).
- **ALinq Synergy & Coleções Fluentes:** Sinergia nativa com `Antevemus.AlinqCollection`, com compilador `ALinqSpecificationVisitor`, o acessor de propriedades compartilhado com dot notation (`antevemus/adeep-property-access`) e integração em `InMemoryRepository`.
- **Multi-SGBD SQL Translator:** 15 drivers (10 dialetos SQL) homologados com quoting de identificadores e prepared statements. O `PdoRepository` roda a sua bateria de contrato em SQLite na suíte e, por `tests/Fixtures/pdo_repository/run_portability.sh`, em PostgreSQL 17 e MySQL 8.4 em contêineres descartáveis; o mesmo script roda o store de feature flags (Módulo 27), a paridade de datas, as baterias sem acento (Módulos 28 e 29, em contêineres PHP 8.2 e 8.4 com `ext-intl`) as feature flags II com a migração da 2.1.0 (Módulo 30) os corpos de regra serializados com a migração da 2.3.0 (Módulo 31) e, desde a v2.5.0, a precedência de regras, a bateria de subqueries e a bateria sem acento do `toCriteria()` executadas pelo Adianti real 8.1 e 8.7 (Módulo 36) e, desde a v2.7.0, as definições versionadas com rascunhos, os tenants e a migração do DDL da 2.6 da máquina de estados (Módulo 40) contra PostgreSQL 17, MySQL 8.4 e MariaDB 11.4.
- **Adianti Database Bridge:** Conversão completa para `TCriteria`/`TFilter` com De Morgan e precedência.
