<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use BadMethodCallException;

/**
 * NotImplementedRepository - Repositório dublê com operações não implementadas
 *
 * Implementação de repositório dublê (IFakeRepository) que sinaliza explicitamente
 * que qualquer operação ainda não foi implementada para o repositório em questão.
 *
 * Funcionalidades:
 * - Extensão direta de UnsupportedRepository
 * - Lançamento determinístico de BadMethodCallException indicando pendência de implementação
 *
 * @template T of IEntity
 * @extends UnsupportedRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NotImplementedRepository extends UnsupportedRepository
{
    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function put(IEntity $entity): void
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function putAll(array $collectionOfEntities): void
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function update(IEntity $entity): void
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Sempre lançado informando que a operação não foi implementada
     */
    public function remove(IEntity $entity): bool
    {
        throw new BadMethodCallException('Operação não implementada pelo repositório.');
    }
}
