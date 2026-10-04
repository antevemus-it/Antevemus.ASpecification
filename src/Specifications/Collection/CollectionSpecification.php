<?php
namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * CollectionSpecification class.
 *
 * Implementação de uma especificação folha (Leaf) que valida itens de uma coleção.
 *
 * Itera sobre um candidato iterável (como arrays ou instâncias de `Traversable`) do PHP e garante que TODOS os seus itens satisfaçam a especificação embutida.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Collection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class CollectionSpecification extends AbstractSpecification
{
    /**
     * Inicializa a especificação de coleção.
     *
     * @param ISpecification<mixed> $itemSpecification A regra que será aplicada a cada item da coleção.
     */


    public function __construct(private readonly ISpecification $itemSpecification) {}
    /**
     * Verifica se o candidato fornecido satisfaz esta regra folha.
     *
     * @param mixed $candidate O valor ou objeto a ser validado.
     * @return bool Retorna true se a regra for atendida, false caso contrário.
     */



    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_iterable($candidate)) return false;
        
        foreach ($candidate as $item) {
            if (!$this->itemSpecification->isSatisfiedBy($item)) {
                return false;
            }
        }
        return true;
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
