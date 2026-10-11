<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToOne;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\OneToMany;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;

/**
 * Order of the relationship tests (forward 031, E3, D7), mapped by attributes: a database-generated
 * identifier, a version counter, NO soft delete (its removal deletes the row, so its composed items
 * are deleted), a reference to its customer (the foreign key mapped to customerId) and a cascaded
 * collection of items whose mappedBy is the items' back reference.
 *
 * @version 1.13.0
 */
#[Table('aspec_rel_order')]
class PdoShopOrder extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    #[Column('id')]
    public ?int $id = null;

    #[Column('number')]
    public string $number;

    #[Column('customer_id')]
    public ?int $customerId = null;

    #[ManyToOne(PdoShopCustomer::class, 'customer_id')]
    public ?PdoShopCustomer $customer = null;

    /** @var list<PdoShopOrderItem> Composition */
    #[OneToMany(PdoShopOrderItem::class, mappedBy: 'order', cascade: true)]
    public array $items = [];

    #[Version]
    public ?int $version = null;

    /**
     * @param list<PdoShopOrderItem> $items
     */
    public function __construct(string $number, ?PdoShopCustomer $customer = null, array $items = [])
    {
        parent::__construct();
        $this->number = $number;
        $this->customer = $customer;
        $this->items = $items;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
