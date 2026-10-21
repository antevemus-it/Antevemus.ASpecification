<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractUUIDEntity;

/**
 * Entity of the write-behind tests (Module 42, Domian parity of INMEMORY_AND_FILE, the child process of
 * persistOnShutdown()) and the `Customer` of example 5.1: a title and a counter.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
class WriteBehindEntity extends AbstractUUIDEntity
{
    public function __construct(public string $title = 'item', public int $counter = 0)
    {
        parent::__construct();
    }
}
