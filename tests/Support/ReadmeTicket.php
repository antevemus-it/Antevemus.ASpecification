<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\CreatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;
use DateTimeImmutable;

/**
 * The `Ticket` class of README example 17.1 (EN/pt-BR), as written (renamed so that it cannot collide
 * with an application class). Used by the transcription of example 17.1 (Module 25).
 *
 * @version 1.13.0
 */
#[Table('ticket', timezone: 'America/Sao_Paulo', booleanAs: BooleanStorage::Integer)]
#[SoftDelete('deleted_at')]
class ReadmeTicket extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[CreatedAt] public ?DateTimeImmutable $createdAt = null;
    #[UpdatedAt] public ?DateTimeImmutable $updatedAt = null;

    public function __construct(
        #[Column] public string $subject,
        #[Column] public bool $urgent = false,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
