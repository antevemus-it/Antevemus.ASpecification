<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use DateTimeInterface;
use InvalidArgumentException;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * DefaultValueSpecification - Leaf specification validating that the candidate matches the default value of its type.
 *
 * Port of the Domian `DefaultValueSpecification<T>`: null, false, 0 and the blank string are
 * default values. Untyped (the default, `defaultValue()`), it accepts the default value of any
 * type; typed (`blankString()` = 'string', `defaultNumber()` = 'number', `defaultValueOfType(T)`),
 * it accepts the default value of that type ONLY: a blank string for 'string', zero for 'number',
 * false for 'bool', [] for 'array'. Since 2.2.0 (BUG-20261013-JPQT) a typed instance refuses null:
 * "is a text and it is blank", "is a number and it is zero", as default(T) of a non-nullable type
 * in C# (only the untyped defaultValue() accepts null, as default(T?) does). Up to 2.1.0 a typed
 * instance accepted null, as Domian's does, and the two typed instances it declared disjoint shared
 * that candidate. Two differently typed instances are disjoint: a blank string is never zero, and
 * null is neither.
 *
 * Features:
 * - Empty string and whitespace detection
 * - Zero and boolean false matching
 * - Optional type restriction ('string', 'number'/'int'/'float', 'bool', 'array')
 * - Explicit guard rejecting DateTimeInterface default evaluation
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DefaultValueSpecification extends AbstractSpecification implements ILeafSpecification, ISerializableSpecification
{
    /**
     * @param string|null $type Restricting type ('string', 'number', 'int', 'float', 'bool', 'array') or null for any
     */
    public function __construct(
        private readonly ?string $type = null
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            // null is the default value of some type, not a value of a given type (2.2.0)
            return $this->normalizedType() === null;
        }
        if ($candidate instanceof DateTimeInterface) {
            throw new InvalidArgumentException("Cannot check for default value of a DateTime object as it is a timestamp");
        }

        return match ($this->normalizedType()) {
            'string' => is_string($candidate) && trim($candidate) === '',
            'number' => (is_int($candidate) || is_float($candidate)) && $candidate == 0,
            'bool' => $candidate === false,
            'array' => $candidate === [],
            default => $this->isAnyDefault($candidate),
        };
    }

    private function isAnyDefault(mixed $candidate): bool
    {
        if (is_string($candidate)) {
            return trim($candidate) === '';
        }
        if (is_numeric($candidate)) {
            return $candidate == 0;
        }
        if (is_bool($candidate)) {
            return $candidate === false;
        }

        return empty($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type ?? 'mixed';
    }

    /**
     * {@inheritdoc}
     *
     * Default values of two different types are disjoint (Domian: blankString ⟂ defaultNumber).
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if (SpecificationAlgebra::baseDisjoint($this, $otherSpecification)) {
            return true;
        }
        // Two typed defaults of different types share no candidate: each accepts values of its own
        // type only, and null none of them (2.2.0, BUG-20261013-JPQT). An untyped default shares the
        // default value of every type, so it is never disjoint with a typed one
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self) {
            $a = $this->normalizedType();
            $b = $other->normalizedType();
            return $a !== null && $b !== null && $a !== $b;
        }

        return false;
    }

    /**
     * Canonical name of the restricting type, or null when unrestricted.
     */
    private function normalizedType(): ?string
    {
        $type = $this->type === null ? null : strtolower($this->type);

        return match ($type) {
            null, 'mixed', '' => null,
            'string', 'str' => 'string',
            'number', 'numeric', 'int', 'integer', 'float', 'double' => 'number',
            'bool', 'boolean' => 'bool',
            'array' => 'array',
            default => $type,
        };
    }

    /**
     * {@inheritdoc}
     *
     * Serialized as `{"t": "defaultValue"}`, with `"type"` when the leaf is typed (2.4.0, forward 041).
     */
    public function toArray(): array
    {
        return $this->type === null ? [] : ['type' => $this->type];
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, [], ['type']);

        return new static($codec->optionalString($data, 'type'));
    }
}
