<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\HttpErrorClassification;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\LocalHttpServer;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Module46_HttpErrorReasonAndRetryAfterTest - Forward 059 (2.12.0): the reason and the retry delay of a
 * failed HTTP call.
 *
 * Against the local HTTP server of Module 21 (tests/Support/LocalHttpServer.php, whose router gained
 * `headers` per endpoint):
 * - D1/D2: the delay of `Retry-After` (seconds, HTTP-date measured from the `date` of the response) and of
 *   `retry-after-ms` reaches DataFetchException::getRetryAfter(), with or without a classifier;
 * - D3/RN-01: without a reason and a delay the message is byte for byte the one of 2.11.1; with them, a
 *   suffix of validated values only;
 * - D4/D5: the classifier answers a code or an HttpErrorClassification (whose delay wins over the
 *   header's), is called only outside 2xx, and the same answer comes out of the resolver and of fetch();
 * - D6/RN-04: a classifier that throws or answers an invalid value leaves the HTTP failure as it is, without
 *   a reason, the cause as previous exception, the value never repeated;
 * - RN-02: the body of the error response never reaches a message of the exception chain nor the failures
 *   of the result: without a classifier, with one that answers from a closed list, and with one that hands
 *   the body back (refused by the pattern). A classifier that turns the body into a valid code on purpose
 *   is outside what the library can prevent; the documentation says to answer from a closed list;
 * - RN-03: the validation of httpStatus() and HttpErrorClassification (never repeating the value refused);
 * - D10: example 16.2 of the documentation (identical in EN and pt-BR, except comments) runs as written.
 *
 * The parser of the header itself (every form, by property) is tests/PhpUnit/RetryAfterTest.php.
 *
 * @version    2.12.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Module46_HttpErrorReasonAndRetryAfterTest extends TestCase
{
    private const SECRET = 'SECRET-ERROR-BODY-59c1';

    /** The `date` of the dated endpoint, and its `Retry-After` 30 seconds later. */
    private const SERVER_DATE = 'Sun, 01 Nov 2026 12:00:00 GMT';
    private const RETRY_DATE = 'Sun, 01 Nov 2026 12:00:30 GMT';

    private LocalHttpServer $server;

    public function run(): void
    {
        $previousDefault = DataSourceRegistry::default();
        $this->server = new LocalHttpServer(4);
        $this->server->start();

        try {
            $this->configureEndpoints();

            $this->testMessageWithoutDetailsIsTheOneOf2_11();
            $this->testRetryAfterReachesTheException();
            $this->testClassifierNamesTheReason();
            $this->testClassificationFromTheBodyWinsOverTheHeader();
            $this->testClassifierIsCalledOnlyOutside2xx();
            $this->testFaultyClassifierKeepsTheHttpFailure();
            $this->testResolverAndFetchAgree();
            $this->testBodyNeverReachesAMessage();
            $this->testValidationOfTheDetails();
            $this->testExample16_2BlocksAreTheSameInBothLanguages();
            $this->testExample16_2RunsAsWritten();
        } finally {
            $this->server->stop();
            DataSourceRegistry::setDefault($previousDefault);
        }
    }

    // ==========================================
    // Fixture
    // ==========================================

    private function configureEndpoints(): void
    {
        $s = $this->server;
        $s->endpoint('plain', ['status' => 500, 'body' => self::SECRET]);
        $s->endpoint('limited', [
            'status' => 429,
            'headers' => ['Retry-After' => '2'],
            'body' => '{"error":{"type":"rate_limit_error","message":"' . self::SECRET . ' sk-...abcd"}}',
        ]);
        $s->endpoint('quota', [
            'status' => 429,
            'body' => '{"error":{"code":"insufficient_quota","message":"' . self::SECRET . '"}}',
        ]);
        $s->endpoint('millis', ['status' => 429, 'headers' => ['Retry-After-Ms' => '250', 'Retry-After' => '9'], 'body' => self::SECRET]);
        $s->endpoint('dated', ['status' => 503, 'headers' => ['Date' => self::SERVER_DATE, 'Retry-After' => self::RETRY_DATE], 'body' => self::SECRET]);
        $s->endpoint('gemini', [
            'status' => 429,
            'headers' => ['Retry-After' => '99'],
            'body' => '{"error":{"status":"RESOURCE_EXHAUSTED","message":"' . self::SECRET . '","details":[{"@type":"type.googleapis.com/google.rpc.RetryInfo","retryDelay":"7.5s"}]}}',
        ]);
        $s->endpoint('ok', ['default' => ['v' => 42]]);
        // Example 16.2 of the documentation (POST /v1/chat/completions)
        $s->endpoint('v1', [
            'status' => 429,
            'headers' => ['Retry-After' => '2'],
            'body' => '{"error":{"code":"rate_limit_exceeded","message":"' . self::SECRET . '"}}',
        ]);
    }

    /**
     * HTTP source on the local server, `/<endpoint>/{id}`, with an optional classifier.
     */
    private function source(string $endpoint, ?Closure $classifyError = null): HttpDataSource
    {
        return new HttpDataSource(
            baseUrl: $this->server->baseUrl(),
            path: '/' . $endpoint . '/{id}',
            parse: static fn(int $status, array $headers, string $body): mixed => json_decode($body, true)['v'] ?? null,
            timeout: 5.0,
            classifyError: $classifyError,
        );
    }

    /**
     * The DataFetchException of fetch() on the endpoint (the synchronous path).
     */
    private function failureOf(string $endpoint, ?Closure $classifyError = null): DataFetchException
    {
        $source = $this->source($endpoint, $classifyError);
        $error = $this->assertThrows(DataFetchException::class, static fn() => $source->fetch(new DataRequest($endpoint, ['id' => '1'])));
        $this->assertInstanceOf(DataFetchException::class, $error);

        return $error;
    }

    /** A classifier that reads the code of the body and answers one of a closed list (the documented way). */
    private static function llmClassifier(): Closure
    {
        return static function (int $status, array $headers, string $body): ?string {
            $error = json_decode($body, true)['error'] ?? [];
            $code = (string) ($error['code'] ?? $error['type'] ?? '');

            return match (true) {
                $code === 'insufficient_quota' => 'insufficient_quota',
                $status === 429 => 'rate_limit',
                $status >= 500 => 'server',
                default => null,
            };
        };
    }

    // ==========================================
    // D3 / RN-01: the message without details
    // ==========================================

    private function testMessageWithoutDetailsIsTheOneOf2_11(): void
    {
        $error = $this->failureOf('plain');
        $this->assertEquals('Data source "plain" answered with HTTP status 500.', $error->getMessage());
        $this->assertEquals(500, $error->getStatusCode());
        $this->assertEquals(null, $error->getReason());
        $this->assertEquals(null, $error->getRetryAfter());
        $this->assertEquals(null, $error->getPrevious());

        // A classifier that answers null changes nothing
        $none = $this->failureOf('plain', static fn(int $status, array $headers, string $body): ?string => null);
        $this->assertEquals('Data source "plain" answered with HTTP status 500.', $none->getMessage());
        $this->assertEquals(null, $none->getReason());
        $this->assertEquals(null, $none->getPrevious());

        // The factory of 2.11 (two arguments) answers what it answered
        $direct = DataFetchException::httpStatus('svc', 404);
        $this->assertEquals('Data source "svc" answered with HTTP status 404.', $direct->getMessage());
        $this->assertEquals(null, $direct->getReason());
        $this->assertEquals(null, $direct->getRetryAfter());

        // Every other failure has neither
        foreach ([
            DataFetchException::transport('svc', 7),
            DataFetchException::timeout('svc', 1.5),
            DataFetchException::resolutionTimeout('svc'),
            DataFetchException::parseFailed('svc', new RuntimeException('x')),
            DataFetchException::sourceFailed('svc', new RuntimeException('x')),
            DataFetchException::submissionOutcomeUnknown('svc'),
            DataFetchException::noTransportResult('svc'),
        ] as $other) {
            $this->assertEquals(null, $other->getReason());
            $this->assertEquals(null, $other->getRetryAfter());
            $this->assertEquals(null, $other->getStatusCode());
        }
    }

    // ==========================================
    // D1 / D2: the delay asked for
    // ==========================================

    private function testRetryAfterReachesTheException(): void
    {
        // Without a classifier: the header is enough
        $limited = $this->failureOf('limited');
        $this->assertEquals(429, $limited->getStatusCode());
        $this->assertEquals(2.0, $limited->getRetryAfter());
        $this->assertEquals(null, $limited->getReason());
        $this->assertEquals('Data source "limited" answered with HTTP status 429 (retry after 2.000 s).', $limited->getMessage());

        // retry-after-ms first (more precise), whatever the case of the names
        $millis = $this->failureOf('millis');
        $this->assertEquals(0.25, $millis->getRetryAfter());
        $this->assertEquals('Data source "millis" answered with HTTP status 429 (retry after 0.250 s).', $millis->getMessage());

        // HTTP-date, measured from the date of the response (the local clock plays no part)
        $dated = $this->failureOf('dated');
        $this->assertEquals(503, $dated->getStatusCode());
        $this->assertEquals(30.0, $dated->getRetryAfter());

        // No header: no delay
        $this->assertEquals(null, $this->failureOf('quota')->getRetryAfter());
    }

    // ==========================================
    // D4: the classifier
    // ==========================================

    private function testClassifierNamesTheReason(): void
    {
        $quota = $this->failureOf('quota', self::llmClassifier());
        $this->assertEquals(429, $quota->getStatusCode());
        $this->assertEquals('insufficient_quota', $quota->getReason());
        $this->assertEquals(null, $quota->getRetryAfter());
        $this->assertEquals('Data source "quota" answered with HTTP status 429 (reason: insufficient_quota).', $quota->getMessage());

        $limited = $this->failureOf('limited', self::llmClassifier());
        $this->assertEquals('rate_limit', $limited->getReason());
        $this->assertEquals(2.0, $limited->getRetryAfter());
        $this->assertEquals('Data source "limited" answered with HTTP status 429 (reason: rate_limit, retry after 2.000 s).', $limited->getMessage());

        $server = $this->failureOf('plain', self::llmClassifier());
        $this->assertEquals('server', $server->getReason());
        $this->assertEquals(null, $server->getPrevious());

        // The classifier receives the status, the headers (lower-case names) and the body as they came
        $seen = [];
        $this->failureOf('dated', static function (int $status, array $headers, string $body) use (&$seen): ?string {
            $seen = [$status, $headers['retry-after'] ?? null, $headers['date'] ?? null, $body];

            return null;
        });
        $this->assertEquals([503, self::RETRY_DATE, self::SERVER_DATE, self::SECRET], $seen);
    }

    private function testClassificationFromTheBodyWinsOverTheHeader(): void
    {
        $gemini = static function (int $status, array $headers, string $body): ?HttpErrorClassification {
            $error = json_decode($body, true)['error'] ?? [];
            foreach ($error['details'] ?? [] as $detail) {
                if (str_ends_with((string) ($detail['@type'] ?? ''), 'RetryInfo')
                    && preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', (string) ($detail['retryDelay'] ?? ''), $m) === 1) {
                    return new HttpErrorClassification('resource_exhausted', (float) $m[1]);
                }
            }

            return null;
        };
        $error = $this->failureOf('gemini', $gemini);
        $this->assertEquals('resource_exhausted', $error->getReason());
        $this->assertEquals(7.5, $error->getRetryAfter(), 'the delay of the body wins over Retry-After: 99');

        // A classification without a delay keeps the delay of the header; one without a reason keeps none
        $reasonOnly = $this->failureOf('gemini', static fn(int $s, array $h, string $b): HttpErrorClassification => new HttpErrorClassification('resource_exhausted'));
        $this->assertEquals(99.0, $reasonOnly->getRetryAfter());
        $delayOnly = $this->failureOf('gemini', static fn(int $s, array $h, string $b): HttpErrorClassification => new HttpErrorClassification(null, 0.0));
        $this->assertEquals(null, $delayOnly->getReason());
        $this->assertEquals(0.0, $delayOnly->getRetryAfter(), 'zero is a delay, not an absence');
        $this->assertEquals('Data source "gemini" answered with HTTP status 429 (retry after 0.000 s).', $delayOnly->getMessage());
    }

    private function testClassifierIsCalledOnlyOutside2xx(): void
    {
        $calls = 0;
        $counting = static function (int $status, array $headers, string $body) use (&$calls): ?string {
            $calls++;

            return 'never';
        };
        $source = $this->source('ok', $counting);
        $this->assertEquals(42, $source->fetch(new DataRequest('ok', ['id' => '1'])));
        $registry = (new DataSourceRegistry())->register('ok', $source);
        $this->assertTrue((new DataResolver($registry))->evaluate(Spec::external('ok', ['id' => 'id'], Spec::equalTo(42)), (object) ['id' => '1'])->isSatisfied);
        $this->assertEquals(0, $calls, 'a 2xx response never reaches the classifier');

        $this->failureOf('plain', $counting);
        $this->assertEquals(1, $calls, 'once per failed response');

        // The boundaries of 2xx, through parseResponse() (the path of the resolver)
        $request = new DataRequest('ok', ['id' => '1']);
        foreach ([200, 204, 299] as $status) {
            $this->assertEquals(7, $source->parseResponse($request, $status, [], '{"v":7}'), "{$status} is a success");
        }
        $this->assertEquals(1, $calls, 'no 2xx status reaches the classifier');
        foreach ([199, 300, 302, 404] as $status) {
            $error = $this->assertThrows(DataFetchException::class, static fn() => $source->parseResponse($request, $status, ['retry-after' => '3'], '{"v":7}'));
            $this->assertEquals($status, $error->getStatusCode());
            $this->assertEquals('never', $error->getReason());
            $this->assertEquals(3.0, $error->getRetryAfter());
        }
        $this->assertEquals(5, $calls, 'every status outside 2xx reaches the classifier');
    }

    // ==========================================
    // D6 / RN-04: a faulty classifier
    // ==========================================

    private function testFaultyClassifierKeepsTheHttpFailure(): void
    {
        $answers = [
            'upper case' => 'Rate_Limit',
            'space' => 'rate limit',
            'empty' => '',
            'too long' => str_repeat('a', 65),
            'trailing newline' => "rate_limit\n",
            'the body itself' => self::SECRET,
            'an int' => 429,
            'an array' => ['rate_limit'],
            'an object' => (object) ['reason' => 'rate_limit'],
            'true' => true,
        ];
        foreach ($answers as $label => $answer) {
            $error = $this->failureOf('limited', static fn(int $s, array $h, string $b): mixed => $answer);
            $this->assertEquals(429, $error->getStatusCode(), "status kept ({$label})");
            $this->assertEquals(null, $error->getReason(), "no reason ({$label})");
            $this->assertEquals(2.0, $error->getRetryAfter(), "delay of the header kept ({$label})");
            $this->assertEquals('Data source "limited" answered with HTTP status 429 (retry after 2.000 s).', $error->getMessage(), $label);
            $previous = $error->getPrevious();
            $this->assertTrue($previous instanceof InvalidArgumentException, "cause kept ({$label})");
            $this->assertTrue(str_contains((string) $previous?->getMessage(), '"limited"'), "the cause names the source ({$label})");
            if (is_string($answer) && $answer !== '') {
                $this->assertFalse(str_contains((string) $previous?->getMessage(), $answer), "the value answered is not repeated ({$label})");
            }
        }

        // A 64-character code is valid (the upper bound)
        $this->assertEquals(str_repeat('a', 64), $this->failureOf('limited', static fn(int $s, array $h, string $b): string => str_repeat('a', 64))->getReason());

        // A classifier that throws (also an HttpErrorClassification refused inside it)
        $throws = $this->failureOf('limited', static function (int $s, array $h, string $b): never {
            throw new RuntimeException('classifier crashed');
        });
        $this->assertEquals(429, $throws->getStatusCode());
        $this->assertEquals(null, $throws->getReason());
        $this->assertEquals(2.0, $throws->getRetryAfter());
        $this->assertTrue($throws->getPrevious() instanceof RuntimeException);
        $this->assertEquals('classifier crashed', $throws->getPrevious()?->getMessage());

        $refused = $this->failureOf('limited', static fn(int $s, array $h, string $b): HttpErrorClassification => new HttpErrorClassification('Not A Code'));
        $this->assertEquals(null, $refused->getReason());
        $this->assertTrue($refused->getPrevious() instanceof InvalidArgumentException);
        $this->assertFalse(str_contains((string) $refused->getPrevious()?->getMessage(), 'Not A Code'));

        // An Error (not only an Exception) is caught too: the failure is still the HTTP status
        $typeError = $this->failureOf('limited', static fn(int $s, array $h, string $b): string => strlen(...));
        $this->assertEquals(429, $typeError->getStatusCode());
        $this->assertTrue($typeError->getPrevious() instanceof \TypeError);
    }

    // ==========================================
    // In a resolution and in fetch(): the same answer
    // ==========================================

    private function testResolverAndFetchAgree(): void
    {
        $registry = (new DataSourceRegistry())
            ->register('quota', $this->source('quota', self::llmClassifier()))
            ->register('limited', $this->source('limited', self::llmClassifier()))
            ->register('dated', $this->source('dated'));
        $resolver = new DataResolver($registry);
        foreach (['quota', 'limited', 'dated'] as $name) {
            $leaf = Spec::external($name, ['id' => 'id'], Spec::equalTo(1));
            $inScope = $resolver->evaluate($leaf, (object) ['id' => '1']);
            DataSourceRegistry::setDefault($registry);
            $outside = $leaf->evaluate((object) ['id' => '1']);
            DataSourceRegistry::setDefault(null);
            $direct = $this->failureOf($name, $name === 'dated' ? null : self::llmClassifier());
            foreach ([$inScope, $outside] as $result) {
                $this->assertTrue($result->isError, "{$name}: evaluation error");
                $this->assertEquals([SpecificationResult::EVALUATION_ERROR_MESSAGE], $result->getReasons());
                $this->assertInstanceOf(DataFetchException::class, $result->exception);
                $this->assertEquals($direct->getStatusCode(), $result->exception->getStatusCode());
                $this->assertEquals($direct->getReason(), $result->exception->getReason(), "{$name}: reason");
                $this->assertEquals($direct->getRetryAfter(), $result->exception->getRetryAfter(), "{$name}: delay");
                $this->assertEquals($direct->getMessage(), $result->exception->getMessage(), "{$name}: message");
            }
        }
    }

    // ==========================================
    // RN-02: the body never reaches a message
    // ==========================================

    private function testBodyNeverReachesAMessage(): void
    {
        $classifiers = [
            'none' => null,
            'closed list' => self::llmClassifier(),
            'echoes the body' => static fn(int $s, array $h, string $b): string => $b,
            'classification with the body' => static fn(int $s, array $h, string $b): HttpErrorClassification => new HttpErrorClassification($b),
            'throws without the body' => static function (int $s, array $h, string $b): never {
                throw new RuntimeException('could not read the error');
            },
        ];
        foreach (['plain', 'limited', 'quota', 'millis', 'dated', 'gemini'] as $endpoint) {
            foreach ($classifiers as $label => $classifier) {
                $registry = (new DataSourceRegistry())->register($endpoint, $this->source($endpoint, $classifier));
                $leaf = Spec::external($endpoint, ['id' => 'id'], Spec::equalTo(1));
                $inScope = (new DataResolver($registry))->evaluate($leaf, (object) ['id' => '1']);
                $chains = [$inScope->exception, $this->failureOf($endpoint, $classifier)];
                $this->assertFalse(str_contains(var_export($inScope->failures, true), self::SECRET), "{$endpoint}/{$label}: failures");
                $this->assertFalse(str_contains((string) $inScope, self::SECRET), "{$endpoint}/{$label}: result");
                foreach ($chains as $error) {
                    for ($e = $error; $e !== null; $e = $e->getPrevious()) {
                        $this->assertFalse(str_contains(strtolower($e->getMessage()), strtolower(self::SECRET)), "{$endpoint}/{$label}: no body in a message");
                        $this->assertFalse(str_contains($e->getMessage(), '127.0.0.1'), "{$endpoint}/{$label}: no URL in a message");
                        if ($e instanceof DataFetchException) {
                            $this->assertFalse(str_contains(strtolower((string) $e->getReason()), strtolower(self::SECRET)), "{$endpoint}/{$label}: no body in the reason");
                        }
                    }
                }
            }
        }
    }

    // ==========================================
    // RN-03: validation of the details
    // ==========================================

    private function testValidationOfTheDetails(): void
    {
        $this->assertEquals('/^[a-z0-9_.-]{1,64}$/D', DataFetchException::REASON_PATTERN);
        foreach (['a', 'rate_limit', 'insufficient_quota', 'overloaded-error', 'v1.quota', '0', str_repeat('z', 64)] as $valid) {
            $this->assertEquals($valid, DataFetchException::httpStatus('svc', 429, $valid)->getReason());
            $this->assertEquals($valid, (new HttpErrorClassification($valid))->reason);
        }
        $invalid = ['', 'Rate', 'rate limit', "rate\n", "rate_limit\n", str_repeat('z', 65), 'ção', 'a/b', 'a:b', '{x}', self::SECRET];
        foreach ($invalid as $value) {
            foreach ([
                static fn() => DataFetchException::httpStatus('svc', 429, $value),
                static fn() => new HttpErrorClassification($value),
            ] as $build) {
                $error = $this->assertThrows(InvalidArgumentException::class, $build);
                if (trim($value) !== '') {
                    $this->assertFalse(str_contains($error->getMessage(), trim($value)), 'the refused reason is not repeated');
                }
            }
        }
        foreach ([-0.001, -1.0, INF, -INF, NAN] as $delay) {
            $this->assertThrows(InvalidArgumentException::class, static fn() => DataFetchException::httpStatus('svc', 429, null, $delay));
            $this->assertThrows(InvalidArgumentException::class, static fn() => new HttpErrorClassification(null, $delay));
        }
        foreach ([0.0, 0.001, 1.0, 86400.0, 1.0e12] as $delay) {
            $this->assertEquals($delay, DataFetchException::httpStatus('svc', 429, null, $delay)->getRetryAfter());
            $this->assertEquals($delay, (new HttpErrorClassification(null, $delay))->retryAfter);
        }
        $both = DataFetchException::httpStatus('svc', 503, 'server', 1.25, new RuntimeException('cause'));
        $this->assertEquals('Data source "svc" answered with HTTP status 503 (reason: server, retry after 1.250 s).', $both->getMessage());
        $this->assertEquals('cause', $both->getPrevious()?->getMessage());
        $this->assertEquals('svc', $both->getSourceName());
        $empty = new HttpErrorClassification();
        $this->assertEquals(null, $empty->reason);
        $this->assertEquals(null, $empty->retryAfter);
    }

    // ==========================================
    // D10: example 16.2 of the documentation
    // ==========================================

    private function testExample16_2BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/resolution/concurrent.md', '16.2');
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/resolution/concurrent.md', '16.2');
        $this->assertCount(1, $english, 'one PHP block in example 16.2 (EN)');
        $this->assertCount(1, $portuguese, 'one PHP block in example 16.2 (pt-BR)');
        $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), 'identical except comments');
    }

    /**
     * Transcription of the block of example 16.2. The only change: the base URL points to the local server.
     */
    private function testExample16_2RunsAsWritten(): void
    {
        $this->server->resetCalls();

        $llmUrl = $this->server->baseUrl();   // docs: 'https://llm.example.com'

        $completion = new HttpDataSource(
            baseUrl: $llmUrl,
            path: '/v1/chat/completions',
            method: 'POST',
            headers: ['Authorization' => 'Bearer llm-key', 'Content-Type' => 'application/json'],
            body: fn(array $p): string => $p['payload'],
            timeout: 60.0,
            classifyError: function (int $status, array $headers, string $body): ?string {
                $code = (string) (json_decode($body, true)['error']['code'] ?? '');

                return match (true) {
                    $code === 'insufficient_quota' => 'insufficient_quota',
                    $status === 429 => 'rate_limit',
                    $status >= 500 => 'server',
                    default => null,
                };
            },
        );

        $caught = null;
        try {
            $completion->fetch(new DataRequest('llm.chat', ['payload' => '{"model":"m","messages":[]}']));
        } catch (DataFetchException $e) {
            $caught = $e;
            $this->assertEquals(429, $e->getStatusCode());
            $this->assertEquals('rate_limit', $e->getReason());
            $this->assertEquals(2.0, $e->getRetryAfter());
            $this->assertEquals('Data source "llm.chat" answered with HTTP status 429 (reason: rate_limit, retry after 2.000 s).', $e->getMessage());
        }
        $this->assertTrue($caught instanceof Throwable, 'the call failed as documented');

        // Prose: the body and the key were sent as configured, and never came back in the exception
        $call = $this->server->calls('v1')[0];
        $this->assertEquals('POST', $call['method']);
        $this->assertEquals('{"model":"m","messages":[]}', $call['body']);
        $this->assertEquals('Bearer llm-key', $call['authorization']);
    }
}
