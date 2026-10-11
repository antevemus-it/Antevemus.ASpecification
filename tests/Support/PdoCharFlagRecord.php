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
 * Booleans stored as text (forward 032, D4): the table default is BooleanStorage::Char ('Y'/'N', the
 * CHAR(1) flags of the Adianti tables), `removed` declares 'S'/'N' on the column and `answer` the
 * longer 'True'/'False' (compiled with LOWER()). All public, so that a specification reads them.
 *
 * @version 1.14.0
 */
#[Table('aspec_char_flag', booleanAs: BooleanStorage::Char)]
class PdoCharFlagRecord extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('id')]
    public int $id;

    #[Column]
    public ?bool $active;

    #[Column(trueValue: 'S', falseValue: 'N')]
    public ?bool $removed;

    #[Column(trueValue: 'True', falseValue: 'False')]
    public ?bool $answer;

    public function __construct(int $id, ?bool $active = null, ?bool $removed = null, ?bool $answer = null)
    {
        parent::__construct();
        $this->id = $id;
        $this->active = $active;
        $this->removed = $removed;
        $this->answer = $answer;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
