<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Attributes\Specifiable;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * Query exposure fixture of the PDO repository (forward 028, RN-04): a mapped private column with no
 * getter and no #[Specifiable] (persisted, never queryable by name, as in memory) next to a marked one.
 *
 * @version 1.11.0
 */
#[Table('aspec_secret')]
class PdoSecretHolder extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    private int $id;

    #[Column('secret')]
    private string $secret;

    #[Specifiable]
    #[Column('marked')]
    private string $marked;

    public function __construct(int $id, string $secret, string $marked)
    {
        parent::__construct();
        $this->id = $id;
        $this->secret = $secret;
        $this->marked = $marked;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }

    public function revealForTest(): string
    {
        return $this->secret;
    }
}
