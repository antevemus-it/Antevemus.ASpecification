<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgument;

/**
 * ArrayCacheInvalidKey - What ArrayCache throws for a key PSR-16 does not allow.
 *
 * @version 2.8.0
 */
final class ArrayCacheInvalidKey extends InvalidArgumentException implements Psr16InvalidArgument
{
}
