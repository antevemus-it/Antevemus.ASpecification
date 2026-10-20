<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Linq\Exceptions\NonTranslatableLinqException;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\CurlMultiTransport;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataResolutionException;
use Antevemus\ASpecification\Resolution\Exceptions\DataSourceNotFoundException;
use Antevemus\ASpecification\Resolution\ExternalDataSpecification;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\IDataSource;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Antevemus\ASpecification\Resolution\IStageTransport;
use Antevemus\ASpecification\Resolution\InMemoryDataCache;
use Antevemus\ASpecification\Resolution\ResolutionMode;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Resolution\ResolutionScope;
use Antevemus\ASpecification\Resolution\ResolvedData;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Tests\Support\DeferredProbeSource;
use Antevemus\ASpecification\Tests\Support\LocalHttpServer;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\RecordingStageTransport;
use Antevemus\ASpecification\Tests\TestCase;
use ArrayObject;
use Closure;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * R021Opaque - A node type the resolver does not know (forward 026 D7): it wraps a specification
 * and decides through isSatisfiedBy(), so it does not expose its operand.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class R021Opaque extends AbstractSpecification
{
    public function __construct(private readonly ISpecification $inner)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->inner->isSatisfiedBy($candidate);
    }

    public function getType(): string
    {
        return 'mixed';
    }
}

/**
 * R021Document - Backed enum used as a request parameter.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 */
enum R021Document: string
{
    case CPF = 'cpf';
}

/**
 * Module21_ConcurrentDataResolutionTest - Concurrent data resolution for I/O-bound specifications
 * (forward 026, v1.10.0).
 *
 * Every HTTP test runs against a local `php -S 127.0.0.1:<free port>` server started by the suite
 * (tests/Support/LocalHttpServer.php + resolution_router.php): per-endpoint delays, a call counter
 * written to a temporary file, error statuses and bodies. The server is stopped in a `finally`,
 * so it never outlives the suite, even when an assertion fails. No test reaches the internet.
 *
 * - D1/D2: the leaf, its parameters (dot paths), the canonical key of a request;
 * - D3/RN-06: URL building with encoded parameters, base URL and headers owned by the source,
 *   redirects not followed, error bodies never in a failure or an exception message;
 * - D4/RN-02: concurrency measured (three 300 ms sources: concurrent < 600 ms, sequential > 900 ms);
 * - D6/RN-03: tiered policy skips the expensive sources once the verdict is decided (server counter);
 * - RN-04: deduplication; RN-05: equivalence of the policies and of the evaluation outside a scope;
 * - D5: scope stack and the synchronous fallback outside it; D7: three-valued logic and unknown
 *   nodes; D8: errors and time limits; D9: cache; D10: visitors refuse the leaf; D12: no curl;
 * - the example 16 blocks of the README (EN/pt-BR), transcribed against the local server;
 * - 2.6.0 (forward 045): deferred sources and stage transports (IDeferredDataSource, IStageTransport),
 *   with a fake transport (tests/Support/RecordingStageTransport.php + DeferredProbeSource.php): the
 *   three groups of a stage and their order, grouping by transport instance, tiered stages, failures
 *   (Throwable result, missing key, crashed transport), cache, deadline, sequential policy,
 *   deduplication, the unitary path, a randomized equivalence check and example 16.1 run as written.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module21_ConcurrentDataResolutionTest extends TestCase
{
    private const SECRET_BODY = 'SECRET-ERROR-BODY-7f3a';

    private LocalHttpServer $server;

    /** @var array<string, float> measured durations in ms (for the report) */
    public static array $timings = [];

    public function run(): void
    {
        $previousDefault = DataSourceRegistry::default();
        $this->server = new LocalHttpServer();
        $this->server->start();

        try {
            $this->configureEndpoints();

            $this->testDataRequestCanonicalKey();
            $this->testLeafDeclaration();
            $this->testPoliciesAndRegistry();
            $this->testOutOfScopeFallback();
            $this->testScopeIsAStack();
            $this->testHttpCallBuilding();
            $this->testConcurrencyIsMeasured();
            $this->testTieredSkipsExpensiveSources();
            $this->testDeduplication();
            $this->testEquivalenceAcrossPolicies();
            $this->testSecurity();
            $this->testErrorsAndTimeLimits();
            $this->testThreeValuedLogicAndUnknownNodes();
            $this->testCache();
            $this->testVisitorsRefuseTheLeaf();
            $this->testHttpSourceNeedsCurl();
            $this->testReadme16BlocksAreTheSameInBothLanguages();
            $this->testReadme16RunsAsWritten('en');
            $this->testReadme16RunsAsWritten('pt-BR');
            $this->testDeferredStageRunsHttpThenTransportsThenOthers();
            $this->testDeferredGroupsByTransportInstance();
            $this->testDeferredTieredFetchesByCost();
            $this->testDeferredResultsFailuresAndCache();
            $this->testDeferredMissingKeyFails();
            $this->testDeferredTransportCrashFailsOnlyItsKeys();
            $this->testDeferredDeadline();
            $this->testDeferredSequentialAndDeduplication();
            $this->testDeferredUnitaryFetchOutsideAScope();
            $this->testDeferredRandomizedEquivalence();
            $this->testStageRunnerEdgeCases();
            $this->testReadme16_1BlocksAreTheSameInBothLanguages();
            $this->testReadme16_1RunsAsWritten();
            $this->testServerIsStoppedEvenOnFailure();
        } finally {
            $this->server->stop();
            DataSourceRegistry::setDefault($previousDefault);
        }

        $this->assertServerIsGone($this->server);
    }

    // ==========================================
    // Fixture
    // ==========================================

    private function configureEndpoints(): void
    {
        $s = $this->server;
        $s->endpoint('serasa', ['values' => [
            '111' => ['score' => 720], '222' => ['score' => 500], '333' => ['score' => 800], '444' => ['score' => 650],
            '111.444.777-35' => ['score' => 720], '222.333.444-05' => ['score' => 650],
        ], 'default' => ['score' => 0]]);
        $s->endpoint('fraud', ['values' => [
            '111' => ['flagged' => false], '222' => ['flagged' => false], '333' => ['flagged' => true], '444' => ['flagged' => false],
        ], 'default' => ['flagged' => false]]);
        $s->endpoint('bank', ['values' => [
            'A1' => ['balance' => 2000], 'A2' => ['balance' => 10], 'A3' => ['balance' => 60000],
            'A-1' => ['balance' => 2000], 'A-2' => ['balance' => 10],
        ], 'default' => ['balance' => 0]]);
        $s->endpoint('down', ['status' => 500, 'body' => self::SECRET_BODY]);
        $s->endpoint('limits', ['status' => 503, 'body' => 'LIMITS-' . self::SECRET_BODY]);
        $s->endpoint('echo', ['default' => ['ok' => true]]);
        $s->endpoint('redirect', ['status' => 302, 'location' => $s->baseUrl() . '/leak/x']);
        $s->endpoint('leak', ['default' => ['leaked' => true]]);
        $s->endpoint('badjson', ['body' => 'this is not json']);
        foreach (['slow1', 'slow2', 'slow3'] as $slow) {
            $s->endpoint($slow, ['delay_ms' => 300, 'default' => ['v' => true]]);
        }
        $s->endpoint('veryslow', ['delay_ms' => 1000, 'default' => ['v' => true]]);
    }

    /**
     * HTTP source on the local server whose value is one field of the JSON body.
     */
    private function source(string $template, string $field, int $cost = 0, ?int $ttl = null, float $timeout = 5.0): HttpDataSource
    {
        return new HttpDataSource(
            baseUrl: $this->server->baseUrl(),
            path: $template,
            parse: static fn(int $status, array $headers, string $body): mixed => json_decode($body, true)[$field] ?? null,
            timeout: $timeout,
            cost: $cost,
            ttl: $ttl
        );
    }

    /**
     * The registry of the credit scenario: balance (cost 1), fraud (5), bureau score (10) and a source
     * that always answers 500.
     */
    private function creditRegistry(): DataSourceRegistry
    {
        return (new DataSourceRegistry())
            ->register('bank.balance', $this->source('/bank/balance/{account}', 'balance', 1))
            ->register('fraud.flag', $this->source('/fraud/check/{document}', 'flagged', 5))
            ->register('serasa.score', $this->source('/serasa/score/{document}', 'score', 10))
            ->register('fraud.down', $this->source('/down/{document}', 'x', 5));
    }

    private static function score(int $min): ExternalDataSpecification
    {
        return Spec::external('serasa.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo($min));
    }

    private static function clean(): ExternalDataSpecification
    {
        return Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isFalse());
    }

    private static function balance(int $min): ExternalDataSpecification
    {
        return Spec::external('bank.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo($min));
    }

    /**
     * @return array<string, object>
     */
    private static function customers(): array
    {
        return [
            'c1' => (object) ['cpf' => '111', 'account' => 'A1', 'age' => 30, 'customer' => (object) ['cpf' => '111']],
            'c2' => (object) ['cpf' => '222', 'account' => 'A2', 'age' => 17, 'customer' => (object) ['cpf' => '222']],
            'c3' => (object) ['cpf' => '333', 'account' => 'A3', 'age' => 45, 'customer' => (object) ['cpf' => '333']],
            'c4' => (object) ['cpf' => '444', 'account' => 'A2', 'age' => 50, 'customer' => (object) ['cpf' => '444']],
        ];
    }

    private static function ms(int $startNs): float
    {
        return (hrtime(true) - $startNs) / 1e6;
    }

    // ==========================================
    // D1 / D2: request and leaf
    // ==========================================

    private function testDataRequestCanonicalKey(): void
    {
        $a = new DataRequest('credit.score', ['document' => '111', 'product' => 'loan']);
        $b = new DataRequest('credit.score', ['product' => 'loan', 'document' => '111']);
        $this->assertEquals($a->getKey(), $b->getKey(), 'parameter order does not change the key');
        $this->assertTrue($a->equals($b));
        $this->assertEquals(['document' => '111', 'product' => 'loan'], $b->parameters, 'parameters are sorted by name');
        $this->assertFalse($a->getKey() === (new DataRequest('credit.score', ['document' => '112', 'product' => 'loan']))->getKey());
        $this->assertFalse($a->getKey() === (new DataRequest('credit.limit', ['document' => '111', 'product' => 'loan']))->getKey());
        $this->assertFalse(str_contains($a->getKey(), '111'), 'the key does not carry parameter values');
        $this->assertTrue(str_starts_with($a->getKey(), 'credit.score#'));

        $stringable = new class () implements \Stringable {
            public function __toString(): string
            {
                return '111';
            }
        };
        $this->assertEquals($a->getKey(), (new DataRequest('credit.score', ['document' => $stringable, 'product' => 'loan']))->getKey());
        $this->assertEquals('cpf', (new DataRequest('x', ['kind' => R021Document::CPF]))->get('kind'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new DataRequest('x', ['document' => null]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new DataRequest('x', ['document' => ['a']]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new DataRequest('x', ['document' => NAN]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new DataRequest('x', ['bad-name' => 1]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new DataRequest('bad name', []));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $a->get('missing'));
    }

    private function testLeafDeclaration(): void
    {
        $leaf = Spec::external('serasa.score', ['document' => 'customer.cpf'], Spec::greaterThanOrEqualTo(700));
        $this->assertInstanceOf(ExternalDataSpecification::class, $leaf);
        $this->assertEquals('serasa.score', $leaf->getSource());
        $this->assertEquals(['document' => 'customer.cpf'], $leaf->getParameters());
        $this->assertEquals("external('serasa.score')", (string) $leaf);
        $this->assertEquals('external(serasa.score)', $leaf->getRuleName());
        $this->assertTrue($leaf->equals(Spec::external('serasa.score', ['document' => 'customer.cpf'], Spec::greaterThanOrEqualTo(700))));
        $this->assertFalse($leaf->equals(Spec::external('serasa.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))));
        $this->assertTrue(Spec::external('x', [], Spec::alwaysFalse())->isContradiction());
        $this->assertFalse($leaf->isTautology());

        // Parameters are dot paths read when the request is built; the candidate is never wrapped
        $request = $leaf->createRequest((object) ['customer' => ['cpf' => '111']]);
        $this->assertEquals(['document' => '111'], $request->parameters);
        $this->assertEquals('serasa.score', $request->source);

        $this->assertThrows(\InvalidArgumentException::class, fn() => Spec::external('bad name', [], Spec::alwaysTrue()));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Spec::external('x', ['bad-name' => 'cpf'], Spec::alwaysTrue()));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Spec::external('x', ['document' => ''], Spec::alwaysTrue()));

        // Before any fetch: invalid candidate and null parameter are rule failures, a missing path an error
        $registry = (new DataSourceRegistry())->register('serasa.score', new CallableDataSource(fn(array $p): int => 900));
        $resolver = new DataResolver($registry);
        $invalid = $resolver->evaluate($leaf, null, ResolutionPolicy::tiered());
        $this->assertFalse($invalid->isSatisfied);
        $this->assertFalse($invalid->isError);
        $nullParameter = $resolver->evaluate($leaf, (object) ['customer' => (object) ['cpf' => null]]);
        $this->assertFalse($nullParameter->isSatisfied);
        $this->assertFalse($nullParameter->isError, 'no request is made for a null parameter');
        $this->assertTrue(str_contains($nullParameter->failures[0]->message, "Parameter 'document'"));
        $missing = $resolver->evaluate($leaf, (object) ['other' => 1]);
        $this->assertTrue($missing->isError);
        $this->assertEquals(SpecificationResult::EVALUATION_ERROR_MESSAGE, $missing->failures[0]->message);
        $this->assertTrue($missing->failures[0]->metadata['evaluation_error']);
        $this->assertTrue($resolver->evaluate($leaf, (object) ['customer' => (object) ['cpf' => '1']])->isSatisfied);

        // The inner specification decides, with its own annotations; the leaf's annotation wraps them
        $registry->register('bank.balance', new CallableDataSource(fn(array $p): int => 10));
        $low = Spec::external('bank.balance', [], Spec::greaterThan(100)->because('Low balance.')->withCode('LOW_BALANCE'));
        $this->assertEquals(['LOW_BALANCE'], $resolver->evaluate($low, (object) [])->getCodes());
        $this->assertEquals(['Low balance.'], $resolver->evaluate($low, (object) [])->getReasons());
        $wrapped = $low->because('Account check failed.')->withCode('ACCOUNT');
        $result = $resolver->evaluate($wrapped, (object) []);
        $this->assertEquals(['ACCOUNT'], $result->getCodes());
        $this->assertEquals('LOW_BALANCE', $result->failures[0]->metadata['causes'][0]->code);
        $this->assertEquals('bank.balance', $result->failures[0]->metadata['source']);
        $this->assertTrue($resolver->resolve($low, (object) [])->isSatisfiedBy(Spec::not($low), (object) []));
    }

    private function testPoliciesAndRegistry(): void
    {
        $this->assertEquals(ResolutionMode::CONCURRENT, ResolutionPolicy::concurrent()->getMode());
        $this->assertEquals(ResolutionMode::TIERED, ResolutionPolicy::tiered()->getMode());
        $this->assertEquals(ResolutionMode::SEQUENTIAL, ResolutionPolicy::sequential()->getMode());
        $this->assertEquals(null, ResolutionPolicy::tiered()->getTimeout());
        $this->assertEquals(2.5, ResolutionPolicy::concurrent(timeout: 2.5)->getTimeout());
        $policy = ResolutionPolicy::tiered();
        $this->assertEquals(1.0, $policy->withTimeout(1.0)->getTimeout());
        $this->assertEquals(null, $policy->getTimeout(), 'policies are immutable');
        $this->assertThrows(\InvalidArgumentException::class, fn() => ResolutionPolicy::tiered(0.0));

        // The default policy is concurrent(): the same failures as an evaluation outside the resolver
        // (1.10.0); tiered() saves calls but may report fewer failures, so it is an explicit choice
        $registry = (new DataSourceRegistry())->register('a', new CallableDataSource(fn(): int => 1));
        $this->assertEquals(ResolutionMode::CONCURRENT, (new DataResolver($registry))->resolve(Spec::alwaysTrue(), null)->getPolicy()->getMode());

        // Registry: names validated, registered once, unknown name typed
        $this->assertEquals(['a'], $registry->getNames());
        $this->assertTrue($registry->has('a'));
        $this->assertThrows(DataResolutionException::class, fn() => $registry->register('a', new CallableDataSource(fn(): int => 2)));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $registry->register('no spaces', new CallableDataSource(fn(): int => 2)));
        $notFound = $this->assertThrows(DataSourceNotFoundException::class, fn() => $registry->get('b'));
        $this->assertEquals('b', $notFound->getSourceName());
        $this->assertThrows(\InvalidArgumentException::class, fn() => new CallableDataSource(fn(): int => 1, ttl: -1));

        // DataSourceRegistry::default() is replaceable and resettable
        $mine = new DataSourceRegistry();
        DataSourceRegistry::setDefault($mine);
        $this->assertTrue(DataSourceRegistry::default() === $mine);
        $this->assertTrue((new DataResolver())->getRegistry() === $mine);
        DataSourceRegistry::setDefault(null);
        $this->assertFalse(DataSourceRegistry::default() === $mine);
    }

    // ==========================================
    // D5: outside a scope, and the scope stack
    // ==========================================

    private function testOutOfScopeFallback(): void
    {
        $calls = 0;
        $registry = (new DataSourceRegistry())
            ->register('limit', new CallableDataSource(function (array $p) use (&$calls): int {
                $calls++;
                return $p['document'] === '111' ? 5000 : 0;
            }));
        DataSourceRegistry::setDefault($registry);
        $leaf = Spec::external('limit', ['document' => 'cpf'], Spec::greaterThan(1000));

        $this->assertEquals(0, ResolutionScope::depth());
        $this->assertTrue($leaf->evaluate((object) ['cpf' => '111'])->isSatisfied, 'outside a scope the leaf fetches its own data');
        $this->assertTrue($leaf->isSatisfiedBy((object) ['cpf' => '111']));
        $this->assertFalse($leaf->evaluate((object) ['cpf' => '222'])->isSatisfied);
        $this->assertEquals(3, $calls, 'synchronous, one call per evaluation');

        // The other leaves keep reading the candidate as usual (the candidate is never wrapped)
        $rule = Spec::property('age', Spec::greaterThanOrEqualTo(18))->and($leaf);
        $this->assertTrue($rule->evaluate((object) ['cpf' => '111', 'age' => 30])->isSatisfied);
        $this->assertFalse($rule->evaluate((object) ['cpf' => '111', 'age' => 10])->isSatisfied);

        // An unknown source is an evaluation error, never a rule failure; isSatisfiedBy() throws it
        $unknown = Spec::external('nowhere', [], Spec::alwaysTrue());
        $result = $unknown->evaluate((object) []);
        $this->assertTrue($result->isError);
        $this->assertInstanceOf(DataSourceNotFoundException::class, $result->exception);
        $this->assertTrue(Spec::not($unknown)->evaluate((object) [])->isError, 'NOT never turns an error into approval');
        $this->assertThrows(DataSourceNotFoundException::class, fn() => $unknown->isSatisfiedBy((object) []));
        DataSourceRegistry::setDefault(null);
    }

    private function testScopeIsAStack(): void
    {
        $registry = (new DataSourceRegistry())
            ->register('n', new CallableDataSource(fn(array $p): int => (int) $p['v']));
        $resolver = new DataResolver($registry);
        $leaf = Spec::external('n', ['v' => 'v'], Spec::equalTo(1));
        $outer = $resolver->resolve($leaf, ['v' => 1], ResolutionPolicy::concurrent());
        $inner = $resolver->resolve($leaf, ['v' => 2], ResolutionPolicy::concurrent());

        $this->assertEquals(0, ResolutionScope::depth());
        $seen = $outer->run(function () use ($inner, $outer): array {
            $depths = [ResolutionScope::depth(), ResolutionScope::current() === $outer];
            $depths[] = $inner->run(fn(): bool => ResolutionScope::current() === $inner && ResolutionScope::depth() === 2);
            $depths[] = ResolutionScope::current() === $outer;
            try {
                $inner->run(function (): void {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
                $depths[] = ResolutionScope::depth();
            }
            return $depths;
        });
        $this->assertEquals([1, true, true, true, 1], $seen, 'reentrant, and closed when the callback throws');
        $this->assertEquals(0, ResolutionScope::depth());
        $this->assertEquals(null, ResolutionScope::current());
        $this->assertTrue($outer->evaluate($leaf, ['v' => 1])->isSatisfied);
        $this->assertFalse($inner->evaluate($leaf, ['v' => 2])->isSatisfied);
        $this->assertEquals(1, $outer->getFetchCount());
    }

    // ==========================================
    // D3 / RN-06 (unit part): the call is built by the source
    // ==========================================

    private function testHttpCallBuilding(): void
    {
        $base = 'https://bureau.example.com:8443/api';
        $source = new HttpDataSource(
            baseUrl: $base,
            path: '/v1/score/{document}?product={product}',
            method: 'POST',
            headers: ['Authorization' => 'Bearer token', 'Accept' => 'application/json'],
            body: fn(array $p): string => json_encode(['document' => $p['document']]),
            timeout: 1.5,
            cost: 7,
            ttl: 30
        );
        $this->assertEquals(7, $source->getCost());
        $this->assertEquals(30, $source->getTtl());
        $this->assertEquals(1.5, $source->getTimeout());
        $this->assertEquals('POST', $source->getMethod());
        $this->assertEquals($base, $source->getBaseUrl());

        $call = $source->createCall(new DataRequest('s', ['document' => '111.444.777-35', 'product' => 'loan']));
        $this->assertEquals('https://bureau.example.com:8443/api/v1/score/111%2E444%2E777-35?product=loan', $call->url);
        $this->assertEquals('POST', $call->method);
        $this->assertEquals(['Authorization' => 'Bearer token', 'Accept' => 'application/json'], $call->headers);
        $this->assertEquals('{"document":"111.444.777-35"}', $call->body);
        $this->assertEquals(1.5, $call->timeout);

        foreach (['../admin', '..', '/etc/passwd', 'http://evil.example/x', '//evil.example', 'a?b=c#frag', '%2e%2e%2f', "a\r\nX-Injected: 1", '@evil.example', '\\evil'] as $hostile) {
            $url = $source->createCall(new DataRequest('s', ['document' => $hostile, 'product' => 'a&product=b#x']))->url;
            $this->assertEquals('bureau.example.com', parse_url($url, PHP_URL_HOST), "host kept for {$hostile}");
            $this->assertEquals(8443, parse_url($url, PHP_URL_PORT));
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->assertTrue(str_starts_with($path, '/api/v1/score/'), "path prefix kept for {$hostile}");
            $this->assertFalse(str_contains(substr($path, strlen('/api/v1/score/')), '/'), "one segment for {$hostile}");
            $this->assertEquals($hostile, rawurldecode(substr($path, strlen('/api/v1/score/'))), 'the value arrives intact');
            $this->assertEquals(null, parse_url($url, PHP_URL_FRAGMENT));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertEquals(['product' => 'a&product=b#x'], $query, 'a query value cannot add parameters');
        }

        // Missing placeholder parameter: the call is not built
        $this->assertThrows(DataResolutionException::class, fn() => $source->createCall(new DataRequest('s', ['document' => '1'])));

        // The base URL, the template and the headers are validated when the source is built
        foreach (['ftp://x.example', 'https://', 'https://x.example?q=1', 'https://x.example#f', 'https://u:p@x.example', 'x.example', 'https://x.example/{id}'] as $badBase) {
            $this->assertThrows(\InvalidArgumentException::class, fn() => new HttpDataSource($badBase, '/a'), "base {$badBase}");
        }
        foreach (['a/{id}', '//evil.example/{id}', '/a#{id}', '/a/{id', '/a/{1d}', '/a b', '/a\\b'] as $badPath) {
            $this->assertThrows(\InvalidArgumentException::class, fn() => new HttpDataSource('https://x.example', $badPath), "path {$badPath}");
        }
        $this->assertThrows(\InvalidArgumentException::class, fn() => new HttpDataSource('https://x.example', '/a', headers: ['X-A' => "v\r\nX-B: 1"]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new HttpDataSource('https://x.example', '/a', method: 'get me'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new HttpDataSource('https://x.example', '/a', timeout: 0.0));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new HttpDataSource('https://x.example', '/a', ttl: -5));
    }

    // ==========================================
    // RN-02: concurrency measured
    // ==========================================

    private function testConcurrencyIsMeasured(): void
    {
        // One server per source. A single php -S with several workers is not a reliable proof of
        // concurrency: measured with plain curl_multi (without this library), it lets one worker
        // accept two connections and serve them one after the other in 1 round out of 30 on a
        // workstation and in about 4 out of 10 inside a container (PHP 8.2 and 8.4 alike). With a
        // server of its own, each source is served as soon as its request arrives.
        $servers = [];
        try {
            $registry = new DataSourceRegistry();
            foreach ([1, 2, 3] as $n) {
                $servers[$n] = new LocalHttpServer(2);
                $servers[$n]->start();
                $servers[$n]->endpoint("slow{$n}", ['delay_ms' => 300, 'default' => ['v' => true]]);
                $registry->register("slow.{$n}", new HttpDataSource(
                    baseUrl: $servers[$n]->baseUrl(),
                    path: "/slow{$n}/value/{document}",
                    parse: static fn(int $status, array $headers, string $body): mixed => json_decode($body, true)['v'] ?? null,
                    timeout: 5.0,
                    cost: 1
                ));
            }
            $idle = static function () use (&$servers): void {
                foreach ($servers as $server) {
                    $server->waitIdle();
                }
            };
            $tree = Spec::external('slow.1', ['document' => 'cpf'], Spec::isTrue())
                ->and(Spec::external('slow.2', ['document' => 'cpf'], Spec::isTrue()))
                ->and(Spec::external('slow.3', ['document' => 'cpf'], Spec::isTrue()));
            $candidate = (object) ['cpf' => '111'];
            $resolver = new DataResolver($registry);

            // Best of three only against a load spike of the machine (CI runners), never against
            // the library: fetched one after the other, every attempt would take ~900 ms.
            $elapsed = INF;
            for ($attempt = 0; $attempt < 3 && $elapsed >= 600.0; $attempt++) {
                $idle();
                foreach ($servers as $server) {
                    $server->resetCalls();
                }
                $start = hrtime(true);
                $result = $resolver->evaluate($tree, $candidate, ResolutionPolicy::concurrent());
                $elapsed = min($elapsed, self::ms($start));
            }
            self::$timings['concurrent'] = $elapsed;
            $this->assertTrue($result->isSatisfied);
            $this->assertTrue($elapsed < 600.0, sprintf('concurrent took %.1f ms (expected < 600)', $elapsed));
            $this->assertTrue($elapsed >= 290.0, sprintf('concurrent took %.1f ms: the 300 ms delay was not applied', $elapsed));
            foreach ([1, 2, 3] as $n) {
                $this->assertEquals(1, $servers[$n]->count("slow{$n}"));
            }

            // Same cost: the tiered policy fetches them in one stage too
            $elapsed = INF;
            for ($attempt = 0; $attempt < 3 && $elapsed >= 600.0; $attempt++) {
                $idle();
                $start = hrtime(true);
                $data = $resolver->resolve($tree, $candidate, ResolutionPolicy::tiered());
                $elapsed = min($elapsed, self::ms($start));
            }
            self::$timings['tiered_same_cost'] = $elapsed;
            $this->assertEquals(1, $data->getStageCount());
            $this->assertTrue($elapsed < 600.0, sprintf('tiered (one cost) took %.1f ms (expected < 600)', $elapsed));

            $idle();
            $start = hrtime(true);
            $result = $resolver->evaluate($tree, $candidate, ResolutionPolicy::sequential());
            self::$timings['sequential'] = $elapsed = self::ms($start);
            $this->assertTrue($result->isSatisfied);
            $this->assertTrue($elapsed > 900.0, sprintf('sequential took %.1f ms (expected > 900)', $elapsed));

            DataSourceRegistry::setDefault($registry);
            $idle();
            $start = hrtime(true);
            $result = $tree->evaluate($candidate);
            self::$timings['outside_scope'] = $elapsed = self::ms($start);
            $this->assertTrue($result->isSatisfied);
            $this->assertTrue($elapsed > 900.0, sprintf('outside a scope took %.1f ms (expected > 900)', $elapsed));
            DataSourceRegistry::setDefault(null);
        } finally {
            foreach ($servers as $server) {
                $server->stop();
            }
        }
    }

    // ==========================================
    // D6 / RN-03: cost policy
    // ==========================================

    private function testTieredSkipsExpensiveSources(): void
    {
        $resolver = new DataResolver($this->creditRegistry());
        $poor = self::customers()['c2']; // balance 10

        // AND whose cheap operand fails: the fraud check and the bureau are never called
        $and = self::score(700)->and(self::clean())->and(self::balance(1000));
        $this->server->resetCalls();
        $data = $resolver->resolve($and, $poor, ResolutionPolicy::tiered());
        $result = $data->evaluate($and, $poor);
        $this->assertFalse($result->isSatisfied);
        $this->assertEquals(1, $this->server->count('bank'));
        $this->assertEquals(0, $this->server->count('fraud'), 'fraud check (cost 5) not called');
        $this->assertEquals(0, $this->server->count('serasa'), 'bureau (cost 10) not called');
        $this->assertCount(2, $data->getSkippedRequests());
        $this->assertEquals(1, $data->getFetchCount());
        $this->assertEquals(1, $data->getStageCount());
        $this->assertEquals(["Candidate failed to satisfy rule 'GreaterThanOrEqualSpecification'."], $result->getReasons());

        // Control: concurrent pays every source
        $this->server->resetCalls();
        $this->assertFalse($resolver->evaluate($and, $poor, ResolutionPolicy::concurrent())->isSatisfied);
        $this->assertEquals(1, $this->server->count('serasa'));
        $this->assertEquals(1, $this->server->count('fraud'));

        // OR whose cheap (left) operand approves: the expensive sources are never called
        $rich = self::customers()['c3']; // balance 60000
        $or = self::balance(50000)->or(self::score(700)->and(self::clean()));
        $this->server->resetCalls();
        $data = $resolver->resolve($or, $rich, ResolutionPolicy::tiered());
        $this->assertTrue($data->evaluate($or, $rich)->isSatisfied);
        $this->assertEquals(0, $this->server->count('serasa'));
        $this->assertEquals(0, $this->server->count('fraud'));
        $this->assertCount(2, $data->getSkippedRequests());

        // Documented limit: an OR approved by its RIGHT operand still fetches the left one, because an
        // error on the left operand aborts a disjunction (core OR semantics, unchanged)
        $orRight = self::score(700)->or(self::balance(50000));
        $this->server->resetCalls();
        $this->assertTrue($resolver->evaluate($orRight, $rich, ResolutionPolicy::tiered())->isSatisfied);
        $this->assertEquals(1, $this->server->count('serasa'));

        // A pure leaf decides before any fetch: no source is called at all
        $minor = Spec::property('age', Spec::greaterThanOrEqualTo(18))->and(self::balance(1000))->and(self::score(700));
        $this->server->resetCalls();
        $data = $resolver->resolve($minor, $poor, ResolutionPolicy::tiered());
        $this->assertEquals(0, $data->getFetchCount());
        $this->assertEquals(0, count($this->server->calls()));
        $this->assertFalse($data->evaluate($minor, $poor)->isSatisfied);
        $this->assertEquals(0, count($this->server->calls()));

        // Every stage fetched when nothing decides early (the verdict needs everything)
        $good = self::customers()['c1'];
        $this->server->resetCalls();
        $data = $resolver->resolve($and, $good, ResolutionPolicy::tiered());
        $this->assertEquals(3, $data->getStageCount());
        $this->assertCount(0, $data->getSkippedRequests());
        $this->assertTrue($data->evaluate($and, $good)->isSatisfied);
        $this->assertEquals(['bank', 'fraud', 'serasa'], array_column($this->server->calls(), 'endpoint'), 'cheapest first');
    }

    // ==========================================
    // RN-04: deduplication
    // ==========================================

    private function testDeduplication(): void
    {
        $resolver = new DataResolver($this->creditRegistry());
        $candidate = (object) ['cpf' => '111', 'customer' => (object) ['cpf' => '111']];
        // The same bureau request reached by four leaves (other inner specifications, other paths, NOT)
        $tree = self::score(700)
            ->and(Spec::external('serasa.score', ['document' => 'customer.cpf'], Spec::lessThan(900)))
            ->and(Spec::not(Spec::external('serasa.score', ['document' => 'cpf'], Spec::lessThan(100))))
            ->and(Spec::property('customer', self::score(600)));

        foreach (['concurrent', 'tiered', 'sequential'] as $policy) {
            $this->server->resetCalls();
            $data = $resolver->resolve($tree, $candidate, ResolutionPolicy::$policy());
            $this->assertTrue($data->evaluate($tree, $candidate)->isSatisfied, $policy);
            $this->assertEquals(1, $this->server->count('serasa'), "{$policy}: one call for four leaves");
            $this->assertEquals(1, $data->getFetchCount(), $policy);
            $this->assertCount(1, $data->getRequests(), $policy);
        }

        // Outside a scope there is no deduplication: each leaf fetches its own data
        DataSourceRegistry::setDefault($this->creditRegistry());
        $this->server->resetCalls();
        $this->assertTrue($tree->evaluate($candidate)->isSatisfied);
        $this->assertEquals(4, $this->server->count('serasa'));
        DataSourceRegistry::setDefault(null);
    }

    // ==========================================
    // RN-05: equivalence
    // ==========================================

    private function testEquivalenceAcrossPolicies(): void
    {
        $registry = $this->creditRegistry();
        $resolver = new DataResolver($registry);
        $adult = Spec::property('age', Spec::greaterThanOrEqualTo(18))->withCode('UNDERAGE');
        $down = Spec::external('fraud.down', ['document' => 'cpf'], Spec::isFalse());

        $trees = [
            'and' => self::score(700)->and(self::clean())->and(self::balance(1000)),
            'or' => self::balance(50000)->or(self::score(700)->and(self::clean())),
            'annotated' => self::score(700)->and(self::clean())->because('Credit denied.')->withCode('CREDIT_DENIED')->and($adult),
            'leaf annotations' => self::score(700)->because('Score below 700.')->withCode('SCORE_LOW')
                ->and(Spec::external('bank.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo(1000)->because('Low balance.')->withCode('LOW_BALANCE'))),
            'not' => Spec::not(Spec::external('serasa.score', ['document' => 'cpf'], Spec::lessThan(600))->or(Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isTrue()))),
            'nor' => new JointDenialSpecification(
                Spec::external('bank.balance', ['account' => 'account'], Spec::lessThan(100)),
                Spec::external('serasa.score', ['document' => 'cpf'], Spec::lessThan(600))
            ),
            'nested candidate' => Spec::property('customer', self::score(700))->and(self::balance(1000)),
            'error and' => self::balance(1000)->and($down),
            'error or' => self::score(700)->or($down)->and($adult),
            'unknown node' => (new R021Opaque(self::score(700)))->and(self::balance(1000)),
            'pure first' => $adult->and(self::score(700))->and(Spec::not(self::clean())->not()),
        ];

        $skippedCases = 0;
        $exactCases = 0;
        foreach ($trees as $name => $tree) {
            foreach (self::customers() as $id => $candidate) {
                $label = "{$name}/{$id}";
                $results = [];
                $data = [];
                foreach (['concurrent', 'tiered', 'sequential'] as $policy) {
                    $data[$policy] = $resolver->resolve($tree, $candidate, ResolutionPolicy::$policy());
                    $results[$policy] = $data[$policy]->evaluate($tree, $candidate);
                }
                DataSourceRegistry::setDefault($registry);
                $results['outside'] = $tree->evaluate($candidate);
                DataSourceRegistry::setDefault(null);

                // The verdict is the same everywhere
                foreach (['tiered', 'sequential', 'outside'] as $mode) {
                    $this->assertEquals($results['concurrent']->isSatisfied, $results[$mode]->isSatisfied, "{$label}: verdict of {$mode}");
                }
                // Everything fetched: same failures (messages and codes) and same error state
                foreach (['sequential', 'outside'] as $mode) {
                    $this->assertEquals(self::signature($results['concurrent']), self::signature($results[$mode]), "{$label}: failures of {$mode}");
                    $this->assertEquals($results['concurrent']->isError, $results[$mode]->isError, "{$label}: error state of {$mode}");
                }
                // Tiered: identical when nothing was skipped; otherwise the skipped branches report nothing,
                // so its failures are a subset of the complete ones (never an invented failure)
                if ($data['tiered']->getSkippedRequests() === []) {
                    $exactCases++;
                    $this->assertEquals(self::signature($results['concurrent']), self::signature($results['tiered']), "{$label}: failures of tiered");
                    $this->assertEquals($results['concurrent']->isError, $results['tiered']->isError, "{$label}: error state of tiered");
                } else {
                    $skippedCases++;
                    $this->assertTrue(self::isSubMultiset(self::signature($results['tiered']), self::signature($results['concurrent'])), "{$label}: tiered failures are a subset");
                    foreach ($results['tiered']->failures as $failure) {
                        $this->assertFalse(isset($failure->metadata['skipped']), "{$label}: a skipped leaf never surfaces");
                    }
                }
            }
        }
        $this->assertTrue($skippedCases > 0 && $exactCases > 0, "both kinds of case exercised ({$skippedCases} skipped, {$exactCases} exact)");
    }

    /**
     * @return list<string>
     */
    private static function signature(SpecificationResult $result): array
    {
        return array_map(static fn($f): string => $f->message . '|' . ($f->code ?? ''), $result->failures);
    }

    /**
     * @param list<string> $small
     * @param list<string> $large
     */
    private static function isSubMultiset(array $small, array $large): bool
    {
        foreach ($small as $item) {
            $index = array_search($item, $large, true);
            if ($index === false) {
                return false;
            }
            unset($large[$index]);
        }

        return true;
    }

    // ==========================================
    // RN-06: security, against the server
    // ==========================================

    private function testSecurity(): void
    {
        $registry = (new DataSourceRegistry())
            ->register('echo.doc', $this->source('/echo/doc/{document}', 'ok'))
            ->register('echo.query', $this->source('/echo/q?doc={document}&kind=fixed', 'ok'))
            ->register('fraud.down', $this->source('/down/{document}', 'x'))
            ->register('jump', $this->source('/redirect/{document}', 'x'));
        $resolver = new DataResolver($registry);
        $host = '127.0.0.1:' . $this->server->port();

        $hostile = ['../admin', '..', '/etc/passwd', 'http://evil.example/x', '//evil.example/y', 'a?b=c#frag', '%2e%2e%2f', "a\r\nX-Injected: 1", 'x/../../leak/z'];
        foreach ($hostile as $value) {
            $this->server->resetCalls();
            $tree = Spec::external('echo.doc', ['document' => 'cpf'], Spec::isTrue())
                ->and(Spec::external('echo.query', ['document' => 'cpf'], Spec::isTrue()));
            $this->assertTrue($resolver->evaluate($tree, (object) ['cpf' => $value], ResolutionPolicy::concurrent())->isSatisfied, "served for {$value}");
            $calls = $this->server->calls();
            $this->assertCount(2, $calls, "two calls for {$value}");
            foreach ($calls as $call) {
                $this->assertEquals('echo', $call['endpoint'], "endpoint kept for {$value}");
                $this->assertEquals($host, $call['host'], "host kept for {$value}");
            }
            $byPath = array_column($calls, 'uri');
            sort($byPath);
            [$pathCall, $queryCall] = str_starts_with($byPath[0], '/echo/doc/') ? $byPath : array_reverse($byPath);
            $rest = substr((string) parse_url($pathCall, PHP_URL_PATH), strlen('/echo/doc/'));
            $this->assertTrue(str_starts_with($pathCall, '/echo/doc/') && !str_contains($rest, '/'), "one path segment for {$value}");
            $this->assertEquals(null, parse_url($pathCall, PHP_URL_QUERY), "no query added for {$value}");
            $this->assertEquals($value, rawurldecode($rest), "value intact for {$value}");
            $this->assertEquals('/echo/q', parse_url($queryCall, PHP_URL_PATH));
            parse_str((string) parse_url($queryCall, PHP_URL_QUERY), $query);
            $this->assertEquals(['doc' => $value, 'kind' => 'fixed'], $query, "query kept for {$value}");
        }
        $this->assertEquals(0, $this->server->count('leak'));

        // The body of an error response never reaches the failure nor the exception message
        $down = Spec::external('fraud.down', ['document' => 'cpf'], Spec::isFalse());
        $inScope = $resolver->evaluate($down, (object) ['cpf' => '1']);
        DataSourceRegistry::setDefault($registry);
        $outside = $down->evaluate((object) ['cpf' => '1']);
        DataSourceRegistry::setDefault(null);
        foreach ([$inScope, $outside] as $result) {
            $this->assertTrue($result->isError);
            $this->assertEquals([SpecificationResult::EVALUATION_ERROR_MESSAGE], $result->getReasons());
            $this->assertFalse(str_contains(var_export($result->failures, true), 'SECRET'));
            $this->assertFalse(str_contains((string) $result, 'SECRET'));
            $this->assertInstanceOf(DataFetchException::class, $result->exception);
            $this->assertEquals(500, $result->exception->getStatusCode());
            $this->assertEquals('fraud.down', $result->exception->getSourceName());
            for ($e = $result->exception; $e !== null; $e = $e->getPrevious()) {
                $this->assertFalse(str_contains($e->getMessage(), 'SECRET'), 'no body in any exception message');
                $this->assertFalse(str_contains($e->getMessage(), '127.0.0.1'), 'no URL in any exception message');
            }
        }

        // Redirects are not followed: the Location host/path is never contacted
        $this->server->resetCalls();
        $result = $resolver->evaluate(Spec::external('jump', ['document' => 'cpf'], Spec::alwaysTrue()), (object) ['cpf' => '1']);
        $this->assertTrue($result->isError);
        $this->assertEquals(302, $result->exception->getStatusCode());
        $this->assertEquals(1, $this->server->count('redirect'));
        $this->assertEquals(0, $this->server->count('leak'), 'redirect not followed');

        // Headers belong to the source: the authentication header is sent as configured
        $this->server->resetCalls();
        $auth = (new DataSourceRegistry())->register('auth', new HttpDataSource(
            baseUrl: $this->server->baseUrl(),
            path: '/echo/auth/{document}',
            headers: ['Authorization' => 'Bearer source-owned'],
        ));
        $this->assertTrue((new DataResolver($auth))->evaluate(Spec::external('auth', ['document' => 'cpf'], Spec::alwaysTrue()), (object) ['cpf' => '1'])->isSatisfied);
        $this->assertEquals('Bearer source-owned', $this->server->calls('echo')[0]['authorization']);
    }

    // ==========================================
    // D8: errors and time limits
    // ==========================================

    private function testErrorsAndTimeLimits(): void
    {
        // Time limit of a source
        $registry = (new DataSourceRegistry())
            ->register('slow', $this->source('/veryslow/a/{document}', 'v', 1, timeout: 0.2));
        $this->server->waitIdle();
        $start = hrtime(true);
        $result = (new DataResolver($registry))->evaluate(Spec::external('slow', ['document' => 'cpf'], Spec::isTrue()), (object) ['cpf' => '1']);
        $elapsed = self::ms($start);
        self::$timings['source_timeout_0.2s'] = $elapsed;
        $this->assertTrue($result->isError);
        $this->assertTrue($elapsed < 800.0, sprintf('source time limit honoured (%.1f ms)', $elapsed));
        $this->assertTrue(str_contains($result->exception->getMessage(), 'its time limit'));
        $this->assertEquals(null, $result->exception->getStatusCode());

        // Time limit of the whole resolution: calls still running are aborted, the others not made
        $registry = new DataSourceRegistry();
        foreach (['a', 'b', 'c'] as $n) {
            $registry->register("vs.{$n}", $this->source("/veryslow/{$n}/{document}", 'v', 1));
        }
        $tree = Spec::external('vs.a', ['document' => 'cpf'], Spec::isTrue())
            ->and(Spec::external('vs.b', ['document' => 'cpf'], Spec::isTrue()))
            ->and(Spec::external('vs.c', ['document' => 'cpf'], Spec::isTrue()));
        $this->server->waitIdle();
        $start = hrtime(true);
        $data = (new DataResolver($registry))->resolve($tree, (object) ['cpf' => '1'], ResolutionPolicy::concurrent(timeout: 0.3));
        $elapsed = self::ms($start);
        self::$timings['resolution_timeout_0.3s'] = $elapsed;
        $this->assertTrue($elapsed < 800.0, sprintf('resolution time limit honoured (%.1f ms)', $elapsed));
        $this->assertCount(3, $data->getFailedRequests());
        foreach ($data->getFailedRequests() as $request) {
            $this->assertTrue(str_contains($data->getError($request)->getMessage(), 'time limit of the resolution'));
        }
        $result = $data->evaluate($tree, (object) ['cpf' => '1']);
        $this->assertTrue($result->isError);
        $this->assertFalse($result->isSatisfied);

        $this->server->resetCalls();
        $this->server->waitIdle();
        $start = hrtime(true);
        $result = (new DataResolver($registry))->evaluate($tree, (object) ['cpf' => '2'], ResolutionPolicy::sequential(timeout: 0.3));
        $elapsed = self::ms($start);
        $this->assertTrue($elapsed < 800.0);
        $this->assertTrue($result->isError);
        // Count only this phase's calls (document 2): an aborted request of the concurrent phase above
        // (document 1) can still sit in the accept queue of a busy worker of php -S, invisible to
        // waitIdle(), and be logged after the reset.
        // The count is the eventual one: the call aborted at the limit is logged by its worker at the
        // start of the script, and a stalled worker (a shared CI runner) can log it after evaluate()
        // returned (2.5.2: once, PHP 8.3 leg, "0 calls" where 1 was started). Wait for the first call of
        // this phase to be logged (bounded) and for the server to be idle, then count; the second call
        // must never appear.
        $phaseCalls = fn(): array => array_filter($this->server->calls('veryslow'), static fn(array $call): bool => str_ends_with((string) parse_url($call['uri'], PHP_URL_PATH), '/2'));
        $logDeadline = microtime(true) + 2.0;
        while ($phaseCalls() === [] && microtime(true) < $logDeadline) {
            usleep(10_000);
        }
        $this->server->waitIdle();
        $phase = $phaseCalls();
        $this->assertEquals(1, count($phase), sprintf(
            'after the limit no call is started (%d call(s) of this phase logged, evaluate() took %.1f ms, error: %s)',
            count($phase),
            $elapsed,
            $result->exception?->getMessage() ?? 'none'
        ));

        // Parser failure, callable failure, unknown source, missing parameter: evaluation errors
        $registry = (new DataSourceRegistry())
            ->register('bad.json', new HttpDataSource($this->server->baseUrl(), '/badjson/{document}'))
            ->register('throws', new CallableDataSource(function (array $p): never {
                throw new RuntimeException('internal detail 42');
            }))
            ->register('needs.two', new HttpDataSource($this->server->baseUrl(), '/echo/{document}/{other}'));
        $resolver = new DataResolver($registry);
        $candidate = (object) ['cpf' => '1'];

        $parse = $resolver->evaluate(Spec::external('bad.json', ['document' => 'cpf'], Spec::alwaysTrue()), $candidate);
        $this->assertTrue($parse->isError);
        $this->assertTrue(str_contains($parse->exception->getMessage(), 'could not be parsed (JsonException)'));
        $this->assertInstanceOf(\JsonException::class, $parse->exception->getPrevious());

        $throws = $resolver->evaluate(Spec::external('throws', [], Spec::alwaysTrue())->withCode('LIMIT_CHECK'), $candidate);
        $this->assertTrue($throws->isError);
        $this->assertEquals(['LIMIT_CHECK'], $throws->getCodes(), 'the code of the leaf is kept on the error');
        $this->assertEquals([SpecificationResult::EVALUATION_ERROR_MESSAGE], $throws->getReasons());
        $this->assertFalse(str_contains($throws->exception->getMessage(), 'internal detail'));
        $this->assertEquals('internal detail 42', $throws->exception->getPrevious()->getMessage(), 'the original stays for the log');

        $this->assertInstanceOf(DataSourceNotFoundException::class, $resolver->evaluate(Spec::external('nowhere', [], Spec::alwaysTrue()), $candidate)->exception);
        $missing = $resolver->evaluate(Spec::external('needs.two', ['document' => 'cpf'], Spec::alwaysTrue()), $candidate);
        $this->assertTrue($missing->isError);
        $this->assertInstanceOf(DataResolutionException::class, $missing->exception);

        // A failed fetch never decides the verdict on its own: the next stage is still fetched
        $registry = $this->creditRegistry()->register('cheap.down', $this->source('/down/{document}', 'x', 0));
        $this->server->resetCalls();
        $tree = Spec::external('cheap.down', ['document' => 'cpf'], Spec::isTrue())->and(self::score(700));
        $result = (new DataResolver($registry))->evaluate($tree, self::customers()['c1']);
        $this->assertEquals(1, $this->server->count('serasa'), 'the error is unknown, so the bureau is still asked');
        $this->assertTrue($result->isError);
        $this->assertTrue((new DataResolver($registry))->evaluate(Spec::not(Spec::external('cheap.down', ['document' => 'cpf'], Spec::isTrue())), self::customers()['c1'])->isError, 'NOT keeps the error');
    }

    // ==========================================
    // D7: three-valued logic and unknown nodes
    // ==========================================

    private function testThreeValuedLogicAndUnknownNodes(): void
    {
        $resolver = new DataResolver($this->creditRegistry());
        $poor = self::customers()['c2'];

        // NOT and NOR are known: a skipped leaf under a negation never adds a failure
        $tree = self::balance(1000)->and(Spec::not(Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isTrue())))
            ->and(new JointDenialSpecification(self::score(900), Spec::alwaysFalse()));
        $this->server->resetCalls();
        $data = $resolver->resolve($tree, $poor, ResolutionPolicy::tiered());
        $result = $data->evaluate($tree, $poor);
        $this->assertFalse($result->isSatisfied);
        $this->assertCount(2, $data->getSkippedRequests());
        $this->assertEquals(["Candidate failed to satisfy rule 'GreaterThanOrEqualSpecification'."], $result->getReasons());
        $this->assertEquals(0, $this->server->count('fraud') + $this->server->count('serasa'));

        // NOR decided by its cheap operand alone
        $nor = new JointDenialSpecification(self::balance(5), self::score(700));
        $this->server->resetCalls();
        $this->assertFalse($resolver->evaluate($nor, $poor, ResolutionPolicy::tiered())->isSatisfied);
        $this->assertEquals(0, $this->server->count('serasa'));

        // Unknown node: nothing is guessed; it is evaluated for real (its hidden leaf fetched on demand)
        $opaque = (new R021Opaque(self::score(700)))->and(self::balance(1000));
        $good = self::customers()['c1'];
        $this->server->resetCalls();
        $this->assertTrue($resolver->evaluate($opaque, $good, ResolutionPolicy::tiered())->isSatisfied);
        $this->assertEquals(1, $this->server->count('serasa'));
        $this->server->resetCalls();
        $result = $resolver->evaluate($opaque, $poor, ResolutionPolicy::tiered());
        $this->assertFalse($result->isSatisfied);
        $this->assertEquals(1, $this->server->count('serasa'), 'the unknown node is evaluated, so its data is fetched');

        // A rule binding (a node the walk sees through) gets its data prefetched with the rest
        $binding = new \Antevemus\ASpecification\Engine\RuleBoundSpecification(
            self::score(700),
            new \Antevemus\ASpecification\Engine\RuleDefinition(code: 'R-1', name: 'Score', ruleType: 'score', scope: 'credit')
        );
        $data = $resolver->resolve($binding->and(self::clean()), $good, ResolutionPolicy::concurrent());
        $this->assertEquals(1, $data->getStageCount());
        $this->assertEquals(2, $data->getFetchCount());
        $this->assertTrue($data->evaluate($binding->and(self::clean()), $good)->isSatisfied);
    }

    // ==========================================
    // D9: cache between resolutions
    // ==========================================

    private function testCache(): void
    {
        $now = 1000.0;
        $cache = new InMemoryDataCache(function () use (&$now): float {
            return $now;
        });
        $registry = (new DataSourceRegistry())
            ->register('cached', $this->source('/serasa/score/{document}', 'score', 10, ttl: 60))
            ->register('uncached', $this->source('/fraud/check/{document}', 'flagged', 1))
            ->register('down.cached', $this->source('/down/{document}', 'x', 1, ttl: 60));
        $resolver = new DataResolver($registry, $cache);
        $tree = Spec::external('cached', ['document' => 'cpf'], Spec::greaterThan(0))
            ->and(Spec::external('uncached', ['document' => 'cpf'], Spec::isFalse()));
        $candidate = self::customers()['c1'];

        $this->server->resetCalls();
        $this->assertTrue($resolver->evaluate($tree, $candidate, ResolutionPolicy::tiered())->isSatisfied);
        $data = $resolver->resolve($tree, $candidate, ResolutionPolicy::concurrent());
        $this->assertEquals(1, $data->getCacheHitCount());
        $this->assertEquals(1, $this->server->count('serasa'), 'second resolution answered by the cache');
        $this->assertEquals(2, $this->server->count('fraud'), 'a source without TTL is never cached');
        $this->assertCount(1, $cache);

        $now += 61;
        $resolver->resolve($tree, $candidate, ResolutionPolicy::concurrent());
        $this->assertEquals(2, $this->server->count('serasa'), 'expired after the TTL of the source');

        $down = Spec::external('down.cached', ['document' => 'cpf'], Spec::alwaysTrue());
        $resolver->resolve($down, $candidate, ResolutionPolicy::tiered());
        $resolver->resolve($down, $candidate, ResolutionPolicy::tiered());
        $this->assertEquals(2, $this->server->count('down'), 'failures are never cached');

        $cache->set('k', 'v', 0);
        $this->assertFalse($cache->has('k'));
        $cache->set('k', null, 10);
        $this->assertTrue($cache->has('k'));
        $this->assertEquals(null, $cache->get('k'));
        $cache->clear();
        $this->assertCount(0, $cache);
    }

    // ==========================================
    // D10: visitors
    // ==========================================

    private function testVisitorsRefuseTheLeaf(): void
    {
        $rule = Spec::property('age', Spec::greaterThanOrEqualTo(18))->and(self::score(700));

        $e = $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $rule->toSql('pgsql'));
        $this->assertTrue(str_contains($e->getMessage(), "external data (source 'serasa.score')"));
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::property('customer', self::score(700))->toSql('mysql'));
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::not(self::score(700))->toSql('ansi'));

        $e = $this->assertThrows(NonTranslatableCriteriaException::class, fn() => $rule->toCriteria());
        $this->assertTrue(str_contains($e->getMessage(), "External data (source 'serasa.score')"));
        $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::not(self::score(700))->toCriteria());
        $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::property('customer', Spec::not(self::score(700)))->toCriteria());

        $e = $this->assertThrows(NonTranslatableLinqException::class, fn() => ALinqSpecificationVisitor::createPredicate($rule));
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertTrue(str_contains($e->getMessage(), "external data (source 'serasa.score')"));
        $this->assertThrows(NonTranslatableLinqException::class, fn() => ALinqSpecificationVisitor::createPredicate(Spec::not(self::score(700))));

        // The rest of the library is untouched: the same rule without the leaf still compiles
        $this->assertTrue(Spec::property('age', Spec::greaterThanOrEqualTo(18))->toSql('pgsql')->sql !== '');
    }

    // ==========================================
    // D12: ext-curl is a suggestion
    // ==========================================

    private function testHttpSourceNeedsCurl(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $this->assertTrue(isset($composer['suggest']['ext-curl']), 'ext-curl is suggested');
        $this->assertFalse(isset($composer['require']['ext-curl']), 'ext-curl is not required');
        $this->assertTrue(HttpDataSource::isSupported());

        if (!function_exists('proc_open')) {
            return;
        }
        // Redirected by /bin/sh, not by 'file' descriptors: see LocalHttpServer (2.6.0, Infection's file:// interceptor)
        $process = proc_open(
            ['/bin/sh', '-c', 'exec "$0" "$@" </dev/null 2>/dev/null', PHP_BINARY, '-n', __DIR__ . '/../Support/resolution_no_curl_probe.php'],
            [1 => ['pipe', 'w']],
            $pipes
        );
        $this->assertTrue(is_resource($process));
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        $report = json_decode($output, true);
        $this->assertTrue(is_array($report), 'probe ran: ' . $output);
        $this->assertTrue($report['callable'], 'a callable source works without curl');
        $this->assertTrue($report['deferred'] ?? false, 'a deferred source registers and resolves through its transport without curl (2.6.0)');
        if ($report['curl'] === false) {
            $this->assertEquals(DataResolutionException::class, $report['http'], 'HTTP source refused at registration without curl');
            $this->assertTrue(str_contains($report['message'], 'ext-curl'));
        } else {
            $this->assertEquals('registered', $report['http']);
        }
    }

    // ==========================================
    // README example 16
    // ==========================================

    private function testReadme16BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = self::readmeBlocks($root . '/docs/en/resolution/concurrent.md');
        $portuguese = self::readmeBlocks($root . '/docs/pt-BR/resolution/concurrent.md');
        $this->assertCount(2, $english, 'two PHP blocks in example 16 (EN)');
        $this->assertCount(2, $portuguese, 'two PHP blocks in example 16 (pt-BR)');
        foreach ($english as $i => $block) {
            $this->assertEquals(self::codeTokens($block), self::codeTokens($portuguese[$i]), "block {$i} identical except comments");
        }
    }

    /**
     * @return list<string>
     */
    private static function readmeBlocks(string $file): array
    {
        $text = (string) file_get_contents($file);
        $start = strpos($text, "\n### 16. ");
        if ($start === false) {
            return [];
        }
        // Up to the next example (1.11.0 added examples 17 and 18 after this one) or section
        $ends = array_filter([strpos($text, "\n### ", $start + 1), strpos($text, "\n## ", $start + 1)], static fn($p): bool => $p !== false);
        $end = $ends === [] ? false : min($ends);
        $section = substr($text, $start, ($end === false ? strlen($text) : $end) - $start);
        preg_match_all('/```php\n(.*?)```/s', $section, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private static function codeTokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all('<?php ' . $code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) {
                    continue;
                }
                $tokens[] = $token[1];
            } else {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Transcription of the two blocks of README example 16 (identical in EN and pt-BR, except
     * comments). The only change: the three base URLs point to the local server.
     */
    private function testReadme16RunsAsWritten(string $language): void
    {
        DataSourceRegistry::setDefault(null);
        $this->server->resetCalls();

        // --- block 1 ---
        $serasaUrl = $this->server->baseUrl();   // README: 'https://bureau.example.com'
        $fraudUrl  = $this->server->baseUrl();   // README: 'https://fraud.example.com'
        $bankUrl   = $this->server->baseUrl();   // README: 'https://core-banking.example.com'

        $registry = DataSourceRegistry::default()
            ->register('serasa.score', new HttpDataSource(
                baseUrl: $serasaUrl,
                path: '/serasa/score/{document}',
                parse: fn(int $status, array $headers, string $body): int => json_decode($body, true)['score'],
                headers: ['Authorization' => 'Bearer bureau-token'],
                timeout: 2.0,
                cost: 10,
            ))
            ->register('fraud.flag', new HttpDataSource(
                baseUrl: $fraudUrl,
                path: '/fraud/check/{document}',
                parse: fn(int $status, array $headers, string $body): bool => json_decode($body, true)['flagged'],
                cost: 5,
            ))
            ->register('bank.balance', new HttpDataSource(
                baseUrl: $bankUrl,
                path: '/bank/balance/{account}',
                parse: fn(int $status, array $headers, string $body): float => json_decode($body, true)['balance'],
                cost: 1,
            ));

        $credit = Spec::external('bank.balance', ['account' => 'account.number'],
                Spec::greaterThanOrEqualTo(1000)->because('Balance below 1,000.')->withCode('LOW_BALANCE'))
            ->and(Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isFalse())->withCode('FRAUD_SUSPECTED'))
            ->and(Spec::external('serasa.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))
                ->because('Credit score below 700.')->withCode('SCORE_LOW'));

        $customer = (object) ['cpf' => '111.444.777-35', 'account' => (object) ['number' => 'A-1']];
        $resolver = new DataResolver($registry);

        $this->assertTrue($resolver->evaluate($credit, $customer, ResolutionPolicy::concurrent())->isSatisfied);

        $poor = (object) ['cpf' => '222.333.444-05', 'account' => (object) ['number' => 'A-2']];
        $data = $resolver->resolve($credit, $poor, ResolutionPolicy::tiered());
        $this->assertEquals(2, count($data->getSkippedRequests()));
        $this->assertEquals(['LOW_BALANCE'], $data->evaluate($credit, $poor)->getCodes());

        $this->assertEquals(['LOW_BALANCE', 'SCORE_LOW'], $resolver->evaluate($credit, $poor, ResolutionPolicy::concurrent())->getCodes());
        $this->assertFalse($resolver->evaluate($credit, $poor, ResolutionPolicy::sequential())->isSatisfied);

        // Prose: the authentication header belongs to the source; the tiered run on $poor called one source
        foreach ($this->server->calls('serasa') as $call) {
            $this->assertEquals('Bearer bureau-token', $call['authorization']);
        }
        $this->assertEquals(1, $data->getFetchCount());

        // --- block 2 ---
        $this->assertTrue($credit->evaluate($customer)->isSatisfied);

        $limitsUrl = $this->server->baseUrl();   // README: 'https://limits.example.com'
        $registry->register('credit.limit', new HttpDataSource(baseUrl: $limitsUrl, path: '/limits/{document}', cost: 2));
        $limit = Spec::external('credit.limit', ['document' => 'cpf'], Spec::greaterThan(0));

        $result = $resolver->evaluate($limit, $customer);
        $this->assertTrue($result->isError);
        $this->assertEquals('The rule could not be evaluated.', $result->failures[0]->message);
        $this->assertEquals(503, $result->exception->getStatusCode());
        $this->assertFalse(str_contains($result->exception->getMessage(), 'SECRET'));

        $caught = false;
        try {
            $credit->toSql('pgsql');
        } catch (NonTranslatableSpecificationException $e) {
            $caught = true;
        }
        $this->assertTrue($caught, "README 16 ({$language}): the SQL visitor refuses the leaf");

        DataSourceRegistry::setDefault(null);
    }

    // ==========================================
    // 2.6.0 (forward 045): deferred sources and stage transports
    // ==========================================

    /** A leaf whose request carries the CPF of the candidate as `document`. */
    private static function onCpf(string $source, ISpecification $inner): ExternalDataSpecification
    {
        return Spec::external($source, ['document' => 'cpf'], $inner);
    }

    /** The canonical key of the request of onCpf() for a CPF. */
    private static function cpfKey(string $source, string $cpf): string
    {
        return (new DataRequest($source, ['document' => $cpf]))->getKey();
    }

    /**
     * Inside one stage: the HTTP batch, then one fetchAll() per transport, then the other sources, whatever
     * the order of the leaves in the tree; the deferred keys reach the transport in the order of the tree.
     */
    private function testDeferredStageRunsHttpThenTransportsThenOthers(): void
    {
        $log = new ArrayObject();
        $transport = new RecordingStageTransport('t1', $log);
        $a = new DeferredProbeSource($transport, static fn(array $p): int => 10);
        $b = new DeferredProbeSource($transport, static fn(array $p): int => 20);
        $c = new DeferredProbeSource($transport, static fn(array $p): int => 30);
        $registry = (new DataSourceRegistry())
            ->register('d.a', $a)
            ->register('d.b', $b)
            ->register('d.c', $c)
            ->register('cb', new CallableDataSource(static function (array $p) use ($log): bool {
                $log->append('callable');
                return true;
            }))
            ->register('http', new HttpDataSource(
                baseUrl: $this->server->baseUrl(),
                path: '/serasa/score/{document}',
                parse: static function (int $status, array $headers, string $body) use ($log): int {
                    $log->append('http');
                    return json_decode($body, true)['score'];
                }
            ));
        $tree = self::onCpf('cb', Spec::isTrue())
            ->and(self::onCpf('d.a', Spec::equalTo(10)))
            ->and(self::onCpf('http', Spec::greaterThanOrEqualTo(700)))
            ->and(self::onCpf('d.b', Spec::equalTo(20)))
            ->and(self::onCpf('d.c', Spec::equalTo(30)));
        $resolver = new DataResolver($registry);

        $data = $resolver->resolve($tree, self::customers()['c1'], ResolutionPolicy::concurrent());
        $this->assertEquals(['http', 'transport:t1', 'callable'], $log->getArrayCopy(), 'HTTP batch, then the transport, then the callable source');
        $this->assertEquals(1, $transport->callCount(), 'the three deferred requests in one fetchAll()');
        $this->assertEquals(['d.a', 'd.b', 'd.c'], $transport->calls[0]['sources'], 'in the order of the tree');
        $this->assertEquals([self::cpfKey('d.a', '111'), self::cpfKey('d.b', '111'), self::cpfKey('d.c', '111')], $transport->calls[0]['keys']);
        $this->assertEquals(1, $data->getStageCount());
        $this->assertEquals(5, $data->getFetchCount(), '3 deferred + 1 callable + 1 HTTP');
        $this->assertTrue($data->evaluate($tree, self::customers()['c1'])->isSatisfied);
        $this->assertEquals(0, count($a->unitaryFetches) + count($b->unitaryFetches) + count($c->unitaryFetches), 'the resolver never takes the unitary fetch() of a deferred source');

        $result = $resolver->evaluate($tree, self::customers()['c2'], ResolutionPolicy::concurrent());
        $this->assertFalse($result->isSatisfied, 'score 500 of c2');
        $this->assertFalse($result->isError);
    }

    /**
     * One fetchAll() per transport instance, transports in the order their first request was met; sources of
     * one transport registered under different names (or the same source under two names) share one call.
     */
    private function testDeferredGroupsByTransportInstance(): void
    {
        $log = new ArrayObject();
        $t1 = new RecordingStageTransport('t1', $log);
        $t2 = new RecordingStageTransport('t2', $log);
        $shared = new DeferredProbeSource($t1, static fn(array $p): bool => true);
        $registry = (new DataSourceRegistry())
            ->register('x.1', new DeferredProbeSource($t1, static fn(array $p): bool => true))
            ->register('y.1', new DeferredProbeSource($t2, static fn(array $p): bool => true))
            ->register('x.2', $shared)
            ->register('x.3', $shared);
        $resolver = new DataResolver($registry);
        $tree = self::onCpf('y.1', Spec::isTrue())
            ->and(self::onCpf('x.1', Spec::isTrue()))
            ->and(self::onCpf('x.2', Spec::isTrue()))
            ->and(self::onCpf('x.3', Spec::isTrue()));

        $data = $resolver->resolve($tree, self::customers()['c1']);
        $this->assertEquals(['transport:t2', 'transport:t1'], $log->getArrayCopy(), 'first-seen order: y.1 comes first in the tree');
        $this->assertEquals([['y.1']], array_map(static fn(array $call): array => $call['sources'], $t2->calls));
        $this->assertEquals([['x.1', 'x.2', 'x.3']], array_map(static fn(array $call): array => $call['sources'], $t1->calls), 'grouped by instance, the same source under two names included');
        $this->assertEquals(1, $data->getStageCount());
        $this->assertEquals(4, $data->getFetchCount());
        $this->assertTrue($data->evaluate($tree, self::customers()['c1'])->isSatisfied);

        $log->exchangeArray([]);
        $resolver->resolve(self::onCpf('x.1', Spec::isTrue())->and(self::onCpf('y.1', Spec::isTrue())), self::customers()['c1']);
        $this->assertEquals(['transport:t1', 'transport:t2'], $log->getArrayCopy(), 'first-seen order: x.1 comes first now');
    }

    /** Tiered: one stage per cost, cheapest first; a decided verdict skips the expensive deferred source. */
    private function testDeferredTieredFetchesByCost(): void
    {
        $transport = new RecordingStageTransport();
        $registry = (new DataSourceRegistry())
            ->register('pricey', new DeferredProbeSource($transport, static fn(array $p): bool => true, cost: 10))
            ->register('cheap', new DeferredProbeSource($transport, static fn(array $p): bool => $p['document'] === '111', cost: 1));
        $resolver = new DataResolver($registry);
        $tree = self::onCpf('pricey', Spec::isTrue())->and(self::onCpf('cheap', Spec::isTrue()));

        $data = $resolver->resolve($tree, self::customers()['c2'], ResolutionPolicy::tiered());
        $this->assertEquals([['cheap']], array_map(static fn(array $call): array => $call['sources'], $transport->calls), 'only the cheap stage reached the transport');
        $this->assertEquals(['pricey'], array_map(static fn(DataRequest $r): string => $r->source, $data->getSkippedRequests()));
        $this->assertEquals('The request to data source "pricey" is not resolved.', $this->assertThrows(DataResolutionException::class, static fn() => $data->get(new DataRequest('pricey', ['document' => '222'])))->getMessage(), 'get() of a skipped request');
        $this->assertThrows(DataResolutionException::class, static fn() => $data->get(new DataRequest('never.asked')), 'get() of a request this resolution does not know');
        $this->assertEquals(1, $data->getStageCount());
        $result = $data->evaluate($tree, self::customers()['c2']);
        $this->assertFalse($result->isSatisfied);
        $this->assertFalse($result->isError, 'a skipped branch reports no failure');

        $data = $resolver->resolve($tree, self::customers()['c1'], ResolutionPolicy::tiered());
        $this->assertEquals([['cheap'], ['cheap'], ['pricey']], array_map(static fn(array $call): array => $call['sources'], $transport->calls), 'two stages, cheapest first');
        $this->assertEquals(2, $data->getStageCount());
        $this->assertEquals([], $data->getSkippedRequests());
        $this->assertTrue($data->evaluate($tree, self::customers()['c1'])->isSatisfied);
    }

    /**
     * A Throwable answered by the transport fails that request only: the evaluation error of its leaf, never
     * cached; a value with a TTL is cached and answered without the transport the next time.
     */
    private function testDeferredResultsFailuresAndCache(): void
    {
        $now = 1000.0;
        $cache = new InMemoryDataCache(function () use (&$now): float {
            return $now;
        });
        $boom = new RuntimeException('bureau offline');
        $transport = (new RecordingStageTransport())->script('d.err', $boom);
        $registry = (new DataSourceRegistry())
            ->register('d.err', new DeferredProbeSource($transport, static fn(array $p): bool => true, ttl: 60))
            ->register('d.ttl', new DeferredProbeSource($transport, static fn(array $p): int => 42, ttl: 60))
            ->register('d.nottl', new DeferredProbeSource($transport, static fn(array $p): int => 7));
        $resolver = new DataResolver($registry, $cache);
        $candidate = self::customers()['c1'];

        // A Throwable result
        $leaf = self::onCpf('d.err', Spec::isTrue())->withCode('BUREAU');
        $data = $resolver->resolve($leaf, $candidate);
        $result = $data->evaluate($leaf, $candidate);
        $this->assertTrue($result->isError);
        $this->assertTrue($result->exception === $boom, 'the Throwable answered is the exception of the result');
        $this->assertEquals(true, $result->failures[0]->metadata['evaluation_error'] ?? null);
        $this->assertEquals([SpecificationResult::EVALUATION_ERROR_MESSAGE], $result->getReasons());
        $this->assertEquals(['BUREAU'], $result->getCodes());
        $this->assertEquals(['d.err'], array_map(static fn(DataRequest $r): string => $r->source, $data->getFailedRequests()));
        $this->assertTrue($data->getError(new DataRequest('d.err', ['document' => '111'])) === $boom);
        $this->assertCount(0, $cache, 'a failure is never cached');
        $resolver->resolve($leaf, $candidate);
        $this->assertEquals(2, $transport->callCount(), 'the next resolution asks the transport again');

        // A value with a TTL: cached; the next resolution is a cache hit and never reaches the transport
        $tree = self::onCpf('d.ttl', Spec::equalTo(42))->and(self::onCpf('d.nottl', Spec::equalTo(7)));
        $data = $resolver->resolve($tree, $candidate);
        $this->assertEquals([['d.ttl', 'd.nottl']], array_map(static fn(array $call): array => $call['sources'], array_slice($transport->calls, 2)));
        $this->assertCount(1, $cache, 'only the source with a TTL');
        $data = $resolver->resolve($tree, $candidate);
        $this->assertEquals(['d.nottl'], $transport->calls[3]['sources'], 'd.ttl answered by the cache');
        $this->assertEquals(1, $data->getCacheHitCount());
        $this->assertEquals(1, $data->getFetchCount());
        $this->assertTrue($data->evaluate($tree, $candidate)->isSatisfied);
        $data = $resolver->resolve(self::onCpf('d.ttl', Spec::equalTo(42)), $candidate);
        $this->assertEquals(4, $transport->callCount(), 'every request answered by the cache: no fetchAll()');
        $this->assertEquals(0, $data->getStageCount(), 'a stage answered by the cache is not counted');
        $this->assertEquals(0, $data->getFetchCount());
        $now += 61;
        $resolver->resolve(self::onCpf('d.ttl', Spec::equalTo(42)), $candidate);
        $this->assertEquals(5, $transport->callCount(), 'expired after the TTL of the source');
    }

    /**
     * A key the transport does not answer fails with DataFetchException::noTransportResult() (the source
     * named, never a parameter); a key answered that nobody asked is ignored.
     */
    private function testDeferredMissingKeyFails(): void
    {
        $transport = (new RecordingStageTransport('t', extraKey: true))->script('d.miss', RecordingStageTransport::OMIT);
        $registry = (new DataSourceRegistry())
            ->register('d.miss', new DeferredProbeSource($transport, static fn(array $p): bool => true))
            ->register('d.here', new DeferredProbeSource($transport, static fn(array $p): bool => true));
        $tree = self::onCpf('d.miss', Spec::isTrue())->and(self::onCpf('d.here', Spec::isTrue()));
        $data = (new DataResolver($registry))->resolve($tree, self::customers()['c1']);

        $error = $data->getError(new DataRequest('d.miss', ['document' => '111']));
        $this->assertInstanceOf(DataFetchException::class, $error);
        $this->assertEquals('The stage transport returned no result for data source "d.miss".', $error->getMessage());
        $this->assertEquals('d.miss', $error->getSourceName());
        $this->assertEquals(null, $error->getStatusCode());
        $this->assertEquals(0, $error->getCode());
        $this->assertTrue($this->assertThrows(DataFetchException::class, static fn() => $data->get(new DataRequest('d.miss', ['document' => '111']))) === $error, 'get() throws the failure of a failed request');
        $this->assertFalse(str_contains($error->getMessage(), '111'), 'never a parameter value');
        $this->assertTrue($data->isResolved(new DataRequest('d.here', ['document' => '111'])), 'the other key of the call resolves');
        $this->assertEquals(2, count($data->getRequests()), 'the key nobody asked is ignored');
        $this->assertEquals(2, $data->getFetchCount());
        $this->assertTrue($data->evaluate($tree, self::customers()['c1'])->isError);
    }

    /**
     * fetchAll() that throws fails every request of its transport, and only those: the other transport and
     * the callable source of the stage still succeed. A source whose getTransport() throws fails alone.
     */
    private function testDeferredTransportCrashFailsOnlyItsKeys(): void
    {
        $down = new RuntimeException('transport down');
        $crashing = new RecordingStageTransport('t1', crash: $down);
        $healthy = new RecordingStageTransport('t2');
        $registry = (new DataSourceRegistry())
            ->register('a.1', new DeferredProbeSource($crashing, static fn(array $p): bool => true))
            ->register('a.2', new DeferredProbeSource($crashing, static fn(array $p): bool => true))
            ->register('b.1', new DeferredProbeSource($healthy, static fn(array $p): bool => true))
            ->register('cb', new CallableDataSource(static fn(array $p): bool => true))
            ->register('orphan', new DeferredProbeSource(null, static fn(array $p): bool => true));
        $tree = self::onCpf('a.1', Spec::isTrue())
            ->and(self::onCpf('orphan', Spec::isTrue()))
            ->and(self::onCpf('a.2', Spec::isTrue()))
            ->and(self::onCpf('b.1', Spec::isTrue()))
            ->and(self::onCpf('cb', Spec::isTrue()));
        $data = (new DataResolver($registry))->resolve($tree, self::customers()['c1']);

        $request = static fn(string $source): DataRequest => new DataRequest($source, ['document' => '111']);
        $this->assertEquals(1, $crashing->callCount());
        $this->assertTrue($data->getError($request('a.1')) === $down && $data->getError($request('a.2')) === $down, 'both keys of the crashed call fail with its exception');
        $this->assertEquals(1, $healthy->callCount());
        $this->assertTrue($data->isResolved($request('b.1')), 'the other transport of the stage');
        $this->assertTrue($data->isResolved($request('cb')), 'the callable source of the stage');
        $this->assertInstanceOf(LogicException::class, $data->getError($request('orphan')));
        $this->assertEquals('probe without a transport', $data->getError($request('orphan'))->getMessage());
        $this->assertEquals(['a.1', 'orphan', 'a.2'], array_map(static fn(DataRequest $r): string => $r->source, $data->getFailedRequests()));
        $this->assertEquals(4, $data->getFetchCount(), '2 handed to the crashed transport + 1 + the callable; the orphan never left');
        $this->assertEquals(1, $data->getStageCount());
        $this->assertTrue($data->evaluate($tree, self::customers()['c1'])->isError);
    }

    /**
     * The transport receives the deadline of the resolution (null without a limit); a transport whose turn
     * comes after the deadline is not called and its requests fail with the resolution time limit.
     */
    private function testDeferredDeadline(): void
    {
        $transport = new RecordingStageTransport();
        $registry = (new DataSourceRegistry())->register('d.a', new DeferredProbeSource($transport, static fn(array $p): bool => true));
        $resolver = new DataResolver($registry);
        $leaf = self::onCpf('d.a', Spec::isTrue());

        $before = CurlMultiTransport::now();
        $resolver->resolve($leaf, self::customers()['c1'], ResolutionPolicy::concurrent(timeout: 2.0));
        $after = CurlMultiTransport::now();
        $deadline = $transport->calls[0]['deadline'];
        $this->assertTrue($deadline !== null && $deadline >= $before + 2.0 && $deadline <= $after + 2.0, sprintf(
            'the deadline is the start of the resolution + 2.0 s (deadline %.6f, resolution from %.6f to %.6f)',
            $deadline ?? -1.0,
            $before,
            $after
        ));
        $left = ($deadline ?? 0.0) - $transport->calls[0]['at'];
        $this->assertTrue($left > 1.0 && $left <= 2.0, sprintf('at the call, %.6f s of the 2.0 s were left', $left));
        $resolver->resolve($leaf, self::customers()['c2'], ResolutionPolicy::concurrent());
        $this->assertEquals(null, $transport->calls[1]['deadline'], 'no limit: null');

        // A first transport sleeps past the limit: the second one is not called, the callables not run (each fails)
        $log = new ArrayObject();
        $slow = new RecordingStageTransport('slow', $log, sleepMs: 500);
        $late = new RecordingStageTransport('late', $log);
        $callableRuns = 0;
        $registry = (new DataSourceRegistry())
            ->register('s.1', new DeferredProbeSource($slow, static fn(array $p): bool => true))
            ->register('l.1', new DeferredProbeSource($late, static fn(array $p): bool => true))
            ->register('cb', new CallableDataSource(function (array $p) use (&$callableRuns): bool {
                $callableRuns++;
                return true;
            }))
            ->register('cb2', new CallableDataSource(function (array $p) use (&$callableRuns): bool {
                $callableRuns++;
                return true;
            }));
        $tree = self::onCpf('s.1', Spec::isTrue())->and(self::onCpf('l.1', Spec::isTrue()))->and(self::onCpf('cb', Spec::isTrue()))->and(self::onCpf('cb2', Spec::isTrue()));
        $start = hrtime(true);
        $data = (new DataResolver($registry))->resolve($tree, self::customers()['c1'], ResolutionPolicy::concurrent(timeout: 0.4));
        $elapsed = self::ms($start);
        self::$timings['deferred_deadline_0.4s'] = $elapsed;
        $startedAfter = ($slow->calls[0]['at'] ?? 0.0) - (($slow->calls[0]['deadline'] ?? 0.0) - 0.4);
        $this->assertEquals(['transport:slow'], $log->getArrayCopy(), sprintf(
            'the first transport starts before the limit (%.1f ms after the start of the resolution), the second is never called (resolve() took %.1f ms)',
            $startedAfter * 1000,
            $elapsed
        ));
        $this->assertTrue($data->isResolved(new DataRequest('s.1', ['document' => '111'])), 'a value the transport returned is kept (honouring the limit is the transport\'s job)');
        foreach (['l.1', 'cb', 'cb2'] as $source) {
            $error = $data->getError(new DataRequest($source, ['document' => '111']));
            $this->assertInstanceOf(DataFetchException::class, $error);
            $this->assertEquals(sprintf('The time limit of the resolution was reached before data source "%s" answered.', $source), $error->getMessage());
        }
        $this->assertEquals(0, $callableRuns);
        $this->assertEquals(1, $data->getFetchCount(), 'only the request handed to the first transport');
    }

    /**
     * Sequential: each leaf reaches the transport with a one-key $pending, in evaluation order; a leaf
     * short-circuited by the evaluation never reaches it. The same request twice: one key, one fetch.
     */
    private function testDeferredSequentialAndDeduplication(): void
    {
        $transport = new RecordingStageTransport();
        $probes = [
            'q.a' => new DeferredProbeSource($transport, static fn(array $p): bool => true),
            'q.b' => new DeferredProbeSource($transport, static fn(array $p): bool => false),
            'q.c' => new DeferredProbeSource($transport, static fn(array $p): bool => true),
        ];
        $registry = new DataSourceRegistry();
        foreach ($probes as $name => $probe) {
            $registry->register($name, $probe);
        }
        $resolver = new DataResolver($registry);
        $candidate = self::customers()['c1'];
        $tree = self::onCpf('q.a', Spec::isTrue())->and(self::onCpf('q.b', Spec::isTrue()))->and(self::onCpf('q.c', Spec::isTrue()));

        $data = $resolver->resolve($tree, $candidate, ResolutionPolicy::sequential());
        $this->assertEquals(0, $transport->callCount(), 'sequential: nothing fetched beforehand');
        $this->assertFalse($data->isSatisfiedBy($tree, $candidate));
        $this->assertEquals([['q.a'], ['q.b']], array_map(static fn(array $call): array => $call['sources'], $transport->calls), 'one key per call, in evaluation order; q.c short-circuited');
        $this->assertEquals([[self::cpfKey('q.a', '111')], [self::cpfKey('q.b', '111')]], array_map(static fn(array $call): array => $call['keys'], $transport->calls));
        $this->assertEquals(2, $data->getStageCount());
        $this->assertEquals(2, $data->getFetchCount());
        $this->assertEquals(0, array_sum(array_map(static fn(DeferredProbeSource $p): int => count($p->unitaryFetches), $probes)), 'the unitary fetch() is not taken inside the scope');

        // The same request twice in the tree: one key, one fetch, under both policies
        $twice = self::onCpf('q.a', Spec::isTrue())->and(Spec::not(self::onCpf('q.a', Spec::isFalse())));
        $calls = $transport->callCount();
        $data = $resolver->resolve($twice, $candidate);
        $this->assertEquals([[self::cpfKey('q.a', '111')]], array_map(static fn(array $call): array => $call['keys'], array_slice($transport->calls, $calls)));
        $this->assertEquals(1, $data->getFetchCount());
        $this->assertTrue($data->evaluate($twice, $candidate)->isSatisfied);
        $calls = $transport->callCount();
        $this->assertTrue($resolver->evaluate($twice, $candidate, ResolutionPolicy::sequential())->isSatisfied);
        $this->assertEquals(1, $transport->callCount() - $calls, 'sequential: the second leaf reads the value fetched by the first');
    }

    /** Outside a resolution scope a leaf takes the unitary fetch() of the source; the registry accepts it. */
    private function testDeferredUnitaryFetchOutsideAScope(): void
    {
        $transport = new RecordingStageTransport();
        $probe = new DeferredProbeSource($transport, static fn(array $p): string => $p['document'] === '111' ? 'verified' : 'pending');
        $registry = new DataSourceRegistry();
        $this->assertTrue($registry->register('kyc', $probe) === $registry, 'registered, no extension asked');
        $this->assertTrue($registry->get('kyc') === $probe);
        DataSourceRegistry::setDefault($registry);
        try {
            $leaf = self::onCpf('kyc', Spec::equalTo('verified'));
            $this->assertTrue($leaf->evaluate(self::customers()['c1'])->isSatisfied);
            $this->assertFalse($leaf->evaluate(self::customers()['c2'])->isSatisfied);
        } finally {
            DataSourceRegistry::setDefault(null);
        }
        $this->assertEquals(['111', '222'], array_map(static fn(DataRequest $r): string => (string) $r->get('document'), $probe->unitaryFetches));
        $this->assertEquals(0, $transport->callCount(), 'no transport outside a scope');
    }

    /**
     * Random trees (fixed seed) over callable, deferred (on k transports) and plain sources: the same verdict
     * under concurrent(), tiered() and sequential(); one fetchAll() per transport with pending keys in each
     * stage (concurrent: per transport; tiered: per pair cost x transport among the requests not skipped).
     */
    private function testDeferredRandomizedEquivalence(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(45045));
        $plain = static fn(Closure $value, int $cost): IDataSource => new class($value, $cost) implements IDataSource {
            public function __construct(private readonly Closure $value, private readonly int $cost)
            {
            }

            public function fetch(DataRequest $request): mixed
            {
                return ($this->value)($request->parameters);
            }

            public function getCost(): int
            {
                return $this->cost;
            }

            public function getTtl(): ?int
            {
                return null;
            }
        };
        $mismatches = [];
        $withTwoTransportsCalled = 0;
        $skipped = 0;
        $start = hrtime(true);
        for ($i = 0; $i < 300; $i++) {
            $transports = [];
            for ($k = $random->getInt(1, 3), $j = 0; $j < $k; $j++) {
                $transports[] = new RecordingStageTransport("t{$j}");
            }
            $registry = new DataSourceRegistry();
            $transportOf = [];
            $costOf = [];
            $n = $random->getInt(3, 6);
            for ($s = 0; $s < $n; $s++) {
                $truth = ['111' => $random->getInt(0, 1) === 1, '222' => $random->getInt(0, 1) === 1];
                $value = static fn(array $p): bool => $truth[$p['document']];
                $costOf["s{$s}"] = $cost = $random->getInt(0, 2);
                $kind = $random->getInt(0, 3);                  // half of the sources are deferred
                if ($kind >= 2) {
                    $transport = $transports[$random->getInt(0, count($transports) - 1)];
                    $transportOf["s{$s}"] = spl_object_id($transport);
                    $registry->register("s{$s}", new DeferredProbeSource($transport, $value, $cost));
                } else {
                    $registry->register("s{$s}", $kind === 0 ? new CallableDataSource($value, $cost) : $plain($value, $cost));
                }
            }
            $build = static function (int $depth) use (&$build, $random, $n): ISpecification {
                if ($depth === 0 || $random->getInt(0, 3) === 0) {
                    return self::onCpf('s' . $random->getInt(0, $n - 1), $random->getInt(0, 1) === 1 ? Spec::isTrue() : Spec::isFalse());
                }
                return match ($random->getInt(0, 2)) {
                    0 => $build($depth - 1)->and($build($depth - 1)),
                    1 => $build($depth - 1)->or($build($depth - 1)),
                    default => Spec::not($build($depth - 1)),
                };
            };
            $tree = $build(3);
            $candidate = self::customers()[$random->getInt(0, 1) === 1 ? 'c1' : 'c2'];
            $resolver = new DataResolver($registry);
            $callsNow = static fn(): int => array_sum(array_map(static fn(RecordingStageTransport $t): int => $t->callCount(), $transports));

            $data = $resolver->resolve($tree, $candidate, ResolutionPolicy::concurrent());
            $deferred = array_values(array_filter($data->getRequests(), static fn(DataRequest $r): bool => isset($transportOf[$r->source])));
            $expected = count(array_unique(array_map(static fn(DataRequest $r): int => $transportOf[$r->source], $deferred)));
            $calls = $callsNow();
            $handed = array_sum(array_map(static fn(RecordingStageTransport $t): int => array_sum(array_map(static fn(array $c): int => count($c['keys']), $t->calls)), $transports));
            if ($calls !== $expected || $handed !== count($deferred)) {
                $mismatches[] = sprintf('#%d concurrent: %d fetchAll() for %d transports, %d keys handed for %d deferred requests', $i, $calls, $expected, $handed, count($deferred));
            }
            $withTwoTransportsCalled += $expected >= 2 ? 1 : 0;
            $concurrent = $data->evaluate($tree, $candidate)->isSatisfied;

            $before = $callsNow();
            $data = $resolver->resolve($tree, $candidate, ResolutionPolicy::tiered());
            $skippedKeys = array_map(static fn(DataRequest $r): string => $r->getKey(), $data->getSkippedRequests());
            $skipped += count($skippedKeys);
            $fetched = array_filter($deferred, static fn(DataRequest $r): bool => !in_array($r->getKey(), $skippedKeys, true));
            $expected = count(array_unique(array_map(static fn(DataRequest $r): string => $costOf[$r->source] . ':' . $transportOf[$r->source], $fetched)));
            if ($callsNow() - $before !== $expected) {
                $mismatches[] = sprintf('#%d tiered: %d fetchAll() for %d pairs cost x transport', $i, $callsNow() - $before, $expected);
            }
            $tiered = $data->evaluate($tree, $candidate)->isSatisfied;
            $sequential = $resolver->evaluate($tree, $candidate, ResolutionPolicy::sequential())->isSatisfied;
            if ($concurrent !== $tiered || $concurrent !== $sequential) {
                $mismatches[] = sprintf('#%d verdicts: concurrent %s, tiered %s, sequential %s', $i, var_export($concurrent, true), var_export($tiered, true), var_export($sequential, true));
            }
        }
        $elapsed = self::ms($start);
        self::$timings['deferred_randomized_300'] = $elapsed;
        $this->assertEquals([], array_slice($mismatches, 0, 5), sprintf('%d mismatch(es) in 300 random trees', count($mismatches)));
        $this->assertTrue($withTwoTransportsCalled >= 30 && $skipped >= 30, sprintf('the sample covers several transports in one stage (%d trees) and skipped requests (%d)', $withTwoTransportsCalled, $skipped));
        $this->assertTrue($elapsed < 2000.0, sprintf('300 random trees in %.1f ms', $elapsed));
    }

    /**
     * The rest of the stage runner of ResolvedData (2.6.0: the file entered the scope of mutation testing, and
     * these are the answers of the code around the deferred group that no scenario pinned): the requests are a
     * list; pendingByCost(), pendingAmong(), loadFromCache() and fetch() ignore resolved and unknown keys; a
     * failed key never stops its stage; the fetch count adds up across stages; the cache is read and written
     * only for a source with a TTL, and a source with a TTL works without a cache.
     */
    private function testStageRunnerEdgeCases(): void
    {
        $candidate = self::customers()['c1'];
        $key = static fn(string $source): string => self::cpfKey($source, '111');
        $request = static fn(string $source): DataRequest => new DataRequest($source, ['document' => '111']);

        // The internal protocol over resolved and unknown keys
        $transport = new RecordingStageTransport();
        $registry = (new DataSourceRegistry())
            ->register('e.cheap', new DeferredProbeSource($transport, static fn(array $p): bool => true, cost: 1))
            ->register('e.pricey', new DeferredProbeSource($transport, static fn(array $p): bool => true, cost: 10));
        $data = new ResolvedData($registry, ResolutionPolicy::tiered());
        $data->add($request('e.cheap'));
        $data->add($request('e.pricey'));
        $this->assertEquals([0, 1], array_keys($data->getRequests()), 'getRequests() is a list');
        $data->fetch([$key('e.cheap')]);
        $this->assertEquals([10 => [$key('e.pricey')]], $data->pendingByCost([$key('e.cheap'), 'unknown#0', $key('e.pricey')]), 'pendingByCost(): a resolved or unknown key is not pending');
        $this->assertEquals([$key('e.pricey')], $data->pendingAmong([$key('e.cheap'), 'unknown#0', $key('e.pricey')]), 'pendingAmong(): a list of the pending keys only');
        $data->fetch([$key('e.cheap'), 'unknown#0', $key('e.pricey')]);
        $this->assertEquals(2, $data->getFetchCount(), 'fetch(): a resolved or unknown key is skipped, never fetched again, and the stage goes on');
        $this->assertEquals([['e.cheap'], ['e.pricey']], array_map(static fn(array $call): array => $call['sources'], $transport->calls));

        // An unknown source fails in its stage and the stage goes on with the next key
        $data = (new DataResolver($registry))->resolve(Spec::external('nowhere', [], Spec::isTrue())->and(self::onCpf('e.cheap', Spec::isTrue())), $candidate);
        $this->assertTrue($data->isResolved($request('e.cheap')), 'the key after a failed one is fetched in the same stage');
        $this->assertEquals(1, $data->getStageCount());

        // Tiered with the cache: loadFromCache() answers before the first stage, so a cached expensive answer
        // decides the verdict and the cheap source is never called
        $cache = new InMemoryDataCache();
        $transport = new RecordingStageTransport();
        $registry = (new DataSourceRegistry())
            ->register('c.cheap', new DeferredProbeSource($transport, static fn(array $p): bool => true, cost: 1, ttl: 60))
            ->register('c.pricey', new DeferredProbeSource($transport, static fn(array $p): bool => false, cost: 10, ttl: 60));
        $resolver = new DataResolver($registry, $cache);
        $resolver->resolve(self::onCpf('c.pricey', Spec::isTrue()), $candidate);
        $tree = self::onCpf('c.cheap', Spec::isTrue())->and(self::onCpf('c.pricey', Spec::isTrue()));
        $data = $resolver->resolve($tree, $candidate, ResolutionPolicy::tiered());
        $this->assertEquals(1, $transport->callCount(), 'only the first resolution reached the transport');
        $this->assertEquals(['c.cheap'], array_map(static fn(DataRequest $r): string => $r->source, $data->getSkippedRequests()));
        $this->assertEquals(1, $data->getCacheHitCount());
        $this->assertEquals(0, $data->getFetchCount());
        $this->assertFalse($data->evaluate($tree, $candidate)->isSatisfied);
        $data->loadFromCache([$key('c.pricey'), 'unknown#0']);
        $this->assertEquals(1, $data->getCacheHitCount(), 'loadFromCache(): a resolved or unknown key is not read again');
        $fresh = new ResolvedData($registry, ResolutionPolicy::tiered(), $cache);
        $fresh->add($request('c.pricey'));
        $fresh->loadFromCache(['unknown#0', $key('c.pricey')]);
        $this->assertEquals(1, $fresh->getCacheHitCount(), 'loadFromCache(): an unknown key is skipped and the next one read');

        // HTTP: a call that cannot be built fails in its stage; the fetch count adds up across tiered stages
        $httpRegistry = (new DataSourceRegistry())
            ->register('h.cheap', $this->source('/serasa/score/{document}', 'score', 1))
            ->register('h.pricey', $this->source('/fraud/check/{document}', 'flagged', 10))
            ->register('h.broken', new HttpDataSource($this->server->baseUrl(), '/echo/{document}/{other}'));
        $data = (new DataResolver($httpRegistry))->resolve(Spec::external('h.broken', ['document' => 'cpf'], Spec::alwaysTrue()), $candidate);
        $this->assertEquals(['h.broken'], array_map(static fn(DataRequest $r): string => $r->source, $data->getFailedRequests()), 'a call that cannot be built fails at once');
        $tree = Spec::external('h.pricey', ['document' => 'cpf'], Spec::isFalse())->and(Spec::external('h.cheap', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700)));
        $data = (new DataResolver($httpRegistry))->resolve($tree, $candidate, ResolutionPolicy::tiered());
        $this->assertEquals([2, 2], [$data->getStageCount(), $data->getFetchCount()], 'two HTTP stages, one call each, counted together');

        // The cache is read and written only for a source with a TTL; a TTL without a cache is harmless
        $cache = new InMemoryDataCache();
        $cache->set($key('t.none'), 'stale', 60);
        $registry = (new DataSourceRegistry())
            ->register('t.none', new DeferredProbeSource(new RecordingStageTransport(), static fn(array $p): string => 'fresh'))
            ->register('t.ttl', new DeferredProbeSource(new RecordingStageTransport(), static fn(array $p): string => 'kept', ttl: 60));
        $data = (new DataResolver($registry, $cache))->resolve(self::onCpf('t.none', Spec::equalTo('fresh')), $candidate);
        $this->assertEquals('fresh', $data->get($request('t.none')), 'a source without a TTL never reads the cache');
        $this->assertEquals(0, $data->getCacheHitCount());
        $this->assertEquals('stale', $cache->get($key('t.none')), 'nor writes it');
        $data = (new DataResolver($registry))->resolve(self::onCpf('t.ttl', Spec::equalTo('kept')), $candidate);
        $this->assertTrue($data->isResolved($request('t.ttl')), 'a source with a TTL and no cache');
    }

    // ==========================================
    // Example 16.1 (docs/<language>/resolution/deferred.md)
    // ==========================================

    private function testReadme16_1BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/resolution/deferred.md', '16.1');
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/resolution/deferred.md', '16.1');
        $this->assertCount(1, $english, 'one PHP block in example 16.1 (EN)');
        $this->assertCount(1, $portuguese, 'one PHP block in example 16.1 (pt-BR)');
        $this->assertEquals(ReadmeBlocks::codeTokens($english[0] ?? ''), ReadmeBlocks::codeTokens($portuguese[0] ?? ''), 'example 16.1: the same code except comments');
    }

    /**
     * Example 16.1 runs as written: the code between the markers is the block of the page token by token
     * (its `use` lines are imports of this file; a bare expression of the page is kept here as `$rN = ...`).
     */
    private function testReadme16_1RunsAsWritten(): void
    {
        // README 16.1 >>>
        // The transport: every pending request of its sources in the stage, in one call
        $transport = new class implements IStageTransport {
            public int $calls = 0;

            public function fetchAll(array $pending, ?float $deadline): array
            {
                $this->calls++;
                $results = [];
                foreach ($pending as $key => [$source, $request]) {
                    try {
                        $results[$key] = $source->fetch($request);
                    } catch (\Throwable $e) {
                        $results[$key] = $e;                     // fails this request only
                    }
                }

                return $results;
            }
        };

        // A deferred source: its closure is the synchronous fetch(); inside a resolution the transport fetches it
        $deferred = fn(\Closure $value): IDeferredDataSource => new class($value, $transport) implements IDeferredDataSource {
            public function __construct(private \Closure $value, private IStageTransport $transport)
            {
            }

            public function fetch(DataRequest $request): mixed
            {
                return ($this->value)($request->parameters);
            }

            public function getCost(): int
            {
                return 0;
            }

            public function getTtl(): ?int
            {
                return null;
            }

            public function getTransport(): IStageTransport
            {
                return $this->transport;
            }
        };

        $registry = (new DataSourceRegistry())
            ->register('ledger.balance', $deferred(fn(array $p): float => ['A-1' => 2500.0, 'A-2' => 10.0][$p['account']]))
            ->register('kyc.status', $deferred(fn(array $p): string => $p['document'] === '111.444.777-35' ? 'verified' : 'pending'))
            ->register('sanctions.hit', $deferred(fn(array $p): bool => throw new \RuntimeException('sanctions list offline')));

        $rule = Spec::external('ledger.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo(1000))
            ->and(Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified')));

        $customer = (object) ['cpf' => '111.444.777-35', 'account' => 'A-1'];
        $resolver = new DataResolver($registry);
        $data = $resolver->resolve($rule, $customer);

        $r1 = $transport->calls;                                      // 1: both requests in one fetchAll()
        $r2 = $data->getStageCount();                                 // 1
        $r3 = $data->getFetchCount();                                 // 2
        $r4 = $data->evaluate($rule, $customer)->isSatisfied;         // true

        // A request the transport answers with a Throwable is the evaluation error of its leaf
        $screened = $rule->and(Spec::external('sanctions.hit', ['document' => 'cpf'], Spec::isFalse()));
        $result = $resolver->evaluate($screened, $customer);
        $r5 = $result->isError;                                       // true
        $r6 = $result->exception->getMessage();                       // 'sanctions list offline'
        $r7 = $transport->calls;                                      // 2
        // <<< README 16.1

        $this->assertEquals([1, 1, 2, true, true, 'sanctions list offline', 2], [$r1, $r2, $r3, $r4, $r5, $r6, $r7], 'example 16.1: the results written in its comments');
        $this->assertFalse($result->isSatisfied);
        $this->assertTranscribed16_1();
    }

    /** The code between the markers of example 16.1 is the block of docs/en/resolution/deferred.md. */
    private function assertTranscribed16_1(): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// README 16.1 >>>\n");
        $end = strpos($source, "// <<< README 16.1\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, 'transcription of example 16.1 marked');
        $tokens = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        $block = ReadmeBlocks::of(dirname(__DIR__, 2) . '/docs/en/resolution/deferred.md', '16.1')[0] ?? '';
        preg_match_all('/^use ([^;]+);$/m', $block, $imports);
        $this->assertCount(6, $imports[1], 'example 16.1 imports six names');
        foreach ($imports[1] as $import) {
            $this->assertTrue(str_contains($source, "\nuse {$import};\n"), "example 16.1: {$import} is imported by this file too");
        }
        $code = ReadmeBlocks::codeTokens((string) preg_replace('/^use [^;]+;$/m', '', $block));
        $this->assertTrue(count($code) > 100 && $transcribed === $code, sprintf(
            'example 16.1: the transcription is the code of the page (%d tokens transcribed, %d on the page)',
            count($transcribed),
            count($code)
        ));
    }

    // ==========================================
    // The server never outlives the suite
    // ==========================================

    private function testServerIsStoppedEvenOnFailure(): void
    {
        $server = new LocalHttpServer(2);
        $server->start();
        $pid = $server->pid();
        $this->assertTrue($pid > 0 && is_dir("/proc/{$pid}") || !is_dir('/proc'));
        try {
            try {
                throw new RuntimeException('simulated failure in the middle of a test');
            } finally {
                $server->stop();
            }
        } catch (RuntimeException $e) {
            $this->assertEquals('simulated failure in the middle of a test', $e->getMessage());
        }
        $this->assertServerIsGone($server);
    }

    private function assertServerIsGone(LocalHttpServer $server): void
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $server->port(), $errno, $error, 0.5);
        $this->assertTrue($socket === false, 'the server no longer accepts connections');
        if (is_dir('/proc')) {
            $pid = $server->pid();
            $alive = is_file("/proc/{$pid}/stat") && !str_contains((string) @file_get_contents("/proc/{$pid}/stat"), ') Z ');
            $this->assertFalse($alive, 'the server process is gone');
        }
    }
}
