<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException;

/**
 * TextComparison - How a text operator compares the candidate with its literal (2.1.0).
 *
 * The second argument of `contains()`, `startsWith()` and `endsWith()` accepts a case of this
 * enum besides the boolean it always took: `true` is `CaseSensitive`, `false` is `IgnoreCase`,
 * and nothing that already worked changes meaning.
 *
 * - `CaseSensitive`: the characters must be equal.
 * - `IgnoreCase`: the case of every Unicode letter is ignored (`mb_strtolower`); accents are
 *   compared (`'jose'` does not match `'José'`), in memory and in every database since 1.14.0.
 * - `IgnoreCaseAndAccents`: case and accents are ignored, the .NET `CompareOptions.IgnoreCase |
 *   IgnoreNonSpace`. Both sides are folded the same way: canonical decomposition (NFD), removal of
 *   the nonspacing marks (`\p{Mn}`), canonical composition (NFC) of what is left and the
 *   `mb_strtolower()` case folding, so `'configuracao'` matches `'Configuração'` and `'NINO'`
 *   matches `'niño'`. Letters that are not a base letter plus a mark stay themselves: `ß` is not
 *   `ss`, `æ` is not `ae`, `œ` is not `oe`, `ø`, `đ` and `ł` are not `o`, `d` and `l`. Requires
 *   `ext-intl` (the `Normalizer` class); building a specification with this option without the
 *   extension throws MissingExtensionException.
 *
 * Example:
 * <code>
 * Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('Configuração'); // true
 * Spec::contains('configuracao', false)->isSatisfiedBy('Configuração');                                // false
 * </code>
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum TextComparison: string
{
    case CaseSensitive = 'case_sensitive';
    case IgnoreCase = 'ignore_case';
    case IgnoreCaseAndAccents = 'ignore_case_and_accents';

    /**
     * The comparison a `$caseSensitive` argument stands for: `true` is CaseSensitive, `false` is
     * IgnoreCase, a case of this enum is itself.
     *
     * @param bool|self $caseSensitive
     * @return self
     */
    public static function of(bool|self $caseSensitive): self
    {
        if ($caseSensitive instanceof self) {
            return $caseSensitive;
        }

        return $caseSensitive ? self::CaseSensitive : self::IgnoreCase;
    }

    /**
     * Whether the comparison tells upper from lower case (only CaseSensitive does).
     *
     * @return bool
     */
    public function isCaseSensitive(): bool
    {
        return $this === self::CaseSensitive;
    }

    /**
     * Whether the comparison ignores accents (only IgnoreCaseAndAccents does).
     *
     * @return bool
     */
    public function ignoresAccents(): bool
    {
        return $this === self::IgnoreCaseAndAccents;
    }

    /**
     * Whether accent folding is available in this runtime (`ext-intl` loaded).
     *
     * @return bool
     */
    public static function isAccentFoldingAvailable(): bool
    {
        return extension_loaded('intl');
    }

    /**
     * Throws when accent folding is not available, naming the missing extension.
     *
     * @return void
     * @throws MissingExtensionException When `ext-intl` is not loaded
     */
    public static function assertAccentFoldingAvailable(): void
    {
        if (!self::isAccentFoldingAvailable()) {
            throw new MissingExtensionException(
                'intl',
                'TextComparison::IgnoreCaseAndAccents decomposes the text with the Normalizer class of ext-intl; install and enable ext-intl, or compare with TextComparison::IgnoreCase'
            );
        }
    }

    /**
     * Folds a text for the IgnoreCaseAndAccents comparison: NFD, removal of the nonspacing marks
     * (`\p{Mn}`), NFC, then `mb_strtolower(..., 'UTF-8')`. Returns null for a text that is not valid
     * UTF-8, which has no decomposition.
     *
     * Example: `foldCaseAndAccents('CONFIGURAÇÃO')` gives `'configuracao'`; `foldCaseAndAccents('Straße')`
     * gives `'straße'`.
     *
     * @param string $text
     * @return string|null
     * @throws MissingExtensionException When `ext-intl` is not loaded
     */
    public static function foldCaseAndAccents(string $text): ?string
    {
        self::assertAccentFoldingAvailable();

        if (!mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        if ($decomposed === false) {
            return null;
        }

        $bare = preg_replace('/\p{Mn}+/u', '', $decomposed);
        if ($bare === null) {
            return null;
        }

        // Recomposition keeps what the marks did not touch whole: a Hangul syllable decomposed into
        // jamo by NFD would otherwise contain the jamo of a shorter syllable.
        $composed = \Normalizer::normalize($bare, \Normalizer::FORM_C);

        return mb_strtolower($composed === false ? $bare : $composed, 'UTF-8');
    }
}
