<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ALinq\ALinqCollection;
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
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Tests\TestCase;
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
        $this->testALinqBridgeToCollectionAndFilter();
        $this->testALinqBridgeFluentChainingAndAggregation();
        $this->testInMemoryRepositoryAsLinqCollection();
        $this->testInMemoryRepositoryFindAsLinqCollection();
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
}
