# 🗺️ Roadmap & Próximos Passos

O **Antevemus ASpecification** continua em evolução contínua com marcos de curto, médio e longo prazo. Para a lista completa e detalhada de iniciativas, consulte o documento oficial:

👉 **[Consulte o ROADMAP.md completo](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/ROADMAP.md)**

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
15b. **Adaptador Fibers/Revolt para Fontes Que Não São HTTP** (gancho do core `IDeferredDataSource`/`IStageTransport` entregue na v2.6.0; pacote satélite `antevemus/aspecification-revolt` entregue como v1.0.0 em 2026-10-20)
16. **Cache Distribuído de Especificações** (PSR-6 / PSR-16 / Redis) (entregue: 16a na v2.4.0, 16b na v2.8.0)
16a. **Serialização de Especificações** (JSON canônico com nomes curtos e estáveis, `SpecificationCodec`, nunca uma classe vinda da entrada) (entregue na v2.4.0)
16b. **Adaptadores de Cache Distribuído** (PSR-16: o catálogo de regras, as feature flags e os clusters de roteamento de um repositório particionado, `psr/simple-cache` opcional) (entregue na v2.8.0)
17. **Compiladores AST para GraphQL & OpenAPI 3.1**
18. **Doctrine ORM & Laravel Eloquent Query Visitors**
19. **Disparo de Eventos de Domínio por Especificação** (entregue na v1.11.0)
19b. **Gatilhos de Eventos de Domínio por Tempo** (varredura agendada para travessias sem escrita) (entregue na v1.12.0)
20. **Sintetizador de Especificações Assistido por IA**
21. **Repositório sobre PDO** (o `HibernateRepository` do Domian) (entregue na v1.11.0)
21b. **Continuações do `PdoRepository`** (colunas de ciclo de vida e exclusão lógica, ganchos, log de SQL, inserção em lote, atualização em lote por especificação, projeções e agregações, subconsultas, relacionamentos `ManyToOne`/`OneToMany` com carga ansiosa em lote) (entregue na v1.13.0)
21c. **Continuações do `PdoRepository` II** (`ManyToMany`, declarar como carregada uma coleção montada à mão, `iterate()` com relações sobre MySQL sem buffer, booleanos `CHAR(1)`) (entregue na v1.14.0)
22. **Comparação de Texto Sem Acento** (opção explícita que ignora maiúsculas e acentos, com a mesma resposta em memória e no SQL) (entregue na v2.1.0)
22b. **Curingas e Expressões Regulares Sem Acento** (`wildcard()` e `regex()` com `TextComparison`; traduzidos onde o banco responde como a memória, recusados nos demais) (entregue na v2.3.0)
23. **Repositório Híbrido com Gravação Adiada** (o modo `INMEMORY_AND_FILE` do Domian: memória agora, disco no `persist()`) (Entregue na v2.8.0)
24. **Feature Flags Declaradas, com Alvo e Histórico** (entregue na v2.1.0)
24b. **Alvo de Feature Flag por Regra do Catálogo** (`targetRuleCode`, `IRuleCatalog::findRuleByCode()`, corpo da regra em `spec_json`) (entregue na v2.4.0)
24c. **Rollout de Feature Flag por Porcentagem** (bucket estável por sujeito a partir do `xxh3` do código e da chave, gravado pelo caminho auditado) (entregue na v2.3.0)
24d. **API para a Vigência de uma Feature Flag** (`setValidity()`, compare-and-set pela versão da linha, linha de histórico com tipo) (entregue na v2.3.0)
25. **Modo Inteiro Opcional para as Folhas Relacionais** (`asInteger()`, `Spec::int()`: limites inteiros fechados onde for correto)
26. **Auditoria de Solidez da Álgebra de Especificações** (bancada por força bruta sobre toda classe de especificação; a álgebra nunca promete mais do que a avaliação entrega) (entregue na v2.1.0)

26b. **Decisões pendentes da bancada de solidez** (um valor-padrão tipado, `isBlankString()`/`defaultNumber()`/`defaultValueOfType()`, é "um valor daquele tipo, e o seu padrão" e recusa `null`, que só o `defaultValue()` sem tipo aceita; `NAN` é recusado pelas folhas ordenadas como candidato e como limite, de modo que `not(lessThan(v))` e `greaterThanOrEqualTo(v)` concordam em todo candidato que respondem) (Entregue na v2.2.0)

27. **Motor de Regras para um Consumidor Real** (tipos de regra só consulta, a regra mais específica de um tipo, rótulo humano do alvo, Adianti como host) (Entregue na v2.5.0)
28. **TCriteria Sem Acento e Com Subquery** (`withAccentFolding()`, `inSubquery()`, `existsIn()`, também no `toSql()`) (Entregue na v2.5.0)
29. **Fontes de Dados Assíncronas** (`AsyncDataSource`: envia uma vez, consulta depois, dado pendente nunca aprovado nem reprovado) (Entregue na v2.5.0)
30. **Máquina de Estados para um Consumidor Real** (códigos legados dos estados, definições versionadas em PDO com rascunho e publicação, guard `spec`, `migrateAll()`, tenants, prazos em tempo útil) (Entregue na v2.7.0)
