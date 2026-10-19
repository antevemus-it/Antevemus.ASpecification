# ALinq Synergy & Fluent LINQ Collections

### 10. ALinq Synergy & Fluent LINQ Collections (Module 14)

Native high-performance interoperability with the **[`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection)** framework:

#### 10.1 Deep Property Resolution with Dot-Notation (`PropertyAccess`)
Evaluate nested properties on objects (with getters or Boolean methods) and multidimensional associative arrays without reflection bottlenecks. Since v2.0.0 the resolution lives in [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess), shared with ALinq (one accessor, one contract test); `Helpers\PropertyAccessor` remains as a deprecated facade until v3.0:

```php
use Antevemus\ASpecification\Spec;

$vipInNY = Spec::property('address.city', Spec::equalTo('New York'))
    ->and(Spec::property('profile.score', Spec::greaterThan(90)));

$user = (object)[
    'address' => (object)['city' => 'New York'],
    'profile' => ['score' => 95]
];

$result = $vipInNY->evaluate($user);
// $result->isSatisfied === true
```

Each segment resolves in this order, first match wins: array or `ArrayAccess` key; public method `getX()`, `x()`, `isX()`, `hasX()` without required parameters (never `__call()`, never a name starting with `__`); public initialized property or `__get` guarded by `__isset`; public property; and, last, a private or protected property **marked `#[Specifiable]`** by the author of the class (since v1.6.1). The property name may come from a request (a filter or an ordering built by a screen), so private state is reachable by name only when its author opts in. An unmarked non-public property behaves as a property that does not exist: the property specification is an evaluation error, `PropertyAccess::hasProperty()` answers `false`, and a delta (`updateWithDelta()`) that names it throws `InvalidArgumentException` unless the entity has a public setter for it.

```php
use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\Attributes\Specifiable;
use Antevemus\ASpecification\Spec;

final class Account
{
    #[Specifiable]
    private string $status = 'active';      // opted in: readable by name, writable by a delta
    private string $passwordHash = 'h4sh';  // not marked: invisible to a name
}

$account = new Account();

Spec::property('status', Spec::equalTo('active'))->isSatisfiedBy($account);        // true
PropertyAccess::hasProperty($account, 'passwordHash');                              // false
Spec::property('passwordHash', Spec::startsWith('h'))->evaluate($account)->isError; // true, as a missing property
```

`#[Specifiable]` on a class covers every property **declared by that class** (not those of its parent nor of its subclasses). `#[Antevemus\ALinq\Attributes\Specifiable]` and `#[Antevemus\ADeepPropertyAccess\Attributes\Specifiable]` are accepted as the same marker, by name.

#### 10.2 AST Compiler to LINQ Predicates (`ALinqSpecificationVisitor`)
Compile specification trees into a zero-overhead native `Closure(mixed $candidate): bool` using short-circuit logical operators (`&&`, `||`, `!`):

```php
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;

$predicate = ALinqSpecificationVisitor::createPredicate($vipInNY);

// Use directly in ALinq collections or native array_filter
$approved = $myAlinqCollection->where($predicate);
```

#### 10.3 Fluent In-Memory Repositories with `ALinqBridge`
Connect repositories and iterables directly to LINQ pipelines (sorting, pagination, grouping, and aggregations):

```php
use Antevemus\ASpecification\Linq\ALinqBridge;

// 1. Fluent filtering on any iterable
$techItems = ALinqBridge::filter($products, $specTech)
    ->orderByDescending(fn($p) => $p->price)
    ->take(10)
    ->toArray();

// 2. Strongly typed query on InMemoryRepository returning an ALinqCollection
$topCustomers = $inMemoryRepo->findAsLinqCollection($specApproved)
    ->orderBy(fn($c) => $c->getPoints())
    ->take(5)
    ->toArray();

// 3. Aggregate metrics directly
$avgPoints = $inMemoryRepo->asLinqCollection()->average(fn($c) => $c->getPoints());
```

#### 10.4 Generator-Based Streaming & Lazy Evaluation (`ALinqLazyCollection`) with O(1) Memory
Process massive, infinite, or file-backed data streams with constant memory footprint by pairing specifications with lazy generators:

```php
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Spec;

// 1. Stream massive dataset through specification filter with constant O(1) RAM
$largeLogStream = static function(): \Generator {
    $handle = fopen('system_events.log', 'rb');
    while (($line = fgets($handle)) !== false) {
        yield json_decode($line, true);
    }
    fclose($handle);
};

// Elements are pulled and evaluated lazily on demand
$criticalEvents = Spec::filterLazy($largeLogStream, $specHighSeverity)
    ->take(100)
    ->toArray();

// 2. Stream directly from InMemoryRepository with findAsLazyCollection()
$activeCustomers = $inMemoryRepo->findAsLazyCollection($specActive)
    ->select(fn($c) => $c->getEmail())
    ->take(50)
    ->toArray();
```
