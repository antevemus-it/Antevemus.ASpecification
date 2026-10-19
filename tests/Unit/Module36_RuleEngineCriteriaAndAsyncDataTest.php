<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Adianti\Database\TCriteria;
use Adianti\Database\TFilter;
use Adianti\Database\TTransaction;
use Adianti\Widget\Dialog\TMessage;
use Adianti\Widget\Dialog\TToast;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationHandler;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Criteria\Exceptions\UnsafeCriteriaValueException;
use Antevemus\ASpecification\Criteria\TAccentInsensitiveFilter;
use Antevemus\ASpecification\Criteria\TConstantFilter;
use Antevemus\ASpecification\Criteria\TCriteriaBuilder;
use Antevemus\ASpecification\Criteria\TSubqueryFilter;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\Exceptions\MissingRuleHandlerException;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RuleEngineVerdict;
use Antevemus\ASpecification\Engine\RulePrecedence;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Resolution\AsyncDataSource;
use Antevemus\ASpecification\Resolution\AsyncPoll;
use Antevemus\ASpecification\Resolution\AsyncTicket;
use Antevemus\ASpecification\Resolution\CallableAsyncTicketStore;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\InMemoryAsyncTicketStore;
use Antevemus\ASpecification\Resolution\InMemoryDataCache;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Serialization\Exceptions\NonSerializableSpecificationException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Exceptions\DatabaseOnlySpecificationException;
use Antevemus\ASpecification\Specifications\LabelledSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Specifications\Subquery\ExistsSubquerySpecification;
use Antevemus\ASpecification\Specifications\Subquery\InSubquerySpecification;
use Antevemus\ASpecification\Sql\Dialects\InformixDialect;
use Antevemus\ASpecification\Sql\Dialects\MySqlDialect;
use Antevemus\ASpecification\Sql\Dialects\PostgreSqlDialect;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsafeIdentifierException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Sql\LikePattern;
use Antevemus\ASpecification\Tests\Support\Module36Cases;
use Antevemus\ASpecification\Tests\Support\PdoRecordingPdo;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\UseCase\IUseCase;
use Antevemus\ASpecification\UseCase\TransactionMiddleware;
use Antevemus\ASpecification\UseCase\UseCaseResult;
use Antevemus\ASpecification\UseCase\UseCaseStatus;
use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

// README 22.3 classes >>>
/** Joins the transaction the screen opened; opens (and ends) one only when there is none. */
final class AdiantiTransaction
{
    private bool $opened = false;

    public function __construct(private readonly string $database) {}

    public function middleware(): TransactionMiddleware
    {
        return new TransactionMiddleware($this->begin(...), $this->commit(...), $this->rollback(...));
    }

    public function begin(): void
    {
        if (TTransaction::get() === null) {
            TTransaction::open($this->database);
            $this->opened = true;
        }
    }

    public function commit(): void
    {
        if ($this->opened) {
            $this->opened = false;
            TTransaction::close();
        }
    }

    public function rollback(): void
    {
        if ($this->opened) {
            $this->opened = false;
            TTransaction::rollback();
        }
    }
}

/** A UseCaseResult on an Adianti screen: messages of the rules, never the message of an exception. */
final class UseCaseFeedback
{
    public static function show(UseCaseResult $result, string $done): void
    {
        match (true) {
            $result->getStatus() === UseCaseStatus::ACCEPTED => TToast::show('info', 'Received: being processed.'),
            $result->isSuccess() => TToast::show('success', $done),
            $result->getStatus() === UseCaseStatus::FAILED => new TMessage('error', 'Unexpected error. Reference: ' . ($result->getCorrelationId() ?? '-')),
            default => new TMessage('error', implode('<br>', array_map(fn($failure) => $failure->message, $result->getErrors()))),
        };
    }
}

final class OpenClaim implements IUseCase
{
    public function execute(object $request): UseCaseResult
    {
        TTransaction::get()->prepare('INSERT INTO claim_history (claim_id, event) VALUES (?, ?)')->execute([$request->claimId, 'opened']);

        return UseCaseResult::created($request->claimId);
    }
}
// <<< README 22.3 classes

/**
 * Module36Bureau - A provider that creates an analysis now and answers it later (forward 048, L10): a scripted
 * fake of the credit bureau of README 22.2 and of the TShield of RN-07 (e).
 *
 * @version 2.5.0
 */
final class Module36Bureau
{
    /** Analyses created (POSTs), the number RN-02/RN-07 bound to one per key. */
    public int $created = 0;

    public int $polls = 0;

    /** @var array<string, list<string>> Remaining answers per ticket: pending, done, error, raise */
    private array $script = [];

    /** @var array<string, string> Ticket by CPF */
    private array $byCpf = [];

    /** @var array<string, int> Score by ticket */
    private array $scores = [];

    /**
     * @param list<string> $answers What each poll answers, in order (the last one repeats)
     */
    public function __construct(private readonly array $answers = ['pending', 'pending', 'done'], private readonly int $score = 720)
    {
    }

    public function createAnalysis(string $cpf, string $key): string
    {
        $this->created++;
        $ticket = 'T' . $this->created . '-' . substr(md5($cpf . $key), 0, 6);
        $this->script[$ticket] = $this->answers;
        $this->byCpf[$cpf] = $ticket;
        $this->scores[$ticket] = $this->score;

        return $ticket;
    }

    public function isDone(string $ticket): bool
    {
        return $this->next($ticket) === 'done';
    }

    /** One answer of the script: pending, done or error ("raise" throws, a transport failure). */
    public function next(string $ticket): string
    {
        $this->polls++;
        $answer = count($this->script[$ticket]) > 1 ? array_shift($this->script[$ticket]) : $this->script[$ticket][0];
        if ($answer === 'raise') {
            throw new RuntimeException('connection reset by the bureau');
        }

        return $answer;
    }

    public function score(string $ticket): int
    {
        return $this->scores[$ticket];
    }

    public function findAnalysis(string $cpf): ?string
    {
        return $this->byCpf[$cpf] ?? null;
    }
}

/**
 * Module36Row - entity of the PdoRepository that refuses the raw subqueries (2.5.0).
 *
 * @version 2.5.0
 */
final class Module36Row extends AbstractEntity
{
    public function __construct(private ?int $id, public string $label)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

/**
 * Module36_RuleEngineCriteriaAndAsyncDataTest - Forward 048 (2.5.0): the gaps of the rule engine, of the
 * TCriteria bridge and of the data resolution measured at the Virttus.
 *
 * - L1 (D1-D3): lookup-only rule types (registerLookupOnly(), getSkippedRules(), refusal of an ambiguous
 *   type, an undeclared type still MissingRuleHandlerException), and the property: compiling with the
 *   declaration = compiling the catalog filtered by the Evaluator of the Virttus (transcribed);
 * - L2 (D4-D6): findRulesByType() in memory and over PDO (one query), RulePrecedence, and the property:
 *   InMemory = PDO = brute force, whatever the order of insertion; byScenario() = findRule() per scenario;
 *   without product and plan, the first() of the Virttus (transcribed);
 * - L3 (D7-D12): labelled targets (innermost wins, annotations, errors kept, codec, simplify(), SQL, algebra,
 *   collapse() with labels) and RN-07 (a): the Evaluator of the Virttus rewritten with labels and lookup-only
 *   types answers the violations of the current one over a generated table of facts;
 * - L4 (D13-D16): TAccentInsensitiveFilter and withAccentFolding(); with ext-intl and the databases, the
 *   battery of the 035 executed through the TCriteria of the REAL Adianti 8.1 and 8.7 (and the stub) =
 *   toSql() = memory, negations with NULL rows, `prep = 0` failing closed;
 * - L5 (D17-D24): inSubquery()/existsIn(): identifiers, SQL and TCriteria forms, memory = error, codec
 *   refusal, PdoRepository refusal; the battery over a two-table fixture with hostile literals (`#`, `--`,
 *   `/*`, `]:`, `'`, `\`), TCriteria of the REAL Adianti 8.1 and 8.7 = toSql() = PHP brute force, in SQLite
 *   (and PostgreSQL 17, MySQL 8.4, MariaDB 11.4 when ASPEC_PDO_* are set), and the control: the raw
 *   `(SELECT` of TFilter mutilates `#`;
 * - L10 (D25-D28): DataPendingException, isPending(), AsyncDataSource (submit once, poll, reconcile, fail
 *   closed), the stores, the AND/OR/NOT matrix of a pending datum, the at-most-once property over scripted
 *   providers and RN-07 (e): the TShield simulated, exactly one POST;
 * - L11 (D29-D33): README 22.3, the Adianti host (transaction joined, UseCaseResult on TMessage/TToast),
 *   run against the stubs, with the defect of a second TTransaction::open() shown;
 * - README 22 to 22.3 transcribed here (the transcription is checked token by token against the README) and
 *   the same blocks in README.pt-BR.md and docs/adianti/README.md.
 *
 * @version 2.5.0
 */
class Module36_RuleEngineCriteriaAndAsyncDataTest extends TestCase
{
    /** Reference DDL of the rule catalog. */
    private const DDL = __DIR__ . '/../../resources/sql/rule_catalog.ansi.sql';

    public function run(): void
    {
        // L1
        $this->testLookupOnlyTypes();
        $this->testLookupOnlyRefusesAmbiguity();
        $this->testLookupOnlyAgainstTheVirttusFilter();
        // L2
        $this->testPrecedenceOrder();
        $this->testRuleTypeCatalogInMemory();
        // L3
        $this->testLabelledTargets();
        $this->testLabelsThroughTheEngine();
        $this->testVirttusEvaluatorAcceptance();
        // L4
        $this->testAccentFilterRendering();
        $this->testAccentFoldingThroughTheVisitor();
        // L5
        $this->testSubqueryIdentifiers();
        $this->testSubqueryTranslations();
        $this->testSubqueryInMemoryCodecAndRepository();
        // L10
        $this->testPendingMatrix();
        $this->testAsyncDataSourceProtocol();
        $this->testAsyncAtMostOneSubmissionProperty();
        $this->testTShieldSimulation();
        $this->testEdgesFoundByMutation();
        $this->testConstantFilterRendering();
        // L11 and the README
        $this->testAdiantiHostDefectAndRecipe();
        $this->testReadme22();
        $this->testReadme221();
        $this->testReadme222();
        $this->testReadme223();
        $this->testReadme22BlocksAreTheSameInBothLanguages();

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 36 roda só a parte em memória.\n");
            return;
        }
        $this->testBrokenControls();
        $this->testRuleTypeCatalogOverPdo($this->sqliteCatalog(), 'sqlite', true);
        $this->testSubqueryBattery($this->sqliteEmpty(), 'sqlite');
        $this->testVirttusSubqueriesAcceptance();
        $this->testConstantConditionsOnSqlite();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; Módulo 36 em {$label} pulado.\n");
                continue;
            }
            $this->dropCatalog($pdo);
            self::loadDdl($pdo, (string) file_get_contents(self::DDL));
            $this->testRuleTypeCatalogOverPdo($pdo, $family, false);
            $this->dropCatalog($pdo);
            $subqueries = $this->testSubqueryBattery($pdo, $family);
            $accents = extension_loaded('intl') ? $this->testAccentBattery($pdo, $family) : 0;
            fwrite(STDOUT, "    [048] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": precedência, {$subqueries} consultas de subquery e {$accents} sem acento OK\n");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // L1: lookup-only rule types
    ///////////////////////////////////////////////////////////////////////////

    private function testLookupOnlyTypes(): void
    {
        $catalog = new InMemoryRuleCatalog([
            new RuleDefinition(code: 'max_occ', name: 'Max', ruleType: 'max_occurrences', intValue: 2, violationMessage: 'Too many', priority: 5, scope: 'claim'),
            new RuleDefinition(code: 'deadline_x', name: 'Deadline', ruleType: 'stage_deadline', intValue: 10, priority: 9, scope: 'claim'),
            new RuleDefinition(code: 'switch_y', name: 'Switch', ruleType: 'claim_switch', priority: 1, scope: 'claim'),
        ]);
        $closure = static fn(IRuleDefinition $rule): ISpecification => Spec::property('occ', Spec::lessThanOrEqualTo((int) $rule->getIntValue()));

        // Before (2.4.0 behaviour kept without the declaration): one rule without handler stops the scope
        $plain = new DynamicSpecificationEngine($catalog, (new RuleSpecificationRegistry())->registerClosure('max_occurrences', $closure));
        $e = $this->assertThrows(MissingRuleHandlerException::class, fn() => $plain->compileSpecification('claim'));
        $this->assertTrue(str_contains($e->getMessage(), 'stage_deadline'), 'L1: a mensagem de hoje nomeia o tipo');

        $registry = (new RuleSpecificationRegistry())->registerClosure('max_occurrences', $closure)->registerLookupOnly('stage_deadline', ' claim_switch ');
        $this->assertTrue($registry->isLookupOnly('stage_deadline'), 'D1: declarado');
        $this->assertTrue($registry->isLookupOnly(' claim_switch'), 'D1: comparado sem espaços');
        $this->assertFalse($registry->isLookupOnly('max_occurrences'), 'D1: tipo com handler não é só consulta');
        $this->assertEquals(['stage_deadline', 'claim_switch'], $registry->getLookupOnlyTypes(), 'D1: na ordem da declaração');
        $this->assertFalse($registry->hasHandler('stage_deadline'), 'D1: só consulta não tem handler');

        $engine = new DynamicSpecificationEngine($catalog, $registry);
        $this->assertEquals([], $engine->getSkippedRules(), 'D2: vazio antes de compilar');
        $verdict = $engine->validate(['occ' => 3], 'claim');
        $this->assertFalse($verdict->isSatisfied(), 'D2: a regra com handler continua validando');
        $this->assertEquals(['max_occ'], $verdict->getFailureCodes(), 'D2: só a regra com handler falha');
        $this->assertEquals(['deadline_x', 'switch_y'], array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $engine->getSkippedRules()), 'D2: puladas na ordem do catálogo (prioridade)');
        $this->assertTrue($engine->validate(['occ' => 1], 'claim')->isSatisfied(), 'D2: só consulta nunca reprova');
        $this->assertCount(2, $engine->getSkippedRules(), 'D2: zerada e preenchida a cada compilação');
        $this->assertTrue($engine->withCatalogValidation()->compileSpecification('claim') instanceof ISpecification, 'D2: independe do modo de validação');

        $onlyLookup = new DynamicSpecificationEngine(new InMemoryRuleCatalog([$catalog->findRuleByCode('deadline_x')]), $registry);
        $this->assertTrue($onlyLookup->compileSpecification('claim')->isTautology(), 'D2: escopo só com valores de consulta compila para alwaysTrue');
        $this->assertCount(1, $onlyLookup->getSkippedRules());
        $onlyLookup->compileSpecification('other');
        $this->assertEquals([], $onlyLookup->getSkippedRules(), 'D2: zerada a cada chamada');

        // D2: a rule of a lookup-only type WITH spec_json is a validation (the document wins over the type)
        $codec = new SpecificationCodec();
        $withDocument = new InMemoryRuleCatalog([
            new RuleDefinition(code: 'deadline_doc', name: 'Deadline doc', ruleType: 'stage_deadline', scope: 'claim', specJson: $codec->toJson(Spec::property('days', Spec::lessThanOrEqualTo(10)))),
        ]);
        $docEngine = new DynamicSpecificationEngine($withDocument, $registry);
        $this->assertEquals(['deadline_doc'], $docEngine->validate(['days' => 11], 'claim')->getFailureCodes(), 'D2: spec_json compilado mesmo de tipo só consulta');
        $this->assertEquals([], $docEngine->getSkippedRules(), 'D2: não pulada');

        // D3: an undeclared type without handler is still refused, even with lookup-only declarations
        $typo = new InMemoryRuleCatalog([new RuleDefinition(code: 'typo', name: 'Typo', ruleType: 'stage_deadlin', scope: 'claim')]);
        $this->assertThrows(MissingRuleHandlerException::class, fn() => (new DynamicSpecificationEngine($typo, $registry))->validate([], 'claim'));
        // buildSpecification() directly: a lookup-only type has no specification
        $this->assertThrows(MissingRuleHandlerException::class, fn() => $registry->buildSpecification($catalog->findRuleByCode('deadline_x')));
        $this->assertThrows(RuleEngineException::class, fn() => $registry->registerLookupOnly('  '), 'D1: tipo vazio recusado');
    }

    private function testLookupOnlyRefusesAmbiguity(): void
    {
        $closure = static fn(IRuleDefinition $rule): ISpecification => Spec::alwaysTrue();
        $e = $this->assertThrows(RuleEngineException::class, fn() => (new RuleSpecificationRegistry())->registerLookupOnly('t')->registerClosure('t', $closure));
        $this->assertTrue(str_contains($e->getMessage(), 'lookup only'), 'D1: closure em tipo só consulta recusada no registro');
        $e = $this->assertThrows(RuleEngineException::class, fn() => (new RuleSpecificationRegistry())->registerClosure('t', $closure)->registerLookupOnly('x', 't'));
        $this->assertTrue(str_contains($e->getMessage(), 'has a specification handler'), 'D1: o contrário também');

        // A class handler whose supports() is dynamic: refused when a rule of the type is compiled or built
        $handler = new class implements IRuleSpecificationHandler {
            public function supports(string $ruleType): bool
            {
                return str_starts_with($ruleType, 'stage_');
            }

            public function build(IRuleDefinition $rule): ISpecification
            {
                return Spec::alwaysTrue();
            }
        };
        $registry = (new RuleSpecificationRegistry([$handler]))->registerLookupOnly('stage_deadline');
        $rule = new RuleDefinition(code: 'd', name: 'D', ruleType: 'stage_deadline', scope: 's');
        $e = $this->assertThrows(RuleEngineException::class, fn() => (new DynamicSpecificationEngine(new InMemoryRuleCatalog([$rule]), $registry))->compileSpecification('s'));
        $this->assertTrue(str_contains($e->getMessage(), 'Ambiguous rule type "stage_deadline"') && str_contains($e->getMessage(), 'rule d'), 'D1: ambíguo na compilação, com a regra');
        $this->assertFalse($e instanceof MissingRuleHandlerException, 'D1: ambíguo não é "sem handler"');
        $e = $this->assertThrows(RuleEngineException::class, fn() => $registry->buildSpecification($rule));
        $this->assertTrue(str_contains($e->getMessage(), 'Ambiguous rule type') && str_contains($e->getMessage(), $handler::class), 'D1: ambíguo também no buildSpecification(), com o handler');
        $this->assertTrue((new DynamicSpecificationEngine(new InMemoryRuleCatalog([new RuleDefinition(code: 'o', name: 'O', ruleType: 'stage_other', scope: 's')]), $registry))->compileSpecification('s')->isTautology(), 'D1: outro tipo do handler segue normal');
    }

    /**
     * RN-02 (L1): over generated catalogs, compiling with registerLookupOnly() = compiling the catalog filtered
     * by the algorithm of the Evaluator of the Virttus (RentalGuaranteeContractManagerBusinessRuleEvaluator,
     * d4ef0f5, :81-96: keep the rules whose type has a handler, copy them into an InMemoryRuleCatalog, compile):
     * the same verdict, the same failures in the same order. An undeclared type always raises; a lookup-only
     * type with spec_json is compiled.
     */
    private function testLookupOnlyAgainstTheVirttusFilter(): void
    {
        mt_srand(20261019);
        $codec = new SpecificationCodec();
        $handlers = [
            // coded failures (the path of the Virttus) and raw ones (collapsed by RuleBoundSpecification)
            'h_max' => static fn(IRuleDefinition $r): ISpecification => Spec::property('a', Spec::lessThanOrEqualTo((int) $r->getIntValue()))->withCode($r->getCode())->because('max ' . $r->getCode()),
            'h_min' => static fn(IRuleDefinition $r): ISpecification => Spec::property('b', Spec::greaterThanOrEqualTo((int) $r->getIntValue())),
        ];
        $lookup = ['l_window', 'l_switch'];
        $compared = 0;
        for ($n = 0; $n < 120; $n++) {
            $rules = [];
            $count = mt_rand(1, 8);
            for ($i = 0; $i < $count; $i++) {
                $type = ['h_max', 'h_min', 'l_window', 'l_switch'][mt_rand(0, 3)];
                $document = str_starts_with($type, 'h_') && mt_rand(0, 4) === 0
                    ? $codec->toJson(Spec::property('a', Spec::lessThan(mt_rand(0, 9)))->withCode('DOC' . $i))
                    : null;
                $rules[] = new RuleDefinition(
                    code: sprintf('R%02d', $i),
                    name: 'Rule ' . $i,
                    ruleType: $type,
                    violationAction: [RuleAction::BLOCK, RuleAction::WARN, RuleAction::LOG][mt_rand(0, 2)],
                    intValue: mt_rand(0, 9),
                    priority: mt_rand(0, 3),
                    scope: ['s', null][mt_rand(0, 1)],
                    specJson: $document
                );
            }
            $facts = ['a' => mt_rand(0, 9), 'b' => mt_rand(0, 9)];

            $declared = new RuleSpecificationRegistry();
            foreach ($handlers as $type => $handler) {
                $declared->registerClosure($type, $handler);
            }
            $declared->registerLookupOnly(...$lookup);
            $new = new DynamicSpecificationEngine(new InMemoryRuleCatalog($rules), $declared);
            $newResult = $new->compileSpecification('s')->evaluate($facts);

            // --- Virttus Evaluator::evaluate(), :79-96 (transcribed) ---
            $rows = [];
            foreach ((new InMemoryRuleCatalog($rules))->findRules('s', null, []) as $rule) {
                if (isset($handlers[$rule->getRuleType()])) {   // tipo sem handler (só consulta): não avalia
                    $rows[$rule->getCode()] = $rule;
                }
            }
            $registry = Spec::ruleRegistry();
            foreach ($handlers as $type => $handler) {
                $registry->registerClosure($type, $handler);
            }
            $oldResult = $rows === []
                ? SpecificationResult::satisfied()
                : Spec::engine(new InMemoryRuleCatalog(array_values($rows)), $registry)->compileSpecification('s')->evaluate($facts);
            // --- /Virttus ---

            $this->assertEquals($oldResult->isSatisfied, $newResult->isSatisfied, "RN-02 L1 #{$n}: mesmo veredito");
            $this->assertEquals(self::failureShape($oldResult), self::failureShape($newResult), "RN-02 L1 #{$n}: mesmas falhas, mesma ordem");
            $skipped = array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $new->getSkippedRules());
            $expectedSkipped = [];
            foreach ((new InMemoryRuleCatalog($rules))->findRules('s', null, []) as $rule) {
                if (in_array($rule->getRuleType(), $lookup, true)) {
                    $expectedSkipped[] = $rule->getCode();
                }
            }
            $this->assertEquals($expectedSkipped, $skipped, "RN-02 L1 #{$n}: puladas = as só consulta, na ordem");
            $compared++;

            // An undeclared type always raises
            $withTypo = [...$rules, new RuleDefinition(code: 'TYPO', name: 'Typo', ruleType: 'l_windw', scope: 's')];
            $this->assertThrows(MissingRuleHandlerException::class, fn() => (new DynamicSpecificationEngine(new InMemoryRuleCatalog($withTypo), $declared))->compileSpecification('s'));
            // A lookup-only type with a document is compiled
            $withDocument = [...$rules, new RuleDefinition(code: 'LDOC', name: 'Lookup doc', ruleType: 'l_switch', scope: 's', specJson: $codec->toJson(Spec::property('a', Spec::lessThan(0))->withCode('LDOC')))];
            $this->assertTrue(in_array('LDOC', (new DynamicSpecificationEngine(new InMemoryRuleCatalog($withDocument), $declared))->compileSpecification('s')->evaluate($facts)->getCodes(), true), "RN-02 L1 #{$n}: só consulta com spec_json valida");
        }
        $this->assertEquals(120, $compared);
    }

    ///////////////////////////////////////////////////////////////////////////
    // L2: the most specific rule
    ///////////////////////////////////////////////////////////////////////////

    private function testPrecedenceOrder(): void
    {
        $rule = static fn(string $code, ?string $scenario = null, int $priority = 0, ?string $product = null, ?string $plan = null, string $type = 't', bool $active = true): RuleDefinition => new RuleDefinition(
            code: $code,
            name: $code,
            ruleType: $type,
            priority: $priority,
            scope: 's',
            scenario: $scenario,
            parameters: array_filter(['product_code' => $product, 'plan_code' => $plan], static fn($v): bool => $v !== null),
            active: $active
        );
        $pick = static fn(array $rules, ?string $scenario = 'x'): ?string => RulePrecedence::mostSpecific($rules, 't', $scenario)?->getCode();

        $this->assertEquals('S', $pick([$rule('G', null, 9), $rule('S', 'x', 0)]), 'D5 (1): cenário exato vence genérica de prioridade maior');
        $this->assertEquals('G', $pick([$rule('G', null, 0), $rule('Y', 'y', 9)]), 'D5 (1): outro cenário nunca vale');
        $this->assertEquals('G', $pick([$rule('G', ''), $rule('S', 'x')], null), 'D5: sem cenário, só as genéricas');
        $this->assertEquals('G', $pick([$rule('G', ''), $rule('S', 'x')], ''), "D5: cenário '' = sem cenário");
        $this->assertEquals('PL', $pick([$rule('PR', null, 9, 'P'), $rule('PL', null, 0, 'P', 'L'), $rule('N', null, 9)]), 'D5 (2): plano > produto > nada');
        $this->assertEquals('PR', $pick([$rule('PR', null, 0, 'P'), $rule('N', null, 9)]), 'D5 (2): produto > nada');
        $this->assertEquals('S2', $pick([$rule('S1', 'x', 0, 'P', 'L'), $rule('S2', 'x', 1, 'P', 'L')]), 'D5 (3): prioridade dentro do nível');
        $this->assertEquals('A', $pick([$rule('B', 'x', 1), $rule('A', 'x', 1)]), 'D5 (4): code em ordem de bytes');
        $this->assertEquals('B', $pick([$rule('a', 'x', 1), $rule('B', 'x', 1)]), "D5 (4): strcmp ('B' < 'a')");
        $this->assertEquals('10', $pick([$rule('9', 'x'), $rule('10', 'x')]), "D5 (4): strcmp, nunca numérico ('10' < '9')");
        $this->assertEquals(null, $pick([$rule('I', 'x', 9, null, null, 't', false), $rule('O', 'x', 9, null, null, 'u')]), 'D5: inativa e outro tipo ignoradas');
        $this->assertEquals(null, $pick([]), 'D5: nada');
        $this->assertTrue(RulePrecedence::compare($rule('S', 'x'), $rule('G')) < 0 && RulePrecedence::compare($rule('G'), $rule('S', 'x')) > 0, 'D5: compare() antissimétrico');
        $this->assertEquals(0, RulePrecedence::compare($rule('A', 'x'), $rule('A', 'x')), 'D5: compare() reflexivo');

        $rules = [$rule('G'), $rule('X1', 'x', 1), $rule('X2', 'x', 2), $rule('Y', 'y'), $rule('Z', 'z', 0, null, null, 'u'), $rule('W', 'w', 0, null, null, 't', false)];
        $this->assertEquals(['x', 'y'], RulePrecedence::scenariosOf($rules, 't'), 'D5: cenários do tipo, ativos, em ordem de bytes');
        $this->assertEquals(['x' => 'X2', 'y' => 'Y'], array_map(static fn(IRuleDefinition $r): string => $r->getCode(), RulePrecedence::byScenario($rules, 't')), 'D5: byScenario sem as genéricas');
        $this->assertEquals(['1', '2'], RulePrecedence::scenariosOf([$rule('A', '2'), $rule('B', '1')], 't'), 'D5: cenários numéricos continuam texto');
        $generator = (static function () use ($rules) {
            yield from $rules;
        })();
        $this->assertEquals(['x', 'y'], array_keys(RulePrecedence::byScenario($generator, 't')), 'D5: aceita iterável');
    }

    private function testRuleTypeCatalogInMemory(): void
    {
        $catalog = new InMemoryRuleCatalog([
            new RuleDefinition(code: 'b', name: 'b', ruleType: 't', priority: 1, scope: 's'),
            new RuleDefinition(code: 'a', name: 'a', ruleType: 't', priority: 1, scope: 's', scenario: 'x'),
            new RuleDefinition(code: 'c', name: 'c', ruleType: 't', priority: 5, scope: null),
            new RuleDefinition(code: 'd', name: 'd', ruleType: 'u', priority: 9, scope: 's'),
            new RuleDefinition(code: 'e', name: 'e', ruleType: 't', priority: 9, scope: 'other'),
            new RuleDefinition(code: 'f', name: 'f', ruleType: 't', priority: 9, scope: 's', active: false),
            new RuleDefinition(code: 'g', name: 'g', ruleType: 't', priority: 9, scope: 's', parameters: ['product_code' => 'P']),
        ]);
        $codes = static fn(array $rules): array => array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $rules);
        $this->assertEquals(['c', 'a', 'b'], $codes($catalog->findRulesByType('s', 't')), 'D4: todos os cenários, prioridade e code');
        $this->assertEquals(['g', 'c', 'a', 'b'], $codes($catalog->findRulesByType('s', 't', ['product_code' => 'P'])), 'D4: filtros de aplicabilidade');
        $this->assertEquals([], $catalog->findRulesByType('s', 'none'));
        $this->assertThrows(\Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException::class, fn() => $catalog->findRulesByType('s', 't', ['codigo_produto' => 'P']), 'D4: chave legada recusada');
        $this->assertEquals(['d', 'c', 'b'], $codes($catalog->findRules('s')), 'D6: findRules() intacto (todos os tipos, a ordem de hoje)');
        $this->assertEquals('a', RulePrecedence::findRule($catalog, 's', 't', 'x')?->getCode(), 'D5: findRule sobre IRuleTypeCatalog');

        // A catalog without IRuleTypeCatalog: findRules() filtered by type, the same answer
        $plain = new class ($catalog) implements IRuleCatalog {
            public int $calls = 0;

            public function __construct(private readonly InMemoryRuleCatalog $inner)
            {
            }

            public function findRules(string $scope, ?string $scenario = null, array $filters = []): array
            {
                $this->calls++;

                return $this->inner->findRules($scope, $scenario, $filters);
            }

            public function findDocumentRules(string $scope, ?string $scenario = null): array
            {
                return [];
            }

            public function findRuleByCode(string $code): ?IRuleDefinition
            {
                return $this->inner->findRuleByCode($code);
            }
        };
        foreach (['x', 'y', null] as $scenario) {
            $this->assertEquals(RulePrecedence::findRule($catalog, 's', 't', $scenario)?->getCode(), RulePrecedence::findRule($plain, 's', 't', $scenario)?->getCode(), 'D5: catálogo sem IRuleTypeCatalog responde igual');
        }
        $this->assertEquals(3, $plain->calls, 'D5: o caminho de findRules() foi usado');
    }

    /**
     * RN-02 (L2) over a PDO catalog (SQLite in the suite; PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 in
     * run_portability.sh): generated rules (scenarios, product and plan, priorities, codes, validity);
     * findRule() over InMemory = over PDO = over a catalog without IRuleTypeCatalog = brute force (below,
     * outside the library), whatever the order of insertion; findRulesByType() in one query and in the same
     * order in both; byScenario() = findRule() per scenario; without product and plan, the first() of the
     * Virttus (transcribed) over the PDO catalog.
     */
    private function testRuleTypeCatalogOverPdo(PDO $pdo, string $family, bool $recording): void
    {
        mt_srand(4801 + crc32($family));
        $dates = [null, '2026-01-01', '2026-06-01', '2026-12-31'];
        $codes = range(1, 60);
        shuffle($codes);
        $rules = [];
        foreach ($codes as $n) {
            $product = [null, 'P1', 'P2'][mt_rand(0, 2)];
            $from = $dates[mt_rand(0, 2)];
            $until = $dates[mt_rand(0, 3)];
            if ($from !== null && $until !== null && $until < $from) {
                [$from, $until] = [$until, $from];
            }
            $rules[] = [
                'code' => sprintf('R%02d', $n) . ['', 'a', 'B'][mt_rand(0, 2)],
                'rule_type' => ['t1', 't2'][mt_rand(0, 1)],
                'scope' => ['claim', null, 'other'][mt_rand(0, 2)],
                'scenario' => [null, 'occupied', 'vacant', 'occupied'][mt_rand(0, 3)],
                'product_code' => $product,
                'plan_code' => $product !== null && mt_rand(0, 2) === 0 ? 'PL1' : null,
                'priority' => mt_rand(0, 3),
                'valid_from' => $from,
                'valid_until' => $until,
                'active' => mt_rand(0, 9) === 0 ? 'N' : 'Y',
            ];
        }
        $pdo->exec('DELETE FROM business_rule');
        $insert = $pdo->prepare('INSERT INTO business_rule (code, name, rule_type, scope, scenario, product_code, plan_code, priority, valid_from, valid_until, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rules as $r) {
            $insert->execute([$r['code'], $r['code'], $r['rule_type'], $r['scope'], $r['scenario'], $r['product_code'], $r['plan_code'], $r['priority'], $r['valid_from'], $r['valid_until'], $r['active']]);
        }
        $pdoCatalog = new PdoRuleCatalog($pdo);
        $definitions = array_map(static fn(array $r): RuleDefinition => RuleDefinition::fromArray($r + ['name' => $r['code']]), $rules);

        $checked = 0;
        foreach ([[], ['product_code' => 'P1'], ['product_code' => 'P1', 'plan_code' => 'PL1'], ['product_code' => 'P2', 'reference_date' => '2026-07-01'], ['reference_date' => '2026-03-01']] as $filters) {
            foreach (['claim', 'other'] as $scope) {
                foreach (['t1', 't2'] as $type) {
                    $memoryList = (new InMemoryRuleCatalog($definitions))->findRulesByType($scope, $type, $filters);
                    if ($recording && $pdo instanceof PdoRecordingPdo) {
                        $pdo->reset();
                    }
                    $pdoList = $pdoCatalog->findRulesByType($scope, $type, $filters);
                    if ($recording && $pdo instanceof PdoRecordingPdo) {
                        $this->assertCount(1, $pdo->statements, 'D4: uma consulta');
                        $this->assertTrue(str_contains($pdo->statements[0], 'rule_type = :rule_type') && !str_contains($pdo->statements[0], ':scenario'), 'D4: por tipo, sem predicado de cenário');
                    }
                    $this->assertEquals(self::codes($memoryList), self::codes($pdoList), "D4 {$family}: InMemory = PDO, mesma ordem");

                    foreach ([null, 'occupied', 'vacant', 'none'] as $scenario) {
                        $expected = self::bruteForceMostSpecific($rules, $scope, $type, $scenario, $filters);
                        $this->assertEquals($expected, RulePrecedence::findRule(new InMemoryRuleCatalog($definitions), $scope, $type, $scenario, $filters)?->getCode(), "RN-02 L2 {$family}: InMemory = força bruta ({$scope}/{$type}/{$scenario})");
                        $this->assertEquals($expected, RulePrecedence::findRule($pdoCatalog, $scope, $type, $scenario, $filters)?->getCode(), "RN-02 L2 {$family}: PDO = força bruta");
                        $shuffled = $definitions;
                        shuffle($shuffled);
                        $this->assertEquals($expected, RulePrecedence::findRule(new InMemoryRuleCatalog($shuffled), $scope, $type, $scenario, $filters)?->getCode(), "RN-02 L2 {$family}: independe da ordem de inserção");
                        $this->assertEquals($expected, RulePrecedence::mostSpecific($pdoCatalog->findRules($scope, $scenario === 'none' ? 'none' : $scenario, $filters), $type, $scenario)?->getCode(), "RN-02 L2 {$family}: via findRules() filtrado");
                        $checked++;
                    }
                    $byScenario = array_map(static fn(IRuleDefinition $r): string => $r->getCode(), RulePrecedence::byScenario($pdoList, $type));
                    $perScenario = [];
                    foreach (RulePrecedence::scenariosOf($pdoList, $type) as $scenario) {
                        $perScenario[$scenario] = RulePrecedence::findRule($pdoCatalog, $scope, $type, $scenario, $filters)?->getCode();
                    }
                    $this->assertEquals($perScenario, $byScenario, "RN-02 L2 {$family}: byScenario = findRule por cenário");

                    if ($filters === []) {
                        foreach ([null, 'occupied', 'vacant'] as $scenario) {
                            // --- Virttus RuleCatalog::first(), :51-64 (transcribed; rules() = findRules() of the PDO catalog) ---
                            $generic = null;
                            $first = null;
                            foreach ($pdoCatalog->findRules($scope, $scenario, []) as $candidate) {
                                if ($candidate->getRuleType() !== $type) {
                                    continue;
                                }
                                if ($scenario !== null && $candidate->getScenario() === $scenario) {
                                    $first = $candidate;
                                    break;
                                }
                                $generic = $generic ?? $candidate;
                            }
                            $first ??= $generic;
                            // --- /Virttus ---
                            $this->assertEquals($first?->getCode(), RulePrecedence::findRule($pdoCatalog, $scope, $type, $scenario)?->getCode(), "RN-02 L2 {$family}: sem produto e plano = first() da Virttus");
                        }
                        // --- Virttus RuleCatalog::byScenario(), :81-97 (transcribed): 1 + N reads ---
                        $st = $pdo->prepare("SELECT DISTINCT scenario FROM business_rule WHERE scope = ? AND rule_type = ? AND active = 'Y' AND scenario IS NOT NULL");
                        $st->execute([$scope, $type]);
                        $virttus = [];
                        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $scenario) {
                            $r = RulePrecedence::mostSpecific($pdoCatalog->findRules($scope, (string) $scenario, []), $type, (string) $scenario);
                            if ($r && $r->getScenario() === $scenario) {
                                $virttus[(string) $scenario] = $r->getCode();
                            }
                        }
                        ksort($virttus);
                        // --- /Virttus ---
                        $ours = array_map(static fn(IRuleDefinition $r): string => $r->getCode(), RulePrecedence::byScenario($pdoCatalog->findRulesByType($scope, $type), $type));
                        foreach ($ours as $scenario => $code) {
                            if (!isset($virttus[$scenario])) {
                                // D5/interpretation: a scenario only global-scope rules have (scope NULL) is listed here; the
                                // SQL of the Virttus filters `scope = ?` exactly
                                $origin = array_filter($rules, static fn(array $r): bool => $r['scenario'] === $scenario && $r['rule_type'] === $type && $r['active'] === 'Y' && $r['scope'] === $scope);
                                $this->assertEquals([], $origin, "RN-07 (b) {$family}: cenário {$scenario} só de regra global");
                                unset($ours[$scenario]);
                            }
                        }
                        $this->assertEquals($virttus, $ours, "RN-07 (b) {$family}: byScenario() = o da Virttus, com uma leitura");
                    }
                }
            }
        }
        $this->assertEquals(5 * 2 * 2 * 4, $checked);
    }

    /**
     * The most specific applicable rule by brute force, written apart from the library: the applicability
     * of IRuleCatalog (active, scope, product, plan, validity by day) and the precedence of D5.
     *
     * @param list<array<string, mixed>> $rules
     * @param array<string, string> $filters
     */
    private static function bruteForceMostSpecific(array $rules, string $scope, string $type, ?string $scenario, array $filters): ?string
    {
        $candidates = [];
        foreach ($rules as $r) {
            $ruleScenario = $r['scenario'] ?? '';
            if ($r['active'] !== 'Y' || $r['rule_type'] !== $type || !in_array($r['scope'], [null, '', $scope], true)) {
                continue;
            }
            if ($ruleScenario !== '' && $ruleScenario !== $scenario) {
                continue;
            }
            if ($r['product_code'] !== null && $r['product_code'] !== ($filters['product_code'] ?? null)) {
                continue;
            }
            if ($r['plan_code'] !== null && $r['plan_code'] !== ($filters['plan_code'] ?? null)) {
                continue;
            }
            $day = $filters['reference_date'] ?? null;
            if ($day !== null && (($r['valid_from'] !== null && $r['valid_from'] > $day) || ($r['valid_until'] !== null && $r['valid_until'] < $day))) {
                continue;
            }
            $level = $r['plan_code'] !== null ? 2 : ($r['product_code'] !== null ? 1 : 0);
            $candidates[] = [$ruleScenario !== '' ? 1 : 0, $level, $r['priority'], $r['code']];
        }
        usort($candidates, static function (array $a, array $b): int {
            foreach ([0, 1, 2] as $i) {
                if ($a[$i] !== $b[$i]) {
                    return $b[$i] - $a[$i];
                }
            }

            return strcmp($a[3], $b[3]);
        });

        return $candidates[0][3] ?? null;
    }

    /**
     * RN-03: each property has its broken control, run here, so that a regression of that kind cannot pass:
     * a registry that skips an undeclared type (L1), a precedence that ignores the scenario (L2), a source
     * without a store of tickets (L10). The controls of L5 (the raw subselect) and of L4 (folding only the
     * column with unaccent) run with their batteries.
     */
    private function testBrokenControls(): void
    {
        // L1: a registry that treats every type without handler as lookup only lets the typo through
        $broken = new class extends RuleSpecificationRegistry {
            public function isLookupOnly(string $ruleType): bool
            {
                return !$this->hasHandler($ruleType);
            }
        };
        $typo = new InMemoryRuleCatalog([new RuleDefinition(code: 'typo', name: 'Typo', ruleType: 'stage_deadlin', scope: 's')]);
        $this->assertTrue((new DynamicSpecificationEngine($typo, $broken))->validate([], 's')->isSatisfied(), 'RN-03 L1: o registro quebrado aprova o tipo digitado errado');
        $this->assertThrows(MissingRuleHandlerException::class, fn() => (new DynamicSpecificationEngine($typo, (new RuleSpecificationRegistry())->registerLookupOnly('stage_deadline')))->validate([], 's'), 'RN-03 L1: o registro certo recusa');

        // L2: a precedence that ignores the scenario picks the generic rule of higher priority
        $rules = [
            new RuleDefinition(code: 'G', name: 'G', ruleType: 't', priority: 9, scope: 's'),
            new RuleDefinition(code: 'S', name: 'S', ruleType: 't', priority: 0, scope: 's', scenario: 'x'),
        ];
        $ignoringScenario = $rules;
        usort($ignoringScenario, static fn(IRuleDefinition $a, IRuleDefinition $b): int => ($b->getPriority() <=> $a->getPriority()) ?: strcmp($a->getCode(), $b->getCode()));
        $this->assertEquals('G', $ignoringScenario[0]->getCode(), 'RN-03 L2: a precedência quebrada');
        $this->assertEquals('S', RulePrecedence::mostSpecific($rules, 't', 'x')?->getCode(), 'RN-03 L2: a certa');

        // L10: a source without a store of tickets submits at every resolution
        $bureau = new Module36Bureau(['pending', 'pending', 'done']);
        $naive = new \Antevemus\ASpecification\Resolution\CallableDataSource(static function (array $p, DataRequest $r) use ($bureau): int {
            $ticket = $bureau->createAnalysis((string) $p['cpf'], $r->getKey());
            if (!$bureau->isDone($ticket)) {
                throw new DataPendingException('naive');
            }

            return $bureau->score($ticket);
        });
        $resolver = new DataResolver((new DataSourceRegistry())->register('m36.naive', $naive));
        foreach ([1, 2, 3] as $attempt) {
            $resolver->evaluate(Spec::external('m36.naive', ['cpf' => 'cpf'], Spec::greaterThan(0)), (object) ['cpf' => '1']);
        }
        $this->assertEquals(3, $bureau->created, 'RN-03 L10: sem store de ticket, um envio por resolução');
    }

    /**
     * Fix of 2.5.0 (the side finding of the forward): the constant conditions are TConstantFilter, plain
     * `1 = 1` / `1 = 0` in both modes, with nothing bound.
     */
    private function testConstantFilterRendering(): void
    {
        foreach ([[TConstantFilter::alwaysTrue(), '1 = 1'], [TConstantFilter::alwaysFalse(), '1 = 0']] as [$filter, $text]) {
            $this->assertEquals([$text, $text, []], [$filter->dump(), $filter->dump(true), $filter->getPreparedVars()], "fix: {$text} nos dois modos, sem variável");
        }
        $this->assertEquals([['1', '=', 1], ['1', '=', 0]], [
            [TConstantFilter::alwaysTrue()->getVariable(), TConstantFilter::alwaysTrue()->getOperator(), TConstantFilter::alwaysTrue()->getValue()],
            [TConstantFilter::alwaysFalse()->getVariable(), TConstantFilter::alwaysFalse()->getOperator(), TConstantFilter::alwaysFalse()->getValue()],
        ], 'fix: os campos do TFilter, os de antes');
        foreach ([
            ['(1 = 1)', Spec::alwaysTrue()],
            ['(1 = 0)', Spec::alwaysFalse()],
            ['(1 = 1)', Spec::not(Spec::alwaysFalse())],
            ['(1 = 0)', Spec::not(Spec::alwaysTrue())],
            ['(1 = 0)', Spec::property('x', Spec::in())],
            ['(x IS NOT NULL AND 1 = 1)', Spec::property('x', Spec::notIn())],
            ['((1 = 0 OR x = :p))', Spec::alwaysFalse()->or(Spec::property('x', Spec::equalTo(2)))],
        ] as [$expected, $spec]) {
            $criteria = Spec::toCriteria($spec);
            $prepared = (string) preg_replace('/:par_\d+/', ':p', (string) $criteria->dump(true));
            $vars = (array) $criteria->getPreparedVars();
            $this->assertEquals($expected, $prepared, "fix: com prepared {$expected}");
            $this->assertEquals(str_contains($expected, ':p') ? 1 : 0, count($vars), "fix: nenhuma variável para a constante ({$expected})");
            $this->assertEquals(str_replace(':p', '2', $expected), $criteria->dump(), "fix: sem prepared {$expected}");
        }
    }

    /**
     * Fix of 2.5.0: executed with `prep = 1` on SQLite (the values of execute() are text), through the REAL Adianti
     * 8.1 and 8.7 and the stub, the tautology selects every row and the contradiction none; the control, the
     * TFilter('1', '=', 1) of up to 2.4.0, selects none (the defect).
     */
    private function testConstantConditionsOnSqlite(): void
    {
        $pdo = $this->sqliteEmpty();
        $pdo->exec('CREATE TABLE t_const (id INT PRIMARY KEY, x INT NULL)');
        $pdo->exec('INSERT INTO t_const VALUES (1, 1), (2, 2), (3, NULL)');
        $expected = [
            'alwaysTrue' => [1, 2, 3], 'notAlwaysFalse' => [1, 2, 3], 'alwaysFalse' => [], 'notAlwaysTrue' => [],
            'inEmpty' => [], 'notInEmpty' => [1, 2], 'trueOrFalse' => [2], 'raw' => [],
        ];
        $cases = [];
        foreach (array_keys($expected) as $id) {
            $cases[$id] = ['type' => 'constant', 'id' => $id];
        }
        $real = $this->realAdiantiDumps($cases);
        foreach ($expected as $id => $rows) {
            $spec = Module36Cases::constantSpec($id);
            if ($spec === null) {
                $criteria = new TCriteria();
                $criteria->add(new TFilter('1', '=', 1));
            } else {
                $criteria = Spec::toCriteria($spec);
            }
            $this->assertEquals($rows, $this->ids($pdo, 't_const', (string) $criteria->dump(true), (array) $criteria->getPreparedVars()), "fix stub {$id}");
            foreach ($real as $version => $dumps) {
                $this->assertEquals($rows, $this->ids($pdo, 't_const', $dumps[$id]['prepared'], $dumps[$id]['vars']), "fix Adianti {$version} {$id}: " . $dumps[$id]['prepared']);
            }
        }
        $this->assertEquals([1, 2, 3], $this->ids($pdo, 't_const', '1 = 1', []), 'controle: 1 = 1 em texto seleciona tudo');
        $this->assertEquals([], $this->ids($pdo, 't_const', '1 = :p', [':p' => 1]), 'controle: 1 = :p ligado como texto não seleciona nada no SQLite (o defeito)');
    }

    /**
     * RN-07 (d): five single-table subqueries of the Virttus, as written there (`NOESC:(SELECT ...)`) and as
     * Spec::inSubquery(), dumped by the TCriteria of the REAL Adianti 8.1 (and 8.7, and the stub) and executed
     * on a SQLite fixture with the schemas attached: the same rows.
     */
    private function testVirttusSubqueriesAcceptance(): void
    {
        $pdo = $this->sqliteEmpty();
        foreach (['aflow-engine', 'rental_guarantee_contract_manager', 'pessoa'] as $schema) {
            $pdo->exec("ATTACH DATABASE ':memory:' AS \"{$schema}\"");
        }
        $pdo->exec('CREATE TABLE t_outer (id INT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE "aflow-engine".module_states (state_id INT NULL, module_id INT NULL, deleted_at TEXT NULL)');
        $pdo->exec('CREATE TABLE rental_guarantee_contract_manager.business_consultant_units (business_consultant_id INT NULL, system_unit_id INT NULL, deleted_at TEXT NULL)');
        $pdo->exec('CREATE TABLE rental_guarantee_contract_manager.contract_parties (pessoa_id INT NULL, system_unit_id INT NULL, deleted_at TEXT NULL)');
        $pdo->exec('CREATE TABLE pessoa.pessoa_documento (pessoa_id INT NULL, numero TEXT NULL, active TEXT NULL)');
        $pdo->exec('CREATE TABLE rental_guarantee_contract_manager.rental_units (system_unit_id INT NULL)');
        mt_srand(4815);
        for ($id = 1; $id <= 20; $id++) {
            $pdo->exec("INSERT INTO t_outer VALUES ({$id})");
        }
        $deleted = static fn(): string => mt_rand(0, 3) === 0 ? "'2026-01-01'" : 'NULL';
        for ($n = 0; $n < 40; $n++) {
            $pdo->exec(sprintf('INSERT INTO "aflow-engine".module_states VALUES (%s, %d, %s)', mt_rand(0, 9) === 0 ? 'NULL' : (string) mt_rand(1, 20), mt_rand(1, 4), $deleted()));
            $pdo->exec(sprintf('INSERT INTO rental_guarantee_contract_manager.business_consultant_units VALUES (%d, %d, %s)', mt_rand(1, 20), mt_rand(1, 3), $deleted()));
            $pdo->exec(sprintf('INSERT INTO rental_guarantee_contract_manager.contract_parties VALUES (%d, %d, %s)', mt_rand(1, 20), mt_rand(1, 3), $deleted()));
            $pdo->exec(sprintf("INSERT INTO pessoa.pessoa_documento VALUES (%d, '%s', '%s')", mt_rand(1, 20), ['123.456', 'ab-77', 'XY99', '0042'][mt_rand(0, 3)] . mt_rand(0, 9), mt_rand(0, 4) === 0 ? 'N' : 'Y'));
            if ($n % 3 === 0) {
                $pdo->exec(sprintf('INSERT INTO rental_guarantee_contract_manager.rental_units VALUES (%d)', mt_rand(1, 20)));
            }
        }
        $cases = [];
        foreach (['module_states' => [1, 2, 3, 4], 'consultant_units' => [1, 2, 3], 'contract_parties' => [1, 3], 'pessoa_documento' => ['456', 'ab-7', 'xy9', '0042'], 'rental_units' => [0]] as $id => $values) {
            foreach ($values as $value) {
                foreach (['raw', 'spec'] as $form) {
                    $cases["{$id} {$value} {$form}"] = ['type' => 'virttus', 'id' => $id, 'value' => $value, 'form' => $form];
                }
            }
        }
        $real = $this->realAdiantiDumps($cases);
        $compared = 0;
        foreach ($cases as $label => $case) {
            if ($case['form'] !== 'spec') {
                continue;
            }
            $rawLabel = substr($label, 0, -4) . 'raw';
            [$column, $raw, $spec] = Module36Cases::virttusSubquery($case);
            $stubRaw = new TCriteria();
            $stubRaw->add(new TFilter($column, 'IN', $raw));
            $expected = $this->ids($pdo, 't_outer', (string) $stubRaw->dump(true), (array) $stubRaw->getPreparedVars());
            $stub = Spec::toCriteria($spec);
            $this->assertEquals($expected, $this->ids($pdo, 't_outer', (string) $stub->dump(true), (array) $stub->getPreparedVars()), "RN-07 (d) stub {$label}");
            $sql = Spec::toSql($spec, 'sqlite');
            $this->assertEquals($expected, $this->ids($pdo, 't_outer', $sql->getSql(), $sql->getParameters()), "RN-07 (d) toSql {$label}");
            foreach ($real as $version => $dumps) {
                $this->assertEquals($expected, $this->ids($pdo, 't_outer', $dumps[$rawLabel]['prepared'], $dumps[$rawLabel]['vars']), "RN-07 (d) Adianti {$version} original {$label}");
                $this->assertEquals($expected, $this->ids($pdo, 't_outer', $dumps[$label]['prepared'], $dumps[$label]['vars']), "RN-07 (d) Adianti {$version} inSubquery {$label}");
                $this->assertTrue($dumps[$label]['vars'] !== [] || $case['id'] === 'rental_units', "RN-07 (d) {$label}: os valores ligados (rental_units não tem valor)");
                $this->assertFalse(str_contains($dumps[$label]['prepared'], (string) $case['value']) && $case['id'] === 'pessoa_documento', "RN-07 (d) {$label}: o termo não entra no texto");
            }
            $compared++;
        }
        $this->assertEquals(14, $compared);
    }

    ///////////////////////////////////////////////////////////////////////////
    // L3: the label of a target
    ///////////////////////////////////////////////////////////////////////////

    private function testLabelledTargets(): void
    {
        $occ = Spec::property('occ', Spec::lessThan(2))->withCode('max_occ')->because('Too many');
        $labelled = $occ->labelled('Ongoing: 3 (maximum 2)');
        $this->assertInstanceOf(LabelledSpecification::class, $labelled);
        $this->assertEquals('Ongoing: 3 (maximum 2)', $labelled->getLabel());
        $this->assertTrue($labelled->getInnerSpecification() === $occ);
        $this->assertTrue($labelled->evaluate((object) ['occ' => 1])->isSatisfied, 'D8: satisfeito passa intacto');
        $failure = $labelled->evaluate((object) ['occ' => 3])->failures[0];
        $this->assertEquals('Ongoing: 3 (maximum 2)', $failure->property, 'D8: property = rótulo');
        $this->assertEquals('Ongoing: 3 (maximum 2)', $failure->metadata['label'], 'D8: metadata[label]');
        $this->assertEquals('occ', $failure->metadata['property_path'], 'D8: o nome técnico não se perde');
        $this->assertEquals(['max_occ', 'Too many'], [$failure->code, $failure->message], 'D8: mensagem e código intactos');
        $this->assertTrue(Spec::labelled('Ongoing: 3 (maximum 2)', $occ)->equals($labelled), 'D7: Spec::labelled() = labelled()');
        $this->assertFalse($labelled->equals($occ->labelled('Other')), 'D8: rótulo diferente, especificação diferente');
        $this->assertFalse($labelled->equals($occ), 'D8: não é o interno');
        $this->assertEquals('Labelled[x](' . (string) Spec::alwaysTrue() . ')', (string) Spec::labelled('x', Spec::alwaysTrue()));
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::labelled(" \t", Spec::alwaysTrue()), 'D7: rótulo em branco recusado');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::alwaysTrue()->labelled(''), 'D7: rótulo vazio recusado');

        // A failure with the same property as the label: no property_path
        $same = Spec::property('occ', Spec::lessThan(2))->labelled('occ')->evaluate((object) ['occ' => 5])->failures[0];
        $this->assertFalse(isset($same->metadata['property_path']), 'D8: property igual ao rótulo não vira property_path');
        $leaf = Spec::lessThan(2)->labelled('Value')->evaluate(5)->failures[0];
        $this->assertTrue($leaf->property === 'Value' && !isset($leaf->metadata['property_path']), 'D8: falha sem property só ganha o rótulo');

        // D9: nested labels, the innermost wins
        $group = Spec::allOf(
            Spec::property('a', Spec::lessThan(1))->labelled('A'),
            Spec::property('b', Spec::lessThan(1))->labelled('B'),
            Spec::property('c', Spec::lessThan(1))
        )->labelled('Group');
        $failures = $group->evaluate((object) ['a' => 5, 'b' => 5, 'c' => 5])->failures;
        $this->assertEquals(['A', 'B', 'Group'], array_map(static fn(SpecificationFailure $f): ?string => $f->property, $failures), 'D9: o de dentro vence');
        $this->assertEquals(['a', 'b', 'c'], array_map(static fn(SpecificationFailure $f): ?string => $f->metadata['property_path'] ?? null, $failures), 'D9: caminhos preservados');
        $this->assertEquals('Inner', Spec::labelled('Outer', Spec::labelled('Inner', Spec::lessThan(1)))->evaluate(5)->failures[0]->property, 'D9: aninhado direto');

        // D10: because()/withCode() on the decorator, an error never absorbed
        $annotated = Spec::property('a', Spec::lessThan(1))->labelled('L')->because('Reason')->withCode('C');
        $f = $annotated->evaluate((object) ['a' => 5])->failures[0];
        $this->assertEquals(['Reason', 'C', 'L', 'a'], [$f->message, $f->code, $f->property, $f->metadata['property_path']], 'D10: mensagem e código do decorador');
        $error = $annotated->evaluate((object) ['b' => 1]);
        $this->assertTrue($error->isError && !$error->isSatisfied, 'D10: erro continua erro');
        $this->assertEquals([SpecificationResult::EVALUATION_ERROR_MESSAGE, null, 'L'], [$error->failures[0]->message, $error->failures[0]->code, $error->failures[0]->property], 'D10: o erro não é absorvido pela anotação, mas é rotulado');
        $this->assertEquals('a', $error->failures[0]->metadata['property_path'], 'D10: o erro guarda o caminho');
        $this->assertTrue($annotated->not()->evaluate((object) ['b' => 1])->isError, 'D10: NOT não inverte o erro');
        $throwing = new class extends \Antevemus\ASpecification\AbstractSpecification {
            public function getType(): string
            {
                return 'mixed';
            }

            public function isSatisfiedBy(mixed $candidate): bool
            {
                return true;
            }

            public function evaluate(mixed $candidate): SpecificationResult
            {
                throw new RuntimeException('raised');
            }
        };
        $raised = Spec::labelled('L', $throwing)->withCode('X')->evaluate(1);
        $this->assertTrue($raised->isError && $raised->exception instanceof RuntimeException && $raised->failures[0]->property === 'L' && $raised->failures[0]->code === 'X', 'D10: evaluate() que lança vira erro rotulado');

        // D8/D11: answer, algebra, visitors, simplify, codec
        $this->assertTrue(Spec::labelled('L', Spec::isNull())->isSatisfiedBy(null), 'D8: aceita o que o interno aceita (null)');
        $this->assertTrue(Spec::isTautology(Spec::labelled('L', Spec::alwaysTrue())) && Spec::isContradiction(Spec::labelled('L', Spec::alwaysFalse())), 'D8: tautologia e contradição do interno');
        $this->assertTrue(Spec::greaterThan(5)->isGeneralizationOf(Spec::labelled('L', Spec::greaterThan(10))), 'D11: a álgebra desembrulha o outro lado');
        $this->assertTrue(Spec::labelled('L', Spec::greaterThan(5))->isGeneralizationOf(Spec::greaterThan(10)), 'D11: e o próprio');
        $this->assertTrue(Spec::labelled('L', Spec::greaterThan(10))->isSpecialCaseOf(Spec::greaterThan(5)), 'D11: caso especial');
        $this->assertTrue(Spec::labelled('L', Spec::greaterThan(5))->isDisjointWith(Spec::labelled('M', Spec::lessThan(3))), 'D11: disjunção');
        $this->assertFalse(Spec::labelled('L', Spec::greaterThan(5))->isDisjointWith(Spec::lessThan(7)), 'D11: interseção');
        $this->assertTrue(Spec::not(Spec::labelled('L', Spec::greaterThan(5)))->isGeneralizationOf(Spec::lessThan(3)), 'D11: negação desembrulha');
        $this->assertFalse(Spec::isContradiction(Spec::labelled('L', Spec::greaterThan(5))->and(Spec::lessThan(7))), 'D11: conjunção satisfatível');
        $this->assertTrue(Spec::isContradiction(Spec::labelled('L', Spec::greaterThan(5))->and(Spec::labelled('M', Spec::lessThan(3)))), 'D11: conjunção contraditória vista pelos rótulos');

        $tree = Spec::property('name', Spec::startsWith('Ab'))->and(Spec::property('age', Spec::greaterThan(18)));
        $this->assertEquals(Spec::toSql($tree, 'pgsql')->toSql(), Spec::toSql($tree->labelled('Who'), 'pgsql')->toSql(), 'D8: toSql ignora o rótulo');
        $this->assertEquals(Spec::toCriteria($tree)->dump(), Spec::toCriteria($tree->labelled('Who'))->dump(), 'D8: toCriteria ignora o rótulo');
        $this->assertEquals(Spec::toCriteria(Spec::not($tree))->dump(), Spec::toCriteria(Spec::not($tree->labelled('Who')))->dump(), 'D8: negado também');
        $this->assertEquals(Spec::toSql(Spec::not($tree), 'mysql')->toSql(), Spec::toSql(Spec::not($tree->labelled('Who')), 'mysql')->toSql());
        $simplified = Spec::simplify(Spec::labelled('L', Spec::equal(1)->and(Spec::equal(1))));
        $this->assertTrue($simplified instanceof LabelledSpecification && $simplified->getLabel() === 'L' && $simplified->getInnerSpecification()->equals(Spec::equal(1)), 'D11: simplify() mantém o nó e simplifica dentro');
        $same = Spec::labelled('L', Spec::equal(1));
        $this->assertTrue(Spec::simplify($same) === $same, 'D11: nada a simplificar, o mesmo objeto');
        $kept = Spec::simplify(Spec::labelled('L', Spec::equal(1))->withCode('C')->and(Spec::alwaysTrue()));
        $this->assertTrue($kept instanceof LabelledSpecification, 'D11: o rótulo não é removido como operando neutro');

        $codec = new SpecificationCodec();
        $json = $codec->toJson($annotated);
        $this->assertEquals('{"v":1,"spec":{"t":"labelled","label":"L","s":{"t":"property","p":"a","s":{"t":"lt","v":1}},"because":"Reason","code":"C"}}', $json, 'D11: o documento');
        $this->assertTrue($codec->fromJson($json)->equals($annotated), 'D11: ida e volta');
        $this->assertThrows(\Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException::class, fn() => $codec->fromJson('{"v":1,"spec":{"t":"labelled","label":"  ","s":{"t":"true"}}}'), 'D11: rótulo em branco no documento recusado');
        $this->assertThrows(\Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException::class, fn() => $codec->fromJson('{"v":1,"spec":{"t":"labelled","s":{"t":"true"}}}'), 'D11: sem label recusado');

        // TreeAnalyzer: an external datum below a label is collected (fetched before the evaluation)
        $sources = (new DataSourceRegistry())->register('m36.value', new \Antevemus\ASpecification\Resolution\CallableDataSource(static fn(array $p): int => (int) $p['x'] * 10));
        $external = Spec::labelled('Score', Spec::external('m36.value', ['x' => 'x'], Spec::greaterThan(15)));
        $data = (new DataResolver($sources))->resolve($external, (object) ['x' => 1]);
        $this->assertCount(1, $data->getRequests(), 'D8: o pedido sob o rótulo é coletado');
        $this->assertEquals('Score', $data->evaluate($external, (object) ['x' => 1])->failures[0]->property, 'D8: falha de dado externo rotulada');
    }

    /**
     * D12: a rule reported once (no failure with a code) keeps its labels when there are two or more; the
     * coded path (the one of the Virttus) is untouched.
     */
    private function testLabelsThroughTheEngine(): void
    {
        $catalog = new InMemoryRuleCatalog([new RuleDefinition(code: 'R', name: 'R', ruleType: 'r', violationMessage: 'Rule R', scope: 's')]);
        $two = (new RuleSpecificationRegistry())->registerClosure('r', static fn(): ISpecification => Spec::allOf(
            Spec::property('a', Spec::lessThan(1))->labelled('Target A'),
            Spec::property('b', Spec::lessThan(1))->labelled('Target B'),
            Spec::property('c', Spec::lessThan(1))->labelled('Target A')
        ));
        $failure = (new DynamicSpecificationEngine($catalog, $two))->validate(['a' => 5, 'b' => 5, 'c' => 5], 's')->getAllFailures()[0];
        $this->assertEquals(null, $failure->property, 'D12: dois alvos, property nula (como em 2.4.0)');
        $this->assertEquals(['Target A', 'Target B'], $failure->metadata['labels'], 'D12: rótulos distintos na ordem');
        $this->assertEquals('Rule R', $failure->message);

        $one = (new RuleSpecificationRegistry())->registerClosure('r', static fn(): ISpecification => Spec::property('a', Spec::lessThan(1))->labelled('Only'));
        $single = (new DynamicSpecificationEngine($catalog, $one))->validate(['a' => 5], 's')->getAllFailures()[0];
        $this->assertTrue($single->property === 'Only' && !isset($single->metadata['labels']), 'D12: um rótulo só, nenhuma chave nova');

        $coded = (new RuleSpecificationRegistry())->registerClosure('r', static fn(): ISpecification => Spec::allOf(
            Spec::property('a', Spec::lessThan(1))->withCode('CA')->labelled('A'),
            Spec::property('b', Spec::lessThan(1))->withCode('CB')->labelled('B')
        ));
        $failures = (new DynamicSpecificationEngine($catalog, $coded))->validate(['a' => 5, 'b' => 5], 's')->getAllFailures();
        $this->assertEquals([['CA', 'A'], ['CB', 'B']], array_map(static fn(SpecificationFailure $f): array => [$f->code, $f->property], $failures), 'D12: com código, falha a falha, como antes');
        $this->assertFalse(isset($failures[0]->metadata['labels']));
    }

    /**
     * RN-07 (a): the Evaluator of the Virttus (d4ef0f5) over the rules of its scripts 40, 50, 54 and 56
     * (transcribed, not read from its database) answers the same violations as the same handlers rewritten
     * with labelled() and registerLookupOnly() instead of the anonymous subclass and of the copy into an
     * InMemoryRuleCatalog, over a table of facts generated with a fixed seed (generation below).
     */
    private function testVirttusEvaluatorAcceptance(): void
    {
        $catalog = new InMemoryRuleCatalog(self::virttusRules());
        $lookupTypes = ['sinistro_janela_aviso', 'sinistro_pagamento_vencimentos', 'sinistro_pagamento_dias_tardio', 'sinistro_pagamento_dia', 'sinistro_op_agrupada', 'sinistro_visibilidade_motivo_op', 'sinistro_prazo_pendencia', 'sinistro_cobertura_debito', 'sinistro_prazo_etapa'];

        mt_srand(4807);
        $facts = [];
        for ($n = 0; $n < 300; $n++) {
            $row = [];
            foreach ([
                'ocorrencias_em_andamento' => static fn() => mt_rand(0, 5),
                'dias_desde_fato' => static fn() => mt_rand(0, 200),
                'pool_disponivel' => static fn() => [0.0, 0.004, 0.01, 1500.5][mt_rand(0, 3)],
                'danos' => static fn() => (bool) mt_rand(0, 1),
                'orcamentos_anexados' => static fn() => mt_rand(0, 3),
                'sub_rogacao_anexada' => static fn() => (bool) mt_rand(0, 1),
                'fatura_vencida_dias' => static fn() => mt_rand(0, 3),
                'competencias_distintas' => static fn() => mt_rand(0, 3),
                'dias_desde_finalizacao' => static fn() => mt_rand(0, 60),
                'garantia_acionada' => static fn() => (bool) mt_rand(0, 1),
                'periodicidade_meses' => static fn() => [null, 6, 12, 24][mt_rand(0, 3)],
                'indices' => static fn() => [[['papel' => 'Índice de reajuste', 'codigo' => ['IGP-M', 'IPCA', 'TR', 'USD'][mt_rand(0, 3)], 'nome' => ['Dólar comercial', 'IPCA', 'Taxa Referencial', 'IGP-M'][mt_rand(0, 3)]]], []][mt_rand(0, 1)],
            ] as $key => $make) {
                if (mt_rand(0, 3) > 0) {
                    $row[$key] = $make();
                }
            }
            $facts[] = $row;
        }

        $violationsSeen = 0;
        foreach (['sinistro', 'contrato_aprovacao', 'reajuste'] as $scope) {
            foreach ($facts as $n => $row) {
                $old = self::virttusEvaluateOld($catalog, $scope, $row);
                $new = self::virttusEvaluateNew($catalog, $scope, $row, $lookupTypes);
                $this->assertEquals($old, $new, "RN-07 (a) {$scope} #{$n}: mesmas violações");
                $violationsSeen += count($old);
            }
        }
        $this->assertTrue($violationsSeen > 100, 'RN-07 (a): a tabela de fatos produz violações (' . $violationsSeen . ')');
    }

    /** The current Evaluator::evaluate() of the Virttus (:79-121), transcribed. */
    private static function virttusEvaluateOld(InMemoryRuleCatalog $catalog, string $escopo, array $facts): array
    {
        $handlers = self::virttusHandlers($facts, static function (IRuleDefinition $rule, string $alvo, Closure $complies): ISpecification {
            // Evaluator::target(), :278-301: an anonymous subclass of PredicateSpecification only to set the target
            $spec = new class ($complies, $alvo) extends PredicateSpecification {
                public function __construct(Closure $predicate, private readonly string $alvo)
                {
                    parent::__construct($predicate);
                }

                public function evaluate(mixed $candidate): SpecificationResult
                {
                    $result = parent::evaluate($candidate);
                    if ($result->isSatisfied) {
                        return $result;
                    }

                    return new SpecificationResult(
                        false,
                        array_map(fn(SpecificationFailure $f) => $f->withProperty($this->alvo), $result->failures),
                        $result->isError,
                        $result->exception
                    );
                }
            };

            return $spec->withCode($rule->getCode())->because((string) $rule->getViolationMessage());
        });
        $rows = [];
        foreach ($catalog->findRules($escopo, null, []) as $rule) {
            if (isset($handlers[$rule->getRuleType()])) {
                $rows[$rule->getCode()] = $rule;
            }
        }
        if (!$rows) {
            return [];
        }
        $registry = Spec::ruleRegistry();
        foreach ($handlers as $type => $handler) {
            $registry->registerClosure($type, $handler);
        }
        $result = Spec::engine(new InMemoryRuleCatalog(array_values($rows)), $registry)->compileSpecification($escopo)->evaluate($facts);

        return self::virttusViolations($result, static fn(string $code): IRuleDefinition => $rows[$code]);
    }

    /** The same Evaluator with lookup-only types and labelled targets (2.5.0): no filter, no copy, no subclass. */
    private static function virttusEvaluateNew(InMemoryRuleCatalog $catalog, string $escopo, array $facts, array $lookupTypes): array
    {
        $handlers = self::virttusHandlers($facts, static fn(IRuleDefinition $rule, string $alvo, Closure $complies): ISpecification => (new PredicateSpecification($complies))
            ->withCode($rule->getCode())
            ->because((string) $rule->getViolationMessage())
            ->labelled($alvo));
        $registry = Spec::ruleRegistry();
        foreach ($handlers as $type => $handler) {
            $registry->registerClosure($type, $handler);
        }
        $registry->registerLookupOnly(...$lookupTypes);
        $result = Spec::engine($catalog, $registry)->compileSpecification($escopo)->evaluate($facts);

        return self::virttusViolations($result, static fn(string $code): IRuleDefinition => $catalog->findRuleByCode($code));
    }

    /**
     * @param Closure(string): IRuleDefinition $ruleOf
     * @return list<array<string, string>>
     */
    private static function virttusViolations(SpecificationResult $result, Closure $ruleOf): array
    {
        if ($result->isError) {
            return [['erro' => (string) $result->exception?->getMessage()]];
        }
        $actions = ['bloquear' => 'block', 'alertar' => 'warn', 'apenas_log' => 'log'];
        $violations = [];
        foreach ($result->failures as $failure) {
            $rule = $ruleOf((string) $failure->code);
            $violations[] = [
                'codigo' => $rule->getCode(),
                'nome' => $rule->getName(),
                'acao' => array_search($rule->getViolationAction()->value, $actions, true) ?: 'bloquear',
                'mensagem' => $failure->message,
                'fundamento_legal' => (string) $rule->getLegalBasis(),
                'alvo' => (string) $failure->property,
            ];
        }

        return $violations;
    }

    /**
     * The 11 handlers of the Evaluator of the Virttus (:161-271, d4ef0f5), transcribed; `$target` is the
     * target builder (Evaluator::target() or its labelled() rewrite).
     *
     * @param Closure(IRuleDefinition, string, Closure): ISpecification $target
     * @return array<string, Closure(IRuleDefinition): ISpecification>
     */
    private static function virttusHandlers(array $facts, Closure $target): array
    {
        return [
            'indexador_vedado' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (empty($facts['indices'])) {
                    return Spec::alwaysTrue();
                }
                $param = json_decode((string) $rule->getTextValue(), true) ?: [];
                $codes = array_map([self::class, 'normCode'], (array) ($param['codigos'] ?? []));
                $terms = array_filter(array_map([self::class, 'normText'], (array) ($param['termos'] ?? [])));
                $specs = [];
                foreach (array_values($facts['indices']) as $i => $idx) {
                    $alvo = trim(($idx['papel'] ?? 'Índice') . ' ' . ($idx['nome'] ?? $idx['codigo'] ?? ''));
                    $specs[] = $target($rule, $alvo, function (array $f) use ($i, $codes, $terms): bool {
                        $idx = array_values($f['indices'])[$i];
                        $code = self::normCode((string) ($idx['codigo'] ?? ''));
                        $name = ' ' . self::normText((string) ($idx['nome'] ?? '')) . ' ';
                        $hit = $code !== '' && in_array($code, $codes, true);
                        foreach ($terms as $t) {
                            $hit = $hit || str_contains($name, ' ' . $t . ' ');
                        }
                        return !$hit;
                    });
                }
                return count($specs) === 1 ? $specs[0] : Spec::allOf(...$specs);
            },
            'sinistro_max_ocorrencias' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('ocorrencias_em_andamento', $facts) || $rule->getIntValue() === null || (int) $rule->getIntValue() <= 0) {
                    return Spec::alwaysTrue();
                }
                $max = (int) $rule->getIntValue();
                $alvo = 'Ocorrências em andamento no contrato: ' . (int) $facts['ocorrencias_em_andamento'] . ' (máximo ' . $max . ')';
                return $target($rule, $alvo, fn(array $f): bool => (int) $f['ocorrencias_em_andamento'] < $max);
            },
            'sinistro_prazo_comunicacao' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('dias_desde_fato', $facts) || $rule->getIntValue() === null || (int) $rule->getIntValue() <= 0) {
                    return Spec::alwaysTrue();
                }
                $max = (int) $rule->getIntValue();
                $alvo = 'Dias desde o fato: ' . (int) $facts['dias_desde_fato'] . ' (prazo ' . $max . ')';
                return $target($rule, $alvo, fn(array $f): bool => (int) $f['dias_desde_fato'] <= $max);
            },
            'sinistro_bloqueio_pool_zerado' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('pool_disponivel', $facts) || $facts['pool_disponivel'] === null) {
                    return Spec::alwaysTrue();
                }
                $alvo = 'Pool disponível: R$ ' . number_format((float) $facts['pool_disponivel'], 2, ',', '.');
                return $target($rule, $alvo, fn(array $f): bool => (float) $f['pool_disponivel'] > 0.005);
            },
            'sinistro_orcamentos_minimos' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (empty($facts['danos']) || !array_key_exists('orcamentos_anexados', $facts) || (int) $rule->getIntValue() <= 0) {
                    return Spec::alwaysTrue();
                }
                $min = (int) $rule->getIntValue();
                $alvo = 'Orçamentos de reparo anexados: ' . (int) $facts['orcamentos_anexados'] . ' (mínimo ' . $min . ')';
                return $target($rule, $alvo, fn(array $f): bool => (int) $f['orcamentos_anexados'] >= $min);
            },
            'sinistro_sub_rogacao' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('sub_rogacao_anexada', $facts)) {
                    return Spec::alwaysTrue();
                }
                return $target($rule, 'Instrumento de sub-rogação', fn(array $f): bool => (bool) $f['sub_rogacao_anexada']);
            },
            'sinistro_fatura_vencida' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('fatura_vencida_dias', $facts)) {
                    return Spec::alwaysTrue();
                }
                $tol = max(0, (int) $rule->getIntValue());
                $alvo = 'Fatura da Virttus vencida há ' . (int) $facts['fatura_vencida_dias'] . ' dia(s) (tolerância ' . $tol . ')';
                return $target($rule, $alvo, fn(array $f): bool => (int) $f['fatura_vencida_dias'] <= $tol);
            },
            'sinistro_competencias_por_ocorrencia' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('competencias_distintas', $facts) || $rule->getTextValue() !== 'uma_por_competencia') {
                    return Spec::alwaysTrue();
                }
                $alvo = 'Competências na ocorrência: ' . (int) $facts['competencias_distintas'];
                return $target($rule, $alvo, fn(array $f): bool => (int) $f['competencias_distintas'] <= 1);
            },
            'sinistro_prazo_contestacao' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('dias_desde_finalizacao', $facts) || (int) $rule->getIntValue() <= 0) {
                    return Spec::alwaysTrue();
                }
                $max = (int) $rule->getIntValue();
                $alvo = 'Dias desde a finalização: ' . (int) $facts['dias_desde_finalizacao'] . ' (prazo ' . $max . ')';
                return $target($rule, $alvo, fn(array $f): bool => (int) $f['dias_desde_finalizacao'] <= $max);
            },
            'contrato_garantia_acionada' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('garantia_acionada', $facts)) {
                    return Spec::alwaysTrue();
                }
                return $target($rule, 'Garantia já acionada', fn(array $f): bool => !$f['garantia_acionada']);
            },
            'periodicidade_minima_reajuste' => function (IRuleDefinition $rule) use ($facts, $target): ISpecification {
                if (!array_key_exists('periodicidade_meses', $facts) || $facts['periodicidade_meses'] === null) {
                    return Spec::alwaysTrue();
                }
                $alvo = 'Periodicidade do reajuste (' . (int) $facts['periodicidade_meses'] . ' meses)';
                $minimo = (int) $rule->getIntValue();
                return $target($rule, $alvo, fn(array $f): bool => (int) $f['periodicidade_meses'] >= $minimo);
            },
        ];
    }

    private static function normCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(self::stripAccents($code)));
    }

    private static function normText(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(self::stripAccents($text))));
    }

    private static function stripAccents(string $s): string
    {
        return strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o',
            'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A',
            'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ç' => 'C']);
    }

    /**
     * The rules of the Virttus: scripts 40 (reajuste, scope NULL in the script, read here under 'reajuste' by
     * the global scope), 50 (sinistro, with the parameter rows and the coverage per scenario), 54
     * (contrato_aprovacao) and 56 (pool zerado), transcribed from the SQL (active 'N' rows kept inactive).
     *
     * @return list<RuleDefinition>
     */
    private static function virttusRules(): array
    {
        $rule = static fn(string $code, string $name, string $type, string $action, ?int $int, ?string $text, ?string $legal, ?string $message, ?string $scope, ?string $scenario = null, bool $active = true, int $priority = 0): RuleDefinition => new RuleDefinition(
            code: $code,
            name: $name,
            ruleType: $type,
            violationAction: RuleAction::from($action),
            intValue: $int,
            textValue: $text,
            legalBasis: $legal,
            violationMessage: $message,
            priority: $priority,
            scope: $scope,
            scenario: $scenario,
            active: $active
        );
        $rules = [
            // script 40 (reajuste; scope NULL, priority 100)
            $rule('indexador_vedado_salario_minimo', 'Indexador vedado: salário mínimo', 'indexador_vedado', 'block', null, '{"codigos": ["SALARIO_MINIMO", "SALARIOMINIMO", "SM", "PISO_NACIONAL"], "termos": ["salario minimo", "piso salarial"]}', 'Lei 8.245/91, arts. 17 e 85; CF, art. 7º, IV', 'Salário mínimo não pode ser índice de reajuste de aluguel (Lei 8.245/91, arts. 17 e 85; CF, art. 7º, IV).', null, null, true, 100),
            $rule('indexador_vedado_cambio', 'Indexador vedado: câmbio e moeda estrangeira', 'indexador_vedado', 'block', null, '{"codigos": ["USD", "USDBRL", "DOLAR", "EUR", "EURO", "PTAX", "CAMBIO", "GBP", "LIBRA", "OURO"], "termos": ["dolar", "euro", "cambio", "cambial", "ptax", "moeda estrangeira", "libra esterlina", "ouro"]}', 'Lei 8.245/91, arts. 17 e 85; Lei 10.192/2001, art. 1º, I', 'Variação cambial ou moeda estrangeira não pode ser índice de reajuste de aluguel (Lei 8.245/91, arts. 17 e 85; Lei 10.192/2001, art. 1º, I).', null, null, true, 100),
            $rule('indexador_vedado_tr', 'Indexador vedado: TR', 'indexador_vedado', 'block', null, '{"codigos": ["TR", "TAXA_REFERENCIAL"], "termos": ["taxa referencial"]}', 'Lei 9.069/95, art. 27, §5º', 'A TR não pode ser índice de reajuste de aluguel (Lei 9.069/95, art. 27, §5º).', null, null, true, 100),
            $rule('indexador_vedado_cdi_selic', 'Indexador vedado: CDI e Selic (taxas de juros)', 'indexador_vedado', 'block', null, '{"codigos": ["CDI", "DI", "CDI_CETIP", "SELIC", "SELIC_META", "SELIC_OVER"], "termos": ["cdi", "selic", "deposito interbancario"]}', 'STJ, REsp 2.081.432 (3ª Turma, 03/10/2023); Lei 10.192/2001, art. 2º', 'CDI e Selic são taxas de juros, não índices de preços: não podem ser índice de reajuste (STJ, REsp 2.081.432; Lei 10.192/2001, art. 2º).', null, null, true, 100),
            $rule('reajuste_periodicidade_anual', 'Reajuste no máximo anual', 'periodicidade_minima_reajuste', 'block', 12, null, 'Lei 10.192/2001, art. 2º, §§1º e 3º; Lei 9.069/95, art. 28', 'Periodicidade do reajuste menor que 12 meses é nula (Lei 10.192/2001, art. 2º, §1º; Lei 9.069/95, art. 28).', null, null, true, 100),
            // script 50 (sinistro)
            $rule('sinistro_max_ocorrencias', 'Ocorrências em andamento por contrato', 'sinistro_max_ocorrencias', 'block', 3, null, 'v2 18_regra_negocio (max_sinistros_abertos)', 'O contrato já tem o máximo de ocorrências em andamento.', 'sinistro'),
            $rule('sinistro_prazo_comunicacao', 'Prazo para comunicar a ocorrência (dias do fato)', 'sinistro_prazo_comunicacao', 'block', 120, null, 'Condições Gerais 6.8', 'Comunicação fora do prazo: mais dias do fato do que o permitido.', 'sinistro'),
            $rule('sinistro_janela_aviso', 'Janela do aviso (dias do fato)', 'sinistro_janela_aviso', 'log', 14, null, 'Termo de Assunção, Cláusula 4ª', null, 'sinistro'),
            $rule('sinistro_pagamento_dentro_janela', 'Pagamento da OP — aviso dentro da janela', 'sinistro_pagamento_vencimentos', 'log', 1, null, 'Termo de Assunção, Cláusula 4ª', null, 'sinistro', 'dentro_janela'),
            $rule('sinistro_pagamento_fora_janela', 'Pagamento da OP — aviso fora da janela', 'sinistro_pagamento_vencimentos', 'log', 2, null, 'Termo de Assunção, Cláusula 4ª', null, 'sinistro', 'fora_janela'),
            $rule('sinistro_pagamento_aviso_tardio', 'Pagamento da OP — aviso depois do 2º vencimento', 'sinistro_pagamento_dias_tardio', 'log', 90, null, 'Termo de Assunção, Cláusula 4ª', null, 'sinistro'),
            $rule('sinistro_pagamento_dia', 'Dia do pagamento da OP', 'sinistro_pagamento_dia', 'log', null, 'ultimo_dia_util_antes', null, null, 'sinistro'),
            $rule('sinistro_prazo_contestacao', 'Prazo de contestação (dias da finalização)', 'sinistro_prazo_contestacao', 'block', 30, null, null, 'Prazo de contestação vencido.', 'sinistro', null, false),
            $rule('sinistro_orcamentos_danos', 'Orçamentos exigidos para danos ao imóvel', 'sinistro_orcamentos_minimos', 'block', 2, null, 'Artigo ao parceiro; Condições Gerais', 'Anexe os orçamentos de reparo exigidos para os danos ao imóvel.', 'sinistro'),
            $rule('sinistro_sub_rogacao', 'Instrumento de sub-rogação antes de pagar', 'sinistro_sub_rogacao', 'block', null, null, 'Condições Gerais (sub-rogação)', 'Anexe o instrumento de sub-rogação assinado pelo locador antes de pagar.', 'sinistro', null, false),
            $rule('sinistro_fatura_vencida', 'Imobiliária com fatura da Virttus vencida', 'sinistro_fatura_vencida', 'block', 0, null, null, 'A imobiliária tem fatura da Virttus vencida: regularize para abrir ocorrência.', 'sinistro', null, false),
            $rule('sinistro_competencias', 'Competências por ocorrência', 'sinistro_competencias_por_ocorrencia', 'block', null, 'acumular', null, 'Esta ocorrência só aceita débitos de uma competência: abra outra ocorrência para o outro mês.', 'sinistro'),
            $rule('sinistro_op_agrupada', 'Uma OP para várias ocorrências', 'sinistro_op_agrupada', 'log', null, null, null, null, 'sinistro'),
            $rule('sinistro_motivo_op_visivel', 'Motivo da OP cancelada/rejeitada visível à imobiliária', 'sinistro_visibilidade_motivo_op', 'log', null, null, null, null, 'sinistro', null, false),
            $rule('sinistro_prazo_pendencia', 'Prazo da imobiliária para responder a pendência', 'sinistro_prazo_pendencia', 'warn', null, null, 'Workflow de Regulação de Ocorrências (Virttus)', 'Pendência com o prazo vencido.', 'sinistro'),
        ];
        foreach (['aluguel' => 'aluguel_', 'iptu' => 'iptu_', 'condominio' => 'condominio_', 'multa_rescisoria' => 'multas_penalidades_contratuais', 'danos_imovel' => 'danos_danos_prediais', 'pintura' => 'danos_pintura,danos_danos_prediais'] as $scenario => $prefixes) {
            $rules[] = $rule('sinistro_cobertura_' . $scenario, 'Cobertura do débito: ' . $scenario, 'sinistro_cobertura_debito', 'block', null, $prefixes, 'Condições Gerais', 'O plano do contrato não cobre este débito.', 'sinistro', $scenario);
        }
        foreach (['recebimento' => [1, 6], 'analise_documental' => [3, 12], 'resolvendo_pendencia' => [3, 12], 'analise_tecnica' => [5, 18], 'analise_vistorias' => [3, 12], 'agendamento_pagamento' => [5, 18], 'emissao_op' => [1, 6], 'aguardando_pagamento' => [3, 12], 'validando_pagamento' => [3, 12], 'em_contestacao' => [2, 2]] as $stage => [$inside, $outside]) {
            $rules[] = new RuleDefinition(code: 'sinistro_prazo_' . $stage, name: 'Prazo da etapa: ' . $stage, ruleType: 'sinistro_prazo_etapa', violationAction: RuleAction::WARN, intValue: $inside, legalBasis: 'Workflow de Regulação de Ocorrências (Virttus)', scope: 'sinistro', scenario: 'clm_' . $stage, parameters: ['fora_janela' => $outside]);
        }
        // script 54 (contrato_aprovacao) and 56 (pool zerado)
        $rules[] = $rule('contrato_garantia_acionada', 'Contrato com garantia já acionada: recusa ou alçada própria', 'contrato_garantia_acionada', 'block', null, null, 'Política de subscrição da Virttus (Heliton, 2026-10-16)', 'Contrato com garantia já acionada: recusar, ou aprovação pela alçada própria (motivo obrigatório).', 'contrato_aprovacao');
        $rules[] = $rule('sinistro_bloqueio_pool_zerado', 'Pool de garantia zerado bloqueia a abertura', 'sinistro_bloqueio_pool_zerado', 'block', null, null, 'v2 18_regra_negocio (global_bloqueio_pool_zerado); Condições Gerais (LMI)', 'O pool de garantia do contrato está zerado: não há saldo para nova ocorrência.', 'sinistro');

        return $rules;
    }

    ///////////////////////////////////////////////////////////////////////////
    // L4: TCriteria without accents
    ///////////////////////////////////////////////////////////////////////////

    /** D14/D15 over the filter itself (no ext-intl needed: the leaves are not built here). */
    private function testAccentFilterRendering(): void
    {
        $pg = new PostgreSqlDialect('f_fold');
        $filter = new TAccentInsensitiveFilter('name', $pg, LikePattern::exact('josé'));
        $dump = $filter->dump(true);
        $this->assertEquals(1, preg_match('/^f_fold\(lower\(name\)\) LIKE f_fold\(lower\((:aspf_\d+)\)\)$/', $dump, $m), 'D14: a condição do dialeto: ' . $dump);
        $this->assertEquals([$m[1] => 'josé'], $filter->getPreparedVars(), 'D14: o valor cru, ligado');
        $plain = $filter->dump(false);
        $this->assertTrue($plain !== $dump && !str_contains($plain, 'josé') && str_contains($plain, ':aspf_'), 'D15: sem prepared, o mesmo marcador, nunca o valor');
        $this->assertCount(1, $filter->getPreparedVars(), 'D15: ligado também sem prepared (marcador renovado a cada dump)');
        $this->assertEquals('f_fold(lower(name)) LIKE f_fold(lower(' . TAccentInsensitiveFilter::PLACEHOLDER_PREFIX . 'x))', $pg->formatAccentInsensitiveLike('name', ':aspf_x'), 'D14: TCriteria = toSql do mesmo dialeto (mesma chamada)');

        $escaped = new TAccentInsensitiveFilter('name', $pg, LikePattern::exact('50%'), true);
        $escapedDump = $escaped->dump(true);
        $this->assertEquals(1, preg_match("/^NOT \(f_fold\(lower\(name\)\) LIKE f_fold\(lower\((:aspf_\d+)\)\) ESCAPE '!'\)$/", $escapedDump, $m), "D14: ESCAPE '!' e negação: " . $escapedDump);
        $this->assertEquals([$m[1] => '50!%'], $escaped->getPreparedVars());

        $my = new TAccentInsensitiveFilter('nome', new MySqlDialect(), LikePattern::exact('jose'));
        $this->assertEquals(1, preg_match('/^CONVERT\(nome USING utf8mb4\) COLLATE utf8mb4_0900_ai_ci LIKE :aspf_\d+$/', $my->dump(true)), 'D16: MySQL pela collation: ' . $my->dump(true));

        $regex = new TAccentInsensitiveFilter('name', new PostgreSqlDialect(), '\bsao');
        $this->assertEquals(1, preg_match('/ ~\* (:aspf_\d+)$/', $regex->dump(true), $m), 'D14: regex pelo accentInsensitiveRegex()');
        $this->assertEquals([$m[1] => '\ysao'], $regex->getPreparedVars(), 'D14: o corpo reescrito pelo dialeto (\b -> \y)');
        $this->assertThrows(\LogicException::class, fn() => (new TAccentInsensitiveFilter('n', new MySqlDialect(), 'x'))->dump(), 'D16: regex sem acento no MySQL não tem forma');

        // Inside a criteria (the stub mirrors TCriteria::dump(): it resets the flag of every child)
        $criteria = new TCriteria();
        $criteria->add(new TFilter('id', '>', 1));
        $criteria->add(new TAccentInsensitiveFilter('name', $pg, LikePattern::exact('ana')));
        $criteria->setCaseInsensitive(true);
        $dump = $criteria->dump(true);
        $this->assertTrue(!str_contains($dump, 'UPPER') && str_contains($dump, 'f_fold(lower(name))'), 'D14: setCaseInsensitive() ignorado: ' . $dump);
        $vars = $criteria->getPreparedVars();
        $this->assertCount(2, $vars, 'D14: as variáveis da expressão juntam-se às do Adianti');
        $this->assertTrue(count(array_filter(array_keys($vars), static fn(string $k): bool => str_starts_with($k, ':aspf_'))) === 1 && count(array_filter(array_keys($vars), static fn(string $k): bool => str_starts_with($k, ':par_'))) === 1, 'D14: :aspf_N nunca colide com :par_N');

        $builder = Spec::criteriaBuilder(Spec::property('x', Spec::equal(1)));
        $this->assertTrue($builder->getAccentFolding() === null);
        $this->assertTrue($builder->withAccentFolding('pgsql') === $builder && $builder->getAccentFolding() instanceof PostgreSqlDialect, 'D13: por nome');
        $this->assertTrue($builder->withAccentFolding(new MySqlDialect('utf8mb4_unicode_ci'))->getAccentFolding() instanceof MySqlDialect, 'D13: por instância');
        $this->assertEquals('(x = 1)', $builder->build()->dump(), 'D13: sem folha de acento, o mesmo critério');
        $this->assertEquals('(x = 1)', Spec::toCriteria(Spec::property('x', Spec::equal(1)), null, [], null, 'mysql')->dump(), 'D13: Spec::toCriteria com o dialeto');
        $this->assertEquals(20, Spec::toCriteria(Spec::property('x', Spec::equal(1)), null, ['limit' => 20], null, 'pgsql')->getProperty('limit'), 'D13: propriedades com o dialeto');
    }

    /** D13-D16 through the visitor: the leaves need ext-intl (the battery against the databases is below). */
    private function testAccentFoldingThroughTheVisitor(): void
    {
        if (!extension_loaded('intl')) {
            fwrite(STDOUT, "    [AVISO] ext-intl ausente; toCriteria sem acento pelo visitor só nos contêineres com intl (run_portability.sh).\n");
            return;
        }
        $ai = \Antevemus\ASpecification\Specifications\String\TextComparison::IgnoreCaseAndAccents;
        $spec = Spec::property('name', Spec::contains('josé', $ai));

        $e = $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria($spec));
        $this->assertTrue(str_contains($e->getMessage(), 'or declare the database with withAccentFolding()'), 'D13: a recusa de hoje com a mensagem acrescida');
        $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria(Spec::not($spec)), 'D13: negado também');

        $pgDump = Spec::toCriteria($spec, accentFolding: 'pgsql')->dump(true);
        $this->assertEquals(1, preg_match('/^\(' . preg_quote(PostgreSqlDialect::builtInAccentFolding('name'), '/') . ' LIKE /', $pgDump), 'D14: a dobra embutida do toSql');
        $criteria = Spec::criteriaBuilder($spec)->withAccentFolding(new PostgreSqlDialect('f_fold'))->build();
        $this->assertEquals(1, preg_match('/^\(f_fold\(lower\(name\)\) LIKE f_fold\(lower\((:aspf_\d+)\)\)\)$/', $criteria->dump(true), $m), 'D14: com a função');
        $this->assertEquals([$m[1] => '%josé%'], $criteria->getPreparedVars(), 'D14: o mesmo padrão do toSql');
        $this->assertEquals(array_values(Spec::toSql($spec, new PostgreSqlDialect('f_fold'))->getParameters()), array_values($criteria->getPreparedVars()), 'D14: TCriteria = toSql (valores)');
        $sql = Spec::toSql($spec, new PostgreSqlDialect('f_fold'))->toSql();
        $this->assertEquals(self::normalizeCondition($sql), self::normalizeCondition($criteria->dump(true)), 'D14: TCriteria = toSql (condição, PG)');
        $mysqlCriteria = Spec::toCriteria(Spec::property('name', Spec::startsWith('50%', $ai)), accentFolding: 'mysql');
        $mysqlSql = Spec::toSql(Spec::property('name', Spec::startsWith('50%', $ai)), 'mysql')->toSql();
        $this->assertEquals(self::normalizeCondition($mysqlSql), self::normalizeCondition($mysqlCriteria->dump(true)), 'D14: TCriteria = toSql (condição, MySQL, ESCAPE)');

        $negated = Spec::toCriteria(Spec::not($spec), accentFolding: new PostgreSqlDialect('f_fold'))->dump(true);
        $this->assertEquals(1, preg_match('/^\(\(name IS NULL OR NOT \(f_fold\(lower\(name\)\) LIKE f_fold\(lower\(:aspf_\d+\)\)\)\)\)$/', $negated), 'D14: negação com tolerância a nulo: ' . $negated);
        foreach ([
            'equal' => Spec::equalIgnoreCaseAndAccents('São'),
            'wildcard' => Spec::wildcard('são*', $ai),
            'endsWith' => Spec::endsWith('ão', $ai),
            'regex' => Spec::regex('/^s[aã]o/', $ai),
        ] as $label => $leaf) {
            $this->assertTrue(str_contains(Spec::toCriteria(Spec::property('city', $leaf), accentFolding: 'pgsql')->dump(true), ':aspf_'), "D14: {$label} traduzido no PostgreSQL");
        }
        $e = $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria(Spec::property('city', Spec::regex('/^sao/', $ai)), accentFolding: 'mysql'));
        $this->assertTrue(str_contains($e->getMessage(), 'mysql'), 'D16: regex sem acento recusada no MySQL, como no toSql');
        $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria(Spec::property('city', Spec::regex('/^sao/x', $ai)), accentFolding: 'pgsql'), 'D14: modificador x recusado');
        $e = $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria($spec, accentFolding: 'sqlite'));
        $this->assertTrue(str_contains($e->getMessage(), 'sqlite'), 'D16: dialeto que o toSql recusa, recusado');
        $this->assertTrue(str_contains(Spec::toCriteria(Spec::property('t', Spec::contains('(SELECT', $ai)), accentFolding: 'pgsql')->dump(true), ':aspf_'), 'D14: o valor é ligado pelo filtro, nunca passa pelo transform() do TFilter');
    }

    /** The condition without the guard and the quotes of toSql(), the placeholders unified. */
    private static function normalizeCondition(string $sql): string
    {
        $sql = (string) preg_replace('/:(aspf_|p)\d+/', ':v', $sql);
        $sql = str_replace(['"', '`'], '', $sql);
        $sql = (string) preg_replace('/^\(?\(name IS NOT NULL AND (.*)\)\)?$/', '$1', $sql);

        return trim((string) preg_replace('/^\((.*)\)$/', '$1', $sql));
    }

    /**
     * RN-02 (L4) against a database, with ext-intl: the battery of the 035 (Module 28) and of the 039, on the
     * default column of each database; for every case and its negation, the TCriteria dumped by the REAL
     * Adianti 8.1 and 8.7 (and by the stub) and executed = toSql() executed = the in-memory evaluation, the row
     * with NULL included. A dump without prepared mode never carries the value and fails at execution.
     */
    private function testAccentBattery(PDO $pdo, string $family): int
    {
        $isPg = $family === 'pgsql';
        $pdo->exec('DROP TABLE IF EXISTS asf_person');
        $pdo->exec('CREATE TABLE asf_person (id INT PRIMARY KEY, df ' . ($isPg ? 'TEXT' : 'VARCHAR(40) CHARACTER SET utf8mb4') . ' NULL)');
        $insert = $pdo->prepare('INSERT INTO asf_person (id, df) VALUES (?, ?)');
        foreach (Module28_AccentInsensitiveTextTest::VALUES as $i => $value) {
            $insert->execute([$i + 1, $value]);
        }
        $dialects = $isPg ? ['pgsql', 'pgsql:aspec048_fold'] : ['mysql'];
        if ($isPg) {
            $pdo->exec('DROP FUNCTION IF EXISTS aspec048_fold(text) CASCADE');
            $pdo->exec('CREATE FUNCTION aspec048_fold(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN ' . PostgreSqlDialect::builtInAccentFolding('t'));
        }
        $operations = [
            ['contains', 'configuracao'], ['contains', 'CONFIGURACAO'], ['startsWith', 'configura'], ['endsWith', 'ÇÕES'],
            ['equal', 'sao paulo'], ['contains', 'pessego'], ['equal', 'avo'], ['contains', 'senor'], ['contains', 'ELEVE'],
            ['contains', 'creme brulee'], ['contains', 'muller'], ['contains', 'ß'], ['contains', '%'], ['equal', '50% OFF'],
            ['startsWith', 'A_'], ['wildcard', 'conf*'], ['wildcard', '*ÃO'], ['wildcard', 'a?b'], ['contains', '!'],
        ];
        if ($isPg) {
            $operations[] = ['regex', '/^s[aã]o/'];
            $operations[] = ['regex', '/\bpaulo/'];
        }
        $cases = [];
        foreach ($dialects as $dialect) {
            foreach ($operations as [$op, $value]) {
                foreach ([false, true] as $negated) {
                    $cases["{$dialect} {$op}('{$value}')" . ($negated ? ' negated' : '')] = ['type' => 'accent', 'dialect' => $dialect, 'op' => $op, 'value' => $value, 'negated' => $negated];
                }
            }
        }
        $queries = 0;
        $real = $this->realAdiantiDumps($cases);
        foreach ($cases as $label => $case) {
            $spec = Module36Cases::accentSpec($case);
            $expected = [];
            foreach (Module28_AccentInsensitiveTextTest::VALUES as $i => $value) {
                if ($spec->isSatisfiedBy((object) ['df' => $value])) {
                    $expected[] = $i + 1;
                }
            }
            $dialect = Module36Cases::dialect($case['dialect']);
            $clause = Spec::toSql($spec, $dialect);
            $this->assertEquals($expected, $this->ids($pdo, 'asf_person', $clause->getSql(), $clause->getParameters()), "RN-02 L4 {$family} toSql {$label}");
            $stub = Spec::toCriteria($spec, accentFolding: $dialect);
            $this->assertEquals($expected, $this->ids($pdo, 'asf_person', (string) $stub->dump(true), (array) $stub->getPreparedVars()), "RN-02 L4 {$family} TCriteria (stub) {$label}");
            $queries += 2;
            foreach ($real as $version => $dumps) {
                $this->assertTrue(isset($dumps[$label]['prepared']), "Adianti {$version}: {$label}: " . json_encode($dumps[$label] ?? null));
                $this->assertEquals($expected, $this->ids($pdo, 'asf_person', $dumps[$label]['prepared'], $dumps[$label]['vars']), "RN-02 L4 {$family} TCriteria (Adianti {$version}) {$label}");
                $this->assertFalse(str_contains($dumps[$label]['plain'], (string) $case['value']) && strlen($case['value']) > 2, "D15 {$version}: sem prepared, o valor nunca entra no texto ({$label})");
                $queries++;
            }
        }
        // D15: prep = 0 fails closed (the placeholder has no value)
        foreach ($real === [] ? ['stub' => ['x' => ['plain' => (string) Spec::toCriteria(Module36Cases::accentSpec(reset($cases)), accentFolding: Module36Cases::dialect(reset($cases)['dialect']))->dump(false)]]] : $real as $version => $dumps) {
            $plain = (string) reset($dumps)['plain'];
            try {
                $pdo->query("SELECT id FROM asf_person WHERE {$plain}");
                $this->assertTrue(false, "D15 {$family} {$version}: prep = 0 deveria falhar: {$plain}");
            } catch (PDOException) {
                $this->assertTrue(true);
            }
        }
        if ($isPg) {
            // RN-03 (L4): folding only the column (with unaccent) and binding the value as typed selects another
            // set as soon as the value has an accent: the battery catches the variant the forward discarded
            try {
                $pdo->exec('CREATE EXTENSION IF NOT EXISTS unaccent');
                $statement = $pdo->prepare('SELECT id FROM asf_person WHERE unaccent(lower(df)) LIKE :p ORDER BY id');
                $statement->execute([':p' => '%' . mb_strtolower('ÇÕES') . '%']);
                $columnOnly = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
                $memory = [];
                foreach (Module28_AccentInsensitiveTextTest::VALUES as $i => $value) {
                    if (Module36Cases::accentSpec(['op' => 'contains', 'value' => 'ÇÕES', 'negated' => false, 'dialect' => 'pgsql'])->isSatisfiedBy((object) ['df' => $value])) {
                        $memory[] = $i + 1;
                    }
                }
                $this->assertTrue($columnOnly !== $memory, 'RN-03 L4: a dobra só da coluna diverge da memória ([' . implode(',', $columnOnly) . '] x [' . implode(',', $memory) . '])');
            } catch (PDOException $e) {
                fwrite(STDOUT, '    [AVISO] unaccent indisponível; controle RN-03 de L4 pulado: ' . $e->getMessage() . "\n");
            }
        }
        $pdo->exec('DROP TABLE asf_person');
        if ($isPg) {
            $pdo->exec('DROP FUNCTION IF EXISTS aspec048_fold(text) CASCADE');
        }

        return $queries;
    }

    ///////////////////////////////////////////////////////////////////////////
    // L5: subqueries
    ///////////////////////////////////////////////////////////////////////////

    private function testSubqueryIdentifiers(): void
    {
        $where = Spec::alwaysTrue();
        foreach (['module_states', 'public.module_states', '"aflow-engine".module_states', 'a$b', '"x y"', '_t', 'S1."t-2"."c c"'] as $valid) {
            $this->assertEquals($valid, (new InSubquerySpecification($valid, 'id', $where))->getTable(), "D18: aceito {$valid}");
        }
        foreach (['', 'a;drop', 'a b', '"a"b"', '"a\\b"', "a\x00", '1abc', 'a.', '.a', '"', '""', 'a--b', 'a/*', "\"a\nb\"", 'a)', "a'", 'a.b.', "\"a\x7F\""] as $invalid) {
            $this->assertThrows(UnsafeIdentifierException::class, fn() => Spec::inSubquery('x', $invalid, 'id', $where), 'D18: tabela recusada ' . json_encode($invalid));
            $this->assertThrows(UnsafeIdentifierException::class, fn() => Spec::inSubquery('x', 't', $invalid, $where), 'D18: coluna recusada ' . json_encode($invalid));
            $this->assertThrows(UnsafeIdentifierException::class, fn() => Spec::existsIn('t', [$invalid === '' ? ' ' : $invalid => 'id'], $where, 'o'), 'D18: coluna do on recusada ' . json_encode($invalid));
            $this->assertThrows(UnsafeIdentifierException::class, fn() => Spec::existsIn('t', ['fk' => $invalid], $where, 'o'), 'D18: propriedade do on recusada ' . json_encode($invalid));
            $this->assertThrows(UnsafeIdentifierException::class, fn() => Spec::existsIn('t', ['fk' => 'id'], $where, $invalid), 'D18: tabela externa recusada ' . json_encode($invalid));
        }
        $e = $this->assertThrows(UnsafeIdentifierException::class, fn() => Spec::inSubquery('x', 'a;drop', 'id', $where));
        $this->assertTrue(str_contains($e->getMessage(), 'A subquery accepts identifiers'), 'D18: a mensagem diz a gramática da subquery');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::existsIn('t', [], $where, 'o'), 'D17: on vazio');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::existsIn('t', ['fk', 'id'], $where, 'o'), 'D17: on não associativo');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::existsIn('t', ['fk' => 1], $where, 'o'), 'D17: propriedade não texto');
        $this->assertEquals([['aflow-engine', true], ['module_states', false]], InSubquerySpecification::segments('"aflow-engine".module_states'), 'D18: segmentos');
    }

    private function testSubqueryTranslations(): void
    {
        $where = Spec::property('moduleId', Spec::equalTo(7))->and(Spec::property('deletedAt', Spec::isNull()));
        $in = Spec::inSubquery('stateId', 'module_states', 'id', $where);
        $this->assertTrue($in->getInnerSpecification() instanceof InSubquerySpecification && $in->getPropertyName() === 'stateId', 'D17: inSubquery() é uma propriedade');

        // SQL (D20)
        $pg = Spec::toSql($in, 'pgsql');
        $this->assertEquals('("state_id" IS NOT NULL AND "state_id" IN (SELECT "sq"."id" FROM "module_states" "sq" WHERE "sq"."id" IS NOT NULL AND ((("sq"."module_id" IS NOT NULL AND "sq"."module_id" = :ps1_1) AND "sq"."deleted_at" IS NULL))))', $pg->toSql(), 'D20: IN no PostgreSQL');
        $this->assertEquals([':ps1_1' => 7], $pg->getParameters(), 'D20: parâmetros com prefixo próprio');
        $this->assertEquals('NOT (("state_id" IS NOT NULL AND "state_id" IN (SELECT "sq"."id" FROM "module_states" "sq" WHERE "sq"."id" IS NOT NULL AND ((("sq"."module_id" IS NOT NULL AND "sq"."module_id" = :ps1_1) AND "sq"."deleted_at" IS NULL)))))', Spec::toSql(Spec::not($in), 'pgsql')->toSql(), 'D17: NOT IN, a coluna selecionada sem NULL');
        $two = Spec::toSql($in->and(Spec::property('name', Spec::equalTo('x')))->and(Spec::inSubquery('typeId', 'types', 'id', Spec::property('code', Spec::equalTo('t')))), 'pgsql');
        $this->assertEquals([':ps1_1', ':p1', ':ps2_1'], array_keys($two->getParameters()), 'D20: cada subquery com o seu prefixo');
        $nested = Spec::toSql(Spec::inSubquery('a', 'ta', 'id', Spec::inSubquery('b', 'tb', 'id', Spec::property('c', Spec::equalTo(1)))), 'pgsql');
        $this->assertEquals([':ps1_s1_1' => 1], $nested->getParameters(), 'D20: subquery aninhada');
        $this->assertTrue(str_contains($nested->toSql(), '"sq"."b" IN (SELECT "sq"."id" FROM "tb" "sq"'), 'D20: aninhada sobre as colunas de sq');
        $exists = Spec::existsIn('"aflow-engine".claim_document', ['claim_id' => 'id', 'kind' => 'docKind'], Spec::property('status', Spec::equalTo("Apto #3 -- x")), 'claim');
        $this->assertEquals('EXISTS (SELECT 1 FROM `aflow-engine`.`claim_document` `sq` WHERE `sq`.`claim_id` = `claim`.`id` AND `sq`.`kind` = `claim`.`doc_kind` AND ((`sq`.`status` IS NOT NULL AND `sq`.`status` = :ps1_1)))', Spec::toSql($exists, 'mysql')->toSql(), 'D18/D20: EXISTS no MySQL, o segmento entre aspas re-citado com crase');
        $this->assertEquals("Apto #3 -- x", Spec::toSql($exists, 'mysql')->getParameters()[':ps1_1'], 'RN-08: o literal só como parâmetro');
        $this->assertEquals('NOT (EXISTS (SELECT 1 FROM "aflow-engine"."claim_document" "sq" WHERE "sq"."claim_id" = "claim"."id" AND "sq"."kind" = "claim"."doc_kind" AND (("sq"."status" IS NOT NULL AND "sq"."status" = :ps1_1))))', Spec::toSql(Spec::not($exists), 'pgsql')->toSql(), 'D17: NOT EXISTS');
        $mapped = Spec::toSql(Spec::existsIn('t', ['fk' => 'id'], Spec::property('flag', Spec::equalTo(1)), 'o', ['flag' => 'is_flag']), 'pgsql', ['id' => 'x.id_col']);
        $this->assertEquals('EXISTS (SELECT 1 FROM "t" "sq" WHERE "sq"."fk" = "x"."id_col" AND (("sq"."is_flag" IS NOT NULL AND "sq"."is_flag" = :ps1_1)))', $mapped->toSql(), 'D17: mapeadores interno e externo (externo já qualificado mantido)');
        $this->assertEquals('EXISTS (SELECT 1 FROM "Oracle" "SQ" WHERE "SQ"."FK" = "O"."ID" AND (1 = 1))', Spec::toSql(Spec::existsIn('"Oracle"', ['fk' => 'id'], Spec::alwaysTrue(), 'o'), 'oracle')->toSql(), 'D18: Oracle maiúsculo, o segmento entre aspas como escrito');
        $this->assertThrows(UnsupportedSqlOperationException::class, fn() => Spec::toSql(Spec::existsIn('"x y"', ['fk' => 'id'], Spec::alwaysTrue(), 'o'), new InformixDialect()), 'D18: dialeto sem identificador delimitado recusa o segmento entre aspas');
        $this->assertTrue(str_contains(Spec::toSql(Spec::existsIn('x', ['fk' => 'id'], Spec::alwaysTrue(), 'o'), new InformixDialect())->toSql(), 'EXISTS (SELECT 1 FROM x sq'), 'D18: Informix sem aspas, segmento simples');
        $this->assertEquals('EXISTS (SELECT 1 FROM "t" "sq" WHERE "sq"."fk" = "o"."id" AND (1 = 1))', Spec::toSql(Spec::property('ignored', Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysTrue(), 'o')), 'pgsql')->toSql(), 'D17: EXISTS é condição da linha, sem guarda da coluna');

        // TCriteria (D19)
        $criteria = Spec::toCriteria($in);
        $this->assertEquals('(state_id IN (SELECT sq.id FROM module_states sq WHERE sq.id IS NOT NULL AND (sq.module_id = 7 AND sq.deleted_at IS NULL)))', $criteria->dump(), 'D19: IN');
        $prepared = $criteria->dump(true);
        $this->assertEquals(1, preg_match('/^\(state_id IN \(SELECT sq\.id FROM module_states sq WHERE sq\.id IS NOT NULL AND \(sq\.module_id = (:par_\d+) AND sq\.deleted_at IS NULL\)\)\)$/', $prepared, $m), 'D19: prepared: ' . $prepared);
        $this->assertEquals([$m[1] => 7], $criteria->getPreparedVars(), 'D19: valores ligados pelo TFilter do Adianti');
        $this->assertEquals('((state_id IS NULL OR state_id NOT IN (SELECT sq.id FROM module_states sq WHERE sq.id IS NOT NULL AND (sq.module_id = 7 AND sq.deleted_at IS NULL))))', Spec::toCriteria(Spec::not($in))->dump(), 'D17/D19: NOT IN com tolerância a nulo');
        $this->assertEquals("(EXISTS (SELECT 1 FROM \"aflow-engine\".claim_document sq WHERE sq.claim_id = claim.id AND sq.kind = claim.doc_kind AND (sq.status = 'Apto #3 -- x')))", Spec::toCriteria($exists)->dump(), 'D19: EXISTS sem passar pelo transform() (o # e o -- intactos)');
        $this->assertEquals('(NOT EXISTS (SELECT 1 FROM t sq WHERE sq.fk = o.id AND (1 = 1)))', Spec::toCriteria(Spec::not(Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysTrue(), 'o')))->dump(), 'D19: NOT EXISTS');
        $this->assertEquals('(EXISTS (SELECT 1 FROM t sq WHERE sq.fk = x.id_col AND (1 = 1)))', Spec::toCriteria(Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysTrue(), 'o'), ['id' => 'x.id_col'])->dump(), 'D19: externo já qualificado');
        $hostile = Spec::toCriteria(Spec::inSubquery('stateId', 's', 'id', Spec::property('label', Spec::equalTo('x]:y #3 /* -- ')->or(Spec::equalTo(':[v]:')))));
        $dump = $hostile->dump(true);
        $this->assertTrue(!str_contains($dump, 'x]:y') && !str_contains($dump, ':[v]:'), 'RN-08: nenhum literal no texto: ' . $dump);
        $this->assertEquals(['x]:y #3 /* -- ', ':[v]:'], array_values($hostile->getPreparedVars()), 'D19: os literais hostis intactos nas variáveis');
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::inSubquery('a', 't', 'id', Spec::property('label', Spec::equalTo('(SELECT 1)')))), 'D19: assertSafeValue nas folhas internas');
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::existsIn('t', ['fk' => 'id'], Spec::property('label', Spec::equalTo('NOESC:1')), 'o')), 'D19: NOESC: recusado dentro');
        $insensitive = Spec::toCriteria(Spec::inSubquery('a', 't', 'id', Spec::property('code', Spec::equalIgnoreCase('ab'))->and(Spec::property('n', Spec::wildcard('x*')))));
        $this->assertEquals("(a IN (SELECT sq.id FROM t sq WHERE sq.id IS NOT NULL AND (UPPER(sq.code) LIKE UPPER('ab') AND sq.n LIKE 'x%')))", $insensitive->dump(), 'D19: as folhas internas como as do Adianti (caixa fixada)');

        $filter = $criteria->getExpressions()[0];
        $this->assertTrue($filter instanceof TSubqueryFilter);
        $clone = clone $criteria;
        $this->assertEquals($criteria->dump(), $clone->dump(), 'D19: o clone (TRepository) escreve o mesmo');
        $criteria->add(new TFilter('deleted_at', 'IS', null));
        $this->assertEquals('(state_id IN (SELECT sq.id FROM module_states sq WHERE sq.id IS NOT NULL AND (sq.module_id = 7 AND sq.deleted_at IS NULL)) AND deleted_at IS NULL)', $criteria->dump(), 'D23: o soft delete do TRepository vale para a tabela externa, não para a subquery');

        $labelled = Spec::labelled('L', $in);
        $this->assertEquals(Spec::toSql($in, 'pgsql')->toSql(), Spec::toSql($labelled, 'pgsql')->toSql(), 'L3+L5: rótulo sobre subquery');
    }

    private function testSubqueryInMemoryCodecAndRepository(): void
    {
        $in = Spec::inSubquery('stateId', 'module_states', 'id', Spec::property('moduleId', Spec::equalTo(7)));
        $exists = Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysTrue(), 'o');
        $result = $in->evaluate((object) ['stateId' => 1]);
        $this->assertTrue($result->isError && !$result->isSatisfied && $result->exception instanceof DatabaseOnlySpecificationException, 'D21: erro de avaliação em memória');
        $this->assertTrue(str_contains((string) $result->exception?->getMessage(), 'InSubquerySpecification is evaluated only by the database'), 'D21: a mensagem');
        $this->assertTrue(Spec::not($in)->evaluate((object) ['stateId' => 1])->isError, 'D21: NOT não inverte');
        $this->assertTrue($in->or(Spec::alwaysTrue())->evaluate((object) ['stateId' => 1])->isError, 'D21: OR com erro à esquerda encerra');
        $this->assertTrue($exists->evaluate((object) [])->isError && Spec::not($exists)->evaluate((object) [])->isError, 'D21: EXISTS também');
        $this->assertThrows(DatabaseOnlySpecificationException::class, fn() => $exists->isSatisfiedBy((object) []), 'D21: isSatisfiedBy() lança');
        $this->assertFalse($exists->isTautology() || $exists->isContradiction() || Spec::isTautology($in) || Spec::isContradiction($in), 'D21: nem tautologia nem contradição');
        $this->assertTrue($exists->isGeneralizationOf($exists) && !$exists->isGeneralizationOf(Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysFalse(), 'o')), 'D21: álgebra opaca (só reflexividade)');
        $this->assertTrue(Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysTrue(), 'o')->equals($exists), 'D21: equals pelos componentes');
        $this->assertFalse(Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysTrue(), 'p')->equals($exists), 'D21: tabela externa diferente');
        $this->assertFalse(Spec::existsIn('t', ['fk2' => 'id'], Spec::alwaysTrue(), 'o')->equals($exists), 'D21: on diferente');
        $this->assertFalse((new InSubquerySpecification('t', 'id', Spec::alwaysTrue()))->equals(new InSubquerySpecification('t', 'id2', Spec::alwaysTrue())), 'D21: coluna diferente');
        $this->assertTrue((new InSubquerySpecification('t', 'id', Spec::alwaysTrue(), ['a' => 'b']))->equals(new InSubquerySpecification('t', 'id', Spec::alwaysTrue(), ['a' => 'b'])), 'D21: mapeamento igual');

        $codec = new SpecificationCodec();
        foreach ([[$in, 'property.inSubquery'], [$exists, 'existsIn'], [Spec::not($exists), 'not.existsIn']] as [$spec, $path]) {
            try {
                $codec->encode($spec);
                $this->assertTrue(false, 'D22: recusada');
            } catch (NonSerializableSpecificationException $e) {
                $this->assertEquals($path, $e->path, 'D22: o caminho na árvore');
                $this->assertTrue(str_contains($e->getMessage(), 'names a table'), 'D22: o motivo');
            }
        }

        // D20: a PdoRepository uses inQuery(); the raw subquery is refused by its compiler
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE m36_row (id INTEGER PRIMARY KEY, label VARCHAR(20) NOT NULL)');
        $repository = new PdoRepository($pdo, EntityMapping::for(Module36Row::class)->table('m36_row')->id('id')->column('label', type: ColumnType::String));
        $e = $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repository->toWhereClause(Spec::existsIn('t', ['fk' => 'id'], Spec::alwaysTrue(), 'm36_row')));
        $this->assertTrue(str_contains($e->getMessage(), 'Spec::inQuery()'), 'D20: aponta o inQuery()');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repository->toWhereClause(Spec::inSubquery('label', 't', 'x', Spec::alwaysTrue())), 'D20: dentro da propriedade também');
    }

    /**
     * RN-02 (L5) against a database: generated where trees over the inner fixture (and fixed ones with the
     * hostile literals), inSubquery()/existsIn() and their negations; the TCriteria of the REAL Adianti 8.1
     * and 8.7 (when found next to the repository) and of the stub, executed with the prepared variables =
     * toSql() executed = the brute force of Module36Cases (outside the library). The RN-03 control: the raw
     * `(SELECT` passthrough of TFilter selects another set for a literal with `#`.
     */
    private function testSubqueryBattery(PDO $pdo, string $family): int
    {
        foreach (['asq_item', 'asq_state'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        $pdo->exec('CREATE TABLE asq_state (id INT PRIMARY KEY, module_id INT NULL, label VARCHAR(80) NULL, deleted_at VARCHAR(20) NULL, item_ref INT NULL)');
        $pdo->exec('CREATE TABLE asq_item (id INT PRIMARY KEY, state_id INT NULL, name VARCHAR(20) NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO asq_state (id, module_id, label, deleted_at, item_ref) VALUES (?, ?, ?, ?, ?)');
        foreach (Module36Cases::STATES as $row) {
            $insert->execute($row);
        }
        $insert = $pdo->prepare('INSERT INTO asq_item (id, state_id, name) VALUES (?, ?, ?)');
        foreach (Module36Cases::ITEMS as $row) {
            $insert->execute($row);
        }
        $dialect = $family === 'sqlite' ? 'sqlite' : ($family === 'pgsql' ? 'pgsql' : 'mysql');

        mt_srand(4805);
        $cases = [];
        foreach ([
            ['eq', 'label', 'apto #3'], ['eq', 'label', 'a--b'], ['eq', 'label', 'x/*y*/z'], ['eq', 'label', 'x]:y'],
            ['eq', 'label', "o'brien"], ['eq', 'label', 'back\\slash'], ['eq', 'label', ':[v]:'], ['contains', 'label', '%'],
            ['and', ['eq', 'moduleId', 7], ['isNull', 'deletedAt']], ['not', ['eq', 'label', 'apto #3']],
        ] as $i => $where) {
            foreach (['in', 'exists'] as $kind) {
                foreach ([false, true] as $negated) {
                    $cases["fixed {$i} {$kind}" . ($negated ? ' not' : '')] = ['type' => 'subquery', 'kind' => $kind, 'negated' => $negated, 'where' => $where];
                }
            }
        }
        for ($n = 0; $n < 60; $n++) {
            $cases["random {$n}"] = ['type' => 'subquery', 'kind' => $n % 2 === 0 ? 'in' : 'exists', 'negated' => $n % 4 >= 2, 'where' => Module36Cases::randomWhere(3)];
        }

        $real = $this->realAdiantiDumps($cases);
        $queries = 0;
        $nonEmpty = 0;
        foreach ($cases as $label => $case) {
            $expected = Module36Cases::expectedItems($case);
            $nonEmpty += $expected !== [] && count($expected) < count(Module36Cases::ITEMS) ? 1 : 0;
            $spec = Module36Cases::subquerySpec($case);
            $clause = Spec::toSql($spec, $dialect);
            $this->assertEquals($expected, $this->ids($pdo, 'asq_item', $clause->getSql(), $clause->getParameters()), "RN-02 L5 {$family} toSql {$label}: " . json_encode($case['where']));
            $stub = Spec::toCriteria($spec);
            $this->assertEquals($expected, $this->ids($pdo, 'asq_item', (string) $stub->dump(true), (array) $stub->getPreparedVars()), "RN-02 L5 {$family} TCriteria (stub) {$label}");
            $queries += 2;
            foreach ($real as $version => $dumps) {
                $this->assertTrue(isset($dumps[$label]['prepared']), "Adianti {$version} {$label}: " . json_encode($dumps[$label] ?? null));
                $this->assertEquals($expected, $this->ids($pdo, 'asq_item', $dumps[$label]['prepared'], $dumps[$label]['vars']), "RN-02 L5 {$family} TCriteria (Adianti {$version}) {$label}");
                $queries++;
            }
        }
        $this->assertTrue($nonEmpty > 40, "RN-02 L5: a bateria separa as linhas ({$nonEmpty} casos com resposta parcial)");

        // RN-03 control: the raw subselect of TFilter removes '#', so 'apto #3' becomes 'apto 3' (another row)
        $raw = new TCriteria();
        $raw->add(new TFilter('state_id', 'IN', "(SELECT id FROM asq_state WHERE label = 'apto #3')"));
        $expected = Module36Cases::expectedItems(['kind' => 'in', 'negated' => false, 'where' => ['eq', 'label', 'apto #3']]);
        $mutilated = $this->ids($pdo, 'asq_item', (string) $raw->dump(true), (array) $raw->getPreparedVars());
        $this->assertEquals([2], $expected);
        $this->assertEquals([10], $mutilated, 'RN-03: a passagem crua de (SELECT mutila o # e seleciona outra linha');
        $pseudo = new TCriteria();
        $pseudo->add(new TFilter('state_id', 'IN', '(SELECT id FROM asq_state WHERE label = :[x]:y]:)'));
        $this->assertTrue(str_contains((string) $pseudo->dump(true), ':par_') && str_contains((string) $pseudo->dump(true), 'y]:'), 'RN-03: a pseudovariável quebra com ]:');

        foreach (['asq_item', 'asq_state'] as $table) {
            $pdo->exec("DROP TABLE {$table}");
        }
        if ($family === 'sqlite') {
            fwrite(STDOUT, '    [048 L5] SQLite: ' . count($cases) . ' casos de subquery, ' . $queries . ' consultas (Adianti real: ' . (implode(', ', array_keys($real)) ?: 'nenhum') . ")\n");
        }

        return $queries;
    }

    ///////////////////////////////////////////////////////////////////////////
    // L10: asynchronous data sources
    ///////////////////////////////////////////////////////////////////////////

    /**
     * D25: a pending datum never approves nor refuses; NOT does not invert it; AND propagates it; OR follows the
     * rule of today (the error of the left side ends the disjunction).
     */
    private function testPendingMatrix(): void
    {
        $bureau = new Module36Bureau(['pending']);
        $sources = (new DataSourceRegistry())->register('m36.score', new AsyncDataSource(
            submit: static fn(DataRequest $r, string $key): string => $bureau->createAnalysis((string) $r->get('cpf'), $key),
            poll: static fn(string $ticket): AsyncPoll => $bureau->isDone($ticket) ? AsyncPoll::ready(720) : AsyncPoll::pending(30),
            tickets: new InMemoryAsyncTicketStore()
        ));
        $resolver = new DataResolver($sources);
        $pending = Spec::external('m36.score', ['cpf' => 'cpf'], Spec::greaterThanOrEqualTo(700));
        $true = Spec::property('x', Spec::equalTo(1));
        $false = Spec::property('x', Spec::equalTo(2));
        $candidate = (object) ['cpf' => '1', 'x' => 1];

        $result = $resolver->evaluate($pending, $candidate);
        $this->assertTrue($result->isPending() && $result->isError && !$result->isSatisfied, 'D25: pendente = erro tipado');
        $this->assertTrue($result->exception instanceof DataPendingException && $result->exception->getRetryAfter() === 30 && $result->exception->getSource() === 'm36.score' && $result->exception->getTicket() !== null, 'D25: a exceção');
        $this->assertEquals(SpecificationResult::EVALUATION_ERROR_MESSAGE, $result->failures[0]->message, 'D25: nunca a mensagem da exceção na falha');
        $this->assertFalse(str_contains($result->exception->getMessage(), (string) $result->exception->getTicket()), 'D25: a mensagem não carrega o ticket');
        foreach ([
            'NOT P' => [Spec::not($pending), false, true],
            'P AND T' => [$pending->and($true), false, true],
            'T AND P' => [$true->and($pending), false, true],
            'F AND P' => [$false->and($pending), false, true],
            'P OR T' => [$pending->or($true), false, true],
            'F OR P' => [$false->or($pending), false, true],
            'T OR P' => [$true->or($pending), true, false],
            'NOT (P AND T)' => [Spec::not($pending->and($true)), false, true],
        ] as $label => [$spec, $satisfied, $isPending]) {
            $r = $resolver->evaluate($spec, $candidate);
            $this->assertEquals([$satisfied, $isPending], [$r->isSatisfied, $r->isPending()], "D25: {$label}");
        }
        $this->assertEquals(1, $bureau->created, 'D27: um envio só, em todas as resoluções');

        // RuleEngineVerdict::isPending() and ResolvedData::getPendingRequests()
        $data = $resolver->resolve($pending, $candidate);
        $this->assertCount(1, $data->getPendingRequests(), 'D25: o pedido pendente');
        $this->assertCount(1, $data->getFailedRequests(), 'D25: pendente é falha desta resolução');
        $verdict = RuleEngineVerdict::fromSpecificationResult($data->evaluate($pending, $candidate));
        $this->assertTrue($verdict->isPending() && $verdict->hasBlockingErrors() && !$verdict->canProceed(), 'D25: o veredito pendente bloqueia');
        $catalog = new InMemoryRuleCatalog([new RuleDefinition(code: 'score', name: 'Score', ruleType: 'score', scope: 's')]);
        $registry = (new RuleSpecificationRegistry())->registerClosure('score', static fn(): ISpecification => $pending);
        $previous = DataSourceRegistry::default();
        DataSourceRegistry::setDefault($sources);
        try {
            $engineVerdict = (new DynamicSpecificationEngine($catalog, $registry))->validate($candidate, 's');
        } finally {
            DataSourceRegistry::setDefault($previous);
        }
        $this->assertTrue($engineVerdict->isPending() && $engineVerdict->hasEvaluationErrors(), 'D25: pelo motor, com a regra vinculada');
        $this->assertFalse(RuleEngineVerdict::fromSpecificationResult(SpecificationResult::error(new RuntimeException('x'), 'r'))->isPending(), 'D25: erro comum não é pendente');
        $wrapped = SpecificationResult::error(new RuntimeException('wrap', 0, new DataPendingException('s')), 'r');
        $this->assertTrue($wrapped->isPending(), 'D25: pendente na cadeia de anteriores');
        $this->assertFalse(SpecificationResult::failure('m')->isPending() || SpecificationResult::satisfied()->isPending(), 'D25: falha e satisfeito não são pendentes');
        $this->assertFalse(SpecificationResult::combine(SpecificationResult::error(new RuntimeException('first'), 'a'), $result)->isPending(), 'D25: o primeiro erro decide (real antes de pendente)');
        $this->assertThrows(InvalidArgumentException::class, fn() => new DataPendingException('s', null, -1));
        $this->assertThrows(InvalidArgumentException::class, fn() => AsyncPoll::pending(-1));
    }

    /** D26-D28: the protocol of AsyncDataSource and the stores. */
    private function testAsyncDataSourceProtocol(): void
    {
        $request = new DataRequest('bureau', ['cpf' => '111']);
        $bureau = new Module36Bureau(['pending', 'done']);
        $store = new InMemoryAsyncTicketStore();
        $source = new AsyncDataSource(
            submit: static fn(DataRequest $r, string $key): string => $bureau->createAnalysis((string) $r->get('cpf'), $key),
            poll: static fn(string $ticket, DataRequest $r): AsyncPoll => $bureau->isDone($ticket) ? AsyncPoll::ready($bureau->score($ticket)) : AsyncPoll::pending(),
            tickets: $store,
            cost: 7,
            ttl: 60
        );
        $this->assertEquals([7, 60], [$source->getCost(), $source->getTtl()]);
        $this->assertTrue($source->getTicketStore() === $store);
        $e = $this->assertThrows(DataPendingException::class, fn() => $source->fetch($request));
        $this->assertTrue($e->getRetryAfter() === null && $store->get($request->getKey())?->getTicket() === $e->getTicket(), 'D26: ticket guardado sob a chave do pedido');
        $this->assertEquals(720, $source->fetch($request), 'D26: pronto devolve o valor');
        $this->assertEquals(720, $source->fetch($request), 'D26: o ticket fica: a mesma pergunta lê o mesmo resultado');
        $this->assertEquals(1, $bureau->created, 'D27: um envio');
        $this->assertThrows(InvalidArgumentException::class, fn() => new AsyncDataSource(static fn() => 't', static fn() => AsyncPoll::ready(1), $store, ttl: -1));

        // Value cached only when ready (D26)
        $cache = new InMemoryDataCache();
        $bureau2 = new Module36Bureau(['pending', 'done']);
        $resolver = new DataResolver((new DataSourceRegistry())->register('m36.cached', new AsyncDataSource(
            submit: static fn(DataRequest $r, string $key): string => $bureau2->createAnalysis((string) $r->get('cpf'), $key),
            poll: static fn(string $ticket): AsyncPoll => $bureau2->isDone($ticket) ? AsyncPoll::ready(800) : AsyncPoll::pending(),
            tickets: new InMemoryAsyncTicketStore(),
            ttl: 300
        )), $cache);
        $spec = Spec::external('m36.cached', ['cpf' => 'cpf'], Spec::greaterThan(700));
        $key = (new DataRequest('m36.cached', ['cpf' => '9']))->getKey();
        $this->assertTrue($resolver->evaluate($spec, (object) ['cpf' => '9'])->isPending() && !$cache->has($key), 'D25: pendente nunca vai para o cache');
        $this->assertTrue($resolver->evaluate($spec, (object) ['cpf' => '9'])->isSatisfied && $cache->has($key), 'D26: pronto vai para o cache pelo TTL');
        $polls = $bureau2->polls;
        $this->assertTrue($resolver->evaluate($spec, (object) ['cpf' => '9'])->isSatisfied && $bureau2->polls === $polls, 'D26: do cache, sem consultar');

        // Failure: the ticket is kept (terminal), unless resubmitAfterFailure
        foreach ([false => 1, true => 2] as $resubmit => $expectedCreated) {
            $failing = new Module36Bureau(['error']);
            $failingStore = new InMemoryAsyncTicketStore();
            $failingSource = new AsyncDataSource(
                submit: static fn(DataRequest $r, string $key): string => $failing->createAnalysis('c', $key),
                poll: static fn(string $ticket): AsyncPoll => $failing->next($ticket) === 'error' ? AsyncPoll::failed('rejected by the bureau') : AsyncPoll::ready(1),
                tickets: $failingStore,
                resubmitAfterFailure: (bool) $resubmit
            );
            $e = $this->assertThrows(DataFetchException::class, fn() => $failingSource->fetch($request));
            $this->assertTrue(str_contains($e->getMessage(), 'failed (RuntimeException)') && $e->getPrevious()?->getMessage() === 'rejected by the bureau', 'D26: failed vira sourceFailed com o motivo como anterior');
            $this->assertThrows(DataFetchException::class, fn() => $failingSource->fetch($request));
            $this->assertEquals($expectedCreated, $failing->created, 'D27: resubmitAfterFailure ' . var_export((bool) $resubmit, true));
        }

        // D27: a crash between sending and storing the ticket: never sent again; reconcile or fail closed
        foreach ([null, 'reconcile'] as $mode) {
            $crashing = new Module36Bureau(['done']);
            $crashed = false;
            $inner = new InMemoryAsyncTicketStore();
            $tickets = new CallableAsyncTicketStore(
                get: static fn(string $k): ?AsyncTicket => $inner->get($k),
                markSubmitting: static fn(string $k): bool => $inner->markSubmitting($k),
                storeTicket: static function (string $k, string $t) use ($inner, &$crashed): void {
                    if (!$crashed) {
                        $crashed = true;
                        throw new RuntimeException('process killed after the POST');
                    }
                    $inner->storeTicket($k, $t);
                },
                clear: static fn(string $k) => $inner->clear($k)
            );
            $crashSource = new AsyncDataSource(
                submit: static fn(DataRequest $r, string $key): string => $crashing->createAnalysis((string) $r->get('cpf'), $key),
                poll: static fn(string $ticket): AsyncPoll => AsyncPoll::ready($crashing->score($ticket)),
                tickets: $tickets,
                reconcile: $mode === null ? null : static fn(DataRequest $r, string $key): ?string => $crashing->findAnalysis((string) $r->get('cpf'))
            );
            $this->assertThrows(DataFetchException::class, fn() => $crashSource->fetch($request), 'D27: a queda é uma falha da busca');
            $this->assertTrue($inner->get($request->getKey())?->isSubmitting() === true, 'D27: fica "submitting"');
            if ($mode === null) {
                $e = $this->assertThrows(DataFetchException::class, fn() => $crashSource->fetch($request));
                $this->assertTrue(str_contains($e->getMessage(), 'outcome of a submitted request is unknown'), 'D27: sem reconcile, falha fechada');
                $this->assertThrows(DataFetchException::class, fn() => $crashSource->fetch($request), 'D27: e continua fechada');
                $inner->clear($request->getKey());
                $this->assertEquals(720, $crashSource->fetch($request), 'D27: limpar a chave libera um novo envio');
                $this->assertEquals(2, $crashing->created, 'D27: o novo envio só depois de limpar');
            } else {
                $this->assertEquals(720, $crashSource->fetch($request), 'D27: reconcile acha o ticket');
                $this->assertEquals(1, $crashing->created, 'D27: sem reenvio');
                $this->assertEquals('T1-' . substr(md5('111' . $request->getKey()), 0, 6), $inner->get($request->getKey())?->getTicket(), 'D27: o ticket reconciliado guardado');
            }
        }

        // Claimed by another process between get() and markSubmitting(): pending, nothing sent
        $raced = new Module36Bureau();
        $racedStore = new CallableAsyncTicketStore(
            get: static fn(string $k): ?AsyncTicket => null,
            markSubmitting: static fn(string $k): bool => false,
            storeTicket: static fn() => null,
            clear: static fn() => null
        );
        $racedSource = new AsyncDataSource(static fn(DataRequest $r, string $key): string => $raced->createAnalysis('x', $key), static fn() => AsyncPoll::ready(1), $racedStore);
        $this->assertThrows(DataPendingException::class, fn() => $racedSource->fetch($request), 'D28: chave tomada por outro processo = pendente');
        $this->assertEquals(0, $raced->created);
        $polled = new AsyncDataSource(
            static fn(DataRequest $r, string $key): string => $raced->createAnalysis('x', $key),
            static fn(string $t): AsyncPoll => AsyncPoll::ready('polled ' . $t),
            new CallableAsyncTicketStore(
                get: (static function () {
                    $calls = 0;
                    return static function (string $k) use (&$calls): ?AsyncTicket {
                        return ++$calls === 1 ? null : AsyncTicket::submitted('OTHER');
                    };
                })(),
                markSubmitting: static fn(string $k): bool => false,
                storeTicket: static fn() => null,
                clear: static fn() => null
            )
        );
        $this->assertEquals('polled OTHER', $polled->fetch($request), 'D28: o ticket do outro processo é consultado');

        // Closures that answer the wrong thing, and closures that raise
        foreach ([
            'submit sem ticket' => new AsyncDataSource(static fn(): string => '', static fn() => AsyncPoll::ready(1), new InMemoryAsyncTicketStore()),
            'poll sem AsyncPoll' => new AsyncDataSource(static fn(): string => 't', static fn() => 'ready', new InMemoryAsyncTicketStore()),
            'poll lança' => new AsyncDataSource(static fn(): string => 't', static fn() => throw new RuntimeException('x'), new InMemoryAsyncTicketStore()),
            'store lança' => new AsyncDataSource(static fn(): string => 't', static fn() => AsyncPoll::ready(1), new CallableAsyncTicketStore(static fn() => throw new RuntimeException('db down'), static fn() => true, static fn() => null, static fn() => null)),
            'store devolve lixo' => new AsyncDataSource(static fn(): string => 't', static fn() => AsyncPoll::ready(1), new CallableAsyncTicketStore(static fn() => 'x', static fn() => true, static fn() => null, static fn() => null)),
            'mark devolve lixo' => new AsyncDataSource(static fn(): string => 't', static fn() => AsyncPoll::ready(1), new CallableAsyncTicketStore(static fn() => null, static fn() => 1, static fn() => null, static fn() => null)),
            'reconcile devolve lixo' => new AsyncDataSource(static fn(): string => 't', static fn() => AsyncPoll::ready(1), new CallableAsyncTicketStore(static fn() => AsyncTicket::submitting(), static fn() => true, static fn() => null, static fn() => null), reconcile: static fn() => 5),
        ] as $label => $broken) {
            $e = $this->assertThrows(DataFetchException::class, fn() => $broken->fetch($request), "D26: {$label}");
            $expected = in_array($label, ['poll lança', 'store lança'], true) ? RuntimeException::class : \UnexpectedValueException::class;
            $this->assertTrue($e->getPrevious() instanceof $expected && $e->getPrevious()::class === $expected, "D26: {$label} com a causa " . $expected . ', ' . ($e->getPrevious() === null ? 'null' : $e->getPrevious()::class));
        }
        foreach ([
            'submit não texto' => new AsyncDataSource(static fn() => 5, static fn() => AsyncPoll::ready(1), new InMemoryAsyncTicketStore()),
            'reconcile vazio' => new AsyncDataSource(static fn(): string => 't', static fn() => AsyncPoll::ready(1), new CallableAsyncTicketStore(static fn() => AsyncTicket::submitting(), static fn() => true, static fn() => null, static fn() => null), reconcile: static fn() => ''),
        ] as $label => $broken) {
            $e = $this->assertThrows(DataFetchException::class, fn() => $broken->fetch($request), "D26: {$label}");
            $this->assertTrue($e->getPrevious()::class === \UnexpectedValueException::class, "D26: {$label} recusado pela fonte, não pelo store");
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => AsyncTicket::submitted(''));
        $this->assertTrue(AsyncTicket::submitting()->isSubmitting() && AsyncTicket::submitting()->getTicket() === null && !AsyncTicket::submitted('t')->isSubmitting());
        $poll = AsyncPoll::failed('why');
        $this->assertTrue($poll->isFailed() && !$poll->isReady() && !$poll->isPending() && $poll->getReason() === 'why' && $poll->getValue() === null && $poll->getRetryAfter() === null);
        $ready = AsyncPoll::ready(['a' => 1]);
        $this->assertTrue($ready->isReady() && $ready->getValue() === ['a' => 1] && $ready->getReason() === null);
        $memory = new InMemoryAsyncTicketStore();
        $this->assertTrue($memory->markSubmitting('k') && !$memory->markSubmitting('k'), 'D28: markSubmitting reivindica uma vez');
        $memory->storeTicket('k', 'T');
        $this->assertTrue(!$memory->markSubmitting('k') && $memory->get('k')?->getTicket() === 'T' && count($memory->all()) === 1);
        $memory->clear('k');
        $this->assertTrue($memory->get('k') === null && $memory->all() === []);
    }

    /**
     * RN-02 (L10): scripted providers (pending k times, then ready or failed; transport failures; a crash
     * between sending and storing) over many resolutions of many keys: at most one submission per key, a
     * pending datum never satisfied nor refused (NOT included), a value cached only when ready.
     */
    private function testAsyncAtMostOneSubmissionProperty(): void
    {
        mt_srand(4810);
        $bureau = new class {
            /** @var array<string, int> */
            public array $created = [];

            /** @var array<string, list<string>> */
            public array $script = [];

            /** @var array<string, string> */
            public array $byCpf = [];

            public function create(string $cpf): string
            {
                $this->created[$cpf] = ($this->created[$cpf] ?? 0) + 1;
                $ticket = 'T-' . $cpf . '-' . $this->created[$cpf];
                $steps = array_fill(0, mt_rand(0, 4), ['pending', 'raise'][mt_rand(0, 4) === 0 ? 1 : 0]);
                $steps[] = mt_rand(0, 5) === 0 ? 'error' : 'done';
                $this->script[$ticket] = $steps;
                $this->byCpf[$cpf] = $ticket;

                return $ticket;
            }

            public function poll(string $ticket): AsyncPoll
            {
                $step = count($this->script[$ticket]) > 1 ? array_shift($this->script[$ticket]) : $this->script[$ticket][0];

                return match ($step) {
                    'raise' => throw new RuntimeException('timeout'),
                    'pending' => AsyncPoll::pending(),
                    'error' => AsyncPoll::failed('refused'),
                    default => AsyncPoll::ready(650 + (int) substr(md5($ticket), 0, 2) % 100),
                };
            }
        };
        $inner = new InMemoryAsyncTicketStore();
        $crashes = 0;
        $store = new CallableAsyncTicketStore(
            get: static fn(string $k): ?AsyncTicket => $inner->get($k),
            markSubmitting: static fn(string $k): bool => $inner->markSubmitting($k),
            storeTicket: static function (string $k, string $t) use ($inner, &$crashes): void {
                if (mt_rand(0, 9) === 0) {
                    $crashes++;
                    throw new RuntimeException('crash between the POST and the ticket');
                }
                $inner->storeTicket($k, $t);
            },
            clear: static fn(string $k) => $inner->clear($k)
        );
        $cache = new InMemoryDataCache();
        $resolver = new DataResolver((new DataSourceRegistry())->register('m36.prop', new AsyncDataSource(
            submit: static fn(DataRequest $r): string => $bureau->create((string) $r->get('cpf')),
            poll: static fn(string $ticket): AsyncPoll => $bureau->poll($ticket),
            tickets: $store,
            ttl: 600,
            reconcile: static fn(DataRequest $r): ?string => $bureau->byCpf[(string) $r->get('cpf')] ?? null
        )), $cache);
        $spec = Spec::external('m36.prop', ['cpf' => 'cpf'], Spec::greaterThanOrEqualTo(700));
        $pending = 0;
        $ready = 0;
        for ($n = 0; $n < 600; $n++) {
            $cpf = (string) mt_rand(1, 40);
            $candidate = (object) ['cpf' => $cpf];
            $negated = mt_rand(0, 1) === 1;
            $result = $resolver->evaluate($negated ? Spec::not($spec) : $spec, $candidate);
            $key = (new DataRequest('m36.prop', ['cpf' => $cpf]))->getKey();
            if ($result->isPending()) {
                $pending++;
                $this->assertFalse($result->isSatisfied, "RN-02 L10 #{$n}: pendente nunca satisfeito (NOT incluído)");
                $this->assertFalse($cache->has($key), "RN-02 L10 #{$n}: pendente nunca no cache");
            } elseif (!$result->isError) {
                $ready++;
                $this->assertTrue($cache->has($key), "RN-02 L10 #{$n}: pronto no cache");
            }
            $this->assertTrue(($bureau->created[$cpf] ?? 0) <= 1, "RN-02 L10 #{$n}: no máximo um envio por chave (cpf {$cpf})");
        }
        $this->assertTrue($pending > 20 && $ready > 20 && $crashes > 0, "RN-02 L10: o roteiro exercita pendente ({$pending}), pronto ({$ready}) e queda ({$crashes})");
    }

    /**
     * RN-07 (e): the TShield of the Virttus (FetchCreditAnalysisAction, d4ef0f5, :107-139) simulated: create the
     * analysis, its query failing once (where the action returned ERROR without storing the query number and
     * POSTed again), pending, ready; and a crash between creating and storing: exactly one POST.
     */
    private function testTShieldSimulation(): void
    {
        $tshield = new Module36Bureau(['raise', 'pending', 'pending', 'done'], 655);
        $source = new AsyncDataSource(
            submit: static fn(DataRequest $r, string $key): string => $tshield->createAnalysis((string) $r->get('cpf'), $key),   // POST getCreditAnalysis()
            poll: static fn(string $queryNumber): AsyncPoll => match ($tshield->next($queryNumber)) {                          // queryCreditAnalysis()
                'done' => AsyncPoll::ready($tshield->score($queryNumber)),
                default => AsyncPoll::pending(),
            },
            tickets: new InMemoryAsyncTicketStore(),
            reconcile: static fn(DataRequest $r): ?string => $tshield->findAnalysis((string) $r->get('cpf'))
        );
        $resolver = new DataResolver((new DataSourceRegistry())->register('tshield.analysis', $source));
        $rule = Spec::external('tshield.analysis', ['cpf' => 'cpf'], Spec::greaterThanOrEqualTo(600))->withCode('SCORE');
        $candidate = (object) ['cpf' => '123.456.789-09'];
        $outcomes = [];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $r = $resolver->evaluate($rule, $candidate);
            $outcomes[] = $r->isPending() ? 'pending' : ($r->isError ? 'error' : ($r->isSatisfied ? 'approved' : 'refused'));
        }
        $this->assertEquals(['error', 'pending', 'pending', 'approved', 'approved'], $outcomes, 'RN-07 (e): a consulta que falha não reenvia');
        $this->assertEquals(1, $tshield->created, 'RN-07 (e): exatamente 1 POST');
    }


    /**
     * Edges pinned after the Infection run of the forward (mutants that survived the first suite): defaults,
     * bounds, refusals by the right exception, messages, and the state the TFilter subclasses leave behind.
     */
    private function testEdgesFoundByMutation(): void
    {
        // L1: trimmed types in the registry and in the messages
        $this->assertThrows(RuleEngineException::class, fn() => (new RuleSpecificationRegistry())->registerLookupOnly('t')->registerClosure(' t ', static fn(): ISpecification => Spec::alwaysTrue()), 'D1: closure com espaços também recusada');
        $handler = new class implements IRuleSpecificationHandler {
            public function supports(string $ruleType): bool
            {
                return $ruleType === 'stage';
            }

            public function build(IRuleDefinition $rule): ISpecification
            {
                return Spec::alwaysTrue();
            }
        };
        $registry = (new RuleSpecificationRegistry([$handler]))->registerLookupOnly('stage');
        $padded = new RuleDefinition(code: 'p', name: 'P', ruleType: ' stage ', scope: 's');
        $e = $this->assertThrows(RuleEngineException::class, fn() => (new DynamicSpecificationEngine(new InMemoryRuleCatalog([$padded]), $registry))->compileSpecification('s'));
        $this->assertTrue(str_contains($e->getMessage(), 'type "stage" (rule p)'), 'D1: o tipo sem espaços na mensagem do motor: ' . $e->getMessage());
        $e = $this->assertThrows(RuleEngineException::class, fn() => $registry->buildSpecification($padded));
        $this->assertTrue(str_contains($e->getMessage(), 'type "stage":'), 'D1: e na do registro: ' . $e->getMessage());
        $this->assertEquals(['stage'], $registry->getLookupOnlyTypes());

        // L2: legacy keys refused even when no rule has the type; ties and generators
        $catalog = new InMemoryRuleCatalog([new RuleDefinition(code: 'a', name: 'a', ruleType: 't', scope: 's')]);
        $this->assertThrows(\Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException::class, fn() => $catalog->findRulesByType('s', 'none', ['codigo_produto' => 'P']), 'D4: chave legada recusada sem regra do tipo');
        $first = new RuleDefinition(code: 'A', name: 'first', ruleType: 't', scope: 's');
        $second = new RuleDefinition(code: 'A', name: 'second', ruleType: 't', scope: 's');
        $this->assertTrue(RulePrecedence::mostSpecific([$first, $second], 't', null) === $first, 'D5: empate total, o primeiro lido');
        $sameKey = (static function () {
            yield 'k' => new RuleDefinition(code: 'X', name: 'X', ruleType: 't', scope: 's', scenario: 'x');
            yield 'k' => new RuleDefinition(code: 'Y', name: 'Y', ruleType: 't', scope: 's', scenario: 'y');
        })();
        $this->assertEquals(['x', 'y'], array_keys(RulePrecedence::byScenario($sameKey, 't')), 'D5: gerador com chaves repetidas');
        $recording = new PdoRecordingPdo('sqlite::memory:');
        $recording->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($recording, (string) file_get_contents(self::DDL));
        $recording->reset();
        (new PdoRuleCatalog($recording))->findRules('s', 'x', ['product_code' => 'P']);
        $sql = $recording->statements[0];
        $scope = strpos($sql, ':scope OR');
        $scenario = strpos($sql, 'scenario = :scenario');
        $product = strpos($sql, ':f_product_code');
        $this->assertTrue($scope !== false && $scenario !== false && $product !== false && $scope < $scenario && $scenario < $product, 'D6: o SQL do findRules() na ordem de 2.4.0: ' . $sql);

        // L3: because() alone and withCode() alone on the label
        $onlyReason = Spec::property('a', Spec::lessThan(1)->withCode('INNER'))->labelled('L')->because('R')->evaluate((object) ['a' => 5])->failures[0];
        $this->assertEquals(['R', 'INNER'], [$onlyReason->message, $onlyReason->code], 'D10: só because() troca a mensagem');
        $onlyCode = Spec::property('a', Spec::lessThan(1)->because('inner message'))->labelled('L')->withCode('C')->evaluate((object) ['a' => 5])->failures[0];
        $this->assertEquals(['inner message', 'C'], [$onlyCode->message, $onlyCode->code], 'D10: só withCode() troca o código');
        $this->assertTrue(\Antevemus\ASpecification\Specifications\SpecificationAlgebra::refusesNull(Spec::labelled('L', Spec::greaterThan(5))) && !\Antevemus\ASpecification\Specifications\SpecificationAlgebra::refusesNull(Spec::labelled('L', Spec::isNull())), 'D11: refusesNull() vê o rotulado');
        $this->assertTrue(Spec::lessThanOrEqualTo(5)->isGeneralizationOf(Spec::not(Spec::labelled('L', Spec::greaterThan(5)))), 'D11: a negação do rotulado se inverte');

        // L4: the filter keeps the TFilter fields; the case flag is ignored
        $filter = new TAccentInsensitiveFilter('name', new PostgreSqlDialect(), LikePattern::exact('a'), true);
        $this->assertEquals(['name', 'NOT LIKE', 'a'], [$filter->getVariable(), $filter->getOperator(), $filter->getValue()], 'D14: os campos do TFilter');
        $this->assertEquals('LIKE', (new TAccentInsensitiveFilter('name', new PostgreSqlDialect(), '^a'))->getOperator());
        $filter->setCaseInsensitive(true);
        $this->assertFalse($filter->getCaseInsensitive(), 'D14: setCaseInsensitive() ignorado');

        // L5: the subquery filter keeps its fields, forwards the case flag and is cloned deep
        $criteria = Spec::toCriteria(Spec::inSubquery('a', 't', 'id', Spec::property('n', Spec::wildcard('x*'))));
        $subquery = $criteria->getExpressions()[0];
        $this->assertEquals(['a', 'IN', ''], [$subquery->getVariable(), $subquery->getOperator(), $subquery->getValue()], 'D19: os campos do TFilter');
        $this->assertEquals("a IN (SELECT sq.id FROM t sq WHERE sq.id IS NOT NULL AND (sq.n LIKE 'x%'))", $subquery->dump(), 'D19: dump() sem argumento = sem prepared, como o TFilter');
        $criteria->setCaseInsensitive(true);
        $this->assertEquals("(a IN (SELECT sq.id FROM t sq WHERE sq.id IS NOT NULL AND (UPPER(sq.n) LIKE UPPER('x%'))))", $criteria->dump(), 'D19: o flag chega às folhas internas');
        $original = Spec::toCriteria(Spec::inSubquery('a', 't', 'id', Spec::property('n', Spec::equalTo('v'))));
        $clone = clone $original;
        $clone->dump(true);
        $this->assertEquals([], $original->getPreparedVars(), 'D19: o clone tem o seu critério interno');
        $this->assertThrows(UnsafeIdentifierException::class, fn() => InSubquerySpecification::segments('a;b'), 'D18: segments() valida');
        $this->assertEquals('EXISTS (SELECT 1 FROM `a``b` `sq` WHERE `sq`.`fk` = `o`.`id` AND (1 = 1))', Spec::toSql(Spec::existsIn('"a`b"', ['fk' => 'id'], Spec::alwaysTrue(), 'o'), 'mysql')->toSql(), 'D18: a crase dobrada dentro do segmento');
        $this->assertEquals('EXISTS (SELECT 1 FROM [x]]y] [sq] WHERE [sq].[fk] = [o].[id] AND (1 = 1))', Spec::toSql(Spec::existsIn('"x]y"', ['fk' => 'id'], Spec::alwaysTrue(), 'o'), 'sqlsrv')->toSql(), 'D18: o colchete dobrado no SQL Server');
        $this->assertEquals('Unsafe SQL identifier rejected: "x y". Only plain or qualified identifiers ([A-Za-z_][A-Za-z0-9_$]*, optionally dot-qualified) or simple function calls over identifiers (e.g. LOWER(name)) are accepted.', (new UnsafeIdentifierException('x y'))->getMessage(), 'a mensagem dos dialetos inalterada');

        // L10: defaults and bounds
        $source = new AsyncDataSource(static fn(): string => 't', static fn() => AsyncPoll::ready(1), new InMemoryAsyncTicketStore(), ttl: 0);
        $this->assertEquals([0, 0], [$source->getCost(), $source->getTtl()], 'D26: custo padrão 0, TTL 0 aceito');
        $this->assertEquals(0, AsyncPoll::pending(0)->getRetryAfter(), 'D26: retryAfter 0 aceito');
        $pending = new DataPendingException('src', 'T', 0, new RuntimeException('cause'));
        $this->assertEquals([0, 'Data source "src" accepted the request; its result is not ready yet.', 'cause', 0, 'T'], [$pending->getCode(), $pending->getMessage(), $pending->getPrevious()?->getMessage(), $pending->getRetryAfter(), $pending->getTicket()], 'D25: a exceção');
        $store = new CallableAsyncTicketStore(static fn() => 'x', static fn() => 1, static fn() => null, static fn() => null);
        $this->assertThrows(\UnexpectedValueException::class, fn() => $store->get('k'), 'D28: get() que não devolve AsyncTicket');
        $this->assertThrows(\UnexpectedValueException::class, fn() => $store->markSubmitting('k'), 'D28: markSubmitting() que não devolve bool');
        $this->assertTrue((new CallableAsyncTicketStore(static fn() => null, static fn() => true, static fn() => null, static fn() => null))->get('k') === null, 'D28: null é "nada"');
        $sources = (new DataSourceRegistry())
            ->register('m36.p1', new AsyncDataSource(static fn(): string => 'T1', static fn() => AsyncPoll::pending(), new InMemoryAsyncTicketStore()))
            ->register('m36.p2', new AsyncDataSource(static fn(): string => 'T2', static fn() => AsyncPoll::pending(), new InMemoryAsyncTicketStore()))
            ->register('m36.fail', new \Antevemus\ASpecification\Resolution\CallableDataSource(static fn() => throw new RuntimeException('down')));
        $tree = Spec::external('m36.p1', ['x' => 'x'], Spec::isTrue())->and(Spec::external('m36.p2', ['x' => 'x'], Spec::isTrue()))->and(Spec::external('m36.fail', ['x' => 'x'], Spec::isTrue()));
        $data = (new DataResolver($sources))->resolve($tree, (object) ['x' => 1]);
        $this->assertEquals(['m36.p1', 'm36.p2'], array_map(static fn(DataRequest $r): string => $r->source, $data->getPendingRequests()), 'D25: só os pendentes, todos eles');
        $this->assertCount(3, $data->getFailedRequests());
    }

    ///////////////////////////////////////////////////////////////////////////
    // L11: Adianti as the host
    ///////////////////////////////////////////////////////////////////////////

    /**
     * §1.1 item 40: TransactionMiddleware(fn() => TTransaction::open(...)) inside a screen that already opened a
     * transaction opens a SECOND connection: the history is committed apart and survives the screen's rollback.
     * The recipe of README 22.3 joins the open transaction (one connection, the screen decides).
     */
    private function testAdiantiHostDefectAndRecipe(): void
    {
        $file = $this->claimDatabase();
        TTransaction::reset();
        TTransaction::register('rental', ['type' => 'sqlite', 'name' => $file, 'prep' => '1']);
        $useCase = new OpenClaim();

        $naive = Spec::useCaseRunner(middlewares: [new TransactionMiddleware(
            static fn() => TTransaction::open('rental'),
            static fn() => TTransaction::close(),
            static fn() => TTransaction::rollback()
        )]);
        $screen = TTransaction::open('rental');
        $result = $naive->run($useCase, (object) ['claimId' => 1]);
        $this->assertTrue($result->isSuccess() && TTransaction::get() === $screen, 'L11: o ingênuo abriu e fechou a sua própria conexão');
        TTransaction::rollback();
        $this->assertEquals(1, $this->historyCount($file), 'L11 (§1.1 item 40): a gravação sobreviveu ao rollback da tela (outra conexão)');

        $joined = Spec::useCaseRunner(middlewares: [(new AdiantiTransaction('rental'))->middleware()]);
        $screen = TTransaction::open('rental');
        $this->assertTrue($joined->run($useCase, (object) ['claimId' => 2])->isSuccess());
        $this->assertTrue(TTransaction::get() === $screen, 'D30: a mesma conexão');
        TTransaction::rollback();
        $this->assertEquals(1, $this->historyCount($file), 'D30: uma transação só: o rollback da tela desfaz o histórico');

        $this->assertTrue(TTransaction::get() === null);
        $this->assertTrue($joined->run($useCase, (object) ['claimId' => 3])->isSuccess() && TTransaction::get() === null, 'D30: sem transação aberta, abre e fecha a sua');
        $this->assertEquals(2, $this->historyCount($file), 'D30: confirmada');
        $failing = new class implements IUseCase {
            public function execute(object $request): UseCaseResult
            {
                TTransaction::get()->prepare('INSERT INTO claim_history (claim_id, event) VALUES (?, ?)')->execute([9, 'failed']);

                return UseCaseResult::rejected(SpecificationResult::failure('Claim not allowed.', 'CLAIM_RULE'));
            }
        };
        $rejected = $joined->run($failing, (object) []);
        $this->assertTrue($rejected->getStatus() === UseCaseStatus::REJECTED && TTransaction::get() === null && $this->historyCount($file) === 2, 'D30: falha desfaz o que ela abriu');

        // D30 alternative: PdoTransactionManager over TTransaction::get() joins the open transaction
        $screen = TTransaction::open('rental');
        $manager = new \Antevemus\ASpecification\Persistence\PdoTransactionManager(TTransaction::get());
        $this->assertTrue($manager->inTransaction(), 'D30: PdoTransactionManager vê a transação aberta');
        $manager->transactional(static fn() => TTransaction::get()->exec("INSERT INTO claim_history (claim_id, event) VALUES (4, 'joined')"));
        TTransaction::rollback();
        $this->assertEquals(2, $this->historyCount($file), 'D30: e entra nela (desfeita com a tela)');

        // D31: the adapter
        TMessage::$shown = [];
        TToast::$shown = [];
        UseCaseFeedback::show($rejected, 'Done.');
        UseCaseFeedback::show(UseCaseResult::accepted('job-1'), 'Done.');
        UseCaseFeedback::show(UseCaseResult::failed('BOOM', 'SQLSTATE[23505] duplicate key value')->withCorrelationId('c-9'), 'Done.');
        UseCaseFeedback::show(UseCaseResult::noChange(), 'Done.');
        UseCaseFeedback::show(UseCaseResult::invalid(SpecificationResult::failure('Name is required.', 'NAME')), 'Done.');
        $this->assertEquals([
            ['type' => 'error', 'message' => 'Claim not allowed.', 'title' => ''],
            ['type' => 'error', 'message' => 'Unexpected error. Reference: c-9', 'title' => ''],
            ['type' => 'error', 'message' => 'Name is required.', 'title' => ''],
        ], TMessage::$shown, 'D31: as mensagens das regras; FAILED com a referência, nunca a exceção');
        $this->assertEquals([['type' => 'info', 'message' => 'Received: being processed.'], ['type' => 'success', 'message' => 'Done.']], TToast::$shown, 'D31: ACCEPTED e sucesso no TToast');
        TTransaction::reset();
        @unlink($file);
    }

    private function claimDatabase(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'aspec048') ?: throw new RuntimeException('tempnam');
        $pdo = new PDO('sqlite:' . $file);
        $pdo->exec('CREATE TABLE claim_history (claim_id INT NOT NULL, event VARCHAR(20) NOT NULL)');

        return $file;
    }

    private function historyCount(string $file): int
    {
        return (int) (new PDO('sqlite:' . $file))->query('SELECT COUNT(*) FROM claim_history')->fetchColumn();
    }

    ///////////////////////////////////////////////////////////////////////////
    // README 22 to 22.3 (transcribed; the transcription is checked against the README)
    ///////////////////////////////////////////////////////////////////////////

    private function testReadme22(): void
    {
        // README 22 >>>
        $catalog = new InMemoryRuleCatalog([
            new RuleDefinition(code: 'max_claims', name: 'Ongoing claims', ruleType: 'claim_max', intValue: 2, violationMessage: 'Too many ongoing claims.', scope: 'claim'),
            // Values the application reads: they validate nothing
            new RuleDefinition(code: 'notice_days', name: 'Notice window', ruleType: 'claim_notice', intValue: 30, scope: 'claim'),
            new RuleDefinition(code: 'notice_days_vacant', name: 'Notice window, vacant', ruleType: 'claim_notice', intValue: 45, scope: 'claim', scenario: 'vacant'),
        ]);
        $facts = ['ongoing' => 3];

        $registry = (new RuleSpecificationRegistry())
            ->registerClosure('claim_max', fn(RuleDefinition $rule) => Spec::property('ongoing', Spec::lessThanOrEqualTo($rule->getIntValue()))
                ->withCode($rule->getCode())
                ->because($rule->getViolationMessage())
                ->labelled("Ongoing claims: {$facts['ongoing']} (maximum {$rule->getIntValue()})"))
            ->registerLookupOnly('claim_notice');                       // without it: MissingRuleHandlerException

        $engine = new DynamicSpecificationEngine($catalog, $registry);
        $verdict = $engine->validate($facts, 'claim');
        $failure = $verdict->getBlockingFailures()[0];
        $r1 = $failure->property;                                      // 'Ongoing claims: 3 (maximum 2)'
        $r2 = $failure->metadata['property_path'];                     // 'ongoing'
        $r3 = $engine->getSkippedRules()[0]->getCode();                // 'notice_days'

        // The most specific rule of a type: the scenario's, else the scope-global one
        $r4 = RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();     // 45
        $r5 = RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'occupied')->getIntValue();   // 30
        $byScenario = RulePrecedence::byScenario($catalog->findRulesByType('claim', 'claim_notice'), 'claim_notice');
        $r6 = array_map(fn(RuleDefinition $rule): int => $rule->getIntValue(), $byScenario);           // ['vacant' => 45]: one read
        // <<< README 22

        $this->assertEquals('Ongoing claims: 3 (maximum 2)', $r1, 'README 22: o alvo');
        $this->assertEquals('ongoing', $r2);
        $this->assertEquals('notice_days', $r3);
        $this->assertEquals(45, $r4);
        $this->assertEquals(30, $r5);
        $this->assertEquals(['vacant' => 45], $r6);
        $this->assertTranscribed('22', 0, 'README 22');
    }

    private function testReadme221(): void
    {
        if (extension_loaded('intl')) {
            // README 22.1 accents >>>
            $byName = Spec::property('name', Spec::contains('jose', TextComparison::IgnoreCaseAndAccents));
            $criteria = Spec::toCriteria($byName, accentFolding: new PostgreSqlDialect('f_fold'));   // the function of example 20
            $r1 = $criteria->dump(true);         // (f_fold(lower(name)) LIKE f_fold(lower(:aspf_1)))
            $r2 = $criteria->getPreparedVars();  // [':aspf_1' => '%jose%']
            // <<< README 22.1 accents
            $this->assertEquals(1, preg_match('/^\(f_fold\(lower\(name\)\) LIKE f_fold\(lower\((:aspf_\d+)\)\)\)$/', $r1, $m), 'README 22.1: ' . $r1);
            $this->assertEquals([$m[1] => '%jose%'], $r2);
            $this->assertTranscribed('22.1', 0, 'README 22.1 accents');
        }

        // README 22.1 >>>
        $moduleId = 7;
        $states = Spec::inSubquery('stateId', 'module_states', 'id',
            Spec::property('moduleId', Spec::equalTo($moduleId))->and(Spec::property('deletedAt', Spec::isNull())));
        $criteria = Spec::toCriteria($states);
        $r3 = $criteria->dump(true);
        // (state_id IN (SELECT sq.id FROM module_states sq WHERE sq.id IS NOT NULL AND (sq.module_id = :par_1 AND sq.deleted_at IS NULL)))
        $r4 = $criteria->getPreparedVars();  // [':par_1' => 7]

        // A related row exists: one statement for a query written by hand
        $withReport = Spec::existsIn('claim_document', ['claim_id' => 'id'], Spec::property('kind', Spec::equalTo('report')), 'claim');
        $where = Spec::toSql($withReport, 'pgsql');
        $r5 = $where->toSql();
        // EXISTS (SELECT 1 FROM "claim_document" "sq" WHERE "sq"."claim_id" = "claim"."id" AND (("sq"."kind" IS NOT NULL AND "sq"."kind" = :ps1_1)))
        $r6 = $where->getParameters();       // [':ps1_1' => 'report']
        $r7 = $withReport->evaluate((object) ['id' => 1])->isError;   // true: a subquery is evaluated by the database only
        // <<< README 22.1

        $this->assertEquals(1, preg_match('/^\(state_id IN \(SELECT sq\.id FROM module_states sq WHERE sq\.id IS NOT NULL AND \(sq\.module_id = (:par_\d+) AND sq\.deleted_at IS NULL\)\)\)$/', $r3, $m), 'README 22.1: ' . $r3);
        $this->assertEquals([$m[1] => 7], $r4);
        $this->assertEquals('EXISTS (SELECT 1 FROM "claim_document" "sq" WHERE "sq"."claim_id" = "claim"."id" AND (("sq"."kind" IS NOT NULL AND "sq"."kind" = :ps1_1)))', $r5);
        $this->assertEquals([':ps1_1' => 'report'], $r6);
        $this->assertTrue($r7);
        $this->assertTranscribed('22.1', 0, 'README 22.1');
    }

    private function testReadme222(): void
    {
        $bureau = new Module36Bureau(['pending', 'pending', 'done'], 720);

        // README 22.2 >>>
        $sources = (new DataSourceRegistry())->register('bureau.score', new AsyncDataSource(
            submit: fn(DataRequest $request, string $key): string => $bureau->createAnalysis($request->get('cpf'), $key),
            poll: fn(string $ticket): AsyncPoll => $bureau->isDone($ticket) ? AsyncPoll::ready($bureau->score($ticket)) : AsyncPoll::pending(retryAfter: 30),
            tickets: new InMemoryAsyncTicketStore(),         // in production, a table of the application (CallableAsyncTicketStore)
            reconcile: fn(DataRequest $request): ?string => $bureau->findAnalysis($request->get('cpf')),
        ));
        $scoreOk = Spec::external('bureau.score', ['cpf' => 'cpf'], Spec::greaterThanOrEqualTo(700))->withCode('SCORE_LOW');
        $resolver = new DataResolver($sources);
        $tenant = (object) ['cpf' => '111.444.777-35'];

        $first = $resolver->evaluate($scoreOk, $tenant);
        $r1 = $first->isPending();                                       // true: submitted, not ready: neither approved nor refused
        $r2 = $resolver->evaluate(Spec::not($scoreOk), $tenant)->isSatisfied;   // false: NOT does not invert a pending datum
        // ... the next request, or the next run of the job, once the bureau is done
        $r3 = $resolver->evaluate($scoreOk, $tenant)->isSatisfied;      // true (score 720)
        $r4 = $bureau->created;                                          // 1: the analysis was created once
        // <<< README 22.2

        $this->assertTrue($r1, 'README 22.2: pendente');
        $this->assertFalse($r2, 'README 22.2: NOT não inverte');
        $this->assertTrue($r3, 'README 22.2: pronto');
        $this->assertEquals(1, $r4, 'README 22.2: um envio');
        $this->assertTranscribed('22.2', 0, 'README 22.2');
    }

    private function testReadme223(): void
    {
        $file = $this->claimDatabase();
        TTransaction::reset();
        TTransaction::register('rental', ['type' => 'sqlite', 'name' => $file, 'prep' => '1']);
        TToast::$shown = [];

        // README 22.3 >>>
        $useCases = Spec::useCaseRunner(middlewares: [(new AdiantiTransaction('rental'))->middleware()]);

        TTransaction::open('rental');                                            // the screen's own transaction
        $result = $useCases->run(new OpenClaim(), (object) ['claimId' => 7]);    // joins it: one connection, one commit
        $result->isSuccess() ? TTransaction::close() : TTransaction::rollback();
        UseCaseFeedback::show($result, 'Claim opened.');                         // TToast: 'Claim opened.'
        // <<< README 22.3

        $this->assertEquals(UseCaseStatus::CREATED, $result->getStatus());
        $this->assertEquals(1, $this->historyCount($file), 'README 22.3: confirmado pela tela');
        $this->assertEquals([['type' => 'success', 'message' => 'Claim opened.']], TToast::$shown);
        $this->assertTrue(TTransaction::get() === null);
        $this->assertTranscribed('22.3', 0, 'README 22.3 classes');
        $this->assertTranscribed('22.3', 0, 'README 22.3');
        @unlink($file);
    }

    private function testReadme22BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['22', '22.1', '22.2', '22.3'] as $example) {
            $english = ReadmeBlocks::of($root . '/README.md', $example);
            $portuguese = ReadmeBlocks::of($root . '/README.pt-BR.md', $example);
            $this->assertCount(1, $english, "um bloco PHP no exemplo {$example} (EN)");
            $this->assertCount(1, $portuguese, "um bloco PHP no exemplo {$example} (pt-BR)");
            $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), "README {$example}: bloco igual fora os comentários");
        }
        // D29/D33: the recipe of docs/adianti/README.md is the block of README 22.3
        $docs = (string) file_get_contents($root . '/docs/adianti/README.md');
        preg_match_all('/```php\n(.*?)```/s', $docs, $blocks);
        $this->assertCount(1, $blocks[1], 'docs/adianti: um bloco PHP');
        $this->assertEquals(ReadmeBlocks::codeTokens(ReadmeBlocks::of($root . '/README.md', '22.3')[0]), ReadmeBlocks::codeTokens($blocks[1][0]), 'docs/adianti: o bloco do README 22.3');
    }

    /**
     * The code between the markers `README <name> >>>` and `<<< README <name>` of this file is, token by token
     * (comments and blanks aside), a contiguous part of block $index of the README example $example.
     */
    private function assertTranscribed(string $example, int $index, string $name): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// {$name} >>>\n");
        $end = strpos($source, "// <<< {$name}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcrição {$name} marcada");
        // The transcription may keep an expression the README shows bare as `$rN = <expression>`
        $tokens = ReadmeBlocks::codeTokens(substr($source, $start, $end - $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        $blocks = ReadmeBlocks::of(dirname(__DIR__, 2) . '/README.md', $example);
        $this->assertTrue(isset($blocks[$index]), "README {$example}: bloco {$index}");
        $block = ReadmeBlocks::codeTokens($blocks[$index] ?? '');
        $found = false;
        $length = count($transcribed);
        for ($i = 0; $i + $length <= count($block) && !$found; $i++) {
            $found = array_slice($block, $i, $length) === $transcribed;
        }
        $this->assertTrue($length > 10 && $found, "{$name}: a transcrição é o código do README {$example}");
    }

    ///////////////////////////////////////////////////////////////////////////
    // Support
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Dumps of the cases by the REAL Adianti TFilter/TCriteria, per version found next to the repository
     * (templates 8.1.0 and 8.7.0 of Antevemus.AdiantiTemplates: their TExpression, TFilter and TCriteria are
     * byte-identical to the copies of the Virttus (8.1) and of the AflowEngineApp (8.7)).
     *
     * @param array<string, array<string, mixed>> $cases
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function realAdiantiDumps(array $cases): array
    {
        $base = dirname(__DIR__, 3) . '/Antevemus.AdiantiTemplates';
        $found = [];
        foreach (['8.1' => 'adianti-template-8.1.0', '8.7' => 'adianti-template-8.7.0'] as $version => $dir) {
            $path = "{$base}/{$dir}/lib/adianti/database";
            if (is_file($path . '/TCriteria.php')) {
                $found[$version] = $path;
            }
        }
        if ($found === []) {
            fwrite(STDOUT, "    [AVISO] Adianti real não encontrado em {$base}; só o stub do TCriteria.\n");
            return [];
        }
        $file = tempnam(sys_get_temp_dir(), 'aspec048cases') ?: throw new RuntimeException('tempnam');
        file_put_contents($file, json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $out = [];
        foreach ($found as $version => $path) {
            $json = (string) shell_exec(sprintf(
                '%s -d xdebug.mode=off -d memory_limit=512M %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__) . '/Support/real_adianti_048_probe.php'),
                escapeshellarg($path),
                escapeshellarg($file)
            ));
            $decoded = json_decode(trim((string) preg_replace('/^.*Cannot load Xdebug.*$/m', '', $json)), true);
            $this->assertTrue(is_array($decoded), "sonda do Adianti real {$version} não devolveu JSON: " . substr($json, 0, 500));
            $out[$version] = is_array($decoded) ? $decoded : [];
        }
        @unlink($file);

        return $out;
    }

    /**
     * The ids of $table selected by a condition (named parameters bound as TRepository::load() does with
     * prep = 1: execute() with the prepared variables).
     *
     * @param array<string, mixed> $parameters
     * @return list<int>
     */
    private function ids(PDO $pdo, string $table, string $condition, array $parameters): array
    {
        $statement = $pdo->prepare("SELECT id FROM {$table} WHERE {$condition} ORDER BY id");
        $statement->execute(array_map(static fn(mixed $v): mixed => is_bool($v) ? (int) $v : $v, $parameters));

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<IRuleDefinition> $rules
     * @return list<string>
     */
    private static function codes(array $rules): array
    {
        return array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $rules);
    }

    /** @return list<array{0: string, 1: ?string, 2: ?string, 3: mixed}> */
    private static function failureShape(SpecificationResult $result): array
    {
        return array_map(static fn(SpecificationFailure $f): array => [$f->message, $f->code, $f->property, $f->metadata['action'] ?? null], $result->failures);
    }

    private function sqliteEmpty(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function sqliteCatalog(): PdoRecordingPdo
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));

        return $pdo;
    }

    private function dropCatalog(PDO $pdo): void
    {
        foreach (['document_requirement', 'document_requirement_group', 'business_rule'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /** Runs a DDL script: drops `--` comment lines and executes each `;`-terminated statement. */
    private static function loadDdl(PDO $pdo, string $ddl): void
    {
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
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
