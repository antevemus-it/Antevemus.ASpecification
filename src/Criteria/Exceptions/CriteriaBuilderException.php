<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria\Exceptions;

use RuntimeException;

/**
 * CriteriaBuilderException - Exceção Base para Erros de Construção de TCriteria
 *
 * Lançada quando ocorrem falhas durante o mapeamento ou compilação de especificações
 * para objetos do motor de banco de dados do Adianti Framework.
 *
 * Funcionalidades:
 * - Identificação uniforme de falhas do módulo Criteria
 * - Herança de RuntimeException padrão do PHP
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Criteria\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class CriteriaBuilderException extends RuntimeException
{
}
