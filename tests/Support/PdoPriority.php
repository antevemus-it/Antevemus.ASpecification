<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

/**
 * Int-backed enum of the PDO repository type fixture (forward 028).
 *
 * @version 1.11.0
 */
enum PdoPriority: int
{
    case Low = 1;
    case High = 9;
}
