<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Repositories\InMemoryRepository;

/**
 * InMemoryRepository that counts calls to getAll() and to iterateAllEntitiesSpecifiedBy()
 * (RN-04 of 1.5.0): the lazy bridge must never call getAll() and must ask for a new generator on
 * every traversal.
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class InstrumentedInMemoryRepository extends InMemoryRepository
{
    public int $getAllCalls = 0;
    public int $iterateCalls = 0;

    public function getAll(): array
    {
        $this->getAllCalls++;
        return parent::getAll();
    }

    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        $this->iterateCalls++;
        return parent::iterateAllEntitiesSpecifiedBy($specification);
    }
}
