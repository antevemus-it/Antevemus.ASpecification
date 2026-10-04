<?php
namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * EqualIgnoreCaseStringSpecification class.
 *
 * Implementação de uma especificação folha (Leaf) para igualdade de strings ignorando caixa (Case Insensitive).
 *
 * Utiliza a função nativa `strcasecmp` do PHP para garantir que as duas strings sejam idênticas independentemente de possuírem letras maiúsculas ou minúsculas.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EqualIgnoreCaseStringSpecification extends AbstractSpecification
{
    /**
     * Inicializa a especificação com o valor exato.
     *
     * @param string $value A string que deve ser utilizada como alvo da validação.
     */


    public function __construct(private readonly string $value) {}

    /**
     * Retorna o valor alvo de comparação ignorando caixa.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }
    /**
     * Verifica se o candidato fornecido satisfaz esta regra folha.
     *
     * @param mixed $candidate O valor ou objeto a ser validado.
     * @return bool Retorna true se a regra for atendida, false caso contrário.
     */



    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) return false;
        return strcasecmp($candidate, $this->value) === 0;
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
