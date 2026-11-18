<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\InMemoryMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;
use Antevemus\ASpecification\Tests\PhpUnit\InMemoryContractTest;
use Antevemus\ASpecification\Tests\PhpUnit\SqliteContractTest;
use Antevemus\ASpecification\Tests\Support\Module49Fault;
use Antevemus\ASpecification\Tests\Support\Module49FaultyContract;
use Antevemus\ASpecification\Tests\Support\Module49LowStepsContract;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\SqliteSatellite\SqliteMessageSource;
use Antevemus\ASpecification\Tests\Support\SqliteSatellite\SqliteMessageTransport;
use Antevemus\ASpecification\Tests\Support\SqliteSatellite\SqliteMessagingBench;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use ErrorException;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\TextUI\CliArguments\Builder as CliArgumentsBuilder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use TypeError;

/**
 * Module49_MessagingContractBatteryTest - Forward 064 (2.15.0): the public contract battery of the messaging hook.
 *
 * - D1/D5: MessagingContractTestCase, the battery of src/Messaging/Testing/, runs scenario by scenario (setUp, the
 *   scenario, tearDown; every data set of the property sequence) against the in-memory reference
 *   (InMemoryMessagingBench, through tests/PhpUnit/InMemoryContractTest.php, example 27) and against the didactic
 *   SQLite satellite of the manual (SqliteMessagingBench, through tests/PhpUnit/SqliteContractTest.php, examples 27.1
 *   to 27.4): every scenario passes on both;
 * - D3/D4: the battery is not vacuous: over the in-memory reference with one fault planted (Module49FaultyBench, one
 *   per item of the contract), the scenario of the broken item fails on an assertion; a satellite that lowers the
 *   steps of the property sequence below MIN_PROPERTY_STEPS fails; the battery never skips and its seeds and minimum
 *   are final constants;
 * - the coverage guard: every scenario of the battery is declared here (with the private battery it came from) and
 *   runs on both benches, so a scenario added to the battery that no module runs fails; every file of
 *   src/Messaging/Testing/ is declared, imported, loaded, `@version 2.15.0`, strict types;
 * - D1: nothing else of src/ references Messaging\Testing nor PHPUnit; PHPUnit is a `suggest` of composer.json, never
 *   a `require`;
 * - D6: examples 27 to 27.4 of docs/<language>/messaging/writing-a-satellite.md are the code the battery runs, token
 *   by token, the same code in both languages.
 *
 * The battery is a PHPUnit TestCase. Under tests/run_all.php it runs without the PHPUnit runner: each scenario is a
 * plain method call on an instance of the concrete class, its assertions (PHPUnit\Framework\Assert) counted here and
 * taken out of the static count of PHPUnit, so that the bridge (MessagingContractBatteryModuleTest) reports them once.
 *
 * @version    2.15.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Module49_MessagingContractBatteryTest extends TestCase
{
    /** Every scenario of the battery, with the private battery (before 2.15.0) it came from. */
    public const SCENARIOS = [
        'testARoundTripDeliversTheMessageAsPublished' => 'Redis Streams and RabbitMQ',
        'testEveryConsumerGetsEveryMessage' => 'RabbitMQ',
        'testMessagesOfAKeyKeepTheirOrderAcrossNames' => 'Redis Streams',
        'testMessagesOfAKeyKeepTheirOrderAcrossBatches' => 'RabbitMQ',
        'testARequeuedDeliveryComesBackBeforeTheLaterOnesOfItsKey' => 'Redis Streams',
        'testARequeuedDeliveryComesBackBeforeTheOnesLeftBehind' => 'RabbitMQ',
        'testARequeuedDeliveryComesBackFirstOneDeliveryAtATime' => 'Redis Streams',
        'testAMessageThatKeepsFailingIsDeadLetteredAfterMaxDeliveries' => 'Redis Streams and RabbitMQ',
        'testABodyThatIsNotAMessageIsDeadLetteredNeverDropped' => 'Redis Streams and RabbitMQ',
        'testAMessageWithoutAHandlerIsAcknowledgedWithAWarning' => 'Redis Streams and RabbitMQ',
        'testAConsumerOfOneTenantAcknowledgesTheOthersWithoutHandling' => 'Redis Streams and RabbitMQ',
        'testTheLargestPayloadTravelsWhole' => 'Redis Streams and RabbitMQ',
        'testBatchesOfMixedKeysKeepEveryKeyInOrder' => 'Redis Streams',
        'testABatchOfManyKeysKeepsEveryKeyInOrder' => 'RabbitMQ',
        'testADuplicateIsAbsorbedByTheInbox' => 'Redis Streams and RabbitMQ',
        'testARelayThatDiesBetweenTheConfirmationAndTheMarkSendsAgainAndTheEffectRunsOnce' => 'Redis Streams (contract) and RabbitMQ (broker failure scenarios)',
        'testTheDeliveriesOfADeadConsumerGoToItsSuccessor' => 'Redis Streams and RabbitMQ',
        'testAProcessStartedAgainGetsWhatItLeftInFlight' => 'Redis Streams',
        'testALostConnectionFailsTheWholeBatchAndMarksNothing' => 'Redis Streams',
        'testNoReasonReportOrExceptionCarriesThePayload' => 'Redis Streams (contract) and RabbitMQ (broker failure scenarios)',
        'testEachBenchIsAWorldOfItsOwn' => 'new in 2.15.0 (the isolation of D2)',
        'testRandomInterleavingsKeepTheGuarantees' => 'Redis Streams (MessagingPropertyTest) and RabbitMQ (InterleavingPropertyTest)',
    ];

    /** Every file of src/Messaging/Testing/, by path without `.php` (the coverage guard; Module 48 guards the rest). */
    public const TESTING_FILES = ['IMessagingBench', 'InMemoryMessagingBench', 'MessagingContractTestCase'];

    /** The concrete batteries every scenario runs on: the reference and the didactic satellite. */
    private const BENCHES = [InMemoryContractTest::class, SqliteContractTest::class];

    /** Each planted fault and the scenarios of the item it breaks (each must fail on an assertion). */
    private const FAULTS = [
        'confirms_without_storing' => ['testARoundTripDeliversTheMessageAsPublished', 'testRandomInterleavingsKeepTheGuarantees'],
        'drops_headers' => ['testARoundTripDeliversTheMessageAsPublished'],
        'drops_duplicates' => ['testADuplicateIsAbsorbedByTheInbox', 'testARelayThatDiesBetweenTheConfirmationAndTheMarkSendsAgainAndTheEffectRunsOnce'],
        'no_fan_out' => ['testEveryConsumerGetsEveryMessage'],
        'reverses_a_receive' => ['testMessagesOfAKeyKeepTheirOrderAcrossBatches', 'testMessagesOfAKeyKeepTheirOrderAcrossNames'],
        'requeues_to_the_back' => ['testARequeuedDeliveryComesBackBeforeTheOnesLeftBehind', 'testARequeuedDeliveryComesBackFirstOneDeliveryAtATime'],
        'count_stuck_at_one' => ['testAMessageThatKeepsFailingIsDeadLetteredAfterMaxDeliveries'],
        'loses_the_dead_letter_reason' => ['testAMessageThatKeepsFailingIsDeadLetteredAfterMaxDeliveries', 'testABodyThatIsNotAMessageIsDeadLetteredNeverDropped'],
        'drops_invalid_bodies' => ['testABodyThatIsNotAMessageIsDeadLetteredNeverDropped'],
        'loses_in_flight_on_death' => ['testTheDeliveriesOfADeadConsumerGoToItsSuccessor', 'testAProcessStartedAgainGetsWhatItLeftInFlight'],
        'lost_connection_stores' => ['testALostConnectionFailsTheWholeBatchAndMarksNothing'],
        'reason_carries_the_payload' => ['testNoReasonReportOrExceptionCarriesThePayload'],
        'silent_double_ack' => ['testNoReasonReportOrExceptionCarriesThePayload'],
        'shared_world' => ['testEachBenchIsAWorldOfItsOwn'],
    ];

    private const PAGE = 'messaging/writing-a-satellite.md';

    /** @var array<string, int> "<class>::<scenario>" => data sets run and passed */
    private array $passed = [];

    /** @var array<string, list<string>> fault => scenarios that failed under it (kept for the record) */
    public array $caught = [];

    public function run(): void
    {
        $this->assertTrue(class_exists(\PHPUnit\Framework\TestCase::class), 'the battery needs PHPUnit (require-dev of the core; a suggest for its consumers)');
        self::configurePhpUnit();
        $this->testEveryScenarioIsDeclared();
        foreach (self::BENCHES as $class) {
            $this->testTheBatteryPasses($class);
        }
        $this->testEveryDeclaredScenarioRanOnEveryBench();
        $this->testEveryFaultIsCaught();
        $this->testThePropertyMinimumCannotBeLowered();
        $this->testTheBatteryNeverSkipsAndKeepsItsConstants();
        $this->testTheInMemoryBenchOnItsOwn();
        $this->testCoverageGuard();
        $this->testNothingElseOfTheLibraryLoadsTheBattery();
        $this->testExamplesAreTheSameInBothLanguages();
        $this->testExamplesAreTheCodeTheBatteryRuns();
    }

    // =====================================================================
    // D1/D5: the battery on the reference and on the didactic satellite
    // =====================================================================

    private function testEveryScenarioIsDeclared(): void
    {
        $scenarios = self::scenariosOf(MessagingContractTestCase::class);
        $declared = array_keys(self::SCENARIOS);
        sort($declared);
        $this->assertEquals($declared, $scenarios, 'every scenario of the battery is declared in Module 49 (a new one must be run by a module)');
        $this->assertEquals(22, count($scenarios), 'the 22 scenarios of 2.15.0');
    }

    /**
     * @param class-string<MessagingContractTestCase> $class
     */
    private function testTheBatteryPasses(string $class): void
    {
        foreach (self::scenariosOf($class) as $scenario) {
            foreach (self::dataSets($class, $scenario) as $label => $arguments) {
                $error = $this->runScenario($class, $scenario, $arguments);
                $this->assertTrue($error === null, sprintf('%s::%s %s: %s', (new ReflectionClass($class))->getShortName(), $scenario, $label, $error === null ? '' : self::describe($error)));
                $this->passed["{$class}::{$scenario}"] = ($this->passed["{$class}::{$scenario}"] ?? 0) + 1;
            }
        }
    }

    private function testEveryDeclaredScenarioRanOnEveryBench(): void
    {
        foreach (self::BENCHES as $class) {
            foreach (array_keys(self::SCENARIOS) as $scenario) {
                $expected = $scenario === 'testRandomInterleavingsKeepTheGuarantees' ? count(MessagingContractTestCase::PROPERTY_SEEDS) : 1;
                $this->assertEquals($expected, $this->passed["{$class}::{$scenario}"] ?? 0, "{$scenario} ran and passed on {$class}");
            }
        }
        $this->assertEquals(6, count(MessagingContractTestCase::PROPERTY_SEEDS), 'six fixed seeds');
    }

    // =====================================================================
    // D3/D4: the battery rejects a broken broker
    // =====================================================================

    private function testEveryFaultIsCaught(): void
    {
        $faults = array_map(static fn(Module49Fault $f): string => $f->value, Module49Fault::cases());
        sort($faults);
        $declared = array_keys(self::FAULTS);
        sort($declared);
        $this->assertEquals($declared, $faults, 'every planted fault has its scenarios declared');
        foreach (Module49Fault::cases() as $fault) {
            Module49FaultyContract::$fault = $fault;
            $failed = [];
            foreach (self::scenariosOf(Module49FaultyContract::class) as $scenario) {
                foreach (self::dataSets(Module49FaultyContract::class, $scenario) as $arguments) {
                    $error = $this->runScenario(Module49FaultyContract::class, $scenario, $arguments);
                    if ($error !== null) {
                        $failed[$scenario] = ($failed[$scenario] ?? false) || $error instanceof AssertionFailedError;
                    }
                }
            }
            foreach (self::FAULTS[$fault->value] as $scenario) {
                $this->assertTrue(($failed[$scenario] ?? false) === true, sprintf('fault %s: %s fails on an assertion (failed: %s)', $fault->value, $scenario, implode(', ', array_keys($failed))));
            }
            $this->caught[$fault->value] = array_keys($failed);
        }
        Module49FaultyContract::$fault = Module49Fault::CONFIRMS_WITHOUT_STORING;
    }

    private function testThePropertyMinimumCannotBeLowered(): void
    {
        foreach (self::dataSets(Module49LowStepsContract::class, 'testRandomInterleavingsKeepTheGuarantees') as $arguments) {
            $error = $this->runScenario(Module49LowStepsContract::class, 'testRandomInterleavingsKeepTheGuarantees', $arguments);
            $this->assertTrue($error instanceof AssertionFailedError && str_contains($error->getMessage(), 'never set them below 150'), 'a satellite below MIN_PROPERTY_STEPS fails: ' . ($error === null ? 'it passed' : self::describe($error)));
        }
        $this->assertEquals(150, MessagingContractTestCase::MIN_PROPERTY_STEPS, 'the minimum of the two private batteries');
    }

    private function testTheBatteryNeverSkipsAndKeepsItsConstants(): void
    {
        $code = self::code(dirname(__DIR__, 2) . '/src/Messaging/Testing/MessagingContractTestCase.php');
        foreach (['markTestSkipped', 'markTestIncomplete', 'Requires', 'expectException', 'createMock', 'getMockBuilder'] as $forbidden) {
            $this->assertFalse(str_contains($code, $forbidden), "D3: the battery never calls {$forbidden} (no skip, no capability flag, no runner-only feature)");
        }
        $battery = new ReflectionClass(MessagingContractTestCase::class);
        $this->assertTrue($battery->isAbstract() && $battery->getMethod('createBench')->isAbstract(), 'abstract: a satellite answers createBench()');
        foreach (['PROPERTY_SEEDS', 'MIN_PROPERTY_STEPS', 'MAX_DELIVERIES'] as $constant) {
            $this->assertTrue($battery->getReflectionConstant($constant)?->isFinal() === true, "{$constant} is final: a satellite cannot change it");
        }
        foreach ($battery->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test') && $method->getDeclaringClass()->getName() === MessagingContractTestCase::class) {
                $this->assertTrue($method->isFinal(), "D3: {$method->getName()} is final: a satellite cannot override a scenario to weaken it");
            }
        }
        $bench = new ReflectionClass(IMessagingBench::class);
        $this->assertEquals(
            ['close', 'deadLetters', 'receiveWaitMs', 'restarted', 'source', 'storeRaw', 'successor', 'transport', 'transportWithLostConnection'],
            self::sorted(array_map(static fn(ReflectionMethod $m): string => $m->getName(), $bench->getMethods())),
            'the bench: the union of the two private benches (D2)'
        );
        $this->assertTrue(new InMemoryMessagingBench() instanceof IMessagingBench && new SqliteMessagingBench() instanceof IMessagingBench, 'both benches implement it');
    }

    /** What the battery does not reach of InMemoryMessagingBench: a source of another bench, the exact shapes. */
    private function testTheInMemoryBenchOnItsOwn(): void
    {
        $bench = new InMemoryMessagingBench();
        $other = new InMemoryMessagingBench();
        $foreign = $other->source('billing', ['crm.lead_created.v1']);
        foreach (['successor', 'restarted', 'deadLetters'] as $method) {
            $e = $this->assertThrows(LogicException::class, static fn() => $bench->{$method}($foreign), "{$method}() refuses a source of another bench");
            $this->assertTrue(str_contains($e->getMessage(), 'was not created by this bench'), $e->getMessage());
        }
        $lost = $this->assertThrows(MessagingException::class, static fn() => $bench->transportWithLostConnection()->send([new Message('crm.lead_created.v1', ['secret' => 'S-49'])]), 'a lost connection throws MessagingException');
        $this->assertEquals('The connection to the in-memory broker was lost: none of the 1 message(s) of the batch was stored.', $lost->getMessage());
        $this->assertEquals(0, $bench->receiveWaitMs(), 'the in-memory broker needs no wait');
        $source = $bench->source('billing', ['crm.lead_created.v1']);
        $bench->storeRaw('crm.lead_created.v1', 'not json');
        $delivery = $source->receive(1, 0)[0];
        $source->reject($delivery, false, 'refused');
        $this->assertEquals([['messageId' => null, 'reason' => 'refused', 'deliveryCount' => 1]], $bench->deadLetters($source), 'exactly the three keys of the bench contract, nothing of the source added');
        $bench->close();
        $this->assertThrows(LogicException::class, static fn() => $bench->deadLetters($source), 'close() forgets the sources of the bench');
    }

    // =====================================================================
    // The coverage guard and D1
    // =====================================================================

    private function testCoverageGuard(): void
    {
        $root = dirname(__DIR__, 2);
        $folder = $root . '/src/Messaging/Testing';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($folder) + 1, -4);
            }
        }
        sort($files);
        $this->assertEquals(self::TESTING_FILES, $files, 'every file of src/Messaging/Testing/ is declared here');
        $source = (string) file_get_contents(__FILE__);
        foreach ($files as $file) {
            $class = 'Antevemus\\ASpecification\\Messaging\\Testing\\' . $file;
            $this->assertTrue(str_contains($source, "\nuse {$class};\n"), "{$class} is imported by this module");
            $this->assertTrue(class_exists($class, false) || interface_exists($class, false), "{$class} was loaded by the battery");
            $code = (string) file_get_contents("{$folder}/{$file}.php");
            $this->assertTrue(str_contains($code, 'declare(strict_types=1);') && str_contains($code, '@version    2.15.0') && str_contains($code, '@subpackage Messaging\\Testing'), "{$file}: strict types, @version 2.15.0, @subpackage Messaging\\Testing");
        }
        $infection = (string) file_get_contents($root . '/infection.json5');
        $this->assertTrue(str_contains($infection, '|Messaging/'), 'src/Messaging/ (the bench of Testing/ included) is in the mutation scope');
        $this->assertTrue(str_contains($infection, '"~^Messaging/Testing/MessagingContractTestCase\\\\.php$~"'), 'the battery itself, a test, is out of the mutation scope, with its reason recorded in infection.json5 (it is tested by the planted faults above)');
    }

    private function testNothingElseOfTheLibraryLoadsTheBattery(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $checked = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($src) + 1);
            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Messaging/Testing/')) {
                continue;
            }
            $code = self::code($file->getPathname());
            $this->assertFalse(str_contains($code, 'Messaging\\Testing') || str_contains($code, 'PHPUnit'), "{$relative} references neither Messaging\\Testing nor PHPUnit (D1: only a test loads the battery)");
            $checked++;
        }
        $this->assertTrue($checked > 400, "every other file of src/ was read ({$checked})");
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $this->assertFalse(isset($composer['require']['phpunit/phpunit']), 'PHPUnit is not a require of the library');
        $this->assertTrue(str_contains((string) ($composer['suggest']['phpunit/phpunit'] ?? ''), 'messaging contract battery'), 'PHPUnit is a suggest, for the battery');
    }

    // =====================================================================
    // D6: the examples of docs/<language>/messaging/writing-a-satellite.md
    // =====================================================================

    private function testExamplesAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['27', '27.1', '27.2', '27.3', '27.4'] as $number) {
            $english = ReadmeBlocks::of($root . '/docs/en/' . self::PAGE, $number);
            $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/' . self::PAGE, $number);
            $this->assertCount(1, $english, "um bloco PHP no exemplo {$number} (EN)");
            $this->assertCount(1, $portuguese, "um bloco PHP no exemplo {$number} (pt-BR)");
            $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), "exemplo {$number}: bloco igual fora os comentários");
        }
    }

    private function testExamplesAreTheCodeTheBatteryRuns(): void
    {
        $tests = dirname(__DIR__);
        $this->assertTranscribed($tests . '/PhpUnit/InMemoryContractTest.php', '27', 3);
        $this->assertTranscribed($tests . '/Support/SqliteSatellite/SqliteMessageTransport.php', '27.1', 5);
        $this->assertTranscribed($tests . '/Support/SqliteSatellite/SqliteMessageSource.php', '27.2', 6);
        $this->assertTranscribed($tests . '/Support/SqliteSatellite/SqliteMessagingBench.php', '27.3', 5);
        $this->assertTranscribed($tests . '/PhpUnit/SqliteContractTest.php', '27.4', 2);
        $this->assertTrue(class_exists(SqliteMessageTransport::class) && class_exists(SqliteMessageSource::class), 'the transport and the source of the didactic satellite load');
    }

    /**
     * The code between the markers of $file is the block of example $number, token by token (the `use` lines of the
     * block are imports of the file, which has its namespace besides).
     */
    private function assertTranscribed(string $file, string $number, int $imports): void
    {
        $source = (string) file_get_contents($file);
        $start = strpos($source, "// DOC {$number} >>>\n");
        $end = strpos($source, "// <<< DOC {$number}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcription {$number} marked in " . basename($file));
        $transcribed = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $block = ReadmeBlocks::of(dirname(__DIR__, 2) . '/docs/en/' . self::PAGE, $number)[0] ?? '';
        preg_match_all('/^use ([^;]+);$/m', $block, $uses);
        $this->assertCount($imports, $uses[1], "example {$number}: imports");
        foreach ($uses[1] as $import) {
            $this->assertTrue(str_contains($source, "\nuse {$import};\n"), "example {$number}: {$import} is imported by " . basename($file));
        }
        $code = ReadmeBlocks::codeTokens((string) preg_replace('/^use [^;]+;$/m', '', $block));
        $this->assertTrue(count($code) > 20 && $transcribed === $code, sprintf('example %s: the transcription is the code of the page (%d tokens transcribed, %d on the page)', $number, count($transcribed), count($code)));
    }

    // =====================================================================
    // Running a scenario of the battery without the PHPUnit runner
    // =====================================================================

    /**
     * Runs setUp(), the scenario and tearDown() on a new instance; the error, or null when it passed. PHP warnings
     * become errors of the scenario, and the assertions it made are counted here and taken out of PHPUnit's count.
     *
     * @param class-string<MessagingContractTestCase> $class
     * @param list<mixed> $arguments
     */
    private function runScenario(string $class, string $scenario, array $arguments): ?Throwable
    {
        $test = new $class($scenario);
        $hook = Closure::bind(function (string $method): void {
            $this->{$method}();
        }, $test, $class);
        $before = Assert::getCount();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $hook('setUp');
            try {
                $test->{$scenario}(...$arguments);
            } finally {
                $hook('tearDown');
            }

            return null;
        } catch (Throwable $e) {
            return $e;
        } finally {
            restore_error_handler();
            $this->addAssertions(Assert::getCount() - $before);
            Assert::resetCount();
        }
    }

    /**
     * Outside the PHPUnit runner (tests/run_all.php) the configuration of PHPUnit is not built, and a failing
     * assertion cannot describe its values; the default configuration is enough. Under the runner it is left alone.
     */
    private static function configurePhpUnit(): void
    {
        try {
            Registry::get();
        } catch (TypeError) {
            Registry::init((new CliArgumentsBuilder())->fromParameters([]), DefaultConfiguration::create());
        }
    }

    /**
     * The scenarios of a battery: its public methods named test*, sorted.
     *
     * @return list<string>
     */
    private static function scenariosOf(string $class): array
    {
        $names = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test') && !$method->isStatic()) {
                $names[] = $method->getName();
            }
        }
        sort($names);

        return $names;
    }

    /**
     * The data sets of a scenario: those of its DataProvider, or one empty set.
     *
     * @return array<string, list<mixed>>
     */
    private static function dataSets(string $class, string $scenario): array
    {
        $attributes = (new ReflectionMethod($class, $scenario))->getAttributes(DataProvider::class);
        if ($attributes === []) {
            return ['' => []];
        }
        $provider = (string) $attributes[0]->getArguments()[0];
        $sets = [];
        foreach ($class::$provider() as $label => $arguments) {
            $sets[(string) $label] = array_values($arguments);
        }

        return $sets;
    }

    /** The code of a file without its comments. */
    private static function code(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private static function describe(Throwable $e): string
    {
        return sprintf('%s: %s at %s:%d', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine());
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
