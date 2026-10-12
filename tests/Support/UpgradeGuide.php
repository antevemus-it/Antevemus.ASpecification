<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

/**
 * Reads the SQL blocks of UPGRADE-2.0.md (and of its pt-BR twin), so that the statements the guide
 * prints are the ones the suite runs: each block follows an `<!-- upgrade-sql: <name> -->` marker.
 *
 * @version 2.0.0
 */
final class UpgradeGuide
{
    public const ENGLISH = __DIR__ . '/../../UPGRADE-2.0.md';
    public const PORTUGUESE = __DIR__ . '/../../UPGRADE-2.0.pt-BR.md';

    /**
     * The ```sql block that follows the marker `<!-- upgrade-sql: $name -->`; '' when absent.
     */
    public static function sqlBlock(string $file, string $name): string
    {
        $text = (string) file_get_contents($file);
        $pattern = '/<!-- upgrade-sql: ' . preg_quote($name, '/') . ' -->\s*```sql\n(.*?)```/s';

        return preg_match($pattern, $text, $match) === 1 ? $match[1] : '';
    }

    /**
     * The statements of a block (split on `;`, empty ones dropped).
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn(string $s): bool => $s !== ''));
    }
}
