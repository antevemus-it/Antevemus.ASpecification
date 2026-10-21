<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleTypeCatalog;
use Antevemus\ASpecification\Engine\DocumentRequirementMode;
use Antevemus\ASpecification\Engine\DocumentRuleDefinition;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagErrorReporter;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use UnexpectedValueException;

/**
 * CachedRuleCatalog - A Rule Catalog Read Through a Shared PSR-16 Cache
 *
 * Decorates an IRuleCatalog (a PdoRuleCatalog, usually) so that the rules and document requirements of a
 * scope are read from the database once per TTL for every process that shares the cache, instead of once
 * per request. Every answer is the decorated catalog's answer: a hit is the same list, in the same order,
 * with the same values.
 *
 * - Keys (CacheKeys): `<prefix>catalog.<hash>.s1`, the hash covering the generation (one per catalog id), the
 *   catalog version, the method and its arguments: the scope, the scenario and only the reserved filters of
 *   the IRuleCatalog contract (`product_code`, `plan_code` and the day of `reference_date`; every other key of
 *   `$filters` is ignored by the catalog, so it does not split the cache), plus the day of
 *   PdoRuleCatalog::withAsOf(). Filters the catalog would refuse (a Portuguese key of 1.x, a date that is not
 *   a date) are not cached: the call goes to the catalog, which refuses them as before.
 * - Invalidation: invalidate() starts a new generation, which every node sharing the cache reads on its next
 *   call (the old entries are never read again and expire); with an ICatalogVersion (PdoCatalogVersion), the
 *   version is read on every call and a change of the catalog is seen at once by every node; otherwise a
 *   change made without invalidate() is seen at the end of the TTL (60 s by default). Within the TTL and
 *   without either, a node keeps reading the previous rules: the consistency between nodes is eventual.
 * - Values: plain arrays (the getters of each definition), read back as RuleDefinition and
 *   DocumentRuleDefinition: a definition class of your own comes back as the library's, with the same
 *   getters. The `parameters` of a rule are stored as they are (scalars and arrays when they come from
 *   PdoRuleCatalog).
 * - Failures: a cache that throws, refuses a write or holds an entry of an unexpected shape is a miss:
 *   the call goes to the catalog, and the failure goes to the error reporter as a CacheFailureException,
 *   once per key per instance. A version that cannot be read sends the call to the catalog, reported too.
 *   A failure of the catalog itself is thrown, as without a cache, and nothing is written.
 * - findRulesByType() (IRuleTypeCatalog) is offered by CachedRuleTypeCatalog, for a catalog that implements
 *   it: decorate() picks the class, so RulePrecedence keeps reading the rules of a type in one query.
 *
 * Loadable without psr/simple-cache, but not constructible: the cache is a Psr\SimpleCache\CacheInterface
 * (`composer require psr/simple-cache` with a PSR-16 implementation, such as a Redis or APCu adapter).
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class CachedRuleCatalog implements IRuleCatalog
{
    /** Seconds an entry lives when no TTL is given. */
    public const DEFAULT_TTL = 60;

    /** Version of the stored format (the last segment of every key). */
    public const FORMAT = 's1';

    /** The decorated catalog (replaced in the copy made by withAsOf()). */
    protected IRuleCatalog $inner;

    private readonly GuardedCache $guard;

    private readonly string $prefix;

    private readonly string $generationKey;

    private readonly string $versionKey;

    /**
     * @param IRuleCatalog $inner The catalog read on a miss
     * @param CacheInterface $cache The shared PSR-16 cache
     * @param int $ttl Seconds an entry lives (>= 1)
     * @param ICatalogVersion|null $version Token read on every call that changes when the catalog changes (null = invalidate() and the TTL only)
     * @param string $prefix Prefix of every key (A-Z, a-z, 0-9, "_" and ".")
     * @param string $catalogId Name of this catalog in the cache: two catalogs over different tables sharing a cache and a prefix need different ids
     * @param IFeatureFlagErrorReporter|null $reporter Where cache failures are reported (null = not reported; still misses)
     * @throws InvalidArgumentException When the TTL is below 1 or the prefix is not valid
     */
    public function __construct(
        IRuleCatalog $inner,
        CacheInterface $cache,
        private readonly int $ttl = self::DEFAULT_TTL,
        private readonly ?ICatalogVersion $version = null,
        string $prefix = CacheKeys::DEFAULT_PREFIX,
        string $catalogId = 'default',
        ?IFeatureFlagErrorReporter $reporter = null
    ) {
        if ($ttl < 1) {
            throw new InvalidArgumentException(sprintf('The TTL of a cached catalog must be at least 1 second, %d given.', $ttl));
        }
        $this->inner = $inner;
        $this->prefix = CacheKeys::prefix($prefix);
        $this->guard = new GuardedCache($cache, $reporter);
        $this->generationKey = CacheKeys::key($this->prefix, 'catalog', 'gen', CacheKeys::hash([$catalogId]));
        $this->versionKey = CacheKeys::key($this->prefix, 'catalog', 'version', CacheKeys::hash([$catalogId]));
    }

    /**
     * The decorator for the catalog: a CachedRuleTypeCatalog when the catalog implements IRuleTypeCatalog
     * (InMemoryRuleCatalog and PdoRuleCatalog do), a CachedRuleCatalog otherwise. Same parameters as the constructor.
     *
     * @throws InvalidArgumentException When the TTL is below 1 or the prefix is not valid
     */
    public static function decorate(
        IRuleCatalog $inner,
        CacheInterface $cache,
        int $ttl = self::DEFAULT_TTL,
        ?ICatalogVersion $version = null,
        string $prefix = CacheKeys::DEFAULT_PREFIX,
        string $catalogId = 'default',
        ?IFeatureFlagErrorReporter $reporter = null
    ): self {
        return $inner instanceof IRuleTypeCatalog
            ? new CachedRuleTypeCatalog($inner, $cache, $ttl, $version, $prefix, $catalogId, $reporter)
            : new self($inner, $cache, $ttl, $version, $prefix, $catalogId, $reporter);
    }

    /** The decorated catalog. */
    public function getInner(): IRuleCatalog
    {
        return $this->inner;
    }

    /** Seconds an entry lives. */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * A copy over the decorated PdoRuleCatalog::withAsOf($asOf); its reads are cached apart from the
     * original's (the day of the validity filter is part of the key). The original is left untouched.
     *
     * @throws LogicException When the decorated catalog is not a PdoRuleCatalog
     */
    public function withAsOf(DateTimeInterface $asOf): static
    {
        if (!$this->inner instanceof PdoRuleCatalog) {
            throw new LogicException(sprintf('withAsOf() needs a decorated PdoRuleCatalog, %s given.', get_debug_type($this->inner)));
        }
        $clone = clone $this;
        $clone->inner = $this->inner->withAsOf($asOf);

        return $clone;
    }

    /**
     * Starts a new generation: every entry of this catalog written before is never read again, by any node
     * sharing the cache. Call it after the transaction that changed the catalog commits.
     *
     * @return bool False when the cache failed (reported): the entries expire at the end of the TTL
     */
    public function invalidate(): bool
    {
        return $this->guard->set($this->generationKey, self::newGeneration(), null);
    }

    /** Reports again, once per key, the cache failures already reported by this instance (for long-running workers). */
    public function forgetReportedFailures(): void
    {
        $this->guard->forgetReportedFailures();
    }

    /** {@inheritdoc} */
    public function findRules(string $scope, ?string $scenario = null, array $filters = []): array
    {
        $applicability = self::applicability($filters);
        $load = fn(): array => $this->inner->findRules($scope, $scenario, $filters);
        if ($applicability === null) {
            return $load();
        }

        return $this->cached('rules', array_merge([$scope, $scenario], $applicability), $load, self::encodeRules(...), self::decodeRules(...));
    }

    /** {@inheritdoc} */
    public function findDocumentRules(string $scope, ?string $scenario = null): array
    {
        return $this->cached(
            'documents',
            [$scope, $scenario],
            fn(): array => $this->inner->findDocumentRules($scope, $scenario),
            static fn(array $documents): array => ['documents' => array_map(self::encodeDocument(...), $documents)],
            static fn(mixed $value): array => array_map(self::decodeDocument(...), self::listOf($value, 'documents'))
        );
    }

    /** {@inheritdoc} */
    public function findRuleByCode(string $code): ?IRuleDefinition
    {
        return $this->cached(
            'code',
            [$code],
            fn(): ?IRuleDefinition => $this->inner->findRuleByCode($code),
            static fn(?IRuleDefinition $rule): array => ['rule' => $rule === null ? null : self::encodeRule($rule)],
            static function (mixed $value): ?RuleDefinition {
                if (!is_array($value) || !array_key_exists('rule', $value)) {
                    throw new UnexpectedValueException('the entry is not a rule');
                }

                return $value['rule'] === null ? null : self::decodeRule($value['rule']);
            }
        );
    }

    /**
     * Reads the rules of a query through the cache (for CachedRuleTypeCatalog).
     *
     * @param string $operation Name of the query (part of the key)
     * @param list<string|null> $parts Its arguments
     * @param Closure(): list<IRuleDefinition> $load
     * @return list<IRuleDefinition>
     */
    protected function cachedRules(string $operation, array $parts, Closure $load): array
    {
        return $this->cached($operation, $parts, $load, self::encodeRules(...), self::decodeRules(...));
    }

    /**
     * The reserved filters of the IRuleCatalog contract, as parts of a key; null when the filters would be
     * refused by the catalog (a Portuguese key of 1.x, a value that is not a scalar, a date that is not a date):
     * such a call is not cached.
     *
     * @param array<string, mixed> $filters
     * @return list<string|null>|null
     */
    protected static function applicability(array $filters): ?array
    {
        foreach (InMemoryRuleCatalog::LEGACY_FILTER_KEYS as $legacy) {
            if (array_key_exists($legacy, $filters)) {
                return null;
            }
        }
        $parts = [];
        foreach ([InMemoryRuleCatalog::FILTER_PRODUCT, InMemoryRuleCatalog::FILTER_PLAN] as $key) {
            $value = $filters[$key] ?? null;
            if ($value !== null && !is_scalar($value)) {
                return null;
            }
            $parts[] = $value === null ? null : var_export($value, true); // 1, '1', 1.0 and true stay apart
        }
        $reference = $filters[InMemoryRuleCatalog::FILTER_REFERENCE_DATE] ?? null;
        if ($reference instanceof DateTimeInterface) {
            $parts[] = $reference->format('Y-m-d');
        } elseif (is_string($reference) && $reference !== '') {
            try {
                $parts[] = (new DateTimeImmutable($reference))->format('Y-m-d');
            } catch (Exception) {
                return null;
            }
        } elseif ($reference === null) {
            $parts[] = null;
        } else {
            return null;
        }

        return $parts;
    }

    /**
     * @template T
     * @param string $operation Name of the query (part of the key)
     * @param list<string|null> $parts Its arguments
     * @param Closure(): T $load
     * @param Closure(T): mixed $encode
     * @param Closure(mixed): T $decode Throws UnexpectedValueException on an entry of an unexpected shape
     * @return T
     */
    private function cached(string $operation, array $parts, Closure $load, Closure $encode, Closure $decode): mixed
    {
        $key = $this->key($operation, $parts);
        if ($key === null) {
            return $load();
        }
        [$state, $value] = $this->guard->get($key);
        if ($state === GuardedCache::HIT) {
            try {
                return $decode($value);
            } catch (UnexpectedValueException $e) {
                $this->guard->fail('read', $key, $e->getMessage(), $e);
            }
        }
        $result = $load();
        if ($state !== GuardedCache::FAILED) {
            $this->guard->set($key, $encode($result), $this->ttl);
        }

        return $result;
    }

    /**
     * The key of a query, or null when the version or the generation cannot be read (reported). The catalog id
     * is not hashed: the generation is per catalog id, and two generations never repeat.
     *
     * @param list<string|null> $parts
     */
    private function key(string $operation, array $parts): ?string
    {
        $version = null;
        if ($this->version !== null) {
            try {
                $version = $this->version->catalogVersion();
            } catch (Throwable $e) {
                $this->guard->fail('version', $this->versionKey, $e->getMessage(), $e);

                return null;
            }
        }
        $generation = $this->generation();
        if ($generation === null) {
            return null;
        }
        $asOf = $this->inner instanceof PdoRuleCatalog ? $this->inner->getAsOf()?->format('Y-m-d') : null;

        return CacheKeys::key($this->prefix, 'catalog', CacheKeys::hash(array_merge([$generation, $version, $asOf, $operation], $parts)), self::FORMAT);
    }

    /** The current generation, starting one when there is none; null when the cache failed (reported). */
    private function generation(): ?string
    {
        [$state, $value] = $this->guard->get($this->generationKey);
        if ($state === GuardedCache::FAILED) {
            return null;
        }
        if ($state === GuardedCache::HIT) {
            if (is_string($value) && preg_match('/^[0-9a-f]{16}$/', $value) === 1) {
                return $value;
            }
            $this->guard->fail('read', $this->generationKey, 'the generation is not a token');
        }
        $generation = self::newGeneration();

        return $this->guard->set($this->generationKey, $generation, null) ? $generation : null;
    }

    private static function newGeneration(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param list<IRuleDefinition> $rules
     * @return array{rules: list<array<string, mixed>>}
     */
    private static function encodeRules(array $rules): array
    {
        return ['rules' => array_map(self::encodeRule(...), $rules)];
    }

    /**
     * @return list<RuleDefinition>
     * @throws UnexpectedValueException
     */
    private static function decodeRules(mixed $value): array
    {
        return array_map(self::decodeRule(...), self::listOf($value, 'rules'));
    }

    /** @return array<string, mixed> */
    private static function encodeRule(IRuleDefinition $rule): array
    {
        return [
            'code' => $rule->getCode(),
            'name' => $rule->getName(),
            'ruleType' => $rule->getRuleType(),
            'violationAction' => $rule->getViolationAction()->value,
            'description' => $rule->getDescription(),
            'intValue' => $rule->getIntValue(),
            'decimalValue' => $rule->getDecimalValue(),
            'textValue' => $rule->getTextValue(),
            'legalBasis' => $rule->getLegalBasis(),
            'violationMessage' => $rule->getViolationMessage(),
            'conditionExpression' => $rule->getConditionExpression(),
            'priority' => $rule->getPriority(),
            'scope' => $rule->getScope(),
            'scenario' => $rule->getScenario(),
            'parameters' => $rule->getParameters(),
            'active' => $rule->isActive(),
            'specJson' => $rule->getSpecJson(),
        ];
    }

    /** @throws UnexpectedValueException */
    private static function decodeRule(mixed $row): RuleDefinition
    {
        if (!is_array($row)) {
            throw new UnexpectedValueException('a rule is not an array');
        }
        $action = RuleAction::tryFrom(self::field($row, 'violationAction', 'string'));
        if ($action === null) {
            throw new UnexpectedValueException('a rule has an unknown violation action');
        }

        return new RuleDefinition(
            code: self::field($row, 'code', 'string'),
            name: self::field($row, 'name', 'string'),
            ruleType: self::field($row, 'ruleType', 'string'),
            violationAction: $action,
            description: self::field($row, 'description', '?string'),
            intValue: self::field($row, 'intValue', '?int'),
            decimalValue: self::field($row, 'decimalValue', '?float'),
            textValue: self::field($row, 'textValue', '?string'),
            legalBasis: self::field($row, 'legalBasis', '?string'),
            violationMessage: self::field($row, 'violationMessage', '?string'),
            conditionExpression: self::field($row, 'conditionExpression', '?string'),
            priority: self::field($row, 'priority', 'int'),
            scope: self::field($row, 'scope', '?string'),
            scenario: self::field($row, 'scenario', '?string'),
            parameters: self::field($row, 'parameters', 'array'),
            active: self::field($row, 'active', 'bool'),
            specJson: self::field($row, 'specJson', '?string')
        );
    }

    /** @return array<string, mixed> */
    private static function encodeDocument(IDocumentRuleDefinition $document): array
    {
        return [
            'groupCode' => $document->getGroupCode(),
            'documentTypeCode' => $document->getDocumentTypeCode(),
            'scope' => $document->getScope(),
            'requirementMode' => $document->getRequirementMode()->value,
            'alternativeSetCode' => $document->getAlternativeSetCode(),
            'conditionExpression' => $document->getConditionExpression(),
            'sortOrder' => $document->getSortOrder(),
            'active' => $document->isActive(),
            'scenario' => $document->getScenario(),
        ];
    }

    /** @throws UnexpectedValueException */
    private static function decodeDocument(mixed $row): DocumentRuleDefinition
    {
        if (!is_array($row)) {
            throw new UnexpectedValueException('a document requirement is not an array');
        }
        $mode = DocumentRequirementMode::tryFrom(self::field($row, 'requirementMode', 'string'));
        if ($mode === null) {
            throw new UnexpectedValueException('a document requirement has an unknown mode');
        }
        try {
            return new DocumentRuleDefinition(
                groupCode: self::field($row, 'groupCode', 'string'),
                documentTypeCode: self::field($row, 'documentTypeCode', 'string'),
                scope: self::field($row, 'scope', 'string'),
                requirementMode: $mode,
                alternativeSetCode: self::field($row, 'alternativeSetCode', '?string'),
                conditionExpression: self::field($row, 'conditionExpression', '?string'),
                sortOrder: self::field($row, 'sortOrder', 'int'),
                active: self::field($row, 'active', 'bool'),
                scenario: self::field($row, 'scenario', '?string')
            );
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedValueException('a document requirement is not valid: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * The list under `$key` of an entry.
     *
     * @return list<mixed>
     * @throws UnexpectedValueException
     */
    private static function listOf(mixed $value, string $key): array
    {
        if (!is_array($value) || !isset($value[$key]) || !is_array($value[$key]) || !array_is_list($value[$key])) {
            throw new UnexpectedValueException(sprintf('the entry has no list of %s', $key));
        }

        return $value[$key];
    }

    /**
     * A field of a stored row, of the expected type.
     *
     * @param array<array-key, mixed> $row
     * @param string $type `string`, `?string`, `int`, `?int`, `?float`, `bool` or `array`
     * @throws UnexpectedValueException
     */
    private static function field(array $row, string $name, string $type): mixed
    {
        if (!array_key_exists($name, $row)) {
            throw new UnexpectedValueException(sprintf('the field %s is missing', $name));
        }
        $value = $row[$name];
        $valid = match ($type) {
            'string' => is_string($value),
            '?string' => $value === null || is_string($value),
            'int' => is_int($value),
            '?int' => $value === null || is_int($value),
            '?float' => $value === null || is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
        };
        if (!$valid) {
            throw new UnexpectedValueException(sprintf('the field %s is not of type %s', $name, $type));
        }

        return $value;
    }
}
