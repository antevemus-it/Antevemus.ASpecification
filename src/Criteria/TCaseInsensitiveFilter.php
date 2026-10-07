<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TFilter;

/**
 * TCaseInsensitiveFilter - TFilter whose case-insensitive flag cannot be switched off
 *
 * The Adianti TCriteria::dump() propagates its own caseInsensitive flag to every child
 * expression unconditionally (lib/adianti/database/TCriteria.php). A TCriteria is born
 * with the flag off, so a plain TFilter marked insensitive by the visitor was reset to
 * case-sensitive the moment the criteria was dumped, and the "ignore case" leaves of a
 * specification (equalIgnoreCase, wildcard...IgnoreCase) hit the database with no
 * UPPER() at all (BUG-20261007-M646).
 *
 * This subclass pins the flag: any attempt to set it, including the parent's reset,
 * keeps it on. Sibling leaves are untouched, so a sensitive wildcard next to an
 * insensitive one keeps its own semantics at any nesting depth.
 *
 * @version    1.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TCaseInsensitiveFilter extends TFilter
{
    /**
     * @param mixed $variable Column (or mapped expression) name
     * @param mixed $operator LIKE / NOT LIKE (the only operators Adianti applies UPPER() to)
     * @param mixed $value    Literal to compare
     * @param mixed $value2   Unused for LIKE; kept for signature parity with TFilter
     */
    public function __construct(mixed $variable, mixed $operator, mixed $value, mixed $value2 = null)
    {
        parent::__construct($variable, $operator, $value, $value2);
        parent::setCaseInsensitive(true);
    }

    /**
     * Ignores the requested value: this filter is case-insensitive by construction.
     *
     * TCriteria::dump() calls this with the criteria's own flag (false by default); honoring
     * that call is exactly what made the leaf silently case-sensitive in production.
     *
     * @param bool $value Ignored
     */
    public function setCaseInsensitive(bool $value): void
    {
        parent::setCaseInsensitive(true);
    }
}
