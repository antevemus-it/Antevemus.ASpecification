<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use DateTimeImmutable;

/**
 * The `Invoice` class of README example 18.1 (EN/pt-BR), as written (renamed so that it cannot
 * collide with an application class). Used by the transcription of example 18.1 (Module 24).
 *
 * @version 1.12.0
 */
#[Table('invoice')]
class ReadmeInvoice extends AbstractEntity
{
    #[Column] public bool $paid = false;

    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private string $number,
        #[Column('due_date')] public DateTimeImmutable $dueDate,
    ) {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}
