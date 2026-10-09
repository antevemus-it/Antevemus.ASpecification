<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;

/**
 * Minimal entity with a sequential integer id, used by the lazy-source tests (RN-04 of 1.5.0).
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class LazyProbeEntity extends AbstractEntity
{
    public function __construct(public int $n = 0, public string $title = '')
    {
        parent::__construct();
    }

    public function getEntityId(): mixed
    {
        return $this->n;
    }
}
