<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Serialization;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Repositories\EntityPersistenceMetaData;
use Antevemus\ASpecification\Repositories\PersistentEntity;

/**
 * PhpNativeEntitySerializer - Entity Serializer via Native serialize/unserialize
 *
 * Provides maximum throughput and full support for PHP types, with strict
 * protection against arbitrary object injection via allowed_classes.
 *
 * Features:
 * - High-speed native serialization
 * - Safe deserialization restricted to allowed classes whitelist
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Serialization
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PhpNativeEntitySerializer implements IEntitySerializer
{
    /** @var array<class-string> */
    private readonly array $additionalAllowedClasses;

    /**
     * @param array<class-string>|class-string $allowedClassesOrEntityClass Allowed classes whitelist or primary entity class
     * @param array<class-string> $extraClasses Additional allowed classes
     */
    public function __construct(
        array|string $allowedClassesOrEntityClass = [],
        array $extraClasses = []
    ) {
        $base = is_string($allowedClassesOrEntityClass)
            ? [$allowedClassesOrEntityClass]
            : $allowedClassesOrEntityClass;
        $this->additionalAllowedClasses = array_values(array_unique(array_merge($base, $extraClasses)));
    }

    /**
     * {@inheritdoc}
     */
    public function serialize(IEntity $entity): string
    {
        return serialize($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function deserialize(string $data, string $entityClass): IEntity
    {
        $allowed = array_values(array_unique(array_merge(
            [
                $entityClass,
                PersistentEntity::class,
                EntityPersistenceMetaData::class,
                \DateTimeImmutable::class,
                \DateTime::class,
            ],
            $this->additionalAllowedClasses
        )));

        $obj = @unserialize($data, ["allowed_classes" => $allowed]);
        if (!($obj instanceof IEntity)) {
            throw new RepositoryException("Failed to deserialize entity using native PHP or object does not implement IEntity.");
        }

        return $obj;
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType(): string
    {
        return "application/x-php-serialized";
    }

    /**
     * {@inheritdoc}
     */
    public function getFileExtension(): string
    {
        return "bin";
    }
}
