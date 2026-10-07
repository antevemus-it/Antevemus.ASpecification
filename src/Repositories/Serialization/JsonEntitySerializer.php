<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Serialization;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;

/**
 * JsonEntitySerializer - Structured JSON Entity Serializer
 *
 * Converts DDD entities into human-readable JSON documents and reconstructs
 * complete instances via deep reflection (including private and readonly properties),
 * with auxiliary support for \JsonSerializable.
 *
 * Features:
 * - Formatted JSON serialization with type retention (__class)
 * - Reflective hydration preserving encapsulation
 * - Entity document without persistence envelope: per-entity persistence metadata lives in the
 *   repository session (IPersistentRepository::getEntityMetaData), never in the document; a
 *   "__persistence_metadata" block written by other tools is ignored on read (BUG-20261007-5MWT)
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Serialization
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class JsonEntitySerializer implements IEntitySerializer
{
    /**
     * Constructs a JSON entity serializer.
     *
     * @param string|null $defaultEntityClass Default entity class used for deserialization fallback
     */
    public function __construct(
        private readonly ?string $defaultEntityClass = null
    ) {
    }

    /**
     * Returns the configured default entity class name, if any.
     *
     * @return string|null Entity FQCN or null
     */
    public function getDefaultEntityClass(): ?string
    {
        return $this->defaultEntityClass;
    }

    /**
     * {@inheritdoc}
     */
    public function serialize(IEntity $entity): string
    {
        $payload = $this->extractEntityData($entity);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RepositoryException("Failed to serialize entity to JSON: " . json_last_error_msg());
        }

        return $json;
    }

    /**
     * {@inheritdoc}
     */
    public function deserialize(string $data, string $entityClass): IEntity
    {
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            throw new RepositoryException("Invalid JSON for entity deserialization: " . json_last_error_msg());
        }

        $targetClass = (isset($decoded["__class"]) && is_string($decoded["__class"]) && class_exists($decoded["__class"]))
            ? $decoded["__class"]
            : ($entityClass !== IEntity::class && class_exists($entityClass) ? $entityClass : ($this->defaultEntityClass ?? $entityClass));

        if (!is_a($targetClass, IEntity::class, true)) {
            throw new RepositoryException("Target class {$targetClass} does not implement IEntity.");
        }

        // A document may wrap the entity in "__entity_data" (envelope written by other tools).
        // Any "__persistence_metadata" next to it is ignored: metadata is never part of the
        // entity document and deserialize() always returns the plain entity (BUG-20261007-5MWT).
        $rawEntityData = isset($decoded["__entity_data"]) && is_array($decoded["__entity_data"])
            ? $decoded["__entity_data"]
            : $decoded;

        return $this->hydrateObject($targetClass, $rawEntityData);
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType(): string
    {
        return "application/json";
    }

    /**
     * {@inheritdoc}
     */
    public function getFileExtension(): string
    {
        return "json";
    }

    /**
     * Extracts internal entity data into associative array.
     *
     * @param IEntity $entity
     * @return array<string, mixed>
     */
    private function extractEntityData(IEntity $entity): array
    {
        $props = $this->extractProperties($entity);
        $props["__class"] = $entity::class;
        return $props;
    }

    /**
     * Recursively extracts properties via Reflection.
     *
     * @param object $obj
     * @return array<string, mixed>
     */
    private function extractProperties(object $obj): array
    {
        if ($obj instanceof \JsonSerializable) {
            $data = $obj->jsonSerialize();
            if (is_array($data)) {
                return $data;
            }
        }

        $result = [];
        $reflection = new \ReflectionClass($obj);

        while ($reflection !== false) {
            foreach ($reflection->getProperties() as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }
                $name = $prop->getName();
                if (array_key_exists($name, $result)) {
                    continue;
                }
                $prop->setAccessible(true);
                if ($prop->isInitialized($obj)) {
                    $val = $prop->getValue($obj);
                    $result[$name] = $this->normalizeValue($val);
                }
            }
            $reflection = $reflection->getParentClass();
        }

        return $result;
    }

    /**
     * Normalizes values for JSON-serializable types.
     *
     * @param mixed $val
     * @return mixed
     */
    private function normalizeValue(mixed $val): mixed
    {
        if ($val === null || is_scalar($val)) {
            return $val;
        }
        if ($val instanceof \DateTimeInterface) {
            return [
                "__type" => "DateTime",
                "atom" => $val->format(\DateTimeInterface::ATOM),
            ];
        }
        if (is_array($val)) {
            $arr = [];
            foreach ($val as $k => $v) {
                $arr[$k] = $this->normalizeValue($v);
            }
            return $arr;
        }
        if (is_object($val)) {
            return $this->extractProperties($val);
        }
        return (string)$val;
    }

    /**
     * Hydrates an object from array data via Reflection.
     *
     * @template T of object
     * @param class-string<T> $className
     * @param array<string, mixed> $data
     * @return T
     */
    private function hydrateObject(string $className, array $data): object
    {
        $reflection = new \ReflectionClass($className);
        $obj = $reflection->newInstanceWithoutConstructor();

        $currentRef = $reflection;
        while ($currentRef !== false) {
            foreach ($currentRef->getProperties() as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }
                if ($prop->getDeclaringClass()->getName() !== $currentRef->getName()) {
                    continue;
                }
                if ($prop->isReadOnly() && $prop->isInitialized($obj)) {
                    continue;
                }
                $name = $prop->getName();
                if (array_key_exists($name, $data)) {
                    $prop->setAccessible(true);
                    $val = $this->denormalizeValue($prop, $data[$name]);
                    $prop->setValue($obj, $val);
                }
            }
            $currentRef = $currentRef->getParentClass();
        }

        return $obj;
    }

    /**
     * Denormalizes value respecting property typing.
     *
     * @param \ReflectionProperty $prop
     * @param mixed $rawVal
     * @return mixed
     */
    private function denormalizeValue(\ReflectionProperty $prop, mixed $rawVal): mixed
    {
        if ($rawVal === null) {
            return null;
        }

        $type = $prop->getType();
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();
            if (is_array($rawVal) && isset($rawVal["__type"]) && $rawVal["__type"] === "DateTime" && isset($rawVal["atom"])) {
                return new \DateTimeImmutable($rawVal["atom"]);
            }
            if ($typeName === \DateTimeInterface::class || $typeName === \DateTimeImmutable::class) {
                return new \DateTimeImmutable((string)$rawVal);
            }
            if ($typeName === \DateTime::class) {
                return new \DateTime((string)$rawVal);
            }
            if ($typeName === "int") {
                return (int)$rawVal;
            }
            if ($typeName === "float") {
                return (float)$rawVal;
            }
            if ($typeName === "string") {
                return (string)$rawVal;
            }
            if ($typeName === "bool") {
                return (bool)$rawVal;
            }
            if (class_exists($typeName) && is_array($rawVal)) {
                return $this->hydrateObject($typeName, $rawVal);
            }
        }

        return $rawVal;
    }
}
