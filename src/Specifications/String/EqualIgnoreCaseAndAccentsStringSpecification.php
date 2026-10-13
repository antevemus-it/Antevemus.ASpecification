<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use InvalidArgumentException;

/**
 * EqualIgnoreCaseAndAccentsStringSpecification - String equality ignoring case and accents (2.1.0).
 *
 * The pair of EqualIgnoreCaseStringSpecification for TextComparison::IgnoreCaseAndAccents: both
 * strings are folded by TextComparison::foldCaseAndAccents() (NFD, nonspacing marks removed, NFC,
 * `mb_strtolower`) and compared exactly, so `'sao paulo'` matches `'São Paulo'` and `'NINO'`
 * matches `'Niño'`, while `'strasse'` does not match `'Straße'` (ß is not a letter plus a mark).
 * Requires `ext-intl`: building it without the extension throws MissingExtensionException.
 *
 * A separate class, not a subclass of EqualIgnoreCaseStringSpecification, so that a visitor that
 * only knows the case-insensitive leaf never translates this one as if accents counted.
 *
 * Features:
 * - Unicode case- and accent-insensitive equality, the same folding on both sides
 * - The reference value kept as written (`getValue()`), its folding computed once
 * - Safe handling of non-string candidates and of text that is not valid UTF-8 (not satisfied)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EqualIgnoreCaseAndAccentsStringSpecification extends AbstractSpecification
{
    private readonly string $folded;

    /**
     * @param string $value Target string for comparison
     * @throws \Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException When ext-intl is not loaded
     * @throws InvalidArgumentException When the value is not valid UTF-8
     */
    public function __construct(private readonly string $value)
    {
        $folded = TextComparison::foldCaseAndAccents($value);
        if ($folded === null) {
            throw new InvalidArgumentException('A text compared ignoring case and accents must be valid UTF-8.');
        }
        $this->folded = $folded;
    }

    /**
     * Returns the target comparison string value, as written.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Verifies whether the candidate equals the value once both are folded.
     *
     * @param mixed $candidate Target value to validate
     * @return bool
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        return TextComparison::foldCaseAndAccents($candidate) === $this->folded;
    }

    /**
     * Returns the type of candidate validated by this specification.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'string';
    }
}
