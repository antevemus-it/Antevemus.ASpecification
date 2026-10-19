<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * WildcardExpressionMatcherIgnoreCaseStringSpecification - Leaf specification for case-insensitive wildcard matching.
 *
 * Validates whether the candidate string matches a wildcard pattern (e.g. `*.txt`) in a case-insensitive manner.
 *
 * Pattern and candidate are lowered with `mb_strtolower(..., 'UTF-8')` before `fnmatch()`, so the
 * case of any Unicode letter is ignored (`'ÁGUA*'` matches `'água mineral'`); before 1.5.0 the
 * lowering was the ASCII-only `strtolower()` (breaking change RN-03). `fnmatch()` itself works on
 * bytes: `?` matches one byte, so use `*` around multibyte characters. Requires `ext-mbstring`.
 *
 * Features:
 * - Unicode case-insensitive glob matching (`mb_strtolower` then `fnmatch`)
 * - Safe rejection of non-string candidates
 * - 2.3.0: the match is delegated to `new WildcardSpecification($pattern, TextComparison::IgnoreCase)`,
 *   which holds the same rule; the answers do not change. This class does not extend
 *   WildcardSpecification, so a visitor written before 2.3.0 that tests `instanceof
 *   WildcardSpecification` first never translates it as case-sensitive. The accent-insensitive glob is
 *   `Spec::wildcard($pattern, TextComparison::IgnoreCaseAndAccents)`.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class WildcardExpressionMatcherIgnoreCaseStringSpecification extends AbstractSpecification implements ISerializableSpecification
{
    /** The IgnoreCase wildcard that answers for this specification (2.3.0). */
    private readonly WildcardSpecification $matcher;

    /**
     * Initializes the specification with an expected wildcard pattern.
     *
     * @param string $pattern Expected wildcard pattern (e.g. `*.txt`)
     */
    public function __construct(private readonly string $pattern)
    {
        $this->matcher = new WildcardSpecification($pattern, TextComparison::IgnoreCase);
    }

    /**
     * Returns the configured wildcard pattern.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Verifies whether the string candidate matches the wildcard pattern (case-insensitive).
     *
     * @param mixed $candidate String candidate to validate
     * @return bool True if candidate matches pattern case-insensitively
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        // Unicode-aware (1.5.0, RN-03): both sides lowered with mbstring before fnmatch(), by the
        // IgnoreCase WildcardSpecification since 2.3.0.
        return $this->matcher->isSatisfiedBy($candidate);
    }

    /**
     * The comparison of this specification, always TextComparison::IgnoreCase (2.3.0).
     *
     * @return TextComparison
     */
    public function getComparison(): TextComparison
    {
        return TextComparison::IgnoreCase;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }

    /**
     * {@inheritdoc}
     *
     * Serialized as `{"t": "wildcardIgnoreCase", "p": "<pattern>"}` (2.4.0, forward 041).
     */
    public function toArray(): array
    {
        return ['p' => $this->pattern];
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['p']);

        return new static($codec->string($data, 'p'));
    }
}
