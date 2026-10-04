<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

/**
 * MissingRuleHandlerException - Exceção Lançada na Ausência de Handler para Tipo de Regra
 *
 * Ocorre quando o catálogo solicita a compilação de uma regra cujo `tipo_regra` não possui
 * nenhum handler ou fábrica registrada no `RuleSpecificationRegistry`.
 *
 * Funcionalidades:
 * - Identificação explícita do código da regra e do tipo não suportado
 * - Sugestão de resolução no registro de especificações
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MissingRuleHandlerException extends RuleEngineException
{
    /**
     * @param string $tipoRegra Chave técnica do tipo de validação
     * @param string|null $codigo Código estável da regra no catálogo
     */
    public function __construct(
        public readonly string $tipoRegra,
        public readonly ?string $codigo = null
    ) {
        $msg = sprintf(
            'Nenhum handler de especificação registrado para o tipo de regra "%s"%s.',
            $tipoRegra,
            $codigo !== null ? " (código: {$codigo})" : ''
        );
        parent::__construct($msg);
    }
}
