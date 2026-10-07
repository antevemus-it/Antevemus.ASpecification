<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

/**
 * ContainsSpecification - Leaf specification satisfied when the candidate string contains a literal substring.
 *
 * Created by `Spec::contains()`; evaluated in memory as the regex `/substring/` and
 * translated by the SQL visitor as `LIKE '%substring%'` (BUG-20261007-3E3F).
 *
 * @template T
 * @extends LiteralPatternSpecification<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class ContainsSpecification extends LiteralPatternSpecification
{
    /** {@inheritdoc} */
    public function toWildcardPattern(string $escapedLiteral, string $any): string
    {
        return $any . $escapedLiteral . $any;
    }

    /** {@inheritdoc} */
    protected function buildPattern(string $quotedLiteral): string
    {
        return '/' . $quotedLiteral . '/';
    }
}
