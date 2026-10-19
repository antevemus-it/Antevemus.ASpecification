# 🛡️ Garantias Permanentes

Desde a v2.1.0 a qualidade da álgebra de especificações é garantida por verificações automáticas, nenhuma dependente de alguém lembrar de rodá-la. Nenhuma versão é publicada com qualquer uma das cinco vermelha: a tag de uma release espera o workflow `Tests` verde no commit da release.

| Garantia | O que verifica | Onde roda | Comando local |
| :--- | :--- | :--- | :--- |
| **Bancada de solidez (parte fixa)** | Para todo par do pool e todo candidato, `equals()`, generalização e caso especial, disjunção, tautologia e contradição, `simplify()`, a deduplicação de `and()`/`or()` e as partições nunca prometem mais do que a avaliação entrega (`tests/PhpUnit/AlgebraSoundnessTest.php`, `AlgebraSoundnessCasesTest.php`) | Workflow `Tests`, em todo push e pull request: PHP 8.2, 8.3 e 8.4 com `intl`, PHP 8.4 sem `intl` e PHP 8.4 com ALinq | `vendor/bin/phpunit` |
| **Bancada de solidez (rodada ampliada)** | Mais árvores, mais fundas, e todos os pares de compostos (cerca de 10 minutos) | Workflow `Quality`, semanal e manual | `ASPEC_SOUNDNESS_EXTENDED=1 vendor/bin/phpunit tests/PhpUnit/AlgebraSoundnessTest.php tests/PhpUnit/AlgebraSoundnessCasesTest.php` (`ASPEC_SOUNDNESS_DUMP=<arquivo>` grava cada violação como uma linha JSON) |
| **Guarda de cobertura do pool** | Toda classe concreta de especificação de `src/` está no pool da bancada; classe nova fora dele reprova a suíte | Junto com a bancada | `vendor/bin/phpunit` |
| **Paridade memória × SQL** | O repositório PDO, o store de feature flags, a comparação de texto sem acento, as folhas de data e hora, a bancada de solidez, e a precedência de regras, as subqueries e os acentos do `toCriteria()` dão no PostgreSQL 17, no MySQL 8.4 e no MariaDB 11.4 a resposta que dão em memória | Workflow `Tests`, job `SQL parity` (PHP 8.2 e 8.4 com `intl`, os três bancos como contêineres de serviço) | `bash tests/Fixtures/pdo_repository/run_portability.sh` (os três bancos em contêineres descartáveis) |
| **Teste de mutação** | O Infection planta defeitos nas especificações, na serialização, no motor de regras, na ponte TCriteria e nas fontes assíncronas (o escopo exato está no `infection.json5`: troca `===` por `==`, remove um ramo, inverte uma condição) e mede quantos a suíte inteira pega; pontuação abaixo do mínimo reprova | Workflow `Quality`, semanal e manual | `vendor/bin/infection --threads=max --only-covering-test-cases`, com um driver de cobertura (pcov) |

## O Mínimo do Infection

O mínimo vive no `infection.json5` (`minMsi` e `minCoveredMsi`) e só sobe: quando a pontuação medida sobe, o mínimo acompanha; nunca se baixa o mínimo para uma rodada passar (mutante sobrevivente se mata com teste, ou se declara equivalente no `infection.json5` com o motivo).

| Versão | Escopo | Mutantes | MSI medido | Mínimo |
| :--- | :--- | ---: | ---: | ---: |
| 2.1.0 | Especificações e as duas bases abstratas | 2 558 | 85,81% | 84 |
| 2.2.0 | Idem | 2 709 | 86,82% | 85 |
| 2.3.0 | Idem | 2 763 | 87,12% | 86 |
| 2.4.0 | Idem + serialização | 3 323 | 89,38% | 88 |
| 2.5.0 | Idem + motor de regras, ponte TCriteria e fontes assíncronas | 4 481 | 90,54% | 89 |

## O Que Cada Mudança Traz

- **Classe nova de especificação** traz, no mesmo commit, a semântica de avaliação no docblock da classe (como compara objetos, datas e nulos) e o seu lugar no pool da bancada, com candidatos de fronteira para o tipo de valor que guarda; sem isso a guarda do pool reprova a suíte.
- **Cada violação** achada pela bancada, pelas rodadas de paridade ou por mutante sobrevivente relevante vira teste de reprodução permanente, e a correção entra antes da release.

Os testes por trás de cada garantia, e os números da versão atual, estão em [Testes](tests.md).
