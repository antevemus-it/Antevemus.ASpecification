<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractUlidEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * The `Sale` class of README example 17.2 (EN/pt-BR), as written (renamed so that it cannot collide
 * with an application class). Used by the transcription of example 17.2 (Module 25).
 *
 * @version 1.13.0
 */
#[Table('sale')]
#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]
class ReadmeSale extends AbstractUlidEntity
{
    public function __construct(
        #[Column('product_id')] public int $productId,
        #[Column] public ?string $region,
        #[Column(type: ColumnType::Decimal)] public string $amount,
        #[Column] public int $quantity = 1,
    ) {
        parent::__construct();
    }
}
