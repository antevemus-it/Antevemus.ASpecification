<?php

namespace Antevemus\ASpecification;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;

/**
 * AbstractCompositeSpecification class.
 *
 * Classe abstrata base para especificações compostas.
 *
 * Esta classe define a estrutura de dados das especificações compostas, consistindo de:
 * - O tipo desta especificação (parametrizado como T)
 * - Especificações encapsuladas (podem ser LeafSpecification e/ou CompositeSpecification)
 * - A relação lógica entre todas as especificações encapsuladas (conjunção/disjunção)
 *
 * Especificações compostas podem conter tanto especificações folha (leaf) quanto outras
 * especificações compostas, formando uma árvore de especificações que pode ser avaliada
 * recursivamente.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Core
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractCompositeSpecification extends AbstractSpecification implements ICompositeSpecification
{
    /**
     * O tipo desta especificação.
     *
     * @var string Nome completo da classe/interface
     */
    protected string $type;

    /**
     * Conjunto de especificações encapsuladas.
     *
     * Usa SplObjectStorage internamente para garantir unicidade (equivalente a HashSet do Java).
     * Previne duplicatas automaticamente com performance O(1).
     *
     * @var \SplObjectStorage Conjunto de ISpecification (pode conter leaf e composite specs)
     */
    protected \SplObjectStorage $specifications;

    /**
     * Indica se esta especificação foi finalizada (imutável).
     *
     * @var bool
     */
    protected bool $finalized = false;

    /**
     * Construtor.
     *
     * @param string $type Nome completo da classe ou interface do tipo T
     */
    public function __construct(string $type)
    {
        $this->type = $type;
        $this->specifications = new \SplObjectStorage(); // Inicializa como Set
    }

    /**
     * Finaliza a criação desta especificação composta, tornando-a imutável.
     *
     * Deve ser invocado quando a construção desta especificação composta
     * estiver completa. Após chamar este método, a especificação não pode
     * mais ser modificada.
     *
     * @return static Esta especificação para encadeamento fluente
     */
    protected function finalizeCreation(): static
    {
        $this->finalized = true;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function accept(\Antevemus\ASpecification\Contracts\ISpecificationVisitor $visitor): mixed
    {
        return $visitor->visitComposite($this);
    }

    /**
     * {@inheritdoc}
     */
    public function where(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if ($this->specifications->count() > 0) {
            throw new \BadMethodCallException(
                'A cláusula "where" só pode ser invocada uma vez em expressões de especificação'
            );
        }

        return $this->and(new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function andWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if ($this->specifications->count() === 0) {
            throw new \BadMethodCallException(
                'A cláusula "where" deve ser invocada antes de "andWhere"/"orWhere" em expressões parametrizadas'
            );
        }

        return $this->and(new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function orWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if ($this->specifications->count() === 0) {
            throw new \BadMethodCallException(
                'A cláusula "where" deve ser invocada antes de "andWhere"/"orWhere" em expressões parametrizadas'
            );
        }

        return $this->or(new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function and(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('A especificação da propriedade não pode ser nula quando o nome da propriedade é fornecido.');
            }
            return $this->and(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Especificação não pode ser null');
        }

        // Verifica compatibilidade de tipos
        if (!$this->canCastAtLeastOneWay($this->getType(), $otherSpecification->getType())) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Não é possível criar conjunção de Specification<%s> e Specification<%s>',
                    $this->getType(),
                    $otherSpecification->getType()
                )
            );
        }

        // Se são a mesma especificação, retorna esta
        if ($otherSpecification === $this || $this->equals($otherSpecification)) {
            return $this;
        }

        // Verifica se são disjuntas
        if ($this->isDisjointWith($otherSpecification)) {
            throw new \InvalidArgumentException(
                'Não é possível criar conjunção de duas especificações disjuntas'
            );
        }

        // Cria e retorna nova especificação AND
        // AndSpecification já recebe as specs no construtor (left, right)
        return new AndSpecification($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function or(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('A especificação da propriedade não pode ser nula quando o nome da propriedade é fornecido.');
            }
            return $this->or(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Especificação não pode ser null');
        }

        // Se são a mesma especificação, retorna esta
        if ($otherSpecification === $this || $this->equals($otherSpecification)) {
            return $this;
        }

        // Cria e retorna nova especificação OR
        // OrSpecification já recebe as specs no construtor (left, right)
        return new OrSpecification($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function remainderUnsatisfiedBy(object $candidate): ?ICompositeSpecification
    {
        if ($candidate === null) {
            throw new \InvalidArgumentException('Objeto candidato não pode ser null');
        }

        // Verificar se tem disjunção - não suportado
        if ($this->hasDisjunction()) {
            throw new \InvalidArgumentException(
                'Satisfação parcial de especificações disjuntivas não é suportada'
            );
        }

        // Verificar tipo
        if (!$this->canCastFromTo(get_class($candidate), $this->getType())) {
            return $this;
        }

        // Se já satisfaz completamente, retorna null
        if ($this->isSatisfiedBy($candidate)) {
            return null;
        }

        // Criar especificação de resto (apenas specs não satisfeitas)
        $remainderSpec = new AndSpecification($this, $this);

        // Obter todas as especificações parametrizadas
        $parameterizedSpecs = $this->getAllParameterizedSpecifications();

        // Verificar cada propriedade do candidato contra as specs parametrizadas
        foreach ($parameterizedSpecs as $paramSpec) {
            if ($paramSpec instanceof PropertySpecification) {
                // Se a spec não é satisfeita, adiciona ao remainder
                if (!$paramSpec->isSatisfiedBy($candidate)) {
                    $remainderSpec = $remainderSpec->and($paramSpec);
                }
            }
        }

        return $remainderSpec;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(?object $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        // Verificar compatibilidade de tipo
        if ($this->type !== null && !$this->canCastFromTo(get_class($candidate), $this->type)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $specification): bool
    {
        if ($specification === null) {
            throw new \InvalidArgumentException('Especificação não pode ser null');
        }

        // Se são iguais, é uma generalização
        if ($this->equals($specification)) {
            return true;
        }

        // Lógica específica baseada no tipo de especificação
        // Implementação simplificada - pode ser sobrescrita pelas subclasses
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isSpecialCaseOf(ISpecification $specification): bool
    {
        if ($specification === null) {
            throw new \InvalidArgumentException('Especificação não pode ser null');
        }

        return $this->canCastAtLeastOneWay($this->getType(), $specification->getType())
            && $specification->isGeneralizationOf($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function getLeftSide(): ?ISpecification
    {
        // Implementação padrão - pode ser sobrescrita
        $specs = iterator_to_array($this->specifications);
        return count($specs) > 0 ? $specs[0] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        // Implementação padrão - pode ser sobrescrita
        $specs = iterator_to_array($this->specifications);
        return count($specs) > 1 ? $specs[1] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        // Converte SplObjectStorage para array para API pública
        return iterator_to_array($this->specifications);
    }

    /**
     * Verifica se esta especificação tem conjunção (AND).
     *
     * @return bool
     */
    protected function hasConjunction(): bool
    {
        return $this instanceof AndSpecification;
    }

    /**
     * Verifica se esta especificação tem disjunção (OR).
     *
     * @return bool
     */
    protected function hasDisjunction(): bool
    {
        if ($this instanceof OrSpecification) {
            return true;
        }

        foreach ($this->specifications as $spec) {
            if ($spec instanceof AbstractCompositeSpecification && $spec->hasDisjunction()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se tem pelo menos uma especificação parametrizada.
     *
     * @return bool
     */
    protected function hasParameterization(): bool
    {
        foreach ($this->getAllSpecifications() as $spec) {
            if ($spec instanceof PropertySpecification) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se é uma composição simples (sem parametrização).
     *
     * @return bool
     */
    protected function isSimpleComposition(): bool
    {
        return !$this->hasParameterization();
    }

    /**
     * Obtém todas as especificações (recursivamente).
     *
     * @return array<ISpecification>
     */
    protected function getAllSpecifications(): array
    {
        $allSpecs = [];

        foreach ($this->specifications as $spec) {
            $allSpecs[] = $spec;

            if ($spec instanceof AbstractCompositeSpecification) {
                $allSpecs = array_merge($allSpecs, $spec->getAllSpecifications());
            }
        }

        return $allSpecs;
    }

    /**
     * Obtém todas as especificações parametrizadas.
     *
     * @return array<PropertySpecification>
     */
    protected function getAllParameterizedSpecifications(): array
    {
        $paramSpecs = [];

        foreach ($this->getAllSpecifications() as $spec) {
            if ($spec instanceof PropertySpecification) {
                $paramSpecs[] = $spec;
            }
        }

        return $paramSpecs;
    }

    /**
     * Obtém mapa de especificações folha por nome de propriedade.
     *
     * @return array<string, array<ILeafSpecification>>
     */
    protected function getLeafSpecificationMap(): array
    {
        $map = [];

        foreach ($this->getAllParameterizedSpecifications() as $paramSpec) {
            $propertyName = $paramSpec->getPropertyName();

            if (!isset($map[$propertyName])) {
                $map[$propertyName] = [];
            }

            // Adicionar leaf specs da especificação parametrizada
            foreach ($paramSpec->getSpecifications() as $spec) {
                if ($spec instanceof ILeafSpecification && !($spec instanceof PropertySpecification)) {
                    $map[$propertyName][] = $spec;
                }
            }
        }

        return $map;
    }

    /**
     * Obtém lista de nomes de propriedades acessíveis.
     *
     * @return array<string>
     */
    protected function getAccessibleObjectNameList(): array
    {
        return array_keys($this->getLeafSpecificationMap());
    }

    /**
     * Verifica se especifica todas as instâncias de seu tipo.
     *
     * @return bool
     */
    abstract protected function isSpecifyingAllInstancesOfItsType(): bool;

    /**
     * Encapsula esta especificação em uma nova especificação composta.
     *
     * @param AbstractCompositeSpecification $newSpecification Nova especificação
     * @param ISpecification $specificationToBeWrapped Especificação a encapsular
     * @return ICompositeSpecification
     */
    protected function wrapWithNewSpecification(
        AbstractCompositeSpecification $newSpecification,
        ISpecification $specificationToBeWrapped
    ): ICompositeSpecification {
        // Usa attach() para adicionar ao SplObjectStorage (evita duplicatas automaticamente)
        $newSpecification->specifications->attach($specificationToBeWrapped);
        $newSpecification->specifications->attach($this);

        return $newSpecification;
    }

    /**
     * Verifica se dois tipos podem ser convertidos em pelo menos uma direção.
     *
     * @param string $type1 Primeiro tipo
     * @param string $type2 Segundo tipo
     * @return bool
     */
    protected function canCastAtLeastOneWay(string $type1, string $type2): bool
    {
        return $this->canCastFromTo($type1, $type2) || $this->canCastFromTo($type2, $type1);
    }

    /**
     * Verifica se pode fazer cast de um tipo para outro.
     *
     * @param string $fromType Tipo de origem
     * @param string $toType Tipo de destino
     * @return bool
     */
    protected function canCastFromTo(string $fromType, string $toType): bool
    {
        if ($fromType === $toType) {
            return true;
        }

        if (!class_exists($fromType) && !interface_exists($fromType)) {
            return false;
        }

        if (!class_exists($toType) && !interface_exists($toType)) {
            return false;
        }

        return is_subclass_of($fromType, $toType);
    }

    /**
     * Obtém valor de propriedade de um objeto via reflexão.
     *
     * Implementa abordagem híbrida (POINT 2 - Option C):
     * 1. Tenta getters públicos primeiro (getX, isX, hasX)
     * 2. Tenta propriedades públicas
     * 3. Usa ReflectionProperty::setAccessible() para private/protected
     *
     * @param object $object Objeto
     * @param string $propertyName Nome da propriedade
     * @return mixed Valor da propriedade
     * @throws \RuntimeException Se a propriedade não for encontrada
     */
    protected function getPropertyValue(object $object, string $propertyName): mixed
    {
        // PASSO 1: Tentar getters públicos (respeitando encapsulamento)
        $getter = 'get' . ucfirst($propertyName);
        if (method_exists($object, $getter) && is_callable([$object, $getter])) {
            return $object->$getter();
        }

        $isMethod = 'is' . ucfirst($propertyName);
        if (method_exists($object, $isMethod) && is_callable([$object, $isMethod])) {
            return $object->$isMethod();
        }

        $hasMethod = 'has' . ucfirst($propertyName);
        if (method_exists($object, $hasMethod) && is_callable([$object, $hasMethod])) {
            return $object->$hasMethod();
        }

        // PASSO 2: Tentar propriedade pública
        if (property_exists($object, $propertyName)) {
            $reflection = new \ReflectionProperty($object, $propertyName);
            if ($reflection->isPublic()) {
                return $object->$propertyName;
            }
        }

        // PASSO 3: Usar Reflection para acessar private/protected (equivalente ao setAccessible do Java)
        try {
            $reflection = new \ReflectionProperty($object, $propertyName);
            $reflection->setAccessible(true);
            return $reflection->getValue($object);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Propriedade "%s" não encontrada em %s. Tentou: getter público, propriedade pública, Reflection.',
                    $propertyName,
                    get_class($object)
                ),
                0,
                $e
            );
        }
    }

    /**
     * Verifica igualdade com outra especificação.
     *
     * @param mixed $other Outro objeto
     * @return bool
     */
    public function equals(mixed $other): bool
    {
        if (!($other instanceof AbstractCompositeSpecification)) {
            return false;
        }

        return $this->type === $other->type
            && $this->specifications === $other->specifications;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        $specNames = array_map(
            fn($spec) => $spec instanceof ISpecification ?
                (method_exists($spec, '__toString') ? (string)$spec : get_class($spec)) :
                'unknown',
            iterator_to_array($this->specifications)
        );

        return sprintf(
            '%s(%s)',
            $this instanceof AndSpecification ? 'AND' :
                ($this instanceof OrSpecification ? 'OR' : get_class($this)),
            implode(', ', $specNames)
        );
    }
}
