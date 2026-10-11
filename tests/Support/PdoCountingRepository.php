<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;

/**
 * PdoRepository that counts hydrated entities (forward 028, RN-05: count() builds no entity).
 *
 * @version 1.11.0
 */
class PdoCountingRepository extends PdoRepository
{
    public int $hydrations = 0;

    protected function hydrate(array $row): IEntity
    {
        $this->hydrations++;

        return parent::hydrate($row);
    }
}
