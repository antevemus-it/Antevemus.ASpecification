<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * Region of the subquery tests (forward 031, E2, Spec::inQuery()): an assigned text identifier, a
 * nullable zone and a soft delete mark without a property.
 *
 * @version 1.13.0
 */
#[Table('aspec_region')]
#[SoftDelete('deleted_at')]
class PdoRegion extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('code')]
    public string $code;

    #[Column('zone')]
    public ?string $zone;

    public function __construct(string $code, ?string $zone)
    {
        parent::__construct();
        $this->code = $code;
        $this->zone = $zone;
    }

    public function getEntityId(): string
    {
        return $this->code;
    }
}
