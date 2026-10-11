<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataResolutionException;
use Throwable;

/**
 * CurlMultiTransport - Runs a Batch of HTTP Calls Concurrently With `curl_multi`
 *
 * Internal to the resolution subsystem: the DataResolver hands it every HTTP call of a stage, and
 * HttpDataSource::fetch() a batch of one. No dependency beyond `ext-curl`, and it works under
 * PHP-FPM (no event loop, no Fibers): the process blocks until the slowest call of the batch ends,
 * so the batch costs about as much as its slowest call instead of the sum of all of them.
 *
 * Safety: redirects are not followed, only `http`/`https` are allowed, the URL path is sent as
 * built (`CURLOPT_PATH_AS_IS`, no dot-segment squashing), and the time limit of each transfer is the
 * smaller of the call's own limit and what is left of the resolution's limit. Failures are returned
 * as DataFetchException (status code, transport error code, time limit); the response body of a
 * failed call is never read into an exception.
 *
 * @internal
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CurlMultiTransport
{
    /** CURLE_OPERATION_TIMEDOUT */
    private const CURL_TIMEOUT = 28;

    private function __construct()
    {
    }

    /**
     * True when `ext-curl` is loaded.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('curl');
    }

    /**
     * Monotonic clock in seconds.
     */
    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /** Smallest remaining resolution budget (seconds) for which a call is still started. */
    public const MIN_START_BUDGET = 0.010;

    /**
     * Runs the calls together and returns, per key, the response or the failure.
     *
     * @param array<string, array{0: HttpCall, 1: string}> $calls Call and source name, by key
     * @param float|null $deadline Monotonic instant (now()) at which the resolution gives up, or null
     * @return array<string, array{status: int, headers: array<string, string>, body: string}|Throwable>
     * @throws DataResolutionException When `ext-curl` is not loaded
     */
    public static function execute(array $calls, ?float $deadline = null): array
    {
        if (!self::isAvailable()) {
            throw new DataResolutionException('HTTP data sources need the curl extension (ext-curl), which is not loaded.');
        }

        $results = [];
        $transfers = [];
        $headers = [];
        $multi = curl_multi_init();

        try {
            foreach ($calls as $key => [$call, $sourceName]) {
                $key = (string) $key;
                $limit = $call->timeout;
                $boundByResolution = false;
                if ($deadline !== null) {
                    $remaining = $deadline - self::now();
                    // A budget too small to complete any call is an exhausted budget: starting the call
                    // anyway would reach the server (localhost connects in microseconds) and spend a
                    // request, possibly a paid one, that can only end as a time-limit failure.
                    if ($remaining < self::MIN_START_BUDGET) {
                        $results[$key] = DataFetchException::resolutionTimeout($sourceName);
                        continue;
                    }
                    if ($remaining < $limit) {
                        $limit = $remaining;
                        $boundByResolution = true;
                    }
                }

                $handle = curl_init();
                $headers[$key] = [];
                $limitMs = max(1, (int) ceil($limit * 1000));
                $options = [
                    CURLOPT_URL => $call->url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_PATH_AS_IS => true,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_TIMEOUT_MS => $limitMs,
                    CURLOPT_CONNECTTIMEOUT_MS => $limitMs,
                    CURLOPT_HTTPHEADER => self::formatHeaders($call->headers),
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, $key): int {
                        $trimmed = trim($line);
                        if (str_starts_with($trimmed, 'HTTP/')) {
                            $headers[$key] = [];
                        } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                            [$name, $value] = explode(':', $trimmed, 2);
                            $name = strtolower(trim($name));
                            $value = trim($value);
                            $headers[$key][$name] = isset($headers[$key][$name]) ? $headers[$key][$name] . ', ' . $value : $value;
                        }

                        return strlen($line);
                    },
                ];
                if ($call->method === 'HEAD') {
                    $options[CURLOPT_NOBODY] = true;
                } elseif ($call->method !== 'GET') {
                    $options[CURLOPT_CUSTOMREQUEST] = $call->method;
                }
                if ($call->body !== null) {
                    $options[CURLOPT_POSTFIELDS] = $call->body;
                }
                curl_setopt_array($handle, $options);
                curl_multi_add_handle($multi, $handle);
                $transfers[$key] = [$handle, $sourceName, $limit, $boundByResolution];
            }

            $finished = [];
            if ($transfers !== []) {
                do {
                    $status = curl_multi_exec($multi, $active);
                    while (($info = curl_multi_info_read($multi)) !== false) {
                        $finished[spl_object_id($info['handle'])] = (int) $info['result'];
                    }
                    if ($active && $status === CURLM_OK && curl_multi_select($multi, 0.05) === -1) {
                        usleep(1000);
                    }
                } while ($active && $status === CURLM_OK);
                while (($info = curl_multi_info_read($multi)) !== false) {
                    $finished[spl_object_id($info['handle'])] = (int) $info['result'];
                }
            }

            foreach ($transfers as $key => [$handle, $sourceName, $limit, $boundByResolution]) {
                $id = spl_object_id($handle);
                if (isset($finished[$id])) {
                    $errorCode = $finished[$id];
                } else {
                    // The multi handle stopped before this transfer reported its completion.
                    $errorCode = curl_errno($handle);
                    if ($errorCode === 0) {
                        $errorCode = 2; // CURLE_FAILED_INIT
                    }
                }
                if ($errorCode === 0) {
                    $results[$key] = [
                        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                        'headers' => $headers[$key] ?? [],
                        'body' => (string) curl_multi_getcontent($handle),
                    ];
                } elseif ($errorCode === self::CURL_TIMEOUT) {
                    $results[$key] = $boundByResolution
                        ? DataFetchException::resolutionTimeout($sourceName)
                        : DataFetchException::timeout($sourceName, $limit);
                } else {
                    $results[$key] = DataFetchException::transport($sourceName, $errorCode);
                }
                curl_multi_remove_handle($multi, $handle);
            }
        } finally {
            curl_multi_close($multi);
        }

        // Keep the order of the input.
        $ordered = [];
        foreach (array_keys($calls) as $key) {
            $ordered[(string) $key] = $results[(string) $key];
        }

        return $ordered;
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function formatHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
