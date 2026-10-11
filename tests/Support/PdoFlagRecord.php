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
 * Boolean storage fixture (forward 031, D2): `active` is a SMALLINT column stored as 1/0 on every
 * driver (declared on the column), `native` a real boolean column (Native, the default).
 *
 * @version 1.13.0
 */
#[Table('aspec_flag')]
class PdoFlagRecord extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    private int $id;

    #[Column(booleanAs: BooleanStorage::Integer)]
    public ?bool $active = null;

    #[Column]
    public ?bool $native = null;

    #[Column]
    public ?string $label = null;

    public function __construct(int $id, ?bool $active = null, ?bool $native = null, ?string $label = null)
    {
        parent::__construct();
        $this->id = $id;
        $this->active = $active;
        $this->native = $native;
        $this->label = $label;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
