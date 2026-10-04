<?php
namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * RegexSpecification class.
 *
 * Implementação de uma especificação folha (Leaf) para validação de expressões regulares.
 *
 * Utiliza a função nativa `preg_match` do PHP para verificar se o candidato em formato de string atende ao padrão da expressão regular esperada.
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
class RegexSpecification extends AbstractSpecification
{
    /**
     * Inicializa a especificação com um padrão.
     *
     * @param string $pattern O padrão esperado para a validação.
     */


    public function __construct(private readonly string $pattern) {}

    /**
     * Retorna a expressão regular configurada.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
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
        return preg_match($this->pattern, $candidate) === 1;
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
