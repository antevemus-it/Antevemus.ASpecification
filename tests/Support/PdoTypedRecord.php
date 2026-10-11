<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use DateTime;
use DateTimeImmutable;

/**
 * Type conversion fixture of the PDO repository (forward 028, D3): one public property per column
 * type, property-level #[Id] with an assigned string identifier, a DateTime column in another time
 * zone, a mutable DateTime, an explicit Date column, int-backed and pure enums, JSON and an untyped
 * (raw) column.
 *
 * @version 1.11.0
 */
#[Table('aspec_typed')]
class PdoTypedRecord extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('id')]
    public string $code;

    #[Column('i')]
    public ?int $i = null;

    #[Column('f')]
    public ?float $f = null;

    #[Column('d', type: ColumnType::Decimal)]
    public ?string $d = null;

    #[Column('b')]
    public ?bool $b = null;

    #[Column('day', type: ColumnType::Date)]
    public ?DateTimeImmutable $day = null;

    #[Column('moment', timezone: 'America/Sao_Paulo')]
    public ?DateTimeImmutable $moment = null;

    #[Column('moment_mut')]
    public ?DateTime $momentMut = null;

    #[Column('payload')]
    public ?array $payload = null;

    #[Column('priority')]
    public ?PdoPriority $priority = null;

    #[Column('color')]
    public ?PdoColor $color = null;

    #[Column('raw')]
    public $raw = null;

    public function __construct(string $code)
    {
        parent::__construct();
        $this->code = $code;
    }

    public function getEntityId(): string
    {
        return $this->code;
    }
}
