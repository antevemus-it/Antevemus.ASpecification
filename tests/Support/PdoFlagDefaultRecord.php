<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * Boolean storage fixture with the default on the table (forward 031, D2): `active` follows
 * `#[Table(booleanAs: Integer)]`, `native` declares Native and wins over it.
 *
 * @version 1.13.0
 */
#[Table('aspec_flag', booleanAs: BooleanStorage::Integer)]
class PdoFlagDefaultRecord extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    private int $id;

    #[Column]
    public ?bool $active = null;

    #[Column(booleanAs: BooleanStorage::Native)]
    public ?bool $native = null;

    #[Column]
    public ?string $label = null;

    public function __construct(int $id, ?bool $active = null, ?bool $native = null)
    {
        parent::__construct();
        $this->id = $id;
        $this->active = $active;
        $this->native = $native;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
