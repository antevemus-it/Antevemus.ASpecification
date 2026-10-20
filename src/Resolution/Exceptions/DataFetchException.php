<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution\Exceptions;

use Throwable;

/**
 * DataFetchException - A Data Source Could Not Deliver Its Value
 *
 * One exception type for every way a fetch can fail: an HTTP status outside 2xx (the status code is
 * kept in getStatusCode()), a transport error, the time limit of the source, the time limit of the
 * whole resolution, a response that could not be parsed or a callable source that raised. Under
 * `evaluate()` it becomes the evaluation error of the leaf that needed the data (failure with the
 * generic message, `evaluation_error = true`, this exception in `$result->exception`).
 *
 * Messages are built from the source name, the status code and the transport error code only: the
 * body of a response, the URL and the parameter values never reach them. The original exception of
 * a parser or of a callable source is kept as the previous exception, for the log.
 * Since 2.5.0 also the request of an asynchronous source whose submission outcome is unknown
 * (submissionOutcomeUnknown(), AsyncDataSource); since 2.6.0 the request of a deferred source its stage
 * transport did not answer (noTransportResult(), IStageTransport).
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DataFetchException extends DataResolutionException
{
    /**
     * @param string $message Message without response body, URL or parameter values
     * @param string $sourceName Name of the data source
     * @param int|null $statusCode HTTP status code, when the source answered with one outside 2xx
     * @param Throwable|null $previous Original exception (parser, callable source)
     */
    private function __construct(
        string $message,
        private readonly string $sourceName,
        private readonly ?int $statusCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The source answered with an HTTP status outside 2xx (redirects are not followed).
     */
    public static function httpStatus(string $sourceName, int $statusCode): self
    {
        return new self(
            sprintf('Data source "%s" answered with HTTP status %d.', $sourceName, $statusCode),
            $sourceName,
            $statusCode
        );
    }

    /**
     * The transfer failed before a response was received (connection refused, DNS, TLS...).
     */
    public static function transport(string $sourceName, int $curlErrorCode): self
    {
        $description = function_exists('curl_strerror') ? (string) curl_strerror($curlErrorCode) : 'transport error';

        return new self(
            sprintf('Data source "%s" could not be reached (transport error %d: %s).', $sourceName, $curlErrorCode, $description),
            $sourceName
        );
    }

    /**
     * The source did not answer within its own time limit.
     */
    public static function timeout(string $sourceName, float $seconds): self
    {
        return new self(
            sprintf('Data source "%s" did not answer within its time limit of %.3f s.', $sourceName, $seconds),
            $sourceName
        );
    }

    /**
     * The time limit of the whole resolution was reached before the source answered.
     */
    public static function resolutionTimeout(string $sourceName): self
    {
        return new self(
            sprintf('The time limit of the resolution was reached before data source "%s" answered.', $sourceName),
            $sourceName
        );
    }

    /**
     * The response (2xx) could not be turned into a value by the parser of the source.
     */
    public static function parseFailed(string $sourceName, Throwable $previous): self
    {
        return new self(
            sprintf('The response of data source "%s" could not be parsed (%s).', $sourceName, self::shortName($previous)),
            $sourceName,
            null,
            $previous
        );
    }

    /**
     * A non-HTTP source raised while fetching.
     */
    public static function sourceFailed(string $sourceName, Throwable $previous): self
    {
        return new self(
            sprintf('Data source "%s" failed (%s).', $sourceName, self::shortName($previous)),
            $sourceName,
            null,
            $previous
        );
    }

    /**
     * A request of an asynchronous source was marked as being submitted and has no ticket: the process
     * stopped between sending it and storing the ticket (or the sending failed half-way), and nothing
     * reconciled it (2.5.0, forward 048, D27). It is not sent again: a paid query is never repeated in
     * silence. Reconcile it (AsyncDataSource `reconcile`) or clear its ticket to send it again.
     */
    public static function submissionOutcomeUnknown(string $sourceName): self
    {
        return new self(
            sprintf('Data source "%s": the outcome of a submitted request is unknown (no ticket was stored); it is not sent again until it is reconciled or its ticket is cleared.', $sourceName),
            $sourceName
        );
    }

    /**
     * The stage transport of a deferred source (IStageTransport, 2.6.0) answered without a result for one
     * of the requests it was handed: the request is failed, never left pending.
     */
    public static function noTransportResult(string $sourceName): self
    {
        return new self(
            sprintf('The stage transport returned no result for data source "%s".', $sourceName),
            $sourceName
        );
    }

    /** Name of the data source that failed. */
    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    /** HTTP status code outside 2xx, or null when the failure was not an HTTP answer. */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    private static function shortName(Throwable $e): string
    {
        $parts = explode('\\', $e::class);

        return (string) end($parts);
    }
}
