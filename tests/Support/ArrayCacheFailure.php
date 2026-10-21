<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Psr\SimpleCache\CacheException;
use RuntimeException;

/**
 * ArrayCacheFailure - What ArrayCache throws when it is told to fail (a cache that is down).
 *
 * @version 2.8.0
 */
final class ArrayCacheFailure extends RuntimeException implements CacheException
{
}
