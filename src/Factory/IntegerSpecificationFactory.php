<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use InvalidArgumentException;

/**
 * IntegerSpecificationFactory - The relational leaves of a rule about integers (2.9.0, forward 044, D1).
 *
 * `Spec::int()` returns this factory; each method builds the leaf of the general factory and makes it
 * int-typed with asInteger(), so `Spec::int()->greaterThan(10)` is `Spec::greaterThan(10)->asInteger()`:
 * getType() is 'int', the evaluation refuses every non-null candidate that is not an int (a float, 12.0
 * included, a numeric text, a boolean) with IncompatibleTypeException, and two int-typed leaves close their
 * bounds against each other (`x > 10` is `x >= 11`, so `greaterThan(10)` and `lessThan(11)` are disjoint and
 * `simplify()` collapses what that proves). It reads better than one asInteger() per leaf when a whole rule
 * is about integers (installments, months, quantities):
 *
 * <code>
 * $months = Spec::int();
 * $rule = Spec::property('adjustmentPeriodMonths', $months->greaterThanOrEqualTo(12))
 *     ->and(Spec::property('installments', $months->between(1, 12)));
 * </code>
 *
 * The parameters are typed `int`: a float, a numeric text or a boolean bound is a TypeError under
 * `declare(strict_types=1)` (and refused by asInteger() otherwise). `in()` builds the set leaf of `Spec::in()`
 * over ints only: a set of ints already refuses every candidate that is not an int (its typed equality is the
 * one of equalTo()), so it needs no type of its own. The SQL, TCriteria and ALinq translations are those of
 * the leaves of the general factory.
 *
 * @version    2.9.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class IntegerSpecificationFactory
{
    /**
     * The candidate is an int strictly greater than `$value` (int-typed `>`; closed: `>= $value + 1`).
     */
    public function greaterThan(int $value): GreaterThanSpecification
    {
        return (new GreaterThanSpecification($value))->asInteger();
    }

    /**
     * The candidate is an int greater than or equal to `$value` (int-typed `>=`).
     */
    public function greaterThanOrEqualTo(int $value): GreaterThanOrEqualSpecification
    {
        return (new GreaterThanOrEqualSpecification($value))->asInteger();
    }

    /**
     * The candidate is an int strictly less than `$value` (int-typed `<`; closed: `<= $value - 1`).
     */
    public function lessThan(int $value): LessThanSpecification
    {
        return (new LessThanSpecification($value))->asInteger();
    }

    /**
     * The candidate is an int less than or equal to `$value` (int-typed `<=`).
     */
    public function lessThanOrEqualTo(int $value): LessThanOrEqualSpecification
    {
        return (new LessThanOrEqualSpecification($value))->asInteger();
    }

    /**
     * The candidate is an int in the closed interval [`$min`, `$max`]: the conjunction of the two int-typed
     * leaves, as `Spec::between()` is for date-times. An interval with `$min > $max` is empty, and
     * isContradiction() proves it.
     */
    public function between(int $min, int $max): AndSpecification
    {
        return new AndSpecification($this->greaterThanOrEqualTo($min), $this->lessThanOrEqualTo($max));
    }

    /**
     * The candidate is the int `$value` (int-typed `=`).
     */
    public function equalTo(int $value): EqualSpecification
    {
        return (new EqualSpecification($value))->asInteger();
    }

    /**
     * The candidate is an int other than `$value` (int-typed `<>`; null is refused, as by notEqual()).
     */
    public function notEqualTo(int $value): NotEqualSpecification
    {
        return (new NotEqualSpecification($value))->asInteger();
    }

    /**
     * The candidate is one of the ints given, variadically or as a single array (`in(1, 2)` ≡ `in([1, 2])`),
     * as `Spec::in()` builds it; an empty set never matches.
     *
     * @param int|array<int> ...$values
     * @throws InvalidArgumentException When a value is not an int
     */
    public function in(int|array ...$values): InSpecification
    {
        if (count($values) === 1 && is_array(reset($values))) {
            $values = reset($values); // the keys are ignored: InSpecification keeps the values as a list
        }
        foreach ($values as $value) {
            if (!is_int($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Spec::int()->in() takes ints only, not %s: convert the values before building the rule.',
                    get_debug_type($value)
                ));
            }
        }

        return new InSpecification($values);
    }
}
