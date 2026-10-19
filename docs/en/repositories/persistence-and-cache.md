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
