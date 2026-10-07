<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\AbstractCompositeSpecification;
use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Factory\ICollectionSpecificationFactory;
use Antevemus\ASpecification\Contracts\Factory\IComparisonSpecificationFactory;
use Antevemus\ASpecification\Contracts\Factory\IDateSpecificationFactory;
use Antevemus\ASpecification\Contracts\Factory\ILogicalSpecificationFactory;
use Antevemus\ASpecification\Contracts\Factory\ISpecialSpecificationFactory;
use Antevemus\ASpecification\Contracts\Factory\ISpecificationFactory;
use Antevemus\ASpecification\Contracts\Factory\ISpecificationWrapperFactory;
use Antevemus\ASpecification\Contracts\Factory\IStringSpecificationFactory;
use Antevemus\ASpecification\Contracts\Factory\ITypeSpecificationFactory;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\CollectionSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LooseEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\TypeCompatibility;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\String\DateStringSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\ContainsSpecification;
use Antevemus\ASpecification\Specifications\String\EndsWithSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\StartsWithSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Factory\Traits\CollectionSpecificationOperationsTrait;
use Antevemus\ASpecification\Factory\Traits\ComparisonSpecificationOperationsTrait;
use Antevemus\ASpecification\Factory\Traits\DateSpecificationOperationsTrait;
use Antevemus\ASpecification\Factory\Traits\LogicalSpecificationOperationsTrait;
use Antevemus\ASpecification\Factory\Traits\SpecialSpecificationOperationsTrait;
use Antevemus\ASpecification\Factory\Traits\SpecificationWrapperOperationsTrait;
use Antevemus\ASpecification\Factory\Traits\StringSpecificationOperationsTrait;
use Antevemus\ASpecification\Factory\Traits\TypeSpecificationOperationsTrait;
use DateTimeInterface;

/**
 * SpecificationFactory - Concrete and unified facade for specification creation
 *
 * Central entry point for fluent and idiomatic specification creation across all domains.
 * Aggregates and implements all 8 specification factory interfaces through composition
 * of specialized sub-factories and modular trait decomposition.
 *
 * Features:
 * - Typed segment accessors: type(), comparison(), logical(), special(), string(), date(), collection(), wrapper()
 * - Unified direct implementation of all 8 factory families via specialized traits
 * - Transparent contravariant/covariant resolution of idiomatic method overloads
 * - Immutable constructor with static factory create()
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationFactory implements
    ITypeSpecificationFactory,
    IComparisonSpecificationFactory,
    ILogicalSpecificationFactory,
    ISpecialSpecificationFactory,
    IStringSpecificationFactory,
    IDateSpecificationFactory,
    ICollectionSpecificationFactory,
    ISpecificationWrapperFactory
{
    use TypeSpecificationOperationsTrait;
    use ComparisonSpecificationOperationsTrait;
    use LogicalSpecificationOperationsTrait;
    use SpecialSpecificationOperationsTrait;
    use StringSpecificationOperationsTrait;
    use DateSpecificationOperationsTrait;
    use CollectionSpecificationOperationsTrait;
    use SpecificationWrapperOperationsTrait;

    private readonly ITypeSpecificationFactory $typeFactory;
    private readonly IComparisonSpecificationFactory $comparisonFactory;
    private readonly ILogicalSpecificationFactory $logicalFactory;
    private readonly ISpecialSpecificationFactory $specialFactory;
    private readonly IStringSpecificationFactory $stringFactory;
    private readonly IDateSpecificationFactory $dateFactory;
    private readonly ICollectionSpecificationFactory $collectionFactory;
    private readonly ISpecificationWrapperFactory $wrapperFactory;

    /**
     * Initializes the specification factory by instantiating specialized sub-factories.
     */
    public function __construct()
    {
        $this->typeFactory = new class extends AbstractTypeSpecificationFactory {
            /** {@inheritdoc} */
            public function createSpecificationFor(string $type): ICompositeSpecification
            {
                $this->validateType($type);
                return new class($type) extends AbstractCompositeSpecification {
                    /** {@inheritdoc} */
                    protected function isSpecifyingAllInstancesOfItsType(): bool
                    {
                        return false;
                    }
                };
            }
        };

        $this->comparisonFactory = new class extends AbstractComparisonSpecificationFactory {
            /** {@inheritdoc} */
            public function equalTo(mixed $value): ISpecification
            {
                return new EqualSpecification($value);
            }

            /** {@inheritdoc} */
            public function looselyEqualTo(mixed $value): ISpecification
            {
                return new LooseEqualSpecification($value);
            }

            /** {@inheritdoc} */
            public function lessThan(mixed $value): ISpecification
            {
                return new LessThanSpecification($value);
            }

            /** {@inheritdoc} */
            public function lessThanOrEqualTo(mixed $value): ISpecification
            {
                return (new LessThanSpecification($value))->or(new EqualSpecification($value));
            }

            /** {@inheritdoc} */
            public function greaterThan(mixed $value): ISpecification
            {
                return new GreaterThanSpecification($value);
            }

            /** {@inheritdoc} */
            public function greaterThanOrEqualTo(mixed $value): ISpecification
            {
                return (new GreaterThanSpecification($value))->or(new EqualSpecification($value));
            }

            /** {@inheritdoc} */
            public function in(mixed ...$values): ISpecification
            {
                // A single array argument IS the set (PHP idiom): in([0, 2, 4]) ≡ in(0, 2, 4).
                // Without this, the array became equalTo([0, 2, 4]) and never matched a scalar.
                if (count($values) === 1 && is_array(reset($values))) {
                    $values = array_values(reset($values));
                }
                if (empty($values)) {
                    return new AlwaysFalseSpecification();
                }
                $spec = new EqualSpecification($values[0]);
                for ($i = 1, $len = count($values); $i < $len; $i++) {
                    $spec = $spec->or(new EqualSpecification($values[$i]));
                }
                return $spec;
            }
        };

        $this->logicalFactory = new class extends AbstractLogicalSpecificationFactory {
            /** {@inheritdoc} */
            public function allOf(ISpecification ...$specifications): ISpecification
            {
                if (empty($specifications)) {
                    return new AlwaysTrueSpecification();
                }
                $spec = $specifications[0];
                for ($i = 1, $len = count($specifications); $i < $len; $i++) {
                    $spec = $spec->and($specifications[$i]);
                }
                return $spec;
            }

            /** {@inheritdoc} */
            public function anyOf(ISpecification ...$specifications): ISpecification
            {
                if (empty($specifications)) {
                    return new AlwaysFalseSpecification();
                }
                $spec = $specifications[0];
                for ($i = 1, $len = count($specifications); $i < $len; $i++) {
                    $spec = $spec->or($specifications[$i]);
                }
                return $spec;
            }

            /** {@inheritdoc} */
            public function not(ISpecification $specification): ISpecification
            {
                return new NotSpecification($specification);
            }
        };

        $this->specialFactory = new class extends AbstractSpecialSpecificationFactory {
            /** {@inheritdoc} */
            public function alwaysTrue(): ISpecification
            {
                return new AlwaysTrueSpecification();
            }

            /** {@inheritdoc} */
            public function alwaysFalse(): ISpecification
            {
                return new AlwaysFalseSpecification();
            }

            /** {@inheritdoc} */
            public function isNull(): ISpecification
            {
                return new class extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return $candidate === null;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string
                    {
                        return 'mixed';
                    }
                };
            }

            /** {@inheritdoc} */
            public function isNotNull(): ISpecification
            {
                return new NotNullSpecification();
            }

            /** {@inheritdoc} */
            public function isTrue(): ISpecification
            {
                return new EqualSpecification(true);
            }

            /** {@inheritdoc} */
            public function isFalse(): ISpecification
            {
                return new EqualSpecification(false);
            }
        };

        $this->stringFactory = new class extends AbstractStringSpecificationFactory {
            /** {@inheritdoc} */
            public function isBlank(): ISpecification
            {
                return new RegexSpecification('/^\s*$/');
            }

            /** {@inheritdoc} */
            public function equalIgnoringCase(string $value): ISpecification
            {
                return new EqualIgnoreCaseStringSpecification($value);
            }

            /** {@inheritdoc} */
            public function matchesRegex(string $pattern): ISpecification
            {
                $this->validateRegexPattern($pattern);
                return new RegexSpecification($pattern);
            }

            /** {@inheritdoc} */
            public function matchesWildcard(string $wildcardExpression): ISpecification
            {
                return new WildcardSpecification($wildcardExpression);
            }

            /** {@inheritdoc} */
            public function matchesWildcardIgnoringCase(string $wildcardExpression): ISpecification
            {
                return new WildcardExpressionMatcherIgnoreCaseStringSpecification($wildcardExpression);
            }

            /** {@inheritdoc} */
            public function contains(string $substring, bool $caseSensitive = true): ISpecification
            {
                return new ContainsSpecification($substring, $caseSensitive);
            }

            /** {@inheritdoc} */
            public function startsWith(string $prefix, bool $caseSensitive = true): ISpecification
            {
                return new StartsWithSpecification($prefix, $caseSensitive);
            }

            /** {@inheritdoc} */
            public function endsWith(string $suffix, bool $caseSensitive = true): ISpecification
            {
                return new EndsWithSpecification($suffix, $caseSensitive);
            }

            /** {@inheritdoc} */
            public function hasLength(ISpecification $lengthSpecification): ISpecification
            {
                return new class($lengthSpecification) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly ISpecification $lengthSpec) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        if (!is_string($candidate)) return false;
                        return $this->lengthSpec->isSatisfiedBy(strlen($candidate));
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return 'string'; }
                };
            }

            /** {@inheritdoc} */
            public function isValidDate(?string $format = null): ISpecification
            {
                return new DateStringSpecification($format ?? 'Y-m-d');
            }
        };

        $this->dateFactory = new class extends AbstractDateSpecificationFactory {
            /** {@inheritdoc} */
            public function before(DateTimeInterface $date): ISpecification
            {
                return new class($date) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly DateTimeInterface $target) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateBeforeSpecification', $this->target) && $candidate < $this->target;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function after(DateTimeInterface $date): ISpecification
            {
                return new class($date) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly DateTimeInterface $target) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateAfterSpecification', $this->target) && $candidate > $this->target;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function at(DateTimeInterface $date): ISpecification
            {
                return new class($date) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly DateTimeInterface $target) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateAtSpecification', $this->target) && $candidate == $this->target;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function beforeOrAt(DateTimeInterface $date): ISpecification
            {
                return new class($date) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly DateTimeInterface $target) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateBeforeOrAtSpecification', $this->target) && $candidate <= $this->target;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function afterOrAt(DateTimeInterface $date): ISpecification
            {
                return new class($date) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly DateTimeInterface $target) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateAfterOrAtSpecification', $this->target) && $candidate >= $this->target;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function between(DateTimeInterface $start, DateTimeInterface $end): ISpecification
            {
                return new class($start, $end) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(
                        private readonly DateTimeInterface $start,
                        private readonly DateTimeInterface $end
                    ) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateBetweenSpecification', $this->start) && $candidate >= $this->start && $candidate <= $this->end;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function isToday(): ISpecification
            {
                return new class extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        if (!TypeCompatibility::isDateCandidate($candidate, 'DateIsTodaySpecification')) return false;
                        $now = new \DateTimeImmutable();
                        return $candidate->format('Y-m-d') === $now->format('Y-m-d');
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function isPast(): ISpecification
            {
                return new class extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateIsPastSpecification', null) && $candidate < new \DateTimeImmutable();
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }

            /** {@inheritdoc} */
            public function isFuture(): ISpecification
            {
                return new class extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        return TypeCompatibility::isDateCandidate($candidate, 'DateIsFutureSpecification', null) && $candidate > new \DateTimeImmutable();
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return DateTimeInterface::class; }
                };
            }
        };

        $this->collectionFactory = new class extends AbstractCollectionSpecificationFactory {
            /** {@inheritdoc} */
            public function hasSize(ISpecification $sizeSpecification): ISpecification
            {
                return new class($sizeSpecification) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly ISpecification $sizeSpec) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        if (is_array($candidate) || $candidate instanceof \Countable) {
                            return $this->sizeSpec->isSatisfiedBy(count($candidate));
                        }
                        if ($candidate instanceof \Traversable) {
                            return $this->sizeSpec->isSatisfiedBy(iterator_count($candidate));
                        }
                        return false;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return 'iterable'; }
                };
            }

            /** {@inheritdoc} */
            public function isEmpty(): ISpecification
            {
                return $this->hasSize(new EqualSpecification(0));
            }

            /** {@inheritdoc} */
            public function include(ISpecification $countSpecification, ISpecification $elementSpecification): ISpecification
            {
                return new class($countSpecification, $elementSpecification) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(
                        private readonly ISpecification $countSpec,
                        private readonly ISpecification $elementSpec
                    ) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        if (!is_iterable($candidate)) return false;
                        $count = 0;
                        foreach ($candidate as $item) {
                            if ($this->elementSpec->isSatisfiedBy($item)) $count++;
                        }
                        return $this->countSpec->isSatisfiedBy($count);
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return 'iterable'; }
                };
            }

            /** {@inheritdoc} */
            public function includePercentageOf(ISpecification $percentageSpecification, ISpecification $elementSpecification): ISpecification
            {
                return new class($percentageSpecification, $elementSpecification) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(
                        private readonly ISpecification $percentageSpec,
                        private readonly ISpecification $elementSpec
                    ) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        if (!is_iterable($candidate)) return false;
                        $total = 0;
                        $matching = 0;
                        foreach ($candidate as $item) {
                            $total++;
                            if ($this->elementSpec->isSatisfiedBy($item)) $matching++;
                        }
                        if ($total === 0) return false;
                        $pct = ($matching / $total) * 100.0;
                        return $this->percentageSpec->isSatisfiedBy($pct);
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return 'iterable'; }
                };
            }

            /** {@inheritdoc} */
            public function all(ISpecification $elementSpecification): ISpecification
            {
                return new CollectionSpecification($elementSpecification);
            }

            /** {@inheritdoc} */
            public function any(ISpecification $elementSpecification): ISpecification
            {
                return new class($elementSpecification) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly ISpecification $elementSpec) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        if (!is_iterable($candidate)) return false;
                        foreach ($candidate as $item) {
                            if ($this->elementSpec->isSatisfiedBy($item)) return true;
                        }
                        return false;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return 'iterable'; }
                };
            }

            /** {@inheritdoc} */
            public function none(ISpecification $elementSpecification): ISpecification
            {
                return new class($elementSpecification) extends AbstractSpecification {
                    /** {@inheritdoc} */
                    public function __construct(private readonly ISpecification $elementSpec) {}
                    /** {@inheritdoc} */
                    public function isSatisfiedBy(mixed $candidate): bool
                    {
                        if (!is_iterable($candidate)) return false;
                        foreach ($candidate as $item) {
                            if ($this->elementSpec->isSatisfiedBy($item)) return false;
                        }
                        return true;
                    }
                    /** {@inheritdoc} */
                    public function getType(): string { return 'iterable'; }
                };
            }
        };

        $this->wrapperFactory = new class extends AbstractSpecificationWrapperFactory {};
    }

    /**
     * Creates a new instance of the unified specification factory.
     *
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    // ==========================================
    // 1. Typed Segment Accessors
    // ==========================================

    /**
     * Gets the specialized sub-factory for type/class specifications.
     *
     * @return ITypeSpecificationFactory
     */
    public function type(): ITypeSpecificationFactory
    {
        return $this->typeFactory;
    }

    /**
     * Gets the specialized sub-factory for value comparison specifications.
     *
     * @return IComparisonSpecificationFactory
     */
    public function comparison(): IComparisonSpecificationFactory
    {
        return $this->comparisonFactory;
    }

    /**
     * Gets the specialized sub-factory for boolean/logical specifications.
     *
     * @return ILogicalSpecificationFactory
     */
    public function logical(): ILogicalSpecificationFactory
    {
        return $this->logicalFactory;
    }

    /**
     * Gets the specialized sub-factory for special specifications (tautologies, contradictions, nulls).
     *
     * @return ISpecialSpecificationFactory
     */
    public function special(): ISpecialSpecificationFactory
    {
        return $this->specialFactory;
    }

    /**
     * Gets the specialized sub-factory for string and regular expression specifications.
     *
     * @return IStringSpecificationFactory
     */
    public function string(): IStringSpecificationFactory
    {
        return $this->stringFactory;
    }

    /**
     * Gets the specialized sub-factory for date and time specifications.
     *
     * @return IDateSpecificationFactory
     */
    public function date(): IDateSpecificationFactory
    {
        return $this->dateFactory;
    }

    /**
     * Gets the specialized sub-factory for collection and iterable specifications.
     *
     * @return ICollectionSpecificationFactory
     */
    public function collection(): ICollectionSpecificationFactory
    {
        return $this->collectionFactory;
    }

    /**
     * Gets the specialized sub-factory for specification wrapping and adaptation.
     *
     * @return ISpecificationWrapperFactory
     */
    public function wrapper(): ISpecificationWrapperFactory
    {
        return $this->wrapperFactory;
    }

    // ==========================================
    // 2. Signature Overload Resolution
    // ==========================================

    private function wrapAsComposite(ISpecification $specification): ICompositeSpecification
    {
        if ($specification instanceof ICompositeSpecification) {
            return $specification;
        }

        return new class($specification) extends AbstractCompositeSpecification {
            /** {@inheritdoc} */
            public function __construct(private readonly ISpecification $inner)
            {
                parent::__construct($inner->getType());
            }

            /** {@inheritdoc} */
            public function isSatisfiedBy(?object $candidate): bool
            {
                return $this->inner->isSatisfiedBy($candidate);
            }

            /** {@inheritdoc} */
            protected function isSpecifyingAllInstancesOfItsType(): bool
            {
                return false;
            }
        };
    }

    /**
     * Polymorphically resolves type specification creation or specification wrapping.
     *
     * @param ISpecification|string $target Class/type name or specification to wrap
     * @return ICompositeSpecification
     */
    public function a(ISpecification|string $target): ICompositeSpecification
    {
        if (is_string($target)) {
            return $this->typeFactory->a($target);
        }
        return $this->wrapAsComposite($this->wrapperFactory->a($target));
    }

    /**
     * Polymorphically resolves type specification creation or specification wrapping (alias for a).
     *
     * @param ISpecification|string $target Class/type name or specification to wrap
     * @return ICompositeSpecification
     */
    public function an(ISpecification|string $target): ICompositeSpecification
    {
        if (is_string($target)) {
            return $this->typeFactory->an($target);
        }
        return $this->wrapAsComposite($this->wrapperFactory->an($target));
    }

    /**
     * Polymorphically resolves type verification or specification wrapping.
     *
     * @param ISpecification|string $target Class/type name or specification
     * @return ICompositeSpecification
     */
    public function isA(ISpecification|string $target): ICompositeSpecification
    {
        if (is_string($target)) {
            return $this->typeFactory->isA($target);
        }
        return $this->wrapAsComposite($this->wrapperFactory->isA($target));
    }

    /**
     * Polymorphically resolves type verification or specification wrapping (alias for isA).
     *
     * @param ISpecification|string $target Class/type name or specification
     * @return ICompositeSpecification
     */
    public function isAn(ISpecification|string $target): ICompositeSpecification
    {
        if (is_string($target)) {
            return $this->typeFactory->isAn($target);
        }
        return $this->wrapAsComposite($this->wrapperFactory->isAn($target));
    }

    /**
     * Creates a specification for all elements of a collection or type.
     *
     * @param ISpecification|string $target Type of instances or element specification
     * @return ICompositeSpecification
     */
    public function all(ISpecification|string $target): ICompositeSpecification
    {
        if (is_string($target)) {
            return $this->typeFactory->all($target);
        }
        return $this->wrapAsComposite($this->collectionFactory->all($target));
    }

    /**
     * Creates an equality specification or wraps an existing specification.
     *
     * @param mixed $value Value to compare or ISpecification to wrap
     * @return ISpecification
     */
    public function is(mixed $value): ISpecification
    {
        if ($value instanceof ISpecification) {
            return $this->wrapperFactory->is($value);
        }
        return $this->comparisonFactory->is($value);
    }

    /**
     * Creates a temporal before boundary or numeric less-than specification.
     *
     * @param mixed $value DateTimeInterface or numeric/comparable value
     * @return ISpecification
     */
    public function before(mixed $value): ISpecification
    {
        if ($value instanceof DateTimeInterface) {
            return $this->dateFactory->before($value);
        }
        return $this->comparisonFactory->before($value);
    }

    /**
     * Alias for before().
     *
     * @param mixed $value DateTimeInterface or numeric/comparable value
     * @return ISpecification
     */
    public function isBefore(mixed $value): ISpecification
    {
        return $this->before($value);
    }

    /**
     * Creates a temporal after boundary or numeric greater-than specification.
     *
     * @param mixed $value DateTimeInterface or numeric/comparable value
     * @return ISpecification
     */
    public function after(mixed $value): ISpecification
    {
        if ($value instanceof DateTimeInterface) {
            return $this->dateFactory->after($value);
        }
        return $this->comparisonFactory->after($value);
    }

    /**
     * Alias for after().
     *
     * @param mixed $value DateTimeInterface or numeric/comparable value
     * @return ISpecification
     */
    public function isAfter(mixed $value): ISpecification
    {
        return $this->after($value);
    }

}
