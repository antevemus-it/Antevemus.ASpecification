<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

/**
 * Reads the PHP blocks of one numbered example, so that a transcription test can check that the English
 * and the Portuguese text carry the same code (comments aside).
 *
 * Since 2.5.1 (forward 049) the examples live on the pages of docs/en and docs/pt-BR, not in the README:
 * $file is the page that hosts the example (example 17.1 is `### 17.1. ` on docs/<language>/repositories/
 * pdo-lifecycle.md). The numbering of the former README is kept on the pages, so the cut is unchanged.
 *
 * @version 2.5.1
 */
final class ReadmeBlocks
{
    /**
     * The ```php blocks of example $number in $file, a page of docs/<language> (from its "### N. " heading
     * to the next "### " or "## ", or to the end of the page); a sub-example is named by its text ('18.1' for
     * "### 18.1. ").
     *
     * @return list<string>
     */
    public static function of(string $file, int|string $number): array
    {
        $text = (string) file_get_contents($file);
        $start = strpos($text, "\n### {$number}. ");
        if ($start === false) {
            return [];
        }
        $ends = array_filter([strpos($text, "\n### ", $start + 1), strpos($text, "\n## ", $start + 1)], static fn($p): bool => $p !== false);
        $end = $ends === [] ? strlen($text) : min($ends);
        preg_match_all('/```php\n(.*?)```/s', substr($text, $start, $end - $start), $matches);

        return $matches[1];
    }

    /**
     * The code tokens of a block, without comments and whitespace.
     *
     * @return list<string>
     */
    public static function codeTokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all('<?php ' . $code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) {
                    continue;
                }
                $tokens[] = $token[1];
            } else {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
