<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * Row of the null semantics parity test (1.11.0): an assigned integer identifier and three nullable
 * columns, two integers and one string, all public so that a specification reads them by name in
 * memory, in SQL and in the PdoRepository.
 *
 * @version 1.11.0
 */
#[Table('aspec_null_parity')]
class PdoNullParityRow extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('id')]
    public int $id;

    #[Column('n')]
    public ?int $n;

    #[Column('m')]
    public ?int $m;

    #[Column('s')]
    public ?string $s;

    public function __construct(int $id, ?int $n, ?int $m, ?string $s)
    {
        parent::__construct();
        $this->id = $id;
        $this->n = $n;
        $this->m = $m;
        $this->s = $s;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
