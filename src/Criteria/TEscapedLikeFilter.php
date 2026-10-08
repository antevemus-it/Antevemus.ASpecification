<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TFilter;

/**
 * TEscapedLikeFilter - TFilter for a LIKE / NOT LIKE whose value needs an ESCAPE clause
 *
 * The Adianti TFilter::dump() renders `variable operator value` and has no slot for the
 * `ESCAPE` clause that must follow a LIKE operand containing escaped `%` or `_`. Passing the
 * clause inside the value is impossible (the value is quoted or bound as a parameter) and the
 * `NOESC:` passthrough is a raw-SQL hole the visitor refuses (BUG-20261007-KJ36). This subclass
 * appends the clause after the parent's rendering, in plain and prepared mode alike, so the
 * database reads `col LIKE '%50!%%' ESCAPE '!'` (BUG-20261007-3TVR, BUG-20261007-ZY6E).
 *
 * When built case-insensitive it also pins the flag, like TCaseInsensitiveFilter, because
 * TCriteria::dump() resets every child's flag to its own (BUG-20261007-M646); the UPPER()
 * wrapping done by the parent stays inside the clause: `UPPER(col) LIKE UPPER('%a!%%') ESCAPE '!'`.
 *
 * @internal Built by CriteriaSpecificationVisitor; not meant to be constructed by consumers.
 * @version    1.4.1
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TEscapedLikeFilter extends TFilter
{
    /**
     * @param mixed $variable Column
     * @param mixed $operator 'LIKE' or 'NOT LIKE'
     * @param mixed $value Pattern with the wildcards already escaped
     * @param string $escape Escape character declared in the ESCAPE clause
     * @param bool $pinCaseInsensitive Whether the filter is case-insensitive and must stay so
     */
    public function __construct(
        mixed $variable,
        mixed $operator,
        mixed $value,
        private readonly string $escape,
        private readonly bool $pinCaseInsensitive = false
    ) {
        parent::__construct($variable, $operator, $value);
        if ($pinCaseInsensitive) {
            parent::setCaseInsensitive(true);
        }
    }

    /**
     * Keeps the flag on when the filter was built case-insensitive (the TCriteria reset is ignored);
     * otherwise behaves like a plain TFilter.
     *
     * @param bool $value
     */
    public function setCaseInsensitive(bool $value): void
    {
        parent::setCaseInsensitive($this->pinCaseInsensitive ? true : $value);
    }

    /**
     * Parent rendering followed by the ESCAPE clause.
     *
     * @param bool $prepared
     * @return string
     */
    public function dump($prepared = false): string
    {
        return parent::dump($prepared) . " ESCAPE '" . $this->escape . "'";
    }
}
