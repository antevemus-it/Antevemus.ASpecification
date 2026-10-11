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
 * Target of the ManyToMany fixture of Module 26 (forward 032, D1): an assigned identifier and soft
 * delete (a soft-removed tag is left out of a loaded collection, its link stays); the identifier may be
 * null, to show that a target without one is refused.
 *
 * @version 1.14.0
 */
#[Table('aspec_rel_tag')]
#[SoftDelete('deleted_at')]
class PdoShopTag extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('id')]
    public ?int $id;

    #[Column('name')]
    public string $name;

    public function __construct(?int $id, string $name)
    {
        parent::__construct();
        $this->id = $id;
        $this->name = $name;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
