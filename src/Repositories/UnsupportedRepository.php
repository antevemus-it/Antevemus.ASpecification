<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IFakeRepository;
use BadMethodCallException;

/**
 * UnsupportedRepository - Repositório dublê com operações não suportadas
 *
 * Implementação de repositório dublê (IFakeRepository) que sinaliza explicitamente
 * que qualquer operação de armazenamento, busca ou remoção não é suportada.
 * Utilizado para compor estruturas de teste, fallbacks e classificação semântica.
 *
 * Funcionalidades:
 * - Implementação estrita de IFakeRepository
 * - Lançamento de BadMethodCallException em tentativas de contagem, busca, inserção e deleção
 * - Sinalização determinística de operações não suportadas
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IFakeRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnsupportedRepository extends AbstractRepository implements IFakeRepository
{
    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function put(IEntity $entity): void
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function putAll(array $collectionOfEntities): void
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function update(IEntity $entity): void
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não é suportada
     */
    public function remove(IEntity $entity): bool
    {
        throw new BadMethodCallException('Operação não suportada pelo repositório.');
    }
}
