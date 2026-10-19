<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * UniqueEntitySpecification - Entity identity uniqueness leaf specification.
 *
 * Concrete leaf specification satisfied exclusively by instances of the same class
 * sharing the identical entity identifier (getEntityId()).
 *
 * Features:
 * - Exact class and entity identifier validation
 * - Immediate disjointness against distinct uniqueness specifications
 * - Structural equality checking by identity and entity class
 *
 * Equality and algebra (2.1.0, BUG-20261013-7RQV):
 * - equals() is the structural one: same concrete class, same target class, the same identifier as
 *   the evaluation reads it (its text: `1` and `'1'` are the same identifier) and the same
 *   because()/withCode() annotations. The reference entity kept for getEntity() is not read by the
 *   evaluation and takes no part. IEntity::class is not a wildcard in equals(): `unique(1)` (any
 *   entity) and `unique(1, Customer::class)` accept different candidates. Up to 2.0.0 equals()
 *   ignored the annotations, accepted any subclass and treated IEntity::class as a wildcard, which
 *   made the equality non-transitive and let simplify(), and()/or() and the partitions merge
 *   specifications that answer differently.
 * - The wildcard lives in the algebra: `unique(id, A)` generalizes `unique(id, B)` when A is
 *   IEntity::class or B is A or a subclass of A; two of them are disjoint when the identifiers
 *   differ or no entity can be of both classes. Annotations do not change what is accepted, so the
 *   algebra ignores them. A subclass that redefines isSatisfiedBy() gets the shared axioms only.
 * - Against any other specification, disjoint only when that one is bounded by its type and the
 *   types are disjoint (forward 037: `unique(1, Customer)` and `all(Vip)` share Vip#1).
 *
 * @template T of IEntity
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Collection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UniqueEntitySpecification extends AbstractSpecification implements ILeafSpecification, ISerializableSpecification
{
    private readonly ?IEntity $entity;
    private readonly mixed $expectedId;
    private readonly string $entityClass;

    /** @var array<class-string, bool> Whether a class evaluates with this class's own isSatisfiedBy(). */
    private static array $ownPredicateByClass = [];

    /**
     * @param T|string|int $entityOrId Reference entity instance or scalar identifier
     * @param class-string<T>|null $entityClass Target entity class (optional when scalar ID provided)
     */
    public function __construct(
        IEntity|string|int $entityOrId,
        ?string $entityClass = null
    ) {
        if ($entityOrId instanceof IEntity) {
            $this->entity = $entityOrId;
            $this->expectedId = $entityOrId->getEntityId();
            $this->entityClass = $entityClass ?? get_class($entityOrId);
        } else {
            $this->entity = null;
            $this->expectedId = $entityOrId;
            $this->entityClass = $entityClass ?? IEntity::class;
        }
    }

    /**
     * Returns the reference entity, if available.
     *
     * @return T|null
     */
    public function getEntity(): ?IEntity
    {
        return $this->entity;
    }

    /**
     * Returns the expected unique entity identifier.
     */
    public function getExpectedId(): mixed
    {
        return $this->expectedId;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->entityClass;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(?object $candidate): bool
    {
        if (!$candidate instanceof IEntity) {
            return false;
        }

        if ($this->entityClass !== IEntity::class && !is_a($candidate, $this->entityClass)) {
            return false;
        }

        return (string)$candidate->getEntityId() === (string)$this->expectedId;
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('The specification cannot be null');
        }
        if (SpecificationAlgebra::baseGeneralizes($this, $otherSpecification)) {
            return true;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);

        return $other instanceof self
            && self::hasOwnPredicate($this)
            && self::hasOwnPredicate($other)
            && self::sameIdentifierText($this->expectedId, $other->expectedId)
            && ($this->entityClass === IEntity::class || is_a($other->entityClass, $this->entityClass, true));
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('The specification cannot be null');
        }

        if (SpecificationAlgebra::baseDisjoint($this, $otherSpecification)) {
            return true;
        }
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self) {
            if (!self::hasOwnPredicate($this) || !self::hasOwnPredicate($other)) {
                return false;
            }
            if (!self::isTextual($this->expectedId) || !self::isTextual($other->expectedId)) {
                return false;
            }
            // Different identifiers, or no entity can be of both classes (IEntity::class takes any).
            return (string) $this->expectedId !== (string) $other->expectedId
                || ($this->entityClass !== IEntity::class && $other->entityClass !== IEntity::class
                    && SpecificationAlgebra::areTypesDisjoint($this->entityClass, $other->entityClass));
        }

        // Any other specification: disjoint when no entity of this class can be one of its candidates,
        // which needs the other side to be bounded by its type and the two types to be disjoint (2.1.0;
        // up to 2.0.0 `unique(1, Customer) ⟂ all(Vip)` because Customer is not a Vip, although Vip#1
        // satisfies both, and anything with a type, ¬all(Order) or notEqualTo($d) included, was taken
        // as bounded by it).
        return self::hasOwnPredicate($this) && SpecificationAlgebra::provenTypesDisjoint($this->entityClass, $other);
    }

    /**
     * {@inheritdoc}
     *
     * The structural equals() reads this class's state as the evaluation does: the identifier by its
     * text (the evaluation compares `(string)` forms) and not the reference entity (never read). Only
     * for this very class: a subclass may evaluate otherwise, so it gets the strict comparison of
     * every property unless it says otherwise.
     */
    protected function propertyValuesEqual(string $declaringClass, string $property, mixed $mine, mixed $theirs): bool
    {
        if ($declaringClass === self::class && static::class === self::class) {
            if ($property === 'entity') {
                return true;
            }
            if ($property === 'expectedId') {
                return self::sameIdentifierText($mine, $theirs);
            }
        }

        return parent::propertyValuesEqual($declaringClass, $property, $mine, $theirs);
    }

    /**
     * True when the evaluation cannot tell the two identifiers apart: both have a text form and the
     * texts are equal; any other identifier only matches itself.
     */
    private static function sameIdentifierText(mixed $a, mixed $b): bool
    {
        if (self::isTextual($a) && self::isTextual($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }

    private static function isTextual(mixed $value): bool
    {
        return $value === null || is_scalar($value) || $value instanceof \Stringable;
    }

    /**
     * True when the specification evaluates with this class's own isSatisfiedBy() (the algebra of
     * identifiers and classes holds only for it).
     */
    private static function hasOwnPredicate(self $specification): bool
    {
        return self::$ownPredicateByClass[$specification::class]
            ??= (new \ReflectionMethod($specification, 'isSatisfiedBy'))->getDeclaringClass()->getName() === self::class;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('UniqueEntitySpecification(%s#%s)', $this->entityClass, (string)$this->expectedId);
    }

    /**
     * {@inheritdoc}
     *
     * Serialized as `{"t": "entity", "id": <identifier>}`, with `"type"` when the entity class is not IEntity
     * (2.4.0, forward 041). A leaf built from an entity instance is written by its identifier and class,
     * which equals() already treats as the same specification.
     */
    public function toArray(): array
    {
        $data = ['id' => $this->expectedId];
        if ($this->entityClass !== IEntity::class) {
            $data['type'] = $this->entityClass;
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['id'], ['type']);

        return new static($codec->value($data, 'id'), $codec->optionalString($data, 'type'));
    }
}
