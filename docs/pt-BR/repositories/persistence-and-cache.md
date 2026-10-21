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

### 5.1. Repositório Híbrido com Gravação Adiada (Módulo 42)

A `PersistenceDefinition` passada ao `InMemoryAndFileRepository` escolhe como uma gravação chega ao armazenamento. Desde a v2.8.0 os dois casos assíncronos do Domian o tornam **write-behind** (gravação adiada): a gravação toca só o cache, e o armazenamento é gravado quando você chama `persist()`.

| Modo | Uma gravação (`put()`, `update()`, `remove()`, `removeAll()`, `clear()`) | `persist()` / `store()` | `load()` | `close()` |
| :--- | :--- | :--- | :--- | :--- |
| `ReadWrite` (o padrão) | o cache e o arquivo, na mesma chamada (write-through) | regrava o arquivo | recarrega o cache do arquivo | grava o arquivo |
| `Snapshot` | o cache e a memória do backend | grava o arquivo | recarrega o cache do arquivo | grava o arquivo |
| `ReadOnly` | recusada (`RepositoryException`) | recusado | recarrega o cache do arquivo | não grava nada |
| `MemoryAsyncFile` (Domian `INMEMORY_AND_FILE`) | só o cache; contada por `pendingWrites()` | grava o cache inteiro de uma vez | recarrega o cache e **descarta** o que não foi persistido | persiste quando `isDirty()` |
| `MemoryAsyncDelegated` (Domian `INMEMORY_AND_DELEGATED`) | só o cache, sobre qualquer backend persistente | `removeAll()` + `putAll()` + `store()` no backend | o mesmo de cima | o mesmo de cima, depois fecha o backend |

Os outros casos do enum repassam cada gravação ao backend, que aplica o seu próprio modo, como antes da v2.8.0.

Nos dois modos write-behind:

- **As consultas respondem do cache**, gravações pendentes incluídas. `isDirty()` diz se há algo pendente; `pendingWrites()` conta uma por entidade gravada, atualizada ou removida (`putAll()` de dez entidades conta dez, um `remove()` que não encontra nada não conta).
- **`persist()` grava o cache inteiro.** Sobre um `SingleFileRepository` é uma única gravação do documento: um arquivo temporário renomeado por cima dele. Quando falha, a exceção chega a você e nada se perde: o cache e a contagem pendente ficam como estavam, o documento anterior fica intacto, e o próximo `persist()` grava tudo. Sobre um `FilePerEntityRepository` cada arquivo é gravado do mesmo jeito (atômico por arquivo, não para o diretório inteiro), e os arquivos das entidades removidas são apagados.
- **`load()` recarrega do armazenamento e descarta o que não foi persistido**, como faz o Domian. Um repositório construído com `new` começa vazio até o primeiro `load()`; `create()` faz a carga por você. Um `persist()` antes de qualquer `load()` substitui o armazenamento pelo cache.
- **O registro do repositório**, `getRepositoryMetaData()`, conta as cargas (leituras) e as persistências (gravações) do repositório inteiro; `put()` e as consultas não o tocam. Ele é guardado com o conteúdo (a chave `__repository_metadata` do documento único, ou `_repository.json` no diretório de um `FilePerEntityRepository`) e lido de volta pelo `load()`. Um arquivo gravado antes da v2.8.0, ou por um repositório write-through, não tem registro e carrega como antes; um repositório write-through mantém o registro que encontrar. Os metadados por entidade do exemplo 5 contam uma gravação por `persist()` para cada entidade gravada desde o anterior.
- **`persistEvery($writes)`** persiste sempre que as gravações pendentes chegam a esse número (verificado depois de cada operação, então um `putAll()` que passa dele persiste uma vez, depois do lote); `0` desliga. **`persistOnShutdown()`** persiste no fim do script se houver algo pendente: o PHP o executa num fim normal, não quando o processo é morto (`SIGKILL`) ou morre antes, então uma gravação que não pode se perder ainda chama `persist()`. Os dois são recusados fora dos modos write-behind.
- **`makePartition()`** devolve uma partição volátil: ela não tem `load()`/`store()`/`close()` próprios, e as suas gravações passam pelo repositório, então ficam pendentes nele e chegam ao armazenamento no próximo `persist()` dele.

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

`Customer` é qualquer entidade que o serializer lê de volta, aqui uma cujo construtor recebe um nome (uma subclasse de `AbstractUUIDEntity`, por exemplo). As duas leituras contadas depois do `load()` são o `load()` do `create()` e o explícito; a segunda instância lê o registro guardado pelo `persist()` e conta o seu próprio `load()`.
