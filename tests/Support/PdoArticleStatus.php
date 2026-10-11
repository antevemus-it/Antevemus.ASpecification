<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

/**
 * Backed enum of the PDO repository fixture (forward 028).
 *
 * @version 1.11.0
 */
enum PdoArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
