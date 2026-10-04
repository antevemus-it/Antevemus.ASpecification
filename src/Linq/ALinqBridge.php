<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Linq;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use RuntimeException;

/**
 * ALinqBridge - Ponte de integração e interoperabilidade entre ASpecification e ALinqCollection
 *
 * Facade e adaptador fluente que conecta árvores de especificação do Antevemus.ASpecification
 * com pipelines de coleção LINQ do Antevemus.AlinqCollection.
 *
 * Funcionalidades:
 * - Conversão de iteráveis e repositórios em memória para instâncias de ALinqCollection
 * - Filtragem direta de coleções utilizando árvores de ISpecification via ALinqSpecificationVisitor
 * - Verificação de disponibilidade do pacote antevemus/alinq-collection em tempo de execução
 * - Encadeamento fluente com operações de ordenação, projeção e agrupamento
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Linq
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ALinqBridge
{
    /**
     * Verifica se a biblioteca Antevemus\ALinq está instalada e disponível no runtime.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Antevemus\ALinq\ALinqCollection::class);
    }

    /**
     * Converte um conjunto de itens ou array em uma ALinqCollection.
     *
     * @param iterable $items
     * @return object Retorna uma instância de \Antevemus\ALinq\ALinqCollection
     * @throws RuntimeException Se a biblioteca antevemus/alinq-collection não estiver disponível
     */
    public static function toCollection(iterable $items): object
    {
        self::ensureAvailable();

        $array = is_array($items) ? array_values($items) : iterator_to_array($items, false);
        return \Antevemus\ALinq\ALinqCollection::from($array);
    }

    /**
     * Filtra qualquer conjunto iterável aplicando uma especificação traduzida para predicado LINQ.
     *
     * @param iterable $items
     * @param ISpecification $specification
     * @return object Retorna uma instância de \Antevemus\ALinq\ALinqCollection filtrada
     */
    public static function filter(iterable $items, ISpecification $specification): object
    {
        $collection = self::toCollection($items);
        $predicate = ALinqSpecificationVisitor::createPredicate($specification);

        /** @var \Antevemus\ALinq\ALinqCollection $collection */
        return $collection->where($predicate);
    }

    /**
     * Extrai todas as entidades de um InMemoryRepository como uma ALinqCollection.
     *
     * @param InMemoryRepository $repository
     * @return object Retorna uma instância de \Antevemus\ALinq\ALinqCollection
     */
    public static function fromRepository(InMemoryRepository $repository): object
    {
        return self::toCollection($repository->getAll());
    }

    /**
     * Consulta um InMemoryRepository aplicando uma especificação e retornando o resultado como ALinqCollection.
     *
     * @param InMemoryRepository $repository
     * @param ISpecification $specification
     * @return object Retorna uma instância de \Antevemus\ALinq\ALinqCollection
     */
    public static function queryRepository(InMemoryRepository $repository, ISpecification $specification): object
    {
        $entities = $repository->findAllEntitiesSpecifiedBy($specification);
        return self::toCollection($entities);
    }

    /**
     * Garante que a classe ALinqCollection está disponível.
     */
    private static function ensureAvailable(): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                "A biblioteca 'antevemus/alinq-collection' é necessária para utilizar o ALinqBridge. " .
                "Instale-a via composer require antevemus/alinq-collection."
            );
        }
    }
}
