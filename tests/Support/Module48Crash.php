<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use RuntimeException;

/**
 * A process of Module 48 (2.14.0, forward 063) dying on purpose: between the confirmation of the broker and the mark
 * of the outbox, or between the commit of a handler and the acknowledgement.
 *
 * @version 2.14.0
 */
final class Module48Crash extends RuntimeException
{
}
