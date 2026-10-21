<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use PHPUnit\Framework\TestCase;

/**
 * DocumentationTreeTest - guard of the official documentation (forward 049, D12/D13, v2.5.1).
 *
 * Since 2.5.1 the README is an index and the documentation lives in docs/en and docs/pt-BR, one page per
 * topic, in the Docs Kit format (a docs-nav.json per language). This class keeps the two trees aligned:
 * (a) the same files in both languages; (b) both navigations parse, have the same shape, point to existing
 * pages and list every page; (c) every relative link of a page or of a README resolves; (d) every page
 * carries the same PHP blocks in both languages, token by token (comments aside), which gives examples 1 and 3 to 15 the EN x pt-BR guard they never had,
 * with no exception since 2.5.2 (the 12 blocks that kept Portuguese identifiers, strings or values were aligned to English); (e) the
 * number of PHP blocks of each page is the one declared below, with its cause; (f) each README has exactly
 * one PHP block, the first use of getting-started/index, and stays below 40 000 bytes; (g) the first use runs;
 * (h) nothing of the internal line leaks to a public page or README: no Reversa or internal docs/ path, no
 * Azure DevOps link, and no relative link outside the language folders but to the public root files.
 *
 * It is a PHPUnit-only test, not a module of tests/run_all.php: the runner keeps its 34 suites.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 */
final class DocumentationTreeTest extends TestCase
{
    private const LANGUAGES = ['en', 'pt-BR'];

    /** The README of each language. */
    private const READMES = ['en' => 'README.md', 'pt-BR' => 'README.pt-BR.md'];

    /** A README above this size is no longer an index (it was 224 225 and 230 376 bytes in 2.5.0). */
    private const README_MAX_BYTES = 40000;

    /**
     * PHP blocks per page, the same in both languages; a page not listed has none. The 58 blocks of the
     * examples of the former README (2.5.0), moved page by page, plus the first use (new in 2.5.1), plus the
     * block of example 16.1 (new in 2.6.0, forward 045: the stage transport hook), plus the three blocks of
     * example 23 (new in 2.7.0: legacy state codes, versioned definitions and tenants of the state machine).
     */
    private const PHP_BLOCKS = [
        'getting-started/index' => 1,                         // the first use (2.5.1), also the only block of each README
        'specifications/fluent-chaining' => 1,                // example 1
        'specifications/notification-pattern' => 1,           // example 2
        'specifications/partial-satisfaction' => 1,           // example 3
        'specifications/tautology-and-simplification' => 2,   // example 12
        'specifications/method-calls' => 1,                   // example 13
        'specifications/text-comparison' => 2,                // examples 20 and 20.1, one each
        'specifications/serialization' => 1,                  // example 21
        'repositories/partitioned-dag' => 1,                  // example 4
        'repositories/persistence-and-cache' => 1,            // example 5
        'repositories/pdo' => 3,                              // example 17
        'repositories/pdo-lifecycle' => 3,                    // example 17.1
        'repositories/pdo-batch' => 1,                        // example 17.2
        'repositories/pdo-relationships' => 5,                // example 17.3
        'rule-engine/dynamic-rules' => 2,                     // example 7
        'rule-engine/real-consumer' => 1,                     // example 22
        'sql/query-visitor' => 1,                             // example 8
        'sql/tcriteria' => 1,                                 // example 9
        'sql/tcriteria-accents-and-subqueries' => 1,          // example 22.1
        'integrations/alinq' => 5,                            // example 10 (10.1 to 10.4)
        'integrations/attributes' => 1,                       // example 11
        'integrations/adianti-host' => 1,                     // example 22.3
        'state-machine/index' => 7,                           // example 14 (14.1 to 14.7)
        'state-machine/use-cases' => 2,                       // example 15
        'state-machine/real-consumer' => 3,                   // example 23 (23.1 to 23.3, 2.7.0)
        'state-machine/business-time' => 2,                   // example 14.11 (2.7.0): business-time deadlines and the holiday table
        'resolution/concurrent' => 2,                         // example 16
        'resolution/async' => 1,                              // example 22.2
        'resolution/deferred' => 1,                           // example 16.1 (2.6.0)
        'events/from-specifications' => 3,                    // example 18
        'events/time-based' => 3,                             // example 18.1
        'feature-flags/index' => 2,                           // example 19
        'feature-flags/window-and-rollout' => 1,              // example 19.1
        'benchmarking/index' => 1,                            // example 6
    ];

    /**
     * Material that lives only on the internal line (Azure DevOps, docs/ outside docs/en and docs/pt-BR, the
     * Reversa folders) and must never reach a public page; compared ignoring case.
     */
    private const INTERNAL = ['_reversa', '.reversa', 'docs/addenda', 'docs/contratos', 'docs/traceability', 'docs/adianti', 'docs/state-machine', 'docs/paridade-domian', 'REVISAO-20', 'AUDITORIA-README'];

    /** The files of the repository root a page or a README may link to, besides the language folders. */
    private const PUBLIC_ROOT_FILES = ['README.md', 'README.pt-BR.md', 'CHANGELOG.md', 'ROADMAP.md', 'UPGRADE-2.0.md', 'UPGRADE-2.0.pt-BR.md', 'LICENSE', 'NOTICE.md', 'THIRD_PARTY_NOTICES.md'];

    /**
     * The blocks whose Portuguese code may differ from the English one: none. Until 2.5.1, 12 of the 58 blocks of
     * the examples (2, 3, 6, 7 x2, 8, 9, 10 x4 and 11) kept Portuguese identifiers, strings or, in example 9, a
     * longer variant, and were declared here with the tokens of each language pinned. Since 2.5.2 their Portuguese
     * code is the English code token by token, with the comments in Portuguese, so every block must be equal. An
     * entry here would make a block an exception again: "<page>#<block index>" => [sha1 EN, sha1 pt-BR] of
     * ReadmeBlocks::codeTokens joined by "\n"; the loop below still requires it to differ and to match the pins.
     *
     * @var array<string, array{string, string}>
     */
    private const LOCALIZED_BLOCKS = [];

    /** The 58 blocks of the examples of the former README, plus the first use, plus example 16.1 (2.6.0), plus example 23 and the two of example 14.11 (2.7.0). */
    private const PHP_BLOCKS_TOTAL = 58 + 1 + 1 + 3 + 2;

    // (a) -----------------------------------------------------------------------------------------------

    public function testBothLanguagesHaveTheSameFiles(): void
    {
        $english = self::files('en');
        $portuguese = self::files('pt-BR');
        self::assertContains('docs-nav.json', $english, 'docs/en/docs-nav.json');
        self::assertContains('index.md', $english, 'docs/en/index.md');
        self::assertSame($english, $portuguese, 'docs/en and docs/pt-BR have the same set of files');
    }

    // (b) -----------------------------------------------------------------------------------------------

    public function testNavigationsAreAlignedAndListEveryPage(): void
    {
        $shapes = [];
        foreach (self::LANGUAGES as $language) {
            $nav = json_decode((string) file_get_contents(self::root() . "/docs/{$language}/docs-nav.json"), true);
            self::assertIsArray($nav, "docs/{$language}/docs-nav.json parses");
            self::assertIsArray($nav['items'] ?? null, "docs/{$language}/docs-nav.json has items");
            self::assertSame(['text' => $nav['items'][0]['text'] ?? null, 'path' => 'index'], $nav['items'][0], "docs/{$language}: the first item is the index");
            $shape = self::shape($nav['items'], $language);
            $paths = array_values(array_filter(array_map(static fn(string $entry): ?string => str_starts_with($entry, 'path:') ? substr($entry, 5) : null, $shape)));
            self::assertSame(count($paths), count(array_unique($paths)), "docs/{$language}: no page twice in the navigation");
            foreach ($paths as $path) {
                self::assertFileExists(self::root() . "/docs/{$language}/{$path}.md", "docs/{$language}/docs-nav.json: {$path}");
            }
            $pages = array_map(static fn(string $file): string => substr($file, 0, -3), array_values(array_filter(self::files($language), static fn(string $file): bool => str_ends_with($file, '.md'))));
            sort($paths);
            sort($pages);
            self::assertSame($pages, $paths, "docs/{$language}: every page is in the navigation, and only pages");
            $shapes[$language] = $shape;
        }
        self::assertSame($shapes['en'], $shapes['pt-BR'], 'both navigations have the same shape: same paths in the same order, groups aligned');
    }

    // (c) -----------------------------------------------------------------------------------------------

    public function testEveryRelativeLinkResolves(): void
    {
        $checked = 0;
        foreach (self::LANGUAGES as $language) {
            $base = realpath(self::root() . "/docs/{$language}");
            foreach (self::pages($language) as $page) {
                $file = "{$base}/{$page}.md";
                $text = (string) file_get_contents($file);
                foreach (self::relativeLinks($text) as $target) {
                    $resolved = realpath(dirname($file) . '/' . $target);
                    self::assertNotFalse($resolved, "docs/{$language}/{$page}.md: the link {$target} resolves");
                    $checked++;
                }
            }
            foreach (self::relativeLinks((string) file_get_contents(self::root() . '/' . self::READMES[$language])) as $target) {
                self::assertFileExists(self::root() . '/' . $target, self::READMES[$language] . ": the link {$target} resolves");
                $checked++;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    // (d) -----------------------------------------------------------------------------------------------

    public function testEveryPageHasTheSameCodeInBothLanguages(): void
    {
        $localized = [];
        foreach (self::pages('en') as $page) {
            $english = (string) file_get_contents(self::root() . "/docs/en/{$page}.md");
            $portuguese = (string) file_get_contents(self::root() . "/docs/pt-BR/{$page}.md");
            self::assertSame(self::fences($english), self::fences($portuguese), "{$page}: the same code fences in the same order");
            $englishBlocks = self::phpBlocks($english);
            $portugueseBlocks = self::phpBlocks($portuguese);
            self::assertCount(count($englishBlocks), $portugueseBlocks, "{$page}: the same number of PHP blocks");
            foreach ($englishBlocks as $i => $block) {
                $englishTokens = ReadmeBlocks::codeTokens($block);
                $portugueseTokens = ReadmeBlocks::codeTokens($portugueseBlocks[$i] ?? '');
                $pinned = self::LOCALIZED_BLOCKS["{$page}#{$i}"] ?? null;
                if ($pinned === null) {
                    self::assertSame($englishTokens, $portugueseTokens, "{$page}: PHP block {$i} identical except comments");
                    continue;
                }
                self::assertNotSame($englishTokens, $portugueseTokens, "{$page}: PHP block {$i} is equal in both languages now, remove it from LOCALIZED_BLOCKS");
                self::assertSame($pinned, [sha1(implode("\n", $englishTokens)), sha1(implode("\n", $portugueseTokens))], "{$page}: PHP block {$i}, localized, changed in one language; align it or declare it again");
                $localized[] = "{$page}#{$i}";
            }
        }
        $declared = array_keys(self::LOCALIZED_BLOCKS);
        sort($declared);
        sort($localized);
        self::assertSame($declared, $localized, 'every declared localized block exists (none since 2.5.2: all 58 blocks of the examples are equal)');
    }

    // (e) -----------------------------------------------------------------------------------------------

    public function testEveryPageHasTheDeclaredNumberOfPhpBlocks(): void
    {
        self::assertSame(self::PHP_BLOCKS_TOTAL, array_sum(self::PHP_BLOCKS), 'the table adds up to 58 + the first use + example 16.1 + example 23 + example 14.11');
        foreach (self::LANGUAGES as $language) {
            $total = 0;
            foreach (self::pages($language) as $page) {
                $count = count(self::phpBlocks((string) file_get_contents(self::root() . "/docs/{$language}/{$page}.md")));
                self::assertSame(self::PHP_BLOCKS[$page] ?? 0, $count, "docs/{$language}/{$page}.md: PHP blocks");
                $total += $count;
            }
            self::assertSame(self::PHP_BLOCKS_TOTAL, $total, "docs/{$language}: PHP blocks in all pages");
        }
    }

    // (f) -----------------------------------------------------------------------------------------------

    public function testEachReadmeIsAnIndexWhoseOnlyCodeIsTheFirstUse(): void
    {
        foreach (self::READMES as $language => $readme) {
            $text = (string) file_get_contents(self::root() . '/' . $readme);
            self::assertLessThan(self::README_MAX_BYTES, strlen($text), "{$readme}: below " . self::README_MAX_BYTES . ' bytes');
            $blocks = self::phpBlocks($text);
            self::assertCount(1, $blocks, "{$readme}: exactly one PHP block");
            $page = self::phpBlocks((string) file_get_contents(self::root() . "/docs/{$language}/getting-started/index.md"));
            self::assertSame(ReadmeBlocks::codeTokens($page[0] ?? ''), ReadmeBlocks::codeTokens($blocks[0] ?? ''), "{$readme}: the block is the first use of docs/{$language}/getting-started/index.md");
        }
    }

    // (g) -----------------------------------------------------------------------------------------------

    public function testTheFirstUseRuns(): void
    {
        // FIRST USE >>>
        $adult = Spec::property('age', Spec::greaterThanOrEqualTo(18))
            ->because('Customer must be of legal age.')
            ->withCode('CLI_001');
        $active = Spec::property('status', Spec::equalTo('ACTIVE'))
            ->because('Only active accounts can receive a credit line.')
            ->withCode('CLI_002');
        $creditRule = $adult->and($active);

        $customer = (object) ['age' => 17, 'status' => 'ACTIVE'];

        $r1 = $creditRule->isSatisfiedBy($customer);

        $result = $creditRule->evaluate($customer);
        $r2 = count($result->failures);
        $r3 = $result->failures[0]->code;
        $r4 = $result->failures[0]->message;

        $where = Spec::toSql($creditRule, 'pgsql');
        $r5 = $where->getSql();
        $r6 = $where->getParameters();
        // <<< FIRST USE

        self::assertFalse($r1);
        self::assertSame(1, $r2);
        self::assertSame('CLI_001', $r3);
        self::assertSame('Customer must be of legal age.', $r4);
        self::assertSame('(("age" IS NOT NULL AND "age" >= :p1) AND ("status" IS NOT NULL AND "status" = :p2))', $r5);
        self::assertSame([':p1' => 18, ':p2' => 'ACTIVE'], $r6);
        self::assertTrue($creditRule->isSatisfiedBy((object) ['age' => 30, 'status' => 'ACTIVE']), 'an adult active customer satisfies it');

        foreach (self::LANGUAGES as $language) {
            self::assertTranscribed("docs/{$language}/getting-started/index.md", 'FIRST USE');
        }
    }

    // (h) -----------------------------------------------------------------------------------------------

    public function testNothingInternalLeaksToThePublicLine(): void
    {
        $files = [];
        foreach (self::LANGUAGES as $language) {
            foreach (self::files($language) as $file) {
                $files["docs/{$language}/{$file}"] = ["docs/{$language}"];
            }
        }
        foreach (self::READMES as $readme) {
            $files[$readme] = ['docs/en', 'docs/pt-BR'];
        }
        $root = realpath(self::root());
        foreach ($files as $file => $folders) {
            $text = (string) file_get_contents(self::root() . '/' . $file);
            foreach (self::INTERNAL as $internal) {
                self::assertStringNotContainsStringIgnoringCase($internal, $text, "{$file}: nothing of the internal material ({$internal})");
            }
            self::assertDoesNotMatchRegularExpression('#dev\.azure\.com|visualstudio\.com#i', $text, "{$file}: no link to Azure DevOps");
            foreach (self::relativeLinks($text) as $target) {
                $resolved = (string) realpath(dirname(self::root() . '/' . $file) . '/' . $target);
                $relative = str_starts_with($resolved, $root . '/') ? substr($resolved, strlen($root) + 1) : $resolved;
                $inside = array_filter($folders, static fn(string $folder): bool => str_starts_with($relative, $folder . '/'));
                self::assertTrue($inside !== [] || in_array($relative, self::PUBLIC_ROOT_FILES, true), "{$file}: the link {$target} points to a public file ({$relative})");
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The files of docs/<language>, relative to it and sorted.
     *
     * @return list<string>
     */
    private static function files(string $language): array
    {
        $base = self::root() . "/docs/{$language}";
        self::assertDirectoryExists($base);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = substr($file->getPathname(), strlen($base) + 1);
        }
        sort($files);

        return $files;
    }

    /**
     * The pages of docs/<language>, without the extension.
     *
     * @return list<string>
     */
    private static function pages(string $language): array
    {
        return array_map(static fn(string $file): string => substr($file, 0, -3), array_values(array_filter(self::files($language), static fn(string $file): bool => str_ends_with($file, '.md'))));
    }

    /**
     * The shape of a navigation without its texts: one entry per item, "path:<path>" or "group:<folder>"
     * followed by its items and "end".
     *
     * @param  list<array<string, mixed>> $items
     * @return list<string>
     */
    private static function shape(array $items, string $language): array
    {
        $shape = [];
        foreach ($items as $item) {
            self::assertIsString($item['text'] ?? null, "docs/{$language}/docs-nav.json: every item has a text");
            self::assertNotSame('', trim($item['text']), "docs/{$language}/docs-nav.json: the text is not empty");
            if (isset($item['items'])) {
                self::assertArrayNotHasKey('path', $item, "docs/{$language}/docs-nav.json: a group has no path");
                $inner = self::shape($item['items'], $language);
                $folders = array_unique(array_map(static fn(string $entry): string => explode('/', substr($entry, 5))[0], array_filter($inner, static fn(string $entry): bool => str_starts_with($entry, 'path:'))));
                self::assertCount(1, $folders, "docs/{$language}/docs-nav.json: a group holds the pages of one folder ({$item['text']})");
                $shape = [...$shape, 'group:' . reset($folders), ...$inner, 'end'];
            } else {
                self::assertIsString($item['path'] ?? null, "docs/{$language}/docs-nav.json: an item has a path or items");
                self::assertDoesNotMatchRegularExpression('/\.md$|^\//', $item['path'], "docs/{$language}/docs-nav.json: a path has no extension and is relative");
                $shape[] = 'path:' . $item['path'];
            }
        }

        return $shape;
    }

    /**
     * The relative links of a Markdown text outside code fences, without their anchors.
     *
     * @return list<string>
     */
    private static function relativeLinks(string $text): array
    {
        $links = [];
        foreach (self::prose($text) as $line) {
            preg_match_all('/\]\(([^)\s]+)\)/', $line, $matches);
            foreach ($matches[1] as $target) {
                if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|\#)#i', $target) === 1) {
                    continue;
                }
                $links[] = explode('#', $target)[0];
            }
        }

        return $links;
    }

    /**
     * The lines of a Markdown text outside code fences.
     *
     * @return list<string>
     */
    private static function prose(string $text): array
    {
        $lines = [];
        $fence = false;
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, '```')) {
                $fence = !$fence;
                continue;
            }
            if (!$fence) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The info string of every code fence that opens a block, in order.
     *
     * @return list<string>
     */
    private static function fences(string $text): array
    {
        preg_match_all('/^```(\S*)\n.*?^```$/ms', $text, $matches);

        return $matches[1];
    }

    /**
     * The ```php blocks of a text, read as ReadmeBlocks::of() reads them.
     *
     * @return list<string>
     */
    private static function phpBlocks(string $text): array
    {
        preg_match_all('/```php\n(.*?)```/s', $text, $matches);

        return $matches[1];
    }

    /**
     * The code between the markers `<name> >>>` and `<<< <name>` of this file is, token by token (comments
     * and blanks aside, and an expression kept as `$rN = <expression>`), a contiguous part of the only PHP
     * block of the page.
     */
    private static function assertTranscribed(string $page, string $name): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// {$name} >>>\n");
        $end = strpos($source, "// <<< {$name}\n");
        self::assertTrue($start !== false && $end !== false && $end > $start, "transcription {$name} marked");
        $tokens = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        $blocks = self::phpBlocks((string) file_get_contents(self::root() . '/' . $page));
        self::assertCount(1, $blocks, "{$page}: one PHP block");
        $block = ReadmeBlocks::codeTokens($blocks[0]);
        $found = false;
        $length = count($transcribed);
        for ($i = 0; $i + $length <= count($block) && !$found; $i++) {
            $found = array_slice($block, $i, $length) === $transcribed;
        }
        self::assertTrue($length > 10 && $found, "{$name}: the transcription is the code of {$page}");
    }
}
