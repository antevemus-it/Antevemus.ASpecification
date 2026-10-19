<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Serialization;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DocumentLeafSpecification;
use Antevemus\ASpecification\Engine\DocumentSetSpecification;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\FeatureFlags\FeatureEnabledSpecification;
use Antevemus\ASpecification\Repositories\Pdo\InQuerySpecification;
use Antevemus\ASpecification\Resolution\ExternalDataSpecification;
use Antevemus\ASpecification\Serialization\Exceptions\NonSerializableSpecificationException;
use Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\CollectionSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\IsNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LooseEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\SameInstantSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\DefaultValueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use Antevemus\ASpecification\Specifications\String\ContainsSpecification;
use Antevemus\ASpecification\Specifications\String\DateStringSpecification;
use Antevemus\ASpecification\Specifications\String\EndsWithSpecification;
use Antevemus\ASpecification\Specifications\String\EnumNameStringSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseAndAccentsStringSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\StartsWithSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\StateMachine\EventOriginSpecification;
use Antevemus\ASpecification\StateMachine\InStateSpecification;
use Antevemus\ASpecification\StateMachine\PayloadHasSpecification;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * SpecificationCodec - Writes a specification as data and reads it back (2.4.0, forward 041, marco 16a).
 *
 * The format (D1) is a JSON tree, canonical (keys in a fixed order, the same specification gives the
 * same document):
 *
 *   {"v": 1, "spec": {"t": "and", "s": [{"t": "property", "p": "age", "s": {"t": "gte", "v": 18}},
 *                                        {"t": "not", "s": {"t": "isNull"}}]}}
 *
 * - the envelope carries the format version `v` (1) and the tree `spec`;
 * - a node is `{"t": "<name>", ...parameters}`; `t` is a short, stable name (types() lists them), never
 *   a class name: renaming a class does not break a stored document and a document never chooses a
 *   class. `and`/`or` hold a list `"s": [...]` of two or more operands (a chain of conjunctions is one
 *   list, read back as the left-nested tree it was); `not` holds one node `"s": {...}`;
 * - `because` and `code` carry the because()/withCode() annotations of a node;
 * - values (D3): scalars as they are (an integer stays an integer, `1.0` stays a float), a date-time as
 *   `{"$date": "2026-01-31T09:30:00.000000-03:00"}` (RFC 3339 with the offset; read back as a
 *   DateTimeImmutable at the same instant and offset), an enum case as
 *   `{"$enum": "<FQCN>", "case": "<name>"}` only for an enum of the allowlist (TextComparison is in it;
 *   allowEnum() adds others; a document never loads a class), lists of those. A closure, any other object,
 *   a resource, an associative array or a non-finite float has no serialized form.
 *
 * The specifications of the library that are data implement ISerializableSpecification and are
 * registered under their names (coreTypes()); a user leaf implements it and is registered with
 * register('myLeaf', MyLeaf::class). The registry is checked by exact class: a subclass is another
 * specification (it may redefine the predicate) and needs a name of its own. Left out of the format on
 * purpose (D4), each refused with NonSerializableSpecificationException and the reason: callbacks
 * (must(), PredicateSpecification), Spec::external(), FeatureEnabledSpecification, the guards of the
 * state machine (referenced by name through GuardRegistry), InQuerySpecification (a repository),
 * RuleBoundSpecification (a binding to a catalog rule) and the document requirements of the rule engine.
 * A method call (`calling()`) is serialized by its method name and declarative arguments (`t: method`).
 *
 * Safety (D6): decoding never uses the native PHP deserialization, never instantiates a class named by
 * the document (the class of a node is the one registered in code for its `t`), refuses an enum outside
 * the allowlist before touching the class, and limits the depth of the tree (64 by default) and the
 * number of nodes (10 000 by default), both configurable; the JSON text itself is bounded before it is
 * walked. A type named as a parameter is a name: `type` is checked with class_exists()/interface_exists()
 * as Spec::specify() does, `enumName` with enum_exists() as its constructor does, `allEntities` and
 * `entity` keep it as given; like any type name of the library it may be autoloaded, never instantiated
 * nor called. Every failure is a SpecificationCodecException (or its
 * subclass NonSerializableSpecificationException) naming the position in the tree (`and[1].not`).
 *
 * The codec does not change the algebra (D9): the decoded specification is made of the same classes, so
 * `decode(encode($s))->equals($s)`; simplify() before encoding is the caller's choice.
 *
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Serialization
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationCodec
{
    /** Version of the format, written in the envelope (`v`). */
    public const FORMAT_VERSION = 1;

    /** Default maximum depth of a tree (the root is at depth 1). */
    public const DEFAULT_MAX_DEPTH = 64;

    /** Default maximum number of specification nodes of a tree. */
    public const DEFAULT_MAX_NODES = 10000;

    /** Format of a `$date` value: RFC 3339 with microseconds and the offset. */
    public const DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** Format of a registered name. */
    private const TYPE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /** An RFC 3339 date-time with an offset (`Z` or `±hh:mm`), with or without a fraction of second. */
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

    /** Keys of a node written by the codec itself. */
    private const RESERVED_KEYS = ['t', 'because', 'code'];

    /**
     * Specifications left out of the format on purpose (D4): class => [label in a path, reason].
     *
     * @var array<class-string, array{0: string, 1: string}>
     */
    private const OUT_OF_CONTRACT = [
        PredicateSpecification::class => ['callback', 'a callback (must(), PredicateSpecification) is code, not data'],
        ExternalDataSpecification::class => ['external', 'Spec::external() fetches its data through a data source when it is evaluated'],
        FeatureEnabledSpecification::class => ['featureEnabled', 'it depends on the FeatureFlags service (a flag targets a catalog rule by its code instead)'],
        InStateSpecification::class => ['inState', 'a guard of the state machine is referenced by name (GuardRegistry, GuardExpression)'],
        EventOriginSpecification::class => ['eventOrigin', 'a guard of the state machine is referenced by name (GuardRegistry, GuardExpression)'],
        PayloadHasSpecification::class => ['payloadHas', 'a guard of the state machine is referenced by name (GuardRegistry, GuardExpression)'],
        InQuerySpecification::class => ['inQuery', 'it holds a PdoRepository (a connection), which is not data'],
        RuleBoundSpecification::class => ['ruleBound', 'it binds a specification to a catalog rule: store the rule (spec_json), not the binding'],
        DocumentLeafSpecification::class => ['document', 'a document requirement is compiled from the catalog (document_requirement)'],
        DocumentSetSpecification::class => ['document', 'a document requirement is compiled from the catalog (document_requirement)'],
    ];

    /** @var array<string, class-string<ISerializableSpecification>> */
    private array $classesByType;

    /** @var array<class-string, string> */
    private array $typesByClass;

    /** @var array<class-string, class-string> Allowed enums, by name */
    private array $allowedEnums = [TextComparison::class => TextComparison::class];

    /** Position of the node being read (helpers of fromArray()). */
    private string $path = '';

    /** Nodes counted by the running encode() or decode(). */
    private int $nodes = 0;

    /**
     * @param int $maxDepth Maximum depth of a tree (>= 1; the root is at depth 1)
     * @param int $maxNodes Maximum number of specification nodes of a tree (>= 1)
     * @param list<class-string<UnitEnum>> $allowedEnums Enums whose cases may be written and read, besides TextComparison
     * @throws InvalidArgumentException When a limit is below 1 or an entry of $allowedEnums is not an enum
     */
    public function __construct(
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
        private readonly int $maxNodes = self::DEFAULT_MAX_NODES,
        array $allowedEnums = []
    ) {
        if ($maxDepth < 1 || $maxNodes < 1) {
            throw new InvalidArgumentException(sprintf(
                'The limits of a specification codec must be at least 1 (depth %d, nodes %d given).',
                $maxDepth,
                $maxNodes
            ));
        }
        $this->classesByType = self::coreTypes();
        $this->typesByClass = array_flip($this->classesByType);
        foreach ($allowedEnums as $enum) {
            $this->allowEnum($enum);
        }
    }

    /**
     * The names of the specifications of the library, with their classes. Some are anonymous classes of
     * SpecificationFactory (`type` is Spec::specify(T), `a` the wrapper of Spec::a($specification),
     * `hasSize`, `hasLength`, `any`, `none`, `include`, `includePercentageOf`, `isPast`, `isFuture`,
     * `isToday`): their class names are resolved here, never written.
     *
     * @return array<string, class-string<ISerializableSpecification>>
     */
    public static function coreTypes(): array
    {
        $factory = new SpecificationFactory();
        $any = new AlwaysTrueSpecification();
        $collections = $factory->collection();
        $dates = $factory->date();

        /** @var array<string, class-string<ISerializableSpecification>> $types */
        $types = [
            'and' => AndSpecification::class,
            'or' => OrSpecification::class,
            'not' => NotSpecification::class,
            'nor' => JointDenialSpecification::class,
            'property' => PropertySpecification::class,
            'method' => MethodParameterizedSpecification::class,
            'type' => $factory->type()->specify(ISpecification::class)::class,
            'a' => $factory->a($any)::class,
            'true' => AlwaysTrueSpecification::class,
            'false' => AlwaysFalseSpecification::class,
            'eq' => EqualSpecification::class,
            'neq' => NotEqualSpecification::class,
            'gt' => GreaterThanSpecification::class,
            'gte' => GreaterThanOrEqualSpecification::class,
            'lt' => LessThanSpecification::class,
            'lte' => LessThanOrEqualSpecification::class,
            'looseEq' => LooseEqualSpecification::class,
            'at' => SameInstantSpecification::class,
            'in' => InSpecification::class,
            'isNull' => IsNullSpecification::class,
            'notNull' => NotNullSpecification::class,
            'defaultValue' => DefaultValueSpecification::class,
            'contains' => ContainsSpecification::class,
            'startsWith' => StartsWithSpecification::class,
            'endsWith' => EndsWithSpecification::class,
            'regex' => RegexSpecification::class,
            'wildcard' => WildcardSpecification::class,
            'wildcardIgnoreCase' => WildcardExpressionMatcherIgnoreCaseStringSpecification::class,
            'equalIgnoreCase' => EqualIgnoreCaseStringSpecification::class,
            'equalIgnoreCaseAndAccents' => EqualIgnoreCaseAndAccentsStringSpecification::class,
            'dateString' => DateStringSpecification::class,
            'enumName' => EnumNameStringSpecification::class,
            'allEntities' => AllEntitiesSpecification::class,
            'entity' => UniqueEntitySpecification::class,
            'all' => CollectionSpecification::class,
            'any' => $collections->any($any)::class,
            'none' => $collections->none($any)::class,
            'hasSize' => $collections->hasSize($any)::class,
            'hasLength' => $factory->string()->hasLength($any)::class,
            'include' => $collections->include($any, $any)::class,
            'includePercentageOf' => $collections->includePercentageOf($any, $any)::class,
            'isPast' => $dates->isPast()::class,
            'isFuture' => $dates->isFuture()::class,
            'isToday' => $dates->isToday()::class,
        ];

        return $types;
    }

    /**
     * Registers a specification of the application under a name of its own.
     *
     * @param string $type Name written as `t` (a letter, then up to 63 letters, digits or underscores)
     * @param class-string<ISerializableSpecification> $class Concrete class implementing ISerializableSpecification
     * @return static
     * @throws InvalidArgumentException When the name is not valid or taken by another class, or the class
     *                                  is not a concrete ISerializableSpecification or has another name already
     */
    public function register(string $type, string $class): static
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid specification name "%s": a letter followed by up to 63 letters, digits or underscores.',
                $type
            ));
        }
        if (!is_subclass_of($class, ISerializableSpecification::class) || (new ReflectionClass($class))->isAbstract()) {
            throw new InvalidArgumentException(sprintf(
                'Class "%s" cannot be registered as "%s": it must be a concrete class implementing %s.',
                $class,
                $type,
                ISerializableSpecification::class
            ));
        }
        $class = (new ReflectionClass($class))->getName();
        $current = $this->classesByType[$type] ?? $class;
        if ($current !== $class) {
            throw new InvalidArgumentException(sprintf('The specification name "%s" is already registered for another class.', $type));
        }
        $name = $this->typesByClass[$class] ?? $type;
        if ($name !== $type) {
            throw new InvalidArgumentException(sprintf('Class "%s" is already registered as "%s".', $class, $name));
        }
        $this->classesByType[$type] = $class;
        $this->typesByClass[$class] = $type;

        return $this;
    }

    /**
     * Allows the cases of an enum to be written and read (`{"$enum": ..., "case": ...}`) and the enum to
     * be named by `enumName` (EnumNameStringSpecification).
     *
     * @param class-string<UnitEnum> $enumClass
     * @return static
     * @throws InvalidArgumentException When it is not an enum
     */
    public function allowEnum(string $enumClass): static
    {
        if (!enum_exists($enumClass)) {
            throw new InvalidArgumentException(sprintf('"%s" is not an enum: only enums can be allowed in a specification document.', $enumClass));
        }
        $name = (new ReflectionEnum($enumClass))->getName();
        $this->allowedEnums[$name] = $name;

        return $this;
    }

    /**
     * Whether the cases of the enum may be written and read.
     */
    public function isEnumAllowed(string $enumClass): bool
    {
        return isset($this->allowedEnums[$enumClass]);
    }

    /**
     * Every registered name with its class (the library's and the application's).
     *
     * @return array<string, class-string<ISerializableSpecification>>
     */
    public function types(): array
    {
        return $this->classesByType;
    }

    /** Maximum depth of a tree. */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /** Maximum number of specification nodes of a tree. */
    public function getMaxNodes(): int
    {
        return $this->maxNodes;
    }

    /**
     * The document of a specification: `['v' => 1, 'spec' => <node>]`.
     *
     * @return array{v: int, spec: array<string, mixed>}
     * @throws NonSerializableSpecificationException When a node or a value has no serialized form
     * @throws SpecificationCodecException When the tree is deeper or larger than the limits
     */
    public function encode(ISpecification $specification): array
    {
        $state = $this->enter();
        try {
            return ['v' => self::FORMAT_VERSION, 'spec' => $this->encodeNode($specification, '', 1)];
        } finally {
            $this->leave($state);
        }
    }

    /**
     * The specification of a document (as encode() returns it, or as json_decode(..., true) reads it).
     *
     * @param array<array-key, mixed> $document
     * @throws NonSerializableSpecificationException When a `t` is unknown or an enum is not allowed
     * @throws SpecificationCodecException When the document is not a valid specification document
     */
    public function decode(array $document): ISpecification
    {
        $state = $this->enter();
        try {
            if (count($document) !== 2 || !array_key_exists('v', $document) || !array_key_exists('spec', $document)) {
                throw new SpecificationCodecException('A specification document is {"v": 1, "spec": {...}}.');
            }
            if ($document['v'] !== self::FORMAT_VERSION) {
                throw new SpecificationCodecException(sprintf(
                    'Unsupported specification format version %s: this codec reads version %d.',
                    is_scalar($document['v']) ? var_export($document['v'], true) : get_debug_type($document['v']),
                    self::FORMAT_VERSION
                ));
            }
            if (!is_array($document['spec'])) {
                throw new SpecificationCodecException('The "spec" of a specification document must be a node {"t": ...}.');
            }

            return $this->decodeNode($document['spec'], '', 1);
        } finally {
            $this->leave($state);
        }
    }

    /**
     * The JSON document of a specification (canonical: the same specification, the same text).
     *
     * @param int $flags Extra json_encode() flags (e.g. JSON_PRETTY_PRINT)
     * @throws NonSerializableSpecificationException When a node or a value has no serialized form
     * @throws SpecificationCodecException When the tree exceeds the limits or a text is not valid UTF-8
     */
    public function toJson(ISpecification $specification, int $flags = 0): string
    {
        $document = $this->encode($specification);
        try {
            return json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $flags,
                $this->jsonDepth()
            );
        } catch (JsonException $e) {
            throw new SpecificationCodecException('The specification has no JSON form: ' . $e->getMessage() . '.', '', $e);
        }
    }

    /**
     * The specification of a JSON document.
     *
     * @throws NonSerializableSpecificationException When a `t` is unknown or an enum is not allowed
     * @throws SpecificationCodecException When the JSON is malformed or is not a valid specification document
     */
    public function fromJson(string $json): ISpecification
    {
        try {
            $document = json_decode($json, true, $this->jsonDepth(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SpecificationCodecException('Malformed specification JSON: ' . $e->getMessage() . '.', '', $e);
        }
        if (!is_array($document)) {
            throw new SpecificationCodecException('A specification document is a JSON object {"v": 1, "spec": {...}}.');
        }

        return $this->decode($document);
    }

    // ==========================================
    // Helpers of fromArray()
    // ==========================================

    /**
     * Checks the parameter keys of the node being read.
     *
     * @param array<string, mixed> $data Parameters given to fromArray()
     * @param list<string> $required Keys that must be present
     * @param list<string> $optional Keys that may be present
     * @throws SpecificationCodecException When a required key is missing or an unknown key is present
     */
    public function expectKeys(array $data, array $required, array $optional = []): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw $this->invalid(sprintf('Missing key "%s".', $key));
            }
        }
        $known = [...$required, ...$optional];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $known, true)) {
                throw $this->invalid(sprintf(
                    'Unknown key "%s" (expected: %s).',
                    (string) $key,
                    $known === [] ? 'none' : implode(', ', $known)
                ));
            }
        }
    }

    /**
     * A child specification.
     *
     * @param array<string, mixed> $data
     * @throws SpecificationCodecException When the key does not hold a specification node
     */
    public function specification(array $data, string $key): ISpecification
    {
        $value = $data[$key] ?? null;
        if (!$value instanceof ISpecification) {
            throw $this->invalid(sprintf('"%s" must be a specification node {"t": ...}.', $key));
        }

        return $value;
    }

    /**
     * A list of child specifications.
     *
     * @param array<string, mixed> $data
     * @param int $min Minimum number of specifications
     * @param int|null $max Maximum number of specifications (null = no maximum)
     * @return list<ISpecification>
     * @throws SpecificationCodecException When the key does not hold such a list
     */
    public function specifications(array $data, string $key, int $min = 1, ?int $max = null): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid(sprintf('"%s" must be a list of specification nodes.', $key));
        }
        foreach ($value as $item) {
            if (!$item instanceof ISpecification) {
                throw $this->invalid(sprintf('"%s" must be a list of specification nodes.', $key));
            }
        }
        $count = count($value);
        if ($count < $min || ($max !== null && $count > $max)) {
            throw $this->invalid(sprintf(
                '"%s" must hold %s specification nodes, %d given.',
                $key,
                $max === null ? 'at least ' . $min : ($min === $max ? $min : $min . ' to ' . $max),
                $count
            ));
        }

        return $value;
    }

    /**
     * A text parameter.
     *
     * @param array<string, mixed> $data
     * @throws SpecificationCodecException When the key does not hold a string
     */
    public function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw $this->invalid(sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * An optional text parameter: $default when the key is absent.
     *
     * @param array<string, mixed> $data
     * @throws SpecificationCodecException When the key is present and does not hold a string
     */
    public function optionalString(array $data, string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $data) ? $this->string($data, $key) : $default;
    }

    /**
     * A value parameter (scalar, null, date-time, enum case or a list of those), never a specification.
     *
     * @param array<string, mixed> $data
     * @throws SpecificationCodecException When the key is missing or holds a specification node
     */
    public function value(array $data, string $key): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw $this->invalid(sprintf('Missing key "%s".', $key));
        }
        $value = $data[$key];
        $this->assertValue($value, $key);

        return $value;
    }

    /**
     * A list of values.
     *
     * @param array<string, mixed> $data
     * @return list<mixed>
     * @throws SpecificationCodecException When the key does not hold a list of values
     */
    public function values(array $data, string $key): array
    {
        $value = $this->value($data, $key);
        if (!is_array($value)) {
            throw $this->invalid(sprintf('"%s" must be a list.', $key));
        }

        /** @var list<mixed> $value */
        return $value;
    }

    /**
     * A text comparison written by its value (`case_sensitive`, `ignore_case`, `ignore_case_and_accents`):
     * $default when the key is absent.
     *
     * @param array<string, mixed> $data
     * @throws SpecificationCodecException When the key holds anything else
     */
    public function comparison(array $data, string $key, ?TextComparison $default = null): ?TextComparison
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        $value = $data[$key];
        $comparison = is_string($value) ? TextComparison::tryFrom($value) : null;
        if ($comparison === null) {
            throw $this->invalid(sprintf(
                '"%s" must be one of %s.',
                $key,
                implode(', ', array_map(static fn(TextComparison $case): string => $case->value, TextComparison::cases()))
            ));
        }

        return $comparison;
    }

    /**
     * A SpecificationCodecException at the node being read, for a check of fromArray().
     */
    public function invalid(string $reason): SpecificationCodecException
    {
        return new SpecificationCodecException($reason, $this->path);
    }

    // ==========================================
    // Encoding
    // ==========================================

    /**
     * @return array<string, mixed>
     */
    private function encodeNode(ISpecification $specification, string $prefix, int $depth): array
    {
        $type = $this->typesByClass[$specification::class] ?? null;
        $path = self::join($prefix, $type ?? self::label($specification));
        $this->countNode($path, $depth);
        if ($type === null || !$specification instanceof ISerializableSpecification) {
            throw self::refusal($specification, $path);
        }

        $node = ['t' => $type];
        foreach ($specification->toArray() as $key => $value) {
            if (!is_string($key) || in_array($key, self::RESERVED_KEYS, true)) {
                throw new SpecificationCodecException(sprintf(
                    'toArray() of %s returned the key "%s": parameter keys are strings other than t, because and code.',
                    self::describe($specification),
                    (string) $key
                ), $path);
            }
            $node[$key] = $this->encodeValue($value, $key === 's' ? $path : $path . '.' . $key, $depth, 0);
        }
        if ($specification instanceof AbstractSpecification) {
            [$reason, $code] = self::annotationsOf($specification);
            if ($reason !== null) {
                $node['because'] = $reason;
            }
            if ($code !== null) {
                $node['code'] = $code;
            }
        }

        return $node;
    }

    private function encodeValue(mixed $value, string $at, int $depth, int $nesting): mixed
    {
        if ($value instanceof ISpecification) {
            return $this->encodeNode($value, $at, $depth + 1);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new NonSerializableSpecificationException('A non-finite float (NAN, INF) has no serialized form.', $at);
            }

            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return ['$date' => $value->format(self::DATE_FORMAT)];
        }
        if ($value instanceof UnitEnum) {
            if (!isset($this->allowedEnums[$value::class])) {
                throw new NonSerializableSpecificationException(sprintf(
                    'Enum %s is not in the allowlist of the codec (SpecificationCodec::allowEnum()).',
                    $value::class
                ), $at);
            }

            return ['$enum' => $value::class, 'case' => $value->name];
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new NonSerializableSpecificationException('An associative array has no serialized form: only lists are.', $at);
            }
            $this->assertNesting($nesting, $at);
            $list = [];
            foreach ($value as $index => $item) {
                $list[] = $this->encodeValue($item, $at . '[' . $index . ']', $depth, $nesting + 1);
            }

            return $list;
        }
        if ($value instanceof Closure) {
            throw new NonSerializableSpecificationException('A closure is code, not data: it has no serialized form.', $at);
        }

        throw new NonSerializableSpecificationException(sprintf(
            'A value of type %s has no serialized form (scalars, null, date-times, allowed enum cases and lists of those).',
            get_debug_type($value)
        ), $at);
    }

    // ==========================================
    // Decoding
    // ==========================================

    /**
     * @param array<array-key, mixed> $node
     */
    private function decodeNode(array $node, string $prefix, int $depth): ISpecification
    {
        $type = $node['t'] ?? null;
        $path = self::join($prefix, is_string($type) ? $type : '?');
        $this->countNode($path, $depth);
        if (!is_string($type)) {
            throw new SpecificationCodecException('A specification node needs a string "t".', $path);
        }
        $class = $this->classesByType[$type] ?? throw new NonSerializableSpecificationException(sprintf(
            'Unknown specification name "%s": the codec reads the names it has registered (SpecificationCodec::register()).',
            $type
        ), $path);
        $reason = self::annotation($node, 'because', $path);
        $code = self::annotation($node, 'code', $path);

        $data = [];
        foreach ($node as $key => $value) {
            if (in_array($key, self::RESERVED_KEYS, true)) {
                continue;
            }
            if (!is_string($key)) {
                throw new SpecificationCodecException(sprintf('Invalid key %d in a specification node.', $key), $path);
            }
            $data[$key] = $this->decodeValue($value, $key === 's' ? $path : $path . '.' . $key, $depth, 0);
        }

        // Children are read before this point: the position of the helpers is the node itself
        $this->path = $path;
        try {
            $specification = $class::fromArray($data, $this);
        } catch (SpecificationCodecException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SpecificationCodecException(sprintf('Invalid parameters for "%s": %s', $type, $e->getMessage()), $path, $e);
        }

        if ($reason !== null) {
            $specification = $specification->because($reason);
        }

        return $code !== null ? $specification->withCode($code) : $specification;
    }

    private function decodeValue(mixed $value, string $at, int $depth, int $nesting): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            throw new SpecificationCodecException('A non-finite float (NAN, INF) is not part of a specification document.', $at);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (!is_array($value)) {
            throw new SpecificationCodecException(sprintf('A value of type %s is not part of a specification document.', get_debug_type($value)), $at);
        }
        if (array_is_list($value)) {
            $this->assertNesting($nesting, $at);
            $list = [];
            foreach ($value as $index => $item) {
                $list[] = $this->decodeValue($item, $at . '[' . $index . ']', $depth, $nesting + 1);
            }

            return $list;
        }
        if (array_key_exists('t', $value)) {
            return $this->decodeNode($value, $at, $depth + 1);
        }
        if (array_key_exists('$date', $value)) {
            return self::decodeDate($value, $at);
        }
        if (array_key_exists('$enum', $value)) {
            return $this->decodeEnum($value, $at);
        }

        throw new SpecificationCodecException(
            'An object of a specification document is a node {"t": ...}, a date {"$date": ...} or an enum case {"$enum": ..., "case": ...}.',
            $at
        );
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function decodeDate(array $value, string $at): DateTimeImmutable
    {
        $text = $value['$date'];
        if (count($value) !== 1 || !is_string($text) || preg_match(self::DATE_PATTERN, $text) !== 1) {
            throw new SpecificationCodecException(
                'A date is {"$date": "<RFC 3339 date-time with an offset>"}, e.g. 2026-01-31T09:30:00.000000-03:00.',
                $at
            );
        }
        try {
            $date = new DateTimeImmutable($text);
        } catch (Throwable $e) {
            throw new SpecificationCodecException(sprintf('"%s" is not a valid date-time.', $text), $at, $e);
        }
        if ($date->format('Y-m-d\TH:i:s') !== substr($text, 0, 19)) {
            throw new SpecificationCodecException(sprintf('"%s" is not a valid date-time.', $text), $at);
        }

        return $date;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function decodeEnum(array $value, string $at): UnitEnum
    {
        $enum = $value['$enum'];
        $name = $value['case'] ?? null;
        if (count($value) !== 2 || !is_string($enum) || !is_string($name)) {
            throw new SpecificationCodecException('An enum case is {"$enum": "<enum class>", "case": "<case name>"}.', $at);
        }
        if (!isset($this->allowedEnums[$enum])) {
            throw new NonSerializableSpecificationException(sprintf(
                'Enum "%s" is not in the allowlist of the codec (SpecificationCodec::allowEnum()): a document never loads a class.',
                $enum
            ), $at);
        }
        /** @var class-string<UnitEnum> $enum */
        foreach ($enum::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new SpecificationCodecException(sprintf('Enum %s has no case "%s".', $enum, $name), $at);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function annotation(array $node, string $key, string $path): ?string
    {
        $value = $node[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new SpecificationCodecException(sprintf('"%s" must be a string.', $key), $path);
        }

        return $value;
    }

    // ==========================================
    // Shared
    // ==========================================

    private function countNode(string $path, int $depth): void
    {
        if ($depth > $this->maxDepth) {
            throw new SpecificationCodecException(sprintf('The specification is deeper than %d levels (the depth limit of the codec).', $this->maxDepth), $path);
        }
        if (++$this->nodes > $this->maxNodes) {
            throw new SpecificationCodecException(sprintf('The specification has more than %d nodes (the node limit of the codec).', $this->maxNodes), $path);
        }
    }

    /**
     * Nesting bound of the JSON text, written and read: the envelope, an object and a list per level of
     * the tree, the lists of a value, and a margin of 2. A text nested deeper is refused by json_decode()
     * before the codec walks it.
     */
    private function jsonDepth(): int
    {
        return 3 * $this->maxDepth + 2;
    }

    private function assertNesting(int $nesting, string $at): void
    {
        if ($nesting >= $this->maxDepth) {
            throw new SpecificationCodecException(sprintf('A value is nested deeper than %d levels (the depth limit of the codec).', $this->maxDepth), $at);
        }
    }

    private function assertValue(mixed $value, string $key): void
    {
        if ($value instanceof ISpecification) {
            throw $this->invalid(sprintf('"%s" must be a value, not a specification node.', $key));
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertValue($item, $key);
            }
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function enter(): array
    {
        $state = [$this->path, $this->nodes];
        $this->path = '';
        $this->nodes = 0;

        return $state;
    }

    /**
     * @param array{0: string, 1: int} $state
     */
    private function leave(array $state): void
    {
        [$this->path, $this->nodes] = $state;
    }

    private static function join(string $prefix, string $segment): string
    {
        return $prefix === '' ? $segment : $prefix . '.' . $segment;
    }

    /**
     * The because()/withCode() annotations of a node.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function annotationsOf(AbstractSpecification $specification): array
    {
        return [
            (new ReflectionProperty(AbstractSpecification::class, 'customReason'))->getValue($specification),
            (new ReflectionProperty(AbstractSpecification::class, 'customCode'))->getValue($specification),
        ];
    }

    /**
     * Label of an unregistered specification in a path.
     */
    private static function label(ISpecification $specification): string
    {
        foreach (self::OUT_OF_CONTRACT as $class => [$label]) {
            if ($specification instanceof $class) {
                return $label;
            }
        }

        return '<' . self::describe($specification) . '>';
    }

    private static function refusal(ISpecification $specification, string $path): NonSerializableSpecificationException
    {
        $name = self::describe($specification);
        foreach (self::OUT_OF_CONTRACT as $class => [, $reason]) {
            if ($specification instanceof $class) {
                return new NonSerializableSpecificationException(sprintf('%s has no serialized form: %s.', $name, $reason), $path, $specification::class);
            }
        }
        if ($specification instanceof ISerializableSpecification) {
            return new NonSerializableSpecificationException(sprintf(
                '%s is not registered in the codec: a subclass of a serializable specification may redefine its predicate, so it is never written under the name of its parent; register it with SpecificationCodec::register().',
                $name
            ), $path, $specification::class);
        }

        return new NonSerializableSpecificationException(sprintf(
            '%s does not implement ISerializableSpecification: implement it and register the class with SpecificationCodec::register().',
            $name
        ), $path, $specification::class);
    }

    /**
     * Short name of a specification class for messages (an anonymous class by its parent).
     */
    private static function describe(ISpecification $specification): string
    {
        $reflection = new ReflectionClass($specification);
        if (!$reflection->isAnonymous()) {
            return $reflection->getShortName();
        }
        $parent = $reflection->getParentClass();

        return 'anonymous ' . ($parent !== false ? $parent->getShortName() : 'class');
    }
}
