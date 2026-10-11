<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * TreeAnalyzer - Collection of Requests and Three-Valued Evaluation of a Specification Tree
 *
 * Internal to the DataResolver.
 *
 * collect(): walks the tree with the candidate and returns every request its external data leaves
 * declare (deduplicated by canonical key) and, for each leaf, the neutral outcome of its position:
 * satisfied under an even number of negations (NOT, NOR), not satisfied under an odd number, unknown
 * below a node type this class does not know or when the same leaf sits at positions of both kinds.
 * Property restrictions hand their property value to the inner specification, as evaluate() does.
 *
 * isDecided(): evaluates the tree over the data resolved so far in three-valued logic and answers
 * whether `isSatisfied` of evaluate() is already fixed. Each node yields the set of outcomes it can
 * still have among satisfied (S), failed (F) and evaluation error (E). The known node types follow
 * their evaluate() exactly, including how an error propagates (an error on the left operand of an
 * OR aborts the disjunction; an AND with an error is an error):
 * - AND: E if an operand is E, S if both are S, F otherwise (both operands always evaluated);
 * - OR: the left operand when it is S or E, otherwise the right one;
 * - NOT: E stays E, S and F swap; NOR: through isSatisfiedBy() of its operands;
 * - property restriction: the base, the property access and the inner specification on the value;
 * - external data leaf: unknown (S, F or E) while its request is pending, skipped or failed (a
 *   failed fetch is unknown, so it never decides the verdict on its own, forward 026 D8), otherwise
 *   its real outcome;
 * - any other node: its real outcome, unless an external data leaf below it is not resolved, in
 *   which case it is unknown until everything below it is fetched (D7, nothing is guessed).
 * Node types are matched by exact class, as SpecificationSimplifier does: a subclass is unknown.
 *
 * @internal
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TreeAnalyzer
{
    private const S = 1;
    private const F = 2;
    private const E = 4;
    private const ALL = 7;

    private function __construct()
    {
    }

    /**
     * Requests of the tree and neutral outcome of each external data leaf.
     *
     * @return array{0: array<string, DataRequest>, 1: array<int, bool|null>}
     */
    public static function collect(ISpecification $specification, mixed $candidate): array
    {
        $requests = [];
        $neutral = [];
        self::walk($specification, $candidate, true, static function (
            ExternalDataSpecification $leaf,
            mixed $candidate,
            ?bool $polarity
        ) use (&$requests, &$neutral): void {
            $id = spl_object_id($leaf);
            if (!array_key_exists($id, $neutral)) {
                $neutral[$id] = $polarity;
            } elseif ($neutral[$id] !== $polarity) {
                $neutral[$id] = null;
            }
            try {
                $request = $leaf->createRequest($candidate);
                $requests[$request->getKey()] ??= $request;
            } catch (Throwable) {
                // The leaf fails (or errors) without fetching when it is evaluated.
            }
        });

        return [$requests, $neutral];
    }

    /**
     * True when isSatisfied of evaluate() can no longer change, whatever the pending data turns out
     * to be. Runs inside the resolution scope of the data.
     */
    public static function isDecided(ISpecification $specification, mixed $candidate, ResolvedData $data): bool
    {
        $outcomes = $data->run(static fn(): int => self::outcomes($specification, $candidate, false, $data));

        return ($outcomes & self::S) === 0 || $outcomes === self::S;
    }

    /**
     * Set of outcomes a node can still have, as evaluate() (or isSatisfiedBy() when $boolMode).
     */
    private static function outcomes(ISpecification $node, mixed $candidate, bool $boolMode, ResolvedData $data): int
    {
        switch ($node::class) {
            case ExternalDataSpecification::class:
                /** @var ExternalDataSpecification $node */
                try {
                    $request = $node->createRequest($candidate);
                } catch (Throwable) {
                    return self::real($node, $candidate, $boolMode);
                }

                return $data->isSettled($request->getKey()) ? self::real($node, $candidate, $boolMode) : self::ALL;

            case AndSpecification::class:
                /** @var AndSpecification $node */
                if ($boolMode && $candidate === null) {
                    return self::F;
                }
                $left = self::outcomes($node->getLeftSide(), $candidate, $boolMode, $data);
                $right = self::outcomes($node->getRightSide(), $candidate, $boolMode, $data);

                return $boolMode
                    ? self::combine($left, $right, static fn(int $l, int $r): int => $l === self::S ? $r : $l)
                    : self::combine($left, $right, static fn(int $l, int $r): int => match (true) {
                        $l === self::E || $r === self::E => self::E,
                        $l === self::S && $r === self::S => self::S,
                        default => self::F,
                    });

            case OrSpecification::class:
                /** @var OrSpecification $node */
                if ($boolMode && $candidate === null) {
                    return self::F;
                }
                $left = self::outcomes($node->getLeftSide(), $candidate, $boolMode, $data);
                $right = self::outcomes($node->getRightSide(), $candidate, $boolMode, $data);

                return self::combine($left, $right, static fn(int $l, int $r): int => $l === self::F ? $r : $l);

            case NotSpecification::class:
                /** @var NotSpecification $node */
                if ($candidate === null) {
                    return self::F;
                }

                return self::negate(self::outcomes($node->getSpecification(), $candidate, $boolMode, $data));

            case JointDenialSpecification::class:
                /** @var JointDenialSpecification $node */
                if ($candidate === null) {
                    return self::F;
                }
                // evaluate() is the default one: isSatisfiedBy() is !left && !right, short-circuited.
                $left = self::outcomes($node->getLeftSide(), $candidate, true, $data);
                $right = self::outcomes($node->getRightSide(), $candidate, true, $data);

                return self::combine($left, $right, static fn(int $l, int $r): int => match (true) {
                    $l === self::E => self::E,
                    $l === self::S => self::F,
                    $r === self::E => self::E,
                    $r === self::S => self::F,
                    default => self::S,
                });

            case PropertySpecification::class:
                /** @var PropertySpecification $node */
                if ($candidate === null) {
                    return self::F;
                }
                if ($boolMode && !is_object($candidate)) {
                    return self::E; // isSatisfiedBy(?object): a TypeError
                }
                if (!$boolMode && !is_object($candidate) && !is_array($candidate)) {
                    return self::F;
                }
                $base = self::outcomes($node->getBaseSpecification(), $candidate, $boolMode, $data);
                $outcomes = $base & (self::F | self::E);
                if (($base & self::S) !== 0) {
                    try {
                        $value = self::propertyValue($candidate, $node->getPropertyName());
                    } catch (Throwable) {
                        return $outcomes | self::E;
                    }
                    $outcomes |= $value === null
                        ? self::F
                        : self::outcomes($node->getPropertySpecification(), $value, $boolMode, $data);
                }

                return $outcomes;

            default:
                return self::hasUnsettledExternal($node, $candidate, $data)
                    ? self::ALL
                    : self::real($node, $candidate, $boolMode);
        }
    }

    /**
     * Walks the tree as evaluate() hands candidates down, calling the visitor on every external data
     * leaf with its candidate and the polarity of its position (true: even number of negations,
     * false: odd, null: below a node type that is not known).
     *
     * @param Closure(ExternalDataSpecification, mixed, ?bool): void $visitor
     */
    private static function walk(ISpecification $node, mixed $candidate, ?bool $polarity, Closure $visitor): void
    {
        if ($node instanceof ExternalDataSpecification) {
            $visitor($node, $candidate, $polarity);
            return;
        }

        switch ($node::class) {
            case AndSpecification::class:
            case OrSpecification::class:
                /** @var AndSpecification|OrSpecification $node */
                self::walk($node->getLeftSide(), $candidate, $polarity, $visitor);
                self::walk($node->getRightSide(), $candidate, $polarity, $visitor);
                return;

            case NotSpecification::class:
                /** @var NotSpecification $node */
                self::walk($node->getSpecification(), $candidate, $polarity === null ? null : !$polarity, $visitor);
                return;

            case JointDenialSpecification::class:
                /** @var JointDenialSpecification $node */
                $flipped = $polarity === null ? null : !$polarity;
                self::walk($node->getLeftSide(), $candidate, $flipped, $visitor);
                self::walk($node->getRightSide(), $candidate, $flipped, $visitor);
                return;

            case PropertySpecification::class:
                /** @var PropertySpecification $node */
                self::walk($node->getBaseSpecification(), $candidate, $polarity, $visitor);
                if (!is_object($candidate) && !is_array($candidate)) {
                    return;
                }
                try {
                    $value = self::propertyValue($candidate, $node->getPropertyName());
                } catch (Throwable) {
                    return;
                }
                if ($value !== null) {
                    self::walk($node->getPropertySpecification(), $value, $polarity, $visitor);
                }
                return;

            default:
                // Unknown node: its operands are visited with the same candidate (best effort; a leaf the
                // walk cannot reach is fetched when the evaluation reaches it), position unknown.
                if ($node instanceof ICompositeSpecification) {
                    foreach ($node->getSpecifications() as $child) {
                        if ($child instanceof ISpecification && $child !== $node) {
                            self::walk($child, $candidate, null, $visitor);
                        }
                    }
                } elseif ($node instanceof RuleBoundSpecification) {
                    self::walk($node->getInnerSpecification(), $candidate, null, $visitor);
                }
        }
    }

    /**
     * True when an external data leaf below the node (as far as the walk reaches) is not resolved.
     */
    private static function hasUnsettledExternal(ISpecification $node, mixed $candidate, ResolvedData $data): bool
    {
        $unsettled = false;
        self::walk($node, $candidate, null, static function (
            ExternalDataSpecification $leaf,
            mixed $candidate
        ) use (&$unsettled, $data): void {
            if ($unsettled) {
                return;
            }
            try {
                $request = $leaf->createRequest($candidate);
            } catch (Throwable) {
                return;
            }
            $unsettled = !$data->isSettled($request->getKey());
        });

        return $unsettled;
    }

    /**
     * Real outcome of a node (its data resolved): evaluate() or isSatisfiedBy().
     */
    private static function real(ISpecification $node, mixed $candidate, bool $boolMode): int
    {
        try {
            if ($boolMode) {
                return $node->isSatisfiedBy($candidate) ? self::S : self::F;
            }
            $result = $node->evaluate($candidate);

            return $result->isSatisfied ? self::S : ($result->isError ? self::E : self::F);
        } catch (Throwable) {
            return self::E;
        }
    }

    /**
     * Property value as PropertySpecification reads it (missing property: exception).
     */
    private static function propertyValue(mixed $candidate, string $propertyName): mixed
    {
        if (!PropertyAccessor::hasProperty($candidate, $propertyName)) {
            throw new InvalidArgumentException(sprintf('Property "%s" not found.', $propertyName));
        }

        return PropertyAccessor::getValue($candidate, $propertyName);
    }

    private static function negate(int $outcomes): int
    {
        return ($outcomes & self::E)
            | (($outcomes & self::S) !== 0 ? self::F : 0)
            | (($outcomes & self::F) !== 0 ? self::S : 0);
    }

    /**
     * @param Closure(int, int): int $operator Outcome of the node for one outcome of each operand
     */
    private static function combine(int $left, int $right, Closure $operator): int
    {
        $result = 0;
        foreach ([self::S, self::F, self::E] as $l) {
            if (($left & $l) === 0) {
                continue;
            }
            foreach ([self::S, self::F, self::E] as $r) {
                if (($right & $r) !== 0) {
                    $result |= $operator($l, $r);
                }
            }
        }

        return $result;
    }
}
