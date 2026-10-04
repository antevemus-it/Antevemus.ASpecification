<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * FirebirdDialect - Dialeto Especializado para Firebird e InterBase (firebird, fbird, ibase)
 *
 * Provê suporte a identificadores delimitados ("coluna"), booleanos inteiros (1/0)
 * e busca case-insensitive com LOWER().
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FirebirdDialect extends AbstractSqlDialect
{
    /**
     * @param string $family Identificador específico ('firebird', 'fbird' ou 'ibase')
     */
    public function __construct(
        private readonly string $family = 'firebird'
    ) {
    }

    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return $this->family;
    }
}
