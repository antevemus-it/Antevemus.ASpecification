<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Parity;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\SameInstantSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\DefaultValueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\DateStringSpecification;
use Antevemus\ASpecification\Specifications\String\EnumNameStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test domain mirroring net.sourceforge.domian.test.domain (Customer / VipCustomer / Order).
 */
interface AlgebraEntity
{
}

class AlgebraCustomer implements AlgebraEntity
{
    public function __construct(
        public ?int $customerId = null,
        public ?DateTimeImmutable $membershipDate = null,
        public ?string $name = null,
        public ?string $gender = null,
        public ?DateTimeImmutable $birthDate = null,
        public bool $foreign = false,
        public array $orders = [],
    ) {
    }
}

class AlgebraVipCustomer extends AlgebraCustomer
{
    public ?DateTimeImmutable $vipCustomerSince = null;
}

class AlgebraOrder implements AlgebraEntity
{
    public ?int $orderId = null;
}

enum AlgebraGender
{
    case MALE;
    case FEMALE;
}

/**
 * DomianSpecificationAlgebraTest - Executable transcription of the Domian specification algebra tests.
 *
 * Each test method is one Domian test (SubsumptionTest, IsDisjointWith_*_Test,
 * CompositeSpecificationTest, ValueBoundSpecificationTest, NotNullSpecificationTest,
 * AbstractPartitionedRepositoryTest.checkEqualsAndSubsumption_extra) or one rule of the Venn set
 * algebra they rely on; the integer/double/timestamp disjointness tables are transcribed row by
 * row from IsDisjointWith_ValueBoundSpecifications_Test. The probe
 * docs/paridade-domian-2026-10-09/sondas/probe_subsumption.php is included as a data provider.
 *
 * Deliberate deviations from the Java expectations (the set algebra wins over the Java code):
 *  - SubsumptionTest.shouldDealWithNegatedSpecifications_3: ¬(T ∧ name=Tommy) ⊇ ¬T is TRUE
 *    (complement of a subset is a superset); Domian answers false for every negation.
 *  - IsDisjointWith_CompositeSpecifications_Test.equalDisjointMemberSpecificationOrEqualDisjoint...:
 *    (name=Tommy ∨ date=yesterday) and (name=Jasper ∨ date=the day before) share the customer
 *    named Tommy admitted the day before yesterday, so they are NOT disjoint (Domian: true).
 *  - IsDisjointWith_ParameterizedSpecifications_Test.differentAccessibleObjectsMeansDisjoint:
 *    customerId=42 and name="Johnny" are not disjoint (Domian decides by Long vs String).
 *  - Day-granularity dates (testComparableValueBoundSpecifications_Day_*): a DateTime domain is
 *    dense, so after(today) and before(tomorrow) intersect; only the timestamp table is ported.
 *  - where() twice and and(name, spec) before where() do not throw (deliberate PHP relaxation).
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Parity
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomianSpecificationAlgebraTest extends TestCase
{
    private static DateTimeImmutable $today;
    private static DateTimeImmutable $yesterday;
    private static DateTimeImmutable $tomorrow;
    private static DateTimeImmutable $theDayBeforeYesterday;
    private static DateTimeImmutable $twoDaysAgo;
    private static DateTimeImmutable $twentyYearsAgo;

    public static function setUpBeforeClass(): void
    {
        self::$today = new DateTimeImmutable('today');
        self::$yesterday = self::$today->modify('-1 day');
        self::$tomorrow = self::$today->modify('+1 day');
        self::$theDayBeforeYesterday = self::$today->modify('-2 days');
        self::$twoDaysAgo = self::$today->modify('-2 days');
        self::$twentyYearsAgo = self::$today->modify('-20 years');
    }

    // ==========================================
    // Domian test helpers (SubsumptionTest.assertLemma1 / assertLemma2)
    // ==========================================

    /**
     * Lemma 1: (A ∧ B) ⊂ A, (A ∧ B) ⊂ B, (A ∨ B) ⊇ A, (A ∨ B) ⊇ B, in both operand orders.
     */
    private function assertLemma1(ISpecification $specA, ISpecification $specB): void
    {
        $combined = $specA->and($specB);
        self::assertTrue($combined->isSpecialCaseOf($specA), "(A and B) special case of A: {$combined}");
        self::assertTrue($combined->isSpecialCaseOf($specB), "(A and B) special case of B: {$combined}");
        $combined = $specA->or($specB);
        self::assertTrue($combined->isGeneralizationOf($specA), "(A or B) generalization of A: {$combined}");
        self::assertTrue($combined->isGeneralizationOf($specB), "(A or B) generalization of B: {$combined}");
        $combined = $specB->and($specA);
        self::assertTrue($combined->isSpecialCaseOf($specA));
        self::assertTrue($combined->isSpecialCaseOf($specB));
        $combined = $specB->or($specA);
        self::assertTrue($combined->isGeneralizationOf($specA));
        self::assertTrue($combined->isGeneralizationOf($specB));
    }

    private function assertLemma1DisjunctionOnly(ISpecification $specA, ISpecification $specB): void
    {
        $combined = $specA->or($specB);
        self::assertTrue($combined->isGeneralizationOf($specA));
        self::assertTrue($combined->isGeneralizationOf($specB));
        $combined = $specB->or($specA);
        self::assertTrue($combined->isGeneralizationOf($specA));
        self::assertTrue($combined->isGeneralizationOf($specB));
    }

    /**
     * Lemma 2: subsumption is consistent with satisfaction for a given candidate.
     */
    private function assertLemma2(ISpecification $specA, ISpecification $specB, object $candidate): void
    {
        if ($specA->isSatisfiedBy($candidate) && $specB->isGeneralizationOf($specA)) {
            self::assertTrue($specB->isSatisfiedBy($candidate));
            return;
        }
        if ($specB->isSatisfiedBy($candidate) && $specA->isGeneralizationOf($specB)) {
            self::assertTrue($specA->isSatisfiedBy($candidate));
            return;
        }
        if ($specA->isSatisfiedBy($candidate) && !$specB->isSatisfiedBy($candidate)) {
            self::assertFalse($specB->isGeneralizationOf($specA));
            return;
        }
        if ($specB->isSatisfiedBy($candidate) && !$specA->isSatisfiedBy($candidate)) {
            self::assertFalse($specA->isGeneralizationOf($specB));
        }
    }

    private static function customers(): ICompositeSpecification
    {
        return Spec::allInstancesOfType(AlgebraCustomer::class);
    }

    // ==========================================
    // SubsumptionTest
    // ==========================================

    public function testShouldBeReflexive(): void
    {
        $rawDateSpec = Spec::equalTo(self::$tomorrow);
        $anotherRawDateSpec = Spec::equalTo(self::$tomorrow);
        self::assertTrue($rawDateSpec->isGeneralizationOf($rawDateSpec));
        self::assertTrue($rawDateSpec->isGeneralizationOf($anotherRawDateSpec));
        $numberSpec = Spec::equalTo(42);
        $rawNumberSpec = Spec::equalTo(42);
        self::assertTrue($numberSpec->isGeneralizationOf($numberSpec));
        self::assertTrue($numberSpec->isGeneralizationOf($rawNumberSpec));
        self::assertTrue($numberSpec->isSpecialCaseOf($numberSpec));
        self::assertTrue($numberSpec->isSpecialCaseOf($rawNumberSpec));
    }

    public function testShouldNotAcceptSpecificationOfDifferentType(): void
    {
        $rawDateSpec = Spec::equalTo(new DateTimeImmutable());
        $rawNumberSpec = Spec::equalTo(42);
        $rawCustomerSpec = Spec::all(AlgebraCustomer::class);
        self::assertFalse($rawDateSpec->isGeneralizationOf($rawNumberSpec));
        self::assertFalse($rawNumberSpec->isGeneralizationOf($rawDateSpec));
        self::assertFalse($rawDateSpec->isGeneralizationOf($rawCustomerSpec));
        self::assertFalse($rawNumberSpec->isGeneralizationOf($rawCustomerSpec));
        self::assertFalse($rawDateSpec->isSpecialCaseOf($rawNumberSpec));
        self::assertFalse($rawNumberSpec->isSpecialCaseOf($rawDateSpec));
        self::assertFalse($rawDateSpec->isSpecialCaseOf($rawCustomerSpec));
        self::assertFalse($rawNumberSpec->isSpecialCaseOf($rawCustomerSpec));
    }

    public function testValueBoundSpecificationCannotBeGeneralizationOfEachOther(): void
    {
        $todaySpec = Spec::equalTo(self::$today);
        $yesterdaySpec = Spec::equalTo(self::$yesterday);
        self::assertFalse($todaySpec->isGeneralizationOf($yesterdaySpec));
        self::assertFalse($yesterdaySpec->isGeneralizationOf($todaySpec));
        self::assertFalse($todaySpec->isSpecialCaseOf($yesterdaySpec));
        $lessThan10 = Spec::lessThan(10);
        $greaterThanOrEqualTo1000 = Spec::greaterThanOrEqualTo(1000);
        self::assertFalse($lessThan10->isGeneralizationOf($greaterThanOrEqualTo1000));
        self::assertFalse($greaterThanOrEqualTo1000->isGeneralizationOf($lessThan10));
        self::assertFalse($lessThan10->isSpecialCaseOf($greaterThanOrEqualTo1000));
        self::assertFalse($greaterThanOrEqualTo1000->isSpecialCaseOf($lessThan10));
    }

    public function testCheckRestOfValueBoundSpecifications(): void
    {
        $todaySpec = Spec::equalTo(self::$today);
        $notNullSpec = new NotNullSpecification();
        self::assertTrue($notNullSpec->isGeneralizationOf($notNullSpec));
        self::assertTrue($notNullSpec->isGeneralizationOf($todaySpec));
        self::assertTrue($notNullSpec->isSpecialCaseOf($notNullSpec));
        self::assertFalse($notNullSpec->isSpecialCaseOf($todaySpec));

        $leaves = [
            new DefaultValueSpecification('string'),
            new DateStringSpecification('Ymd'),
            new EnumNameStringSpecification(AlgebraGender::class),
            new RegexSpecification('/[^abc]/'),
            new WildcardSpecification('j?h*y'),
            new WildcardExpressionMatcherIgnoreCaseStringSpecification('j?h*y'),
            new PropertySpecification(Spec::all(AlgebraOrder::class), 'orderId', new NotNullSpecification()),
            Spec::hasSize(Spec::isGreaterThanOrEqualTo(42)),
        ];
        foreach ($leaves as $leaf) {
            self::assertTrue($leaf->isGeneralizationOf($leaf), get_class($leaf) . ' must be reflexive');
            self::assertTrue($leaf->isSpecialCaseOf($leaf), get_class($leaf) . ' must be reflexive');
            self::assertFalse($leaf->isGeneralizationOf($todaySpec), get_class($leaf) . ' does not generalize equalTo(today)');
            self::assertFalse($leaf->isSpecialCaseOf($todaySpec), get_class($leaf) . ' is not a special case of equalTo(today)');
        }
        self::assertFalse((new DefaultValueSpecification('string'))->isGeneralizationOf(Spec::exactly('Vienna')));
    }

    public function testBasicLemma4Stuff(): void
    {
        $customerSpec1 = Spec::all(AlgebraCustomer::class)->where('name', Spec::is('Tommy'));
        $customerSpec2 = Spec::all(AlgebraCustomer::class)->where('membershipDate', Spec::isBefore(self::$yesterday));
        $this->assertLemma1($customerSpec1, $customerSpec2);
        $customerSpec1 = Spec::all(AlgebraCustomer::class)->where('name', Spec::is('Tommy'))->or('name', Spec::is('Johnny'));
        $customerSpec2 = Spec::all(AlgebraCustomer::class)->where('membershipDate', Spec::isBefore(self::$yesterday))->or('name', Spec::is('Johnny'));
        $this->assertLemma1($customerSpec1, $customerSpec2);
    }

    public function testShouldBeReflexiveCompositeSpecification(): void
    {
        $dateSpec = Spec::all(DateTimeImmutable::class);
        $rawDateSpec = Spec::all(DateTimeImmutable::class);
        self::assertTrue($dateSpec->isGeneralizationOf($dateSpec));
        self::assertTrue($dateSpec->isGeneralizationOf($rawDateSpec));
        self::assertTrue($dateSpec->isSpecialCaseOf($dateSpec));
        self::assertTrue($dateSpec->isSpecialCaseOf($rawDateSpec));
        $this->assertLemma1($dateSpec, $rawDateSpec);

        $customerSpec = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'));
        $anotherCustomerSpec = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'));
        self::assertTrue($customerSpec->isGeneralizationOf($customerSpec));
        self::assertTrue($customerSpec->isGeneralizationOf($anotherCustomerSpec));
        self::assertTrue($customerSpec->isSpecialCaseOf($customerSpec));
        self::assertTrue($customerSpec->isSpecialCaseOf($anotherCustomerSpec));
        $this->assertLemma1($customerSpec, $anotherCustomerSpec);
    }

    public function testNotNullSpecificationShouldBeOnTopOfEverything(): void
    {
        self::assertTrue((new NotNullSpecification())->isGeneralizationOf(new NotNullSpecification()));
        self::assertTrue((new NotNullSpecification())->isSpecialCaseOf(new NotNullSpecification()));
        self::assertTrue((new NotNullSpecification())->isGeneralizationOf(Spec::all(AlgebraOrder::class)));
        self::assertFalse((new NotNullSpecification())->isSpecialCaseOf(Spec::all(AlgebraOrder::class)));
        self::assertTrue((new NotNullSpecification())->isGeneralizationOf(Spec::isEqualTo(1)));
        self::assertFalse((new NotNullSpecification())->isSpecialCaseOf(Spec::isAfter(new DateTimeImmutable())));
        self::assertTrue(Spec::allObjects()->isGeneralizationOf(Spec::all(AlgebraOrder::class)->where('orderId', Spec::is(1))));
        self::assertTrue(Spec::all(AlgebraOrder::class)->isSpecialCaseOf(Spec::allObjects()));
    }

    public function testEntitySpecificationShouldBeOnTopOfAlmostEverything(): void
    {
        self::assertTrue(Spec::allInstancesOfType(AlgebraEntity::class)->isGeneralizationOf(Spec::allInstancesOfType(AlgebraOrder::class)));
        self::assertTrue(Spec::allInstancesOfType(AlgebraOrder::class)->isSpecialCaseOf(Spec::allInstancesOfType(AlgebraEntity::class)));
        $rawEntitySpec = Spec::allInstancesOfType(AlgebraEntity::class);
        $rawOrderSpec = Spec::allInstancesOfType(AlgebraOrder::class);
        self::assertTrue($rawEntitySpec->isGeneralizationOf($rawOrderSpec));
        self::assertFalse($rawOrderSpec->isGeneralizationOf($rawEntitySpec));
        self::assertFalse($rawEntitySpec->isSpecialCaseOf($rawOrderSpec));
        self::assertTrue($rawOrderSpec->isSpecialCaseOf($rawEntitySpec));

        // allEntities() (AllEntitiesSpecification) plays the same role for the IEntity-less test domain
        $allEntities = new AllEntitiesSpecification(AlgebraEntity::class);
        self::assertTrue($allEntities->isGeneralizationOf($rawOrderSpec));
        self::assertTrue($allEntities->isGeneralizationOf(Spec::all(AlgebraCustomer::class)->where('name', Spec::is('Tommy'))));
        self::assertFalse($allEntities->isGeneralizationOf(Spec::not($rawOrderSpec)));
        self::assertTrue($rawOrderSpec->isSpecialCaseOf($allEntities));
    }

    public function testShouldAcceptSubtypeSpecificationCompositeSpecification(): void
    {
        $rawCustomerSpec = Spec::all(AlgebraCustomer::class)->where('name', Spec::is('Tommy'));
        $rawVipCustomerSpec = Spec::all(AlgebraVipCustomer::class)
            ->where('name', Spec::is('Tommy'))
            ->and('vipCustomerSince', Spec::before(self::$yesterday));
        self::assertTrue($rawCustomerSpec->isGeneralizationOf($rawVipCustomerSpec));
        self::assertFalse($rawCustomerSpec->isSpecialCaseOf($rawVipCustomerSpec));
        self::assertFalse($rawVipCustomerSpec->isGeneralizationOf($rawCustomerSpec));
        self::assertTrue($rawVipCustomerSpec->isSpecialCaseOf($rawCustomerSpec));

        $customerSpec = Spec::all(AlgebraCustomer::class)->where('name', Spec::is('Tommy'));
        $vipCustomerSpec = Spec::all(AlgebraVipCustomer::class)
            ->where('vipCustomerSince', Spec::before(self::$yesterday))
            ->and('name', Spec::is('Tommy'));
        self::assertTrue($customerSpec->isGeneralizationOf($vipCustomerSpec));
        self::assertTrue($vipCustomerSpec->isSpecialCaseOf($customerSpec));

        $this->assertLemma2($rawCustomerSpec, $rawVipCustomerSpec, new AlgebraCustomer(name: 'Tommy'));
        $vip = new AlgebraVipCustomer(name: 'Tommy');
        $vip->vipCustomerSince = self::$twoDaysAgo;
        $this->assertLemma2($rawCustomerSpec, $rawVipCustomerSpec, $vip);
        $this->assertLemma1($customerSpec, $vipCustomerSpec);
    }

    public function testShouldNotAcceptSpecificationOfDifferentTypeCompositeSpecification(): void
    {
        $rawCustomerSpec = Spec::all(AlgebraCustomer::class);
        $rawDateSpec = Spec::all(DateTimeImmutable::class);
        self::assertFalse($rawCustomerSpec->isGeneralizationOf($rawDateSpec));
        self::assertFalse($rawDateSpec->isGeneralizationOf($rawCustomerSpec));
        self::assertFalse($rawCustomerSpec->isSpecialCaseOf($rawDateSpec));
        self::assertFalse($rawDateSpec->isSpecialCaseOf($rawCustomerSpec));
        $this->assertLemma1DisjunctionOnly($rawCustomerSpec, $rawDateSpec);
    }

    public function testCheckSimpleFieldSpecificationVersusTypedSpecification(): void
    {
        $allCustomerSpec = Spec::all(AlgebraCustomer::class);
        $maleCustomerSpec = Spec::all(AlgebraCustomer::class)->where('gender', Spec::isEqualTo('MALE'));
        $femaleCustomerSpec = Spec::all(AlgebraCustomer::class)->where('gender', Spec::isEqualTo('FEMALE'));
        self::assertTrue($allCustomerSpec->isGeneralizationOf($maleCustomerSpec));
        self::assertTrue($allCustomerSpec->isGeneralizationOf($femaleCustomerSpec));
        self::assertFalse($maleCustomerSpec->isGeneralizationOf($femaleCustomerSpec));
        self::assertFalse($allCustomerSpec->isSpecialCaseOf($maleCustomerSpec));
        self::assertFalse($allCustomerSpec->isSpecialCaseOf($femaleCustomerSpec));
        self::assertFalse($maleCustomerSpec->isSpecialCaseOf($femaleCustomerSpec));
        self::assertTrue($maleCustomerSpec->isSpecialCaseOf($maleCustomerSpec));
        self::assertTrue($femaleCustomerSpec->isSpecialCaseOf($femaleCustomerSpec));
        self::assertFalse($femaleCustomerSpec->isSpecialCaseOf($maleCustomerSpec));
        $this->assertLemma1($allCustomerSpec, $maleCustomerSpec);
    }

    public function testDisjunctSpecificationsShouldNotBeGeneralizationNorSpecialization(): void
    {
        $pioneerCustomers = self::customers()->where('membershipDate', Spec::isBefore(self::$twoDaysAgo));
        $newCustomers = self::customers()->where('membershipDate', Spec::isAfterOrAtTheSameTimeAs(self::$twoDaysAgo));
        self::assertFalse($newCustomers->isSpecialCaseOf($pioneerCustomers));
        self::assertFalse($newCustomers->isGeneralizationOf($pioneerCustomers));
        self::assertFalse($pioneerCustomers->isSpecialCaseOf($newCustomers));
        self::assertFalse($pioneerCustomers->isGeneralizationOf($newCustomers));
        self::assertTrue($pioneerCustomers->isDisjointWith($newCustomers));
        self::assertTrue($newCustomers->isDisjointWith($pioneerCustomers));
    }

    public function testCheckSimpleConjunctionSpecifications(): void
    {
        $spec = Spec::all(AlgebraCustomer::class)
            ->where('gender', Spec::isEqualTo('MALE'))
            ->and('name', Spec::is('John'));
        $specialCaseOfSpec = Spec::all(AlgebraCustomer::class)
            ->where('gender', Spec::isEqualTo('MALE'))
            ->and('membershipDate', Spec::isAtTheSameTimeAs(self::$today))
            ->and('name', Spec::is('John'));
        self::assertTrue($spec->isGeneralizationOf($specialCaseOfSpec));
        self::assertFalse($spec->isSpecialCaseOf($specialCaseOfSpec));
        self::assertFalse($specialCaseOfSpec->isGeneralizationOf($spec));
        self::assertTrue($specialCaseOfSpec->isSpecialCaseOf($spec));
        $this->assertLemma1($spec, $specialCaseOfSpec);
    }

    public function testCheckSimpleDisjunctionSpecifications(): void
    {
        $spec = Spec::all(AlgebraCustomer::class)
            ->where('gender', Spec::is('MALE'))
            ->and('name', Spec::is('John'));
        $moreGeneralDisjunctionSpec = Spec::all(AlgebraCustomer::class)
            ->where('gender', Spec::is('MALE'))
            ->and('name', Spec::is('John'))
            ->or('membershipDate', Spec::isAtTheSameTimeAs(self::$today));
        self::assertFalse($spec->isGeneralizationOf($moreGeneralDisjunctionSpec));
        self::assertTrue($moreGeneralDisjunctionSpec->isGeneralizationOf($spec));
        self::assertTrue($spec->isSpecialCaseOf($moreGeneralDisjunctionSpec));
        self::assertFalse($moreGeneralDisjunctionSpec->isSpecialCaseOf($spec));
        self::assertFalse($spec->isDisjointWith($moreGeneralDisjunctionSpec));
        $this->assertLemma1($spec, $moreGeneralDisjunctionSpec);

        $customerSpec = Spec::all(AlgebraCustomer::class);
        $maleCustomerSpec = Spec::all(AlgebraCustomer::class)->where('gender', Spec::isEqualTo('MALE'));
        $aCustomerNamedJohnSpec = Spec::all(AlgebraCustomer::class)->where('name', Spec::is('John'))->and('gender', Spec::isEqualTo('MALE'));
        $aCustomerNamedPeterSpec = Spec::all(AlgebraCustomer::class)->where('gender', Spec::isEqualTo('MALE'))->and('name', Spec::is('Peter'));
        $aCustomerNamedJohnOrPeterSpec = $aCustomerNamedJohnSpec->or($aCustomerNamedPeterSpec);
        self::assertTrue($customerSpec->isGeneralizationOf($aCustomerNamedJohnSpec));
        self::assertFalse($customerSpec->isSpecialCaseOf($aCustomerNamedJohnSpec));
        self::assertTrue($customerSpec->isGeneralizationOf($aCustomerNamedJohnOrPeterSpec));
        self::assertFalse($customerSpec->isSpecialCaseOf($aCustomerNamedJohnOrPeterSpec));
        self::assertTrue($maleCustomerSpec->isGeneralizationOf($aCustomerNamedJohnSpec));
        self::assertFalse($maleCustomerSpec->isSpecialCaseOf($aCustomerNamedJohnSpec));
        self::assertTrue($maleCustomerSpec->isGeneralizationOf($aCustomerNamedJohnOrPeterSpec));
        self::assertFalse($maleCustomerSpec->isSpecialCaseOf($aCustomerNamedJohnOrPeterSpec));
        self::assertFalse($aCustomerNamedJohnSpec->isGeneralizationOf($aCustomerNamedJohnOrPeterSpec));
        self::assertTrue($aCustomerNamedJohnSpec->isSpecialCaseOf($aCustomerNamedJohnOrPeterSpec));
        self::assertTrue($aCustomerNamedJohnOrPeterSpec->isGeneralizationOf($aCustomerNamedJohnSpec));
        self::assertFalse($aCustomerNamedJohnOrPeterSpec->isSpecialCaseOf($aCustomerNamedJohnSpec));
        self::assertTrue($aCustomerNamedJohnOrPeterSpec->isGeneralizationOf($aCustomerNamedJohnOrPeterSpec));
        self::assertTrue($aCustomerNamedJohnOrPeterSpec->isSpecialCaseOf($aCustomerNamedJohnOrPeterSpec));
        self::assertFalse($aCustomerNamedJohnOrPeterSpec->isGeneralizationOf($customerSpec));
        self::assertTrue($aCustomerNamedJohnOrPeterSpec->isSpecialCaseOf($customerSpec));
        self::assertFalse($aCustomerNamedJohnOrPeterSpec->isGeneralizationOf($maleCustomerSpec));
        self::assertTrue($aCustomerNamedJohnOrPeterSpec->isSpecialCaseOf($maleCustomerSpec));
        self::assertTrue($aCustomerNamedPeterSpec->isDisjointWith($aCustomerNamedJohnSpec));
        self::assertFalse($aCustomerNamedPeterSpec->isDisjointWith($aCustomerNamedJohnOrPeterSpec));
        self::assertFalse($aCustomerNamedJohnSpec->isDisjointWith($aCustomerNamedJohnOrPeterSpec));
        $this->assertLemma1($aCustomerNamedPeterSpec, $aCustomerNamedJohnOrPeterSpec);
    }

    public function testShouldDealWithNegatedSpecifications1(): void
    {
        $allObjects = Spec::allObjects();
        $customer = Spec::all(AlgebraCustomer::class);
        $notCustomer = Spec::not($customer);
        $customerNotNamedTommy = Spec::all(AlgebraCustomer::class)->where('name', Spec::isNot('Tommy'));
        self::assertTrue($allObjects->isGeneralizationOf($customer));
        self::assertTrue($allObjects->isGeneralizationOf($notCustomer));
        self::assertFalse($customer->isGeneralizationOf($allObjects));
        self::assertFalse($notCustomer->isGeneralizationOf($allObjects));
        self::assertFalse($allObjects->isSpecialCaseOf($customer));
        self::assertFalse($allObjects->isSpecialCaseOf($notCustomer));
        self::assertTrue($customer->isSpecialCaseOf($allObjects));
        self::assertTrue($notCustomer->isSpecialCaseOf($allObjects));
        self::assertFalse($notCustomer->isGeneralizationOf($customer));
        self::assertFalse($notCustomer->isSpecialCaseOf($customer));
        self::assertFalse($customer->isGeneralizationOf($notCustomer));
        self::assertFalse($customer->isSpecialCaseOf($notCustomer));
        self::assertFalse($notCustomer->isGeneralizationOf($customerNotNamedTommy));
        self::assertFalse($notCustomer->isSpecialCaseOf($customerNotNamedTommy));
        self::assertFalse($customerNotNamedTommy->isGeneralizationOf($notCustomer));
        self::assertFalse($customerNotNamedTommy->isSpecialCaseOf($notCustomer));
        self::assertTrue($customer->isGeneralizationOf($customerNotNamedTommy));
        self::assertFalse($customerNotNamedTommy->isGeneralizationOf($customer));
        self::assertFalse($customer->isSpecialCaseOf($customerNotNamedTommy));
        self::assertTrue($customerNotNamedTommy->isSpecialCaseOf($customer));
        $this->assertLemma1DisjunctionOnly($customer, $notCustomer);
        $this->assertLemma1DisjunctionOnly($notCustomer, $customerNotNamedTommy);
        // A ⟂ ¬A
        self::assertTrue($customer->isDisjointWith($notCustomer));
        self::assertTrue($notCustomer->isDisjointWith($customer));
    }

    public function testShouldDealWithNegatedSpecifications2(): void
    {
        $customer = Spec::all(AlgebraCustomer::class);
        $notCustomer = Spec::not($customer);
        $femaleOrMaleNotNamedTommyOrJohnnyCustomer = Spec::all(AlgebraCustomer::class)
            ->where('name', Spec::isNot('Tommy')->and(Spec::isNot('Johnny')))
            ->or('gender', Spec::is('FEMALE'));
        self::assertFalse($notCustomer->isGeneralizationOf($customer));
        self::assertFalse($notCustomer->isSpecialCaseOf($customer));
        self::assertFalse($customer->isGeneralizationOf($notCustomer));
        self::assertFalse($customer->isSpecialCaseOf($notCustomer));
        self::assertFalse($notCustomer->isGeneralizationOf($femaleOrMaleNotNamedTommyOrJohnnyCustomer));
        self::assertFalse($notCustomer->isSpecialCaseOf($femaleOrMaleNotNamedTommyOrJohnnyCustomer));
        self::assertFalse($femaleOrMaleNotNamedTommyOrJohnnyCustomer->isGeneralizationOf($notCustomer));
        self::assertFalse($femaleOrMaleNotNamedTommyOrJohnnyCustomer->isSpecialCaseOf($notCustomer));
        self::assertTrue($customer->isGeneralizationOf($femaleOrMaleNotNamedTommyOrJohnnyCustomer));
        self::assertFalse($femaleOrMaleNotNamedTommyOrJohnnyCustomer->isGeneralizationOf($customer));
        self::assertFalse($customer->isSpecialCaseOf($femaleOrMaleNotNamedTommyOrJohnnyCustomer));
        self::assertTrue($femaleOrMaleNotNamedTommyOrJohnnyCustomer->isSpecialCaseOf($customer));
        $this->assertLemma1($customer, $femaleOrMaleNotNamedTommyOrJohnnyCustomer);
        $this->assertLemma1DisjunctionOnly($notCustomer, $femaleOrMaleNotNamedTommyOrJohnnyCustomer);
    }

    /**
     * Deviation from Domian: ¬A ⊇ ¬B ⇔ B ⊇ A. Since (T ∧ name=Tommy) ⊂ T, ¬T ⊂ ¬(T ∧ name=Tommy);
     * Domian answers false for every negation pair, the algebra answers true for these two.
     */
    public function testShouldDealWithNegatedSpecifications3(): void
    {
        $notCustomer = Spec::not(Spec::all(AlgebraCustomer::class));
        $notCustomerNamedTommy = Spec::not(Spec::all(AlgebraCustomer::class)->where('name', Spec::is('Tommy')));
        $notCustomerNotNamedTommy = Spec::not(Spec::all(AlgebraCustomer::class)->where('name', Spec::isNot('Tommy')));
        $notCustomerFromYesterday = Spec::not(Spec::all(AlgebraCustomer::class)->where('membershipDate', Spec::isAtTheSameTimeAs(self::$yesterday)));
        $notOrder = Spec::not(Spec::all(AlgebraOrder::class));
        self::assertFalse($notCustomer->isGeneralizationOf($notCustomerNamedTommy));
        self::assertTrue($notCustomer->isSpecialCaseOf($notCustomerNamedTommy), 'algebra: ¬T ⊂ ¬(T ∧ name=Tommy) [Domian: false]');
        self::assertTrue($notCustomerNamedTommy->isGeneralizationOf($notCustomer), 'algebra: ¬(T ∧ name=Tommy) ⊇ ¬T [Domian: false]');
        self::assertFalse($notCustomerNamedTommy->isSpecialCaseOf($notCustomer));
        self::assertFalse($notCustomerNamedTommy->isGeneralizationOf($notCustomerNotNamedTommy));
        self::assertFalse($notCustomerNamedTommy->isSpecialCaseOf($notCustomerNotNamedTommy));
        self::assertFalse($notCustomerNotNamedTommy->isGeneralizationOf($notCustomerNamedTommy));
        self::assertFalse($notCustomerNotNamedTommy->isSpecialCaseOf($notCustomerNamedTommy));
        self::assertFalse($notCustomerNamedTommy->isGeneralizationOf($notCustomerFromYesterday));
        self::assertFalse($notCustomerNamedTommy->isSpecialCaseOf($notCustomerFromYesterday));
        self::assertFalse($notCustomerFromYesterday->isGeneralizationOf($notCustomerNamedTommy));
        self::assertFalse($notCustomerFromYesterday->isSpecialCaseOf($notCustomerNamedTommy));
        self::assertFalse($notCustomer->isGeneralizationOf($notOrder));
        self::assertFalse($notCustomer->isSpecialCaseOf($notOrder));
        self::assertFalse($notOrder->isGeneralizationOf($notCustomer));
        self::assertFalse($notOrder->isSpecialCaseOf($notCustomer));
        $this->assertLemma1DisjunctionOnly($notCustomer, $notCustomerNamedTommy);
        $this->assertLemma1DisjunctionOnly($notCustomer, $notCustomerNotNamedTommy);
        $this->assertLemma1DisjunctionOnly($notCustomer, $notCustomerFromYesterday);
        $this->assertLemma1DisjunctionOnly($notCustomer, $notOrder);
    }

    // ==========================================
    // IsDisjointWith_ValueBoundSpecifications_Test
    // ==========================================

    public function testSpecificationsOfDifferentTypesAreAlwaysDisjoint(): void
    {
        self::assertTrue(Spec::equalTo(new DateTimeImmutable())->isDisjointWith(Spec::equalTo(42)));
        self::assertTrue(Spec::equalTo(42)->isDisjointWith(Spec::equalTo(new DateTimeImmutable())));
        self::assertTrue(Spec::equalTo(42)->isDisjointWith(Spec::equalTo('42')));
        // PHP compares int and float numerically, so lessThan(10) and lessThan(11.795) do intersect (Domian: disjoint by type)
        self::assertFalse(Spec::lessThan(10)->isDisjointWith(Spec::lessThan(11.795)));
    }

    public function testShouldNotBeReflexive(): void
    {
        $rawDateSpec = Spec::equalTo(self::$tomorrow);
        self::assertFalse($rawDateSpec->isDisjointWith($rawDateSpec));
        self::assertFalse($rawDateSpec->isDisjointWith(Spec::equalTo(self::$tomorrow)));
        $numberSpec = Spec::equalTo(42);
        self::assertFalse($numberSpec->isDisjointWith($numberSpec));
        self::assertFalse($numberSpec->isDisjointWith(Spec::equalTo(42)));
        $stringSpec = Spec::equalTo('Yo');
        self::assertFalse($stringSpec->isDisjointWith($stringSpec));
        self::assertFalse($stringSpec->isDisjointWith(Spec::equalTo('Yo')));
    }

    public function testNullSpecifications(): void
    {
        $nullSpec = Spec::isNull();
        $notNullSpec = Spec::isNotNull();
        self::assertFalse($nullSpec->isDisjointWith($nullSpec));
        self::assertTrue($nullSpec->isDisjointWith($notNullSpec));
        self::assertTrue($notNullSpec->isDisjointWith($nullSpec));
        self::assertFalse($notNullSpec->isDisjointWith($notNullSpec));
        self::assertTrue($nullSpec->isDisjointWith(Spec::equalTo(5)));
        self::assertTrue(Spec::not($nullSpec)->isDisjointWith($nullSpec));
        self::assertTrue($nullSpec->isGeneralizationOf(Spec::not($notNullSpec)));
    }

    public function testContradictionsAndTautologies(): void
    {
        $contradiction1 = Spec::createAlwaysFalseSpecification();
        $contradiction2 = Spec::createContradiction();
        $tautology1 = Spec::createAlwaysTrueSpecification();
        $tautology2 = Spec::createTautology();
        self::assertInstanceOf(AlwaysFalseSpecification::class, $contradiction2);
        self::assertInstanceOf(AlwaysTrueSpecification::class, $tautology2);
        self::assertFalse($tautology1->isDisjointWith($tautology1));
        self::assertFalse($tautology1->isDisjointWith($tautology2));
        self::assertFalse($tautology2->isDisjointWith($tautology1));
        self::assertTrue($contradiction1->isDisjointWith($tautology1));
        self::assertTrue($tautology2->isDisjointWith($contradiction1));
        self::assertFalse($tautology1->isDisjointWith(Spec::allInstancesOfType(AlgebraCustomer::class)));
        self::assertFalse($tautology1->isDisjointWith(Spec::is(new AlgebraCustomer(12))));
        self::assertFalse($tautology1->isDisjointWith(Spec::equalTo(10)));
        self::assertFalse($tautology1->isDisjointWith(Spec::blankString()));
        self::assertFalse($tautology1->isDisjointWith(Spec::createDateStringSpecification('Y-n-j')));
        $negatedContradiction1 = Spec::not($contradiction1);
        $negatedContradiction2 = Spec::not($contradiction2);
        $negatedTautology1 = Spec::not($tautology1);
        $negatedTautology2 = Spec::not($tautology2);
        self::assertFalse($negatedContradiction1->isDisjointWith($negatedContradiction1));
        self::assertFalse($negatedContradiction1->isDisjointWith($negatedContradiction2));
        self::assertFalse($negatedContradiction2->isDisjointWith($negatedContradiction1));
        // Deviation from Domian (false): not(alwaysTrue()) is the empty set, and ∅ ∩ ∅ = ∅, so it IS
        // disjoint with itself, exactly as alwaysFalse() already was in this library.
        self::assertTrue($negatedTautology1->isDisjointWith($negatedTautology1));
        self::assertTrue($contradiction1->isDisjointWith($contradiction1));
        self::assertTrue($negatedContradiction1->isDisjointWith($negatedTautology1));
        self::assertTrue($negatedTautology2->isDisjointWith($negatedContradiction1));
        self::assertTrue($contradiction1->isDisjointWith($negatedContradiction1));
        // The contradiction is a special case of everything and generalizes only itself
        self::assertTrue($contradiction1->isSpecialCaseOf(Spec::equalTo(1)));
        self::assertTrue(Spec::equalTo(1)->isGeneralizationOf($contradiction1));
        self::assertTrue($contradiction1->isGeneralizationOf($negatedTautology1));
    }

    public function testDefaultValueSpecifications(): void
    {
        $blankString = Spec::isBlankString();
        $zero = Spec::createDefaultNumberSpecification();
        self::assertTrue($blankString->isSatisfiedBy(null));
        self::assertTrue($blankString->isSatisfiedBy('   '));
        self::assertFalse($blankString->isSatisfiedBy(0));
        self::assertTrue($zero->isSatisfiedBy(0));
        self::assertTrue($zero->isSatisfiedBy(null));
        self::assertFalse($zero->isSatisfiedBy(''));
        self::assertFalse($blankString->isDisjointWith($blankString));
        self::assertTrue($blankString->isDisjointWith($zero));
        $negatedBlankString = Spec::not($blankString);
        $negatedZero = Spec::not($zero);
        self::assertFalse($negatedBlankString->isDisjointWith($negatedBlankString));
        self::assertTrue($negatedBlankString->isDisjointWith($blankString));
        self::assertTrue($negatedZero->isDisjointWith($zero));
        self::assertFalse($negatedBlankString->isDisjointWith($zero));
        self::assertFalse($negatedZero->isDisjointWith($blankString));
    }

    public function testEqualityValueBoundSpecifications(): void
    {
        $ten = Spec::equalTo(10);
        $eleven = Spec::equalTo(11);
        self::assertTrue($ten->isDisjointWith($eleven));
        self::assertTrue($eleven->isDisjointWith($ten));
        $notTen = Spec::not(Spec::equalTo(10));
        self::assertTrue($ten->isDisjointWith($notTen));
        self::assertTrue($notTen->isDisjointWith($ten));
        self::assertFalse($eleven->isDisjointWith($notTen));
        self::assertFalse($notTen->isDisjointWith($eleven));
        $todaySpec = Spec::equalTo(self::$today);
        $yesterdaySpec = Spec::equalTo(self::$yesterday);
        self::assertTrue($todaySpec->isDisjointWith($yesterdaySpec));
        self::assertTrue($yesterdaySpec->isDisjointWith($todaySpec));
        $notTodaySpec = Spec::not(Spec::equalTo(self::$today));
        self::assertTrue($todaySpec->isDisjointWith($notTodaySpec));
        self::assertTrue($notTodaySpec->isDisjointWith($todaySpec));
        self::assertFalse($yesterdaySpec->isDisjointWith($notTodaySpec));
        self::assertFalse($notTodaySpec->isDisjointWith($yesterdaySpec));
        $someStringSpec = Spec::equalTo('aString');
        $anotherStringSpec = Spec::equalTo('anotherString');
        self::assertTrue($someStringSpec->isDisjointWith($anotherStringSpec));
        self::assertTrue($anotherStringSpec->isDisjointWith($someStringSpec));
        $invertedSomeStringSpec = Spec::not(Spec::equalTo('aString'));
        self::assertTrue($someStringSpec->isDisjointWith($invertedSomeStringSpec));
        self::assertTrue($invertedSomeStringSpec->isDisjointWith($someStringSpec));
        self::assertFalse($anotherStringSpec->isDisjointWith($invertedSomeStringSpec));
        self::assertFalse($invertedSomeStringSpec->isDisjointWith($anotherStringSpec));
        // notEqual is the inverse of equalTo
        self::assertTrue(Spec::notEqual(10)->isDisjointWith($ten));
        self::assertTrue(Spec::notEqual(10)->isGeneralizationOf($eleven));
        self::assertTrue($notTen->isGeneralizationOf($eleven));
        self::assertTrue($notTen->isGeneralizationOf(Spec::lessThan(10)));
    }

    public function testEqualityValueBoundSpecificationsAgainstComparisonValueBoundSpecifications(): void
    {
        $ten = Spec::equalTo(10);
        $elevenPoint795 = Spec::equalTo(11.795);
        self::assertTrue($ten->isDisjointWith(Spec::lessThan(10)));
        self::assertFalse($ten->isDisjointWith(Spec::lessThanOrEqualTo(10)));
        self::assertTrue($ten->isDisjointWith(Spec::greaterThan(10)));
        self::assertFalse($ten->isDisjointWith(Spec::greaterThanOrEqualTo(10)));
        self::assertFalse($ten->isDisjointWith(Spec::not(Spec::lessThan(10))));
        self::assertTrue($ten->isDisjointWith(Spec::not(Spec::lessThanOrEqualTo(10))));
        self::assertFalse($ten->isDisjointWith(Spec::not(Spec::greaterThan(10))));
        self::assertTrue($ten->isDisjointWith(Spec::not(Spec::greaterThanOrEqualTo(10))));
        self::assertTrue($elevenPoint795->isDisjointWith(Spec::lessThan(11.795)));
        self::assertFalse($elevenPoint795->isDisjointWith(Spec::lessThanOrEqualTo(11.795)));
        self::assertTrue($elevenPoint795->isDisjointWith(Spec::greaterThan(11.795)));
        self::assertFalse($elevenPoint795->isDisjointWith(Spec::greaterThanOrEqualTo(11.795)));
        $todaySpec = Spec::equalTo(self::$today);
        self::assertTrue($todaySpec->isDisjointWith(Spec::before(self::$today)));
        self::assertFalse($todaySpec->isDisjointWith(Spec::beforeOrAtTheSameTimeAs(self::$today)));
        self::assertTrue($todaySpec->isDisjointWith(Spec::after(self::$today)));
        self::assertFalse($todaySpec->isDisjointWith(Spec::afterOrAtTheSameTimeAs(self::$today)));
        $notTen = Spec::not($ten);
        $notElevenPoint795 = Spec::not($elevenPoint795);
        self::assertFalse($notTen->isDisjointWith($notTen));
        self::assertFalse($notTen->isDisjointWith(Spec::lessThan(10)));
        self::assertFalse($notTen->isDisjointWith(Spec::lessThanOrEqualTo(10)));
        self::assertFalse($notTen->isDisjointWith(Spec::not(Spec::lessThanOrEqualTo(10))));
        self::assertFalse($notTen->isDisjointWith(Spec::greaterThan(10)));
        self::assertFalse($notTen->isDisjointWith(Spec::greaterThanOrEqualTo(10)));
        self::assertFalse($notElevenPoint795->isDisjointWith(Spec::lessThan(11.795)));
        self::assertFalse($notElevenPoint795->isDisjointWith(Spec::lessThanOrEqualTo(11.795)));
        self::assertFalse($notElevenPoint795->isDisjointWith(Spec::greaterThan(11.795)));
        self::assertFalse($notElevenPoint795->isDisjointWith(Spec::greaterThanOrEqualTo(11.795)));
        $notToday = Spec::not(Spec::equalTo(self::$today));
        self::assertFalse($notToday->isDisjointWith($notToday));
        self::assertFalse($notToday->isDisjointWith(Spec::before(self::$today)));
        self::assertFalse($notToday->isDisjointWith(Spec::beforeOrAtTheSameTimeAs(self::$today)));
        self::assertFalse($notToday->isDisjointWith(Spec::after(self::$today)));
        self::assertFalse($notToday->isDisjointWith(Spec::afterOrAtTheSameTimeAs(self::$today)));
    }

    public function testEqualityValueBoundSpecificationsAgainstNegatedComparisonValueBoundSpecifications(): void
    {
        $ten = Spec::equalTo(10);
        $elevenPoint795 = Spec::equalTo(11.795);
        self::assertFalse($ten->isDisjointWith(Spec::not(Spec::lessThan(10))));
        self::assertTrue($ten->isDisjointWith(Spec::not(Spec::lessThanOrEqualTo(10))));
        self::assertFalse($ten->isDisjointWith(Spec::not(Spec::greaterThan(10))));
        self::assertTrue($ten->isDisjointWith(Spec::not(Spec::greaterThanOrEqualTo(10))));
        self::assertFalse($elevenPoint795->isDisjointWith(Spec::not(Spec::lessThan(11.795))));
        self::assertTrue($elevenPoint795->isDisjointWith(Spec::not(Spec::lessThanOrEqualTo(11.795))));
        self::assertFalse($elevenPoint795->isDisjointWith(Spec::not(Spec::greaterThan(11.795))));
        self::assertTrue($elevenPoint795->isDisjointWith(Spec::not(Spec::greaterThanOrEqualTo(11.795))));
        $todaySpec = Spec::equalTo(self::$today);
        self::assertFalse($todaySpec->isDisjointWith(Spec::not(Spec::before(self::$today))));
        self::assertTrue($todaySpec->isDisjointWith(Spec::not(Spec::beforeOrAtTheSameTimeAs(self::$today))));
        self::assertFalse($todaySpec->isDisjointWith(Spec::not(Spec::after(self::$today))));
        self::assertTrue($todaySpec->isDisjointWith(Spec::not(Spec::afterOrAtTheSameTimeAs(self::$today))));
    }

    /**
     * Builds a specification from the expression used in the Domian tables (e.g. "not(lessThan10)",
     * "greaterThanOrEqualTo(9.99D)", "afterOrAtTheSameTimeAsJustAfter").
     *
     * @param array<string, ISpecification> $vars
     */
    private static function expr(string $expression, array $vars): ISpecification
    {
        $expression = trim($expression);
        if (isset($vars[$expression])) {
            return $vars[$expression];
        }
        if (preg_match('/^(\w+)\((.*)\)$/', $expression, $m) === 1) {
            if ($m[1] === 'not') {
                return new NotSpecification(self::expr($m[2], $vars));
            }
            $literal = rtrim($m[2], 'FDfd');
            $value = str_contains($literal, '.') ? (float) $literal : (int) $literal;
            return Spec::{$m[1]}($value);
        }

        throw new \InvalidArgumentException("Unknown table expression: {$expression}");
    }

    /**
     * @param array<string, ISpecification> $vars
     * @param array<int, array{bool, string, string}> $rows
     */
    private function assertDisjointnessTable(array $vars, array $rows, string $table): void
    {
        foreach ($rows as $index => [$expected, $a, $b]) {
            $actual = self::expr($a, $vars)->isDisjointWith(self::expr($b, $vars));
            self::assertSame($expected, $actual, sprintf('%s row %d: %s.isDisjointWith(%s)', $table, $index + 1, $a, $b));
        }
    }

    /**
     * @return array<string, ISpecification>
     */
    private static function numericVars(int|float $ten, int|float $eleven, int|float $six): array
    {
        return [
            'lessThan10' => Spec::lessThan($ten),
            'lessThanOrEqualTo10' => Spec::lessThanOrEqualTo($ten),
            'greaterThan10' => Spec::greaterThan($ten),
            'greaterThanOrEqualTo10' => Spec::greaterThanOrEqualTo($ten),
            'lessThan11' => Spec::lessThan($eleven),
            'lessThanOrEqualTo11' => Spec::lessThanOrEqualTo($eleven),
            'greaterThan11' => Spec::greaterThan($eleven),
            'greaterThanOrEqualTo11' => Spec::greaterThanOrEqualTo($eleven),
            'lessThan6' => Spec::lessThan($six),
            'lessThanOrEqualTo6' => Spec::lessThanOrEqualTo($six),
            'greaterThan6' => Spec::greaterThan($six),
            'greaterThanOrEqualTo6' => Spec::greaterThanOrEqualTo($six),
        ];
    }

    /**
     * IsDisjointWith_ValueBoundSpecifications_Test.testComparableValueBoundSpecifications_Integer (48 rows):
     * there is no integer between 10 and 11, so greaterThan(10) ⟂ lessThan(11).
     */
    public function testComparableValueBoundSpecificationsInteger(): void
    {
        $this->assertDisjointnessTable(self::numericVars(10, 11, 6), [
            [false, 'lessThan10', 'lessThan10'],
            [false, 'lessThan10', 'lessThanOrEqualTo10'],
            [true, 'lessThan10', 'greaterThan10'],
            [true, 'lessThan10', 'greaterThanOrEqualTo10'],
            [false, 'lessThan10', 'lessThan11'],
            [false, 'lessThan10', 'lessThanOrEqualTo11'],
            [true, 'lessThan10', 'greaterThan11'],
            [true, 'lessThan10', 'greaterThanOrEqualTo11'],
            [false, 'lessThan10', 'lessThan6'],
            [false, 'lessThan10', 'lessThanOrEqualTo6'],
            [false, 'lessThan10', 'greaterThan6'],
            [false, 'lessThan10', 'greaterThanOrEqualTo6'],
            [false, 'lessThanOrEqualTo10', 'lessThan10'],
            [false, 'lessThanOrEqualTo10', 'lessThanOrEqualTo10'],
            [true, 'lessThanOrEqualTo10', 'greaterThan10'],
            [false, 'lessThanOrEqualTo10', 'greaterThanOrEqualTo10'],
            [false, 'lessThanOrEqualTo10', 'lessThan11'],
            [false, 'lessThanOrEqualTo10', 'lessThanOrEqualTo11'],
            [true, 'lessThanOrEqualTo10', 'greaterThan11'],
            [true, 'lessThanOrEqualTo10', 'greaterThanOrEqualTo11'],
            [false, 'lessThanOrEqualTo10', 'lessThan6'],
            [false, 'lessThanOrEqualTo10', 'lessThanOrEqualTo6'],
            [false, 'lessThanOrEqualTo10', 'greaterThan6'],
            [false, 'lessThanOrEqualTo10', 'greaterThanOrEqualTo6'],
            [true, 'greaterThan10', 'lessThan10'],
            [true, 'greaterThan10', 'lessThanOrEqualTo10'],
            [false, 'greaterThan10', 'greaterThan10'],
            [false, 'greaterThan10', 'greaterThanOrEqualTo10'],
            [true, 'greaterThan10', 'lessThan11'],
            [false, 'greaterThan10', 'lessThanOrEqualTo11'],
            [false, 'greaterThan10', 'greaterThan11'],
            [false, 'greaterThan10', 'greaterThanOrEqualTo11'],
            [true, 'greaterThan10', 'lessThan6'],
            [true, 'greaterThan10', 'lessThanOrEqualTo6'],
            [false, 'greaterThan10', 'greaterThan6'],
            [false, 'greaterThan10', 'greaterThanOrEqualTo6'],
            [true, 'greaterThanOrEqualTo10', 'lessThan10'],
            [false, 'greaterThanOrEqualTo10', 'lessThanOrEqualTo10'],
            [false, 'greaterThanOrEqualTo10', 'greaterThan10'],
            [false, 'greaterThanOrEqualTo10', 'greaterThanOrEqualTo10'],
            [false, 'greaterThanOrEqualTo10', 'lessThan11'],
            [false, 'greaterThanOrEqualTo10', 'lessThanOrEqualTo11'],
            [false, 'greaterThanOrEqualTo10', 'greaterThan11'],
            [false, 'greaterThanOrEqualTo10', 'greaterThanOrEqualTo11'],
            [true, 'greaterThanOrEqualTo10', 'lessThan6'],
            [true, 'greaterThanOrEqualTo10', 'lessThanOrEqualTo6'],
            [false, 'greaterThanOrEqualTo10', 'greaterThan6'],
            [false, 'greaterThanOrEqualTo10', 'greaterThanOrEqualTo6'],
        ], 'integer');
    }

    /**
     * IsDisjointWith_ValueBoundSpecifications_Test.testNegatedComparableValueBoundSpecifications_Integer (156 rows):
     * ¬(x < 10) ≡ x >= 10, ¬¬A ≡ A, up to three levels of negation.
     */
    public function testNegatedComparableValueBoundSpecificationsInteger(): void
    {
        $this->assertDisjointnessTable(self::numericVars(10, 11, 6), [
            [true, 'lessThan10', 'not(lessThan10)'],
            [true, 'lessThan10', 'not(lessThanOrEqualTo10)'],
            [false, 'lessThan10', 'not(greaterThan10)'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo10)'],
            [false, 'lessThan10', 'not(not(not(greaterThanOrEqualTo10)))'],
            [true, 'not(lessThan10)', 'lessThan10'],
            [false, 'not(lessThan10)', 'lessThanOrEqualTo10'],
            [false, 'not(lessThan10)', 'greaterThan10'],
            [false, 'not(lessThan10)', 'greaterThanOrEqualTo10'],
            [false, 'not(lessThan10)', 'not(lessThan10)'],
            [false, 'not(lessThan10)', 'not(lessThanOrEqualTo10)'],
            [false, 'not(lessThan10)', 'not(greaterThan10)'],
            [true, 'not(lessThan10)', 'not(greaterThanOrEqualTo10)'],
            [false, 'not(not(lessThan10))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [true, 'not(not(not(lessThan10)))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [true, 'lessThan10', 'not(lessThan11)'],
            [true, 'lessThan10', 'not(lessThanOrEqualTo11)'],
            [false, 'lessThan10', 'not(greaterThan11)'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo11)'],
            [false, 'not(lessThan10)', 'lessThan11'],
            [false, 'not(lessThan10)', 'lessThanOrEqualTo11'],
            [false, 'not(lessThan10)', 'greaterThan11'],
            [false, 'not(lessThan10)', 'greaterThanOrEqualTo11'],
            [false, 'not(lessThan10)', 'not(lessThan11)'],
            [false, 'not(lessThan10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(lessThan10)', 'not(greaterThan11)'],
            [false, 'not(lessThan10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'lessThan10', 'not(lessThan6)'],
            [false, 'lessThan10', 'not(lessThanOrEqualTo6)'],
            [false, 'lessThan10', 'not(greaterThan6)'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo6)'],
            [true, 'not(lessThan10)', 'lessThan6'],
            [true, 'not(lessThan10)', 'lessThanOrEqualTo6'],
            [false, 'not(lessThan10)', 'greaterThan6'],
            [false, 'not(lessThan10)', 'greaterThanOrEqualTo6'],
            [false, 'not(lessThan10)', 'not(lessThan6)'],
            [false, 'not(lessThan10)', 'not(lessThanOrEqualTo6)'],
            [true, 'not(lessThan10)', 'not(greaterThan6)'],
            [true, 'not(lessThan10)', 'not(greaterThanOrEqualTo6)'],
            [false, 'lessThanOrEqualTo10', 'not(lessThan10)'],
            [true, 'lessThanOrEqualTo10', 'not(lessThanOrEqualTo10)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThan10)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThanOrEqualTo10)'],
            [false, 'lessThanOrEqualTo10', 'not(not(not(greaterThanOrEqualTo10)))'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThan10'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThanOrEqualTo10'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThan10'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThanOrEqualTo10'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThan10)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThanOrEqualTo10)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThan10)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThanOrEqualTo10)'],
            [false, 'not(not(lessThanOrEqualTo10))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [true, 'not(not(not(lessThanOrEqualTo10)))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [true, 'lessThanOrEqualTo10', 'not(lessThan11)'],
            [true, 'lessThanOrEqualTo10', 'not(lessThanOrEqualTo11)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThan11)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThanOrEqualTo11)'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThan11'],
            [false, 'not(lessThanOrEqualTo10)', 'lessThanOrEqualTo11'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThan11'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThanOrEqualTo11'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThan11)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(greaterThan11)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'lessThanOrEqualTo10', 'not(lessThan6)'],
            [false, 'lessThanOrEqualTo10', 'not(lessThanOrEqualTo6)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThan6)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThanOrEqualTo6)'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThan6'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThanOrEqualTo6'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThan6'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThanOrEqualTo6'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThan6)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThanOrEqualTo6)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThan6)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThanOrEqualTo6)'],
            [false, 'greaterThan10', 'not(lessThan10)'],
            [false, 'greaterThan10', 'not(lessThanOrEqualTo10)'],
            [true, 'greaterThan10', 'not(greaterThan10)'],
            [true, 'greaterThan10', 'not(greaterThanOrEqualTo10)'],
            [true, 'greaterThan10', 'not(not(not(greaterThanOrEqualTo10)))'],
            [false, 'not(greaterThan10)', 'lessThan10'],
            [false, 'not(greaterThan10)', 'lessThanOrEqualTo10'],
            [true, 'not(greaterThan10)', 'greaterThan10'],
            [false, 'not(greaterThan10)', 'greaterThanOrEqualTo10'],
            [false, 'not(greaterThan10)', 'not(lessThan10)'],
            [true, 'not(greaterThan10)', 'not(lessThanOrEqualTo10)'],
            [false, 'not(greaterThan10)', 'not(greaterThan10)'],
            [false, 'not(greaterThan10)', 'not(greaterThanOrEqualTo10)'],
            [true, 'not(not(greaterThan10))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [false, 'not(not(not(greaterThan10)))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [false, 'greaterThan10', 'not(lessThan11)'],
            [false, 'greaterThan10', 'not(lessThanOrEqualTo11)'],
            [false, 'greaterThan10', 'not(greaterThan11)'],
            [true, 'greaterThan10', 'not(greaterThanOrEqualTo11)'],
            [false, 'not(greaterThan10)', 'lessThan11'],
            [false, 'not(greaterThan10)', 'lessThanOrEqualTo11'],
            [true, 'not(greaterThan10)', 'greaterThan11'],
            [true, 'not(greaterThan10)', 'greaterThanOrEqualTo11'],
            [true, 'not(greaterThan10)', 'not(lessThan11)'],
            [true, 'not(greaterThan10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(greaterThan10)', 'not(greaterThan11)'],
            [false, 'not(greaterThan10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'greaterThan10', 'not(lessThan6)'],
            [false, 'greaterThan10', 'not(lessThanOrEqualTo6)'],
            [true, 'greaterThan10', 'not(greaterThan6)'],
            [true, 'greaterThan10', 'not(greaterThanOrEqualTo6)'],
            [false, 'not(greaterThan10)', 'lessThan6'],
            [false, 'not(greaterThan10)', 'lessThanOrEqualTo6'],
            [false, 'not(greaterThan10)', 'greaterThan6'],
            [false, 'not(greaterThan10)', 'greaterThanOrEqualTo6'],
            [false, 'not(greaterThan10)', 'not(lessThan6)'],
            [false, 'not(greaterThan10)', 'not(lessThanOrEqualTo6)'],
            [false, 'not(greaterThan10)', 'not(greaterThan6)'],
            [false, 'not(greaterThan10)', 'not(greaterThanOrEqualTo6)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThan10)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThanOrEqualTo10)'],
            [false, 'greaterThanOrEqualTo10', 'not(greaterThan10)'],
            [true, 'greaterThanOrEqualTo10', 'not(greaterThanOrEqualTo10)'],
            [true, 'greaterThanOrEqualTo10', 'not(not(not(greaterThanOrEqualTo10)))'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThan10'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThanOrEqualTo10'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThan10'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThanOrEqualTo10'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThan10)'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThanOrEqualTo10)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThan10)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThanOrEqualTo10)'],
            [true, 'not(not(greaterThanOrEqualTo10))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [false, 'not(not(not(greaterThanOrEqualTo10)))', 'not(not(not(greaterThanOrEqualTo10)))'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThan11)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThanOrEqualTo11)'],
            [false, 'greaterThanOrEqualTo10', 'not(greaterThan11)'],
            [false, 'greaterThanOrEqualTo10', 'not(greaterThanOrEqualTo11)'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThan11'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThanOrEqualTo11'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThan11'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThanOrEqualTo11'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThan11)'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThan11)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThan6)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThanOrEqualTo6)'],
            [true, 'greaterThanOrEqualTo10', 'not(greaterThan6)'],
            [true, 'greaterThanOrEqualTo10', 'not(greaterThanOrEqualTo6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThan6'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThanOrEqualTo6'],
            [false, 'not(greaterThanOrEqualTo10)', 'greaterThan6'],
            [false, 'not(greaterThanOrEqualTo10)', 'greaterThanOrEqualTo6'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(lessThan6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(lessThanOrEqualTo6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThan6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThanOrEqualTo6)'],
        ], 'negated integer');
    }

    /**
     * IsDisjointWith_ValueBoundSpecifications_Test.testComparableValueBoundSpecifications_Double (207 rows):
     * a decimal domain is dense, so greaterThan(10.0) and lessThan(11.0) intersect.
     */
    public function testComparableValueBoundSpecificationsDouble(): void
    {
        $this->assertDisjointnessTable(self::numericVars(10.00, 11.00, 6.00), [
            [false, 'lessThan10', 'lessThan10'],
            [false, 'lessThan10', 'lessThanOrEqualTo10'],
            [true, 'lessThan10', 'greaterThan10'],
            [true, 'lessThan10', 'greaterThanOrEqualTo10'],
            [false, 'lessThan10', 'lessThan11'],
            [false, 'lessThan10', 'lessThanOrEqualTo11'],
            [true, 'lessThan10', 'greaterThan11'],
            [true, 'lessThan10', 'greaterThanOrEqualTo11'],
            [false, 'lessThan10', 'lessThan6'],
            [false, 'lessThan10', 'lessThanOrEqualTo6'],
            [false, 'lessThan10', 'greaterThan6'],
            [false, 'lessThan10', 'greaterThanOrEqualTo6'],
            [false, 'lessThanOrEqualTo10', 'lessThan10'],
            [false, 'lessThanOrEqualTo10', 'lessThanOrEqualTo10'],
            [true, 'lessThanOrEqualTo10', 'greaterThan10'],
            [false, 'lessThanOrEqualTo10', 'greaterThanOrEqualTo10'],
            [false, 'lessThanOrEqualTo10', 'lessThan11'],
            [false, 'lessThanOrEqualTo10', 'lessThanOrEqualTo11'],
            [true, 'lessThanOrEqualTo10', 'greaterThan11'],
            [true, 'lessThanOrEqualTo10', 'greaterThanOrEqualTo11'],
            [false, 'lessThanOrEqualTo10', 'lessThan6'],
            [false, 'lessThanOrEqualTo10', 'lessThanOrEqualTo6'],
            [false, 'lessThanOrEqualTo10', 'greaterThan6'],
            [false, 'lessThanOrEqualTo10', 'greaterThanOrEqualTo6'],
            [true, 'greaterThan10', 'lessThan10'],
            [true, 'greaterThan10', 'lessThanOrEqualTo10'],
            [false, 'greaterThan10', 'greaterThan10'],
            [false, 'greaterThan10', 'greaterThanOrEqualTo10'],
            [false, 'greaterThan10', 'lessThan11'],
            [false, 'greaterThan10', 'lessThanOrEqualTo11'],
            [false, 'greaterThan10', 'greaterThan11'],
            [false, 'greaterThan10', 'greaterThanOrEqualTo11'],
            [true, 'greaterThan10', 'lessThan6'],
            [true, 'greaterThan10', 'lessThanOrEqualTo6'],
            [false, 'greaterThan10', 'greaterThan6'],
            [false, 'greaterThan10', 'greaterThanOrEqualTo6'],
            [true, 'greaterThanOrEqualTo10', 'lessThan10'],
            [false, 'greaterThanOrEqualTo10', 'lessThanOrEqualTo10'],
            [false, 'greaterThanOrEqualTo10', 'greaterThan10'],
            [false, 'greaterThanOrEqualTo10', 'greaterThanOrEqualTo10'],
            [false, 'greaterThanOrEqualTo10', 'lessThan11'],
            [false, 'greaterThanOrEqualTo10', 'lessThanOrEqualTo11'],
            [false, 'greaterThanOrEqualTo10', 'greaterThan11'],
            [false, 'greaterThanOrEqualTo10', 'greaterThanOrEqualTo11'],
            [true, 'greaterThanOrEqualTo10', 'lessThan6'],
            [true, 'greaterThanOrEqualTo10', 'lessThanOrEqualTo6'],
            [false, 'greaterThanOrEqualTo10', 'greaterThan6'],
            [false, 'greaterThanOrEqualTo10', 'greaterThanOrEqualTo6'],
            [true, 'greaterThan11', 'lessThan10'],
            [false, 'lessThan11', 'greaterThan10'],
            [true, 'lessThan(11.41)', 'greaterThan(11.42)'],
            [true, 'lessThan(11.42)', 'greaterThan(11.42)'],
            [false, 'lessThan(11.43)', 'greaterThan(11.42)'],
            [false, 'lessThan(11.44)', 'greaterThan(11.42)'],
            [false, 'lessThan(11.44F)', 'greaterThan(11.42F)'],
            [false, 'lessThan(11.44D)', 'greaterThan(11.42D)'],
            [true, 'lessThan10', 'not(lessThan10)'],
            [true, 'lessThan10', 'not(lessThanOrEqualTo10)'],
            [false, 'lessThan10', 'not(greaterThan10)'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo10)'],
            [true, 'not(lessThan10)', 'lessThan10'],
            [false, 'not(lessThan10)', 'lessThanOrEqualTo10'],
            [false, 'not(lessThan10)', 'greaterThan10'],
            [false, 'not(lessThan10)', 'greaterThanOrEqualTo10'],
            [false, 'not(lessThan10)', 'not(lessThan10)'],
            [false, 'not(lessThan10)', 'not(lessThanOrEqualTo10)'],
            [false, 'not(lessThan10)', 'not(greaterThan10)'],
            [true, 'not(lessThan10)', 'not(greaterThanOrEqualTo10)'],
            [true, 'lessThan10', 'not(lessThan11)'],
            [true, 'lessThan10', 'not(lessThanOrEqualTo11)'],
            [false, 'lessThan10', 'not(greaterThan11)'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo11)'],
            [false, 'not(lessThan10)', 'lessThan11'],
            [false, 'not(lessThan10)', 'lessThanOrEqualTo11'],
            [false, 'not(lessThan10)', 'greaterThan11'],
            [false, 'not(lessThan10)', 'greaterThanOrEqualTo11'],
            [false, 'not(lessThan10)', 'not(lessThan11)'],
            [false, 'not(lessThan10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(lessThan10)', 'not(greaterThan11)'],
            [false, 'not(lessThan10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'lessThan10', 'not(lessThan6)'],
            [false, 'lessThan10', 'not(lessThanOrEqualTo6)'],
            [false, 'lessThan10', 'not(greaterThan6)'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo6)'],
            [true, 'not(lessThan10)', 'lessThan6'],
            [true, 'not(lessThan10)', 'lessThanOrEqualTo6'],
            [false, 'not(lessThan10)', 'greaterThan6'],
            [false, 'not(lessThan10)', 'greaterThanOrEqualTo6'],
            [false, 'not(lessThan10)', 'not(lessThan6)'],
            [false, 'not(lessThan10)', 'not(lessThanOrEqualTo6)'],
            [true, 'not(lessThan10)', 'not(greaterThan6)'],
            [true, 'not(lessThan10)', 'not(greaterThanOrEqualTo6)'],
            [true, 'lessThan10', 'greaterThanOrEqualTo(10.00D)'],
            [false, 'lessThan10', 'greaterThanOrEqualTo(9.99D)'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo(10.00D))'],
            [false, 'lessThan(10)', 'not(greaterThanOrEqualTo(9))'],
            [false, 'lessThan10', 'not(greaterThanOrEqualTo(9.99D))'],
            [true, 'lessThan10', 'not(not(greaterThanOrEqualTo(10.00D)))'],
            [false, 'lessThan10', 'not(not(greaterThanOrEqualTo(9.99D)))'],
            [false, 'lessThanOrEqualTo10', 'not(lessThan10)'],
            [true, 'lessThanOrEqualTo10', 'not(lessThanOrEqualTo10)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThan10)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThanOrEqualTo10)'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThan10'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThanOrEqualTo10'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThan10'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThanOrEqualTo10'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThan10)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThanOrEqualTo10)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThan10)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThanOrEqualTo10)'],
            [true, 'lessThanOrEqualTo10', 'not(lessThan11)'],
            [true, 'lessThanOrEqualTo10', 'not(lessThanOrEqualTo11)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThan11)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThanOrEqualTo11)'],
            [false, 'not(lessThanOrEqualTo10)', 'lessThan11'],
            [false, 'not(lessThanOrEqualTo10)', 'lessThanOrEqualTo11'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThan11'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThanOrEqualTo11'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThan11)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(greaterThan11)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'lessThanOrEqualTo10', 'not(lessThan6)'],
            [false, 'lessThanOrEqualTo10', 'not(lessThanOrEqualTo6)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThan6)'],
            [false, 'lessThanOrEqualTo10', 'not(greaterThanOrEqualTo6)'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThan6'],
            [true, 'not(lessThanOrEqualTo10)', 'lessThanOrEqualTo6'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThan6'],
            [false, 'not(lessThanOrEqualTo10)', 'greaterThanOrEqualTo6'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThan6)'],
            [false, 'not(lessThanOrEqualTo10)', 'not(lessThanOrEqualTo6)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThan6)'],
            [true, 'not(lessThanOrEqualTo10)', 'not(greaterThanOrEqualTo6)'],
            [false, 'greaterThan10', 'not(lessThan10)'],
            [false, 'greaterThan10', 'not(lessThanOrEqualTo10)'],
            [true, 'greaterThan10', 'not(greaterThan10)'],
            [true, 'greaterThan10', 'not(greaterThanOrEqualTo10)'],
            [false, 'not(greaterThan10)', 'lessThan10'],
            [false, 'not(greaterThan10)', 'lessThanOrEqualTo10'],
            [true, 'not(greaterThan10)', 'greaterThan10'],
            [false, 'not(greaterThan10)', 'greaterThanOrEqualTo10'],
            [false, 'not(greaterThan10)', 'not(lessThan10)'],
            [true, 'not(greaterThan10)', 'not(lessThanOrEqualTo10)'],
            [false, 'not(greaterThan10)', 'not(greaterThan10)'],
            [false, 'not(greaterThan10)', 'not(greaterThanOrEqualTo10)'],
            [false, 'greaterThan10', 'not(lessThan11)'],
            [false, 'greaterThan10', 'not(lessThanOrEqualTo11)'],
            [false, 'greaterThan10', 'not(greaterThan11)'],
            [false, 'greaterThan10', 'not(greaterThanOrEqualTo11)'],
            [false, 'not(greaterThan10)', 'lessThan11'],
            [false, 'not(greaterThan10)', 'lessThanOrEqualTo11'],
            [true, 'not(greaterThan10)', 'greaterThan11'],
            [true, 'not(greaterThan10)', 'greaterThanOrEqualTo11'],
            [true, 'not(greaterThan10)', 'not(lessThan11)'],
            [true, 'not(greaterThan10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(greaterThan10)', 'not(greaterThan11)'],
            [false, 'not(greaterThan10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'greaterThan10', 'not(lessThan6)'],
            [false, 'greaterThan10', 'not(lessThanOrEqualTo6)'],
            [true, 'greaterThan10', 'not(greaterThan6)'],
            [true, 'greaterThan10', 'not(greaterThanOrEqualTo6)'],
            [false, 'not(greaterThan10)', 'lessThan6'],
            [false, 'not(greaterThan10)', 'lessThanOrEqualTo6'],
            [false, 'not(greaterThan10)', 'greaterThan6'],
            [false, 'not(greaterThan10)', 'greaterThanOrEqualTo6'],
            [false, 'not(greaterThan10)', 'not(lessThan6)'],
            [false, 'not(greaterThan10)', 'not(lessThanOrEqualTo6)'],
            [false, 'not(greaterThan10)', 'not(greaterThan6)'],
            [false, 'not(greaterThan10)', 'not(greaterThanOrEqualTo6)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThan10)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThanOrEqualTo10)'],
            [false, 'greaterThanOrEqualTo10', 'not(greaterThan10)'],
            [true, 'greaterThanOrEqualTo10', 'not(greaterThanOrEqualTo10)'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThan10'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThanOrEqualTo10'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThan10'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThanOrEqualTo10'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThan10)'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThanOrEqualTo10)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThan10)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThanOrEqualTo10)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThan11)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThanOrEqualTo11)'],
            [false, 'greaterThanOrEqualTo10', 'not(greaterThan11)'],
            [false, 'greaterThanOrEqualTo10', 'not(greaterThanOrEqualTo11)'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThan11'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThanOrEqualTo11'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThan11'],
            [true, 'not(greaterThanOrEqualTo10)', 'greaterThanOrEqualTo11'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThan11)'],
            [true, 'not(greaterThanOrEqualTo10)', 'not(lessThanOrEqualTo11)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThan11)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThanOrEqualTo11)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThan6)'],
            [false, 'greaterThanOrEqualTo10', 'not(lessThanOrEqualTo6)'],
            [true, 'greaterThanOrEqualTo10', 'not(greaterThan6)'],
            [true, 'greaterThanOrEqualTo10', 'not(greaterThanOrEqualTo6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThan6'],
            [false, 'not(greaterThanOrEqualTo10)', 'lessThanOrEqualTo6'],
            [false, 'not(greaterThanOrEqualTo10)', 'greaterThan6'],
            [false, 'not(greaterThanOrEqualTo10)', 'greaterThanOrEqualTo6'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(lessThan6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(lessThanOrEqualTo6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThan6)'],
            [false, 'not(greaterThanOrEqualTo10)', 'not(greaterThanOrEqualTo6)'],
        ], 'double');
    }

    /**
     * IsDisjointWith_ValueBoundSpecifications_Test.testComparableValueBoundSpecifications_Timestamp (192 rows):
     * instants one second apart on a dense time line.
     */
    public function testComparableValueBoundSpecificationsTimestamp(): void
    {
        $now = new DateTimeImmutable('2026-10-09 12:00:00');
        $justBefore = $now->modify('-1 second');
        $justAfter = $now->modify('+1 second');
        $vars = [];
        foreach (['Now' => $now, 'JustBefore' => $justBefore, 'JustAfter' => $justAfter] as $suffix => $instant) {
            $vars['before' . $suffix] = Spec::before($instant);
            $vars['beforeOrAtTheSameTimeAs' . $suffix] = Spec::beforeOrAtTheSameTimeAs($instant);
            $vars['after' . $suffix] = Spec::after($instant);
            $vars['afterOrAtTheSameTimeAs' . $suffix] = Spec::afterOrAtTheSameTimeAs($instant);
        }
        $this->assertDisjointnessTable($vars, [
            [false, 'beforeNow', 'beforeNow'],
            [false, 'beforeNow', 'beforeOrAtTheSameTimeAsNow'],
            [true, 'beforeNow', 'afterNow'],
            [true, 'beforeNow', 'afterOrAtTheSameTimeAsNow'],
            [false, 'beforeNow', 'beforeJustAfter'],
            [false, 'beforeNow', 'beforeOrAtTheSameTimeAsJustAfter'],
            [true, 'beforeNow', 'afterJustAfter'],
            [true, 'beforeNow', 'afterOrAtTheSameTimeAsJustAfter'],
            [false, 'beforeNow', 'beforeJustBefore'],
            [false, 'beforeNow', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'beforeNow', 'afterJustBefore'],
            [false, 'beforeNow', 'afterOrAtTheSameTimeAsJustBefore'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'beforeNow'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'beforeOrAtTheSameTimeAsNow'],
            [true, 'beforeOrAtTheSameTimeAsNow', 'afterNow'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'afterOrAtTheSameTimeAsNow'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'beforeJustAfter'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'beforeOrAtTheSameTimeAsJustAfter'],
            [true, 'beforeOrAtTheSameTimeAsNow', 'afterJustAfter'],
            [true, 'beforeOrAtTheSameTimeAsNow', 'afterOrAtTheSameTimeAsJustAfter'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'beforeJustBefore'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'afterJustBefore'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'afterOrAtTheSameTimeAsJustBefore'],
            [true, 'afterNow', 'beforeNow'],
            [true, 'afterNow', 'beforeOrAtTheSameTimeAsNow'],
            [false, 'afterNow', 'afterNow'],
            [false, 'afterNow', 'afterOrAtTheSameTimeAsNow'],
            [false, 'afterNow', 'beforeJustAfter'],
            [false, 'afterNow', 'beforeOrAtTheSameTimeAsJustAfter'],
            [false, 'afterNow', 'afterJustAfter'],
            [false, 'afterNow', 'afterOrAtTheSameTimeAsJustAfter'],
            [true, 'afterNow', 'beforeJustBefore'],
            [true, 'afterNow', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'afterNow', 'afterJustBefore'],
            [false, 'afterNow', 'afterOrAtTheSameTimeAsJustBefore'],
            [true, 'afterOrAtTheSameTimeAsNow', 'beforeNow'],
            [false, 'afterOrAtTheSameTimeAsNow', 'beforeOrAtTheSameTimeAsNow'],
            [false, 'afterOrAtTheSameTimeAsNow', 'afterNow'],
            [false, 'afterOrAtTheSameTimeAsNow', 'afterOrAtTheSameTimeAsNow'],
            [false, 'afterOrAtTheSameTimeAsNow', 'beforeJustAfter'],
            [false, 'afterOrAtTheSameTimeAsNow', 'beforeOrAtTheSameTimeAsJustAfter'],
            [false, 'afterOrAtTheSameTimeAsNow', 'afterJustAfter'],
            [false, 'afterOrAtTheSameTimeAsNow', 'afterOrAtTheSameTimeAsJustAfter'],
            [true, 'afterOrAtTheSameTimeAsNow', 'beforeJustBefore'],
            [true, 'afterOrAtTheSameTimeAsNow', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'afterOrAtTheSameTimeAsNow', 'afterJustBefore'],
            [false, 'afterOrAtTheSameTimeAsNow', 'afterOrAtTheSameTimeAsJustBefore'],
            [true, 'beforeNow', 'not(beforeNow)'],
            [true, 'beforeNow', 'not(beforeOrAtTheSameTimeAsNow)'],
            [false, 'beforeNow', 'not(afterNow)'],
            [false, 'beforeNow', 'not(afterOrAtTheSameTimeAsNow)'],
            [true, 'not(beforeNow)', 'beforeNow'],
            [false, 'not(beforeNow)', 'beforeOrAtTheSameTimeAsNow'],
            [false, 'not(beforeNow)', 'afterNow'],
            [false, 'not(beforeNow)', 'afterOrAtTheSameTimeAsNow'],
            [false, 'not(beforeNow)', 'not(beforeNow)'],
            [false, 'not(beforeNow)', 'not(beforeOrAtTheSameTimeAsNow)'],
            [false, 'not(beforeNow)', 'not(afterNow)'],
            [true, 'not(beforeNow)', 'not(afterOrAtTheSameTimeAsNow)'],
            [true, 'beforeNow', 'not(beforeJustAfter)'],
            [true, 'beforeNow', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'beforeNow', 'not(afterJustAfter)'],
            [false, 'beforeNow', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(beforeNow)', 'beforeJustAfter'],
            [false, 'not(beforeNow)', 'beforeOrAtTheSameTimeAsJustAfter'],
            [false, 'not(beforeNow)', 'afterJustAfter'],
            [false, 'not(beforeNow)', 'afterOrAtTheSameTimeAsJustAfter'],
            [false, 'not(beforeNow)', 'not(beforeJustAfter)'],
            [false, 'not(beforeNow)', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(beforeNow)', 'not(afterJustAfter)'],
            [false, 'not(beforeNow)', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'beforeNow', 'not(beforeJustBefore)'],
            [false, 'beforeNow', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [false, 'beforeNow', 'not(beforeJustBefore)'],
            [false, 'beforeNow', 'not(afterOrAtTheSameTimeAsJustBefore)'],
            [true, 'not(beforeNow)', 'beforeJustBefore'],
            [true, 'not(beforeNow)', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'not(beforeNow)', 'afterJustBefore'],
            [false, 'not(beforeNow)', 'afterOrAtTheSameTimeAsJustBefore'],
            [false, 'not(beforeNow)', 'not(beforeJustBefore)'],
            [false, 'not(beforeNow)', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [true, 'not(beforeNow)', 'not(afterJustBefore)'],
            [true, 'not(beforeNow)', 'not(afterOrAtTheSameTimeAsJustBefore)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(beforeNow)'],
            [true, 'beforeOrAtTheSameTimeAsNow', 'not(beforeOrAtTheSameTimeAsNow)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(afterNow)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(afterOrAtTheSameTimeAsNow)'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'beforeNow'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'beforeOrAtTheSameTimeAsNow'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'afterNow'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'afterOrAtTheSameTimeAsNow'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(beforeNow)'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(beforeOrAtTheSameTimeAsNow)'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'not(afterNow)'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'not(afterOrAtTheSameTimeAsNow)'],
            [true, 'beforeOrAtTheSameTimeAsNow', 'not(beforeJustAfter)'],
            [true, 'beforeOrAtTheSameTimeAsNow', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(afterJustAfter)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'beforeJustAfter'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'beforeOrAtTheSameTimeAsJustAfter'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'afterJustAfter'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'afterOrAtTheSameTimeAsJustAfter'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(beforeJustAfter)'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(afterJustAfter)'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(beforeJustBefore)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(afterJustBefore)'],
            [false, 'beforeOrAtTheSameTimeAsNow', 'not(afterOrAtTheSameTimeAsJustBefore)'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'beforeJustBefore'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'afterJustBefore'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'afterOrAtTheSameTimeAsJustBefore'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(beforeJustBefore)'],
            [false, 'not(beforeOrAtTheSameTimeAsNow)', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'not(afterJustBefore)'],
            [true, 'not(beforeOrAtTheSameTimeAsNow)', 'not(afterOrAtTheSameTimeAsJustBefore)'],
            [false, 'afterNow', 'not(beforeNow)'],
            [false, 'afterNow', 'not(beforeOrAtTheSameTimeAsNow)'],
            [true, 'afterNow', 'not(afterNow)'],
            [true, 'afterNow', 'not(afterOrAtTheSameTimeAsNow)'],
            [false, 'not(afterNow)', 'beforeNow'],
            [false, 'not(afterNow)', 'beforeOrAtTheSameTimeAsNow'],
            [true, 'not(afterNow)', 'afterNow'],
            [false, 'not(afterNow)', 'afterOrAtTheSameTimeAsNow'],
            [false, 'not(afterNow)', 'not(beforeNow)'],
            [true, 'not(afterNow)', 'not(beforeOrAtTheSameTimeAsNow)'],
            [false, 'not(afterNow)', 'not(afterNow)'],
            [false, 'not(afterNow)', 'not(afterOrAtTheSameTimeAsNow)'],
            [false, 'afterNow', 'not(beforeJustAfter)'],
            [false, 'afterNow', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'afterNow', 'not(afterJustAfter)'],
            [false, 'afterNow', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(afterNow)', 'beforeJustAfter'],
            [false, 'not(afterNow)', 'beforeOrAtTheSameTimeAsJustAfter'],
            [true, 'not(afterNow)', 'afterJustAfter'],
            [true, 'not(afterNow)', 'afterOrAtTheSameTimeAsJustAfter'],
            [true, 'not(afterNow)', 'not(beforeJustAfter)'],
            [true, 'not(afterNow)', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(afterNow)', 'not(afterJustAfter)'],
            [false, 'not(afterNow)', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'afterNow', 'not(beforeJustBefore)'],
            [false, 'afterNow', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [true, 'afterNow', 'not(afterJustBefore)'],
            [true, 'afterNow', 'not(afterOrAtTheSameTimeAsJustBefore)'],
            [false, 'not(afterNow)', 'beforeJustBefore'],
            [false, 'not(afterNow)', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'not(afterNow)', 'afterJustBefore'],
            [false, 'not(afterNow)', 'afterOrAtTheSameTimeAsJustBefore'],
            [false, 'not(afterNow)', 'not(beforeJustBefore)'],
            [false, 'not(afterNow)', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [false, 'not(afterNow)', 'not(afterJustBefore)'],
            [false, 'not(afterNow)', 'not(afterOrAtTheSameTimeAsJustBefore)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(beforeNow)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(beforeOrAtTheSameTimeAsNow)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(afterNow)'],
            [true, 'afterOrAtTheSameTimeAsNow', 'not(afterOrAtTheSameTimeAsNow)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'beforeNow'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'beforeOrAtTheSameTimeAsNow'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'afterNow'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'afterOrAtTheSameTimeAsNow'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'not(beforeNow)'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'not(beforeOrAtTheSameTimeAsNow)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(afterNow)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(afterOrAtTheSameTimeAsNow)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(beforeJustAfter)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(afterJustAfter)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'beforeJustAfter'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'beforeOrAtTheSameTimeAsJustAfter'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'afterJustAfter'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'afterOrAtTheSameTimeAsJustAfter'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'not(beforeJustAfter)'],
            [true, 'not(afterOrAtTheSameTimeAsNow)', 'not(beforeOrAtTheSameTimeAsJustAfter)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(afterJustAfter)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(afterOrAtTheSameTimeAsJustAfter)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(beforeJustBefore)'],
            [false, 'afterOrAtTheSameTimeAsNow', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [true, 'afterOrAtTheSameTimeAsNow', 'not(afterJustBefore)'],
            [true, 'afterOrAtTheSameTimeAsNow', 'not(afterOrAtTheSameTimeAsJustBefore)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'beforeJustBefore'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'beforeOrAtTheSameTimeAsJustBefore'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'afterJustBefore'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'afterOrAtTheSameTimeAsJustBefore'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(beforeJustBefore)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(beforeOrAtTheSameTimeAsJustBefore)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(afterJustBefore)'],
            [false, 'not(afterOrAtTheSameTimeAsNow)', 'not(afterOrAtTheSameTimeAsJustBefore)'],
        ], 'timestamp');
    }

    // ==========================================
    // IsDisjointWith_CompositeSpecifications_Test
    // ==========================================

    public function testSpecificationsOfDifferentTypesAreAlwaysDisjointComposite(): void
    {
        self::assertTrue(Spec::all(AlgebraCustomer::class)->isDisjointWith(Spec::equalTo(42)));
        self::assertTrue(Spec::all(AlgebraOrder::class)->isDisjointWith(Spec::after(self::$tomorrow)));
        self::assertTrue(Spec::all(AlgebraCustomer::class)->isDisjointWith(Spec::all(AlgebraOrder::class)));
        self::assertTrue(Spec::all(AlgebraOrder::class)->isDisjointWith(Spec::all(AlgebraCustomer::class)));
        self::assertFalse(Spec::all(AlgebraCustomer::class)->isDisjointWith(Spec::equalTo(new AlgebraCustomer(1))));
        self::assertTrue(Spec::all(AlgebraCustomer::class)->isDisjointWith(Spec::equalTo(new AlgebraOrder())));
    }

    public function testSubtypesAreNotDisjoint(): void
    {
        self::assertTrue(Spec::all(AlgebraCustomer::class)->isGeneralizationOf(Spec::all(AlgebraVipCustomer::class)));
        self::assertFalse(Spec::all(AlgebraCustomer::class)->isDisjointWith(Spec::all(AlgebraVipCustomer::class)));
        self::assertTrue(Spec::all(AlgebraVipCustomer::class)->isSpecialCaseOf(Spec::all(AlgebraCustomer::class)));
        self::assertFalse(Spec::all(AlgebraVipCustomer::class)->isDisjointWith(Spec::all(AlgebraCustomer::class)));
        $customersWithOrders = Spec::all(AlgebraCustomer::class)->where('orders', Spec::is(Spec::not(Spec::empty())));
        $vipCustomersWithOrders = Spec::all(AlgebraVipCustomer::class)->where('orders', Spec::is(Spec::not(Spec::empty())));
        self::assertTrue($customersWithOrders->isGeneralizationOf($vipCustomersWithOrders));
        self::assertFalse($customersWithOrders->isDisjointWith($vipCustomersWithOrders));
        self::assertTrue($vipCustomersWithOrders->isSpecialCaseOf($customersWithOrders));
        self::assertFalse($vipCustomersWithOrders->isDisjointWith($customersWithOrders));
    }

    public function testShouldNotBeReflexiveComposite(): void
    {
        self::assertFalse(Spec::all(AlgebraCustomer::class)->isDisjointWith(Spec::all(AlgebraCustomer::class)));
        $orderOrCustomer = Spec::either(Spec::instanceOf(AlgebraCustomer::class), Spec::instanceOf(AlgebraOrder::class));
        self::assertFalse($orderOrCustomer->isDisjointWith($orderOrCustomer));
        $customerNamedTommy = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'));
        $vipCustomerNamedTommy = Spec::all(AlgebraVipCustomer::class)->where('name', Spec::isEqualTo('Tommy'));
        self::assertFalse($customerNamedTommy->isDisjointWith($customerNamedTommy));
        self::assertFalse($vipCustomerNamedTommy->isDisjointWith($vipCustomerNamedTommy));
        self::assertFalse($customerNamedTommy->isDisjointWith($vipCustomerNamedTommy));
        self::assertFalse($vipCustomerNamedTommy->isDisjointWith($customerNamedTommy));
    }

    public function testEqualDisjointMemberSpecificationsMeansDisjoint(): void
    {
        $customersNamedTommy = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'));
        $customersNamedJasper = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Jasper'));
        self::assertFalse($customersNamedTommy->isDisjointWith($customersNamedTommy));
        self::assertTrue($customersNamedTommy->isDisjointWith($customersNamedJasper));
        self::assertTrue($customersNamedJasper->isDisjointWith($customersNamedTommy));
        $customersNamedTommy = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'))->and('membershipDate', Spec::is(self::$yesterday));
        $customersNamedJasper = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Jasper'))->and('membershipDate', Spec::is(self::$yesterday));
        self::assertFalse($customersNamedTommy->isDisjointWith($customersNamedTommy));
        self::assertTrue($customersNamedTommy->isDisjointWith($customersNamedJasper));
        self::assertTrue($customersNamedJasper->isDisjointWith($customersNamedTommy));
    }

    public function testEqualNonDisjointMemberSpecificationsMeansNotDisjoint(): void
    {
        $customersNamedT = Spec::all(AlgebraCustomer::class)->where('name', Spec::like('T?mmy'));
        $customersNamedTimmyIgnoringCase = Spec::all(AlgebraCustomer::class)->where('name', Spec::equalsIgnoringCase('timmy'));
        self::assertFalse($customersNamedT->isDisjointWith($customersNamedT));
        self::assertFalse($customersNamedT->isDisjointWith($customersNamedTimmyIgnoringCase));
        self::assertFalse($customersNamedTimmyIgnoringCase->isDisjointWith($customersNamedT));
        $customersNamedT = Spec::all(AlgebraCustomer::class)->where('name', Spec::like('T?mmy'))->and('membershipDate', Spec::is(self::$yesterday));
        $customersNamedTimmyIgnoringCase = Spec::all(AlgebraCustomer::class)->where('name', Spec::equalsIgnoringCase('timmy'))->and('birthDate', Spec::before(self::$twentyYearsAgo));
        self::assertFalse($customersNamedT->isDisjointWith($customersNamedT));
        self::assertFalse($customersNamedT->isDisjointWith($customersNamedTimmyIgnoringCase));
    }

    /**
     * Deviation from Domian: (name=Tommy ∨ date=yesterday) and (name=Jasper ∨ date=the day before
     * yesterday) share the customer named Tommy admitted the day before yesterday, so the algebra
     * answers NOT disjoint (Domian answers true by comparing the leaves member by member).
     */
    public function testEqualDisjointMemberSpecificationOrEqualNonDisjointMemberSpecificationsMeansNotDisjoint(): void
    {
        $customersNamedTommy = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'))->or('membershipDate', Spec::is(self::$yesterday));
        $customersNamedJasper = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Jasper'))->or('membershipDate', Spec::is(self::$yesterday));
        self::assertFalse($customersNamedTommy->isDisjointWith($customersNamedTommy));
        self::assertFalse($customersNamedTommy->isDisjointWith($customersNamedJasper));
        self::assertFalse($customersNamedJasper->isDisjointWith($customersNamedTommy));
        $customersNamedTommy = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'))->or('membershipDate', Spec::isBefore(new DateTimeImmutable()));
        $customersNamedJasper = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Jasper'))->or('membershipDate', Spec::isBeforeOrAtTheSameTimeAs(self::$yesterday));
        self::assertFalse($customersNamedTommy->isDisjointWith($customersNamedTommy));
        self::assertFalse($customersNamedTommy->isDisjointWith($customersNamedJasper));
        self::assertFalse($customersNamedJasper->isDisjointWith($customersNamedTommy));
        $customersNamedJasper = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Jasper'))->or('membershipDate', Spec::is(self::$theDayBeforeYesterday));
        $customersNamedTommy = Spec::all(AlgebraCustomer::class)->where('name', Spec::isEqualTo('Tommy'))->or('membershipDate', Spec::is(self::$yesterday));
        self::assertFalse($customersNamedTommy->isDisjointWith($customersNamedJasper), 'algebra: Tommy admitted the day before yesterday satisfies both [Domian: disjoint]');
        self::assertTrue($customersNamedTommy->isSatisfiedBy(new AlgebraCustomer(name: 'Tommy', membershipDate: self::$theDayBeforeYesterday)));
        self::assertTrue($customersNamedJasper->isSatisfiedBy(new AlgebraCustomer(name: 'Tommy', membershipDate: self::$theDayBeforeYesterday)));
    }

    /**
     * @return array<string, ISpecification>
     */
    private static function yearSpecs(): array
    {
        $tenYearsAgo = self::$today->modify('-10 years');
        $tenYearsAhead = self::$today->modify('+10 years');
        $thirtyYearsAgo = self::$today->modify('-30 years');
        $thirtyYearsAhead = self::$today->modify('+30 years');

        return [
            'lessThanOrExactlyTenYearsAgo' => Spec::isAfterOrAtTheSameTimeAs($tenYearsAgo),
            'moreThanTenYearsAgo' => Spec::isBefore($tenYearsAgo),
            'lessThanOrExactlyTenYearsAhead' => Spec::isBeforeOrAtTheSameTimeAs($tenYearsAhead),
            'moreThanTenYearsAhead' => Spec::isAfter($tenYearsAhead),
            'lessThanThirtyYearsAgo' => Spec::isAfter($thirtyYearsAgo),
            'moreThanThirtyYearsAgo' => Spec::isBefore($thirtyYearsAgo),
            'lessThanThirtyYearsAhead' => Spec::isBefore($thirtyYearsAhead),
            'moreThanThirtyYearsAhead' => Spec::isAfter($thirtyYearsAhead),
        ];
    }

    public function testValueBoundConjunctionAndValueBoundDisjunctionNotDisjoint(): void
    {
        $y = self::yearSpecs();
        $plusMinusThirtyYears = $y['lessThanThirtyYearsAgo']->and($y['lessThanThirtyYearsAhead']);
        $atLeastTenYearsAway = $y['moreThanTenYearsAgo']->or($y['moreThanTenYearsAhead']);
        self::assertFalse($plusMinusThirtyYears->isDisjointWith($atLeastTenYearsAway));
        self::assertFalse($atLeastTenYearsAway->isDisjointWith($plusMinusThirtyYears));
        self::assertFalse(Spec::not(Spec::not($plusMinusThirtyYears))->isDisjointWith(Spec::not(Spec::not($atLeastTenYearsAway))));
        self::assertFalse(Spec::not(Spec::not($atLeastTenYearsAway))->isDisjointWith(Spec::not(Spec::not($plusMinusThirtyYears))));
        $plusMinusThirtyYears = Spec::both($y['lessThanThirtyYearsAgo'], $y['lessThanThirtyYearsAhead']);
        $atLeastTenYearsAway = Spec::either($y['moreThanTenYearsAgo'], $y['moreThanTenYearsAhead']);
        self::assertFalse($plusMinusThirtyYears->isDisjointWith($atLeastTenYearsAway));
        self::assertFalse($atLeastTenYearsAway->isDisjointWith($plusMinusThirtyYears));
        $plusMinusTenYears = Spec::is($y['lessThanOrExactlyTenYearsAgo'])->and($y['lessThanOrExactlyTenYearsAhead']);
        $moreThanTenYearsAway = Spec::not($plusMinusTenYears);
        self::assertFalse($plusMinusThirtyYears->isDisjointWith($moreThanTenYearsAway));
        self::assertFalse($moreThanTenYearsAway->isDisjointWith($plusMinusThirtyYears));
        self::assertFalse(Spec::not(Spec::not($plusMinusThirtyYears))->isDisjointWith(Spec::not(Spec::not($moreThanTenYearsAway))));
        self::assertFalse(Spec::not(Spec::not($moreThanTenYearsAway))->isDisjointWith(Spec::not(Spec::not($plusMinusThirtyYears))));
        $atLeastThirtyYearsAway = $y['moreThanThirtyYearsAgo']->or($y['moreThanThirtyYearsAhead']);
        $plusMinusThirtyYears = Spec::not($atLeastThirtyYearsAway);
        self::assertFalse($plusMinusThirtyYears->isDisjointWith($moreThanTenYearsAway));
        self::assertFalse($moreThanTenYearsAway->isDisjointWith($plusMinusThirtyYears));
        self::assertFalse(Spec::not(Spec::not($plusMinusThirtyYears))->isDisjointWith(Spec::not(Spec::not($moreThanTenYearsAway))));
        self::assertFalse(Spec::not(Spec::not($moreThanTenYearsAway))->isDisjointWith(Spec::not(Spec::not($plusMinusThirtyYears))));
    }

    public function testValueBoundConjunctionAndValueBoundDisjunctionDisjoint(): void
    {
        $y = self::yearSpecs();
        $plusMinusTenYears = $y['lessThanOrExactlyTenYearsAgo']->and($y['lessThanOrExactlyTenYearsAhead']);
        $moreThanThirtyYearsAway = $y['moreThanThirtyYearsAgo']->or($y['moreThanThirtyYearsAhead']);
        self::assertTrue($plusMinusTenYears->isDisjointWith($moreThanThirtyYearsAway));
        self::assertTrue($moreThanThirtyYearsAway->isDisjointWith($plusMinusTenYears));
        self::assertTrue(Spec::not(Spec::not($plusMinusTenYears))->isDisjointWith(Spec::not(Spec::not($moreThanThirtyYearsAway))));
        self::assertTrue(Spec::not(Spec::not($moreThanThirtyYearsAway))->isDisjointWith(Spec::not(Spec::not($plusMinusTenYears))));
        $plusMinusTenYears = Spec::is($y['lessThanOrExactlyTenYearsAgo'])->and($y['lessThanOrExactlyTenYearsAhead']);
        $moreThanThirtyYearsAway = Spec::is($y['moreThanThirtyYearsAgo'])->or($y['moreThanThirtyYearsAhead']);
        self::assertTrue($plusMinusTenYears->isDisjointWith($moreThanThirtyYearsAway));
        self::assertTrue($moreThanThirtyYearsAway->isDisjointWith($plusMinusTenYears));
        $plusMinusTenYears = Spec::both($y['lessThanThirtyYearsAgo'], $y['lessThanThirtyYearsAhead']);
        $moreThanThirtyYearsAway = Spec::oneOf($y['moreThanThirtyYearsAgo'], $y['moreThanThirtyYearsAhead']);
        self::assertTrue($plusMinusTenYears->isDisjointWith($moreThanThirtyYearsAway));
        self::assertTrue($moreThanThirtyYearsAway->isDisjointWith($plusMinusTenYears));
        self::assertTrue(Spec::not(Spec::not($plusMinusTenYears))->isDisjointWith(Spec::not(Spec::not($moreThanThirtyYearsAway))));
        self::assertTrue(Spec::not(Spec::not($moreThanThirtyYearsAway))->isDisjointWith(Spec::not(Spec::not($plusMinusTenYears))));
        $plusMinusThirtyYears = $y['lessThanThirtyYearsAgo']->and($y['lessThanThirtyYearsAhead']);
        $atLeastTenYearsAway = $y['moreThanTenYearsAgo']->or($y['moreThanTenYearsAhead']);
        $plusMinusTenYears = Spec::not($atLeastTenYearsAway);
        $moreThanThirtyYearsAway = Spec::not($plusMinusThirtyYears);
        self::assertTrue($plusMinusTenYears->isDisjointWith($moreThanThirtyYearsAway));
        self::assertTrue($moreThanThirtyYearsAway->isDisjointWith($plusMinusTenYears));
        self::assertTrue(Spec::not(Spec::not($plusMinusTenYears))->isDisjointWith(Spec::not(Spec::not($moreThanThirtyYearsAway))));
        self::assertTrue(Spec::not(Spec::not($moreThanThirtyYearsAway))->isDisjointWith(Spec::not(Spec::not($plusMinusTenYears))));
    }

    // ==========================================
    // IsDisjointWith_ParameterizedSpecifications_Test
    // ==========================================

    public function testIdenticalParameterizedSpecificationsIsNotDisjoint(): void
    {
        $customerIdSpec1 = Spec::all(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42));
        $customerIdSpec2 = Spec::all(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42));
        $customerIdSpec3 = Spec::all(AlgebraVipCustomer::class)->where('customerId', Spec::isEqualTo(42));
        self::assertFalse($customerIdSpec1->isDisjointWith($customerIdSpec1));
        self::assertFalse($customerIdSpec1->isDisjointWith($customerIdSpec2));
        self::assertFalse($customerIdSpec1->isDisjointWith($customerIdSpec3));
        self::assertFalse($customerIdSpec2->isDisjointWith($customerIdSpec1));
        self::assertFalse($customerIdSpec3->isDisjointWith($customerIdSpec1));
    }

    public function testNonDisjointAccessibleObjectSpecificationMeansNotDisjointParameterizedSpecification(): void
    {
        $customerIdSpec1 = Spec::all(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42));
        $customerIdSpec2 = Spec::all(AlgebraCustomer::class)->where('customerId', Spec::isLessThanOrEqualTo(42));
        $customerIdSpec3 = Spec::all(AlgebraVipCustomer::class)->where('customerId', Spec::isLessThanOrEqualTo(42));
        self::assertFalse($customerIdSpec1->isDisjointWith($customerIdSpec2));
        self::assertFalse($customerIdSpec1->isDisjointWith($customerIdSpec3));
        self::assertFalse($customerIdSpec2->isDisjointWith($customerIdSpec1));
        self::assertFalse($customerIdSpec3->isDisjointWith($customerIdSpec1));
    }

    public function testDifferentDeclaringTypeMeansDisjointParameterizedSpecification(): void
    {
        $customerIdSpec = Spec::all(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42));
        $orderIdSpec = Spec::all(AlgebraOrder::class)->where('orderId', Spec::isEqualTo(42));
        self::assertTrue($customerIdSpec->isDisjointWith($orderIdSpec));
        self::assertTrue($orderIdSpec->isDisjointWith($customerIdSpec));
        $bare1 = new PropertySpecification(Spec::all(AlgebraCustomer::class), 'customerId', Spec::isEqualTo(42));
        $bare2 = new PropertySpecification(Spec::all(AlgebraOrder::class), 'orderId', Spec::isEqualTo(42));
        self::assertTrue($bare1->isDisjointWith($bare2));
        self::assertTrue($bare2->isDisjointWith($bare1));
    }

    public function testDisjointAccessibleObjectSpecificationMeansDisjointParameterizedSpecification(): void
    {
        $customerIdSpec1 = Spec::all(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42));
        $customerIdSpec2 = Spec::all(AlgebraCustomer::class)->where('customerId', Spec::isLessThan(42));
        self::assertTrue($customerIdSpec1->isDisjointWith($customerIdSpec2));
        self::assertTrue($customerIdSpec2->isDisjointWith($customerIdSpec1));
        $thirtyYearsAgo = self::$today->modify('-30 years');
        $customerIdSpec3 = Spec::all(AlgebraCustomer::class)->where('birthDate', Spec::isAfter($thirtyYearsAgo));
        $customerIdSpec4 = Spec::all(AlgebraCustomer::class)->where('birthDate', Spec::isBefore($thirtyYearsAgo));
        self::assertTrue($customerIdSpec3->isDisjointWith($customerIdSpec4));
        self::assertTrue($customerIdSpec4->isDisjointWith($customerIdSpec3));
        $customerIdSpec5 = Spec::all(AlgebraCustomer::class)->where('membershipDate', Spec::afterString('1990-10-10')->and(Spec::beforeString('1991-10-10')));
        $customerIdSpec6 = Spec::all(AlgebraCustomer::class)->where('membershipDate', Spec::afterString('1992-10-10')->and(Spec::beforeString('1994-10-10')));
        self::assertTrue($customerIdSpec5->isDisjointWith($customerIdSpec6));
        self::assertTrue($customerIdSpec6->isDisjointWith($customerIdSpec5));
        // different properties are never proven disjoint (Domian decides by the value types, Long vs String)
        $customerNameSpec = Spec::all(AlgebraCustomer::class)->where('name', Spec::is('Johnny'));
        self::assertFalse($customerIdSpec1->isDisjointWith($customerNameSpec));
    }

    // ==========================================
    // IsDisjointWith_OtherLeafSpecifications_Test
    // ==========================================

    public function testShouldHandleNegatedNullCollectionSpecification(): void
    {
        $spec = Spec::hasSize(Spec::equalTo(0));
        self::assertFalse($spec->isDisjointWith($spec));
        $spec2 = Spec::not($spec);
        self::assertFalse($spec2->isDisjointWith($spec2));
        self::assertTrue($spec2->isDisjointWith($spec));
        self::assertTrue($spec->isDisjointWith($spec2));
        $spec3 = Spec::is(Spec::not($spec));
        self::assertFalse($spec3->isDisjointWith($spec3));
        self::assertFalse($spec3->isDisjointWith($spec2));
        self::assertFalse($spec2->isDisjointWith($spec3));
        self::assertTrue($spec3->isDisjointWith($spec));
        self::assertTrue($spec->isDisjointWith($spec3));
    }

    // ==========================================
    // CompositeSpecificationTest
    // ==========================================

    public function testCombinedByItself(): void
    {
        $donald = new AlgebraCustomer(1, name: 'Donald');
        $goofy = new AlgebraCustomer(2, name: 'Goofy');
        $spec = Spec::a(AlgebraCustomer::class)->where('name', Spec::is('Donald'));
        self::assertTrue($spec->isSatisfiedBy($donald));
        self::assertFalse($spec->isSatisfiedBy($goofy));
        $spec1 = $spec->and($spec);
        self::assertTrue($spec->equals($spec1));
        self::assertTrue($spec1->isSatisfiedBy($donald));
        self::assertFalse($spec1->isSatisfiedBy($goofy));
        $spec2 = $spec->or($spec);
        self::assertTrue($spec->equals($spec2));
        self::assertTrue($spec2->isSatisfiedBy($donald));
        self::assertFalse($spec2->isSatisfiedBy($goofy));
        $spec3 = $spec->and($spec)->or($spec);
        self::assertTrue($spec->equals($spec3));
        $spec4 = $spec1->or($spec2)->and($spec3)->and($spec);
        self::assertTrue($spec->equals($spec4));
        self::assertTrue($spec4->isSatisfiedBy($donald));
        self::assertFalse($spec4->isSatisfiedBy($goofy));
        // The type specification itself: all(T) ∧ all(T) ≡ all(T)
        $all = Spec::all(AlgebraCustomer::class);
        self::assertSame($all, $all->and(Spec::all(AlgebraCustomer::class)));
        self::assertSame($all, $all->or(Spec::all(AlgebraCustomer::class)));
    }

    public function testEquality(): void
    {
        $isLong42 = Spec::isEqualTo(42);
        $isLong44 = Spec::isEqualTo(44);
        $isJoey = Spec::isEqualTo('Joey');
        $spec1 = Spec::createSpecificationFor(AlgebraCustomer::class);
        $spec2 = Spec::createSpecificationFor(AlgebraCustomer::class);
        $spec3 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', $isLong42);
        self::assertNotSame($spec1, $spec2);
        self::assertTrue($spec1->equals($spec2));
        self::assertTrue($spec2->equals($spec1));
        self::assertFalse($spec1->equals($spec3));
        self::assertFalse($spec3->equals($spec1));
        self::assertFalse($spec2->equals($spec3));
        self::assertFalse($spec1->equals(Spec::createSpecificationFor(AlgebraVipCustomer::class)));
        $spec1 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', $isLong42);
        $spec2 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', $isLong42);
        $spec3 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', $isLong42)->and('name', $isJoey)->or('customerId', $isLong44);
        self::assertNotSame($spec1, $spec2);
        self::assertTrue($spec1->equals($spec2));
        self::assertFalse($spec1->equals($spec3));
        self::assertFalse($spec2->equals($spec3));
        $spec1 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', $isLong42)->and('name', $isJoey)->or('customerId', $isLong44);
        $spec2 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', $isLong42)->and('name', $isJoey)->or('customerId', $isLong44);
        $spec3 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', $isLong42);
        self::assertNotSame($spec1, $spec2);
        self::assertTrue($spec1->equals($spec2));
        self::assertFalse($spec1->equals($spec3));
        self::assertFalse($spec2->equals($spec3));
        // operand order is irrelevant for and/or; different values are not
        self::assertTrue(Spec::allOf($isLong42, $isJoey)->equals(Spec::allOf($isJoey, $isLong42)));
        self::assertTrue(Spec::anyOf($isLong42, $isJoey)->equals(Spec::anyOf($isJoey, $isLong42)));
        self::assertFalse(Spec::allOf($isLong42, $isJoey)->equals(Spec::anyOf($isLong42, $isJoey)));
        self::assertFalse(Spec::allOf($isLong42, $isJoey)->equals(Spec::allOf($isLong44, $isJoey)));
        self::assertTrue(Spec::not($isLong42)->equals(Spec::not(Spec::isEqualTo(42))));
        self::assertFalse(Spec::not($isLong42)->equals(Spec::not($isLong44)));
        self::assertTrue(Spec::nor($isLong42, $isJoey)->equals(Spec::nor($isJoey, $isLong42)));
        self::assertFalse(Spec::nor($isLong42, $isJoey)->equals(Spec::anyOf($isLong42, $isJoey)));
        self::assertFalse($spec1->equals('not a specification'));
        self::assertFalse($spec1->equals(null));
        // equality is symmetric
        self::assertSame($spec1->equals($spec3), $spec3->equals($spec1));
    }

    public function testEqualityForSpecificationSubTypes(): void
    {
        $spec1 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42));
        $spec2 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42));
        $spec3 = Spec::createSpecificationFor(AlgebraCustomer::class)->where('customerId', Spec::isEqualTo(42))->and('name', Spec::isEqualTo('Joey'))->or('customerId', Spec::isEqualTo(44));
        self::assertTrue($spec1->equals($spec2));
        self::assertFalse($spec2->equals($spec3));
        self::assertFalse($spec1->equals($spec3));
    }

    public function testNullAsCandidate(): void
    {
        self::assertFalse(Spec::all(AlgebraCustomer::class)->isSatisfiedBy(null));
        self::assertFalse(Spec::allOf(Spec::alwaysTrue(), Spec::alwaysTrue())->isSatisfiedBy(null));
        self::assertFalse(Spec::anyOf(Spec::alwaysTrue(), Spec::alwaysTrue())->isSatisfiedBy(null));
        self::assertFalse((new JointDenialSpecification(Spec::equalTo(1), Spec::equalTo(2)))->isSatisfiedBy(null));
        self::assertFalse(Spec::not(Spec::equalTo(1))->isSatisfiedBy(null));
    }

    // ==========================================
    // ValueBoundSpecificationTest
    // ==========================================

    public function testKeepSpecificationIntegrityByCopyingValue(): void
    {
        $today = new DateTime('today');
        $spec = Spec::isAfter($today);
        self::assertFalse($spec->isSatisfiedBy(null));
        self::assertTrue($spec->isSatisfiedBy(self::$tomorrow));
        self::assertFalse($spec->isSatisfiedBy(self::$today));
        self::assertFalse($spec->isSatisfiedBy(self::$yesterday));
        self::assertFalse($spec->isSatisfiedBy(self::$twentyYearsAgo));
        $today->setTimestamp((new DateTime('1950-01-01'))->getTimestamp());
        self::assertFalse($spec->isSatisfiedBy(null));
        self::assertTrue($spec->isSatisfiedBy(self::$tomorrow));
        self::assertFalse($spec->isSatisfiedBy(self::$today), 'the specification must not drift when the original value is mutated');
        self::assertFalse($spec->isSatisfiedBy(self::$yesterday));
        self::assertFalse($spec->isSatisfiedBy(self::$twentyYearsAgo));
        self::assertNotSame($today, $spec->getValue());
        self::assertInstanceOf(DateTime::class, $spec->getValue());
        self::assertNotSame($today, Spec::at($today)->getValue());
        self::assertNotSame($today, Spec::beforeOrAt($today)->getValue());
        // immutable values and identity leaves are kept as given
        $immutable = new DateTimeImmutable();
        self::assertSame($immutable, Spec::isAfter($immutable)->getValue());
        self::assertSame($today, Spec::equalTo($today)->getValue());
        self::assertSame('myValue', Spec::isEqualTo('myValue')->getValue());
    }

    public function testValueBoundEquality(): void
    {
        $spec1 = Spec::isEqualTo('yo!');
        $spec2 = Spec::isEqualTo('yo!');
        self::assertFalse($spec1->equals(null));
        self::assertFalse($spec1->equals(99));
        self::assertNotSame($spec1, $spec2);
        self::assertTrue($spec1->equals($spec2));
        $spec3 = Spec::isGreaterThanOrEqualTo(42);
        $spec4 = Spec::isGreaterThanOrEqualTo(42);
        self::assertNotSame($spec3, $spec4);
        self::assertTrue($spec3->equals($spec4));
        self::assertInstanceOf(GreaterThanOrEqualSpecification::class, $spec3);
        self::assertTrue(Spec::isAfter(self::$today)->equals(Spec::after(self::$today)));
        self::assertTrue(Spec::isAfter(self::$today)->equals(Spec::after(DateTime::createFromImmutable(self::$today))));
    }

    public function testSameValueButDifferentSpecificationClassShouldNotBeEqual(): void
    {
        self::assertFalse(Spec::isBefore(self::$twoDaysAgo)->equals(Spec::isAfterOrAtTheSameTimeAs(self::$twoDaysAgo)));
        self::assertFalse(Spec::isAfterOrAtTheSameTimeAs(self::$twoDaysAgo)->equals(Spec::isBefore(self::$twoDaysAgo)));
        self::assertFalse(Spec::equalTo(42)->equals(Spec::notEqual(42)));
        self::assertFalse(Spec::greaterThan(42)->equals(Spec::greaterThanOrEqualTo(42)));
        self::assertFalse(Spec::equalTo(42)->equals(Spec::looselyEqualTo(42)));
    }

    // ==========================================
    // NotNullSpecificationTest
    // ==========================================

    public function testNotNullSpecification(): void
    {
        $spec = new NotNullSpecification();
        self::assertFalse($spec->isSatisfiedBy(null));
        foreach (['', '   ', "\n", "\r", "\t", "\r\n", "\r\n\t", '.', 'x'] as $value) {
            self::assertTrue($spec->isSatisfiedBy($value));
        }
    }

    // ==========================================
    // Named relational leaves (factory), interval algebra
    // ==========================================

    public function testFactoryBuildsNamedRelationalLeaves(): void
    {
        self::assertInstanceOf(GreaterThanOrEqualSpecification::class, Spec::greaterThanOrEqualTo(5));
        self::assertInstanceOf(GreaterThanOrEqualSpecification::class, Spec::atLeast(5));
        self::assertInstanceOf(LessThanOrEqualSpecification::class, Spec::lessThanOrEqualTo(5));
        self::assertInstanceOf(LessThanOrEqualSpecification::class, Spec::atMost(5));
        self::assertInstanceOf(LessThanSpecification::class, Spec::isBefore(self::$today));
        self::assertInstanceOf(GreaterThanSpecification::class, Spec::isAfter(self::$today));
        self::assertInstanceOf(LessThanOrEqualSpecification::class, Spec::isBeforeOrAtTheSameTimeAs(self::$today));
        self::assertInstanceOf(GreaterThanOrEqualSpecification::class, Spec::isAfterOrAtTheSameTimeAs(self::$today));
        self::assertInstanceOf(SameInstantSpecification::class, Spec::at(self::$today));
        self::assertInstanceOf(EqualSpecification::class, Spec::at(self::$today));
        self::assertInstanceOf(GreaterThanSpecification::class, Spec::afterString('1992-10-10'));
        self::assertInstanceOf(DateTimeImmutable::class, Spec::afterString('1992-10-10')->getValue());
        self::assertSame(DateTimeImmutable::class, get_class(Spec::afterString('1992-10-10')->getValue()));
        self::assertTrue(Spec::at(self::$today)->isSatisfiedBy(new DateTime('today')));
        self::assertFalse(Spec::at(self::$today)->isSatisfiedBy(self::$tomorrow));
        self::assertTrue(Spec::greaterThanOrEqualTo(5)->isSatisfiedBy(5));
        self::assertFalse(Spec::greaterThanOrEqualTo(5)->isSatisfiedBy(4));
        self::assertTrue(Spec::lessThanOrEqualTo(5)->isSatisfiedBy(5));
        self::assertFalse(Spec::lessThanOrEqualTo(5)->isSatisfiedBy(6));
        self::assertTrue(Spec::between(self::$yesterday, self::$tomorrow)->isSatisfiedBy(self::$today));
        self::assertTrue(Spec::between(self::$yesterday, self::$tomorrow)->isSatisfiedBy(self::$yesterday));
        self::assertFalse(Spec::between(self::$yesterday, self::$tomorrow)->isSatisfiedBy(self::$twoDaysAgo));
        self::assertFalse(Spec::between(self::$yesterday, self::$tomorrow)->isSatisfiedBy(null));
    }

    public function testIntervalSubsumptionOfRelationalLeaves(): void
    {
        self::assertTrue(Spec::greaterThan(5)->isGeneralizationOf(Spec::greaterThan(7)));
        self::assertTrue(Spec::greaterThan(5)->isGeneralizationOf(Spec::greaterThanOrEqualTo(7)));
        self::assertTrue(Spec::greaterThanOrEqualTo(5)->isGeneralizationOf(Spec::greaterThan(5)));
        self::assertFalse(Spec::greaterThan(5)->isGeneralizationOf(Spec::greaterThanOrEqualTo(5)));
        self::assertTrue(Spec::greaterThanOrEqualTo(5)->isGeneralizationOf(Spec::greaterThan(4)), 'integers: x > 4 ≡ x >= 5');
        self::assertFalse(Spec::greaterThanOrEqualTo(5.0)->isGeneralizationOf(Spec::greaterThan(4.0)));
        self::assertTrue(Spec::lessThanOrEqualTo(10)->isGeneralizationOf(Spec::lessThan(5)));
        self::assertTrue(Spec::lessThanOrEqualTo(10)->isGeneralizationOf(Spec::equalTo(10)));
        self::assertFalse(Spec::lessThan(10)->isGeneralizationOf(Spec::equalTo(10)));
        self::assertTrue(Spec::equalTo(5)->isSpecialCaseOf(Spec::greaterThan(3)));
        self::assertTrue(Spec::notEqual(5)->isGeneralizationOf(Spec::lessThan(5)));
        self::assertTrue(Spec::notEqual(5)->isGeneralizationOf(Spec::lessThanOrEqualTo(4)));
        self::assertFalse(Spec::notEqual(5)->isGeneralizationOf(Spec::lessThanOrEqualTo(5)));
        self::assertTrue(Spec::isBefore(new DateTimeImmutable('2020-01-01'))->isGeneralizationOf(Spec::isBefore(new DateTimeImmutable('2010-01-01'))));
        self::assertFalse(Spec::isBefore(new DateTimeImmutable('2010-01-01'))->isGeneralizationOf(Spec::isBefore(new DateTimeImmutable('2020-01-01'))));
        self::assertTrue(Spec::isBefore(new DateTimeImmutable('2020-01-02'))->isGeneralizationOf(Spec::at(new DateTimeImmutable('2020-01-01'))));
        self::assertTrue(Spec::in(1, 2)->isGeneralizationOf(Spec::equalTo(1)));
        self::assertTrue(Spec::equalTo(2)->isSpecialCaseOf(Spec::in(1, 2)));
        self::assertFalse(Spec::in(1, 2)->isGeneralizationOf(Spec::equalTo(3)));
        self::assertTrue(Spec::afterOrAt(self::$yesterday)->isGeneralizationOf(Spec::between(self::$yesterday, self::$tomorrow)));
        self::assertTrue(Spec::between(self::$yesterday, self::$tomorrow)->isSpecialCaseOf(Spec::beforeOrAt(self::$tomorrow)));
        // the algebra never compares mixed types
        self::assertFalse(Spec::greaterThan(9)->isGeneralizationOf(Spec::equalTo('abc')));
        self::assertFalse(Spec::greaterThan(9)->isDisjointWith(Spec::equalTo('abc')));
    }

    public function testIntervalDisjointnessOfRelationalLeaves(): void
    {
        self::assertTrue(Spec::lessThan(5)->isDisjointWith(Spec::greaterThan(5)));
        self::assertTrue(Spec::lessThan(5)->isDisjointWith(Spec::greaterThanOrEqualTo(5)));
        self::assertTrue(Spec::greaterThanOrEqualTo(5)->isDisjointWith(Spec::lessThan(5)));
        self::assertFalse(Spec::lessThanOrEqualTo(5)->isDisjointWith(Spec::greaterThanOrEqualTo(5)));
        self::assertFalse(Spec::lessThan(5)->isDisjointWith(Spec::greaterThan(3)));
        self::assertTrue(Spec::greaterThan(10)->isDisjointWith(Spec::lessThan(11)), 'integers: no value between 10 and 11');
        self::assertTrue(Spec::lessThan(10)->isDisjointWith(Spec::greaterThan(9)));
        self::assertFalse(Spec::greaterThan(10.0)->isDisjointWith(Spec::lessThan(11.0)));
        self::assertFalse(Spec::greaterThan(10)->isDisjointWith(Spec::lessThan(12)));
        self::assertTrue(Spec::equalTo(5)->isDisjointWith(Spec::equalTo(6)));
        self::assertTrue(Spec::equalTo(5)->isDisjointWith(Spec::notEqual(5)));
        self::assertFalse(Spec::notEqual(5)->isDisjointWith(Spec::notEqual(6)));
        self::assertTrue(Spec::after(self::$today)->isDisjointWith(Spec::before(self::$today)));
        self::assertTrue(Spec::before(self::$today)->isDisjointWith(Spec::after(self::$today)));
        self::assertFalse(Spec::after(self::$today)->isDisjointWith(Spec::before(self::$tomorrow)), 'a time line is dense');
        self::assertTrue(Spec::at(self::$today)->isDisjointWith(Spec::before(self::$today)));
        self::assertTrue(Spec::at(self::$today)->isDisjointWith(Spec::not(Spec::at(self::$today))));
        self::assertTrue(Spec::between(self::$yesterday, self::$today)->isDisjointWith(Spec::after(self::$today)));
        self::assertTrue(Spec::isNull()->isDisjointWith(Spec::isNotNull()));
        self::assertTrue(Spec::not(Spec::lessThan(10))->isDisjointWith(Spec::lessThan(10)));
        self::assertTrue(Spec::lessThan(10)->isDisjointWith(Spec::not(Spec::lessThan(10))));
        // intersectsWith is the complement of isDisjointWith
        self::assertFalse(Spec::lessThan(5)->intersectsWith(Spec::greaterThan(5)));
        self::assertTrue(Spec::lessThan(5)->intersectsWith(Spec::greaterThan(3)));
        self::assertTrue(Spec::allOf(Spec::greaterThan(1), Spec::lessThan(9))->intersectsWith(Spec::equalTo(5)));
        self::assertFalse(Spec::allOf(Spec::greaterThan(1), Spec::lessThan(9))->intersectsWith(Spec::equalTo(50)));
    }

    public function testNegationResolution(): void
    {
        self::assertTrue(Spec::not(Spec::lessThan(10))->isGeneralizationOf(Spec::greaterThanOrEqualTo(10)));
        self::assertTrue(Spec::greaterThanOrEqualTo(10)->isGeneralizationOf(Spec::not(Spec::lessThan(10))));
        self::assertTrue(Spec::not(Spec::not(Spec::equalTo(1)))->isGeneralizationOf(Spec::equalTo(1)));
        self::assertTrue(Spec::equalTo(1)->isGeneralizationOf(Spec::not(Spec::not(Spec::equalTo(1)))));
        self::assertTrue(Spec::not(Spec::isNull())->isGeneralizationOf(Spec::isNotNull()));
        self::assertTrue(Spec::not(Spec::alwaysTrue())->isDisjointWith(Spec::equalTo(1)));
        self::assertTrue(Spec::nor(Spec::equalTo(1), Spec::equalTo(2))->isDisjointWith(Spec::equalTo(1)));
        self::assertTrue(Spec::nor(Spec::equalTo(1), Spec::equalTo(2))->isGeneralizationOf(Spec::equalTo(3)));
        self::assertTrue(Spec::nor(Spec::lessThan(1), Spec::greaterThan(9))->isGeneralizationOf(Spec::equalTo(5)));
        self::assertFalse(Spec::nor(Spec::lessThan(1), Spec::greaterThan(9))->isGeneralizationOf(Spec::equalTo(10)));
        // ¬A ⊇ X ⇔ X ⟂ A, for any A
        $customer = Spec::all(AlgebraCustomer::class);
        self::assertTrue(Spec::not($customer)->isGeneralizationOf(Spec::all(AlgebraOrder::class)));
        self::assertFalse(Spec::not($customer)->isGeneralizationOf(Spec::all(AlgebraVipCustomer::class)));
        self::assertTrue(Spec::all(AlgebraOrder::class)->isSpecialCaseOf(Spec::not($customer)));
    }

    // ==========================================
    // AbstractPartitionedRepositoryTest.checkEqualsAndSubsumption_extra (36 assertions)
    // ==========================================

    public function testCheckEqualsAndSubsumptionExtra(): void
    {
        $customers = self::customers();
        $adultCustomers = $customers->where('birthDate', Spec::isBefore(self::$twentyYearsAgo));
        $pioneerCustomers = $customers->where('membershipDate', Spec::isBefore(self::$twoDaysAgo));
        $adultPioneerCustomers = $adultCustomers->and($pioneerCustomers);

        $spec1 = $customers->where('foreign', Spec::isTrue())->and('birthDate', Spec::isBefore(self::$twentyYearsAgo))->and('membershipDate', Spec::isBefore(self::$twoDaysAgo));

        self::assertFalse($spec1->equals($pioneerCustomers));
        self::assertFalse($pioneerCustomers->equals($spec1));
        self::assertTrue($spec1->isSpecialCaseOf($pioneerCustomers));
        self::assertFalse($spec1->isGeneralizationOf($pioneerCustomers));
        self::assertFalse($pioneerCustomers->isSpecialCaseOf($spec1));
        self::assertTrue($pioneerCustomers->isGeneralizationOf($spec1));

        self::assertFalse($spec1->equals($adultCustomers));
        self::assertFalse($adultCustomers->equals($spec1));
        self::assertTrue($spec1->isSpecialCaseOf($adultCustomers));
        self::assertFalse($spec1->isGeneralizationOf($adultCustomers));
        self::assertFalse($adultCustomers->isSpecialCaseOf($spec1));
        self::assertTrue($adultCustomers->isGeneralizationOf($spec1));

        self::assertFalse($spec1->equals($adultPioneerCustomers));
        self::assertFalse($adultPioneerCustomers->equals($spec1));
        self::assertTrue($spec1->isSpecialCaseOf($adultPioneerCustomers));
        self::assertFalse($spec1->isGeneralizationOf($adultPioneerCustomers));
        self::assertFalse($adultPioneerCustomers->isSpecialCaseOf($spec1));
        self::assertTrue($adultPioneerCustomers->isGeneralizationOf($spec1));

        $spec2 = $customers->where('foreign', Spec::isTrue())->and('membershipDate', Spec::isBefore(self::$twoDaysAgo));

        self::assertFalse($spec1->equals($spec2));
        self::assertFalse($spec2->equals($spec1));
        self::assertTrue($spec1->isSpecialCaseOf($spec2));
        self::assertFalse($spec1->isGeneralizationOf($spec2));
        self::assertFalse($spec2->isSpecialCaseOf($spec1));
        self::assertTrue($spec2->isGeneralizationOf($spec1));

        $spec3 = $customers->where('foreign', Spec::isTrue())->and('birthDate', Spec::isBefore(self::$twentyYearsAgo));

        self::assertFalse($spec1->equals($spec3));
        self::assertFalse($spec3->equals($spec1));
        self::assertTrue($spec1->isSpecialCaseOf($spec3));
        self::assertFalse($spec1->isGeneralizationOf($spec3));
        self::assertFalse($spec3->isSpecialCaseOf($spec1));
        self::assertTrue($spec3->isGeneralizationOf($spec1));

        // shouldHandleParallellSpecialCaseOfPartitions relies on these
        self::assertTrue($adultCustomers->isGeneralizationOf($adultPioneerCustomers));
        self::assertTrue($pioneerCustomers->isGeneralizationOf($adultPioneerCustomers));
        self::assertTrue($adultPioneerCustomers->isSpecialCaseOf($adultCustomers));
        self::assertTrue($adultPioneerCustomers->isSpecialCaseOf($pioneerCustomers));
        self::assertFalse($adultCustomers->isDisjointWith($pioneerCustomers));
        self::assertFalse($adultPioneerCustomers->isDisjointWith($adultCustomers));
    }

    // ==========================================
    // Probe docs/paridade-domian-2026-10-09/sondas/probe_subsumption.php (61 assertions)
    // ==========================================

    /**
     * @return iterable<string, array{\Closure(): mixed, mixed}>
     */
    public static function probeSubsumption(): iterable
    {
        $now = new DateTimeImmutable('today');
        $twoDaysAgo = $now->modify('-2 days');
        $twentyYearsAgo = $now->modify('-20 years');

        $customers = Spec::specify(AlgebraCustomer::class);
        $vipCustomers = Spec::specify(AlgebraVipCustomer::class);
        $adultCustomers = Spec::specify(AlgebraCustomer::class)->where('birthDate', Spec::isBefore($twentyYearsAgo));
        $pioneerCustomers = Spec::specify(AlgebraCustomer::class)->where('membershipDate', Spec::isBefore($twoDaysAgo));
        $adultPioneerCustomers = $adultCustomers->and($pioneerCustomers);
        $maleCustomers = Spec::specify(AlgebraCustomer::class)->where('gender', Spec::is('MALE'));
        $maleVipCustomers = Spec::specify(AlgebraVipCustomer::class)->where('gender', Spec::is('MALE'));

        // A. Type specification equality / subsumption
        yield 'A1 all(Customer).equals(all(Customer)) [new instance]' => [fn() => Spec::specify(AlgebraCustomer::class)->equals(Spec::specify(AlgebraCustomer::class)), true];
        yield 'A2 all(Customer).isGeneralizationOf(all(Customer)) [new instance]' => [fn() => $customers->isGeneralizationOf(Spec::specify(AlgebraCustomer::class)), true];
        yield 'A3 all(Customer).isGeneralizationOf(all(VipCustomer))' => [fn() => $customers->isGeneralizationOf($vipCustomers), true];
        yield 'A4 all(VipCustomer).isSpecialCaseOf(all(Customer))' => [fn() => $vipCustomers->isSpecialCaseOf($customers), true];
        yield 'A5 all(Customer).isGeneralizationOf(maleCustomers)' => [fn() => $customers->isGeneralizationOf($maleCustomers), true];
        yield 'A6 maleCustomers.isSpecialCaseOf(all(Customer))' => [fn() => $maleCustomers->isSpecialCaseOf($customers), true];
        yield 'A7 maleCustomers.isGeneralizationOf(maleVipCustomers)' => [fn() => $maleCustomers->isGeneralizationOf($maleVipCustomers), true];
        yield 'A8 maleVipCustomers.isSpecialCaseOf(maleCustomers)' => [fn() => $maleVipCustomers->isSpecialCaseOf($maleCustomers), true];
        yield 'A9 all(VipCustomer).isGeneralizationOf(all(Customer)) [must be false]' => [fn() => $vipCustomers->isGeneralizationOf($customers), false];

        // B. shouldHandleParallellSpecialCaseOfPartitions
        yield 'B1 adultCustomers.isGeneralizationOf(adultPioneerCustomers)' => [fn() => $adultCustomers->isGeneralizationOf($adultPioneerCustomers), true];
        yield 'B2 pioneerCustomers.isGeneralizationOf(adultPioneerCustomers)' => [fn() => $pioneerCustomers->isGeneralizationOf($adultPioneerCustomers), true];
        yield 'B3 adultPioneerCustomers.isSpecialCaseOf(adultCustomers)' => [fn() => $adultPioneerCustomers->isSpecialCaseOf($adultCustomers), true];
        yield 'B4 adultPioneerCustomers.isSpecialCaseOf(pioneerCustomers)' => [fn() => $adultPioneerCustomers->isSpecialCaseOf($pioneerCustomers), true];

        // C. checkEqualsAndSubsumption_extra
        $spec1 = Spec::specify(AlgebraCustomer::class)->where('foreign', Spec::isTrue())->and('birthDate', Spec::isBefore($twentyYearsAgo))->and('membershipDate', Spec::isBefore($twoDaysAgo));
        $spec2 = Spec::specify(AlgebraCustomer::class)->where('foreign', Spec::isTrue())->and('membershipDate', Spec::isBefore($twoDaysAgo));
        $spec3 = Spec::specify(AlgebraCustomer::class)->where('foreign', Spec::isTrue())->and('birthDate', Spec::isBefore($twentyYearsAgo));
        yield 'C1 spec1.equals(pioneerCustomers)' => [fn() => $spec1->equals($pioneerCustomers), false];
        yield 'C2 spec1.isSpecialCaseOf(pioneerCustomers)' => [fn() => $spec1->isSpecialCaseOf($pioneerCustomers), true];
        yield 'C3 spec1.isGeneralizationOf(pioneerCustomers)' => [fn() => $spec1->isGeneralizationOf($pioneerCustomers), false];
        yield 'C4 pioneerCustomers.isGeneralizationOf(spec1)' => [fn() => $pioneerCustomers->isGeneralizationOf($spec1), true];
        yield 'C5 spec1.isSpecialCaseOf(adultCustomers)' => [fn() => $spec1->isSpecialCaseOf($adultCustomers), true];
        yield 'C6 adultCustomers.isGeneralizationOf(spec1)' => [fn() => $adultCustomers->isGeneralizationOf($spec1), true];
        yield 'C7 spec1.isSpecialCaseOf(adultPioneerCustomers)' => [fn() => $spec1->isSpecialCaseOf($adultPioneerCustomers), true];
        yield 'C8 adultPioneerCustomers.isGeneralizationOf(spec1)' => [fn() => $adultPioneerCustomers->isGeneralizationOf($spec1), true];
        yield 'C9 adultPioneerCustomers.isSpecialCaseOf(spec1)' => [fn() => $adultPioneerCustomers->isSpecialCaseOf($spec1), false];
        yield 'C10 spec1.equals(spec2)' => [fn() => $spec1->equals($spec2), false];
        yield 'C11 spec1.isSpecialCaseOf(spec2)' => [fn() => $spec1->isSpecialCaseOf($spec2), true];
        yield 'C12 spec2.isGeneralizationOf(spec1)' => [fn() => $spec2->isGeneralizationOf($spec1), true];
        yield 'C13 spec2.isSpecialCaseOf(spec1)' => [fn() => $spec2->isSpecialCaseOf($spec1), false];
        yield 'C14 spec1.isSpecialCaseOf(spec3)' => [fn() => $spec1->isSpecialCaseOf($spec3), true];
        yield 'C15 spec3.isGeneralizationOf(spec1)' => [fn() => $spec3->isGeneralizationOf($spec1), true];

        // D. Reflexivity / structural equality of composites
        $p1 = Spec::specify(AlgebraCustomer::class)->where('gender', Spec::is('MALE'));
        $p2 = Spec::specify(AlgebraCustomer::class)->where('gender', Spec::is('MALE'));
        yield 'D1 where(gender,is(MALE)).equals(where(gender,is(MALE)))' => [fn() => $p1->equals($p2), true];
        yield 'D2 where(...).isGeneralizationOf(copy)' => [fn() => $p1->isGeneralizationOf($p2), true];
        yield 'D3 where(...).isSpecialCaseOf(copy)' => [fn() => $p1->isSpecialCaseOf($p2), true];
        $or1 = Spec::anyOf(Spec::equalTo(1), Spec::equalTo(2));
        $or2 = Spec::anyOf(Spec::equalTo(1), Spec::equalTo(2));
        yield 'D4 (eq1 OR eq2).equals(copy)' => [fn() => $or1->equals($or2), true];
        yield 'D5 (eq1 OR eq2).isGeneralizationOf(copy)' => [fn() => $or1->isGeneralizationOf($or2), true];
        yield 'D6 (eq1 OR eq2).isGeneralizationOf(eq1) [A∨B ⊇ A]' => [fn() => $or1->isGeneralizationOf(Spec::equalTo(1)), true];
        yield 'D7 eq1.isSpecialCaseOf(eq1 OR eq2)' => [fn() => Spec::equalTo(1)->isSpecialCaseOf($or1), true];
        $not1 = Spec::not(Spec::equalTo(1));
        $not2 = Spec::not(Spec::equalTo(1));
        yield 'D8 NOT(eq1).equals(copy)' => [fn() => $not1->equals($not2), true];
        yield 'D9 NOT(eq1).isGeneralizationOf(copy)' => [fn() => $not1->isGeneralizationOf($not2), true];
        yield 'D10 NOT(eq1).isDisjointWith(eq1 copy)' => [fn() => $not1->isDisjointWith(Spec::equalTo(1)), true];
        yield 'D11 eq1.isDisjointWith(NOT(eq1))' => [fn() => Spec::equalTo(1)->isDisjointWith($not1), true];
        $and1 = Spec::allOf(Spec::greaterThan(1), Spec::lessThan(9));
        yield 'D12 (gt1 AND lt9).isGeneralizationOf(copy)' => [fn() => $and1->isGeneralizationOf(Spec::allOf(Spec::greaterThan(1), Spec::lessThan(9))), true];
        yield 'D13 (gt1 AND lt9).isSpecialCaseOf(gt1)' => [fn() => $and1->isSpecialCaseOf(Spec::greaterThan(1)), true];
        yield 'D14 gt1.isGeneralizationOf(gt1 AND lt9)' => [fn() => Spec::greaterThan(1)->isGeneralizationOf($and1), true];

        // E. Leaf relational subsumption
        yield 'E1 gt(5).isGeneralizationOf(gt(7))' => [fn() => Spec::greaterThan(5)->isGeneralizationOf(Spec::greaterThan(7)), true];
        yield 'E2 gt(5).isGeneralizationOf(gte(7))' => [fn() => Spec::greaterThan(5)->isGeneralizationOf(Spec::greaterThanOrEqualTo(7)), true];
        yield 'E3 gte(5).isGeneralizationOf(gt(5))' => [fn() => Spec::greaterThanOrEqualTo(5)->isGeneralizationOf(Spec::greaterThan(5)), true];
        yield 'E4 gt(5).isGeneralizationOf(gt(5))' => [fn() => Spec::greaterThan(5)->isGeneralizationOf(Spec::greaterThan(5)), true];
        yield 'E5 lt(5).isDisjointWith(gt(5))' => [fn() => Spec::lessThan(5)->isDisjointWith(Spec::greaterThan(5)), true];
        yield 'E6 lt(5).isDisjointWith(gte(5))' => [fn() => Spec::lessThan(5)->isDisjointWith(Spec::greaterThanOrEqualTo(5)), true];
        yield 'E7 lt(5).isDisjointWith(gt(3)) [overlap → false]' => [fn() => Spec::lessThan(5)->isDisjointWith(Spec::greaterThan(3)), false];
        yield 'E8 eq(5).isDisjointWith(eq(6))' => [fn() => Spec::equalTo(5)->isDisjointWith(Spec::equalTo(6)), true];
        yield 'E9 eq(5).isSpecialCaseOf(gt(3))' => [fn() => Spec::equalTo(5)->isSpecialCaseOf(Spec::greaterThan(3)), true];
        yield 'E10 isBefore(2020).isGeneralizationOf(isBefore(2010))' => [fn() => Spec::isBefore(new DateTimeImmutable('2020-01-01'))->isGeneralizationOf(Spec::isBefore(new DateTimeImmutable('2010-01-01'))), true];
        yield 'E11 isBefore(d).isDisjointWith(isAfterOrAt(d))' => [fn() => Spec::isBefore($twoDaysAgo)->isDisjointWith(Spec::isAfterOrAt($twoDaysAgo)), true];
        yield 'E12 pioneerCustomers.isDisjointWith(newCustomers)' => [fn() => $pioneerCustomers->isDisjointWith(Spec::specify(AlgebraCustomer::class)->where('membershipDate', Spec::isAfterOrAt($twoDaysAgo))), true];

        // F. Fluent constraints. Deliberate relaxation: where() twice and and(name, spec) before
        // where() are accepted in PHP (Domian throws UnsupportedOperationException); andWhere()
        // before where() throws as in Domian.
        yield 'F1 where().where() is accepted (PHP relaxation; Java throws)' => [fn() => Spec::specify(AlgebraCustomer::class)->where('gender', Spec::is('MALE'))->where('foreign', Spec::isTrue()) instanceof ICompositeSpecification, true];
        yield 'F2 and(name,spec) without where is accepted (PHP relaxation; Java throws)' => [fn() => Spec::specify(AlgebraCustomer::class)->and('gender', Spec::is('MALE')) instanceof ICompositeSpecification, true];
        yield 'F3 andWhere without where throws' => [function () {
            try {
                Spec::specify(AlgebraCustomer::class)->andWhere('gender', Spec::is('MALE'));
                return false;
            } catch (\Throwable) {
                return true;
            }
        }, true];

        // G. Accessible-object resolution
        yield 'G1 where(processed,isFalse) via isProcessed()' => [fn() => Spec::specify(AlgebraOrderLine::class)->where('processed', Spec::isFalse())->isSatisfiedBy(new AlgebraOrderLine()), true];
        yield 'G2 where(pending,isTrue) via pending() method' => [fn() => Spec::specify(AlgebraOrderLine::class)->where('pending', Spec::isTrue())->isSatisfiedBy(new AlgebraOrderLine()), true];

        // H. Collection size specs
        $withOrders = Spec::specify(AlgebraCustomer::class)->where('orders', Spec::not(Spec::isEmpty()));
        yield 'H1 customersWithOrders satisfied by customer with 1 order' => [fn() => $withOrders->isSatisfiedBy(new AlgebraCustomer(orders: [1])), true];
        yield 'H2 customersWithOrders NOT satisfied by customer with 0 orders' => [fn() => $withOrders->isSatisfiedBy(new AlgebraCustomer()), false];
    }

    #[DataProvider('probeSubsumption')]
    public function testProbeSubsumption(\Closure $probe, mixed $expected): void
    {
        self::assertSame($expected, $probe());
    }

    // ==========================================
    // Domian SpecificationFactory names (28 aliases)
    // ==========================================

    public function testDomianFactoryAliasesDelegateToExistingSpecifications(): void
    {
        self::assertInstanceOf(AlwaysFalseSpecification::class, Spec::createAlwaysFalseSpecification());
        self::assertInstanceOf(AlwaysFalseSpecification::class, Spec::createContradiction());
        self::assertInstanceOf(AlwaysTrueSpecification::class, Spec::createAlwaysTrueSpecification());
        self::assertInstanceOf(AlwaysTrueSpecification::class, Spec::createTautology());
        self::assertInstanceOf(NotNullSpecification::class, Spec::createNotNullSpecification());
        self::assertInstanceOf(NotNullSpecification::class, Spec::allObjects());
        self::assertInstanceOf(AllEntitiesSpecification::class, Spec::allEntities());
        self::assertInstanceOf(AllEntitiesSpecification::class, Spec::entities());
        self::assertInstanceOf(AllEntitiesSpecification::class, Spec::entity());
        self::assertTrue(Spec::createDefaultNumberSpecification()->equals(Spec::defaultNumber()));
        self::assertTrue(Spec::isDefaultNumber()->isSatisfiedBy(0));
        self::assertFalse(Spec::isDefaultNumber()->isSatisfiedBy(''));
        self::assertTrue(Spec::createBlankStringSpecification()->equals(Spec::blankString()));
        self::assertTrue(Spec::isBlankString()->isSatisfiedBy(null));
        self::assertTrue(Spec::isBlankString()->isSatisfiedBy("\r\n"));
        self::assertFalse(Spec::isBlankString()->isSatisfiedBy(0));
        self::assertTrue(Spec::isDefaultValueOfType('bool')->isSatisfiedBy(false));
        self::assertFalse(Spec::defaultValueOfType('bool')->isSatisfiedBy(0));
        self::assertTrue(Spec::createEqualIgnoreCaseStringSpecification('abc')->isSatisfiedBy('ABC'));
        self::assertTrue(Spec::createDateStringSpecification('Y-m-d')->isSatisfiedBy('2026-10-09'));
        self::assertTrue(Spec::createEnumNameStringSpecification(AlgebraGender::class)->isSatisfiedBy('MALE'));
        self::assertTrue(Spec::isEnum(AlgebraGender::class)->isSatisfiedBy('FEMALE'));
        self::assertFalse(Spec::isEnum(AlgebraGender::class)->isSatisfiedBy('OTHER'));
        self::assertTrue(Spec::createEqualSpecification(5)->equals(Spec::equalTo(5)));
        self::assertTrue(Spec::anObjectEqualTo(5)->equals(Spec::equalTo(5)));
        self::assertTrue(Spec::objectEqualTo(5)->equals(Spec::equalTo(5)));
        self::assertTrue(Spec::matchesWildcardExpression('T?mmy')->isSatisfiedBy('Tommy'));
        self::assertTrue(Spec::matchesWildcardExpressionIgnoringCase('T?MMY')->isSatisfiedBy('tommy'));
        self::assertTrue(Spec::isLessThan(5)->equals(Spec::lessThan(5)));
        self::assertTrue(Spec::isLessThanOrEqualTo(5)->equals(Spec::lessThanOrEqualTo(5)));
        self::assertTrue(Spec::isGreaterThan(5)->equals(Spec::greaterThan(5)));
        self::assertTrue(Spec::isGreaterThanOrEqualTo(5)->equals(Spec::greaterThanOrEqualTo(5)));
        self::assertTrue(Spec::includeAPercentageOf(Spec::greaterThan(50.0), Spec::greaterThan(0))->isSatisfiedBy([1, 2, 0]));
        self::assertTrue(Spec::includesAPercentageOf(Spec::greaterThan(50.0), Spec::greaterThan(0))->isSatisfiedBy([1, 2, 0]));

        // DSL functions
        self::assertInstanceOf(AllEntitiesSpecification::class, \Antevemus\ASpecification\DSL\allEntities());
        self::assertInstanceOf(NotNullSpecification::class, \Antevemus\ASpecification\DSL\allObjects());
        self::assertTrue(\Antevemus\ASpecification\DSL\isGreaterThanOrEqualTo(5)->equals(Spec::greaterThanOrEqualTo(5)));
        self::assertTrue(\Antevemus\ASpecification\DSL\blankString()->isSatisfiedBy(null));
        self::assertTrue(\Antevemus\ASpecification\DSL\isEnum(AlgebraGender::class)->isSatisfiedBy('MALE'));
        self::assertInstanceOf(AlwaysTrueSpecification::class, \Antevemus\ASpecification\DSL\createTautology());
    }
}

/**
 * Fixture for the accessible-object resolution probe (AbstractQueenPuzzle / OrderLine).
 */
final class AlgebraOrderLine
{
    private bool $processed = false;

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function pending(): bool
    {
        return true;
    }
}
