<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Repositories\InMemoryRepository;

/**
 * A persistent backend that is not a file (Module 42, MemoryAsyncDelegated): its content stands for a
 * delegated store; every lifecycle call and write is recorded, and store() can be made to fail.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class RecordingPersistentStore extends InMemoryRepository implements IPersistentRepository
{
    /** @var list<string> */
    public array $calls = [];
    public bool $failOnStore = false;

    public function __construct()
    {
        parent::__construct([], 'delegated-store');
    }

    public function getRepositoryId(): string
    {
        return 'delegated-store';
    }

    public function getDataDirectory(): ?string
    {
        return null;
    }

    public function getPersistenceDefinition(): PersistenceDefinition
    {
        return PersistenceDefinition::DelegatedOnly;
    }

    public function getFormatDescription(): string
    {
        return 'recording store';
    }

    public function load(): void
    {
        $this->calls[] = 'load';
    }

    public function store(): void
    {
        $this->calls[] = 'store';
        if ($this->failOnStore) {
            throw new \RuntimeException('injected failure of the delegated store');
        }
    }

    public function close(): void
    {
        $this->calls[] = 'close';
    }

    public function put(IEntity $entity): void
    {
        $this->calls[] = 'put';
        parent::put($entity);
    }

    public function putAll(array $collectionOfEntities): void
    {
        $this->calls[] = 'putAll';
        foreach ($collectionOfEntities as $entity) {
            parent::put($entity);
        }
    }

    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        return null;
    }
}
