<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Entities;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use ReflectionClass;
use Stringable;
use UnitEnum;

/**
 * AbstractEntity - Base Abstract Class for Domain Entity Objects
 *
 * Provides identity-based equality logic and creation timestamp tracking.
 * Ensures comparison semantics are consistent and immutable, governed strictly
 * by identity rather than mutable attributes.
 *
 * Equality follows net.sourceforge.domian.entity.AbstractEntity (Domian, Copyright 2006-2010 the
 * original author or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md): two entities are equal
 * when they are the same object or carry the same identifier, but only within one type: an entity is
 * never equal to an entity of an unrelated class, even with the same identifier
 * (AbstractEntityTest.shouldNotAllowEqualsWhereNotEvenTheTypeIsCorrect: Customer(102) != Order(102)).
 * "Related" means one class is the other or a subclass of it, so an ORM proxy or a VipCustomer
 * still equals the Customer it stands for. hashCode() and __toString() complete the Java trio.
 *
 * Features:
 * - Immutable creation timestamp recording (timeOfCreation)
 * - Optimistic locking version tracking support (version)
 * - Identity-based equivalence evaluation (equals), guarded by type
 * - Identity hash consistent with equals (hashCode)
 * - Reflective string form listing the entity's properties (__toString)
 * - Domain object classification indicators (isEntity, isValueObject)
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractEntity implements IEntity, Stringable
{
    protected readonly DateTimeImmutable $timeOfCreation;

    /**
     * Version control counter for optimistic locking support in RDBMS.
     */
    protected ?int $version = null;

    /**
     * Initialize entity setting immutable creation timestamp.
     */
    public function __construct()
    {
        $this->timeOfCreation = new DateTimeImmutable();
    }

    /**
     * Get optimistic locking version of the entity.
     *
     * @return int|null
     */
    public function getVersion(): ?int
    {
        return $this->version;
    }

    /**
     * Set optimistic locking version.
     *
     * @param int|null $version
     */
    public function setVersion(?int $version): void
    {
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeOfCreation(): DateTimeImmutable
    {
        return $this->timeOfCreation;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool Always true for domain entities
     */
    public final function isEntity(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool Always false for domain entities
     */
    public final function isValueObject(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Same object, or same identifier within the same type family (one class is the other or a
     * subclass of it). Entities of unrelated classes are never equal, whatever their identifiers.
     *
     * @throws LogicException When either entity has no identifier yet (the Java original raises NPE)
     */
    public function equals(IEntity $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof AbstractEntity || !$this->isSameTypeFamily($other)) {
            return false;
        }

        $thisId = $this->getEntityId();
        $otherId = $other->getEntityId();

        if ($thisId === null || $otherId === null) {
            throw new LogicException("The entity does not have a valid ID to compare.");
        }

        // When identities are Value Objects providing their own equals() method
        if (is_object($thisId) && method_exists($thisId, 'equals')) {
            return $thisId->equals($otherId);
        }

        return $thisId === $otherId;
    }

    /**
     * Identity hash, consistent with equals(): two equal entities have the same hash
     * (Java hashCode() = getEntityId().hashCode()). Scalar and Stringable identifiers hash to their
     * string form; other objects to their object hash; arrays to a digest of their serialization.
     *
     * @return string
     * @throws LogicException When the entity has no identifier yet
     */
    public function hashCode(): string
    {
        $id = $this->getEntityId();

        if ($id === null) {
            throw new LogicException("The entity does not have a valid ID to hash.");
        }

        if (is_scalar($id) || $id instanceof Stringable) {
            return (string) $id;
        }

        if (is_object($id)) {
            return spl_object_hash($id);
        }

        return md5(serialize($id));
    }

    /**
     * Java toString() (ReflectionToStringBuilder): the short class name followed by every non-static
     * property of the hierarchy, as "Class[prop=value, ...]". Objects are rendered by class name
     * (dates in ISO 8601, enums by case name, Stringable objects by their string), arrays by size.
     *
     * @return string
     */
    public function toString(): string
    {
        $class = new ReflectionClass($this);
        $parts = [];
        $seen = [];

        while ($class !== false) {
            foreach ($class->getProperties() as $property) {
                $name = $property->getName();
                if ($property->isStatic() || isset($seen[$name])
                    || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $seen[$name] = true;
                $parts[] = $name . '=' . ($property->isInitialized($this)
                    ? self::renderPropertyValue($property->getValue($this))
                    : '<uninitialized>');
            }
            $class = $class->getParentClass();
        }

        return (new ReflectionClass($this))->getShortName() . '[' . implode(', ', $parts) . ']';
    }

    /**
     * @return string Same as toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * One class is the other, or a subclass of it.
     */
    private function isSameTypeFamily(AbstractEntity $other): bool
    {
        return $other instanceof static || $this instanceof $other;
    }

    private static function renderPropertyValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (is_object($value)) {
            return (new ReflectionClass($value))->getShortName();
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        return gettype($value);
    }
}
