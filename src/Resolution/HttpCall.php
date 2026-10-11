<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use InvalidArgumentException;

/**
 * HttpCall - One HTTP Request, Built by an HTTP Data Source
 *
 * Method, absolute URL (`http` or `https`), request headers, optional body and time limit in
 * seconds. The DataResolver runs the calls of a stage together in one `curl_multi`; redirects are
 * never followed, so the host of the call is the host of the URL.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class HttpCall
{
    /**
     * @param string $method HTTP method token, upper case (GET, POST...)
     * @param string $url Absolute http(s) URL
     * @param array<string, string> $headers Request headers by name
     * @param string|null $body Request body (null for none)
     * @param float $timeout Time limit of the whole transfer, in seconds
     * @throws InvalidArgumentException When a field is not valid (header with CR/LF, relative URL...)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $timeout = 5.0
    ) {
        if (preg_match('/^[A-Z]+$/', $method) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP method "%s": an upper-case token is required.', $method));
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw new InvalidArgumentException('An HTTP call needs an absolute http or https URL.');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('An HTTP call URL cannot contain spaces or control characters.');
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
                throw new InvalidArgumentException('Invalid HTTP header name.');
            }
            if (!is_string($value) || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('Invalid value for HTTP header "%s".', $name));
            }
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('The time limit of an HTTP call must be a positive number of seconds.');
        }
    }
}
