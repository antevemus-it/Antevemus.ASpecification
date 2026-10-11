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
 * The `Member` class of README example 17.1 (EN/pt-BR), as written (renamed so that it cannot collide
 * with an application class). Used by the transcription of example 17.1 (Module 25).
 *
 * @version 1.14.0
 */
#[Table('member')]
class ReadmeMember extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column(booleanAs: BooleanStorage::Char)] public bool $active = true,
        #[Column(booleanAs: BooleanStorage::Char, trueValue: 'S', falseValue: 'N')] public ?bool $admin = null,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}
