<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Serialization;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentEntity;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Repositories\EntityPersistenceMetaData;
use Antevemus\ASpecification\Repositories\PersistentEntity;

/**
 * JsonEntitySerializer - Serializador de entidades em formato JSON estruturado
 *
 * Converte entidades DDD em documentos JSON legíveis por humanos e reconstitui
 * instâncias completas via reflexão profunda (inclusive propriedades privadas
 * e readonly), com suporte complementar a \JsonSerializable.
 *
 * Funcionalidades:
 * - Serialização formatada em JSON com preservação de tipo (__class)
 * - Hidratação reflexiva sem violar encapsulamento
 * - Suporte a envelopes de persistência (PersistentEntity)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Serialization
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class JsonEntitySerializer implements IEntitySerializer
{
    /**
     * Construtor do serializador JSON.
     *
     * @param string|null $defaultEntityClass Classe padrão de entidade utilizada na desserialização
     */
    public function __construct(
        private readonly ?string $defaultEntityClass = null
    ) {
    }

    /**
     * Retorna o nome da classe padrão da entidade configurada, se houver.
     *
     * @return string|null FQCN da entidade ou null
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
            throw new RepositoryException("Falha ao serializar entidade para JSON: " . json_last_error_msg());
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
            throw new RepositoryException("JSON inválido para desserialização da entidade: " . json_last_error_msg());
        }

        $targetClass = (isset($decoded["__class"]) && is_string($decoded["__class"]) && class_exists($decoded["__class"]))
            ? $decoded["__class"]
            : ($entityClass !== IEntity::class && class_exists($entityClass) ? $entityClass : ($this->defaultEntityClass ?? $entityClass));

        if (!is_a($targetClass, IEntity::class, true)) {
            throw new RepositoryException("A classe alvo {$targetClass} não implementa IEntity.");
        }

        $rawEntityData = isset($decoded["__entity_data"]) && is_array($decoded["__entity_data"])
            ? $decoded["__entity_data"]
            : $decoded;

        $entity = $this->hydrateObject($targetClass, $rawEntityData);

        // Se houver metadados de persistência salvos no envelope
        if (isset($decoded["__persistence_metadata"]) && is_array($decoded["__persistence_metadata"])) {
            $metaData = new EntityPersistenceMetaData();
            $meta = $decoded["__persistence_metadata"];
            if (isset($meta["access_count"])) {
                for ($i = 0; $i < (int)$meta["access_count"]; $i++) {
                    $metaData->incrementAccessCount();
                }
            }
            if (isset($meta["write_count"])) {
                for ($i = 0; $i < (int)$meta["write_count"]; $i++) {
                    $metaData->incrementWriteCount();
                }
            }
            return new PersistentEntity($entity, $metaData);
        }

        return $entity;
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
     * Extrai os dados internos de uma entidade em array associativo.
     *
     * @param IEntity $entity
     * @return array<string, mixed>
     */
    private function extractEntityData(IEntity $entity): array
    {
        if ($entity instanceof IPersistentEntity) {
            $inner = $entity->getEntity();
            $meta = $entity->getMetaData();
            return [
                "__class" => $inner::class,
                "__is_persistent_envelope" => true,
                "__persistence_metadata" => [
                    "access_count" => $meta->getAccessCount(),
                    "write_count" => $meta->getWriteCount(),
                    "last_access_at" => $meta->getLastAccessTime()?->format(\DateTimeInterface::ATOM),
                    "last_write_at" => $meta->getLastWriteTime()?->format(\DateTimeInterface::ATOM),
                ],
                "__entity_data" => $this->extractProperties($inner),
            ];
        }

        $props = $this->extractProperties($entity);
        $props["__class"] = $entity::class;
        return $props;
    }

    /**
     * Extrai propriedades recursivamente via Reflection.
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
     * Normaliza valores para tipos serializáveis em JSON.
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
     * Hidrata um objeto a partir dos dados do array via Reflection.
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
     * Desnormaliza valor respeitando a tipagem da propriedade.
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
