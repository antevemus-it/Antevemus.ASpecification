<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Serialization;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Repositories\EntityPersistenceMetaData;
use Antevemus\ASpecification\Repositories\PersistentEntity;

/**
 * PhpNativeEntitySerializer - Serializador de entidades via serialize/unserialize nativo
 *
 * Provê máxima velocidade e suporte integral a tipos do PHP, com proteção
 * rigorosa contra injeção de objetos arbitrários via allowed_classes.
 *
 * Funcionalidades:
 * - Serialização nativa de alta velocidade
 * - Desserialização restrita às classes permitidas
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Serialization
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PhpNativeEntitySerializer implements IEntitySerializer
{
    /** @var array<class-string> */
    private readonly array $additionalAllowedClasses;

    /**
     * @param array<class-string>|class-string $allowedClassesOrEntityClass Classes permitidas ou classe principal
     * @param array<class-string> $extraClasses Classes adicionais permitidas
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
            throw new RepositoryException("Falha ao desserializar entidade usando PHP nativo ou objeto não implementa IEntity.");
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
