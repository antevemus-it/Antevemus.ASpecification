<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Exceptions;

use RuntimeException;

/**
 * SqlVisitorException - Exceção Base para Falhas de Tradução SQL
 *
 * Lançada durante a travessia e compilação da árvore de especificações para dialetos SQL.
 *
 * Funcionalidades:
 * - Exceção base tipada para o subsistema de SQL
 * - Rastreabilidade com mensagem e causa original
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqlVisitorException extends RuntimeException
{
}
