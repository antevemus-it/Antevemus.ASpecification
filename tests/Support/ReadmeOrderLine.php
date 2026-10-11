<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * The `OrderLine` class of README example 17.3 (EN/pt-BR), as written (renamed so that it cannot
 * collide with an application class). Used by the transcription of example 17.3 (Module 26).
 *
 * @version 1.13.0
 */
#[Table('order_line')]
class ReadmeOrderLine extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('order_id')] public ?int $orderId = null;

    public function __construct(
        #[Column] public string $sku,
        #[Column] public int $quantity,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
