<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria\Exceptions;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * NonTranslatableCriteriaException - Exceção para Especificação Incompatível com TCriteria
 *
 * Lançada quando uma especificação de domínio não pode ser convertida para um filtro
 * ou expressão relacional do Adianti Framework (ex.: validações reflexivas arbitrárias em memória).
 *
 * Funcionalidades:
 * - Rastreamento da especificação incompatível
 * - Mensagem explicativa com o nome da classe
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Criteria\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NonTranslatableCriteriaException extends CriteriaBuilderException
{
    /**
     * @param ISpecification $specification A especificação não traduzível
     * @param string|null $reason Motivo opcional da impossibilidade
     */
    public function __construct(
        private readonly ISpecification $specification,
        ?string $reason = null
    ) {
        $className = get_class($specification);
        $message = "A especificação [{$className}] não pode ser convertida para um TCriteria/TFilter relacional.";
        if ($reason !== null) {
            $message .= " Motivo: {$reason}";
        }
        parent::__construct($message);
    }

    /**
     * Retorna a especificação que gerou o erro de tradução.
     *
     * @return ISpecification
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }
}
