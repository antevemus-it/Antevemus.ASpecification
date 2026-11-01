<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use InvalidArgumentException;

/**
 * HttpErrorClassification - What the Error Classifier of an HTTP Source Read in a Failed Response
 *
 * The richer answer of the `classifyError` closure of HttpDataSource, for a provider that puts the
 * delay in the body instead of a header (Google's `RetryInfo.retryDelay`, for instance). A classifier
 * that only names the reason may return the text itself.
 *
 * <code>
 * classifyError: function (int $status, array $headers, string $body): ?HttpErrorClassification {
 *     $error = json_decode($body, true)['error'] ?? [];
 *     foreach ($error['details'] ?? [] as $detail) {
 *         if (str_ends_with((string) ($detail['@type'] ?? ''), 'RetryInfo')
 *             && preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', (string) ($detail['retryDelay'] ?? ''), $m)) {
 *             return new HttpErrorClassification('resource_exhausted', (float) $m[1]);
 *         }
 *     }
 *     return null;
 * },
 * </code>
 *
 * Both values are optional and validated here, with the rules of DataFetchException::httpStatus(): the
 * reason is a short code (DataFetchException::REASON_PATTERN, lower case), the delay a finite number of
 * seconds, zero or more. An invalid value is refused without being repeated in the message.
 *
 * @version    2.12.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class HttpErrorClassification
{
    /**
     * @param string|null $reason Short code of the failure (`rate_limit`, `insufficient_quota`...), or null
     * @param float|null $retryAfter Seconds the provider asks to wait before the next call, or null; it wins over the `Retry-After` header
     * @throws InvalidArgumentException When the reason does not match DataFetchException::REASON_PATTERN or the delay is negative or not finite
     */
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?float $retryAfter = null
    ) {
        DataFetchException::assertDetails($reason, $retryAfter);
    }
}
