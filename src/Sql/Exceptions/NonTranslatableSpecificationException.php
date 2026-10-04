<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Exceptions;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * NonTranslatableSpecificationException - Exceção para Especificação Não-Traduzível em SQL
 *
 * Ocorre quando a árvore de especificações contém nós puramente em memória (como Closures
 * arbitrárias ou regras de I/O) que não podem ser convertidos para cláusulas SQL nativas.
 *
 * Funcionalidades:
 * - Identificação exata da classe de especificação não suportada
 * - Detalhamento da causa da impossibilidade de tradução
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NonTranslatableSpecificationException extends SqlVisitorException
{
    /**
     * @param ISpecification $specification Instância da especificação problemática
     * @param string $reason Motivo descritivo da impossibilidade de tradução
     */
    public function __construct(
        public readonly ISpecification $specification,
        string $reason = ''
    ) {
        $className = get_class($specification);
        $msg = sprintf(
            'A especificação do tipo "%s" não pode ser traduzida para consulta SQL%s.',
            $className,
            $reason !== '' ? ": {$reason}" : ''
        );
        parent::__construct($msg);
    }
}
