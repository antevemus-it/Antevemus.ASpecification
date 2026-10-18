<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;

/**
 * Generator of row specifications for the parity benches (extracted from the null semantics parity of
 * Module 22, 1.11.0, unchanged): property() over the nullable columns n, m (integers) and s (text),
 * with a leaf or a composition of values inside (not/allOf/anyOf/notIn), combined by and/or/not/nor/
 * allOf/anyOf, with and without because()/withCode(). Driven by mt_rand(): seed it (mt_srand) before
 * creating the generator for a reproducible sequence. Used by Module 22 (memory = SQL = PdoRepository =
 * TCriteria) and Module 13 (the TCriteria keeps its answer after add(..., AND), BUG-20261013-4CLQ).
 *
 * @version 2.1.0
 */
final class NullParityTrees
{
    /** Values of the integer columns and of the text column; every combination becomes a row. */
    public const INTS = [null, 1, 5, 9];
    public const STRINGS = [null, 'apple', 'banana', 'cherry', 'kiwi'];

    private int $annotation = 0;

    /** Árvore de linha: property() combinadas por and/or/not/nor. */
    public function tree(int $depth): ISpecification
    {
        if ($depth === 0 || mt_rand(0, 9) < 3) {
            return $this->annotate($this->property(), 20);
        }

        $d = $depth - 1;
        $tree = match (mt_rand(0, 6)) {
            0, 1 => Spec::not($this->tree($d)),
            2 => $this->tree($d)->and($this->tree($d)),
            3 => $this->tree($d)->or($this->tree($d)),
            4 => Spec::nor($this->tree($d), $this->tree($d)),
            5 => Spec::allOf($this->tree($d), $this->tree($d), $this->tree($d)),
            default => Spec::anyOf($this->tree($d), $this->tree($d)),
        };

        return $this->annotate($tree, 15);
    }

    /** Uma property() sobre n, m ou s, com folha ou composição de valores dentro. */
    private function property(): ISpecification
    {
        $column = ['n', 'm', 's'][mt_rand(0, 2)];
        $leaf = fn(): ISpecification => $this->annotate($column === 's' ? $this->stringLeaf() : $this->intLeaf(), 15);
        $inner = match (mt_rand(0, 9)) {
            0 => Spec::not($leaf()),
            1 => Spec::allOf($leaf(), $leaf()),
            2 => Spec::anyOf($leaf(), $leaf()),
            default => $leaf(),
        };

        return Spec::property($column, $this->annotate($inner, 10));
    }

    private function intLeaf(): ISpecification
    {
        $v = fn(): int => [0, 1, 5, 9, 10][mt_rand(0, 4)];

        return match (mt_rand(0, 13)) {
            0 => Spec::greaterThan($v()),
            1 => Spec::greaterThanOrEqualTo($v()),
            2 => Spec::lessThan($v()),
            3 => Spec::lessThanOrEqualTo($v()),
            4, 5 => Spec::equalTo($v()),
            6 => Spec::equalTo(null),
            7 => Spec::notEqual($v()),
            8 => Spec::in($v(), $v()),
            9 => Spec::in($v(), null),
            10 => Spec::allOf(Spec::greaterThanOrEqualTo(1), Spec::lessThanOrEqualTo($v())),
            11 => Spec::notIn($v(), $v()),
            12 => Spec::isNotNull(),
            default => Spec::isNull(),
        };
    }

    private function stringLeaf(): ISpecification
    {
        return match (mt_rand(0, 13)) {
            0 => Spec::startsWith(['a', 'b', 'ch', 'k'][mt_rand(0, 3)]),
            1 => Spec::endsWith(['a', 'e', 'i', 'y'][mt_rand(0, 3)]),
            2, 3 => Spec::contains(['an', 'e', 'i', 'pp'][mt_rand(0, 3)]),
            4, 5 => Spec::equalTo(['apple', 'kiwi', 'zzz'][mt_rand(0, 2)]),
            6 => Spec::equalTo(null),
            7 => Spec::notEqual('apple'),
            8 => Spec::in('apple', 'kiwi'),
            9 => Spec::in('banana', null),
            10 => Spec::greaterThan(['b', 'c'][mt_rand(0, 1)]),
            11 => Spec::lessThan('c'),
            12 => Spec::isNotNull(),
            default => Spec::isNull(),
        };
    }

    /** because() e/ou withCode() com probabilidade (por cento), valores únicos. */
    private function annotate(ISpecification $node, int $percent): ISpecification
    {
        if (mt_rand(1, 100) > $percent) {
            return $node;
        }
        $n = ++$this->annotation;

        return match (mt_rand(0, 2)) {
            0 => $node->because("M{$n}"),
            1 => $node->withCode("C{$n}"),
            default => $node->because("M{$n}")->withCode("C{$n}"),
        };
    }
}
