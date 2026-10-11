<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;

/**
 * PDO repository fixture with a nullable string identifier (forward 028, D5): the repository fills
 * it with a ULID or a UUIDv7 when the entity is first put. Mapped with the explicit builder.
 *
 * @version 1.11.0
 */
class PdoTag extends AbstractEntity
{
    private ?string $id = null;

    public function __construct(private string $name)
    {
        parent::__construct();
    }

    public function getEntityId(): ?string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
