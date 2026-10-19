# 🎯 Nossos Diferenciais

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
