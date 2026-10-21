# Decoupled Persistence & Hybrid L1/L2 Cache Tiering

### 5. Decoupled Persistence & Hybrid L1/L2 Cache Tiering

```php
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;

// High-speed RAM read performance (L1) with durable disk persistence (L2)
$repo = InMemoryAndFileRepository::create(
    storagePath: '/var/data/customers.json',
    serializer: new JsonEntitySerializer(Customer::class)
);

$repo->put($newCustomer); // Stored in RAM and synchronized to disk atomically
```

The document is JSON whatever the serializer, and every entity carries its persistence metadata (`__persistence_metadata`: read and write counters, first and last read and write) next to its data, so `getEntityMetaData()` answers the same in a new process (since v2.0.0).

### 5.1. Write-Behind Hybrid Repository (Module 42)

The `PersistenceDefinition` given to `InMemoryAndFileRepository` chooses how a write reaches the storage. Since v2.8.0 the two asynchronous cases of Domian make it **write-behind**: a write touches the cache only, and the storage is written when you call `persist()`.

| Mode | A write (`put()`, `update()`, `remove()`, `removeAll()`, `clear()`) | `persist()` / `store()` | `load()` | `close()` |
| :--- | :--- | :--- | :--- | :--- |
| `ReadWrite` (the default) | the cache and the file, in the same call (write-through) | rewrites the file | reloads the cache from the file | writes the file |
| `Snapshot` | the cache and the backend's memory | writes the file | reloads the cache from the file | writes the file |
| `ReadOnly` | refused (`RepositoryException`) | refused | reloads the cache from the file | writes nothing |
| `MemoryAsyncFile` (Domian `INMEMORY_AND_FILE`) | the cache only; counted by `pendingWrites()` | writes the whole cache at once | reloads the cache and **discards** what was not persisted | persists when `isDirty()` |
| `MemoryAsyncDelegated` (Domian `INMEMORY_AND_DELEGATED`) | the cache only, over any persistent backend | `removeAll()` + `putAll()` + `store()` on the backend | the same as above | the same as above, then closes the backend |

The other cases of the enum pass each write to the backend, which applies its own mode, as before v2.8.0.

In the two write-behind modes:

- **Queries answer from the cache**, pending writes included. `isDirty()` tells whether anything is pending; `pendingWrites()` counts one per entity put, updated or removed (`putAll()` of ten entities counts ten, a `remove()` that finds nothing counts nothing).
- **`persist()` writes the whole cache.** Over a `SingleFileRepository` it is one write of the document: a temporary file renamed over it. When it fails, the exception reaches you and nothing is lost: the cache and the pending count stay as they were, the previous document is intact, and the next `persist()` writes everything. Over a `FilePerEntityRepository` each file is written the same way (atomic per file, not for the whole directory), and the files of the removed entities are deleted.
- **`load()` reloads from the storage and discards what was not persisted**, as Domian does. A repository built with `new` starts empty until its first `load()`; `create()` loads it for you. A `persist()` before any `load()` replaces the storage with the cache.
- **The repository record**, `getRepositoryMetaData()`, counts the loads (reads) and the persists (writes) of the whole repository; `put()` and the queries do not touch it. It is stored with the content (the `__repository_metadata` key of the single document, or `_repository.json` in the directory of a `FilePerEntityRepository`) and read back by `load()`. A file written before v2.8.0, or by a write-through repository, has no record and loads as before; a write-through repository keeps the record it finds. The per-entity metadata of example 5 counts one write per `persist()` for each entity written since the previous one.
- **`persistEvery($writes)`** persists whenever the pending writes reach that number (checked after each operation, so a `putAll()` past it persists once, after the batch); `0` turns it off. **`persistOnShutdown()`** persists at the end of the script if anything is pending: PHP runs it at a normal end, not when the process is killed (`SIGKILL`) or dies before it, so a write you cannot lose still calls `persist()`. Both are refused outside the write-behind modes.
- **`makePartition()`** returns a volatile partition: it has no `load()`/`store()`/`close()` of its own, and its writes go through the repository, so they are pending there and reach the storage with its next `persist()`.

```php
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;

$storagePath = '/var/data/customers.json';

$repo = InMemoryAndFileRepository::create(
    storagePath: $storagePath,
    serializer: new JsonEntitySerializer(Customer::class),
    persistenceDefinition: PersistenceDefinition::MemoryAsyncFile
);

for ($i = 1; $i <= 1000; $i++) {
    $repo->put(new Customer("Customer {$i}"));
}

$repo->pendingWrites();                                  // 1000
$repo->countAllEntities();                               // 1000
file_exists($storagePath);                               // false

$repo->persist();
$repo->isDirty();                                        // false

$draft = new Customer('Draft');
$repo->put($draft);
$repo->load();
$repo->contains($draft);                                 // false

$repo->getRepositoryMetaData()->getReadCount();          // 2
$repo->getRepositoryMetaData()->getWriteCount();         // 1

$again = InMemoryAndFileRepository::create(
    storagePath: $storagePath,
    serializer: new JsonEntitySerializer(Customer::class),
    persistenceDefinition: PersistenceDefinition::MemoryAsyncFile
);
$again->countAllEntities();                              // 1000

$repo->persistEvery(500);
for ($i = 1001; $i <= 1500; $i++) {
    $repo->put(new Customer("Customer {$i}"));
}
$repo->pendingWrites();                                  // 0
$repo->getRepositoryMetaData()->getWriteCount();         // 2

$repo->close();
```

`Customer` is any entity the serializer reads back, here one whose constructor takes a name (a subclass of `AbstractUUIDEntity`, for instance). The two reads counted after `load()` are the `load()` of `create()` and the explicit one; the second instance reads the record stored by `persist()` and counts its own `load()`.
