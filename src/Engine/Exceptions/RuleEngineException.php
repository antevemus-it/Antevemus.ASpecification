<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

use RuntimeException;

/**
 * RuleEngineException - Exceção Base para Falhas na Dynamic Rule Engine
 *
 * Lançada quando ocorrem erros operacionais, de configuração ou de compilação
 * durante o ciclo de vida do motor dinâmico de especificações.
 *
 * Funcionalidades:
 * - Exceção base tipada para o subsistema de Engine
 * - Rastreabilidade com mensagem e causa original
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleEngineException extends RuntimeException
{
}
