# Persistência Desacoplada e Cache Híbrido L1/L2

### 5. Persistência Desacoplada e Cache Híbrido L1/L2

```php
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;

// Repositório com velocidade de leitura em RAM (L1) e durabilidade em disco (L2)
$repo = InMemoryAndFileRepository::create(
    storagePath: '/var/data/customers.json',
    serializer: new JsonEntitySerializer(Customer::class)
);

$repo->put($newCustomer); // Salva em memória e sincroniza no arquivo
```

O documento é JSON qualquer que seja o serializer, e cada entidade leva os seus metadados de persistência (`__persistence_metadata`: contadores de leitura e gravação, primeira e última leitura e gravação) ao lado dos dados, então `getEntityMetaData()` responde igual em outro processo (desde a v2.0.0).
