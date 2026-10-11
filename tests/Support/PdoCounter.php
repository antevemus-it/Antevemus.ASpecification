<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;

/**
 * PDO repository fixture without attributes (forward 028): mapped with the explicit
 * EntityMapping::for() builder, integer identifier, private properties only, optional lock counter.
 * Used by the identity strategy and transaction tests.
 *
 * @version 1.11.0
 */
class PdoCounter extends AbstractEntity
{
    public static int $constructed = 0;

    private ?int $id;

    private string $label;

    private ?int $lockVersion = null;

    public function __construct(string $label, ?int $id = null)
    {
        parent::__construct();
        self::$constructed++;
        $this->label = $label;
        $this->id = $id;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getLockVersion(): ?int
    {
        return $this->lockVersion;
    }
}
