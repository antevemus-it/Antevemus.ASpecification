<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ALinq\ALinqCollection;
use Antevemus\ALinq\ALinqLazyCollection;
use Antevemus\ASpecification\AbstractCompositeSpecification;
use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Tests\Support\CountingSpecification;
use Antevemus\ASpecification\Tests\Support\LazyProbeEntity;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Repositories\AbstractRepository;
use stdClass;

/**
 * Module14_ALinqSynergyTest - Suíte de Testes para Integração ASpecification & ALinqCollection
 *
 * Valida a sinergia entre o Antevemus.ASpecification e a biblioteca Antevemus.AlinqCollection:
 * 1. Resolução inteligente de propriedades (PropertyAccessor & PropertySpecification com dot notation)
 * 2. Compilador de AST para predicados funcionais LINQ (ALinqSpecificationVisitor)
 * 3. Repositórios em memória com pipelines fluentes LINQ (ALinqBridge & InMemoryRepository)
 *
 * Funcionalidades:
 * - Validação de PropertyAccessor em objetos, arrays e dot notation aninhada
 * - Compilação AST de especificações para predicados executáveis de alta performance
 * - Interoperabilidade fluente com ALinqCollection (where, orderBy, take, sum)
 * - Integração nativa de InMemoryRepository com asLinqCollection e findAsLinqCollection
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module14_ALinqSynergyTest extends TestCase
{
    public function run(): void
    {
        $this->testPropertyAccessorDirectAndGetters();
        $this->testPropertyAccessorDotNotation();
        $this->testPropertyAccessorArraySupport();
        $this->testPropertySpecificationWithDotNotation();
        $this->testALinqVisitorLeafCompilations();
        $this->testALinqVisitorCompositesAndShortCircuit();
        $this->testALinqVisitorWithComplexDomainObject();
        if (ALinqBridge::isAvailable()) {
            $this->testALinqBridgeToCollectionAndFilter();
            $this->testALinqBridgeFluentChainingAndAggregation();
            $this->testInMemoryRepositoryAsLinqCollection();
            $this->testInMemoryRepositoryFindAsLinqCollection();
            $this->testALinqBridgeLazyStreamingPipeline();
            $this->testInMemoryRepositoryLazyCollection();
            $this->testSpecFacadeLazyMethods();
        } else {
            // A integração com o ALinq é opcional (composer `suggest`, PHP 8.4). Sem o pacote irmão,
            // os testes de ponte/coleção são pulados com aviso; PropertyAccessor e o visitor continuam cobertos.
            fwrite(STDOUT, "    [AVISO] antevemus/alinq-collection não encontrado; testes de ALinqBridge/coleções pulados (integração opcional, PHP 8.4).\n");
        }
        $this->testALinqVisitorIsAsStrictAsTheCoreOnComparisons();
        $this->testALinqVisitorHandsScalarPropertyValuesToCustomLeaves();
        $this->testALinqVisitorMirrorsPropertySpecificationOnNullAndMissingProperties();
        $this->testALinqVisitorParityTableWithCoreEvaluation();
        $this->testALinqVisitorHandsCandidateAsIsToChildlessComposites();

        // Forward 017 (v1.5.0): RN-07 (in_array estrito), RN-05 (ponte tipada), RN-04 (ponte sobre IRepository).
        $this->testRn07ALinqCompilesInToStrictInArray();
        if (ALinqBridge::isLazyAvailable()) {
            $this->testRn05BridgeReturnsALinqInterfaces();
            $this->testRn04BridgeStreamsAnyRepositoryLazily();
        }
    }

    /**
     * 1. Testa PropertyAccessor em atributos diretos, getters e métodos booleanos.
     */
    private function testPropertyAccessorDirectAndGetters(): void
    {
        $user = new class {
            public string $name = 'Heliton';
            private int $age = 35;
            private bool $active = true;

            public function getAge(): int
            {
                return $this->age;
            }

            public function isActive(): bool
            {
                return $this->active;
            }
        };

        $this->assertEquals('Heliton', PropertyAccessor::getValue($user, 'name'));
        $this->assertEquals(35, PropertyAccessor::getValue($user, 'age'));
        $this->assertTrue(PropertyAccessor::getValue($user, 'active'));

        $this->assertTrue(PropertyAccessor::hasProperty($user, 'name'));
        $this->assertTrue(PropertyAccessor::hasProperty($user, 'age'));
        $this->assertTrue(PropertyAccessor::hasProperty($user, 'active'));
        $this->assertFalse(PropertyAccessor::hasProperty($user, 'nonExistent'));
    }

    /**
     * 2. Testa PropertyAccessor com dot notation aninhada.
     */
    private function testPropertyAccessorDotNotation(): void
    {
        $order = (object)[
            'id' => 101,
            'customer' => (object)[
                'name' => 'Acme Corp',
                'address' => (object)[
                    'city' => 'Belo Horizonte',
                    'zip' => '30000-000',
                ],
            ],
        ];

        $this->assertEquals('Acme Corp', PropertyAccessor::getValue($order, 'customer.name'));
        $this->assertEquals('Belo Horizonte', PropertyAccessor::getValue($order, 'customer.address.city'));
        $this->assertEquals('30000-000', PropertyAccessor::getValue($order, 'customer.address.zip'));
        $this->assertEquals(null, PropertyAccessor::getValue($order, 'customer.address.country'));
        $this->assertTrue(PropertyAccessor::hasProperty($order, 'customer.address.city'));
        $this->assertFalse(PropertyAccessor::hasProperty($order, 'customer.address.invalid'));
    }

    /**
     * 3. Testa PropertyAccessor com arrays associativos e aninhados.
     */
    private function testPropertyAccessorArraySupport(): void
    {
        $data = [
            'tenant' => 'antevemus',
            'config' => [
                'theme' => 'dark',
                'limits' => [
                    'max_connections' => 50,
                ],
            ],
        ];

        $this->assertEquals('antevemus', PropertyAccessor::getValue($data, 'tenant'));
        $this->assertEquals('dark', PropertyAccessor::getValue($data, 'config.theme'));
        $this->assertEquals(50, PropertyAccessor::getValue($data, 'config.limits.max_connections'));
        $this->assertEquals(null, PropertyAccessor::getValue($data, 'config.limits.timeout'));
    }

    /**
     * 4. Testa PropertySpecification avaliando propriedades aninhadas via dot notation.
     */
    private function testPropertySpecificationWithDotNotation(): void
    {
        $base = Spec::alwaysTrue();
        $specCity = new PropertySpecification($base, 'address.city', new EqualSpecification('São Paulo'));
        $specMaxAge = new PropertySpecification($base, 'profile.age', new GreaterThanSpecification(18));

        $candidate1 = (object)[
            'address' => (object)['city' => 'São Paulo'],
            'profile' => ['age' => 28],
        ];
        $candidate2 = (object)[
            'address' => (object)['city' => 'Rio de Janeiro'],
            'profile' => ['age' => 16],
        ];

        $this->assertTrue($specCity->evaluate($candidate1)->isSatisfied);
        $this->assertTrue($specMaxAge->evaluate($candidate1)->isSatisfied);

        $this->assertFalse($specCity->evaluate($candidate2)->isSatisfied);
        $this->assertFalse($specMaxAge->evaluate($candidate2)->isSatisfied);
    }

    /**
     * 5. Testa compilação de folhas relacionais no ALinqSpecificationVisitor.
     */
    private function testALinqVisitorLeafCompilations(): void
    {
        $visitor = new ALinqSpecificationVisitor();

        // Igualdade
        $predEq = $visitor->visit(new EqualSpecification(42));
        $this->assertTrue($predEq(42));
        $this->assertFalse($predEq(43));

        // Desigualdade
        $predNeq = $visitor->visit(new NotEqualSpecification('ACTIVE'));
        $this->assertTrue($predNeq('INACTIVE'));
        $this->assertFalse($predNeq('ACTIVE'));

        // Relacionais (> e <)
        $predGt = $visitor->visit(new GreaterThanSpecification(100));
        $this->assertTrue($predGt(150));
        $this->assertFalse($predGt(100));

        $predLt = $visitor->visit(new LessThanSpecification(50));
        $this->assertTrue($predLt(30));
        $this->assertFalse($predLt(50));

        // Not Null
        $predNotNull = $visitor->visit(new NotNullSpecification());
        $this->assertTrue($predNotNull('abc'));
        $this->assertFalse($predNotNull(null));

        // String Case-Insensitive
        $predCase = $visitor->visit(new EqualIgnoreCaseStringSpecification('admin'));
        $this->assertTrue($predCase('ADMIN'));
        $this->assertTrue($predCase('Admin'));
        $this->assertFalse($predCase('user'));

        // Wildcard
        $predWildcard = $visitor->visit(new WildcardSpecification('PR-*'));
        $this->assertTrue($predWildcard('PR-1234'));
        $this->assertFalse($predWildcard('RJ-1234'));

        // Regex
        $predRegex = $visitor->visit(new RegexSpecification('/^[A-Z]{3}-\d{4}$/'));
        $this->assertTrue($predRegex('ABC-1234'));
        $this->assertFalse($predRegex('abc-1234'));

        // Tautologia e Contradição
        $predTrue = $visitor->visit(new AlwaysTrueSpecification());
        $predFalse = $visitor->visit(new AlwaysFalseSpecification());
        $this->assertTrue($predTrue('anything'));
        $this->assertFalse($predFalse('anything'));
    }

    /**
     * 6. Testa composição lógica AND, OR, NOT e NOR no ALinqSpecificationVisitor.
     */
    private function testALinqVisitorCompositesAndShortCircuit(): void
    {
        $visitor = new ALinqSpecificationVisitor();

        // (x > 10 AND x < 20)
        $specAnd = (new GreaterThanSpecification(10))->and(new LessThanSpecification(20));
        $predAnd = $visitor->visit($specAnd);
        $this->assertTrue($predAnd(15));
        $this->assertFalse($predAnd(5));
        $this->assertFalse($predAnd(25));

        // (x == 'A' OR x == 'B')
        $specOr = (new EqualSpecification('A'))->or(new EqualSpecification('B'));
        $predOr = $visitor->visit($specOr);
        $this->assertTrue($predOr('A'));
        $this->assertTrue($predOr('B'));
        $this->assertFalse($predOr('C'));

        // NOT (x == 'BLOCKED')
        $specNot = (new EqualSpecification('BLOCKED'))->not();
        $predNot = $visitor->visit($specNot);
        $this->assertTrue($predNot('ACTIVE'));
        $this->assertFalse($predNot('BLOCKED'));

        // Joint Denial NOR: neither left nor right
        $specNor = new JointDenialSpecification(new EqualSpecification('X'), new EqualSpecification('Y'));
        $predNor = $visitor->visit($specNor);
        $this->assertTrue($predNor('Z'));
        $this->assertFalse($predNor('X'));
        $this->assertFalse($predNor('Y'));
    }

    /**
     * 7. Testa avaliação de objetos de domínio complexos com PropertySpecification.
     */
    private function testALinqVisitorWithComplexDomainObject(): void
    {
        $base = Spec::alwaysTrue();

        // Regra de Negócio:
        // status == 'ACTIVE' AND age >= 18 AND (role == 'ADMIN' OR score > 90)
        $spec = (new PropertySpecification($base, 'status', new EqualSpecification('ACTIVE')))
            ->and(new PropertySpecification($base, 'age', new GreaterThanSpecification(17)))
            ->and(
                (new PropertySpecification($base, 'role', new EqualSpecification('ADMIN')))
                    ->or(new PropertySpecification($base, 'score', new GreaterThanSpecification(90)))
            );

        $predicate = ALinqSpecificationVisitor::createPredicate($spec);

        $validAdmin = (object)['status' => 'ACTIVE', 'age' => 20, 'role' => 'ADMIN', 'score' => 50];
        $validHighScorer = (object)['status' => 'ACTIVE', 'age' => 25, 'role' => 'MEMBER', 'score' => 95];
        $underageUser = (object)['status' => 'ACTIVE', 'age' => 16, 'role' => 'ADMIN', 'score' => 99];
        $inactiveUser = (object)['status' => 'INACTIVE', 'age' => 30, 'role' => 'ADMIN', 'score' => 99];

        $this->assertTrue($predicate($validAdmin));
        $this->assertTrue($predicate($validHighScorer));
        $this->assertFalse($predicate($underageUser));
        $this->assertFalse($predicate($inactiveUser));
    }

    /**
     * 8. Testa ALinqBridge convertendo iteráveis e filtrando com especificações.
     */
    private function testALinqBridgeToCollectionAndFilter(): void
    {
        $this->assertTrue(ALinqBridge::isAvailable());

        $items = [
            (object)['id' => 1, 'name' => 'Alice', 'dept' => 'IT', 'salary' => 6000],
            (object)['id' => 2, 'name' => 'Bob', 'dept' => 'Sales', 'salary' => 4500],
            (object)['id' => 3, 'name' => 'Charlie', 'dept' => 'IT', 'salary' => 7500],
            (object)['id' => 4, 'name' => 'Diana', 'dept' => 'HR', 'salary' => 5200],
        ];

        $specIT = new PropertySpecification(Spec::alwaysTrue(), 'dept', new EqualSpecification('IT'));

        /** @var ALinqCollection $itEmployees */
        $itEmployees = ALinqBridge::filter($items, $specIT);

        $this->assertInstanceOf(ALinqCollection::class, $itEmployees);
        $this->assertEquals(2, $itEmployees->count());

        $names = $itEmployees->select(fn($e) => $e->name)->toArray();
        $this->assertEquals(['Alice', 'Charlie'], $names);
    }

    /**
     * 9. Testa encadeamento fluente de ALinqCollection a partir de uma especificação filtrada.
     */
    private function testALinqBridgeFluentChainingAndAggregation(): void
    {
        $products = [
            (object)['name' => 'Mouse', 'category' => 'Tech', 'price' => 50],
            (object)['name' => 'Keyboard', 'category' => 'Tech', 'price' => 150],
            (object)['name' => 'Monitor', 'category' => 'Tech', 'price' => 1200],
            (object)['name' => 'Chair', 'category' => 'Office', 'price' => 800],
            (object)['name' => 'Desk', 'category' => 'Office', 'price' => 1500],
        ];

        // Filtra Tech com preço > 100, ordena por preço descrescente e soma
        $specTechExpensive = (new PropertySpecification(Spec::alwaysTrue(), 'category', new EqualSpecification('Tech')))
            ->and(new PropertySpecification(Spec::alwaysTrue(), 'price', new GreaterThanSpecification(100)));

        /** @var ALinqCollection $filtered */
        $filtered = ALinqBridge::filter($products, $specTechExpensive);

        $this->assertEquals(2, $filtered->count());

        $orderedDesc = $filtered->orderByDescending(fn($p) => $p->price)->toArray();
        $this->assertEquals('Monitor', $orderedDesc[0]->name);
        $this->assertEquals('Keyboard', $orderedDesc[1]->name);

        $totalTech = $filtered->sum(fn($p) => $p->price);
        $this->assertEquals(1350, $totalTech);
    }

    /**
     * 10. Testa InMemoryRepository::asLinqCollection().
     */
    private function testInMemoryRepositoryAsLinqCollection(): void
    {
        $repo = new InMemoryRepository();

        $e1 = new class('USR-1', 'Active User', 100) extends AbstractEntity {
            public function __construct(private string $id, public string $name, public int $points) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };
        $e2 = new class('USR-2', 'Banned User', 0) extends AbstractEntity {
            public function __construct(private string $id, public string $name, public int $points) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };

        $repo->put($e1);
        $repo->put($e2);

        /** @var ALinqCollection $collection */
        $collection = $repo->asLinqCollection();

        $this->assertInstanceOf(ALinqCollection::class, $collection);
        $this->assertEquals(2, $collection->count());

        $first = $collection->first(fn($item) => $item->points > 50);
        $this->assertTrue($first !== null);
        $this->assertEquals('USR-1', $first->getEntityId());
    }

    /**
     * 11. Testa InMemoryRepository::findAsLinqCollection($specification).
     */
    private function testInMemoryRepositoryFindAsLinqCollection(): void
    {
        $repo = new InMemoryRepository();

        $e1 = new class('E-1', 'Pending Approval', 500) extends AbstractEntity {
            public function __construct(private string $id, public string $status, public int $amount) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };
        $e2 = new class('E-2', 'Approved', 1200) extends AbstractEntity {
            public function __construct(private string $id, public string $status, public int $amount) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };
        $e3 = new class('E-3', 'Approved', 300) extends AbstractEntity {
            public function __construct(private string $id, public string $status, public int $amount) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };

        $repo->put($e1);
        $repo->put($e2);
        $repo->put($e3);

        $specApproved = new PropertySpecification(Spec::alwaysTrue(), 'status', new EqualSpecification('Approved'));

        /** @var ALinqCollection $approvedCollection */
        $approvedCollection = $repo->findAsLinqCollection($specApproved);

        $this->assertInstanceOf(ALinqCollection::class, $approvedCollection);
        $this->assertEquals(2, $approvedCollection->count());

        // Operação LINQ combinada: maior valor aprovado
        $maxAmount = $approvedCollection->max(fn($item) => $item->amount);
        $this->assertEquals(1200, $maxAmount);

        // Operação LINQ combinada: ordenado por amount crescente
        $ordered = $approvedCollection->orderBy(fn($item) => $item->amount)->toArray();
        $this->assertEquals('E-3', $ordered[0]->getEntityId());
        $this->assertEquals('E-2', $ordered[1]->getEntityId());
    }

    /**
     * 12. Test ALinqBridge::toLazyCollection() and filterLazy() with generator pipeline in O(1) RAM.
     */
    private function testALinqBridgeLazyStreamingPipeline(): void
    {
        $this->assertTrue(ALinqBridge::isLazyAvailable());

        $generatorFactory = static function (): \Generator {
            for ($i = 1; $i <= 10000; $i++) {
                yield (object)['id' => $i, 'val' => $i * 10];
            }
        };

        $lazy = ALinqBridge::toLazyCollection($generatorFactory);
        $this->assertInstanceOf(ALinqLazyCollection::class, $lazy);

        $specGreaterThan500 = new PropertySpecification(Spec::alwaysTrue(), 'val', new GreaterThanSpecification(500));
        $filteredLazy = ALinqBridge::filterLazy($generatorFactory, $specGreaterThan500);

        $this->assertInstanceOf(ALinqLazyCollection::class, $filteredLazy);

        $firstThree = array_values($filteredLazy->take(3)->toArray());
        $this->assertCount(3, $firstThree);
        $this->assertEquals(510, $firstThree[0]->val);
        $this->assertEquals(520, $firstThree[1]->val);
        $this->assertEquals(530, $firstThree[2]->val);
    }

    /**
     * 13. Test InMemoryRepository::asLazyCollection() and findAsLazyCollection($spec).
     */
    private function testInMemoryRepositoryLazyCollection(): void
    {
        $repo = new InMemoryRepository();

        $e1 = new class('LR-1', 'Active', 100) extends AbstractEntity {
            public function __construct(private string $id, public string $status, public int $score) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };
        $e2 = new class('LR-2', 'Inactive', 50) extends AbstractEntity {
            public function __construct(private string $id, public string $status, public int $score) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };
        $e3 = new class('LR-3', 'Active', 250) extends AbstractEntity {
            public function __construct(private string $id, public string $status, public int $score) {
                parent::__construct();
            }
            public function getEntityId(): mixed {
                return $this->id;
            }
        };

        $repo->put($e1);
        $repo->put($e2);
        $repo->put($e3);

        $lazyAll = $repo->asLazyCollection();
        $this->assertInstanceOf(ALinqLazyCollection::class, $lazyAll);
        $this->assertEquals(3, $lazyAll->count());

        $specActive = new PropertySpecification(Spec::alwaysTrue(), 'status', new EqualSpecification('Active'));
        $lazyActive = $repo->findAsLazyCollection($specActive);

        $this->assertInstanceOf(ALinqLazyCollection::class, $lazyActive);
        $this->assertEquals(2, $lazyActive->count());
        $this->assertEquals(350, $lazyActive->sum(fn($item) => $item->score));
    }

    /**
     * 14. Test Spec::linqLazy() and Spec::filterLazy() fluent facade methods.
     */
    private function testSpecFacadeLazyMethods(): void
    {
        $data = [
            ['name' => 'Server 1', 'load' => 25],
            ['name' => 'Server 2', 'load' => 88],
            ['name' => 'Server 3', 'load' => 92],
        ];

        $lazyStream = Spec::linqLazy($data);
        $this->assertInstanceOf(ALinqLazyCollection::class, $lazyStream);

        $specCriticalLoad = new PropertySpecification(Spec::alwaysTrue(), 'load', new GreaterThanSpecification(80));
        $criticalStream = Spec::filterLazy($data, $specCriticalLoad);

        $this->assertInstanceOf(ALinqLazyCollection::class, $criticalStream);
        $criticalNames = array_values($criticalStream->select(fn($s) => $s['name'])->toArray());

        $this->assertEquals(['Server 2', 'Server 3'], $criticalNames);

        // Also test Spec::linqLazy and Spec::filterLazy directly with InMemoryRepository
        $repo = new InMemoryRepository();
        $repo->put(new class('S-1', 40) extends AbstractEntity {
            public function __construct(private string $id, public int $val) { parent::__construct(); }
            public function getEntityId(): mixed { return $this->id; }
        });
        $repo->put(new class('S-2', 90) extends AbstractEntity {
            public function __construct(private string $id, public int $val) { parent::__construct(); }
            public function getEntityId(): mixed { return $this->id; }
        });

        $repoLazy = Spec::linqLazy($repo);
        $this->assertInstanceOf(ALinqLazyCollection::class, $repoLazy);
        $this->assertEquals(2, $repoLazy->count());

        $filteredRepoLazy = Spec::filterLazy($repo, new PropertySpecification(Spec::alwaysTrue(), 'val', new GreaterThanSpecification(50)));
        $this->assertInstanceOf(ALinqLazyCollection::class, $filteredRepoLazy);
        $this->assertEquals(1, $filteredRepoLazy->count());
    }

    /**
     * 15. Reprodução (a): o predicado compilado deve ser tão estrito quanto as folhas de comparação.
     *
     * Desde a 1.2.0 (RN-04) `equalTo(5)->isSatisfiedBy('5')` lança IncompatibleTypeException; o visitor
     * reimplementava o operador com `==`/`!=`/`>`/`<` e respondia `true` em silêncio.
     */
    private function testALinqVisitorIsAsStrictAsTheCoreOnComparisons(): void
    {
        $visitor = new ALinqSpecificationVisitor();

        $this->assertThrows(
            IncompatibleTypeException::class,
            fn() => $visitor->visit(new EqualSpecification(5))('5'),
            'visitor: equalTo(5) must reject the string "5" exactly like EqualSpecification::isSatisfiedBy()'
        );
        $this->assertThrows(IncompatibleTypeException::class, fn() => $visitor->visit(new NotEqualSpecification(5))('5'));
        $this->assertThrows(IncompatibleTypeException::class, fn() => $visitor->visit(new GreaterThanSpecification(10))('15'));
        $this->assertThrows(IncompatibleTypeException::class, fn() => $visitor->visit(new LessThanSpecification(10))('5'));

        // Through a property: the README promise (section 10.2) is a predicate with the spec's semantics
        $viaProperty = ALinqSpecificationVisitor::createPredicate(Spec::property('n', Spec::equalTo(5)));
        $this->assertThrows(IncompatibleTypeException::class, fn() => $viaProperty((object)['n' => '5']));
        $this->assertTrue($viaProperty((object)['n' => 5]));
        $this->assertFalse($viaProperty((object)['n' => 6]));

        // Opt-in loose equality keeps its coercion in both paths
        $loose = ALinqSpecificationVisitor::createPredicate(Spec::property('n', Spec::looselyEqualTo(5)));
        $this->assertTrue($loose((object)['n' => '5']));
        $this->assertTrue(Spec::property('n', Spec::looselyEqualTo(5))->isSatisfiedBy((object)['n' => '5']));
    }

    /**
     * 16. Reprodução (b): folha custom sobre valor escalar recebe o valor, não `null`.
     */
    private function testALinqVisitorHandsScalarPropertyValuesToCustomLeaves(): void
    {
        $isEven = new class extends AbstractSpecification {
            public function getType(): string
            {
                return 'int';
            }

            public function isSatisfiedBy(mixed $candidate): bool
            {
                return is_int($candidate) && $candidate % 2 === 0;
            }
        };

        $this->assertTrue((new ALinqSpecificationVisitor())->visit($isEven)(4));
        $this->assertFalse((new ALinqSpecificationVisitor())->visit($isEven)(3));

        $predicate = ALinqSpecificationVisitor::createPredicate(Spec::property('n', $isEven));
        $this->assertTrue($predicate((object)['n' => 4]), 'custom leaf over scalar property must see the scalar (core says true)');
        $this->assertFalse($predicate((object)['n' => 3]));
        $this->assertEquals(
            Spec::property('n', $isEven)->isSatisfiedBy((object)['n' => 4]),
            $predicate((object)['n' => 4])
        );
    }

    /**
     * 17. Reprodução (c): propriedade nula e propriedade ausente seguem PropertySpecification.
     *
     * No núcleo, valor nulo nunca satisfaz (`isSatisfiedBy` false, `evaluate` falha) e propriedade
     * ausente é erro de avaliação (InvalidArgumentException), nunca `false` que um NOT inverteria.
     */
    private function testALinqVisitorMirrorsPropertySpecificationOnNullAndMissingProperties(): void
    {
        $isNullOnProperty = Spec::property('n', Spec::isNull());
        $predicate = ALinqSpecificationVisitor::createPredicate($isNullOnProperty);

        $this->assertFalse($isNullOnProperty->isSatisfiedBy((object)['n' => null]));
        $this->assertFalse($isNullOnProperty->evaluate((object)['n' => null])->isSatisfied);
        $this->assertFalse($predicate((object)['n' => null]), 'visitor: null property value must not satisfy, like the core');

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $predicate((object)['other' => 1]),
            'visitor: missing property is an evaluation error, like PropertySpecification::isSatisfiedBy()'
        );
        $this->assertTrue($isNullOnProperty->evaluate((object)['other' => 1])->isError);

        // NOT over a missing property must never approve the candidate (same rule as the core)
        $notPredicate = ALinqSpecificationVisitor::createPredicate(Spec::not(Spec::property('status', Spec::equalTo('BLOCKED'))));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $notPredicate((object)['other' => 1]));
        $this->assertTrue($notPredicate((object)['status' => 'ACTIVE']));
        $this->assertFalse($notPredicate((object)['status' => 'BLOCKED']));

        // Candidates that are neither object nor array: core says not satisfied, visitor too
        $this->assertFalse($predicate(null));
        $this->assertFalse($predicate('scalar'));
    }

    /**
     * 18. Regressão: tabela de paridade predicate × evaluate() para folhas, composições e propriedades,
     * incluindo exceções (o predicado lança o que o núcleo lança) e os exemplos 10.1/10.2 do README.
     */
    private function testALinqVisitorParityTableWithCoreEvaluation(): void
    {
        $yesterday = new \DateTimeImmutable('-1 day');
        $cases = [
            // [rótulo, spec, candidato]
            ['equalTo int ok', Spec::property('n', Spec::equalTo(5)), (object)['n' => 5]],
            ['equalTo int ne', Spec::property('n', Spec::equalTo(5)), (object)['n' => 7]],
            ['equalTo str vs int', Spec::property('n', Spec::equalTo(5)), (object)['n' => '5']],
            ['notEqual str vs int', Spec::property('n', Spec::notEqual(5)), (object)['n' => '5']],
            ['greaterThan ok', Spec::property('n', Spec::greaterThan(1)), (object)['n' => 5]],
            ['greaterThan str vs int', Spec::property('n', Spec::greaterThan(1)), (object)['n' => '5']],
            ['atLeast ok', Spec::property('n', Spec::greaterThanOrEqualTo(1)), (object)['n' => 5]],
            ['lessThan float', Spec::property('n', Spec::lessThan(2.5)), (object)['n' => 2]],
            ['before date', Spec::property('d', Spec::before(new \DateTimeImmutable())), (object)['d' => $yesterday]],
            ['before vs string', Spec::property('d', Spec::before(new \DateTimeImmutable())), (object)['d' => '2000-01-01']],
            ['in ok', Spec::property('n', Spec::in(0, 2, 4)), (object)['n' => 2]],
            ['in miss', Spec::property('n', Spec::in(0, 2, 4)), (object)['n' => 3]],
            ['isNull on null', Spec::property('n', Spec::isNull()), (object)['n' => null]],
            ['isNotNull on null', Spec::property('n', Spec::isNotNull()), (object)['n' => null]],
            ['isNotNull on value', Spec::property('n', Spec::isNotNull()), (object)['n' => 0]],
            ['isTrue bool', Spec::property('b', Spec::isTrue()), (object)['b' => true]],
            ['isTrue int', Spec::property('b', Spec::isTrue()), (object)['b' => 1]],
            ['loose equal', Spec::property('n', Spec::looselyEqualTo(5)), (object)['n' => '5']],
            ['ignoreCase', Spec::property('s', new EqualIgnoreCaseStringSpecification('admin')), (object)['s' => 'ADMIN']],
            ['regex', Spec::property('s', new RegexSpecification('/^A/')), (object)['s' => 'Abc']],
            ['wildcard class', Spec::property('s', new WildcardSpecification('PR-[0-9]*')), (object)['s' => 'PR-1']],
            ['wildcard class miss', Spec::property('s', new WildcardSpecification('PR-[0-9]*')), (object)['s' => 'PR-x']],
            ['wildcard non-string', Spec::property('s', new WildcardSpecification('PR-*')), (object)['s' => 12]],
            ['and/or mix', Spec::property('a', Spec::equalTo(1))->and(Spec::property('b', Spec::equalTo(2))->or(Spec::property('c', Spec::equalTo(3)))), (object)['a' => 1, 'b' => 0, 'c' => 3]],
            ['not', Spec::not(Spec::property('a', Spec::equalTo(1))), (object)['a' => 2]],
            ['not on type mismatch', Spec::not(Spec::property('a', Spec::equalTo(1))), (object)['a' => '2']],
            ['nested dot', Spec::property('address.city', Spec::equalTo('New York')), (object)['address' => (object)['city' => 'New York']]],
            ['array candidate', Spec::property('severity', Spec::equalTo('high')), ['severity' => 'high']],
            ['array candidate miss', Spec::property('severity', Spec::equalTo('high')), ['severity' => 'low']],
            ['missing property', Spec::property('x', Spec::equalTo(1)), (object)['y' => 1]],
            // Bug #27 (ZLFW): childless composites receive the candidate as is, like leaves
            ['childless composite (mixed) on even int', $this->evenIntComposite(), 4],
            ['childless composite (mixed) on odd int', $this->evenIntComposite(), 3],
            ['childless composite (mixed) on null', $this->evenIntComposite(), null],
            ['childless composite (?object) on object', $this->stdClassComposite(), new stdClass()],
            ['childless composite (?object) on scalar', $this->stdClassComposite(), 4],
        ];

        foreach ($cases as [$label, $spec, $candidate]) {
            $result = $spec->evaluate($candidate);
            $coreThrew = $result->isError && $result->exception !== null ? get_class($result->exception) : null;

            $predicate = ALinqSpecificationVisitor::createPredicate($spec);
            try {
                $visitorValue = $predicate($candidate);
                $visitorThrew = null;
            } catch (\Throwable $e) {
                $visitorValue = null;
                $visitorThrew = get_class($e);
            }

            $this->assertEquals($coreThrew, $visitorThrew, "parity [{$label}]: exception class (core={$coreThrew}, visitor={$visitorThrew})");
            if ($coreThrew === null) {
                $this->assertEquals($result->isSatisfied, $visitorValue, "parity [{$label}]: verdict");
            }
        }

        // README 10.1 / 10.2: the compiled predicate approves exactly the VIP in New York
        $vipInNY = Spec::property('address.city', Spec::equalTo('New York'))
            ->and(Spec::property('profile.score', Spec::greaterThan(90)));
        $predicate = ALinqSpecificationVisitor::createPredicate($vipInNY);
        $people = [
            (object)['address' => (object)['city' => 'New York'], 'profile' => ['score' => 95]],
            (object)['address' => (object)['city' => 'New York'], 'profile' => ['score' => 50]],
            (object)['address' => (object)['city' => 'Boston'], 'profile' => ['score' => 99]],
        ];
        $this->assertCount(1, array_filter($people, $predicate));
        if (ALinqBridge::isAvailable()) {
            $this->assertCount(1, ALinqCollection::from($people)->where($predicate)->toArray());
        }
    }

    /**
     * 19. Reprodução do bug #27 (ZLFW): composto sem filhos recebe o candidato como está.
     *
     * `compileGenericComposite()` entregava `is_object($c) ? $c : null` ao `isSatisfiedBy()` do composto:
     * um composto do consumidor que aceita escalares via `mixed` respondia `false` onde o núcleo diz `true`,
     * e um composto com a assinatura `?object` do núcleo respondia `false` onde o núcleo lança `TypeError`
     * (erro de avaliação que um NOT nunca inverte). Regra: adendo ABZR v001, RN-01 itens 1 a 3.
     */
    private function testALinqVisitorHandsCandidateAsIsToChildlessComposites(): void
    {
        $evenInts = $this->evenIntComposite();
        $this->assertEquals([], $evenInts->getSpecifications(), 'the composite under test has no children');

        $this->assertTrue($evenInts->isSatisfiedBy(4));
        $this->assertTrue($evenInts->evaluate(4)->isSatisfied);
        $predicate = ALinqSpecificationVisitor::createPredicate($evenInts);
        $this->assertTrue($predicate(4), 'visitor: childless composite accepting scalars must see the scalar (core says true)');
        $this->assertFalse($predicate(3));
        $this->assertFalse($predicate(null));
        $this->assertFalse($predicate(new stdClass()));

        // Through a property, the scalar property value reaches the composite as well
        $viaProperty = ALinqSpecificationVisitor::createPredicate(Spec::property('n', $evenInts));
        $this->assertTrue($viaProperty((object)['n' => 4]));
        $this->assertFalse($viaProperty((object)['n' => 3]));
        $this->assertEquals(Spec::property('n', $evenInts)->isSatisfiedBy((object)['n' => 4]), $viaProperty((object)['n' => 4]));

        // A core-typed (?object) childless composite on a scalar: the core raises TypeError (evaluate() -> error);
        // the predicate must raise the same, never a silent false that NOT would turn into approval
        $onObjects = $this->stdClassComposite();
        $this->assertTrue($onObjects->evaluate(4)->isError);
        $this->assertEquals(\TypeError::class, get_class($onObjects->evaluate(4)->exception));
        $this->assertThrows(\TypeError::class, fn() => ALinqSpecificationVisitor::createPredicate($onObjects)(4));
        $this->assertThrows(\TypeError::class, fn() => ALinqSpecificationVisitor::createPredicate(Spec::not($onObjects))(4));
        $this->assertTrue(ALinqSpecificationVisitor::createPredicate($onObjects)(new stdClass()));
    }

    /**
     * Consumer-defined childless composite that widens the candidate to `mixed` and accepts even integers.
     */
    private function evenIntComposite(): AbstractCompositeSpecification
    {
        return new class('int') extends AbstractCompositeSpecification {
            protected function isSpecifyingAllInstancesOfItsType(): bool
            {
                return false;
            }

            public function isSatisfiedBy(mixed $candidate): bool
            {
                return is_int($candidate) && $candidate % 2 === 0;
            }
        };
    }

    /**
     * Core-typed (`?object`) childless composite satisfied by any stdClass.
     */
    private function stdClassComposite(): AbstractCompositeSpecification
    {
        return new class(stdClass::class) extends AbstractCompositeSpecification {
            protected function isSpecifyingAllInstancesOfItsType(): bool
            {
                return true;
            }
        };
    }

    /**
     * RN-07 (forward 017, v1.5.0): a InSpecification compila para in_array($candidate, $values, true)
     * e o predicado decide exatamente como a folha (inclusive o IncompatibleTypeException).
     */
    private function testRn07ALinqCompilesInToStrictInArray(): void
    {
        $predicate = ALinqSpecificationVisitor::createPredicate(Spec::in('A', 'B'));
        $this->assertTrue($predicate('A'));
        $this->assertFalse($predicate('a'), 'estrito: caixa importa');
        $this->assertThrows(IncompatibleTypeException::class, fn() => $predicate(1), 'mesmo erro de tipo da folha');

        $numbers = ALinqSpecificationVisitor::createPredicate(Spec::in(1, 2, 3));
        $this->assertTrue($numbers(2));
        $this->assertFalse($numbers(4));
        $this->assertThrows(IncompatibleTypeException::class, fn() => $numbers('2'));
        $this->assertFalse(ALinqSpecificationVisitor::createPredicate(Spec::in())('x'), 'vazio: contradição');
        $this->assertTrue(ALinqSpecificationVisitor::createPredicate(Spec::notIn(1, 2))(3));

        // Paridade com a avaliação do núcleo, dentro de propriedade
        $spec = Spec::property('n', Spec::in(0, 2, 4));
        $compiled = ALinqSpecificationVisitor::createPredicate($spec);
        foreach ([0, 1, 2, 3, 4] as $n) {
            $candidate = (object) ['n' => $n];
            $this->assertEquals($spec->isSatisfiedBy($candidate), $compiled($candidate), "paridade para n={$n}");
        }
    }

    /**
     * RN-05 (forward 017, v1.5.0): a ponte declara os tipos do ALinq 1.3 nos retornos
     * (IALinqCollection / IALinqLazyCollection) e aceita qualquer IRepository.
     */
    private function testRn05BridgeReturnsALinqInterfaces(): void
    {
        $collection = 'Antevemus\\ALinq\\Interfaces\\IALinqCollection';
        $lazy = 'Antevemus\\ALinq\\Interfaces\\IALinqLazyCollection';
        $repository = 'Antevemus\\ASpecification\\Contracts\\Repositories\\IRepository';

        $expected = [
            [ALinqBridge::class, 'toCollection', $collection],
            [ALinqBridge::class, 'filter', $collection],
            [ALinqBridge::class, 'fromRepository', $collection],
            [ALinqBridge::class, 'queryRepository', $collection],
            [ALinqBridge::class, 'toLazyCollection', $lazy],
            [ALinqBridge::class, 'filterLazy', $lazy],
            [ALinqBridge::class, 'fromRepositoryLazy', $lazy],
            [ALinqBridge::class, 'queryRepositoryLazy', $lazy],
            [AbstractRepository::class, 'asLazyCollection', $lazy],
            [AbstractRepository::class, 'findAsLazyCollection', $lazy],
            [InMemoryRepository::class, 'asLinqCollection', $collection],
            [InMemoryRepository::class, 'findAsLinqCollection', $collection],
            [Spec::class, 'linq', $collection],
            [Spec::class, 'filterLinq', $collection],
            [Spec::class, 'linqLazy', $lazy],
            [Spec::class, 'filterLazy', $lazy],
        ];
        foreach ($expected as [$class, $method, $type]) {
            $returnType = (new \ReflectionMethod($class, $method))->getReturnType();
            $this->assertEquals($type, $returnType instanceof \ReflectionNamedType ? $returnType->getName() : (string) $returnType, "{$class}::{$method}()");
        }
        foreach (['toCollection', 'filter', 'toLazyCollection', 'filterLazy'] as $method) {
            $param = (string) (new \ReflectionMethod(ALinqBridge::class, $method))->getParameters()[0]->getType();
            $this->assertTrue(str_contains($param, $repository), "ALinqBridge::{$method}() aceita IRepository: {$param}");
        }

        $repo = new InMemoryRepository([new LazyProbeEntity(1), new LazyProbeEntity(2)]);
        $this->assertInstanceOf($collection, ALinqBridge::toCollection($repo));
        $this->assertInstanceOf($collection, ALinqBridge::filter([1, 2, 3], Spec::greaterThan(1)));
        $this->assertInstanceOf($lazy, ALinqBridge::toLazyCollection($repo));
        $this->assertInstanceOf($lazy, $repo->findAsLazyCollection(Spec::alwaysTrue()));
        $this->assertEquals(2, ALinqBridge::toCollection($repo)->count());
    }

    /**
     * RN-04 (forward 017, v1.5.0): a ponte lazy vale para todo IRepository (partição, arquivo,
     * memória), via ALinqLazyCollection::from(fn() => $repo->iterate(...)): um generator novo por
     * travessia (re-iterável) e só as entidades puxadas são avaliadas.
     */
    private function testRn04BridgeStreamsAnyRepositoryLazily(): void
    {
        $base = new InMemoryRepository();
        for ($i = 0; $i < 1000; $i++) {
            $base->put(new LazyProbeEntity($i, $i % 2 === 0 ? 'par' : 'impar'));
        }
        $partition = $base->makePartition();

        $spec = new CountingSpecification(fn(LazyProbeEntity $e): bool => $e->title === 'impar');
        $stream = $partition->findAsLazyCollection($spec);
        $this->assertEquals(0, $spec->evaluations, 'montar a coleção não avalia nada');
        $firstThree = $stream->take(3)->toArray();
        $this->assertEquals([1, 3, 5], array_map(fn($e) => $e->n, $firstThree));
        $this->assertEquals(6, $spec->evaluations, 'seis avaliações para três acertos, não 1000');
        $this->assertEquals(500, $stream->count(), 'a mesma coleção é re-iterável');

        // Ponte explícita com pipeline do ALinq por cima
        $sum = ALinqBridge::filterLazy($partition, Spec::property('title', Spec::in('par')))
            ->take(5)
            ->sum(fn($e) => $e->n);
        $this->assertEquals(0 + 2 + 4 + 6 + 8, $sum);

        // Repositório de arquivo: a mesma ponte, lendo arquivo por arquivo
        $tmp = sys_get_temp_dir() . '/aspec_m14_rn04_' . bin2hex(random_bytes(4));
        $ser = new \Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer(Module14LazyFileEntity::class);
        $files = new \Antevemus\ASpecification\Repositories\File\FilePerEntityRepository($tmp, Module14LazyFileEntity::class, \Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition::ReadWrite, $ser);
        try {
            foreach (['a', 'b', 'c', 'd'] as $title) {
                $files->put(new Module14LazyFileEntity($title));
            }
            $titles = $files->asLazyCollection()->select(fn($e) => $e->title)->toArray();
            sort($titles);
            $this->assertEquals(['a', 'b', 'c', 'd'], $titles);
            $this->assertEquals(1, $files->findAsLazyCollection(Spec::property('title', Spec::in('c')))->count());
        } finally {
            $files->clear();
            @rmdir($tmp);
        }
    }
}

/** Entidade de arquivo do teste RN-04 da ponte lazy (forward 017). */
final class Module14LazyFileEntity extends \Antevemus\ASpecification\Entities\AbstractUUIDEntity
{
    public function __construct(public string $title = '')
    {
        parent::__construct();
    }
}
