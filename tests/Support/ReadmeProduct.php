<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;

/**
 * The `Product` class of README example 17 (EN/pt-BR), as written (renamed so that it cannot collide
 * with an application class). Used by the transcriptions of examples 17 (Module 22) and 18 (Module 23).
 *
 * @version 1.11.0
 */
#[Table('product')]
#[Version(property: 'version')]
class ReadmeProduct extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    public function __construct(
        #[Column] public string $name,
        #[Column('unit_price', type: ColumnType::Decimal)] public string $price,
        #[Column] public bool $active = true,
        #[Column] private string $cost = '0.00',
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
