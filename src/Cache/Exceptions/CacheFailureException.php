<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache\Exceptions;

use RuntimeException;
use Throwable;

/**
 * CacheFailureException - A Cache Operation That Failed and Was Read as a Miss
 *
 * The caching decorators never let a cache failure reach the caller (a cache that is down, a network
 * timeout, a value that cannot be serialized, an entry that does not have the expected shape): the
 * read goes to the decorated component, as without a cache, and the failure is handed to the error
 * reporter (IFeatureFlagErrorReporter) wrapped in this exception, once per key per decorator instance.
 * `$operation` is `get`, `set`, `delete`, `read` (an entry of an unexpected shape) or `version` (the
 * catalog version could not be read); the original failure, when there is one, is the previous exception.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CacheFailureException extends RuntimeException
{
    /**
     * @param string $operation `get`, `set`, `delete`, `read` or `version`
     * @param string $key The cache key (the first one, for an operation over several keys)
     * @param string $detail What happened
     * @param Throwable|null $previous The failure of the cache, when there is one
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $key,
        string $detail,
        ?Throwable $previous = null
    ) {
        parent::__construct(sprintf('Cache %s of key "%s" failed, read as a miss: %s', $operation, $key, $detail), previous: $previous);
    }
}
