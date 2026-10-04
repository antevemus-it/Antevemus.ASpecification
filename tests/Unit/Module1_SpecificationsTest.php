<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\String\DateStringSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;

class Module1_SpecificationsTest extends TestCase
{
    public function run(): void
    {
        $eq10 = new EqualSpecification(10);
        $gt5 = new GreaterThanSpecification(5);
        $lt20 = new LessThanSpecification(20);
        $notEq = new NotEqualSpecification(99);

        $this->assertTrue($eq10->isSatisfiedBy(10));
        $this->assertFalse($eq10->isSatisfiedBy(5));
        $this->assertTrue($gt5->isSatisfiedBy(8));
        $this->assertFalse($gt5->isSatisfiedBy(3));
        $this->assertTrue($lt20->isSatisfiedBy(15));
        $this->assertFalse($lt20->isSatisfiedBy(25));
        $this->assertTrue($notEq->isSatisfiedBy(10));
        $this->assertFalse($notEq->isSatisfiedBy(99));

        // Composição fluente via AbstractSpecification
        $andSpec = $gt5->and($lt20);
        $this->assertTrue($andSpec->isSatisfiedBy(10));
        $this->assertFalse($andSpec->isSatisfiedBy(30));

        $orSpec = $eq10->or(new EqualSpecification(20));
        $this->assertTrue($orSpec->isSatisfiedBy(10));
        $this->assertTrue($orSpec->isSatisfiedBy(20));
        $this->assertFalse($orSpec->isSatisfiedBy(30));

        $notSpec = $eq10->not();
        $this->assertFalse($notSpec->isSatisfiedBy(10));
        $this->assertTrue($notSpec->isSatisfiedBy(15));

        // Subsunção e disjunção
        $allTrue = new AlwaysTrueSpecification();
        $allFalse = new AlwaysFalseSpecification();
        $this->assertTrue($allTrue->isGeneralizationOf($eq10));
        $this->assertTrue($allTrue->isDisjointWith($allFalse));

        // Strings
        $dateSpec = new DateStringSpecification("Y-m-d");
        $this->assertTrue($dateSpec->isSatisfiedBy("2026-09-26"));
        $this->assertFalse($dateSpec->isSatisfiedBy("invalido"));

        $ignoreCase = new EqualIgnoreCaseStringSpecification("antevemus");
        $this->assertTrue($ignoreCase->isSatisfiedBy("ANTEVEMUS"));
        $this->assertFalse($ignoreCase->isSatisfiedBy("outro"));

        $regex = new RegexSpecification('/^[a-z]+$/');
        $this->assertTrue($regex->isSatisfiedBy("abc"));
        $this->assertFalse($regex->isSatisfiedBy("abc123"));

        $wildcard = new WildcardSpecification("user_*_test");
        $this->assertTrue($wildcard->isSatisfiedBy("user_admin_test"));
        $this->assertFalse($wildcard->isSatisfiedBy("other"));
    }
}
