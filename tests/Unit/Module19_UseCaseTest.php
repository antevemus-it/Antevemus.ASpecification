<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\IOutcome;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\GuardExpression;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\StateMachine;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Antevemus\ASpecification\StateMachine\TransitionStatus;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\UseCase\CallableMiddleware;
use Antevemus\ASpecification\UseCase\IUseCase;
use Antevemus\ASpecification\UseCase\IUseCaseErrorReporter;
use Antevemus\ASpecification\UseCase\MachineEventRequest;
use Antevemus\ASpecification\UseCase\NullUseCaseErrorReporter;
use Antevemus\ASpecification\UseCase\StateMachineEventUseCase;
use Antevemus\ASpecification\UseCase\TransactionMiddleware;
use Antevemus\ASpecification\UseCase\TransactionRollbackFailedException;
use Antevemus\ASpecification\UseCase\UseCaseResult;
use Antevemus\ASpecification\UseCase\UseCaseRunner;
use Antevemus\ASpecification\UseCase\UseCaseStatus;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use Throwable;

// =============================================================================
// Fixtures of the use case battery
// =============================================================================

/** Secret planted in exception messages: it must never reach a result (RN-02). */
const USE_CASE19_SECRET = 'db-password=hunter2-S3CR3T';

/** A request with one blocking rule (name) and one advisory rule (age). */
class UseCase19RegisterRequest implements UseCase19MarkedRequest
{
    public function __construct(
        #[ValidateRule('not_blank', code: 'NAME_REQUIRED', message: 'The name is required.')]
        public string $name,
        #[ValidateRule('>=', value: 18, code: 'AGE_UNDER_18', message: 'Under 18: a guardian must sign too.', severity: 'WARNING')]
        public int $age,
        #[ValidateRule('not_blank', code: 'NICKNAME_MISSING', message: 'No nickname given.', severity: 'INFO')]
        public string $nickname = '',
        public float $amount = 10.0
    ) {
    }
}

interface UseCase19MarkedRequest
{
}

/** A request whose annotated getter throws with a secret in the message. */
class UseCase19ExplodingGetterRequest
{
    #[ValidateRule('not_null', code: 'TOKEN_REQUIRED')]
    public function getToken(): string
    {
        throw new RuntimeException('token vault unreachable: ' . USE_CASE19_SECRET);
    }
}

/** A request whose annotated getter records when the validation reads it. */
class UseCase19ProbeRequest
{
    /** @var list<string> */
    public static array $log = [];

    #[ValidateRule('not_null', code: 'PROBE')]
    public function getProbe(): string
    {
        self::$log[] = 'validate';

        return 'ok';
    }
}

/** A guard that throws with a secret in the message. */
class UseCase19ExplodingSpec extends AbstractSpecification
{
    public function getType(): string
    {
        return 'mixed';
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        throw new RuntimeException('guard backend down: ' . USE_CASE19_SECRET);
    }
}

/** A payload with a public toArray(). */
class UseCase19Payload
{
    public function __construct(public readonly int $id)
    {
    }

    /** @return array{id: int} */
    public function toArray(): array
    {
        return ['id' => $this->id];
    }
}

/** A reporter that records what it receives. */
class UseCase19RecordingReporter implements IUseCaseErrorReporter
{
    /** @var list<array{Throwable, IUseCase, object}> */
    public array $reports = [];

    public function report(Throwable $error, IUseCase $useCase, object $request): void
    {
        $this->reports[] = [$error, $useCase, $request];
    }
}

/** A reporter that is broken. */
class UseCase19BrokenReporter implements IUseCaseErrorReporter
{
    public function report(Throwable $error, IUseCase $useCase, object $request): void
    {
        throw new RuntimeException('logger down');
    }
}

/** A use case built from a closure, counting its calls. */
class UseCase19Callback implements IUseCase
{
    public int $calls = 0;

    /** @param Closure(object): UseCaseResult $body */
    public function __construct(private readonly Closure $body)
    {
    }

    public function execute(object $request): UseCaseResult
    {
        $this->calls++;

        return ($this->body)($request);
    }
}

/** README 15.1 (1.8.0): o request do exemplo, como escrito (renomeado para não colidir). */
final class Readme15RegisterTenantRequest
{
    public function __construct(
        #[ValidateRule('not_blank', code: 'NAME_REQUIRED', message: 'The tenant name is required.')]
        public readonly string $name,
        #[ValidateRule('<=', value: 5000, code: 'RENT_REVIEW', message: 'Rent above 5 000 goes to manual review.', severity: 'WARNING')]
        public readonly int $rent,
    ) {}
}

/**
 * README 15.1 (1.8.0): o caso de uso do exemplo, como escrito (renomeado para não colidir).
 *
 * @implements IUseCase<Readme15RegisterTenantRequest, int>
 */
final class Readme15RegisterTenant implements IUseCase
{
    public function __construct(private PDO $pdo) {}

    public function execute(object $request): UseCaseResult
    {
        $this->pdo->prepare('INSERT INTO tenant (name, rent) VALUES (?, ?)')->execute([$request->name, $request->rent]);

        return UseCaseResult::created((int) $this->pdo->lastInsertId());
    }
}

/**
 * Module19_UseCaseTest - Caso de Uso com Resultado Comum à Máquina de Estados (1.8.0)
 *
 * Forward 024, fases 2 a 4: `IOutcome` (comum a `TransitionResult` e `UseCaseResult`),
 * `UseCaseStatus` (dez status de domínio, mapa HTTP fechado), `UseCaseResult` (fábricas, coerência,
 * sem Throwable), `UseCaseRunner` (ordem da D6, validação por atributos e especificações por classe
 * do request, avisos anexados, exceção sem vazamento), middlewares (`CallableMiddleware`,
 * `TransactionMiddleware`) e a ponte com a máquina (`fromTransition()`, `fromConcurrencyConflict()`,
 * `StateMachineEventUseCase` sobre a máquina real da Virttus com persistência InMemory).
 *
 * Cobre RN-02 (segredo na mensagem da exceção não chega ao resultado, nem classe, arquivo, linha
 * ou trace), RN-03 (só ERROR bloqueia), RN-04 (coerência status × falhas), RN-05 (transação) e a
 * aceitação da §4 (espinha → SUCCESS; pendência aberta → REJECTED/QUOTE_PENDING_OPEN; evento fora
 * do estado → CONFLICT; versão velha → CONFLICT/SM_CONCURRENCY_CONFLICT).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module19_UseCaseTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/state_machine/virttus_quote.v1.json';

    private const VIRTTUS_KEY = 'virttus.rental_guarantee_quote';

    public function run(): void
    {
        $this->testStatusAndHttpMap();
        $this->testFactories();
        $this->testCoherence();
        $this->testSeverityNormalizationAndSanitization();
        $this->testImmutabilityAndSerialization();
        $this->testValidationSeverity();
        $this->testRequestSpecificationsPerClass();
        $this->testNoLeakOfUnhandledExceptions();
        $this->testNoLeakOfValidationEvaluationErrors();
        $this->testMiddlewareOrder();
        $this->testTransactionMiddleware();
        $this->testFromTransition();
        $this->testBridgeOnVirttus();
        $this->testBridgeEdgeCases();
        $this->testFacade();
        if (extension_loaded('pdo_sqlite')) {
            $this->testReadme15_1UseCaseRunnerWithTransaction();
        }
        $this->testReadme15_2MachineEventAsUseCase();
    }

    // ==========================================
    // Helpers
    // ==========================================

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private function useCase(Closure $body): UseCase19Callback
    {
        return new UseCase19Callback($body);
    }

    private function warning(string $code, FailureSeverity $severity = FailureSeverity::WARNING): SpecificationFailure
    {
        return new SpecificationFailure('Heads up: ' . $code, $code, 'Test', null, [], $severity);
    }

    private function virttusGuards(): GuardRegistry
    {
        return Spec::guardRegistry()->register('hasRole', static function (array $params): ISpecification {
            $roles = $params['roles'] ?? [];
            if ($roles === []) {
                return Spec::alwaysTrue();
            }

            return Spec::property('context.role', Spec::in($roles))
                ->because('The user role is not allowed to take this action.')
                ->withCode('ROLE_NOT_ALLOWED');
        });
    }

    /**
     * @param list<MachineDefinition> $definitions
     */
    private function machineRunner(array $definitions, ?GuardRegistry $guards = null): MachineRunner
    {
        return new MachineRunner(new StateMachine($guards), new InMemoryMachineDefinitionRepository($definitions), MachinePersistence::inMemory());
    }

    /** draft --SUBMIT [payloadHas file]--> sent (final). */
    private function documentMachine(): MachineDefinition
    {
        return Spec::machine('document', 1)
            ->region('r', 'draft')
                ->state('draft')->on('SUBMIT', 'sent', 'submit')->guard('payloadHas', ['field' => 'file'])
                ->finalState('sent')
            ->build();
    }

    /** Every textual rendering of a value: json, serialize, print_r, var_export. */
    private function renderings(mixed $value): string
    {
        return implode("\n", [
            (string) json_encode($value),
            serialize($value),
            print_r($value, true),
            var_export($value, true),
        ]);
    }

    private function assertNoLeak(string $haystack, string $label, ?Throwable $exception = null): void
    {
        $this->assertFalse(str_contains($haystack, USE_CASE19_SECRET), "{$label}: the secret does not leak");
        $this->assertFalse(str_contains($haystack, 'RuntimeException'), "{$label}: the exception class does not leak");
        $this->assertFalse(str_contains($haystack, 'Stack trace') || str_contains($haystack, '#0 '), "{$label}: no stack trace");
        $this->assertFalse(str_contains($haystack, basename(__FILE__)), "{$label}: no file of the exception");
        if ($exception !== null) {
            $this->assertFalse(str_contains($haystack, $exception->getFile()), "{$label}: no file path");
            $this->assertFalse(str_contains($haystack, $exception->getTraceAsString()), "{$label}: no trace");
        }
    }

    // ==========================================
    // 1. Status and HTTP edge (D2, D5)
    // ==========================================

    private function testStatusAndHttpMap(): void
    {
        $this->assertCount(10, UseCaseStatus::cases(), 'Ten domain statuses (D2)');
        $map = [];
        $success = [];
        foreach (UseCaseStatus::cases() as $status) {
            $map[$status->name] = $status->toHttpStatus();
            if ($status->isSuccess()) {
                $success[] = $status->name;
            }
        }
        $this->assertEquals([
            'SUCCESS' => 200,
            'CREATED' => 201,
            'ACCEPTED' => 202,
            'NO_CHANGE' => 204,
            'INVALID' => 400,
            'REJECTED' => 422,
            'NOT_FOUND' => 404,
            'CONFLICT' => 409,
            'FORBIDDEN' => 403,
            'FAILED' => 500,
        ], $map, 'Closed HTTP map, every status covered (D5)');
        $this->assertCount(10, array_unique($map), 'No two statuses share an HTTP code');
        $this->assertEquals(['SUCCESS', 'CREATED', 'ACCEPTED', 'NO_CHANGE'], $success, 'isSuccess() for the first four only');
        $this->assertEquals(
            ['success', 'created', 'accepted', 'no_change', 'invalid', 'rejected', 'not_found', 'conflict', 'forbidden', 'failed'],
            array_map(static fn(UseCaseStatus $s): string => $s->value, UseCaseStatus::cases())
        );
        $this->assertFalse(method_exists(UseCaseStatus::class, 'fromHttpStatus'), 'No way back from an HTTP integer (D5)');
    }

    // ==========================================
    // 2. Factories (D3)
    // ==========================================

    private function testFactories(): void
    {
        $ok = UseCaseResult::success(['id' => 7]);
        $this->assertTrue($ok->isSuccess());
        $this->assertEquals(UseCaseStatus::SUCCESS, $ok->getStatus());
        $this->assertEquals(['id' => 7], $ok->getPayload());
        $this->assertTrue($ok->getReason()->isSatisfied, 'A success without failures has a satisfied reason');
        $this->assertCount(0, $ok->getReason());
        $this->assertTrue($ok->getCorrelationId() === null);

        foreach ([
            [UseCaseResult::created('x'), UseCaseStatus::CREATED],
            [UseCaseResult::accepted('ticket'), UseCaseStatus::ACCEPTED],
            [UseCaseResult::noChange(), UseCaseStatus::NO_CHANGE],
        ] as [$result, $status]) {
            $this->assertEquals($status, $result->getStatus());
            $this->assertTrue($result->isSuccess(), "{$status->value} is a success");
        }

        $withWarning = UseCaseResult::success('p', [$this->warning('W1')]);
        $this->assertTrue($withWarning->isSuccess(), 'A warning does not change success (derived from the status only)');
        $this->assertFalse($withWarning->getReason()->isSatisfied, 'The reason is satisfied only without failures');
        $this->assertEquals(['W1'], $withWarning->getCodes());
        $this->assertCount(1, $withWarning->getWarnings());
        $this->assertCount(0, $withWarning->getErrors());

        $fromResult = UseCaseResult::accepted(null, SpecificationResult::failure('Logged', 'L1', severity: FailureSeverity::INFO));
        $this->assertEquals(['L1'], $fromResult->getCodes());

        $reason = SpecificationResult::failure('Bad', 'B1');
        $invalid = UseCaseResult::invalid($reason);
        $this->assertEquals(UseCaseStatus::INVALID, $invalid->getStatus());
        $this->assertFalse($invalid->isSuccess());
        $this->assertTrue($invalid->getPayload() === null);
        $this->assertEquals(FailureSeverity::ERROR, $invalid->getReason()->failures[0]->severity, 'An unclassified failure becomes ERROR');
        $this->assertEquals(UseCaseStatus::REJECTED, UseCaseResult::rejected($reason)->getStatus());

        foreach ([
            [UseCaseResult::notFound('Q_404', 'No such quote.', ['id' => 9]), UseCaseStatus::NOT_FOUND],
            [UseCaseResult::conflict('Q_409', 'Quote already approved.'), UseCaseStatus::CONFLICT],
            [UseCaseResult::forbidden('Q_403', 'Not your quote.'), UseCaseStatus::FORBIDDEN],
            [UseCaseResult::failed('Q_500', 'Try again later.'), UseCaseStatus::FAILED],
        ] as [$result, $status]) {
            $this->assertEquals($status, $result->getStatus());
            $this->assertFalse($result->isSuccess());
            $this->assertCount(1, $result->getReason());
            $failure = $result->getReason()->failures[0];
            $this->assertEquals(FailureSeverity::ERROR, $failure->severity);
            $this->assertEquals(UseCaseResult::RULE_NAME, $failure->ruleName);
        }
        $this->assertEquals(['id' => 9], UseCaseResult::notFound('Q_404', 'No such quote.', ['id' => 9])->getReason()->failures[0]->metadata);
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::conflict('', 'msg'), 'Blank code refused');
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::failed('CODE', '  '), 'Blank message refused');

        // failed() takes no Throwable (D3): two strings and an array.
        $types = array_map(
            static fn(\ReflectionParameter $p): string => (string) $p->getType(),
            (new \ReflectionMethod(UseCaseResult::class, 'failed'))->getParameters()
        );
        $this->assertEquals(['string', 'string', 'array'], $types, 'failed(code, message, metadata): no Throwable');

        // No property of the result can hold a Throwable.
        foreach ((new ReflectionClass(UseCaseResult::class))->getProperties() as $property) {
            $type = $property->getType();
            $names = $type instanceof ReflectionUnionType
                ? array_map(static fn(ReflectionNamedType $t): string => $t->getName(), $type->getTypes())
                : [$type instanceof ReflectionNamedType ? $type->getName() : 'mixed'];
            $this->assertFalse(in_array(Throwable::class, $names, true), "Property {$property->getName()} is not a Throwable");
        }
        $this->assertTrue((new ReflectionClass(UseCaseResult::class))->isFinal(), 'UseCaseResult is final');
        $this->assertFalse((new ReflectionClass(UseCaseResult::class))->getConstructor()->isPublic(), 'Built only by its factories');
        $this->assertInstanceOf(IOutcome::class, $ok);
    }

    // ==========================================
    // 3. Coherence (RN-04)
    // ==========================================

    private function testCoherence(): void
    {
        $error = SpecificationResult::failure('Blocking', 'E1', severity: FailureSeverity::ERROR);
        $unclassified = SpecificationResult::failure('Unclassified', 'U1');

        foreach ([UseCaseStatus::SUCCESS, UseCaseStatus::CREATED, UseCaseStatus::ACCEPTED, UseCaseStatus::NO_CHANGE] as $status) {
            $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::of($status, null, $error), "{$status->value} with an ERROR failure is refused");
            $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::of($status, null, $unclassified), "{$status->value} with an unclassified failure (ERROR) is refused");
            $this->assertTrue(UseCaseResult::of($status, 'p')->isSuccess());
        }
        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => UseCaseResult::of(UseCaseStatus::SUCCESS, null, SpecificationResult::error(new RuntimeException('x'), 'R')),
            'A success with an evaluation error is refused'
        );
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::success(null, [$this->warning('E', FailureSeverity::ERROR)]), 'An ERROR is not a warning');
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::success(null, ['not a failure']));
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::success(null, SpecificationResult::error(new RuntimeException('x'), 'R')));

        foreach ([UseCaseStatus::INVALID, UseCaseStatus::REJECTED, UseCaseStatus::NOT_FOUND, UseCaseStatus::CONFLICT, UseCaseStatus::FORBIDDEN, UseCaseStatus::FAILED] as $status) {
            $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::of($status), "{$status->value} without a failure is refused");
            $this->assertFalse(UseCaseResult::of($status, null, $error)->isSuccess());
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::rejected(SpecificationResult::satisfied()), 'REJECTED without failure (RN-04)');
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::invalid(SpecificationResult::satisfied()), 'INVALID without failure');
        // A failure status accepts a non-blocking failure (an unhandled machine event carries INFO).
        $info = SpecificationResult::failure('Not handled', 'SM_UNHANDLED_EVENT', severity: FailureSeverity::INFO);
        $this->assertEquals(UseCaseStatus::CONFLICT, UseCaseResult::of(UseCaseStatus::CONFLICT, null, $info)->getStatus());

        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::success()->withCorrelationId('  '), 'Blank correlation id refused');
        $this->assertThrows(InvalidArgumentException::class, fn() => UseCaseResult::success()->withWarnings([$this->warning('E', FailureSeverity::ERROR)]), 'withWarnings refuses an ERROR');
    }

    // ==========================================
    // 4. Severity normalization and sanitization
    // ==========================================

    private function testSeverityNormalizationAndSanitization(): void
    {
        $cases = [
            ['own', new SpecificationFailure('m', 'c', null, null, [], FailureSeverity::INFO), FailureSeverity::INFO],
            ['metadata WARNING', new SpecificationFailure('m', 'c', null, null, ['severity' => 'WARNING']), FailureSeverity::WARNING],
            ['metadata info', new SpecificationFailure('m', 'c', null, null, ['severity' => ' info ']), FailureSeverity::INFO],
            ['metadata enum', new SpecificationFailure('m', 'c', null, null, ['severity' => FailureSeverity::WARNING]), FailureSeverity::WARNING],
            ['metadata unknown', new SpecificationFailure('m', 'c', null, null, ['severity' => 'loud']), FailureSeverity::ERROR],
            ['none', new SpecificationFailure('m', 'c'), FailureSeverity::ERROR],
            ['evaluation error wins', new SpecificationFailure('m', 'c', null, null, ['evaluation_error' => 'X', 'severity' => 'INFO'], FailureSeverity::INFO), FailureSeverity::ERROR],
            ['evaluation error null marker', new SpecificationFailure('m', 'c', null, null, ['evaluation_error' => null]), FailureSeverity::ERROR],
        ];
        foreach ($cases as [$label, $failure, $expected]) {
            $this->assertEquals($expected, UseCaseResult::severityOf($failure), "severityOf: {$label}");
        }

        // Every failure carries an explicit severity after construction.
        $result = UseCaseResult::success(null, [new SpecificationFailure('m', 'W', null, null, ['severity' => 'WARNING'])]);
        $this->assertEquals(FailureSeverity::WARNING, $result->getReason()->failures[0]->severity);

        // Sanitization: no exception object, no Throwable in metadata, generic evaluation-error message.
        $exception = new RuntimeException('evaluation blew up: ' . USE_CASE19_SECRET);
        $reason = new SpecificationResult(false, [
            new SpecificationFailure($exception->getMessage(), 'EVAL', 'Rule', 'field', [
                'evaluation_error' => RuntimeException::class,
                'cause' => $exception,
                'nested' => ['deep' => $exception, 'keep' => 1],
            ]),
        ], true, $exception);
        $rejected = UseCaseResult::rejected($reason);
        $failure = $rejected->getReason()->failures[0];
        $this->assertTrue($rejected->getReason()->exception === null, 'The exception of the reason is dropped');
        $this->assertTrue($rejected->getReason()->isError, 'The error flag is kept');
        $this->assertEquals(UseCaseResult::EVALUATION_ERROR_MESSAGE, $failure->message);
        $this->assertEquals(['evaluation_error' => true, 'nested' => ['keep' => 1]], $failure->metadata);
        $this->assertEquals(['EVAL', 'Rule', 'field'], [$failure->code, $failure->ruleName, $failure->property], 'Code, rule and property kept');
        $this->assertNoLeak($this->renderings($rejected), 'sanitized reason', $exception);
        $this->assertNoLeak($this->renderings($rejected->toArray()), 'sanitized reason (array)', $exception);
    }

    // ==========================================
    // 5. Immutability and serialization
    // ==========================================

    private function testImmutabilityAndSerialization(): void
    {
        $original = UseCaseResult::success(new UseCase19Payload(3));
        $tagged = $original->withCorrelationId('req-42');
        $this->assertTrue($original !== $tagged);
        $this->assertTrue($original->getCorrelationId() === null, 'withCorrelationId returns a copy');
        $this->assertEquals('req-42', $tagged->getCorrelationId());
        $this->assertTrue($tagged->withCorrelationId(null)->getCorrelationId() === null);

        $warned = $tagged->withWarnings([$this->warning('W1')]);
        $this->assertCount(0, $tagged->getReason(), 'withWarnings returns a copy');
        $this->assertEquals(['W1'], $warned->getCodes());
        $this->assertEquals('req-42', $warned->getCorrelationId(), 'Copies keep the correlation id');
        $this->assertTrue($warned->withWarnings([]) === $warned, 'Nothing to attach: same instance');

        $this->assertThrows(\Error::class, function () use ($original): void {
            /** @phpstan-ignore-next-line */
            $original->status = UseCaseStatus::FAILED;
        }, 'Readonly properties');

        $this->assertEquals([
            'status' => 'success',
            'success' => true,
            'payload' => ['id' => 3],
            'reason' => [
                'satisfied' => false,
                'error' => false,
                'failures' => [[
                    'message' => 'Heads up: W1',
                    'code' => 'W1',
                    'rule' => 'Test',
                    'property' => null,
                    'severity' => 'warning',
                    'metadata' => [],
                ]],
            ],
            'correlation_id' => 'req-42',
        ], $warned->toArray(), 'Deterministic array form');
        $this->assertEquals(json_encode($warned->toArray()), json_encode($warned), 'JsonSerializable = toArray()');
        $this->assertFalse(array_key_exists('http', $warned->toArray()), 'HTTP lives only in UseCaseStatus::toHttpStatus() (D5)');

        $this->assertEquals('no_change', UseCaseResult::noChange(UseCaseStatus::CREATED)->toArray()['status']);
        $this->assertEquals('created', UseCaseResult::noChange(UseCaseStatus::CREATED)->toArray()['payload'], 'Enum payload reduced to its value');
        $this->assertEquals('2026-10-10T10:00:00.000000+00:00', UseCaseResult::success($this->at('2026-10-10T10:00:00+00:00'))->toArray()['payload']);
    }

    // ==========================================
    // 6. Validation severity (RN-03)
    // ==========================================

    private function testValidationSeverity(): void
    {
        $runner = new UseCaseRunner();
        $useCase = $this->useCase(static fn(UseCase19RegisterRequest $r): UseCaseResult => UseCaseResult::created(['name' => $r->name]));

        // Only WARNING and INFO: the use case runs and the failures come back with the success.
        $result = $runner->run($useCase, new UseCase19RegisterRequest('Ana', 16));
        $this->assertEquals(1, $useCase->calls, 'WARNING/INFO do not block (RN-03)');
        $this->assertEquals(UseCaseStatus::CREATED, $result->getStatus());
        $this->assertEquals(['name' => 'Ana'], $result->getPayload());
        $this->assertEquals(['AGE_UNDER_18', 'NICKNAME_MISSING'], $result->getCodes());
        $this->assertEquals(
            [FailureSeverity::WARNING, FailureSeverity::INFO],
            array_map(static fn(SpecificationFailure $f): ?FailureSeverity => $f->severity, $result->getReason()->failures)
        );

        // Clean request: no failure at all.
        $clean = $runner->run($useCase, new UseCase19RegisterRequest('Ana', 30, 'ana'));
        $this->assertEquals(2, $useCase->calls);
        $this->assertTrue($clean->getReason()->isSatisfied);

        // One ERROR: INVALID, the use case is NOT called; the reason has every validation failure.
        $invalid = $runner->run($useCase, new UseCase19RegisterRequest('  ', 16));
        $this->assertEquals(2, $useCase->calls, 'An ERROR prevents the call (RN-03)');
        $this->assertEquals(UseCaseStatus::INVALID, $invalid->getStatus());
        $this->assertEquals(400, $invalid->getStatus()->toHttpStatus());
        $this->assertEquals(['NAME_REQUIRED', 'AGE_UNDER_18', 'NICKNAME_MISSING'], $invalid->getCodes());
        $this->assertCount(1, $invalid->getErrors());
        $this->assertEquals('name', $invalid->getErrors()[0]->property);

        // Warnings are attached whatever the status the use case returns.
        $refusing = $this->useCase(static fn(): UseCaseResult => UseCaseResult::rejected(SpecificationResult::failure('Over the limit', 'LIMIT', severity: FailureSeverity::ERROR)));
        $refused = $runner->run($refusing, new UseCase19RegisterRequest('Ana', 16));
        $this->assertEquals(UseCaseStatus::REJECTED, $refused->getStatus());
        $this->assertEquals(['LIMIT', 'AGE_UNDER_18', 'NICKNAME_MISSING'], $refused->getCodes());

        // validate() alone, with explicit severities.
        $validation = $runner->validate(new UseCase19RegisterRequest('', 40, 'x'));
        $this->assertEquals([FailureSeverity::ERROR], array_map(static fn(SpecificationFailure $f): ?FailureSeverity => $f->severity, $validation->failures));
        $this->assertTrue($runner->validate(new \stdClass())->isSatisfied, 'A request without attributes is valid');
    }

    // ==========================================
    // 7. Specifications registered per request class (D6)
    // ==========================================

    private function testRequestSpecificationsPerClass(): void
    {
        $useCase = $this->useCase(static fn(): UseCaseResult => UseCaseResult::success());
        $positive = Spec::property('amount', Spec::greaterThan(0))->because('The amount must be positive.')->withCode('AMOUNT_POSITIVE');

        $runner = (new UseCaseRunner())->registerRequestSpecification(UseCase19RegisterRequest::class, $positive);
        $result = $runner->run($useCase, new UseCase19RegisterRequest('Ana', 30, 'ana', -5.0));
        $this->assertEquals(UseCaseStatus::INVALID, $result->getStatus(), 'A registered specification blocks (unclassified = ERROR)');
        $this->assertEquals(['AMOUNT_POSITIVE'], $result->getCodes());
        $this->assertEquals(0, $useCase->calls);
        $this->assertTrue($runner->run($useCase, new UseCase19RegisterRequest('Ana', 30, 'ana', 5.0))->isSuccess());

        // Registered for an interface: applies to the implementing request.
        $byInterface = (new UseCaseRunner())->registerRequestSpecification(
            UseCase19MarkedRequest::class,
            Spec::property('amount', Spec::lessThan(1000))->withCode('AMOUNT_CAP')
        );
        $this->assertEquals(['AMOUNT_CAP'], $byInterface->run($useCase, new UseCase19RegisterRequest('Ana', 30, 'ana', 5000.0))->getCodes());

        // Registered for another class: not evaluated.
        $other = (new UseCaseRunner())->registerRequestSpecification(UseCase19ProbeRequest::class, Spec::alwaysFalse()->withCode('NEVER'));
        $this->assertTrue($other->run($useCase, new UseCase19RegisterRequest('Ana', 30, 'ana'))->isSuccess());

        // A WARNING-classified specification failure does not block.
        $advisory = (new UseCaseRunner())->registerRequestSpecification(UseCase19RegisterRequest::class, new class extends AbstractSpecification {
            public function getType(): string
            {
                return 'object';
            }

            public function isSatisfiedBy(mixed $candidate): bool
            {
                return false;
            }

            public function evaluate(mixed $candidate): SpecificationResult
            {
                return SpecificationResult::failure('Unusual amount.', 'AMOUNT_UNUSUAL', severity: FailureSeverity::WARNING);
            }
        });
        $warned = $advisory->run($useCase, new UseCase19RegisterRequest('Ana', 30, 'ana'));
        $this->assertTrue($warned->isSuccess());
        $this->assertEquals(['AMOUNT_UNUSUAL'], $warned->getCodes());

        // An unsatisfied verdict without a recorded failure still blocks.
        $silent = (new UseCaseRunner())->registerRequestSpecification(UseCase19RegisterRequest::class, new class extends AbstractSpecification {
            public function getType(): string
            {
                return 'object';
            }

            public function isSatisfiedBy(mixed $candidate): bool
            {
                return false;
            }

            public function evaluate(mixed $candidate): SpecificationResult
            {
                return new SpecificationResult(false);
            }
        });
        $calls = $useCase->calls;
        $this->assertEquals(UseCaseStatus::INVALID, $silent->run($useCase, new UseCase19RegisterRequest('Ana', 30, 'ana'))->getStatus());
        $this->assertEquals($calls, $useCase->calls);

        $this->assertThrows(InvalidArgumentException::class, fn() => (new UseCaseRunner())->registerRequestSpecification('App\\Nope', Spec::alwaysTrue()));
    }

    // ==========================================
    // 8. No leak of unhandled exceptions (RN-02, D6)
    // ==========================================

    private function testNoLeakOfUnhandledExceptions(): void
    {
        $reporter = new UseCase19RecordingReporter();
        $runner = new UseCaseRunner($reporter);
        $thrown = new RuntimeException('connection string ' . USE_CASE19_SECRET);
        $useCase = $this->useCase(static function () use ($thrown): UseCaseResult {
            throw $thrown;
        });
        $request = new UseCase19RegisterRequest('Ana', 30, 'ana');

        $result = $runner->run($useCase, $request);
        $this->assertEquals(UseCaseStatus::FAILED, $result->getStatus());
        $this->assertEquals(500, $result->getStatus()->toHttpStatus());
        $this->assertEquals([UseCaseResult::CODE_UNHANDLED_EXCEPTION], $result->getCodes());
        $this->assertEquals([UseCaseResult::UNHANDLED_EXCEPTION_MESSAGE], $result->getReason()->getReasons());
        $this->assertEquals([], $result->getReason()->failures[0]->metadata, 'No metadata about the exception');
        $this->assertTrue($result->getReason()->exception === null);
        $this->assertTrue($result->getPayload() === null);
        $this->assertNoLeak($this->renderings($result), 'unhandled exception', $thrown);
        $this->assertNoLeak($this->renderings($result->toArray()), 'unhandled exception (array)', $thrown);
        $this->assertNoLeak((string) $result->getReason(), 'unhandled exception (reason string)', $thrown);

        $this->assertCount(1, $reporter->reports, 'The exception goes to the reporter');
        $this->assertTrue($reporter->reports[0][0] === $thrown, 'The very same exception, untouched');
        $this->assertTrue($reporter->reports[0][1] === $useCase);
        $this->assertTrue($reporter->reports[0][2] === $request);

        // A middleware that throws, and a broken reporter: still FAILED, still no leak.
        $broken = new UseCaseRunner(new UseCase19BrokenReporter(), [
            static function (object $request, callable $next): UseCaseResult {
                throw new RuntimeException('middleware ' . USE_CASE19_SECRET);
            },
        ]);
        $failed = $broken->run($this->useCase(static fn(): UseCaseResult => UseCaseResult::success()), $request);
        $this->assertEquals(UseCaseStatus::FAILED, $failed->getStatus());
        $this->assertNoLeak($this->renderings($failed), 'middleware exception with broken reporter');

        // A configuration error of the validation (unknown operator) is unhandled too.
        $config = new UseCaseRunner($reporter);
        $configResult = $config->run($this->useCase(static fn(): UseCaseResult => UseCaseResult::success()), new class {
            #[ValidateRule('bogus-operator')]
            public string $field = 'x';
        });
        $this->assertEquals(UseCaseStatus::FAILED, $configResult->getStatus());
        $this->assertCount(2, $reporter->reports);

        // A use case that throws an Error (not an Exception) as well.
        $error = $runner->run($this->useCase(static fn(): UseCaseResult => throw new \TypeError('type ' . USE_CASE19_SECRET)), $request);
        $this->assertEquals([UseCaseResult::CODE_UNHANDLED_EXCEPTION], $error->getCodes());
        $this->assertNoLeak($this->renderings($error), 'Error');

        $this->assertInstanceOf(NullUseCaseErrorReporter::class, (new UseCaseRunner())->getErrorReporter(), 'Null reporter by default');
    }

    // ==========================================
    // 9. No leak of validation evaluation errors (RN-02)
    // ==========================================

    private function testNoLeakOfValidationEvaluationErrors(): void
    {
        $reporter = new UseCase19RecordingReporter();
        $runner = new UseCaseRunner($reporter);
        $useCase = $this->useCase(static fn(): UseCaseResult => UseCaseResult::success());

        $result = $runner->run($useCase, new UseCase19ExplodingGetterRequest());
        $this->assertEquals(UseCaseStatus::INVALID, $result->getStatus(), 'A getter that throws is an evaluation error: blocks');
        $this->assertEquals(0, $useCase->calls);
        $this->assertEquals(['TOKEN_REQUIRED'], $result->getCodes());
        $failure = $result->getReason()->failures[0];
        $this->assertEquals(UseCaseResult::EVALUATION_ERROR_MESSAGE, $failure->message);
        $this->assertEquals(true, $failure->metadata['evaluation_error']);
        $this->assertTrue($result->getReason()->isError);
        $this->assertNoLeak($this->renderings($result), 'validation evaluation error');
        $this->assertNoLeak($this->renderings($result->toArray()), 'validation evaluation error (array)');
        $this->assertCount(1, $reporter->reports, 'The evaluation exception is reported');
        $this->assertTrue(str_contains($reporter->reports[0][0]->getMessage(), USE_CASE19_SECRET), 'The reporter gets the full detail');
    }

    // ==========================================
    // 10. Middleware order (D6, D7)
    // ==========================================

    private function testMiddlewareOrder(): void
    {
        // One log shared with the probe getter of the request (it records the validation step).
        UseCase19ProbeRequest::$log = [];
        $log = &UseCase19ProbeRequest::$log;
        $logger = static function (string $name) use (&$log): Closure {
            return static function (object $request, callable $next) use ($name, &$log): UseCaseResult {
                $log[] = "{$name}>";
                $result = $next($request);
                $log[] = "<{$name}";

                return $result;
            };
        };
        $runner = new UseCaseRunner(null, [$logger('A')]);
        $runner->addMiddleware(new CallableMiddleware($logger('B')))->addMiddleware($logger('C'));
        $useCase = $this->useCase(static function () use (&$log): UseCaseResult {
            $log[] = 'execute';

            return UseCaseResult::success();
        });

        $this->assertTrue($runner->run($useCase, new UseCase19ProbeRequest())->isSuccess());
        $this->assertEquals(['A>', 'B>', 'C>', 'validate', 'execute', '<C', '<B', '<A'], $log, 'Inbound in registration order → validation → execute → outbound reversed');

        // The outbound part sees the final result, warnings included, and may replace it.
        $tagging = (new UseCaseRunner())->addMiddleware(
            static fn(object $request, callable $next): UseCaseResult => $next($request)->withCorrelationId('corr-1')
        );
        $tagged = $tagging->run($this->useCase(static fn(): UseCaseResult => UseCaseResult::success()), new UseCase19RegisterRequest('Ana', 16));
        $this->assertEquals('corr-1', $tagged->getCorrelationId());
        $this->assertEquals(['AGE_UNDER_18', 'NICKNAME_MISSING'], $tagged->getCodes(), 'Warnings attached before the outbound part');

        // A middleware may short-circuit: nothing inside runs.
        $log = [];
        $guarded = new UseCaseRunner(null, [
            $logger('A'),
            static fn(object $request, callable $next): UseCaseResult => UseCaseResult::forbidden('NO_ACCESS', 'Not allowed.'),
            $logger('B'),
        ]);
        $blocked = $guarded->run($useCase, new UseCase19RegisterRequest('', 1));
        $this->assertEquals(UseCaseStatus::FORBIDDEN, $blocked->getStatus());
        $this->assertEquals(['A>', '<A'], $log, 'Short-circuit: validation and use case skipped');

        // INVALID still goes through the outbound part.
        $log = [];
        $outer = new UseCaseRunner(null, [$logger('A')]);
        $this->assertEquals(UseCaseStatus::INVALID, $outer->run($useCase, new UseCase19RegisterRequest('', 30))->getStatus());
        $this->assertEquals(['A>', '<A'], $log);
    }

    // ==========================================
    // 11. TransactionMiddleware (RN-05)
    // ==========================================

    private function testTransactionMiddleware(): void
    {
        $log = [];
        $failOn = [];
        $tx = static function () use (&$log, &$failOn): TransactionMiddleware {
            $step = static function (string $name) use (&$log, &$failOn): Closure {
                return static function () use ($name, &$log, &$failOn): void {
                    $log[] = $name;
                    if (in_array($name, $failOn, true)) {
                        throw new RuntimeException("{$name} failed " . USE_CASE19_SECRET);
                    }
                };
            };

            return new TransactionMiddleware($step('begin'), $step('commit'), $step('rollback'));
        };
        $request = new UseCase19RegisterRequest('Ana', 30, 'ana');
        $reporter = new UseCase19RecordingReporter();
        $runner = new UseCaseRunner($reporter, [$tx()]);

        foreach ([
            'success' => [UseCaseResult::success(), ['begin', 'commit']],
            'no change' => [UseCaseResult::noChange(), ['begin', 'commit']],
            'rejected' => [UseCaseResult::rejected(SpecificationResult::failure('No', 'NO')), ['begin', 'rollback']],
            'conflict' => [UseCaseResult::conflict('C', 'Conflict.'), ['begin', 'rollback']],
        ] as $label => [$returned, $expected]) {
            $log = [];
            $result = $runner->run($this->useCase(static fn(): UseCaseResult => $returned), $request);
            $this->assertTrue($result === $returned, "{$label}: result passed through");
            $this->assertEquals($expected, $log, "{$label}: commit only on success, rollback on failure (RN-05)");
        }

        $log = [];
        $this->assertEquals(UseCaseStatus::INVALID, $runner->run($this->useCase(static fn(): UseCaseResult => UseCaseResult::success()), new UseCase19RegisterRequest('', 30))->getStatus());
        $this->assertEquals(['begin', 'rollback'], $log, 'INVALID rolls back (validation runs inside the middlewares)');

        // Exception: rollback, and the exception reaches the runner untouched.
        $log = [];
        $thrown = new RuntimeException('use case ' . USE_CASE19_SECRET);
        $result = $runner->run($this->useCase(static fn(): UseCaseResult => throw $thrown), $request);
        $this->assertEquals(['begin', 'rollback'], $log, 'Rollback on exception');
        $this->assertEquals(UseCaseStatus::FAILED, $result->getStatus());
        $this->assertTrue(end($reporter->reports)[0] === $thrown, 'The middleware did not swallow nor wrap the exception');
        $this->assertNoLeak($this->renderings($result), 'transaction + exception', $thrown);

        // Called directly, the middleware rethrows.
        $log = [];
        $caught = $this->assertThrows(RuntimeException::class, fn() => $tx()->process($request, static fn(): UseCaseResult => throw $thrown));
        $this->assertTrue($caught === $thrown, 'Never swallowed (RN-05)');
        $this->assertEquals(['begin', 'rollback'], $log);

        // Commit that throws: rollback attempted, FAILED, commit exception reported.
        $log = [];
        $failOn = ['commit'];
        $result = $runner->run($this->useCase(static fn(): UseCaseResult => UseCaseResult::success()), $request);
        $this->assertEquals(['begin', 'commit', 'rollback'], $log);
        $this->assertEquals(UseCaseStatus::FAILED, $result->getStatus());
        $this->assertTrue(str_starts_with(end($reporter->reports)[0]->getMessage(), 'commit failed'));
        $this->assertNoLeak($this->renderings($result), 'commit failure');

        // Rollback that throws after an exception: both kept in one exception.
        $log = [];
        $failOn = ['rollback'];
        $result = $runner->run($this->useCase(static fn(): UseCaseResult => throw $thrown), $request);
        $this->assertEquals(UseCaseStatus::FAILED, $result->getStatus());
        $reported = end($reporter->reports)[0];
        $this->assertInstanceOf(TransactionRollbackFailedException::class, $reported);
        $this->assertTrue($reported->getOriginal() === $thrown, 'Original exception kept');
        $this->assertTrue(str_starts_with((string) $reported->getPrevious()?->getMessage(), 'rollback failed'), 'Rollback failure kept');
        $this->assertNoLeak($this->renderings($result), 'rollback failure');

        // Rollback that throws on a failure result: unhandled → FAILED.
        $log = [];
        $result = $runner->run($this->useCase(static fn(): UseCaseResult => UseCaseResult::conflict('C', 'Conflict.')), $request);
        $this->assertEquals(UseCaseStatus::FAILED, $result->getStatus());
        $failOn = [];
    }

    // ==========================================
    // 12. fromTransition (D4)
    // ==========================================

    private function testFromTransition(): void
    {
        $machine = new StateMachine();
        $definition = $this->documentMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $snapshot = $machine->initialSnapshot($definition, null, $t0)->snapshot;

        $this->assertInstanceOf(IOutcome::class, $machine->initialSnapshot($definition, null, $t0), 'TransitionResult implements IOutcome (D4)');

        $accepted = $machine->transition($definition, $snapshot, Event::external('SUBMIT', $t0, ['file' => 'a.pdf']));
        $success = UseCaseResult::fromTransition($accepted);
        $this->assertEquals(UseCaseStatus::SUCCESS, $success->getStatus());
        $this->assertTrue($success->getPayload() === $accepted->getSnapshot(), 'Payload = snapshot when none given');
        $this->assertTrue(UseCaseResult::fromTransition($accepted, ['custom' => true])->getPayload() === ['custom' => true], 'Explicit payload kept');
        $this->assertEquals($accepted->isSuccess(), $success->isSuccess());
        $this->assertEquals(['r' => 'sent'], $success->toArray()['payload']['states'] ?? null, 'Snapshot payload serialized through its toArray()');

        $rejectedTransition = $machine->transition($definition, $snapshot, Event::external('SUBMIT', $t0));
        $this->assertEquals(TransitionStatus::REJECTED, $rejectedTransition->getStatus());
        $rejected = UseCaseResult::fromTransition($rejectedTransition);
        $this->assertEquals(UseCaseStatus::REJECTED, $rejected->getStatus());
        $this->assertEquals($rejectedTransition->getReason()->getReasons(), $rejected->getReason()->getReasons(), 'Same reason');
        $this->assertEquals($rejectedTransition->getReason()->getCodes(), $rejected->getReason()->getCodes());
        $this->assertTrue($rejected->getPayload() === null);

        $unhandled = UseCaseResult::fromTransition($machine->transition($definition, $snapshot, Event::external('ARCHIVE', $t0)));
        $this->assertEquals(UseCaseStatus::CONFLICT, $unhandled->getStatus(), 'Unhandled → CONFLICT');
        $this->assertEquals([TransitionResult::CODE_UNHANDLED], $unhandled->getCodes());
        $this->assertEquals(FailureSeverity::INFO, $unhandled->getReason()->failures[0]->severity, 'Same reason, same severity');

        $done = UseCaseResult::fromTransition($machine->transition($definition, $accepted->getSnapshot(), Event::external('SUBMIT', $t0, ['file' => 'b.pdf'])));
        $this->assertEquals(UseCaseStatus::CONFLICT, $done->getStatus());
        $this->assertEquals([TransitionResult::CODE_MACHINE_DONE], $done->getCodes());

        $conflict = UseCaseResult::fromConcurrencyConflict(new ConcurrencyConflictException('quote-9', 3, 4));
        $this->assertEquals(UseCaseStatus::CONFLICT, $conflict->getStatus());
        $this->assertEquals(409, $conflict->getStatus()->toHttpStatus());
        $this->assertEquals([UseCaseResult::CODE_CONCURRENCY_CONFLICT], $conflict->getCodes());
        $this->assertEquals(['instance_id' => 'quote-9', 'expected_version' => 3, 'actual_version' => 4], $conflict->getReason()->failures[0]->metadata);
        $this->assertFalse(str_contains($this->renderings($conflict), 'ConcurrencyConflictException'), 'Only the data, not the exception');

        // A guard evaluation error inside a transition: no exception, no secret in the use case result.
        $guards = Spec::guardRegistry()->register('explode', static fn(array $params): ISpecification => new UseCase19ExplodingSpec());
        $exploding = Spec::machine('exploding', 1)->region('r', 'a')->state('a')->on('GO', 'b')->guard('explode')->finalState('b')->build();
        $pure = new StateMachine($guards);
        $raw = $pure->transition($exploding, $pure->initialSnapshot($exploding, null, $t0)->snapshot, Event::external('GO', $t0));
        $this->assertTrue($raw->isRejected());
        $converted = UseCaseResult::fromTransition($raw);
        $this->assertEquals(UseCaseStatus::REJECTED, $converted->getStatus());
        $this->assertTrue($converted->getReason()->exception === null);
        $this->assertNoLeak($this->renderings($converted), 'guard evaluation error');
        $this->assertNoLeak($this->renderings($converted->toArray()), 'guard evaluation error (array)');
    }

    // ==========================================
    // 13. Bridge on the Virttus machine (D8, §4)
    // ==========================================

    private function testBridgeOnVirttus(): void
    {
        $machineRunner = $this->machineRunner([MachineDefinition::fromJson((string) file_get_contents(self::FIXTURE))], $this->virttusGuards());
        $t0 = $this->at('2026-10-10T09:00:00-03:00');
        $machineRunner->start('quote-1', self::VIRTTUS_KEY, null, ['role' => 'operator'], $t0, 'ana');

        $runner = Spec::useCaseRunner();
        $context = static fn(MachineEventRequest $r): array => ['role' => 'operator', 'actor' => $r->actor];
        $event = fn(string $type): StateMachineEventUseCase => Spec::machineEventUseCase($machineRunner, $type, $context);

        $spine = [
            ['COMPLETE_CALCULATION', 'calculation_done', 'none'],
            ['REQUEST_CLIENT_DOCUMENTS', 'awaiting_client_documents', 'none'],
            ['CLIENT_DOCUMENTS_RECEIVED', 'awaiting_hiring_proposal', 'none'],
            ['SEND_PROPOSAL', 'proposal_sent', 'none'],
            ['REGISTER_PENDING', 'proposal_sent', 'open'],
            ['RESOLVE_PENDING', 'proposal_sent', 'none'],
            ['APPROVE_PROPOSAL_QUOTATION', 'approve_proposal_quotation', 'none'],
            ['COMPLETE_PROPOSAL', 'completed', 'none'],
        ];
        foreach ($spine as $step => [$type, $progress, $pending]) {
            $at = $t0->modify('+' . ($step + 1) . ' hours');
            $payload = $type === 'REGISTER_PENDING' ? ['motive' => 'missing_document'] : [];
            $version = $machineRunner->load('quote-1')->version;
            $result = $runner->run($event($type), new MachineEventRequest('quote-1', $at, $payload, "click-{$step}", 'ana', $version));
            $this->assertEquals(UseCaseStatus::SUCCESS, $result->getStatus(), "{$type} → SUCCESS: " . $result->getReason());
            $this->assertEquals(200, $result->getStatus()->toHttpStatus());
            $this->assertInstanceOf(MachineSnapshot::class, $result->getPayload());
            $this->assertEquals(['progress' => $progress, 'pending' => $pending], $result->getPayload()->getStates(), "{$type}: snapshot payload");
            $this->assertEquals($version + 1, $machineRunner->load('quote-1')->version);

            if ($type === 'REGISTER_PENDING') {
                // Progress blocked by the open pending item → REJECTED with QUOTE_PENDING_OPEN.
                $blocked = $runner->run($event('APPROVE_PROPOSAL_QUOTATION'), new MachineEventRequest('quote-1', $at, [], 'blocked', 'ana'));
                $this->assertEquals(UseCaseStatus::REJECTED, $blocked->getStatus(), 'Pending open → REJECTED');
                $this->assertEquals(422, $blocked->getStatus()->toHttpStatus());
                $this->assertEquals(['QUOTE_PENDING_OPEN'], $blocked->getCodes());
                $this->assertEquals(FailureSeverity::ERROR, $blocked->getReason()->failures[0]->severity);
                $this->assertEquals($version + 1, $machineRunner->load('quote-1')->version, 'A rejection writes nothing');

                // An event that does not fit the current state → CONFLICT.
                $misplaced = $runner->run($event('COMPLETE_CALCULATION'), new MachineEventRequest('quote-1', $at));
                $this->assertEquals(UseCaseStatus::CONFLICT, $misplaced->getStatus(), 'Event not valid in the current state → CONFLICT');
                $this->assertEquals([TransitionResult::CODE_UNHANDLED], $misplaced->getCodes());

                // A stale expected version → CONFLICT with SM_CONCURRENCY_CONFLICT; nothing written.
                $stale = $runner->run($event('RESOLVE_PENDING'), new MachineEventRequest('quote-1', $at, [], null, 'ana', $version));
                $this->assertEquals(UseCaseStatus::CONFLICT, $stale->getStatus(), 'Stale expectedVersion → CONFLICT');
                $this->assertEquals(409, $stale->getStatus()->toHttpStatus());
                $this->assertEquals([UseCaseResult::CODE_CONCURRENCY_CONFLICT], $stale->getCodes());
                $this->assertEquals(
                    ['instance_id' => 'quote-1', 'expected_version' => $version, 'actual_version' => $version + 1],
                    $stale->getReason()->failures[0]->metadata
                );
                $this->assertEquals(['progress' => 'proposal_sent', 'pending' => 'open'], $machineRunner->load('quote-1')->snapshot->getStates());
            }
        }

        $instance = $machineRunner->load('quote-1');
        $this->assertTrue($instance->snapshot->isDone(), 'The spine ends in completed');
        $this->assertEquals(1 + count($spine), $instance->version);
        $history = $machineRunner->getPersistence()->history->findByInstance('quote-1');
        $this->assertCount(2 + count($spine), $history, 'One history row per step, as with the runner directly');
        $this->assertEquals('ana', end($history)->actor, 'The actor of the request reaches the history');
        $this->assertEquals('click-7', end($history)->idempotencyKey);

        // The same idempotency key again: the original result, SUCCESS, nothing written.
        $replay = $runner->run($event('COMPLETE_PROPOSAL'), new MachineEventRequest('quote-1', $t0->modify('+20 hours'), [], 'click-7'));
        $this->assertEquals(UseCaseStatus::SUCCESS, $replay->getStatus(), 'Idempotent replay → original success');
        $this->assertEquals(['progress' => 'completed', 'pending' => 'none'], $replay->getPayload()->getStates());
        $this->assertEquals(1 + count($spine), $machineRunner->load('quote-1')->version);

        // A done machine: CONFLICT (SM_MACHINE_DONE).
        $doneResult = $runner->run($event('CANCEL'), new MachineEventRequest('quote-1', $t0->modify('+21 hours')));
        $this->assertEquals(UseCaseStatus::CONFLICT, $doneResult->getStatus());
        $this->assertEquals([TransitionResult::CODE_MACHINE_DONE], $doneResult->getCodes());
    }

    // ==========================================
    // 14. Bridge edge cases
    // ==========================================

    private function testBridgeEdgeCases(): void
    {
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $machineRunner = $this->machineRunner([$this->documentMachine()]);
        $machineRunner->start('doc-1', 'document', null, null, $t0);
        $reporter = new UseCase19RecordingReporter();
        $runner = new UseCaseRunner($reporter);
        $submit = new StateMachineEventUseCase($machineRunner, 'SUBMIT');
        $this->assertEquals('SUBMIT', $submit->getEventType());

        $notFound = $runner->run($submit, new MachineEventRequest('doc-404', $t0, ['file' => 'a.pdf']));
        $this->assertEquals(UseCaseStatus::NOT_FOUND, $notFound->getStatus(), 'Unknown instance → NOT_FOUND');
        $this->assertEquals([StateMachineEventUseCase::CODE_INSTANCE_NOT_FOUND], $notFound->getCodes());
        $this->assertEquals(404, $notFound->getStatus()->toHttpStatus());

        $blank = $runner->run($submit, new MachineEventRequest(' ', $t0));
        $this->assertEquals(UseCaseStatus::INVALID, $blank->getStatus(), 'Blank instance id → INVALID by the request attribute');
        $this->assertEquals(['SM_INSTANCE_ID_REQUIRED'], $blank->getCodes());

        $guardRejected = $runner->run($submit, new MachineEventRequest('doc-1', $t0));
        $this->assertEquals(UseCaseStatus::REJECTED, $guardRejected->getStatus(), 'payloadHas(file) refuses');

        $wrongRequest = $runner->run($submit, new \stdClass());
        $this->assertEquals(UseCaseStatus::FAILED, $wrongRequest->getStatus(), 'A request of the wrong type is a programming error → FAILED');
        $this->assertInstanceOf(InvalidArgumentException::class, end($reporter->reports)[0]);

        $this->assertThrows(InvalidArgumentException::class, fn() => new StateMachineEventUseCase($machineRunner, ' SUBMIT'));

        // Through a TransactionMiddleware: commit on SUCCESS, rollback on REJECTED.
        $log = [];
        $txRunner = new UseCaseRunner(null, [new TransactionMiddleware(
            static function () use (&$log): void {
                $log[] = 'begin';
            },
            static function () use (&$log): void {
                $log[] = 'commit';
            },
            static function () use (&$log): void {
                $log[] = 'rollback';
            }
        )]);
        $this->assertEquals(UseCaseStatus::REJECTED, $txRunner->run($submit, new MachineEventRequest('doc-1', $t0))->getStatus());
        $this->assertEquals(UseCaseStatus::SUCCESS, $txRunner->run($submit, new MachineEventRequest('doc-1', $t0, ['file' => 'a.pdf']))->getStatus());
        $this->assertEquals(['begin', 'rollback', 'begin', 'commit'], $log);

        // A guard that throws: REJECTED, generic message, no secret; the reporter of the adapter gets it.
        $guards = Spec::guardRegistry()->register('explode', static fn(array $params): ISpecification => new UseCase19ExplodingSpec());
        $exploding = Spec::machine('exploding', 1)->region('r', 'a')->state('a')->on('GO', 'b')->guard('explode')->finalState('b')->build();
        $explodingRunner = $this->machineRunner([$exploding], $guards);
        $explodingRunner->start('x-1', 'exploding', null, null, $t0);
        $guardReporter = new UseCase19RecordingReporter();
        $result = $runner->run(new StateMachineEventUseCase($explodingRunner, 'GO', null, $guardReporter), new MachineEventRequest('x-1', $t0));
        $this->assertEquals(UseCaseStatus::REJECTED, $result->getStatus());
        $this->assertEquals(UseCaseResult::EVALUATION_ERROR_MESSAGE, $result->getReason()->failures[0]->message);
        $this->assertNoLeak($this->renderings($result), 'guard exception through the bridge');
        $this->assertCount(1, $guardReporter->reports, 'The guard exception is reported by the adapter');
        $this->assertTrue(str_contains($guardReporter->reports[0][0]->getMessage(), USE_CASE19_SECRET));
    }

    // ==========================================
    // 15. Facade
    // ==========================================

    private function testFacade(): void
    {
        $reporter = new UseCase19RecordingReporter();
        $runner = Spec::useCaseRunner($reporter, [static fn(object $r, callable $next): UseCaseResult => $next($r)]);
        $this->assertInstanceOf(UseCaseRunner::class, $runner);
        $this->assertTrue($runner->getErrorReporter() === $reporter);
        $this->assertTrue($runner->run($this->useCase(static fn(): UseCaseResult => UseCaseResult::success()), new \stdClass())->isSuccess());

        $useCase = Spec::machineEventUseCase($this->machineRunner([$this->documentMachine()]), 'SUBMIT');
        $this->assertInstanceOf(StateMachineEventUseCase::class, $useCase);
        $this->assertInstanceOf(IUseCase::class, $useCase);
    }

    // ==========================================
    // README (1.8.0): exemplos 15.1 e 15.2 transcritos (EN/pt-BR); cada comentário "// valor" do
    // bloco é uma asserção aqui.
    // ==========================================

    /** README 15.1: IUseCase + UseCaseRunner, WARNING não bloqueia, ERROR bloqueia, transação, HTTP só na borda. */
    private function testReadme15_1UseCaseRunnerWithTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tenant (id INTEGER PRIMARY KEY, name TEXT NOT NULL, rent INTEGER NOT NULL)');

        $useCases = Spec::useCaseRunner(middlewares: [
            new TransactionMiddleware($pdo->beginTransaction(...), $pdo->commit(...), $pdo->rollBack(...)),
        ]);

        $created = $useCases->run(new Readme15RegisterTenant($pdo), new Readme15RegisterTenantRequest('Alice', 6200));
        $this->assertTrue($created->isSuccess());                         // a WARNING never blocks (committed)
        $this->assertTrue($created->getStatus() === UseCaseStatus::CREATED);
        $this->assertEquals('RENT_REVIEW', $created->getWarnings()[0]->code);

        $invalid = $useCases->run(new Readme15RegisterTenant($pdo), new Readme15RegisterTenantRequest('', 1200));
        $this->assertTrue($invalid->getStatus() === UseCaseStatus::INVALID); // execute() was not called (rolled back)
        $this->assertEquals(['NAME_REQUIRED'], $invalid->getCodes());

        // HTTP only at the edge of the application
        $this->assertEquals(400, $invalid->getStatus()->toHttpStatus());  // 400
        $this->assertEquals(201, UseCaseStatus::CREATED->toHttpStatus());
        $this->assertEquals(422, UseCaseStatus::REJECTED->toHttpStatus());
        $this->assertEquals(409, UseCaseStatus::CONFLICT->toHttpStatus());

        // Prosa do exemplo: só o sucesso foi gravado; nenhuma transação ficou aberta
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
        // correção da 1.8.0: o campo severity da falha vem da severidade declarada no atributo
        $this->assertTrue($created->getWarnings()[0]->severity === FailureSeverity::WARNING);
    }

    /** README 15.2: um evento da máquina (o quote do README 14.2) exposto como caso de uso. */
    private function testReadme15_2MachineEventAsUseCase(): void
    {
        $now = new DateTimeImmutable('2026-10-10 09:00');
        $quote = Spec::machine('quote', 1)
            ->region('progress', 'registering')
                ->state('registering')
                    ->on('SEND_PROPOSAL', 'proposal_sent')
                ->state('proposal_sent')
                    ->on('APPROVE', 'approved')
                        ->guard(GuardExpression::not(GuardExpression::ref('inState', ['state' => 'pending.open']))
                            ->because('Resolve the open pending item first.')
                            ->withCode('QUOTE_PENDING_OPEN'))
                    ->on('CANCEL', 'cancelled')
                ->finalState('approved')
                ->finalState('cancelled')
            ->region('pending', 'none')
                ->state('none')
                    ->on('REGISTER_PENDING', 'open')->guard('payloadHas', ['field' => 'motive'])
                ->state('open')
                    ->on('RESOLVE_PENDING', 'none')
            ->build();

        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$quote]), MachinePersistence::inMemory());
        $runner->start('quote-7', 'quote', 1, null, $now);

        $useCases = Spec::useCaseRunner();
        $send = fn(string $event, array $payload = []): UseCaseResult => $useCases->run(
            Spec::machineEventUseCase($runner, $event),
            new MachineEventRequest('quote-7', $now, $payload, actor: 'alice')
        );

        $this->assertTrue($send('SEND_PROPOSAL')->getStatus() === UseCaseStatus::SUCCESS);
        $this->assertTrue($send('REGISTER_PENDING', ['motive' => 'Missing ID'])->isSuccess());
        $refused = $send('APPROVE');
        $this->assertEquals(['QUOTE_PENDING_OPEN'], $refused->getCodes());   // status REJECTED, HTTP 422
        $this->assertTrue($refused->getStatus() === UseCaseStatus::REJECTED);
        $this->assertEquals(422, $refused->getStatus()->toHttpStatus());
        $this->assertTrue($send('SEND_PROPOSAL')->getStatus() === UseCaseStatus::CONFLICT); // the event does not fit the current state
        $this->assertEquals('proposal_sent', $send('RESOLVE_PENDING')->getPayload()->stateOf('progress'));

        $this->assertTrue($useCases->run(Spec::machineEventUseCase($runner, 'CANCEL'), new MachineEventRequest('quote-99', $now))
            ->getStatus() === UseCaseStatus::NOT_FOUND);

        // Prosa do exemplo: versão velha → CONFLICT com SM_CONCURRENCY_CONFLICT
        $stale = $useCases->run(Spec::machineEventUseCase($runner, 'CANCEL'), new MachineEventRequest('quote-7', $now, expectedVersion: 1));
        $this->assertTrue($stale->getStatus() === UseCaseStatus::CONFLICT);
        $this->assertEquals([UseCaseResult::CODE_CONCURRENCY_CONFLICT], $stale->getCodes());
    }
}
