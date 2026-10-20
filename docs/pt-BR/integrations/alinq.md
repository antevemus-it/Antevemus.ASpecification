# ALinq Synergy & Coleções Fluentes LINQ

### 10. ALinq Synergy & Coleções Fluentes LINQ (Módulo 14)

Interoperabilidade nativa de alto desempenho com a biblioteca **[`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection)**:

#### 10.1 Resolução Avançada de Propriedades com Dot Notation (`PropertyAccess`)
Avalie propriedades em objetos com getters ou métodos booleanos, arrays associativos e caminhos aninhados profundos. Desde a v2.0.0 a resolução mora no [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess), compartilhado com o ALinq (um acessor, um teste de contrato); `Helpers\PropertyAccessor` fica como fachada depreciada até a v3.0:

```php
use Antevemus\ASpecification\Spec;

// Regra navegando por objetos/arrays aninhados
$vipInNY = Spec::property('address.city', Spec::equalTo('New York'))
    ->and(Spec::property('profile.score', Spec::greaterThan(90)));

$user = (object)[
    'address' => (object)['city' => 'New York'],
    'profile' => ['score' => 95]
];

$result = $vipInNY->evaluate($user);
// $result->isSatisfied === true
```

Cada segmento é resolvido nesta ordem, vence o primeiro que casar: chave de array ou `ArrayAccess`; método público `getX()`, `x()`, `isX()`, `hasX()` sem parâmetro obrigatório (nunca `__call()`, nunca nome começado por `__`); propriedade pública inicializada ou `__get` guardado por `__isset`; propriedade pública; e, por último, propriedade privada ou protegida **marcada com `#[Specifiable]`** pelo autor da classe (desde a v1.6.1). O nome da propriedade pode vir do request (um filtro ou uma ordenação montados pela tela), então o estado privado só é alcançado por nome quando o autor opta por isso. Propriedade não pública sem a marca se comporta como inexistente: a especificação de propriedade é erro de avaliação, `PropertyAccess::hasProperty()` responde `false` e um delta (`updateWithDelta()`) que a nomeie lança `InvalidArgumentException`, a menos que a entidade tenha setter público para ela.

```php
use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\Attributes\Specifiable;
use Antevemus\ASpecification\Spec;

final class Account
{
    #[Specifiable]
    private string $status = 'active';      // opt-in: legível por nome, gravável por delta
    private string $passwordHash = 'h4sh';  // sem a marca: invisível para um nome
}

$account = new Account();

Spec::property('status', Spec::equalTo('active'))->isSatisfiedBy($account);        // true
PropertyAccess::hasProperty($account, 'passwordHash');                              // false
Spec::property('passwordHash', Spec::startsWith('h'))->evaluate($account)->isError; // true, como propriedade inexistente
```

`#[Specifiable]` na classe vale para todas as propriedades **declaradas naquela classe** (não para as da superclasse nem das subclasses). `#[Antevemus\ALinq\Attributes\Specifiable]` e `#[Antevemus\ADeepPropertyAccess\Attributes\Specifiable]` são aceitos como a mesma marca, pelo nome.

#### 10.2 Compilador de AST para Predicados LINQ (`ALinqSpecificationVisitor`)
Converta árvores de especificações em um `Closure(mixed $candidate): bool` compilado com operadores de curto-circuito (`&&`, `||`, `!`), sem overhead:

```php
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;

// Compila a especificação em um predicado executável
$predicate = ALinqSpecificationVisitor::createPredicate($vipInNY);

// Diretamente utilizável em coleções ALinq ou array_filter nativo
$approved = $myAlinqCollection->where($predicate);
```

#### 10.3 Repositórios em Memória Fluentes com `ALinqBridge`
Conecte repositórios em memória e iteráveis diretamente a pipelines LINQ (ordenação, paginação, agrupamento e agregações):

```php
use Antevemus\ASpecification\Linq\ALinqBridge;

// 1. Filtragem fluente a partir de qualquer iterável
$techItems = ALinqBridge::filter($products, $specTech)
    ->orderByDescending(fn($p) => $p->price)
    ->take(10)
    ->toArray();

// 2. Consulta tipada direta no InMemoryRepository retornando ALinqCollection
$topCustomers = $inMemoryRepo->findAsLinqCollection($specApproved)
    ->orderBy(fn($c) => $c->getPoints())
    ->take(5)
    ->toArray();

// 3. Conversão completa do repositório em memória para ALinqCollection
$avgPoints = $inMemoryRepo->asLinqCollection()->average(fn($c) => $c->getPoints());
```

#### 10.4 Streaming Baseado em Generators & Avaliação Lazy (`ALinqLazyCollection`) com O(1) de RAM
Processe conjuntos massivos de dados, arquivos ou streams infinitas com consumo de memória estritamente constante \(O(1)\), combinando especificações e generators:

```php
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Spec;

// 1. Processamento sob demanda de grandes volumes com consumo O(1) de RAM
$largeLogStream = static function(): \Generator {
    $handle = fopen('system_events.log', 'rb');
    while (($line = fgets($handle)) !== false) {
        yield json_decode($line, true);
    }
    fclose($handle);
};

// Elementos são lidos e testados sob demanda sem carregar o arquivo na memória
$criticalEvents = Spec::filterLazy($largeLogStream, $specHighSeverity)
    ->take(100)
    ->toArray();

// 2. Stream preguiçoso diretamente a partir do InMemoryRepository com findAsLazyCollection()
$activeCustomers = $inMemoryRepo->findAsLazyCollection($specActive)
    ->select(fn($c) => $c->getEmail())
    ->take(50)
    ->toArray();
```
