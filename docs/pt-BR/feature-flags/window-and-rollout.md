# Vigência e Rollout por Porcentagem de uma Flag

### 19.1. Vigência e Rollout por Porcentagem de uma Flag (Módulo 30)

Desde a v2.3.0 o serviço grava as duas coisas que antes só SQL ou migração escreviam: a vigência da flag e um rollout por porcentagem. As duas passam pelo caminho auditado do `toggle()` (motivo, uma transação com a sua linha de histórico) com compare-and-set pela versão da linha, então uma tela que leu uma versão antiga não sobrescreve uma troca mais nova. `Agency` é a classe do exemplo 19.

```php
use Antevemus\ASpecification\FeatureFlags\FeatureFlagChange;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;

// $pdo: as tabelas de resources/sql/feature_flags.ansi.sql (2.3.0), ou as da 2.1.0 depois da migração do cabeçalho dele
$registry = new FeatureFlagRegistry([
    new FeatureFlagDefinition('renewal_notice', 'Renewal notice to agencies', subjectKey: 'unitId'),
]);
$store = new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3);
$flags = new FeatureFlags($registry, $store);

$flags->toggle('renewal_notice', true, 'Launch approved by the board', actor: 42);

// Só de 1º de novembro a 31 de dezembro (os dois inclusivos)
$flags->setValidity(
    'renewal_notice',
    new DateTimeImmutable('2026-11-01T00:00:00Z'),
    new DateTimeImmutable('2026-12-31T23:59:59Z'),
    'Year-end campaign',
    actor: 42,
);

// Primeiro um quarto das agências: um bucket estável por agência (de 0 a 9999)
$flags->setRollout('renewal_notice', 25, 'Gradual launch, first quarter', actor: 42);

$agency = new Agency(unitId: 7);
$november = $flags->withAsOf(new DateTimeImmutable('2026-11-15T12:00:00Z'));
$evaluation = $november->evaluate('renewal_notice', $agency);
$evaluation->reason;    // FeatureFlagReason::OUTSIDE_ROLLOUT (TARGETING_MATCH quando o bucket é menor que 2500)
$evaluation->bucket;    // 8912: o mesmo número em toda requisição, processo e máquina
$flags->withAsOf(new DateTimeImmutable('2027-01-01T00:00:00Z'))->evaluate('renewal_notice', $agency)->reason; // OUTSIDE_VALIDITY

$flags->setRollout('renewal_notice', 100, 'Every agency now', actor: 42);
$flags->withAsOf(new DateTimeImmutable('2026-11-15T12:00:00Z'))->isEnabled('renewal_notice', $agency); // true

array_map(
    fn(FeatureFlagChange $change): string => $change->kind->value . ($change->detail !== null ? ': ' . $change->detail : ''),
    $flags->history('renewal_notice'),
);
// ['rollout: 25% → 100%', 'rollout: none → 25%', 'validity: 2026-11-01T00:00:00+00:00 → 2026-12-31T23:59:59+00:00', 'toggle']
```

- **Vigência.** `setValidity($code, $from, $until, $reason, $actor, $origin, $expectedVersion)`: os dois limites inclusivos, `null` um lado aberto, os dois `null` limpam a vigência; `from > until` é recusado com `InvalidArgumentException` antes de qualquer gravação. A chave não é tocada: a vigência não liga a flag, ela limita quando o "ligada" vale (`OUTSIDE_VALIDITY` fora dela).
- **Rollout.** `setRollout($code, $percent, ...)`, de 0 a 100, `null` para nenhum (todo sujeito). O sujeito entra quando `FeatureFlags::bucket($code, $subjectKey) < $percent * 100`. O bucket são os 8 primeiros dígitos hexadecimais de `xxh3($code . "\0" . $subjectKey)` módulo `FeatureFlags::ROLLOUT_BUCKETS` (10 000): o mesmo em toda requisição, processo e máquina, independente entre flags, e parte do contrato (mudá-lo seria MAJOR); subir a porcentagem nunca tira um sujeito. Medido sobre 10 000 sujeitos, 1 %, 10 %, 25 %, 50 % e 90 % ficam a ±1 % do pedido.
- **Onde o rollout entra na avaliação.** Por último: chave, vigência, lista de sujeitos, alvo em código, depois o rollout. Com rollout, sujeito sem chave é `NOT_IN_TARGET` (inclusive com 100 %); quem está dentro é `TARGETING_MATCH` e quem está fora `OUTSIDE_ROLLOUT` (OpenFeature `DEFAULT`). O `toMetadata()`, e portanto a falha do `spec()`, leva `bucket` e `rollout_percent`, para um painel dizer por que uma agência ficou de fora.
- **Concorrência.** Cada troca é compare-and-set pela `version` (o `toggle()` também a incrementa, então uma vigência lida antes de um toggle fica obsoleta depois dele); passe `expectedVersion:` com a versão que a sua tela mostrou para recusar a gravação por cima da troca de outra pessoa. Gravar o que já está lá lança `StaleFeatureFlagException` e não grava nada, como um segundo `toggle()`. As duas trocas exigem flag declarada.
- **Histórico e listagem.** `FeatureFlagChange::$kind` (`FeatureFlagChangeKind`: `Toggle`, `Subject`, `Validity`, `Rollout`) e `$detail` (`2026-11-01T00:00:00+00:00 → open`, `cleared`, `none → 25%`, `25% → 100%`); o `all()` traz `validFrom`, `validUntil` e `rolloutPercent` de cada flag.
- **As colunas novas são opt-in.** O DDL da 2.3.0 acrescenta `feature_flag.rollout_percent` e `feature_flag_change.change_kind`/`detail`, e o `PdoFeatureFlagStore` só as lê e grava quando mandado, com `columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3`, para uma tabela criada pelo DDL da 2.1.0 continuar funcionando sem mudança depois da atualização. Instalação nova passa a constante; uma existente roda uma vez a migração aditiva comentada no fim do cabeçalho do DDL (três `ADD COLUMN` e um `UPDATE`, para SQLite, PostgreSQL, MySQL 8.0.16+ e MariaDB) e depois a passa. Sem elas não há rollout (`setRollout()` é recusado citando `rollout_percent`), a vigência funciona (as colunas dela existem desde a 2.1.0) e o histórico fica sem tipo nem detalhe. Um store com as colunas de vigência mapeadas para `null` recusa o `setValidity()` com uma mensagem que as cita.
- **Quebra para um store seu:** `IFeatureFlagStore` ganha `supportsValidity()`, `compareAndSetValidity()`, `supportsRollout()` e `compareAndSetRollout()`; `InMemoryFeatureFlagStore` e `PdoFeatureFlagStore` os implementam.
