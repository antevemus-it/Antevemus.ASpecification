<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * Product of the relationship tests (forward 031, E3, D7): an assigned text identifier, referenced
 * by the order items (nested eager loading, `items.product`).
 *
 * @version 1.13.0
 */
#[Table('aspec_rel_product')]
class PdoShopProduct extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('sku')]
    public string $sku;

    #[Column('title')]
    public string $title;

    public function __construct(string $sku, string $title)
    {
        parent::__construct();
        $this->sku = $sku;
        $this->title = $title;
    }

    public function getEntityId(): string
    {
        return $this->sku;
    }
}
