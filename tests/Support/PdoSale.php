<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;
use DateTimeImmutable;

/**
 * Sale of the set-based operations tests (forward 031, E2): an assigned integer identifier, nullable
 * columns of every aggregated type (string, integer, decimal, float, date), a version counter, a
 * modification time and a soft delete mark mapped to a property; all public so that a specification
 * reads them by name.
 *
 * @version 1.13.0
 */
#[Table('aspec_sale')]
#[Version(column: 'version', property: 'version')]
class PdoSale extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('id')]
    public int $id;

    #[Column('region')]
    public ?string $region;

    #[Column('product')]
    public ?string $product;

    #[Column('qty')]
    public ?int $qty;

    #[Column('amount', type: ColumnType::Decimal)]
    public ?string $amount;

    #[Column('weight')]
    public ?float $weight;

    #[Column('sold_on', type: ColumnType::Date)]
    public ?DateTimeImmutable $soldOn;

    #[UpdatedAt('updated_at')]
    public ?DateTimeImmutable $updatedAt = null;

    #[SoftDelete('deleted_at')]
    public ?DateTimeImmutable $deletedAt = null;

    public function __construct(
        int $id,
        ?string $region,
        ?string $product = null,
        ?int $qty = null,
        ?string $amount = null,
        ?float $weight = null,
        ?DateTimeImmutable $soldOn = null
    ) {
        parent::__construct();
        $this->id = $id;
        $this->region = $region;
        $this->product = $product;
        $this->qty = $qty;
        $this->amount = $amount;
        $this->weight = $weight;
        $this->soldOn = $soldOn;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
