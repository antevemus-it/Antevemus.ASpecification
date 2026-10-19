<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Linq\Exceptions\NonTranslatableLinqException;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataResolutionException;
use Antevemus\ASpecification\Resolution\Exceptions\DataSourceNotFoundException;
use Antevemus\ASpecification\Resolution\ExternalDataSpecification;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\InMemoryDataCache;
use Antevemus\ASpecification\Resolution\ResolutionMode;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Resolution\ResolutionScope;
use Antevemus\ASpecification\Resolution\ResolvedData;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Tests\Support\LocalHttpServer;
use Antevemus\ASpecification\Tests\TestCase;
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
 * - the example 16 blocks of the README (EN/pt-BR), transcribed against the local server.
 *
 * @version    1.11.0
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
        $this->assertTrue(self::ms($start) < 800.0);
        $this->assertTrue($result->isError);
        // Count only this phase's calls (document 2): an aborted request of the concurrent phase above
        // (document 1) can still sit in the accept queue of a busy worker of php -S, invisible to
        // waitIdle(), and be logged after the reset.
        $phase = array_filter($this->server->calls('veryslow'), static fn(array $call): bool => str_ends_with((string) parse_url($call['uri'], PHP_URL_PATH), '/2'));
        $this->assertEquals(1, count($phase), 'after the limit no call is started');

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
        $process = proc_open(
            [PHP_BINARY, '-n', __DIR__ . '/../Support/resolution_no_curl_probe.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertTrue(is_resource($process));
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        $report = json_decode($output, true);
        $this->assertTrue(is_array($report), 'probe ran: ' . $output);
        $this->assertTrue($report['callable'], 'a callable source works without curl');
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
