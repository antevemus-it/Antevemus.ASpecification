<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;

/**
 * FilePerEntityRepository - Repositório que persiste um arquivo individual por entidade
 *
 * Organiza entidades em um diretório de arquivos individuais indexados pela identidade única
 * (<storageDir>/<sanitizedId>.<ext>), permitindo busca direta O(1) para UniqueEntitySpecification.
 *
 * Funcionalidades:
 * - Persistência particionada por arquivo individual
 * - Otimização O(1) de acesso direto por chave única
 * - Iteração sob demanda no sistema de arquivos
 *
 * @template T of IEntity
 * @extends AbstractFileRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FilePerEntityRepository extends AbstractFileRepository
{
    /**
     * @param string $storagePath Caminho do diretório de armazenamento
     * @param class-string<T>|string|IEntitySerializer $entityClassOrSerializer Classe da entidade ou serializador
     * @param PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer Modo de persistência ou serializador
     * @param IEntitySerializer|null $serializer Serializador
     * @param string|null $repositoryId ID do repositório
     */
    public function __construct(
        string $storagePath,
        string|IEntitySerializer $entityClassOrSerializer = IEntity::class,
        PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer = PersistenceDefinition::ReadWrite,
        ?IEntitySerializer $serializer = null,
        ?string $repositoryId = null
    ) {
        parent::__construct($storagePath, $entityClassOrSerializer, $persistenceDefinitionOrSerializer, $serializer, $repositoryId);

        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0777, true);
        }
    }

    /**
     * Retorna o caminho do arquivo para uma entidade com o ID informado.
     *
     * @param string|int $id
     * @return string
     */
    public function getFilePath(string|int $id): string
    {
        $sanitized = FileNameSanitizer::sanitize($id);
        $ext = $this->serializer->getFileExtension();
        return rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . "{$sanitized}.{$ext}";
    }

    /**
     * {@inheritdoc}
     */
    public function load(): void
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0777, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function store(): void
    {
        // No modelo de um arquivo por entidade, os arquivos já são mantidos individualmente em disco
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $this->assertWritable();

        if ($this->persistenceDefinition === PersistenceDefinition::Transient) {
            return;
        }

        $target = $this->getFilePath($entity->getEntityId());
        $content = $this->serializer->serialize($entity);
        $this->writeAtomic($target, $content);
    }

    /**
     * {@inheritdoc}
     */
    public function putAll(array $collectionOfEntities): void
    {
        $this->assertWritable();

        foreach ($collectionOfEntities as $entity) {
            if ($entity instanceof IEntity) {
                $this->put($entity);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(IEntity $entity): void
    {
        $this->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        $this->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(IEntity $entity): bool
    {
        $this->assertWritable();

        $target = $this->getFilePath($entity->getEntityId());
        if (file_exists($target)) {
            $this->withExclusiveLock($target, function () use ($target): void {
                if (file_exists($target)) {
                    @unlink($target);
                }
            });
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->assertWritable();

        $matching = $this->findAllEntitiesSpecifiedBy($specification);
        $count = 0;
        foreach ($matching as $entity) {
            if ($this->remove($entity)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->assertWritable();

        $files = $this->scanEntityFiles();
        foreach ($files as $filePath) {
            $this->withExclusiveLock($filePath, function () use ($filePath): void {
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            });
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        if (!$this->isReadable()) {
            return null;
        }

        // Otimização O(1) para UniqueEntitySpecification
        if ($specification instanceof UniqueEntitySpecification) {
            $expectedId = $specification->getExpectedId();
            $target = $this->getFilePath($expectedId);
            if (!file_exists($target)) {
                return null;
            }

            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($target);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                return $entity;
            }
            return null;
        }

        foreach ($this->scanEntityFiles() as $filePath) {
            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($filePath);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        if (!$this->isReadable()) {
            return [];
        }

        // Otimização O(1) se for busca única
        if ($specification instanceof UniqueEntitySpecification) {
            $single = $this->findSingleEntitySpecifiedBy($specification);
            return $single !== null ? [$single] : [];
        }

        $result = [];
        foreach ($this->scanEntityFiles() as $filePath) {
            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($filePath);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        if (!$this->isReadable()) {
            return;
        }

        foreach ($this->scanEntityFiles() as $filePath) {
            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($filePath);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                yield $entity;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        return count($this->findAllEntitiesSpecifiedBy($specification));
    }

    /**
     * {@inheritdoc}
     */
    public function contains(IEntity $entity): bool
    {
        if (!$this->isReadable()) {
            return false;
        }

        $target = $this->getFilePath($entity->getEntityId());
        return file_exists($target);
    }

    /**
     * Retorna a quantidade total de arquivos no diretório.
     */
    public function countTotal(): int
    {
        return count($this->scanEntityFiles());
    }

    /**
     * Varre os arquivos no diretório de armazenamento que possuem a extensão configurada.
     *
     * @return array<string>
     */
    protected function scanEntityFiles(): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $ext = $this->serializer->getFileExtension();
        $pattern = rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . "*.{$ext}";
        $files = glob($pattern);

        return $files !== false ? $files : [];
    }

    /**
     * Lê e desserializa uma entidade a partir de um arquivo com lock compartilhado.
     *
     * @param string $filePath
     * @return IEntity|null
     */
    protected function readEntityFromFile(string $filePath): ?IEntity
    {
        return $this->withSharedLock($filePath, function () use ($filePath): ?IEntity {
            if (!file_exists($filePath)) {
                return null;
            }
            $content = file_get_contents($filePath);
            if ($content === false || trim($content) === "") {
                return null;
            }
            /** @var T $entity */
            $entity = $this->serializer->deserialize($content, $this->entityClass);
            return $entity;
        });
    }
}
