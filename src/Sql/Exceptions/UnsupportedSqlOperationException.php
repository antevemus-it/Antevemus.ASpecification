<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Exceptions;

/**
 * UnsupportedSqlOperationException - Exceção para Operação Não Suportada pelo Dialeto
 *
 * Lançada quando determinado recurso (ex.: Expressões Regulares) não possui suporte
 * sintático no dialeto SQL configurado (ex.: Firebird 2.5 ou SQL Server padrão).
 *
 * Funcionalidades:
 * - Identificação do dialeto e da operação indisponível
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnsupportedSqlOperationException extends SqlVisitorException
{
    /**
     * @param string $operation Nome da operação solicitada
     * @param string $dialect Nome do dialeto em uso
     */
    public function __construct(string $operation, string $dialect)
    {
        parent::__construct(sprintf(
            'A operação "%s" não é suportada nativamente pelo dialeto SQL "%s".',
            $operation,
            $dialect
        ));
    }
}
