<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * The `Customer` class of README example 17.3 (EN/pt-BR), as written (renamed so that it cannot
 * collide with an application class). Used by the transcription of example 17.3 (Module 26).
 *
 * @version 1.13.0
 */
#[Table('customer')]
class ReadmeCustomer extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column] public string $name,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
