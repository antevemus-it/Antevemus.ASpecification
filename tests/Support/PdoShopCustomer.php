<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\OneToMany;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * Customer of the relationship tests (forward 031, E3, D7): an assigned identifier, a soft delete
 * mark without a property, and a read-only (not cascaded) collection of its orders.
 *
 * @version 1.13.0
 */
#[Table('aspec_rel_customer')]
#[SoftDelete('deleted_at')]
class PdoShopCustomer extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('id')]
    public int $id;

    #[Column('name')]
    public string $name;

    #[Column('country')]
    public string $country;

    /** @var list<PdoShopOrder> Aggregation: read with with('orders'), never cascaded */
    #[OneToMany(PdoShopOrder::class, mappedBy: 'customerId')]
    public array $orders = [];

    public function __construct(int $id, string $name, string $country)
    {
        parent::__construct();
        $this->id = $id;
        $this->name = $name;
        $this->country = $country;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
