<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IHumanReadableFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\ITextualFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Repositories\AbstractRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Repositories\EntityPersistenceMetaData;

/**
 * AbstractFileRepository - Classe abstrata base para repositórios persistentes em arquivo
 *
 * Centraliza o gerenciamento de arquivos em disco, serialização agnóstica via
 * IEntitySerializer, locks cooperativos (flock) e a aplicação estrita das
 * regras de ciclo de vida e permissões de PersistenceDefinition (RN-01).
 *
 * Funcionalidades:
 * - Validação estrita de modos ReadOnly, WriteOnly, ReadWrite e Snapshot
 * - Gravação atômica com arquivo temporário e substituição instantânea (rename)
 * - Integração nativa com promoção fluente de particionamento (Módulo 4)
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPersistentRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractFileRepository extends AbstractRepository implements
    IPersistentRepository,
    ITextualFormatRepository,
    IHumanReadableFormatRepository
{
    use FileLockTrait;

    protected readonly IEntitySerializer $serializer;
    protected readonly string $entityClass;
    protected readonly PersistenceDefinition $persistenceDefinition;
    protected readonly string $repositoryId;

    /**
     * @param string $storagePath Caminho do arquivo ou diretório de armazenamento
     * @param class-string<T>|string|IEntitySerializer $entityClassOrSerializer Classe da entidade ou serializador plugável
     * @param PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer Modo de persistência ou serializador
     * @param IEntitySerializer|null $serializer Serializador plugável (padrão: JsonEntitySerializer)
     * @param string|null $repositoryId Identificador único do repositório
     */
    public function __construct(
        protected readonly string $storagePath,
        string|IEntitySerializer $entityClassOrSerializer = IEntity::class,
        PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer = PersistenceDefinition::ReadWrite,
        ?IEntitySerializer $serializer = null,
        ?string $repositoryId = null
    ) {
        if ($entityClassOrSerializer instanceof IEntitySerializer) {
            $this->serializer = $entityClassOrSerializer;
            $this->entityClass = ($entityClassOrSerializer instanceof JsonEntitySerializer && $entityClassOrSerializer->getDefaultEntityClass() !== null)
                ? $entityClassOrSerializer->getDefaultEntityClass()
                : IEntity::class;
            $this->persistenceDefinition = $persistenceDefinitionOrSerializer instanceof PersistenceDefinition
                ? $persistenceDefinitionOrSerializer
                : PersistenceDefinition::ReadWrite;
        } else {
            $this->entityClass = $entityClassOrSerializer;
            if ($persistenceDefinitionOrSerializer instanceof IEntitySerializer) {
                $this->serializer = $persistenceDefinitionOrSerializer;
                $this->persistenceDefinition = PersistenceDefinition::ReadWrite;
            } else {
                $this->persistenceDefinition = $persistenceDefinitionOrSerializer;
                $this->serializer = $serializer ?? new JsonEntitySerializer($this->entityClass);
            }
        }
        $this->repositoryId = $repositoryId ?? basename($storagePath);
    }

    /**
     * {@inheritdoc}
     */
    public function getRepositoryId(): string
    {
        return $this->repositoryId;
    }

    /**
     * {@inheritdoc}
     */
    public function getPersistenceDefinition(): PersistenceDefinition
    {
        return $this->persistenceDefinition;
    }

    /**
     * Retorna o serializador configurado.
     */
    public function getSerializer(): IEntitySerializer
    {
        return $this->serializer;
    }

    /**
     * Retorna o caminho configurado de armazenamento.
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->entityClass;
    }

    /**
     * {@inheritdoc}
     */

    /**
     * Alias de conveniência para findSingleEntitySpecifiedBy.
     */
    public function getEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        return $this->findSingleEntitySpecifiedBy($specification);
    }

    /**
     * Conta todas as entidades presentes no repositório.
     */
    public function countAllEntities(): int
    {
        return $this->countAllEntitiesSpecifiedBy(new AllEntitiesSpecification($this->entityClass));
    }

    /**
     * Cria uma partição virtual persistente vinculada a este repositório em arquivo.
     *
     * @param ISpecification|null $specification Especificação delimitadora da partição
     * @return IPartitionRepository Partição persistente criada
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        return new PersistentPartitionRepository($this, $specification, null, $this->entityClass);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->persistenceDefinition === PersistenceDefinition::Snapshot ||
            $this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
            $this->store();
        }
    }

    /**
     * Valida se operações de escrita são permitidas na definição de persistência atual.
     *
     * @throws RepositoryException Se o repositório estiver em modo ReadOnly
     */
    protected function assertWritable(): void
    {
        if ($this->persistenceDefinition === PersistenceDefinition::ReadOnly) {
            throw new RepositoryException("Operação de escrita rejeitada: o repositório '{$this->repositoryId}' está em modo ReadOnly.");
        }
    }

    /**
     * Verifica se operações de leitura são permitidas na definição de persistência atual.
     */
    protected function isReadable(): bool
    {
        return $this->persistenceDefinition !== PersistenceDefinition::WriteOnly;
    }

    /**
     * Grava conteúdo de forma atômica no arquivo destino via tempfile e rename.
     *
     * @param string $targetFile Caminho absoluto do arquivo final
     * @param string $content Conteúdo a ser gravado
     * @throws RepositoryException
     */

    /**
     * @var array<string, IEntityPersistenceMetaData>
     */
    protected array $metadataMap = [];

    /**
     * {@inheritdoc}
     */
    public function getDataDirectory(): ?string
    {
        return is_dir($this->storagePath) ? $this->storagePath : dirname($this->storagePath);
    }

    /**
     * {@inheritdoc}
     */
    public function getFormatDescription(): string
    {
        return 'File Storage (' . $this->serializer->getContentType() . ')';
    }

    /**
     * {@inheritdoc}
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        $id = (string) $entity->getEntityId();
        return $this->metadataMap[$id] ?? null;
    }

    /**
     * Registra metadados de gravação para uma entidade.
     */
    protected function recordWriteMetadata(IEntity $entity): void
    {
        $id = (string) $entity->getEntityId();
        if (!isset($this->metadataMap[$id])) {
            $this->metadataMap[$id] = new EntityPersistenceMetaData();
        } else {
            $this->metadataMap[$id]->registerWrite();
        }
    }

    /**
     * Registra metadados de leitura para uma entidade.
     */
    protected function recordReadMetadata(IEntity $entity): void
    {
        $id = (string) $entity->getEntityId();
        if (!isset($this->metadataMap[$id])) {
            $this->metadataMap[$id] = new EntityPersistenceMetaData();
        }
        $this->metadataMap[$id]->registerRead();
    }

    protected function writeAtomic(string $targetFile, string $content): void
    {
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RepositoryException("Não foi possível criar o diretório de destino: {$dir}");
            }
        }

        $tmpFile = $targetFile . ".tmp." . bin2hex(random_bytes(4));

        $this->withExclusiveLock($targetFile, function () use ($tmpFile, $targetFile, $content): void {
            $handle = @fopen($tmpFile, "wb");
            if ($handle === false) {
                throw new RepositoryException("Falha ao abrir descritor de arquivo temporário: {$tmpFile}");
            }

            $bytesWritten = fwrite($handle, $content);
            fflush($handle);
            fclose($handle);

            if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
                @unlink($tmpFile);
                throw new RepositoryException("Falha ao escrever bytes no arquivo temporário: {$tmpFile}");
            }

            if (!@rename($tmpFile, $targetFile)) {
                @unlink($tmpFile);
                throw new RepositoryException("Falha ao mover arquivo temporário para o destino final: {$targetFile}");
            }
        });
    }
}
