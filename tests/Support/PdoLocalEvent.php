<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use DateTimeImmutable;

/**
 * Time zone fixture (forward 031, D3): the table holds local time without offset (as the Adianti
 * tables); `happenedAt` follows the zone of the mapping, `utcAt` declares UTC and wins over it.
 *
 * @version 1.13.0
 */
#[Table('aspec_local_event', timezone: 'America/Sao_Paulo')]
class PdoLocalEvent extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    private int $id;

    #[Column]
    public ?DateTimeImmutable $happenedAt = null;

    #[Column(timezone: 'UTC')]
    public ?DateTimeImmutable $utcAt = null;

    #[Column(type: ColumnType::Date)]
    public ?DateTimeImmutable $day = null;

    public function __construct(int $id, ?DateTimeImmutable $happenedAt = null, ?DateTimeImmutable $utcAt = null)
    {
        parent::__construct();
        $this->id = $id;
        $this->happenedAt = $happenedAt;
        $this->utcAt = $utcAt;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
