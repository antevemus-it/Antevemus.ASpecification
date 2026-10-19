<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagReason;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\Serialization\Exceptions\NonSerializableSpecificationException;
use Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Module31Contract - subject of the serialization tests (a rental contract of an agency).
 *
 * @version 2.4.0
 */
final class Module31Contract
{
    public function __construct(
        public readonly int $id,
        public readonly string $state,
        public readonly float|int $rent,
        public readonly string $tenantEmail = 'tenant@example.com',
        public readonly ?DateTimeImmutable $signedAt = null
    ) {
    }
}

/**
 * Module31MarkerLeaf - fuzz control (RN-03): a serializable leaf whose constructor writes a marker file.
 * A document may build it only through a codec that registered it.
 *
 * @version 2.4.0
 */
final class Module31MarkerLeaf extends AbstractSpecification implements ISerializableSpecification
{
    public static string $marker = '';

    public function __construct()
    {
        file_put_contents(self::$marker, 'constructed');
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return true;
    }

    public function getType(): string
    {
        return 'mixed';
    }

    public function toArray(): array
    {
        return [];
    }

    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        return new static();
    }
}

/**
 * Module 31 - Serialization of specifications and feature flags targeted by a catalog rule (2.4.0,
 * forward 041, marcos 16a e 24b).
 *
 * - 16a: a real rule tree goes to JSON and back equal, answering the same; the fuzz of RN-03 (malformed
 *   JSON, unknown `t`, depth 65, 10 001 nodes, an enum outside the allowlist) is refused without running
 *   code of the application, with the control of the marker file: a leaf whose constructor writes a file
 *   is built only by a codec that registered it
 * - 24b (D7): `spec_json` in the catalog (PdoRuleCatalog over SQLite, the reference DDL), findRuleByCode()
 *   in both catalogs, the registry of handlers decoding the body of a rule, the additive migration of a
 *   2.3.0 table; (D8) a flag with `targetRuleCode` evaluated through the catalog, memorized per instance,
 *   failing closed (ERROR, reported once) and following a change of the rule after refresh() (RN-05)
 * - RN-06: testDocumentationExample(), the block of README example 21, and
 *   testReadme21BlocksAreTheSameInBothLanguages(), the same block in README.md and README.pt-BR.md
 * - portability: the catalog battery on PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 when
 *   ASPEC_PDO_{PGSQL,MYSQL,MARIADB}_DSN are set (run_portability.sh, run_parity_suites.sh)
 *
 * @version 2.4.0
 */
class Module31_SpecificationSerializationTest extends TestCase
{
    /** Reference DDL shipped with the package (2.4.0). */
    private const DDL = __DIR__ . '/../../resources/sql/rule_catalog.ansi.sql';

    private const REASON = 'Approved by the product board';

    public function run(): void
    {
        $this->testRoundTripOfARealRule();
        $this->testFuzzNeverRunsCodeOfTheApplication();
        $this->testFlagDefinitionTargetRuleCode();
        $this->testInMemoryCatalogLookupByCode();
        $this->testRuleDefinitionSpecJson();
        $this->testFlagTargetedByARuleFailsClosed();
        $this->testFlagFollowsTheRuleAfterRefresh(new InMemoryRuleCatalog(), null);

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 31 roda só a parte em memória.\n");
            return;
        }

        $this->assertTrue(is_file(self::DDL), 'DDL de referência do catálogo deve existir');
        $this->runCatalogContract($this->sqlite(), 'sqlite');
        $this->testMigrationFrom230($this->sqliteEmpty(), 'sqlite');
        $this->testDocumentationExample();
        $this->testReadme21BlocksAreTheSameInBothLanguages();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; spec_json em {$label} pulado.\n");
                continue;
            }
            $this->resetSchema($pdo);
            $this->runCatalogContract($pdo, $family);
            $this->dropSchema($pdo);
            $this->testMigrationFrom230($pdo, $family);
            $this->dropSchema($pdo);
            fwrite(STDOUT, "    [041] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": spec_json, findRuleByCode, alvo de flag e migração aditiva OK\n");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // 16a: ida e volta de uma regra real
    ///////////////////////////////////////////////////////////////////////////

    private function testRoundTripOfARealRule(): void
    {
        $codec = new SpecificationCodec();
        $rule = self::eligibility();
        $json = $codec->toJson($rule);
        $decoded = $codec->fromJson($json);

        $this->assertTrue($decoded->equals($rule) && $rule->equals($decoded), '16a: a árvore volta igual');
        $this->assertEquals($json, $codec->toJson($decoded), '16a: documento canônico');
        $this->assertEquals($codec->encode($rule), $codec->encode($codec->decode($codec->encode($rule))), '16a: encode(decode(encode(s))) === encode(s)');
        foreach (self::contracts() as $label => $contract) {
            $this->assertEquals($rule->isSatisfiedBy($contract), $decoded->isSatisfiedBy($contract), "16a: mesma resposta para {$label}");
            $this->assertEquals($rule->evaluate($contract)->getCodes(), $decoded->evaluate($contract)->getCodes(), "16a: mesmos códigos para {$label}");
        }
        $this->assertEquals(Spec::toSql($rule, 'pgsql')->toSql(), Spec::toSql($decoded, 'pgsql')->toSql(), 'RN-04: mesmo SQL');
        $this->assertEquals(self::parameters($rule), self::parameters($decoded), 'RN-04: mesmos parâmetros (datas pelo instante e offset)');
        $this->assertTrue(in_array('2026-01-01 00:00:00.000000 +00:00', self::parameters($decoded), true), 'RN-04: a data é parâmetro');
        // The TCriteria stub of the suite prints no date-time value: the parity is checked without the date leaves
        $undated = self::eligibility(false);
        $this->assertEquals(Spec::toCriteria($undated)->dump(), Spec::toCriteria($codec->fromJson($codec->toJson($undated)))->dump(), 'RN-04: mesmo TCriteria');
        $this->assertTrue(str_contains($json, '{"t":"gte","v":{"$date":"2026-01-01T00:00:00.000000+00:00"}}'), 'D3: data RFC 3339 com offset');
        $this->assertTrue(str_contains($json, '"cmp":"ignore_case"'), 'D3: TextComparison pelo valor');
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-03: fuzz com o arquivo marcador
    ///////////////////////////////////////////////////////////////////////////

    private function testFuzzNeverRunsCodeOfTheApplication(): void
    {
        Module31MarkerLeaf::$marker = sys_get_temp_dir() . '/aspec041-marker-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $marker = Module31MarkerLeaf::$marker;
        @unlink($marker);
        $markerClass = Module31MarkerLeaf::class;
        $quoted = json_encode($markerClass, JSON_UNESCAPED_SLASHES);

        $codec = new SpecificationCodec();
        $hostile = [
            'JSON malformado' => ['{"v":1,"spec":{"t":"marker"', SpecificationCodecException::class],
            't desconhecido' => ['{"v":1,"spec":{"t":"marker"}}', NonSerializableSpecificationException::class],
            't = FQCN da classe' => ['{"v":1,"spec":{"t":' . $quoted . '}}', NonSerializableSpecificationException::class],
            't escondido num filho' => ['{"v":1,"spec":{"t":"and","s":[{"t":"isNull"},{"t":"not","s":{"t":"marker"}}]}}', NonSerializableSpecificationException::class],
            'enum fora da lista' => ['{"v":1,"spec":{"t":"eq","v":{"$enum":' . $quoted . ',"case":"x"}}}', NonSerializableSpecificationException::class],
            'tipo com a classe (sem instanciar)' => ['{"v":1,"spec":{"t":"type","type":' . $quoted . '}}', null],
            'allEntities com a classe (sem instanciar)' => ['{"v":1,"spec":{"t":"allEntities","type":' . $quoted . '}}', null],
            'enumName com a classe' => ['{"v":1,"spec":{"t":"enumName","enum":' . $quoted . '}}', SpecificationCodecException::class],
            'profundidade 65' => [self::nested(65), SpecificationCodecException::class],
            '10 001 nós' => [self::wide(10000), SpecificationCodecException::class],
        ];
        foreach ($hostile as $label => [$json, $expected]) {
            if ($expected === null) {
                $this->assertInstanceOf(ISpecification::class, $codec->fromJson($json), "RN-03: {$label} lê só o nome");
            } else {
                $this->assertThrows($expected, fn() => $codec->fromJson($json), "RN-03: {$label}");
            }
            $this->assertFalse(is_file($marker), "RN-03: {$label} não executou código (arquivo marcador ausente)");
        }
        $this->assertTrue($codec->fromJson(self::nested(64)) instanceof ISpecification, 'D6: 64 níveis passam');
        $this->assertTrue($codec->fromJson(self::wide(9999)) instanceof ISpecification, 'D6: 10 000 nós passam');
        $this->assertTrue(str_contains($this->assertThrows(SpecificationCodecException::class, fn() => $codec->fromJson(self::nested(65)))->getMessage(), 'deeper than 64'), 'D6: mensagem da profundidade');
        $this->assertTrue(str_contains($this->assertThrows(SpecificationCodecException::class, fn() => $codec->fromJson(self::wide(10000)))->getMessage(), 'more than 10000 nodes'), 'D6: mensagem dos nós');

        // Controle positivo: o mesmo documento, num codec que registrou o nome, constrói a classe
        $trusting = (new SpecificationCodec())->register('marker', $markerClass);
        $built = $trusting->fromJson('{"v":1,"spec":{"t":"and","s":[{"t":"isNull"},{"t":"not","s":{"t":"marker"}}]}}');
        $this->assertTrue(is_file($marker), 'RN-03: controle positivo, registrado constrói (arquivo criado)');
        $this->assertInstanceOf(ISpecification::class, $built);
        unlink($marker);
        $this->assertFalse(is_file($marker), 'RN-03: marcador limpo');
        $this->assertThrows(NonSerializableSpecificationException::class, fn() => $codec->fromJson('{"v":1,"spec":{"t":"marker"}}'), 'RN-03: o registro é da instância');
        $this->assertFalse(is_file($marker), 'RN-03: outro codec continua sem construir');
    }

    ///////////////////////////////////////////////////////////////////////////
    // 24b: definição da flag, catálogos, RuleDefinition
    ///////////////////////////////////////////////////////////////////////////

    private function testFlagDefinitionTargetRuleCode(): void
    {
        $definition = new FeatureFlagDefinition('renewal_notice', 'Renewal', targetRuleCode: 'renewal_target');
        $this->assertEquals('renewal_target', $definition->targetRuleCode);
        $this->assertTrue($definition->hasTarget(), 'D8: alvo pelo catálogo');
        $this->assertTrue((new FeatureFlagDefinition('renewal_notice', 'Renewal', target: Spec::alwaysTrue()))->hasTarget(), 'alvo em código');
        $this->assertFalse((new FeatureFlagDefinition('renewal_notice', 'Renewal'))->hasTarget(), 'sem alvo');
        $this->assertTrue(str_contains($this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagDefinition('renewal_notice', 'Renewal', target: Spec::alwaysTrue(), targetRuleCode: 'r'))->getMessage(), 'give one of them'), 'D8: exclusivo com target');
        $this->assertTrue(str_contains($this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagDefinition('renewal_notice', 'Renewal', targetRuleCode: ' '))->getMessage(), 'target rule code cannot be blank'), 'código em branco');
        $fromArray = FeatureFlagDefinition::fromArray('renewal_notice', ['name' => 'Renewal', 'target_rule_code' => 'renewal_target']);
        $this->assertEquals('renewal_target', $fromArray->targetRuleCode, 'fromArray: target_rule_code');
        $this->assertTrue(str_contains($this->assertThrows(InvalidArgumentException::class, fn() => FeatureFlagDefinition::fromArray('renewal_notice', ['name' => 'Renewal', 'target_rule_code' => 7]))->getMessage(), '"target_rule_code" must be a string'), 'fromArray: tipo');
    }

    private function testInMemoryCatalogLookupByCode(): void
    {
        $catalog = new InMemoryRuleCatalog([
            new RuleDefinition('r1', 'Inactive', 'spec', scope: 'a', active: false, specJson: '{"v":1,"spec":{"t":"isNull"}}'),
            new RuleDefinition('r1', 'Active', 'spec', scope: 'b', specJson: '{"v":1,"spec":{"t":"notNull"}}'),
            new RuleDefinition('r2', 'Other', 'max', scope: 'c', scenario: 's', parameters: ['product_code' => 'P']),
        ]);
        $this->assertEquals('Active', $catalog->findRuleByCode('r1')?->getName(), 'D7: a ativa, qualquer escopo');
        $this->assertEquals('Other', $catalog->findRuleByCode('r2')?->getName(), 'D7: cenário e produto não filtram');
        $this->assertTrue($catalog->findRuleByCode('r3') === null, 'D7: código inexistente');
        $this->assertTrue($catalog->findRuleByCode('R1') === null, 'D7: código exato');
        $this->assertTrue((new InMemoryRuleCatalog([new RuleDefinition('r1', 'Off', 'spec', active: false)]))->findRuleByCode('r1') === null, 'D7: inativa não volta');
    }

    private function testRuleDefinitionSpecJson(): void
    {
        $json = '{"v":1,"spec":{"t":"eq","v":1}}';
        $this->assertEquals($json, RuleDefinition::fromArray(['code' => 'r', 'name' => 'R', 'rule_type' => 'spec', 'spec_json' => $json])->getSpecJson(), 'D7: spec_json da linha');
        foreach ([null, '', "  \n"] as $blank) {
            $this->assertTrue(RuleDefinition::fromArray(['code' => 'r', 'name' => 'R', 'rule_type' => 'spec', 'spec_json' => $blank])->getSpecJson() === null, 'D7: vazio = sem corpo');
        }
        $this->assertTrue(RuleDefinition::fromArray(['code' => 'r', 'name' => 'R', 'rule_type' => 'max'])->getSpecJson() === null, 'D7: coluna ausente = null');
        $this->assertTrue((new RuleDefinition('r', 'R', 'max'))->getSpecJson() === null, 'D7: default null');

        // The registry of handlers: the body is the specification, before any handler of the rule type
        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('spec', static fn(): ISpecification => Spec::alwaysFalse());
        $built = $registry->buildSpecification(new RuleDefinition('r', 'R', 'spec', specJson: $json));
        $this->assertTrue($built->equals(Spec::equal(1)), 'D7: spec_json vence o handler do rule_type');
        $this->assertTrue($registry->buildSpecification(new RuleDefinition('r', 'R', 'spec'))->equals(Spec::alwaysFalse()), 'sem spec_json, o handler');
        $error = $this->assertThrows(RuleEngineException::class, fn() => $registry->buildSpecification(new RuleDefinition('broken', 'R', 'spec', specJson: '{"v":1,"spec":{"t":"evil"}}')), 'D7: corpo inválido');
        $this->assertTrue(str_contains($error->getMessage(), 'Rule "broken": spec_json is not a specification document') && $error->getPrevious() instanceof NonSerializableSpecificationException, 'D7: a regra é nomeada, a exceção do codec preservada');
        $this->assertEquals(0, $error->getCode(), 'D7: código da exceção');
        $custom = new RuleSpecificationRegistry([], (new SpecificationCodec())->register('marker', Module31MarkerLeaf::class));
        $this->assertTrue($custom->getCodec()->types()['marker'] === Module31MarkerLeaf::class, 'D7: o codec do registro é configurável');
        $this->assertTrue(!isset($registry->getCodec()->types()['marker']), 'D7: codec padrão');

        // The engine compiles the rule from its body, bound to the rule
        $engine = new DynamicSpecificationEngine(new InMemoryRuleCatalog([
            new RuleDefinition('max_rent', 'Maximum rent', 'spec', violationMessage: 'Rent above the limit', scope: 'contract', specJson: (new SpecificationCodec())->toJson(Spec::property('rent', Spec::lessThanOrEqualTo(5000)))),
        ]), new RuleSpecificationRegistry());
        $this->assertTrue($engine->validate(new Module31Contract(1, 'SP', 3000), 'contract')->isSatisfied(), 'D7: o motor compila o corpo');
        $verdict = $engine->validate(new Module31Contract(1, 'SP', 9000), 'contract');
        $this->assertFalse($verdict->isSatisfied(), 'D7: o motor reprova');
        $this->assertTrue(in_array('max_rent', $verdict->getFailureCodes(), true), 'D7: a falha carrega o código da regra');
    }

    ///////////////////////////////////////////////////////////////////////////
    // 24b: flag com alvo no catálogo
    ///////////////////////////////////////////////////////////////////////////

    private function testFlagTargetedByARuleFailsClosed(): void
    {
        $codec = new SpecificationCodec();
        $sp = new Module31Contract(1, 'SP', 3000);

        // No catalog
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $flags = self::flags(null, $reporter);
        $evaluation = $flags->evaluate('renewal_notice', $sp);
        $this->assertTrue($evaluation->reason === FeatureFlagReason::ERROR, 'D8: sem catálogo = ERROR');
        $this->assertTrue($evaluation->error instanceof FeatureFlagException && str_contains($evaluation->error->getMessage(), 'was given no rule catalog'), 'D8: motivo sem catálogo');
        $this->assertFalse($flags->isEnabled('renewal_notice', $sp), 'D8: falha fechada');
        $this->assertCount(1, $reporter->entries(), 'D8: relatado uma vez até refresh()');
        $flags->refresh();
        $flags->evaluate('renewal_notice', $sp);
        $this->assertCount(2, $reporter->entries(), 'D8: relatado de novo após refresh()');
        $this->assertTrue($flags->getRuleCatalog() === null, 'sem catálogo');

        // Missing rule, rule without spec_json, spec_json that does not decode, catalog that fails
        $cases = [
            'regra inexistente' => [new InMemoryRuleCatalog(), 'which is not an active rule of the catalog'],
            'regra inativa' => [new InMemoryRuleCatalog([new RuleDefinition('renewal_target', 'R', 'spec', active: false, specJson: $codec->toJson(Spec::alwaysTrue()))]), 'which is not an active rule of the catalog'],
            'sem spec_json' => [new InMemoryRuleCatalog([new RuleDefinition('renewal_target', 'R', 'max')]), 'has no spec_json'],
            'JSON malformado' => [new InMemoryRuleCatalog([new RuleDefinition('renewal_target', 'R', 'spec', specJson: '{"v":1,')]), 'is not a specification document the codec reads'],
            'nome desconhecido' => [new InMemoryRuleCatalog([new RuleDefinition('renewal_target', 'R', 'spec', specJson: '{"v":1,"spec":{"t":"marker"}}')]), 'Unknown specification name "marker"'],
            'catálogo falhando' => [new class implements IRuleCatalog {
                public function findRules(string $scope, ?string $scenario = null, array $filters = []): array
                {
                    return [];
                }

                public function findDocumentRules(string $scope, ?string $scenario = null): array
                {
                    return [];
                }

                public function findRuleByCode(string $code): ?\Antevemus\ASpecification\Contracts\Engine\IRuleDefinition
                {
                    throw new RuleEngineException('catalog down');
                }
            }, 'catalog down'],
        ];
        foreach ($cases as $label => [$catalog, $message]) {
            $reporter = new InMemoryFeatureFlagErrorReporter();
            $flags = self::flags($catalog, $reporter);
            $evaluation = $flags->evaluate('renewal_notice', $sp);
            $this->assertTrue($evaluation->reason === FeatureFlagReason::ERROR, "D8: {$label} = ERROR");
            $this->assertTrue($evaluation->error !== null && str_contains($evaluation->error->getMessage(), $message), "D8: motivo de {$label}: " . ($evaluation->error?->getMessage() ?? ''));
            $this->assertEquals(0, $evaluation->error?->getCode(), "D8: código da exceção de {$label}");
            $flags->evaluate('renewal_notice', $sp);
            $this->assertFalse($flags->isEnabled('renewal_notice', $sp), "D8: {$label} falha fechada");
            $this->assertCount(1, $reporter->entries(), "D8: {$label} relatado uma vez");
        }

        // A null subject is outside the target before the rule is looked up
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $this->assertTrue(self::flags(null, $reporter)->evaluate('renewal_notice', null)->reason === FeatureFlagReason::NOT_IN_TARGET, 'D8: sujeito nulo fora do alvo');
        $this->assertCount(0, $reporter->entries(), 'D8: sem consulta ao catálogo');

        // A user leaf in the rule: the codec given to FeatureFlags reads it
        $leafJson = '{"v":1,"spec":{"t":"marker"}}';
        Module31MarkerLeaf::$marker = sys_get_temp_dir() . '/aspec041-flag-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $flags = new FeatureFlags(
            new FeatureFlagRegistry([new FeatureFlagDefinition('renewal_notice', 'Renewal', subjectKey: 'id', targetRuleCode: 'renewal_target')]),
            self::store(),
            ruleCatalog: new InMemoryRuleCatalog([new RuleDefinition('renewal_target', 'R', 'spec', specJson: $leafJson)]),
            specificationCodec: (new SpecificationCodec())->register('marker', Module31MarkerLeaf::class)
        );
        $this->assertTrue($flags->isEnabled('renewal_notice', $sp), 'D8: codec configurável');
        @unlink(Module31MarkerLeaf::$marker);
    }

    /**
     * RN-05: the flag follows the rule of the catalog after refresh(), without rebuilding the service.
     *
     * @param PDO|null $pdo When given, the rule lives in the business_rule table of this connection
     */
    private function testFlagFollowsTheRuleAfterRefresh(IRuleCatalog $catalog, ?PDO $pdo, string $label = 'memória'): void
    {
        $codec = new SpecificationCodec();
        $setRule = function (ISpecification $target) use ($catalog, $pdo, $codec): void {
            if ($pdo !== null) {
                $statement = $pdo->prepare('UPDATE business_rule SET spec_json = :json WHERE code = :code');
                $statement->execute([':json' => $codec->toJson($target), ':code' => 'renewal_target']);

                return;
            }
            if ($catalog instanceof InMemoryRuleCatalog) {
                $catalog->addRule(new RuleDefinition('renewal_target', 'Renewal pilot', 'spec', specJson: $codec->toJson($target)));
            }
        };
        if ($pdo !== null) {
            $this->insertRule($pdo, 'renewal_target', $codec->toJson(Spec::property('state', Spec::in('SP', 'RJ'))));
        } else {
            $setRule(Spec::property('state', Spec::in('SP', 'RJ')));
        }

        $reporter = new InMemoryFeatureFlagErrorReporter();
        $flags = self::flags($catalog, $reporter);
        $sp = new Module31Contract(1, 'SP', 3000);
        $mg = new Module31Contract(2, 'MG', 3000);

        $this->assertTrue($flags->isEnabled('renewal_notice', $sp), "RN-05 ({$label}): SP no alvo");
        $this->assertTrue($flags->evaluate('renewal_notice', $mg)->reason === FeatureFlagReason::NOT_IN_TARGET, "RN-05 ({$label}): MG fora do alvo");
        $this->assertTrue($flags->spec('renewal_notice')->isSatisfiedBy($sp), "RN-05 ({$label}): spec() da flag");

        if ($pdo !== null) {
            $setRule(Spec::property('state', Spec::equal('MG')));
        } else {
            // In memory the first active rule with the code wins: a new catalog content is a new rule list
            $catalog = new InMemoryRuleCatalog([new RuleDefinition('renewal_target', 'Renewal pilot', 'spec', specJson: $codec->toJson(Spec::property('state', Spec::equal('MG'))))]);
            $flags = self::flags($catalog, $reporter);
            $this->assertTrue($flags->isEnabled('renewal_notice', $mg), "RN-05 ({$label}): novo conteúdo do catálogo");

            return;
        }
        $this->assertTrue($flags->isEnabled('renewal_notice', $sp), "RN-05 ({$label}): memorizada por instância até refresh()");
        $flags->refresh();
        $this->assertFalse($flags->isEnabled('renewal_notice', $sp), "RN-05 ({$label}): após refresh() SP saiu");
        $this->assertTrue($flags->isEnabled('renewal_notice', $mg), "RN-05 ({$label}): após refresh() MG entrou, sem reconstruir o serviço");

        $pdo->exec("UPDATE business_rule SET spec_json = NULL WHERE code = 'renewal_target'");
        $flags->refresh();
        $this->assertTrue($flags->evaluate('renewal_notice', $mg)->reason === FeatureFlagReason::ERROR, "RN-05 ({$label}): regra sem corpo = ERROR");
        $this->assertCount(1, $reporter->entries(), "RN-05 ({$label}): relatado");
    }

    ///////////////////////////////////////////////////////////////////////////
    // Catálogo relacional (SQLite e, com DSN, PostgreSQL/MySQL/MariaDB)
    ///////////////////////////////////////////////////////////////////////////

    private function runCatalogContract(PDO $pdo, string $family): void
    {
        $codec = new SpecificationCodec();
        $body = $codec->toJson(self::eligibility());
        $this->assertTrue(strlen($body) > 500 && strlen($body) < 4000, 'o corpo de teste cabe na coluna de referência');
        $this->insertRule($pdo, 'eligibility', $body, 'contract');
        $this->insertRule($pdo, 'retired', $codec->toJson(Spec::alwaysFalse()), 'contract', active: 'N');
        $this->insertRule($pdo, 'handled', null, 'contract', ruleType: 'max_rent', intValue: 5000);

        $catalog = new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_4);
        $this->assertEquals('spec_json', $catalog->getColumns()['spec_json'], "{$family}: coluna mapeada");
        $rule = $catalog->findRuleByCode('eligibility');
        $this->assertTrue($rule !== null && $rule->getSpecJson() === $body, "{$family}: spec_json lido igual");
        $this->assertTrue($codec->fromJson((string) $rule?->getSpecJson())->equals(self::eligibility()), "{$family}: a regra decodifica igual");
        $this->assertTrue($catalog->findRuleByCode('retired') === null, "{$family}: inativa não volta");
        $this->assertTrue($catalog->findRuleByCode('nothing') === null, "{$family}: inexistente");
        $this->assertTrue($catalog->findRuleByCode('handled')?->getSpecJson() === null, "{$family}: regra de handler sem corpo");
        $this->assertTrue($catalog->withAsOf(new DateTimeImmutable('2020-01-01'))->findRuleByCode('eligibility') === null, "{$family}: janela de validade de withAsOf()");
        $this->assertTrue($catalog->withAsOf(new DateTimeImmutable('2026-06-01'))->findRuleByCode('eligibility') !== null, "{$family}: dentro da janela");
        $byScope = array_map(static fn($r): string => $r->getCode() . '=' . ($r->getSpecJson() === null ? 'null' : 'json'), $catalog->findRules('contract'));
        sort($byScope);
        $this->assertEquals(['eligibility=json', 'handled=null'], $byScope, "{$family}: findRules traz o corpo");

        $withoutColumn = new PdoRuleCatalog($pdo);
        $this->assertFalse(isset($withoutColumn->getColumns()['spec_json']), "{$family}: ausente por padrão");
        $this->assertTrue($withoutColumn->findRuleByCode('eligibility')?->getSpecJson() === null, "{$family}: sem o mapa a coluna não é lida");
        $this->assertFalse(isset((new PdoRuleCatalog($pdo, columns: ['spec_json' => null]))->getColumns()['spec_json']), "{$family}: null = ausente");
        $this->assertTrue((new PdoRuleCatalog($pdo, columns: ['spec_json' => 'spec_json', 'code' => 'code']))->findRuleByCode('eligibility')?->getSpecJson() === $body, "{$family}: mapa mesclado");
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, columns: ['spec_json' => 'spec json']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, columns: ['name' => null]));
        $unknown = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, columns: ['body' => 'body']));
        $this->assertEquals('Unknown rule catalog column "body". Expected one of: ' . implode(', ', [...PdoRuleCatalog::canonicalColumns(), 'spec_json']) . '.', $unknown->getMessage(), "{$family}: as colunas opcionais entram na lista");
        $this->assertEquals('rule_code', (new PdoRuleCatalog($pdo, columns: ['spec_json' => null, 'code' => 'rule_code']))->getColumns()['code'], "{$family}: o mapa continua depois de uma coluna ausente");

        // The engine compiles the body read from the database
        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('max_rent', static fn($rule): ISpecification => Spec::property('rent', Spec::lessThanOrEqualTo($rule->getIntValue())));
        $engine = new DynamicSpecificationEngine($catalog, $registry);
        foreach (self::contracts() as $label => $contract) {
            $expected = self::eligibility()->isSatisfiedBy($contract) && $contract->rent <= 5000;
            $this->assertEquals($expected, $engine->validate($contract, 'contract')->isSatisfied(), "{$family}: motor com spec_json ({$label})");
        }

        $pdo->exec("DELETE FROM business_rule WHERE code IN ('eligibility', 'retired', 'handled')");
        $this->testFlagFollowsTheRuleAfterRefresh($catalog, $pdo, $family);
        $pdo->exec('DELETE FROM business_rule');
    }

    /**
     * A business_rule table of 2.3.0 (the reference DDL without spec_json) keeps working untouched; the
     * additive migration of the header brings it to 2.4.0.
     */
    private function testMigrationFrom230(PDO $pdo, string $family): void
    {
        $ddl = (string) file_get_contents(self::DDL);
        $old = preg_replace('/^\s+spec_json\s+VARCHAR\(4000\),\n/m', '', $ddl, -1, $removed);
        $this->assertEquals(1, $removed, 'a DDL de 2.3.0 é a de referência sem a coluna');
        self::loadDdl($pdo, (string) $old);
        $pdo->exec("INSERT INTO business_rule (code, name, rule_type, scope) VALUES ('legacy', 'Legacy', 'max_rent', 'contract')");

        $catalog = new PdoRuleCatalog($pdo);
        $this->assertEquals('Legacy', $catalog->findRuleByCode('legacy')?->getName(), "{$family}: tabela de 2.3.0 lida sem a coluna");
        $this->assertCount(1, $catalog->findRules('contract'), "{$family}: findRules intacto");

        $this->assertEquals(1, preg_match('/^--\s+(ALTER TABLE business_rule ADD COLUMN spec_json [^;]+);/m', $ddl, $migration), 'a migração está no cabeçalho do DDL');
        $pdo->exec($migration[1]);
        $pdo->exec("UPDATE business_rule SET spec_json = '{\"v\":1,\"spec\":{\"t\":\"notNull\"}}' WHERE code = 'legacy'");
        $migrated = new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_4);
        $this->assertEquals('{"v":1,"spec":{"t":"notNull"}}', $migrated->findRuleByCode('legacy')?->getSpecJson(), "{$family}: migração aditiva lê spec_json");
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-06: exemplo pronto para o README
    ///////////////////////////////////////////////////////////////////////////

    private function testDocumentationExample(): void
    {
        $pdo = $this->sqlite(); // the tables of resources/sql/rule_catalog.ansi.sql (2.4.0)

        // --- README ---
        $codec = new SpecificationCodec();
        $pilot = Spec::property('state', Spec::in('SP', 'RJ'))
            ->and(Spec::property('rent', Spec::lessThanOrEqualTo(5000)))
            ->because('Pilot: SP and RJ, rent up to 5000');

        $json = $codec->toJson($pilot);
        // {"v":1,"spec":{"t":"and","s":[{"t":"property","p":"state","s":{"t":"in","v":["SP","RJ"]}},
        //  {"t":"property","p":"rent","s":{"t":"lte","v":5000}}],"because":"Pilot: SP and RJ, rent up to 5000"}}
        $r1 = $codec->fromJson($json)->equals($pilot); // true: the same specification

        // The body of a catalog rule is that document; a feature flag targets the rule by its code
        $pdo->prepare("INSERT INTO business_rule (code, name, rule_type, spec_json) VALUES ('renewal_pilot', 'Renewal pilot', 'spec', ?)")
            ->execute([$json]);
        $flags = new FeatureFlags(
            new FeatureFlagRegistry([
                new FeatureFlagDefinition('renewal_notice', 'Renewal notice', subjectKey: 'id', targetRuleCode: 'renewal_pilot'),
            ]),
            new InMemoryFeatureFlagStore(),
            ruleCatalog: new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_4),
        );
        $flags->toggle('renewal_notice', true, 'Pilot approved by the board');

        $r2 = $flags->isEnabled('renewal_notice', new Module31Contract(id: 1, state: 'SP', rent: 3200));          // true
        $r3 = $flags->evaluate('renewal_notice', new Module31Contract(id: 2, state: 'MG', rent: 3200))->reason; // NOT_IN_TARGET

        // The rule changes in the catalog: the flag follows it after refresh(), without a deploy
        $pdo->prepare("UPDATE business_rule SET spec_json = ? WHERE code = 'renewal_pilot'")
            ->execute([$codec->toJson(Spec::property('state', Spec::in('SP', 'RJ', 'MG')))]);
        $flags->refresh();
        $r4 = $flags->isEnabled('renewal_notice', new Module31Contract(id: 2, state: 'MG', rent: 3200)); // true
        // --- /README ---

        $this->assertEquals('{"v":1,"spec":{"t":"and","s":[{"t":"property","p":"state","s":{"t":"in","v":["SP","RJ"]}},{"t":"property","p":"rent","s":{"t":"lte","v":5000}}],"because":"Pilot: SP and RJ, rent up to 5000"}}', $json, 'README: o documento do comentário');
        $this->assertTrue($r1, 'README: ida e volta');
        $this->assertTrue($r2, 'README: SP no piloto');
        $this->assertTrue($r3 === FeatureFlagReason::NOT_IN_TARGET, 'README: MG fora');
        $this->assertTrue($r4, 'README: MG entrou após refresh()');
    }

    private function testReadme21BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/specifications/serialization.md', '21');
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/specifications/serialization.md', '21');
        $this->assertCount(1, $english, 'um bloco PHP no exemplo 21 (EN)');
        $this->assertCount(1, $portuguese, 'um bloco PHP no exemplo 21 (pt-BR)');
        $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), 'README 21: bloco igual fora os comentários');
        $this->assertTrue(str_contains($english[0], "new FeatureFlagDefinition('renewal_notice', 'Renewal notice', subjectKey: 'id', targetRuleCode: 'renewal_pilot')"), 'README 21: o bloco é o exemplo executado acima');
    }

    ///////////////////////////////////////////////////////////////////////////
    // Apoio
    ///////////////////////////////////////////////////////////////////////////

    /** A realistic eligibility rule: state, rent, e-mail domain, signature date, annotations. */
    private static function eligibility(bool $dated = true): ISpecification
    {
        $rule = Spec::property('state', Spec::in('SP', 'RJ', 'MG'))
            ->and(Spec::property('rent', Spec::greaterThan(500)->and(Spec::lessThanOrEqualTo(15000.5)))->withCode('RENT_RANGE'))
            ->and(Spec::property('tenantEmail', Spec::endsWith('@example.com', false))->because('Corporate tenants only'))
            ->and(Spec::not(Spec::property('signedAt', Spec::isNull())));

        return $dated ? $rule->and(Spec::property('signedAt', Spec::afterOrAt(new DateTimeImmutable('2026-01-01T00:00:00Z')))) : $rule;
    }

    /**
     * @return array<string, Module31Contract>
     */
    private static function contracts(): array
    {
        $signed = new DateTimeImmutable('2026-03-01T10:00:00-03:00');

        return [
            'SP elegível' => new Module31Contract(1, 'SP', 3000, 'ana@EXAMPLE.com', $signed),
            'MG aluguel alto' => new Module31Contract(2, 'MG', 9000.25, 'bia@example.com', $signed),
            'RJ acima da faixa' => new Module31Contract(3, 'RJ', 20000, 'carl@example.com', $signed),
            'BA fora' => new Module31Contract(4, 'BA', 3000, 'dan@example.com', $signed),
            'outro domínio' => new Module31Contract(5, 'SP', 3000, 'eve@other.com', $signed),
            'sem assinatura' => new Module31Contract(6, 'SP', 3000, 'fay@example.com', null),
            'assinado antes' => new Module31Contract(7, 'SP', 3000, 'gus@example.com', new DateTimeImmutable('2025-12-31T23:59:59Z')),
        ];
    }

    private static function flags(?IRuleCatalog $catalog, InMemoryFeatureFlagErrorReporter $reporter): FeatureFlags
    {
        return new FeatureFlags(
            new FeatureFlagRegistry([new FeatureFlagDefinition('renewal_notice', 'Renewal notice', subjectKey: 'id', targetRuleCode: 'renewal_target')]),
            self::store(),
            $reporter,
            ruleCatalog: $catalog
        );
    }

    private static function store(): InMemoryFeatureFlagStore
    {
        $store = new InMemoryFeatureFlagStore();
        $store->put(new \Antevemus\ASpecification\FeatureFlags\FeatureFlagState('renewal_notice', true));

        return $store;
    }

    /**
     * The SQL parameters of a specification (MySQL), date-times by wall clock and offset.
     *
     * @return array<string, mixed>
     */
    private static function parameters(ISpecification $specification): array
    {
        return array_map(
            static fn(mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s.u P') : $value,
            Spec::toSql($specification, 'mysql')->getParameters()
        );
    }

    /** JSON of `not` nested to the given depth over isNull. */
    private static function nested(int $depth): string
    {
        return '{"v":1,"spec":' . str_repeat('{"t":"not","s":', $depth - 1) . '{"t":"isNull"}' . str_repeat('}', $depth - 1) . '}';
    }

    /** JSON of an `or` with the given number of operands (operands + 1 nodes). */
    private static function wide(int $operands): string
    {
        return '{"v":1,"spec":{"t":"or","s":[' . implode(',', array_fill(0, $operands, '{"t":"isNull"}')) . ']}}';
    }

    private function insertRule(PDO $pdo, string $code, ?string $specJson, ?string $scope = null, string $active = 'Y', string $ruleType = 'spec', ?int $intValue = null): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO business_rule (code, name, rule_type, int_value, scope, valid_from, active, spec_json) VALUES (:code, :name, :type, :int, :scope, :from, :active, :json)'
        );
        $statement->execute([
            ':code' => $code,
            ':name' => ucfirst($code),
            ':type' => $ruleType,
            ':int' => $intValue,
            ':scope' => $scope,
            ':from' => '2026-01-01',
            ':active' => $active,
            ':json' => $specJson,
        ]);
    }

    private function sqlite(): PDO
    {
        $pdo = $this->sqliteEmpty();
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));

        return $pdo;
    }

    private function sqliteEmpty(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Runs a DDL script: drops `--` comment lines and executes each `;`-terminated statement.
     */
    private static function loadDdl(PDO $pdo, string $ddl): void
    {
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private function resetSchema(PDO $pdo): void
    {
        $this->dropSchema($pdo);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
    }

    private function dropSchema(PDO $pdo): void
    {
        foreach (['document_requirement', 'document_requirement_group', 'business_rule'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private function connectFromEnvironment(string $family): ?PDO
    {
        $prefix = 'ASPEC_PDO_' . strtoupper($family);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            return null;
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');

        return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
