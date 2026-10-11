<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractUlidEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\CreatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;
use DateTimeImmutable;

/**
 * Lifecycle fixture (forward 031, D4): creation and modification timestamps, and a soft delete
 * column that is NOT mapped to a property (the entity never sees it). ULID identity of the parent.
 *
 * @version 1.13.0
 */
#[Table('aspec_note')]
#[SoftDelete('deleted_at')]
#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]
class PdoAuditedNote extends AbstractUlidEntity
{
    #[Column]
    public string $title;

    #[Column]
    public int $score;

    #[CreatedAt]
    private ?DateTimeImmutable $createdAt = null;

    #[UpdatedAt]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct(string $title, int $score = 0, ?DateTimeImmutable $createdAt = null)
    {
        parent::__construct();
        $this->title = $title;
        $this->score = $score;
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
