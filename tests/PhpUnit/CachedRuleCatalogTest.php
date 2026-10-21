<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Cache\CacheKeys;
use Antevemus\ASpecification\Cache\CachedRuleCatalog;
use Antevemus\ASpecification\Cache\CachedRuleTypeCatalog;
use Antevemus\ASpecification\Cache\Exceptions\CacheFailureException;
use Antevemus\ASpecification\Cache\ICatalogVersion;
use Antevemus\ASpecification\Cache\PdoCatalogVersion;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleTypeCatalog;
use Antevemus\ASpecification\Engine\DocumentRequirementMode;
use Antevemus\ASpecification\Engine\DocumentRuleDefinition;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RulePrecedence;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\ArrayCache;
use Antevemus\ASpecification\Tests\Support\CountingRuleCatalog;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * CachedRuleCatalogTest - the rule catalog read through a shared PSR-16 cache: the same answers, one read
 * per TTL, the invalidation by generation and by catalog version, and a failing cache read as a miss.
 *
 * @version 2.8.0
 */
final class CachedRuleCatalogTest extends TestCase
{
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        if (!interface_exists(CacheInterface::class)) {
            self::markTestSkipped('psr/simple-cache is not installed');
        }
    }

    public function testAHitIsTheSameAnswerWithoutReadingTheCatalog(): void
    {
        $inner = self::counting();
        $cache = $this->cache();
        $catalog = CachedRuleCatalog::decorate($inner, $cache);
        self::assertInstanceOf(CachedRuleTypeCatalog::class, $catalog);
        self::assertSame($inner, $catalog->getInner());
        self::assertSame(60, $catalog->getTtl());

        $filters = ['product_code' => 'P1', 'reference_date' => '2026-03-01'];
        $miss = $catalog->findRules('claim', 'vacant', $filters);
        $hit = $catalog->findRules('claim', 'vacant', $filters);
        self::assertSame(1, $inner->reads['findRules']);
        self::assertEquals($inner->findRules('claim', 'vacant', $filters), $hit);
        self::assertEquals($miss, $hit);
        self::assertSame(['full', 'vacant_rule', 'global'], array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $hit));
        self::assertEquals(self::fullRule(), $hit[0], 'every field of the rule comes back');
        self::assertInstanceOf(RuleDefinition::class, $hit[0]);

        $documents = $catalog->findDocumentRules('claim', 'vacant');
        self::assertEquals($documents, $catalog->findDocumentRules('claim', 'vacant'));
        self::assertSame(1, $inner->reads['findDocumentRules']);
        self::assertCount(2, $documents);
        self::assertEquals(new DocumentRuleDefinition('g1', 'id_card', 'claim', DocumentRequirementMode::ONE_OF_SET, 'ids', 'x > 1', 3, true, 'vacant'), $documents[1]);

        self::assertEquals(self::fullRule(), $catalog->findRuleByCode('full'));
        self::assertEquals(self::fullRule(), $catalog->findRuleByCode('full'));
        self::assertNull($catalog->findRuleByCode('nothing'));
        self::assertNull($catalog->findRuleByCode('nothing'), 'the absence is cached too');
        self::assertSame(2, $inner->reads['findRuleByCode']);

        $byType = $catalog->findRulesByType('claim', 'notice', ['product_code' => 'P1']);
        self::assertEquals($byType, $catalog->findRulesByType('claim', 'notice', ['product_code' => 'P1']));
        self::assertSame(1, $inner->reads['findRulesByType']);
        self::assertSame(['full', 'vacant_rule', 'global'], array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $byType));
        self::assertSame(45, RulePrecedence::findRule($catalog, 'claim', 'notice', 'vacant', ['product_code' => 'P1'])->getIntValue());
        self::assertSame(1, $inner->reads['findRulesByType'], 'RulePrecedence reads the cached type query');

        foreach ($cache->keys() as $key) {
            self::assertMatchesRegularExpression('/^aspec\.catalog\.(?:[0-9a-f]{32}\.s1|gen\.[0-9a-f]{32})$/', $key);
        }
        self::assertSame(60, $cache->ttlOf($cache->keys()[0]));
    }

    public function testDecoratePicksTheClassByTheCatalog(): void
    {
        $plain = new class implements IRuleCatalog {
            public int $reads = 0;

            public function findRules(string $scope, ?string $scenario = null, array $filters = []): array
            {
                $this->reads++;

                return [new RuleDefinition('a', 'A', 't', scope: $scope)];
            }

            public function findDocumentRules(string $scope, ?string $scenario = null): array
            {
                return [];
            }

            public function findRuleByCode(string $code): ?IRuleDefinition
            {
                return null;
            }
        };
        $catalog = CachedRuleCatalog::decorate($plain, $this->cache());
        self::assertSame(CachedRuleCatalog::class, $catalog::class);
        self::assertNotInstanceOf(IRuleTypeCatalog::class, $catalog, 'RulePrecedence falls back to findRules(), as without the cache');
        self::assertSame('a', RulePrecedence::findRule($catalog, 'claim', 't', null)?->getCode());
        RulePrecedence::findRule($catalog, 'claim', 't', null);
        self::assertSame(1, $plain->reads);
        self::assertSame(CachedRuleCatalog::class, (new CachedRuleCatalog(self::counting(), $this->cache()))::class);
    }

    public function testOnlyTheReservedFiltersSplitTheCache(): void
    {
        $inner = self::counting();
        $catalog = CachedRuleCatalog::decorate($inner, $this->cache());
        $read = static fn(array $filters, ?string $scenario = 'vacant', string $scope = 'claim'): int => count($catalog->findRules($scope, $scenario, $filters));

        $read(['product_code' => 'P1', 'reference_date' => new DateTimeImmutable('2026-03-01 08:00')]);
        $read(['product_code' => 'P1', 'reference_date' => new DateTimeImmutable('2026-03-01 23:00'), 'contract' => 7]);
        $read(['product_code' => 'P1', 'reference_date' => '2026-03-01 12:00']);
        self::assertSame(1, $inner->reads['findRules'], 'the same day, other keys ignored');

        $read(['product_code' => 'P1', 'reference_date' => '2026-03-02']);
        $read(['product_code' => 'P2', 'reference_date' => '2026-03-01']);
        $read(['product_code' => 'P1', 'plan_code' => 'X', 'reference_date' => '2026-03-01']);
        $read(['product_code' => 'P1']);
        $read(['product_code' => 'P1', 'reference_date' => '2026-03-01'], null);
        $read(['product_code' => 'P1', 'reference_date' => '2026-03-01'], 'occupied');
        $read(['product_code' => 'P1', 'reference_date' => '2026-03-01'], 'vacant', 'rental');
        $read(['product_code' => 1, 'reference_date' => '2026-03-01']);
        $read(['product_code' => '1', 'reference_date' => '2026-03-01']);
        $read(['product_code' => true]);
        $read(['product_code' => false]);
        self::assertSame(12, $inner->reads['findRules'], 'each distinct reserved value is its own entry');
        $read(['product_code' => true]);
        $read(['product_code' => '1', 'reference_date' => '2026-03-01']);
        self::assertSame(12, $inner->reads['findRules']);
    }

    public function testFiltersTheCatalogRefusesAreNotCached(): void
    {
        $inner = self::counting();
        $cache = $this->cache();
        $catalog = CachedRuleCatalog::decorate($inner, $cache);
        foreach ([1, 2] as $_) {
            try {
                $catalog->findRules('claim', null, ['codigo_produto' => 'P1']);
                self::fail('legacy key accepted');
            } catch (LegacyVocabularyException) {
            }
            try {
                $catalog->findRulesByType('claim', 'notice', ['data_referencia' => '2026-01-01']);
                self::fail('legacy key accepted');
            } catch (LegacyVocabularyException) {
            }
            try {
                $catalog->findRules('claim', null, ['reference_date' => 'not a date at all']);
                self::fail('a broken date accepted');
            } catch (InvalidArgumentException) {
            }
            try {
                $catalog->findRules('claim', null, ['reference_date' => 20260101]);
                self::fail('an integer date accepted');
            } catch (InvalidArgumentException) {
            }
            try {
                $catalog->findRules('claim', null, ['reference_date' => '']);
                self::fail('an empty date accepted');
            } catch (InvalidArgumentException) {
            }
            $catalog->findRules('claim', 'vacant', ['product_code' => ['P1']]);
            $catalog->findRulesByType('claim', 'notice', ['plan_code' => new \stdClass()]);
        }
        self::assertSame(['findRules' => 10, 'findRulesByType' => 4], $inner->reads, 'every call reached the catalog');
        self::assertSame([], $cache->keys(), 'nothing was cached, not even a generation');
    }

    public function testInvalidateStartsANewGenerationForEveryNode(): void
    {
        $cache = $this->cache();
        $innerA = self::counting();
        $innerB = self::counting();
        $nodeA = CachedRuleCatalog::decorate($innerA, $cache);
        $nodeB = CachedRuleCatalog::decorate($innerB, $cache);

        $nodeA->findRules('claim', 'vacant');
        $nodeB->findRules('claim', 'vacant');
        self::assertSame(1, $innerA->total());
        self::assertSame(0, $innerB->total(), 'node B read what node A cached');

        self::assertTrue($nodeA->invalidate());
        $nodeB->findRules('claim', 'vacant');
        self::assertSame(1, $innerB->total(), 'after the invalidation node B reads the catalog');
        $nodeA->findRules('claim', 'vacant');
        self::assertSame(1, $innerA->total(), 'and node A reads what node B cached');

        $cache->failOn('set');
        self::assertFalse($nodeA->invalidate(), 'an invalidation the cache refused');
    }

    public function testTheTtlBoundsHowLongAChangeGoesUnseen(): void
    {
        $cache = $this->cache();
        $inner = self::counting();
        $catalog = CachedRuleCatalog::decorate($inner, $cache, 5);
        self::assertSame(5, $catalog->getTtl());
        $catalog->findDocumentRules('claim');
        $this->now += 4;
        $catalog->findDocumentRules('claim');
        self::assertSame(1, $inner->total());
        $this->now += 1;
        $catalog->findDocumentRules('claim');
        self::assertSame(2, $inner->total(), 'expired after 5 s');
        foreach ([0, -1] as $ttl) {
            try {
                new CachedRuleCatalog($inner, $cache, $ttl);
                self::fail("TTL {$ttl} accepted");
            } catch (InvalidArgumentException $e) {
                self::assertSame("The TTL of a cached catalog must be at least 1 second, {$ttl} given.", $e->getMessage());
            }
        }
        self::assertSame(1, (new CachedRuleCatalog($inner, $cache, 1))->getTtl());
    }

    public function testACatalogVersionMakesEveryChangeVisibleAtOnce(): void
    {
        $version = new class implements ICatalogVersion {
            public string $value = 'v1';
            public int $reads = 0;

            public function catalogVersion(): string
            {
                $this->reads++;

                return $this->value;
            }
        };
        $inner = self::counting();
        $catalog = CachedRuleCatalog::decorate($inner, $this->cache(), version: $version);
        $catalog->findRuleByCode('full');
        $catalog->findRuleByCode('full');
        self::assertSame(1, $inner->total());
        self::assertSame(2, $version->reads, 'the version is read on every call');
        $version->value = 'v2';
        $catalog->findRuleByCode('full');
        self::assertSame(2, $inner->total());
        $version->value = 'v1';
        $catalog->findRuleByCode('full');
        self::assertSame(2, $inner->total(), 'the entry of v1 is still there');
    }

    public function testAVersionThatCannotBeReadSendsTheCallToTheCatalog(): void
    {
        $version = new class implements ICatalogVersion {
            public function catalogVersion(): string
            {
                throw new RuntimeException('connection lost');
            }
        };
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $inner = self::counting();
        $cache = $this->cache();
        $catalog = CachedRuleCatalog::decorate($inner, $cache, version: $version, reporter: $reporter);
        $catalog->findRules('claim');
        $catalog->findRules('claim');
        self::assertSame(2, $inner->total());
        self::assertSame([], $cache->keys());
        self::assertCount(1, $reporter->entries(), 'reported once');
        $failure = $reporter->entries()[0]['error'];
        self::assertInstanceOf(CacheFailureException::class, $failure);
        self::assertSame('version', $failure->operation);
        self::assertSame('aspec.catalog.version.' . CacheKeys::hash(['default']), $failure->key);
        self::assertSame('connection lost', $failure->getPrevious()?->getMessage());
        $catalog->forgetReportedFailures();
        $catalog->findRules('claim');
        self::assertCount(2, $reporter->entries());
    }

    public function testAFailingCacheIsACatalogWithoutCache(): void
    {
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $inner = self::counting();
        $cache = $this->cache()->failOn('get');
        $catalog = CachedRuleCatalog::decorate($inner, $cache, reporter: $reporter);
        $expected = $inner->findRules('claim', 'vacant', ['product_code' => 'P1']);
        for ($i = 0; $i < 3; $i++) {
            self::assertEquals($expected, $catalog->findRules('claim', 'vacant', ['product_code' => 'P1']));
            self::assertEquals(self::fullRule(), $catalog->findRuleByCode('full'));
        }
        self::assertCount(1, $reporter->entries(), 'the generation key, once');
        self::assertSame('get', $reporter->entries()[0]['error']->operation);
        self::assertSame(0, $cache->calls('set'), 'a cache that fails reads is not written');

        // Only the entries fail (the generation is read): misses, reported once per key, not written
        $reporter->clear();
        $cache->heal()->failWhen(static fn(string $method, ?string $key): bool => $method === 'get' && $key !== null && !str_contains($key, '.gen.'));
        $catalog = CachedRuleCatalog::decorate($inner, $cache, reporter: $reporter);
        $sets = $cache->calls('set');
        for ($i = 0; $i < 3; $i++) {
            self::assertEquals($expected, $catalog->findRules('claim', 'vacant', ['product_code' => 'P1']));
            self::assertEquals(self::fullRule(), $catalog->findRuleByCode('full'));
        }
        self::assertCount(2, $reporter->entries(), 'two keys, one report each');
        self::assertSame($sets + 1, $cache->calls('set'), 'only the new generation was written');

        // Writes refused: the generation cannot be kept, every call goes to the catalog
        $reporter->clear();
        $cache = $this->cache()->refuseWrites();
        $before = $inner->total();
        $catalog = CachedRuleCatalog::decorate($inner, $cache, reporter: $reporter);
        $catalog->findRules('claim');
        $catalog->findRules('claim');
        self::assertSame($before + 2, $inner->total());
        self::assertCount(1, $reporter->entries());
        self::assertSame('set', $reporter->entries()[0]['error']->operation);
    }

    public function testAnEntryOfAnotherShapeIsAMissAndIsReplaced(): void
    {
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $inner = self::counting();
        $cache = $this->cache();
        $catalog = CachedRuleCatalog::decorate($inner, $cache, reporter: $reporter);
        $catalog->findRules('claim', 'vacant');
        $catalog->findDocumentRules('claim', 'vacant');
        $catalog->findRuleByCode('full');
        [$rulesKey, $documentsKey, $codeKey] = self::entryKeys($cache);

        $good = self::rawRule();
        $broken = [
            'not an array' => 'x',
            'no list' => ['rules' => 'x'],
            'not a list' => ['rules' => ['a' => $good]],
            'a row that is not an array' => ['rules' => [1]],
            'a missing field' => ['rules' => [array_diff_key($good, ['priority' => 1])]],
            'a wrong type' => ['rules' => [['priority' => '5'] + $good]],
            'a float as int' => ['rules' => [['intValue' => 1.5] + $good]],
            'an int as float' => ['rules' => [['decimalValue' => 1] + $good]],
            'a string as bool' => ['rules' => [['active' => 'Y'] + $good]],
            'a null code' => ['rules' => [['code' => null] + $good]],
            'parameters as text' => ['rules' => [['parameters' => '{}'] + $good]],
            'an unknown action' => ['rules' => [['violationAction' => 'shout'] + $good]],
            'an int action' => ['rules' => [['violationAction' => 1] + $good]],
            'an int description' => ['rules' => [['description' => 5] + $good]],
            'an object' => new \stdClass(),
        ];
        $reads = $inner->reads['findRules'];
        foreach ($broken as $case => $value) {
            $cache->plant($rulesKey, $value);
            $reporter->clear();
            $catalog->forgetReportedFailures();
            self::assertSame(['vacant_rule', 'global'], array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $catalog->findRules('claim', 'vacant')), $case);
            self::assertSame(++$reads, $inner->reads['findRules'], $case);
            self::assertCount(1, $reporter->entries(), $case);
            self::assertSame('read', $reporter->entries()[0]['error']->operation, $case);
            self::assertSame($rulesKey, $reporter->entries()[0]['error']->key, $case);
            $catalog->findRules('claim', 'vacant');
            self::assertSame($reads, $inner->reads['findRules'], "{$case}: the entry was replaced");
        }

        $document = ['groupCode' => 'g', 'documentTypeCode' => 'd', 'scope' => 'claim', 'requirementMode' => 'all', 'alternativeSetCode' => null, 'conditionExpression' => null, 'sortOrder' => 0, 'active' => true, 'scenario' => null];
        $documentCases = [
            'not a list' => ['documents' => 'x'],
            'a row that is not an array' => ['documents' => ['x']],
            'an unknown mode' => ['documents' => [['requirementMode' => 'some'] + $document]],
            'an empty scope' => ['documents' => [['scope' => ' '] + $document]],
            'a missing field' => ['documents' => [array_diff_key($document, ['sortOrder' => 0])]],
            'an object' => new \stdClass(),
        ];
        foreach ($documentCases as $case => $value) {
            $cache->plant($documentsKey, $value);
            $reporter->clear();
            $catalog->forgetReportedFailures();
            self::assertCount(2, $catalog->findDocumentRules('claim', 'vacant'), $case);
            self::assertCount(1, $reporter->entries(), $case);
        }
        $cache->plant($documentsKey, ['documents' => [['scope' => ''] + $document]]);
        $reporter->clear();
        $catalog->forgetReportedFailures();
        $catalog->findDocumentRules('claim', 'vacant');
        $failure = $reporter->entries()[0]['error'];
        self::assertSame('Cache read of key "' . $documentsKey . '" failed, read as a miss: a document requirement is not valid: Document requirement "d" (group "g") must declare a non-empty operational scope.', $failure->getMessage());
        self::assertInstanceOf(InvalidArgumentException::class, $failure->getPrevious()?->getPrevious());
        $cache->plant($documentsKey, ['documents' => [$document]]);
        self::assertEquals([new DocumentRuleDefinition('g', 'd', 'claim')], $catalog->findDocumentRules('claim', 'vacant'), 'a valid planted entry is read');

        foreach (['no rule key' => ['x' => 1], 'not an array' => 7, 'a broken rule' => ['rule' => ['code' => 'x']]] as $case => $value) {
            $cache->plant($codeKey, $value);
            $reporter->clear();
            $catalog->forgetReportedFailures();
            self::assertEquals(self::fullRule(), $catalog->findRuleByCode('full'), $case);
            self::assertCount(1, $reporter->entries(), $case);
        }

        // A generation that is not a token: reported, replaced by a new one (the old entries are not read)
        $generationKey = array_values(array_filter($cache->keys(), static fn(string $key): bool => str_contains($key, '.gen.')))[0];
        foreach (['not hex', 12345, str_repeat('a', 15), str_repeat('g', 16), str_repeat('a', 17)] as $token) {
            $cache->plant($generationKey, $token);
            $reporter->clear();
            $catalog->forgetReportedFailures();
            $before = $inner->total();
            $catalog->findRules('claim', 'vacant');
            self::assertSame($before + 1, $inner->total(), var_export($token, true));
            self::assertSame('read', $reporter->entries()[0]['error']->operation);
            self::assertSame($generationKey, $reporter->entries()[0]['error']->key);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $cache->get($generationKey));
        }
    }

    public function testTheQueriesNeverShareAnEntry(): void
    {
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $inner = self::counting();
        $catalog = CachedRuleCatalog::decorate($inner, $this->cache(), reporter: $reporter);
        // The same arguments in different queries: a scope named like a query, a scenario named like a type
        self::assertEquals(self::fullRule(), $catalog->findRuleByCode('full'));
        self::assertSame([], $catalog->findDocumentRules('code', 'full'));
        self::assertSame(['global'], self::codes($catalog->findRules('code', 'full')));
        self::assertSame([], $catalog->findRulesByType('code', 'full'));
        self::assertSame(['global'], self::codes($catalog->findRules('claim', 'notice')));
        self::assertSame(['vacant_rule', 'global'], self::codes($catalog->findRulesByType('claim', 'notice')));
        self::assertSame(['global'], self::codes($catalog->findRules('claim', 'notice', ['product_code' => 'x'])));
        self::assertSame([], $reporter->entries(), 'no entry was read as another query\'s');
        self::assertSame(['findRuleByCode' => 1, 'findDocumentRules' => 1, 'findRules' => 3, 'findRulesByType' => 2], $inner->reads);

        // ... and the scope always counts
        self::assertCount(2, $catalog->findDocumentRules('claim', 'vacant'));
        self::assertSame([], $catalog->findDocumentRules('rental', 'vacant'));
        self::assertSame(['vacant_rule', 'global'], self::codes($catalog->findRulesByType('claim', 'notice')));
        self::assertSame(['global', 'other_scope'], self::codes($catalog->findRulesByType('rental', 'notice')));
        self::assertSame(['findRuleByCode' => 1, 'findDocumentRules' => 3, 'findRules' => 3, 'findRulesByType' => 3], $inner->reads);

        // findRulesByType() is split by every reserved filter too
        foreach ([['plan_code' => 'A'], ['plan_code' => 'B'], ['reference_date' => '2026-01-01'], ['reference_date' => '2026-01-02'], ['product_code' => 'P1', 'plan_code' => 'A']] as $filters) {
            $catalog->findRulesByType('claim', 'notice', $filters);
            $catalog->findRulesByType('claim', 'notice', $filters);
        }
        self::assertSame(8, $inner->reads['findRulesByType']);
    }

    public function testEachCatalogIdHasItsOwnGeneration(): void
    {
        $cache = $this->cache();
        $first = self::counting();
        $second = self::counting();
        $a = CachedRuleCatalog::decorate($first, $cache, catalogId: 'a');
        $b = CachedRuleCatalog::decorate($second, $cache, catalogId: 'b');
        $a->findRules('claim');
        $b->findRules('claim');
        $a->invalidate();
        $b->findRules('claim');
        $a->findRules('claim');
        self::assertSame(1, $second->total(), 'b kept its entries');
        self::assertSame(2, $first->total(), 'a read again');
        self::assertContains('aspec.catalog.gen.' . CacheKeys::hash(['a']), $cache->keys());
        self::assertContains('aspec.catalog.gen.' . CacheKeys::hash(['b']), $cache->keys());
    }

    public function testCatalogIdAndPrefixSeparateTheEntries(): void
    {
        $cache = $this->cache();
        $first = self::counting();
        $second = self::counting();
        CachedRuleCatalog::decorate($first, $cache)->findRules('claim');
        CachedRuleCatalog::decorate($second, $cache, catalogId: 'tenant_b')->findRules('claim');
        CachedRuleCatalog::decorate($second, $cache, prefix: 'other_')->findRules('claim');
        self::assertSame(1, $first->total());
        self::assertSame(2, $second->total());
        self::assertCount(2, array_filter($cache->keys(), static fn(string $key): bool => str_starts_with($key, 'other_catalog.')));
        $this->expectException(InvalidArgumentException::class);
        CachedRuleCatalog::decorate($first, $cache, prefix: 'app:');
    }

    public function testWithAsOfCachesEachDayApart(): void
    {
        $pdo = self::pdoCatalog();
        $cache = $this->cache();
        $catalog = CachedRuleCatalog::decorate(new PdoRuleCatalog($pdo), $cache);
        $march = $catalog->withAsOf(new DateTimeImmutable('2026-03-01'));
        $june = $catalog->withAsOf(new DateTimeImmutable('2026-06-01'));
        self::assertInstanceOf(CachedRuleTypeCatalog::class, $march);
        self::assertNull($catalog->getInner() instanceof PdoRuleCatalog ? $catalog->getInner()->getAsOf() : 'x', 'the original is untouched');
        self::assertSame(['spring', 'always'], self::codes($march->findRules('claim')));
        self::assertSame(['summer', 'always'], self::codes($june->findRules('claim')));
        self::assertSame(['summer', 'spring', 'always'], self::codes($catalog->findRules('claim')));
        self::assertSame(['spring', 'always'], self::codes($march->findRulesByType('claim', 'season')));
        self::assertNull($june->findRuleByCode('spring'));
        self::assertSame('spring', $march->findRuleByCode('spring')?->getCode());

        $pdo->exec('DELETE FROM business_rule');
        self::assertSame(['spring', 'always'], self::codes($march->findRules('claim')), 'cached per day');
        self::assertSame(['summer', 'always'], self::codes($june->findRules('claim')));
        self::assertSame(['spring', 'always'], self::codes($catalog->withAsOf(new DateTimeImmutable('2026-03-01 18:00'))->findRules('claim')), 'by day');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('withAsOf() needs a decorated PdoRuleCatalog, ' . CountingRuleCatalog::class . ' given.');
        CachedRuleCatalog::decorate(self::counting(), $cache)->withAsOf(new DateTimeImmutable());
    }

    public function testTwoNodesSharingACacheOverOneDatabase(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aspec_cache_');
        try {
            $setup = new PDO('sqlite:' . $file);
            self::loadCatalog($setup);
            $setup->exec("ALTER TABLE business_rule ADD COLUMN updated_at VARCHAR(20)");
            $setup->exec("INSERT INTO business_rule (code, name, rule_type, int_value, scope, updated_at) VALUES ('max_claims', 'Max', 'claim_max', 2, 'claim', '2026-10-21 10:00:00')");
            $cache = $this->cache();
            $nodeA = new PDO('sqlite:' . $file);
            $nodeB = new PDO('sqlite:' . $file);
            $catalogA = CachedRuleCatalog::decorate(new PdoRuleCatalog($nodeA), $cache);
            $catalogB = CachedRuleCatalog::decorate(new PdoRuleCatalog($nodeB), $cache);
            self::assertSame(2, $catalogB->findRules('claim')[0]->getIntValue());

            // A writes without invalidating: within the TTL, B keeps reading the previous rule (eventual)
            $nodeA->exec("UPDATE business_rule SET int_value = 3, updated_at = '2026-10-21 10:00:05' WHERE code = 'max_claims'");
            self::assertSame(2, $catalogB->findRules('claim')[0]->getIntValue(), 'the stale read within the TTL');
            self::assertSame(2, $catalogA->findRules('claim')[0]->getIntValue(), 'node A reads the shared entry too');

            // After the invalidation, B reads the change
            $catalogA->invalidate();
            self::assertSame(3, $catalogB->findRules('claim')[0]->getIntValue());

            // Or at the end of the TTL
            $nodeA->exec("UPDATE business_rule SET int_value = 4, updated_at = '2026-10-21 10:00:09' WHERE code = 'max_claims'");
            self::assertSame(3, $catalogB->findRules('claim')[0]->getIntValue());
            $this->now += 60;
            self::assertSame(4, $catalogB->findRules('claim')[0]->getIntValue());

            // With the catalog version, at once
            $versionA = PdoCatalogVersion::forRuleCatalog($nodeA, new PdoRuleCatalog($nodeA), 'updated_at');
            $versionB = PdoCatalogVersion::forRuleCatalog($nodeB, new PdoRuleCatalog($nodeB), 'updated_at');
            $catalogA = CachedRuleCatalog::decorate(new PdoRuleCatalog($nodeA), $cache, version: $versionA);
            $catalogB = CachedRuleCatalog::decorate(new PdoRuleCatalog($nodeB), $cache, version: $versionB);
            self::assertSame(4, $catalogB->findRules('claim')[0]->getIntValue());
            $nodeA->exec("UPDATE business_rule SET int_value = 5, updated_at = '2026-10-21 10:00:12' WHERE code = 'max_claims'");
            self::assertSame(5, $catalogB->findRules('claim')[0]->getIntValue(), 'seen at once through the version');
            $nodeA->exec("INSERT INTO business_rule (code, name, rule_type, int_value, scope) VALUES ('another', 'Another', 'claim_max', 9, 'claim')");
            self::assertCount(2, $catalogB->findRules('claim'), 'a new row without stamp: seen by the count');
            $before = $catalogA->findRules('claim');
            self::assertEquals($before, $catalogB->findRules('claim'));
        } finally {
            @unlink($file);
        }
    }

    public function testTheEngineValidatesTheSameThroughTheCache(): void
    {
        $catalog = new InMemoryRuleCatalog([
            new RuleDefinition(code: 'max_claims', name: 'Ongoing claims', ruleType: 'claim_max', intValue: 2, violationMessage: 'Too many ongoing claims.', scope: 'claim'),
        ]);
        $registry = (new RuleSpecificationRegistry())->registerClosure('claim_max', static fn(RuleDefinition $rule) => Spec::property('ongoing', Spec::lessThanOrEqualTo($rule->getIntValue()))
            ->withCode($rule->getCode())->because($rule->getViolationMessage()));
        $plain = new DynamicSpecificationEngine($catalog, $registry);
        $cached = new DynamicSpecificationEngine(CachedRuleCatalog::decorate($catalog, $this->cache()), $registry);
        foreach ([1, 3] as $ongoing) {
            $expected = $plain->validate(['ongoing' => $ongoing], 'claim');
            foreach ([1, 2] as $_) {
                $verdict = $cached->validate(['ongoing' => $ongoing], 'claim');
                self::assertSame($expected->isSatisfied(), $verdict->isSatisfied());
                self::assertSame($expected->getFailureCodes(), $verdict->getFailureCodes());
                self::assertSame($ongoing > 2 ? ['max_claims'] : [], $verdict->getFailureCodes());
                self::assertSame(
                    array_map(static fn($f): string => $f->code . ':' . $f->message, $expected->getBlockingFailures()),
                    array_map(static fn($f): string => $f->code . ':' . $f->message, $verdict->getBlockingFailures())
                );
            }
        }
    }

    private function cache(): ArrayCache
    {
        return new ArrayCache(fn(): int => $this->now);
    }

    /** A rule with every field set. */
    private static function fullRule(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'full',
            name: 'Full rule',
            ruleType: 'notice',
            violationAction: RuleAction::WARN,
            description: 'Every field',
            intValue: 30,
            decimalValue: 12.5,
            textValue: 'text',
            legalBasis: 'Art. 1',
            violationMessage: 'Too late.',
            conditionExpression: 'a > 1',
            priority: 9,
            scope: 'claim',
            scenario: null,
            parameters: ['product_code' => 'P1', 'valid_from' => '2026-01-01', 'nested' => ['a' => [1, 2.5, null, true]], 'at' => new DateTimeImmutable('2026-01-01 10:00:00.123456', new DateTimeZone('America/Sao_Paulo'))],
            active: true,
            specJson: '{"v":1,"spec":{"t":"true"}}'
        );
    }

    /** @return array<string, mixed> The stored form of a rule, valid */
    private static function rawRule(): array
    {
        return [
            'code' => 'x', 'name' => 'X', 'ruleType' => 't', 'violationAction' => 'block', 'description' => null,
            'intValue' => null, 'decimalValue' => null, 'textValue' => null, 'legalBasis' => null, 'violationMessage' => null,
            'conditionExpression' => null, 'priority' => 0, 'scope' => null, 'scenario' => null, 'parameters' => [],
            'active' => true, 'specJson' => null,
        ];
    }

    private static function counting(): CountingRuleCatalog
    {
        return new CountingRuleCatalog(new InMemoryRuleCatalog(
            [
                self::fullRule(),
                new RuleDefinition(code: 'vacant_rule', name: 'Vacant', ruleType: 'notice', intValue: 45, priority: 5, scope: 'claim', scenario: 'vacant'),
                new RuleDefinition(code: 'global', name: 'Global', ruleType: 'notice', intValue: 30),
                new RuleDefinition(code: 'other_scope', name: 'Other', ruleType: 'notice', scope: 'rental'),
            ],
            [
                new DocumentRuleDefinition('g0', 'proof', 'claim'),
                new DocumentRuleDefinition('g1', 'id_card', 'claim', DocumentRequirementMode::ONE_OF_SET, 'ids', 'x > 1', 3, true, 'vacant'),
            ]
        ));
    }

    /**
     * @param list<string> $keys
     * @return list<string> The keys of the entries (not the generation), in the order they were written
     */
    private static function entryKeys(ArrayCache $cache): array
    {
        $entries = [];
        foreach ($cache->keys() as $key) {
            if (!str_contains($key, '.gen.')) {
                $entries[] = $key;
            }
        }
        // order: the test wrote rules, documents, code; recover it by the values
        usort($entries, static function (string $a, string $b) use ($cache): int {
            $rank = static fn(mixed $value): int => is_array($value) && isset($value['rules']) ? 0 : (is_array($value) && isset($value['documents']) ? 1 : 2);

            return $rank($cache->get($a)) <=> $rank($cache->get($b));
        });

        return $entries;
    }

    /** @param list<IRuleDefinition> $rules @return list<string> */
    private static function codes(array $rules): array
    {
        return array_map(static fn(IRuleDefinition $rule): string => $rule->getCode(), $rules);
    }

    private static function pdoCatalog(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        self::loadCatalog($pdo);
        $pdo->exec("INSERT INTO business_rule (code, name, rule_type, priority, scope, valid_from, valid_until) VALUES
            ('spring', 'Spring', 'season', 5, 'claim', '2026-01-01', '2026-03-31'),
            ('summer', 'Summer', 'season', 6, 'claim', '2026-04-01', '2026-12-31'),
            ('always', 'Always', 'season', 1, 'claim', NULL, NULL)");

        return $pdo;
    }

    private static function loadCatalog(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ddl = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/sql/rule_catalog.ansi.sql');
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
