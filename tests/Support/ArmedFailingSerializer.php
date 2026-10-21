<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;

/**
 * JsonEntitySerializer with an injected failure (Module 42, durability of persist()): once armed, the
 * serialization of the entity whose title is $failOnTitle throws, until disarmed. Every serialization is
 * counted.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class ArmedFailingSerializer extends JsonEntitySerializer
{
    public ?string $failOnTitle = null;
    public int $serializations = 0;

    public function serialize(IEntity $entity): string
    {
        $this->serializations++;
        if ($this->failOnTitle !== null && $entity instanceof WriteBehindEntity && $entity->title === $this->failOnTitle) {
            throw new \RuntimeException("injected failure while serializing \"{$entity->title}\"");
        }

        return parent::serialize($entity);
    }
}
