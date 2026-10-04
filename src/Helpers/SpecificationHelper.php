<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\SpecificationPredicate;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use TypeError;

/**
 * SpecificationHelper - Utilitário Concreto de Segurança de Tipos, Identidade e Introspecção
 *
 * Implementa métodos auxiliares para operar sobre especificações com máxima segurança de tipos
 * em tempo de execução, geração automática de especificações únicas de identidade para entidades
 * DDD e utilitários de filtragem sobre coleções.
 *
 * Funcionalidades:
 * - Validação type-safe graciosa (typeSafeIsSatisfiedBy) sem risco de TypeError fatal
 * - Extração de identidade em 3 níveis desacoplados (IEntity -> getters públicos -> Reflection)
 * - Filtragem otimizada de iteráveis com suporte a type-safe e preservação de chaves
 * - Conversão facilitada para Closure e introspecção estrutural de especificações
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SpecificationHelper extends AbstractSpecificationHelper
{
    /**
     * {@inheritdoc}
     */
    public function typeSafeIsSatisfiedBy(ISpecification $specification, ?object $candidate): bool
    {
        if ($specification === null) {
            throw new InvalidArgumentException('A especificação fornecida não pode ser null.');
        }

        if ($candidate === null) {
            return false;
        }

        $expectedType = $specification->getType();

        // Se o tipo especificado for vazio, mixed ou object genérico, valida diretamente
        if ($expectedType !== '' && $expectedType !== 'mixed' && $expectedType !== 'object') {
            if (!is_a($candidate, $expectedType)) {
                return false;
            }
        }

        try {
            return $specification->isSatisfiedBy($candidate);
        } catch (TypeError) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createUniqueSpecificationFor(object $entity): ISpecification
    {
        if ($entity === null) {
            throw new InvalidArgumentException('A entidade para criação de especificação única não pode ser null.');
        }

        $entityClass = get_class($entity);

        // Nível 1: Contrato DDD IEntity::getEntityId()
        if ($entity instanceof IEntity) {
            $id = $entity->getEntityId();
            if ($id !== null && $id !== '') {
                return new UniqueEntitySpecification($entity, $entityClass);
            }
        }

        // Nível 2: Getters públicos padronizados (getEntityId() ou getId())
        if (method_exists($entity, 'getEntityId')) {
            $id = $entity->getEntityId();
            if ($id !== null && $id !== '') {
                return new UniqueEntitySpecification(is_object($id) ? $id : (string)$id, $entityClass);
            }
        }

        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();
            if ($id !== null && $id !== '') {
                return new UniqueEntitySpecification(is_object($id) ? $id : (string)$id, $entityClass);
            }
        }

        // Nível 3: Fallback via Reflection inspecionando propriedades de identidade
        $ref = new ReflectionClass($entity);
        $candidateProps = ['entityId', 'id', 'uuid', 'identifier'];

        while ($ref !== false) {
            foreach ($candidateProps as $propName) {
                if ($ref->hasProperty($propName)) {
                    $prop = $ref->getProperty($propName);
                    if ($prop->isInitialized($entity)) {
                        $val = $prop->getValue($entity);
                        if ($val !== null && $val !== '') {
                            return new UniqueEntitySpecification(is_object($val) ? $val : (string)$val, $entityClass);
                        }
                    }
                }
            }
            $ref = $ref->getParentClass();
        }

        throw new RuntimeException(
            sprintf(
                "A entidade da classe '%s' não possui propriedades de identidade identificáveis " .
                "(IEntity::getEntityId, getId(), ou propriedades \$id, \$entityId, \$uuid).",
                $entityClass
            )
        );
    }

    /**
     * Filtra uma coleção de elementos utilizando uma especificação.
     *
     * @template T
     * @param iterable<T> $candidates Coleção ou array a ser filtrado
     * @param ISpecification $specification Regra de filtro
     * @param bool $preserveKeys Se true, mantém as chaves originais do array/iterável
     * @param bool $typeSafe Se true, utiliza verificação type-safe ignorando candidatos incompatíveis
     * @return array<T> Elementos que satisfazem a especificação
     */
    public function filter(
        iterable $candidates,
        ISpecification $specification,
        bool $preserveKeys = false,
        bool $typeSafe = false
    ): array {
        $result = [];

        foreach ($candidates as $key => $candidate) {
            $satisfied = $typeSafe && is_object($candidate)
                ? $this->typeSafeIsSatisfiedBy($specification, $candidate)
                : $specification->isSatisfiedBy($candidate);

            if ($satisfied) {
                if ($preserveKeys) {
                    $result[$key] = $candidate;
                } else {
                    $result[] = $candidate;
                }
            }
        }

        return $result;
    }

    /**
     * Retorna uma Closure nativa pronta para uso em array_filter() a partir de uma especificação.
     *
     * @param ISpecification $specification Especificação alvo
     * @param bool $typeSafe Se true, ativa validação type-safe antes de testar
     * @return Closure(mixed): bool
     */
    public function toPredicate(ISpecification $specification, bool $typeSafe = false): Closure
    {
        if ($typeSafe) {
            return fn(mixed $candidate): bool => is_object($candidate)
                ? $this->typeSafeIsSatisfiedBy($specification, $candidate)
                : false;
        }

        return SpecificationPredicate::from($specification);
    }

    /**
     * Realiza a introspecção de uma especificação, retornando um mapa estruturado
     * contendo metadados sobre seu tipo, composição e propriedades.
     *
     * @param ISpecification $specification Especificação a ser inspecionada
     * @return array<string, mixed> Mapa com metadados estruturais
     */
    public function inspect(ISpecification $specification): array
    {
        return [
            'class' => get_class($specification),
            'type' => $specification->getType(),
            'isComposite' => $specification instanceof ICompositeSpecification,
        ];
    }
}
