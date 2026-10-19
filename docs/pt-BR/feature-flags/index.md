# Feature Flags Declaradas, com Alvo e Histórico

### 19. Feature Flags Declaradas, com Alvo e Histórico (Módulo 27)

`Antevemus\ASpecification\FeatureFlags` transforma "esta funcionalidade está ligada para este cliente?" numa decisão declarada e auditada. A flag é declarada uma vez, no código: o código dela, os textos que uma tela de configurações mostra (nome, descrição, categoria, público, o que ligar e desligar faz), de onde veio e, opcionalmente, o alvo. O banco guarda só o que muda em tempo de execução: a chave, a vigência, a lista de sujeitos e o histórico (DDL de referência [`resources/sql/feature_flags.ansi.sql`](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/resources/sql/feature_flags.ansi.sql), SQL portável nas convenções dos outros DDLs do pacote). Toda flag nasce desligada, e toda resposta tem um motivo.

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

Booleano gravado fora dos valores declarados é `FeatureFlagStoreException`, nunca um `false` silencioso; linha com `deleted_at` preenchido não é lida nem trocada. Desde a v2.3.0 a vigência é gravada pelo serviço, e com ela um rollout por porcentagem (exemplo 19.1); o DDL traz, comentada, uma trigger do PostgreSQL que torna o histórico só de inserção também no banco. Desde a v2.4.0 o alvo também pode ser uma regra do catálogo, apontada pelo código (`targetRuleCode`, exemplo 21).
