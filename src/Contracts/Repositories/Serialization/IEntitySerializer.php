<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories\Serialization;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IEntitySerializer - Agnostic Contract for Entity Serialization and Deserialization
 *
 * Defines the standardized interface for bidirectional conversion between
 * IEntity instances and string representations (JSON, native binary serialization, etc.),
 * ensuring type fidelity, values, and unique identity preservation.
 *
 * Features:
 * - Entity serialization to formatted string
 * - Deserialization from string to target concrete entity class instance
 * - Provision of format metadata (Content-Type and recommended file extension)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories\Serialization
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEntitySerializer
{
    /**
     * Serializes an entity to a string representation.
     *
     * @param IEntity $entity Entity to serialize
     * @return string Serialized representation
     */
    public function serialize(IEntity $entity): string;

    /**
     * Deserializes a string, reconstructing the target entity instance.
     *
     * @template T of IEntity
     * @param string $data Serialized payload
     * @param class-string<T> $entityClass Concrete entity class name
     * @return T
     */
    public function deserialize(string $data, string $entityClass): IEntity;

    /**
     * Returns the MIME Content-Type of the serialized representation.
     *
     * @return string
     */
    public function getContentType(): string;

    /**
     * Returns the recommended default file extension (e.g. json, bin).
     *
     * @return string
     */
    public function getFileExtension(): string;
}
