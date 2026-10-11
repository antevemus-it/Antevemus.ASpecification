<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Closure;

/**
 * ResolutionScope - The Resolved Data Visible to External Data Leaves
 *
 * DataResolver::evaluate() (and ResolvedData::evaluate()/isSatisfiedBy()/run()) open a scope with the
 * resolved data, evaluate, and close it. An ExternalDataSpecification evaluated inside a scope reads
 * its data from the innermost scope; outside every scope it fetches the data itself, synchronously,
 * through the default registry (forward 026 D5). The candidate is never wrapped: every other leaf
 * keeps reading its properties as usual.
 *
 * Scopes form a stack (reentrant): a scope opened while another is open hides it until it closes,
 * and it is closed even when the callback throws. The stack is per process, not per Fiber.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ResolutionScope
{
    /** @var list<ResolvedData> */
    private static array $stack = [];

    private function __construct()
    {
    }

    /**
     * Runs the callback with the data as the innermost scope.
     *
     * @template R
     * @param ResolvedData $data Resolved data the leaves read
     * @param Closure(): R $callback Code to run inside the scope
     * @return R What the callback returns
     */
    public static function run(ResolvedData $data, Closure $callback): mixed
    {
        self::$stack[] = $data;
        $depth = count(self::$stack);
        try {
            return $callback();
        } finally {
            // Restores the stack as it was when this scope was opened.
            self::$stack = array_slice(self::$stack, 0, $depth - 1);
        }
    }

    /**
     * The innermost open scope, or null outside every scope.
     */
    public static function current(): ?ResolvedData
    {
        return self::$stack === [] ? null : self::$stack[count(self::$stack) - 1];
    }

    /**
     * Number of open scopes.
     */
    public static function depth(): int
    {
        return count(self::$stack);
    }
}
