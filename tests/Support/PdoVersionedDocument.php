<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;

/**
 * Optimistic locking fixture of the PDO repository (forward 028, D6): database-generated integer
 * identifier and the version counter inherited from AbstractEntity, mapped with class-level
 * #[Version(property: 'version')].
 *
 * @version 1.11.0
 */
#[Table('aspec_document')]
#[Version(column: 'version', property: 'version')]
class PdoVersionedDocument extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('title')]
    private string $title;

    public function __construct(string $title)
    {
        parent::__construct();
        $this->title = $title;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
