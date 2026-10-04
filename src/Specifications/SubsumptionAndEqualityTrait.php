<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;

/**
 * SubsumptionAndEqualityTrait - Trait para igualdade estrutural e axiomas de subsunção (RF-10)
 *
 * Fornece implementação estrutural de igualdade (`equals`) via reflexão de propriedades
 * e axiomas algébricos fundamentais de subsunção (`isGeneralizationOf`) e disjunção
 * (`isDisjointWith`) para especificações no domínio `src/Specifications/`.
 *
 * Funcionalidades:
 * - Igualdade estrutural profunda (`equals`) para especificações compostas e folhas
 * - Axioma de reflexividade ($A \supseteq A$) e universalidade ($AlwaysTrue \supseteq A$)
 * - Subsunção de conjunções ($S \supseteq (A \land B)$ se $S \supseteq A$ ou $S \supseteq B$)
 * - Subsunção de disjunções ($S \supseteq (A \lor B)$ se $S \supseteq A$ e $S \supseteq B$)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait SubsumptionAndEqualityTrait
{
    /**
     * Verifica igualdade estrutural entre esta especificação e outro objeto.
     *
     * @param mixed $other Objeto a ser comparado
     * @return bool
     */
    public function equals(mixed $other): bool
    {
        if ($this === $other) {
            return true;
        }
        if (!is_object($other) || static::class !== $other::class) {
            return false;
        }

        $refThis = new \ReflectionObject($this);
        $refOther = new \ReflectionObject($other);

        foreach ($refThis->getProperties() as $prop) {
            if (!$refOther->hasProperty($prop->getName())) {
                return false;
            }
            $valThis = $prop->getValue($this);
            $valOther = $refOther->getProperty($prop->getName())->getValue($other);

            if ($valThis instanceof ISpecification && $valOther instanceof ISpecification) {
                $subEqual = method_exists($valThis, "equals")
                    ? $valThis->equals($valOther)
                    : ($valThis == $valOther);
                if (!$subEqual) {
                    return false;
                }
            } elseif ($valThis !== $valOther) {
                return false;
            }
        }

        return true;
    }

    /**
     * Avalia os axiomas base de subsunção ($this \supseteq $otherSpecification).
     *
     * @param ISpecification<mixed> $otherSpecification
     * @return bool
     */
    protected function checkBaseGeneralization(ISpecification $otherSpecification): bool
    {
        // Axioma 1: Reflexividade e Igualdade Estrutural ($A \supseteq A$)
        if ($this === $otherSpecification || $this->equals($otherSpecification)) {
            return true;
        }

        // Axioma 2: Qualquer especificação generaliza AlwaysFalseSpecification (conjunto vazio)
        if ($otherSpecification instanceof AlwaysFalseSpecification) {
            return true;
        }

        // Axioma 3: AlwaysTrueSpecification / AllEntitiesSpecification com supertipo compatível
        if ($this instanceof AlwaysTrueSpecification || $this instanceof AllEntitiesSpecification) {
            $myType = $this->getType();
            $otherType = $otherSpecification->getType();
            if ($myType === "mixed" || $myType === "object" || $myType === $otherType) {
                return true;
            }
            if (
                (class_exists($myType) || interface_exists($myType)) &&
                (class_exists($otherType) || interface_exists($otherType)) &&
                is_a($otherType, $myType, true)
            ) {
                return true;
            }
        }

        // Axioma 4: Uma especificação $S$ generaliza uma conjunção $(A \land B)$ se $S \supseteq A$ ou $S \supseteq B$
        if ($otherSpecification instanceof AndSpecification && !($this instanceof AndSpecification)) {
            $left = $otherSpecification->getLeftSide();
            $right = $otherSpecification->getRightSide();
            if ($left !== null && $this->isGeneralizationOf($left)) {
                return true;
            }
            if ($right !== null && $this->isGeneralizationOf($right)) {
                return true;
            }
        }

        // Axioma 5: Uma especificação $S$ generaliza uma disjunção $(A \lor B)$ se $S \supseteq A$ E $S \supseteq B$
        if ($otherSpecification instanceof OrSpecification) {
            $left = $otherSpecification->getLeftSide();
            $right = $otherSpecification->getRightSide();
            if ($left !== null && $right !== null) {
                return $this->isGeneralizationOf($left) && $this->isGeneralizationOf($right);
            }
        }

        return false;
    }

    /**
     * Avalia os axiomas base de disjunção ($this \cap $otherSpecification = \emptyset$).
     *
     * @param ISpecification<mixed> $otherSpecification
     * @return bool
     */
    protected function checkBaseDisjointness(ISpecification $otherSpecification): bool
    {
        if ($this instanceof AlwaysFalseSpecification || $otherSpecification instanceof AlwaysFalseSpecification) {
            return true;
        }

        if ($otherSpecification instanceof NotSpecification) {
            $negated = $otherSpecification->getLeftSide();
            if ($negated !== null && $negated->isGeneralizationOf($this)) {
                return true;
            }
        }

        if ($otherSpecification instanceof AndSpecification) {
            $left = $otherSpecification->getLeftSide();
            $right = $otherSpecification->getRightSide();
            if (($left !== null && $this->isDisjointWith($left)) || ($right !== null && $this->isDisjointWith($right))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Combina uma nova restrição de propriedade via conjunção lógica (AND).
     *
     * @param string $accessibleObjectName Nome da propriedade acessível
     * @param ISpecification<mixed> $accessibleObjectSpecification Especificação da propriedade
     * @return \Antevemus\ASpecification\Contracts\ICompositeSpecification<mixed>
     */
    public function andWhere(
        string $accessibleObjectName,
        ISpecification $accessibleObjectSpecification
    ): \Antevemus\ASpecification\Contracts\ICompositeSpecification {
        return new AndSpecification(
            $this,
            new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification)
        );
    }

    /**
     * Combina uma nova restrição de propriedade via disjunção lógica (OR).
     *
     * @param string $accessibleObjectName Nome da propriedade acessível
     * @param ISpecification<mixed> $accessibleObjectSpecification Especificação da propriedade
     * @return \Antevemus\ASpecification\Contracts\ICompositeSpecification<mixed>
     */
    public function orWhere(
        string $accessibleObjectName,
        ISpecification $accessibleObjectSpecification
    ): \Antevemus\ASpecification\Contracts\ICompositeSpecification {
        return new OrSpecification(
            $this,
            new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification)
        );
    }

    /**
     * Retorna a sub-especificação não satisfeita pelo candidato fornecido, ou null se satisfeita.
     *
     * @param object $candidate Objeto avaliado
     * @return \Antevemus\ASpecification\Contracts\ICompositeSpecification<mixed>|null
     */
    public function remainderUnsatisfiedBy(
        object $candidate
    ): ?\Antevemus\ASpecification\Contracts\ICompositeSpecification {
        if ($this->isSatisfiedBy($candidate)) {
            return null;
        }
        if ($this instanceof \Antevemus\ASpecification\Contracts\ICompositeSpecification) {
            return $this;
        }
        return new AndSpecification($this, $this);
    }
}
