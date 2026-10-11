<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToOne;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use DateTimeImmutable;

/**
 * Order item of the relationship tests (forward 031, E3, D7): a database-generated identifier, the
 * foreign key of its order with a back reference, a reference to its product, and a soft delete mark
 * mapped to a property (an orphan is marked, not deleted; a hard removal of its order deletes it).
 *
 * @version 1.13.0
 */
#[Table('aspec_rel_order_item')]
class PdoShopOrderItem extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    #[Column('id')]
    public ?int $id = null;

    #[Column('order_id')]
    public ?int $orderId = null;

    #[ManyToOne(PdoShopOrder::class, 'order_id')]
    public ?PdoShopOrder $order = null;

    #[Column('product_sku')]
    public ?string $productSku = null;

    #[ManyToOne(PdoShopProduct::class, 'product_sku')]
    public ?PdoShopProduct $product = null;

    #[Column('qty')]
    public int $qty;

    #[SoftDelete('deleted_at')]
    public ?DateTimeImmutable $deletedAt = null;

    public function __construct(int $qty, ?PdoShopProduct $product = null)
    {
        parent::__construct();
        $this->qty = $qty;
        $this->product = $product;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
