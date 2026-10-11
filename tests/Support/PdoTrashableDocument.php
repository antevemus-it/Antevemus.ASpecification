<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;
use DateTime;
use DateTimeImmutable;

/**
 * Soft delete fixture with a version counter (forward 031, D4): database-generated identifier, the
 * soft delete instant mapped to a property, a mutable DateTime modification timestamp.
 *
 * @version 1.13.0
 */
#[Table('aspec_trash_doc')]
#[Version(column: 'version', property: 'version')]
class PdoTrashableDocument extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column]
    public string $title;

    #[SoftDelete]
    private ?DateTimeImmutable $deletedAt = null;

    #[UpdatedAt]
    private ?DateTime $updatedAt = null;

    public function __construct(string $title)
    {
        parent::__construct();
        $this->title = $title;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }
}
