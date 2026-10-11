<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataResolutionException;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * HttpDataSource - A Data Source Behind an HTTP Endpoint
 *
 * Built once, by the application, with everything that identifies the service: a fixed base URL, a
 * path template with `{name}` placeholders, the method, the headers (authentication included), an
 * optional body builder, its own time limit, its cost and its cache TTL. A request only supplies
 * parameter values.
 *
 * <code>
 * $serasa = new HttpDataSource(
 *     baseUrl: 'https://bureau.example.com',
 *     path: '/v1/score/{document}',
 *     parse: fn(int $status, array $headers, string $body): int => json_decode($body, true)['score'],
 *     headers: ['Authorization' => 'Bearer ' . $token],
 *     timeout: 2.0,
 *     cost: 10,
 * );
 * </code>
 *
 * URL building (no SSRF through a parameter, forward 026 D3/RN-06): every placeholder is replaced by
 * the percent-encoded value (`rawurlencode`, and `.` as `%2E`), so a value holding `/`, `?`, `#`,
 * `..` or a whole URL lands inside one path segment or one query value and cannot change the scheme,
 * the host, the port or the path. The resulting URL is checked against the base URL before the call
 * is made. Redirects are not followed.
 *
 * Responses: a 2xx response goes to `parse(int $status, array $headers, string $body)` (default: the
 * body decoded as JSON into arrays); any other status is a DataFetchException carrying the status
 * code, and the body of that response is never read into the exception. An exception of the parser
 * becomes a DataFetchException with the original as previous exception.
 *
 * Requires `ext-curl` (a `suggest` of the package): DataSourceRegistry::register() refuses an HTTP
 * source when the extension is not loaded.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class HttpDataSource implements IHttpDataSource
{
    private readonly string $baseUrl;
    private readonly string $scheme;
    private readonly string $host;
    private readonly ?int $port;
    private readonly string $basePath;

    /** @var list<string> placeholder names of the path template */
    private readonly array $placeholders;

    /** @var Closure(int, array<string, string>, string): mixed */
    private readonly Closure $parse;

    /**
     * @param string $baseUrl Fixed scheme, host, optional port and optional base path (`https://api.example.com/v2`);
     *                        no query, fragment or credentials
     * @param string $path Path template appended to the base URL, starting with `/`, with `{name}`
     *                     placeholders in the path or in the query (`/score/{document}?product={product}`)
     * @param (Closure(int, array<string, string>, string): mixed)|null $parse Turns a 2xx response into the value
     *                     (default: JSON body decoded into arrays)
     * @param string $method HTTP method (upper case)
     * @param array<string, string> $headers Fixed request headers (authentication included)
     * @param (Closure(array<string, string|int|float|bool>): string)|null $body Builds the request body from the
     *                     parameters (null: no body)
     * @param float $timeout Time limit of one call, in seconds
     * @param int $cost Relative cost (lower is cheaper; the tiered policy fetches cheaper sources first)
     * @param int|null $ttl Seconds a value may be cached between resolutions (null or 0: never)
     * @throws InvalidArgumentException When the base URL, the template, the method, a header or a number is not valid
     */
    public function __construct(
        string $baseUrl,
        private readonly string $path,
        ?Closure $parse = null,
        private readonly string $method = 'GET',
        private readonly array $headers = [],
        private readonly ?Closure $body = null,
        private readonly float $timeout = 5.0,
        private readonly int $cost = 0,
        private readonly ?int $ttl = null
    ) {
        $parts = parse_url($baseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (
            $parts === false
            || !in_array($scheme, ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($baseUrl, '?')
            || str_contains($baseUrl, '#')
            || preg_match('/[\x00-\x20\x7f{}]/', $baseUrl) === 1
        ) {
            throw new InvalidArgumentException(
                'The base URL of an HTTP data source must be an absolute http or https URL without query, fragment or credentials.'
            );
        }
        $this->scheme = $scheme;
        $this->host = strtolower((string) $parts['host']);
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->basePath = rtrim((string) ($parts['path'] ?? ''), '/');
        $this->baseUrl = rtrim($baseUrl, '/');

        if (
            !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '#')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
        ) {
            throw new InvalidArgumentException(
                'The path template of an HTTP data source must start with a single "/" and contain no fragment, backslash, space or control character.'
            );
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);
        $withoutPlaceholders = preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/', '', $path);
        if (str_contains((string) $withoutPlaceholders, '{') || str_contains((string) $withoutPlaceholders, '}')) {
            throw new InvalidArgumentException('Invalid placeholder in the path template of an HTTP data source: use {name}, a PHP identifier.');
        }
        $this->placeholders = array_values(array_unique($matches[1]));

        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('The time limit of an HTTP data source must be a positive number of seconds.');
        }
        // Validates the method and the headers once, at construction, with the rules of HttpCall.
        new HttpCall($method, $this->baseUrl . '/', $headers, null, $timeout);
        if ($ttl !== null && $ttl < 0) {
            throw new InvalidArgumentException('The TTL of a data source cannot be negative.');
        }

        $this->parse = $parse ?? static fn(int $status, array $headers, string $body): mixed
            => json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * True when `ext-curl` is loaded (an HTTP source cannot be registered without it).
     */
    public static function isSupported(): bool
    {
        return CurlMultiTransport::isAvailable();
    }

    /**
     * {@inheritdoc}
     *
     * @throws DataResolutionException When a placeholder has no parameter, or the expanded URL would
     *                                 leave the base URL (never expected, checked defensively)
     */
    public function createCall(DataRequest $request): HttpCall
    {
        $expanded = $this->path;
        foreach ($this->placeholders as $name) {
            if (!array_key_exists($name, $request->parameters)) {
                throw new DataResolutionException(sprintf(
                    'Data source "%s" needs the parameter "%s" to build its URL.',
                    $request->source,
                    $name
                ));
            }
            $expanded = str_replace('{' . $name . '}', self::encode($request->parameters[$name]), $expanded);
        }
        $url = $this->baseUrl . $expanded;
        $this->assertWithinBase($url, $request->source);

        $body = $this->body !== null ? ($this->body)($request->parameters) : null;

        return new HttpCall($this->method, $url, $this->headers, $body, $this->timeout);
    }

    /**
     * {@inheritdoc}
     *
     * @throws DataFetchException For a status outside 2xx (with the status code) or a parser failure
     */
    public function parseResponse(DataRequest $request, int $status, array $headers, string $body): mixed
    {
        if ($status < 200 || $status > 299) {
            throw DataFetchException::httpStatus($request->source, $status);
        }

        try {
            return ($this->parse)($status, $headers, $body);
        } catch (Throwable $e) {
            throw DataFetchException::parseFailed($request->source, $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Synchronous path (outside a resolution scope): one call through the same transport.
     *
     * @throws Throwable DataFetchException or DataResolutionException
     */
    public function fetch(DataRequest $request): mixed
    {
        $call = $this->createCall($request);
        $result = CurlMultiTransport::execute(['request' => [$call, $request->source]])['request'];
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $this->parseResponse($request, $result['status'], $result['headers'], $result['body']);
    }

    /** {@inheritdoc} */
    public function getCost(): int
    {
        return $this->cost;
    }

    /** {@inheritdoc} */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    /** Time limit of one call, in seconds. */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /** Base URL, without trailing slash. */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Path template, as given. */
    public function getPathTemplate(): string
    {
        return $this->path;
    }

    /** HTTP method. */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Percent-encodes a parameter value for any position of the URL: every reserved character
     * (`/ ? # & = : @ %`...) and also `.`, so that `..` cannot become a dot segment.
     */
    private static function encode(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return str_replace('.', '%2E', rawurlencode((string) $value));
    }

    /**
     * Defensive check: the expanded URL keeps the scheme, host, port and base path of the source.
     */
    private function assertWithinBase(string $url, string $sourceName): void
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        if (
            $parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== $this->scheme
            || strtolower((string) ($parts['host'] ?? '')) !== $this->host
            || (isset($parts['port']) ? (int) $parts['port'] : null) !== $this->port
            || isset($parts['user'])
            || isset($parts['fragment'])
            || ($this->basePath !== '' && !str_starts_with($path, $this->basePath . '/'))
        ) {
            throw new DataResolutionException(sprintf(
                'The URL of data source "%s" would leave its base URL; the call was not made.',
                $sourceName
            ));
        }
    }
}
