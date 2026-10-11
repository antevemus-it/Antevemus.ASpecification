<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\SpecificationSimplifier;
use Antevemus\ASpecification\Tests\TestCase;

/**
 * S025Customer - Typed candidate of the simplify() tests (forward 025).
 *
 * @version    1.9.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class S025Customer
{
    public function __construct(public ?int $age = null, public ?string $status = null)
    {
    }
}

/**
 * Module20_SimplifyTest - simplify() (forward 025, phase 2, v1.9.0).
 *
 * - RN-02: truth-table equivalence over seeded random trees (comparison leaves, in(), null checks,
 *   not/and/or/nor, alwaysTrue/False, with and without because()/withCode()), evaluated with
 *   isSatisfiedBy() on a fixed candidate set before and after; the node count never grows; the
 *   result is idempotent; the input is not mutated; the annotated messages and codes reported by
 *   evaluate() are unchanged for every candidate.
 * - One targeted test per rule of D2, the annotation rules of D3, unknown types, D5 and the opt-in
 *   of the rule engine (D6).
 * - The simplify() block of README example 12 (EN/pt-BR), transcribed as written.
 *
 * @version    1.9.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module20_SimplifyTest extends TestCase
{
    private int $annotationCounter = 0;

    public function run(): void
    {
        $this->testIdentity();
        $this->testAnnihilation();
        $this->testDoubleNegation();
        $this->testIdempotence();
        $this->testFlattening();
        $this->testContradictionAndTautology();
        $this->testSubsumption();
        $this->testAnnotationsArePreserved();
        $this->testUnknownTypesAreLeftAsTheyAre();
        $this->testImmutabilityAndIdempotenceOfTheResult();
        $this->testShortcuts();
        $this->testNullCandidateCaveat();
        $this->testRuleEngineOptIn();
        $this->testTruthTableEquivalence();
        $this->testReadme12SimplifyRunsAsWritten();
    }

    // ==========================================
    // Targeted tests, one per rule of D2
    // ==========================================

    private function testIdentity(): void
    {
        $a = Spec::property('a', Spec::equalTo(1));

        $this->assertTrue(Spec::simplify(new AndSpecification($a, Spec::alwaysTrue())) === $a, 'A ∧ true = A');
        $this->assertTrue(Spec::simplify(new AndSpecification(Spec::alwaysTrue(), $a)) === $a, 'true ∧ A = A');
        $this->assertTrue(Spec::simplify(new OrSpecification($a, Spec::alwaysFalse())) === $a, 'A ∨ false = A');
        $this->assertTrue(Spec::simplify(new OrSpecification(Spec::alwaysFalse(), $a)) === $a, 'false ∨ A = A');

        $onlyTrue = Spec::simplify(new AndSpecification(Spec::alwaysTrue(), Spec::alwaysTrue()));
        $this->assertInstanceOf(AlwaysTrueSpecification::class, $onlyTrue, 'true ∧ true = true');

        // Inside a property restriction too.
        $restricted = Spec::simplify(Spec::property('a', new AndSpecification(Spec::greaterThan(1), Spec::alwaysTrue())));
        $this->assertInstanceOf(PropertySpecification::class, $restricted);
        $this->assertEquals('(AlwaysTrueSpecification WHERE a: GreaterThanSpecification)', (string) $restricted);
    }

    private function testAnnihilation(): void
    {
        $a = Spec::property('a', Spec::equalTo(1));

        $this->assertInstanceOf(AlwaysFalseSpecification::class, Spec::simplify(new AndSpecification($a, Spec::alwaysFalse())), 'A ∧ false = false');
        $this->assertInstanceOf(AlwaysTrueSpecification::class, Spec::simplify(new OrSpecification(Spec::alwaysTrue(), $a)), 'true ∨ A = true');

        // The constant keeps the type of the chain it replaces.
        $typed = Spec::simplify(new AndSpecification(Spec::specify(S025Customer::class), Spec::alwaysFalse()));
        $this->assertInstanceOf(AlwaysFalseSpecification::class, $typed);
        $this->assertEquals(S025Customer::class, $typed->getType());
    }

    private function testDoubleNegation(): void
    {
        $a = Spec::property('a', Spec::equalTo(1));

        $this->assertTrue(Spec::simplify(new NotSpecification(new NotSpecification($a))) === $a, '¬¬A = A');
        $this->assertTrue(Spec::simplify($a->not()->not()->not()->not()) === $a, '¬¬¬¬A = A');
        $single = Spec::simplify($a->not()->not()->not());
        $this->assertInstanceOf(NotSpecification::class, $single, '¬¬¬A = ¬A');
        $this->assertTrue($single->getSpecification() === $a);

        // ¬(¬A) inside a chain, then flattened into it.
        $b = Spec::property('b', Spec::equalTo(2));
        $chain = Spec::simplify(new AndSpecification($b, new NotSpecification(new NotSpecification(new AndSpecification($a, $b)))));
        $this->assertEquals('((AlwaysTrueSpecification WHERE b: EqualSpecification) AND (AlwaysTrueSpecification WHERE a: EqualSpecification))', (string) $chain, 'b ∧ ¬¬(a ∧ b) = b ∧ a');
    }

    private function testIdempotence(): void
    {
        // Equal by equals(), built separately.
        $simplified = Spec::simplify(new AndSpecification(Spec::property('a', Spec::equalTo(1)), Spec::property('a', Spec::equalTo(1))));
        $this->assertInstanceOf(PropertySpecification::class, $simplified, 'A ∧ A = A');

        $simplified = Spec::simplify(new OrSpecification(Spec::property('a', Spec::in(1, 2)), Spec::property('a', Spec::in(2, 1))));
        $this->assertInstanceOf(PropertySpecification::class, $simplified, 'A ∨ A = A (in() is a set)');

        $a = Spec::property('a', Spec::equalTo(1));
        $b = Spec::property('b', Spec::equalTo(2));
        $simplified = Spec::simplify(new AndSpecification(new AndSpecification($a, $b), new AndSpecification($b, $a)));
        $this->assertTrue($simplified instanceof AndSpecification && $simplified->getLeftSide() === $a && $simplified->getRightSide() === $b, 'a ∧ b ∧ b ∧ a = a ∧ b');
    }

    private function testFlattening(): void
    {
        $a = Spec::property('a', Spec::equalTo(1));
        $b = Spec::property('b', Spec::equalTo(2));
        $c = Spec::property('c', Spec::greaterThan(5));
        $d = Spec::property('d', Spec::lessThan(0));

        // Right-deep chain becomes left-deep, same operands in the same order, same node count.
        $rightDeep = new AndSpecification($a, new AndSpecification($b, new AndSpecification($c, $d)));
        $flat = Spec::simplify($rightDeep);
        $this->assertTrue($flat instanceof AndSpecification && $flat->getRightSide() === $d);
        $this->assertTrue($flat->getLeftSide()->getRightSide() === $c);
        $this->assertTrue($flat->getLeftSide()->getLeftSide()->getLeftSide() === $a);
        $this->assertEquals($this->countNodes($rightDeep), $this->countNodes($flat));

        // Flattening is what lets the rules see across the nesting: a ∧ (b ∧ ¬a).
        $hidden = new AndSpecification($a, new AndSpecification($b, $a->not()));
        $this->assertInstanceOf(AlwaysFalseSpecification::class, Spec::simplify($hidden));

        // A left-deep chain without redundancy is returned as is.
        $leftDeep = new OrSpecification(new OrSpecification($a, $b), $c);
        $this->assertTrue(Spec::simplify($leftDeep) === $leftDeep);

        // Nested chains of the other kind are not merged.
        $mixed = new AndSpecification($a, new OrSpecification($b, $c));
        $this->assertTrue(Spec::simplify($mixed) === $mixed);
    }

    private function testContradictionAndTautology(): void
    {
        $active = Spec::property('status', Spec::equalTo('ACTIVE'));
        $inactive = Spec::property('status', Spec::equalTo('INACTIVE'));

        $this->assertInstanceOf(AlwaysFalseSpecification::class, Spec::simplify(new AndSpecification($active, $active->not())), 'A ∧ ¬A = false');
        $this->assertInstanceOf(AlwaysFalseSpecification::class, Spec::simplify(new AndSpecification($active, $inactive)), 'disjoint operands = false');
        $this->assertInstanceOf(AlwaysTrueSpecification::class, Spec::simplify(new OrSpecification($active, $active->not())), 'A ∨ ¬A = true');
        $this->assertInstanceOf(
            AlwaysFalseSpecification::class,
            Spec::simplify(Spec::property('a', Spec::greaterThan(10))->and(Spec::property('a', Spec::lessThan(5)))),
            'empty range = false'
        );

        // Not proven means not rewritten (no SAT solving).
        $open = new OrSpecification(Spec::property('a', Spec::greaterThan(5)), Spec::property('a', Spec::lessThan(10)));
        $this->assertTrue(Spec::simplify($open) === $open);
    }

    private function testSubsumption(): void
    {
        $one = Spec::property('a', Spec::equalTo(1));
        $oneOrTwo = Spec::property('a', Spec::in(1, 2));

        $this->assertTrue(Spec::simplify(new AndSpecification($oneOrTwo, $one)) === $one, 'A ∧ B = A when A ⊆ B');
        $this->assertTrue(Spec::simplify(new OrSpecification($one, $oneOrTwo)) === $oneOrTwo, 'A ∨ B = B when A ⊆ B');

        $gt5 = Spec::property('a', Spec::greaterThan(5));
        $gt10 = Spec::property('a', Spec::greaterThan(10));
        $this->assertTrue(Spec::simplify(new AndSpecification($gt5, $gt10)) === $gt10, 'a > 5 ∧ a > 10 = a > 10');
        $this->assertTrue(Spec::simplify(new OrSpecification($gt5, $gt10)) === $gt5, 'a > 5 ∨ a > 10 = a > 5');

        // The type specification Spec<T> generalizes its own restrictions.
        $adult = Spec::specify(S025Customer::class)->where('age', Spec::greaterThanOrEqualTo(18));
        $simplified = Spec::simplify(new AndSpecification(Spec::specify(S025Customer::class), $adult));
        $this->assertTrue($simplified->isSatisfiedBy(new S025Customer(20)));
        $this->assertFalse($simplified->isSatisfiedBy(new S025Customer(10)));
        $this->assertTrue($this->countNodes($simplified) < $this->countNodes(new AndSpecification(Spec::specify(S025Customer::class), $adult)));
    }

    // ==========================================
    // D3: annotations
    // ==========================================

    private function testAnnotationsArePreserved(): void
    {
        $a = Spec::property('a', Spec::equalTo(1));
        $annotated = Spec::property('a', Spec::equalTo(1))->because('Must be one')->withCode('E-1');

        // An annotated operand is never removed by identity's partner rules, subsumption or annihilation.
        $kept = Spec::simplify(new AndSpecification($annotated, Spec::alwaysFalse()));
        $this->assertTrue($kept instanceof AndSpecification && $kept->getLeftSide() === $annotated, 'annotated ∧ false keeps the annotated node');
        $kept = Spec::simplify(new OrSpecification($annotated, Spec::alwaysTrue()));
        $this->assertTrue($kept instanceof OrSpecification && $kept->getLeftSide() === $annotated, 'annotated ∨ true keeps the annotated node');
        $general = Spec::property('a', Spec::in(1, 2))->withCode('E-2');
        $kept = Spec::simplify(new AndSpecification($a, $general));
        $this->assertTrue($kept instanceof AndSpecification && $kept->getRightSide() === $general, 'annotated generalization is not dropped by subsumption');
        $kept = Spec::simplify(new OrSpecification($annotated, Spec::property('a', Spec::in(1, 2))));
        $this->assertTrue($kept instanceof OrSpecification, 'annotated special case is not dropped by subsumption');

        // A contradiction keeps its annotated operands and drops the others.
        $active = Spec::property('status', Spec::equalTo('ACTIVE'));
        $contradiction = Spec::simplify(new AndSpecification(new AndSpecification($annotated, $active), $active->not()));
        $this->assertTrue($contradiction instanceof AndSpecification && $contradiction->getLeftSide() === $annotated);
        $this->assertInstanceOf(AlwaysFalseSpecification::class, $contradiction->getRightSide());

        // An annotated constant is never removed as an identity.
        $annotatedTrue = Spec::alwaysTrue()->because('always');
        $kept = Spec::simplify(new AndSpecification($a, $annotatedTrue));
        $this->assertTrue($kept instanceof AndSpecification && $kept->getRightSide() === $annotatedTrue);

        // An annotated chain is simplified inside but never collapsed or replaced by a constant.
        $chain = (new AndSpecification($a, Spec::alwaysTrue()))->because('Chain')->withCode('E-3');
        $this->assertTrue(Spec::simplify($chain) === $chain, 'annotated (A ∧ true) keeps two operands');
        $chain = (new AndSpecification(new AndSpecification($a, Spec::alwaysTrue()), Spec::property('b', Spec::equalTo(2))))->because('Chain')->withCode('E-3');
        $inside = Spec::simplify($chain);
        $this->assertEquals(7, $this->countNodes($inside), 'annotated chain loses its neutral operand');
        $this->assertEquals('Chain', $inside->evaluate((object) ['a' => 2, 'b' => 2])->failures[0]->message);
        $this->assertEquals('E-3', $inside->evaluate((object) ['a' => 2, 'b' => 2])->failures[0]->code);
        $contradictory = (new AndSpecification($active, $active->not()))->withCode('E-4');
        $this->assertTrue(Spec::simplify($contradictory) === $contradictory, 'annotated contradiction is not replaced by false');

        // An annotated chain inside an unannotated one is a unit, not flattened into it.
        $unit = (new AndSpecification($a, Spec::property('b', Spec::equalTo(2))))->because('Unit');
        $outer = Spec::simplify(new AndSpecification($unit, Spec::property('c', Spec::greaterThan(0))));
        $this->assertTrue($outer instanceof AndSpecification && $outer->getLeftSide() === $unit);

        // Double negation: never removes an annotated negation, nor exposes an annotated subtree.
        $annotatedNot = (new NotSpecification($a->not()))->withCode('E-5');
        $this->assertTrue(Spec::simplify($annotatedNot) === $annotatedNot);
        $innerAnnotatedNot = new NotSpecification($a->not()->because('inner'));
        $this->assertTrue(Spec::simplify($innerAnnotatedNot) === $innerAnnotatedNot);
        $hiddenAnnotation = new NotSpecification(new NotSpecification($annotated));
        $this->assertTrue(Spec::simplify($hiddenAnnotation) === $hiddenAnnotation, '¬¬A with A annotated is kept: A would surface');

        // Idempotence merges only when the same annotation is on both sides.
        $same = Spec::simplify(new AndSpecification($annotated, Spec::property('a', Spec::equalTo(1))->because('Must be one')->withCode('E-1')));
        $this->assertTrue($same === $annotated, 'same annotation on both sides: merged');
        $different = new AndSpecification($annotated, Spec::property('a', Spec::equalTo(1))->because('Other')->withCode('E-9'));
        $this->assertTrue(Spec::simplify($different) === $different, 'different annotations: kept');
        $plainAndAnnotated = Spec::simplify(new AndSpecification($annotated, $a));
        $this->assertTrue($plainAndAnnotated === $annotated, 'the unannotated twin is dropped, the annotated one kept');

        // equals() alone is not trusted for annotations: the composite wrapper of a leaf (a(leaf))
        // compares only the wrapped leaf, so two wrappers with different because() are "equal".
        $wrappedX = Spec::getFactory()->a(Spec::isNull())->because('X');
        $wrappedY = Spec::getFactory()->a(Spec::isNull())->because('Y');
        $this->assertTrue($wrappedX->equals($wrappedY), 'precondition: the wrapper ignores annotations in equals()');
        $wrappers = new AndSpecification($wrappedX, $wrappedY);
        $this->assertTrue(Spec::simplify($wrappers) === $wrappers, 'different annotations behind equal wrappers: kept');
        $this->assertTrue(Spec::simplify(new AndSpecification($wrappedX, clone $wrappedX)) === $wrappedX, 'same annotation behind equal wrappers: merged');

        // The annotated messages of evaluate() are those of the original.
        $tree = new AndSpecification(new AndSpecification($annotated, Spec::alwaysTrue()), Spec::property('a', Spec::in(1, 2)));
        foreach ([(object) ['a' => 1, 'b' => 1], (object) ['a' => 3, 'b' => 1]] as $candidate) {
            $this->assertEquals($this->annotationsOf($tree->evaluate($candidate)), $this->annotationsOf(Spec::simplify($tree)->evaluate($candidate)));
        }
    }

    // ==========================================
    // Unknown types, D5, shortcuts
    // ==========================================

    private function testUnknownTypesAreLeftAsTheyAre(): void
    {
        $a = Spec::property('a', Spec::equalTo(1));

        // A subclass of a known composite is opaque: not opened, not rewritten.
        $custom = new class ($a, Spec::alwaysTrue()) extends AndSpecification {
        };
        $this->assertTrue(Spec::simplify($custom) === $custom);
        $this->assertTrue(Spec::simplify(new AndSpecification($custom, $custom)) === $custom, 'idempotence by identity still applies');

        // An opaque node whose evaluate() is not the default one is never dropped (subsumption, absorption).
        $calling = Spec::calling('format', ['Y'], Spec::equalTo('2026'));
        $withFalse = Spec::simplify(new AndSpecification($calling, Spec::alwaysFalse()));
        $this->assertTrue($withFalse instanceof AndSpecification && $withFalse->getLeftSide() === $calling);

        // A rule binding is simplified inside and kept.
        $rule = new RuleDefinition(code: 'R-1', name: 'Rule one', ruleType: 'one', scope: 's');
        $bound = new RuleBoundSpecification(new AndSpecification($a, Spec::alwaysTrue()), $rule);
        $simplified = Spec::simplify($bound);
        $this->assertInstanceOf(RuleBoundSpecification::class, $simplified);
        $this->assertTrue($simplified->getInnerSpecification() === $a);
        $this->assertTrue($simplified->getRule() === $rule);
        $redundant = Spec::simplify(new AndSpecification($a, new RuleBoundSpecification(Spec::property('a', Spec::in(1, 2)), $rule)));
        $this->assertTrue($redundant instanceof AndSpecification, 'a rule binding is never dropped by subsumption');

        // A joint denial is simplified inside.
        $nor = Spec::simplify(new JointDenialSpecification(new AndSpecification($a, Spec::alwaysTrue()), Spec::property('b', Spec::equalTo(2))));
        $this->assertTrue($nor instanceof JointDenialSpecification && $nor->getLeftSide() === $a);
    }

    private function testImmutabilityAndIdempotenceOfTheResult(): void
    {
        $a = Spec::property('a', Spec::equalTo(1));
        $untouched = new OrSpecification(Spec::property('b', Spec::equalTo(2)), Spec::property('b', Spec::equalTo(3)));
        $tree = new AndSpecification(new AndSpecification($a, Spec::alwaysTrue()), $untouched);
        $before = serialize($tree);

        $simplified = Spec::simplify($tree);

        $this->assertEquals($before, serialize($tree), 'the input is not modified');
        $this->assertTrue($simplified instanceof AndSpecification && $simplified->getRightSide() === $untouched, 'unchanged subtrees are shared');
        $this->assertTrue(Spec::simplify($simplified) === $simplified, 'simplify(simplify(x)) is simplify(x)');
        $this->assertTrue(Spec::simplify($a) === $a, 'a leaf is returned as is');
    }

    private function testShortcuts(): void
    {
        $active = Spec::property('status', Spec::equalTo('ACTIVE'));
        $tree = Spec::not(Spec::not($active))->and(Spec::alwaysTrue());

        $this->assertTrue(Spec::simplify($tree) === $active, 'Spec::simplify()');
        $this->assertTrue($tree->simplify() === $active, 'AbstractSpecification::simplify()');
        $this->assertTrue(SpecificationSimplifier::simplify($tree) === $active, 'SpecificationSimplifier::simplify()');
    }

    /**
     * D4 caveat: AND/OR/NOT reject a null candidate whatever their operands. When the root is
     * simplified away, the remaining node answers for null on its own; non-null candidates are
     * what the equivalence covers (as isTautology()/isContradiction() already assume).
     */
    private function testNullCandidateCaveat(): void
    {
        $tree = new AndSpecification(Spec::alwaysTrue(), Spec::alwaysTrue());
        $simplified = Spec::simplify($tree);

        $this->assertTrue($tree->isSatisfiedBy(new \stdClass()) === $simplified->isSatisfiedBy(new \stdClass()));
        $this->assertFalse($tree->isSatisfiedBy(null));
        $this->assertTrue($simplified->isSatisfiedBy(null), 'alwaysTrue() accepts null on its own');
    }

    // ==========================================
    // D6: opt-in of the rule engine
    // ==========================================

    private function testRuleEngineOptIn(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'R-20', name: 'Adult', ruleType: 'adult', scope: 'contract'));
        $catalog->addRule(new RuleDefinition(code: 'R-21', name: 'Known status', ruleType: 'status', scope: 'contract'));
        $registry = Spec::ruleRegistry()
            ->registerClosure('adult', fn() => new AndSpecification(Spec::property('age', Spec::greaterThanOrEqualTo(18)), Spec::alwaysTrue()))
            ->registerClosure('status', fn() => Spec::not(Spec::not(Spec::property('status', Spec::in('ACTIVE', 'PENDING')))));

        $engine = Spec::engine($catalog, $registry);
        $this->assertFalse($engine->isSimplificationEnabled(), 'disabled by default');
        $plain = $engine->compileSpecification('contract');

        $simplifying = $engine->withSimplification();
        $this->assertTrue($simplifying->isSimplificationEnabled());
        $this->assertFalse($engine->isSimplificationEnabled(), 'withSimplification() returns a copy');
        $simplified = $simplifying->compileSpecification('contract');

        $rules = static fn(ISpecification $compiled): string => implode(' AND ', array_map(
            static fn(RuleBoundSpecification $bound): string => (string) $bound,
            $compiled->getSpecifications()
        ));
        $this->assertEquals(
            'Rule[R-20](((AlwaysTrueSpecification WHERE age: GreaterThanOrEqualSpecification) AND AlwaysTrueSpecification))'
            . ' AND Rule[R-21](NOT(NOT((AlwaysTrueSpecification WHERE status: InSpecification))))',
            $rules($plain),
            'nothing is simplified unless asked'
        );
        $this->assertEquals(
            'Rule[R-20]((AlwaysTrueSpecification WHERE age: GreaterThanOrEqualSpecification))'
            . ' AND Rule[R-21]((AlwaysTrueSpecification WHERE status: InSpecification))',
            $rules($simplified)
        );
        $this->assertTrue($this->countNodes($simplified) < $this->countNodes($plain));

        foreach ([new S025Customer(20, 'ACTIVE'), new S025Customer(10, 'ACTIVE'), new S025Customer(30, 'CLOSED'), new S025Customer(5, 'CLOSED')] as $candidate) {
            $before = $engine->validate($candidate, 'contract');
            $after = $simplifying->validate($candidate, 'contract');
            $this->assertEquals($before->isSatisfied(), $after->isSatisfied());
            $this->assertEquals($before->getFailureCodes(), $after->getFailureCodes(), 'the rule failures are the same');
        }
        $this->assertEquals(['R-20', 'R-21'], $simplifying->validate(new S025Customer(5, 'CLOSED'), 'contract')->getFailureCodes());
    }

    // ==========================================
    // README (1.9.0): o bloco do simplify() no exemplo 12 transcrito (EN/pt-BR); cada comentário
    // "// valor" do bloco é uma asserção aqui.
    // ==========================================

    /** README 12: simplify() de uma árvore redundante, anotação preservada, motor com withSimplification(). */
    private function testReadme12SimplifyRunsAsWritten(): void
    {
        $active = Spec::property('status', Spec::equalTo('ACTIVE'));
        $adult  = Spec::property('age', Spec::greaterThanOrEqualTo(18))->because('The tenant must be an adult.')->withCode('TENANT_MINOR');

        // A redundant tree, as composed rules often end up: ¬¬A ∧ true ∧ B ∧ (status in ACTIVE, PENDING)
        $tree = Spec::not(Spec::not($active))
            ->and(Spec::alwaysTrue())
            ->and($adult)
            ->and(Spec::property('status', Spec::in('ACTIVE', 'PENDING')));  // implied by status = ACTIVE

        $simple = $tree->simplify();                                   // same as Spec::simplify($tree)
        $this->assertEquals((string) Spec::simplify($tree), (string) $simple);
        $this->assertTrue($simple->equals($active->and($adult)));      // true: double negation, identity and implied leaf removed
        $this->assertTrue(Spec::simplify($simple) === $simple);        // true: nothing left to simplify
        $this->assertTrue($this->countNodes($simple) < $this->countNodes($tree));

        // Same truth value, and the annotated failure is still reported
        $minor = (object) ['status' => 'ACTIVE', 'age' => 16];
        $this->assertTrue($simple->isSatisfiedBy($minor) === $tree->isSatisfiedBy($minor)); // true
        $this->assertEquals('TENANT_MINOR', $simple->evaluate($minor)->failures[0]->code);
        $this->assertEquals('The tenant must be an adult.', $simple->evaluate($minor)->failures[0]->message);

        // Proven contradictions and tautologies become constants, but an annotated node is never dropped
        $this->assertInstanceOf(AlwaysFalseSpecification::class, Spec::simplify($active->and($active->not()))); // alwaysFalse()
        $this->assertInstanceOf(AlwaysTrueSpecification::class, Spec::simplify($active->or($active->not())));   // alwaysTrue()
        $this->assertEquals(['TENANT_MINOR'], Spec::simplify($adult->and(Spec::alwaysFalse()))->evaluate($minor)->getCodes());

        // Rule engine: opt-in (off by default); every rule binding is kept and simplified inside
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'R-30', name: 'Active tenant', ruleType: 'active_tenant', scope: 'rental_contract'));
        $registry = Spec::ruleRegistry()->registerClosure('active_tenant', fn() => Spec::not(Spec::not($active))->and(Spec::alwaysTrue()));

        $engine = Spec::engine($catalog, $registry)->withSimplification();
        $this->assertEquals(
            'Rule[R-30]((AlwaysTrueSpecification WHERE status: EqualSpecification))',
            (string) $engine->compileSpecification('rental_contract')
        );
        $this->assertEquals(['R-30'], $engine->validate((object) ['status' => 'CLOSED'], 'rental_contract')->getFailureCodes());

        // Prosa do exemplo: desligado por padrão; a entrada não muda; premissa do candidato não nulo
        $this->assertFalse(Spec::engine($catalog, $registry)->isSimplificationEnabled());
        $this->assertEquals(
            'Rule[R-30]((NOT(NOT((AlwaysTrueSpecification WHERE status: EqualSpecification))) AND AlwaysTrueSpecification))',
            (string) Spec::engine($catalog, $registry)->compileSpecification('rental_contract')
        );
        $this->assertTrue(str_starts_with((string) $tree, '(((NOT(NOT('), 'the input is not modified');
        $this->assertFalse(Spec::alwaysTrue()->and(Spec::alwaysTrue())->isSatisfiedBy(null));
        $this->assertTrue(Spec::simplify(Spec::alwaysTrue()->and(Spec::alwaysTrue()))->isSatisfiedBy(null));
    }

    // ==========================================
    // RN-02: truth-table equivalence over generated trees
    // ==========================================

    private function testTruthTableEquivalence(): void
    {
        $domain = [1, 2, 3, null];
        $candidates = [];
        foreach ($domain as $va) {
            foreach ($domain as $vb) {
                $candidates[] = (object) ['a' => $va, 'b' => $vb];
            }
        }

        mt_srand(20261011);
        $this->annotationCounter = 0;
        $violations = [];
        $shrunk = 0;
        $annotatedShrunk = 0;
        $toConstant = 0;
        $trees = 2000;

        for ($i = 0; $i < $trees; $i++) {
            $tree = $this->randomTree(4);
            $serialized = serialize($tree);
            $label = sprintf('#%d %s', $i, (string) $tree);

            $simplified = Spec::simplify($tree);

            if (serialize($tree) !== $serialized) {
                $violations[] = "input mutated: {$label}";
            }
            if (Spec::simplify($simplified) !== $simplified) {
                $violations[] = "not idempotent: {$label}";
            }
            $nodesBefore = $this->countNodes($tree);
            $nodesAfter = $this->countNodes($simplified);
            if ($nodesAfter > $nodesBefore) {
                $violations[] = "node count grew ({$nodesBefore} → {$nodesAfter}): {$label}";
            }
            if ($nodesAfter < $nodesBefore) {
                $shrunk++;
                if (str_contains($serialized, '"M') || str_contains($serialized, '"C')) {
                    $annotatedShrunk++;
                }
            }
            if ($simplified instanceof AlwaysTrueSpecification || $simplified instanceof AlwaysFalseSpecification) {
                $toConstant++;
            }

            foreach ($candidates as $index => $candidate) {
                if ($tree->isSatisfiedBy($candidate) !== $simplified->isSatisfiedBy($candidate)) {
                    $violations[] = sprintf('truth value differs on candidate %d: %s ⇒ %s', $index, $label, (string) $simplified);
                    continue;
                }
                $before = $tree->evaluate($candidate);
                $after = $simplified->evaluate($candidate);
                if ($before->isSatisfied !== $after->isSatisfied || $before->isError !== $after->isError) {
                    $violations[] = sprintf('evaluate() verdict differs on candidate %d: %s ⇒ %s', $index, $label, (string) $simplified);
                    continue;
                }
                if ($this->annotationsOf($before) !== $this->annotationsOf($after)) {
                    $violations[] = sprintf(
                        'annotations differ on candidate %d: %s ⇒ %s [%s] vs [%s]',
                        $index,
                        $label,
                        (string) $simplified,
                        implode(' ', $this->annotationsOf($before)),
                        implode(' ', $this->annotationsOf($after))
                    );
                }
            }
        }

        // One assertion for the whole set: no violation in 2000 trees × 16 candidates.
        $this->assertEquals([], $violations, implode(PHP_EOL, array_slice($violations, 0, 5)));
        // The guard is not vacuous: the rewrite fired, also on annotated trees and down to constants.
        $this->assertTrue($shrunk > $trees / 4, "only {$shrunk} trees shrank");
        $this->assertTrue($annotatedShrunk > 0, 'no annotated tree shrank');
        $this->assertTrue($toConstant > 0, 'no tree became a constant');
    }

    /**
     * Random tree: comparison leaves under property restrictions, in(), null checks, constants, NOT,
     * AND, OR, NOR and the shapes the rules target (¬¬X, X ∧ X, X ∧ ¬X, X ∨ ¬X, X ∧ true, X ∨ false,
     * X ∧ false, X ∨ true), with because()/withCode() on leaves and composites.
     */
    private function randomTree(int $depth): ISpecification
    {
        if ($depth === 0 || mt_rand(0, 9) < 3) {
            return $this->maybeAnnotate($this->randomLeaf(), 25);
        }

        $d = $depth - 1;
        $tree = match (mt_rand(0, 11)) {
            0 => new NotSpecification($this->randomTree($d)),
            1 => new NotSpecification(new NotSpecification($this->randomTree($d))),
            2, 3 => new AndSpecification($this->randomTree($d), $this->randomTree($d)),
            4, 5 => new OrSpecification($this->randomTree($d), $this->randomTree($d)),
            6 => (function () use ($d): ISpecification {
                $x = $this->randomTree($d);
                $twin = match (mt_rand(0, 2)) {
                    0 => $x,
                    1 => clone $x,
                    default => $this->randomTree($d),
                };
                return mt_rand(0, 1) === 0 ? new AndSpecification($x, $twin) : new OrSpecification($twin, $x);
            })(),
            7 => (function () use ($d): ISpecification {
                $x = $this->randomTree($d);
                $other = $this->randomTree($d);
                return mt_rand(0, 1) === 0
                    ? new AndSpecification(new AndSpecification($x, $other), $x->not())
                    : new OrSpecification($x->not(), new OrSpecification($other, $x));
            })(),
            8, 9 => (function () use ($d): ISpecification {
                $x = $this->randomTree($d);
                $constant = $this->maybeAnnotate(mt_rand(0, 1) === 0 ? Spec::alwaysTrue() : Spec::alwaysFalse(), 15);
                return match (mt_rand(0, 3)) {
                    0 => new AndSpecification($x, $constant),
                    1 => new AndSpecification($constant, $x),
                    2 => new OrSpecification($x, $constant),
                    default => new OrSpecification($constant, $x),
                };
            })(),
            10 => new JointDenialSpecification($this->randomTree($d), $this->randomTree($d)),
            default => Spec::property(mt_rand(0, 1) === 0 ? 'a' : 'b', $this->randomValueTree(2)),
        };

        return $this->maybeAnnotate($tree, 15);
    }

    /**
     * Leaves applied to the candidate (a stdClass with properties a and b).
     */
    private function randomLeaf(): ISpecification
    {
        return match (mt_rand(0, 13)) {
            0 => Spec::property('a', Spec::equalTo(1)),
            1 => Spec::property('a', Spec::equalTo(2)),
            2 => Spec::property('a', Spec::lessThan(2)),
            3 => Spec::property('a', Spec::greaterThanOrEqualTo(2)),
            4 => Spec::property('b', Spec::greaterThan(1)),
            5 => Spec::property('a', Spec::in(1, 2)),
            6 => Spec::property('b', Spec::in(2, 3)),
            7 => Spec::property('a', Spec::isNotNull()),
            8 => Spec::property('b', Spec::isNull()),
            9 => Spec::isNotNull(),
            10 => Spec::isNull(),
            11 => Spec::alwaysTrue(),
            12 => Spec::alwaysFalse(),
            default => Spec::property('b', Spec::lessThanOrEqualTo(2)),
        };
    }

    /**
     * Trees applied to a property value (an int), for the inside of a property restriction.
     */
    private function randomValueTree(int $depth): ISpecification
    {
        if ($depth === 0 || mt_rand(0, 9) < 4) {
            $leaf = match (mt_rand(0, 7)) {
                0 => Spec::equalTo(1),
                1 => Spec::greaterThan(1),
                2 => Spec::lessThan(3),
                3 => Spec::in(1, 2),
                4 => Spec::in(2, 3),
                5 => Spec::isNotNull(),
                6 => Spec::alwaysTrue(),
                default => Spec::alwaysFalse(),
            };
            return $this->maybeAnnotate($leaf, 20);
        }

        $d = $depth - 1;
        $tree = match (mt_rand(0, 4)) {
            0 => new NotSpecification($this->randomValueTree($d)),
            1, 2 => new AndSpecification($this->randomValueTree($d), $this->randomValueTree($d)),
            default => new OrSpecification($this->randomValueTree($d), $this->randomValueTree($d)),
        };

        return $this->maybeAnnotate($tree, 10);
    }

    /**
     * Annotates the node with a probability (percent): because() only, withCode() only, or both,
     * with unique values ("M<n>" / "C<n>") so that every annotation is traceable.
     */
    private function maybeAnnotate(ISpecification $node, int $percent): ISpecification
    {
        if (mt_rand(1, 100) > $percent) {
            return $node;
        }

        $n = ++$this->annotationCounter;
        return match (mt_rand(0, 2)) {
            0 => $node->because("M{$n}"),
            1 => $node->withCode("C{$n}"),
            default => $node->because("M{$n}")->withCode("C{$n}"),
        };
    }

    /**
     * The annotations visible in an evaluation: one "message|code" token per failure, where the
     * message is kept only when it is a because() message ("M<n>") and default messages are masked.
     *
     * @return list<string>
     */
    private function annotationsOf(SpecificationResult $result): array
    {
        $tokens = [];
        foreach ($result->failures as $failure) {
            $custom = preg_match('/^M\d+$/', $failure->message) === 1 || $failure->message === 'Must be one';
            if (!$custom && $failure->code === null) {
                continue;
            }
            $tokens[] = ($custom ? $failure->message : '*') . '|' . ($failure->code ?? '-');
        }
        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }

    /**
     * Node count, independent of the simplifier: every composite counts its children.
     */
    private function countNodes(ISpecification $node): int
    {
        if ($node instanceof RuleBoundSpecification) {
            return 1 + $this->countNodes($node->getInnerSpecification());
        }
        $count = 1;
        if ($node instanceof ICompositeSpecification) {
            foreach ($node->getSpecifications() as $child) {
                $count += $this->countNodes($child);
            }
        }

        return $count;
    }
}
