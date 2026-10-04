<?php
namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * NotNullSpecification class.
 *
 * Implementação de uma especificação folha (Leaf) que valida se o candidato não é nulo.
 *
 * Verifica diretamente se o valor ou objeto fornecido é diferente de `null`.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NotNullSpecification extends AbstractSpecification
{
    /**
     * Verifica se o candidato fornecido satisfaz esta regra folha.
     *
     * @param mixed $candidate O valor ou objeto a ser validado.
     * @return bool Retorna true se a regra for atendida, false caso contrário.
     */


    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate !== null;
    }
    /**
     * Retorna o tipo de objeto ou dado que esta especificação valida.
     *
     * @return class-string|string Retorna 'mixed' pois esta especificação folha aceita tipos variados.
     */


    
    public function getType(): string
    {
        return 'mixed';
    }

}
