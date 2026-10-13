<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Countable;
use InvalidArgumentException;

/**
 * FeatureFlagRegistry - The One Place Where Feature Flags Are Declared
 *
 * Every flag the application reads is declared here, in code (forward 034, D2): a flag that is not
 * declared is always off when evaluated, cannot be turned on and is listed as "not declared" by
 * FeatureFlags::all(). A code that does not follow FeatureFlagDefinition::CODE_PATTERN and a code
 * declared twice are refused.
 *
 * <code>
 * $registry = new FeatureFlagRegistry([
 *     new FeatureFlagDefinition('renewal_notice', 'Renewal notice to agencies', subjectKey: 'unit_id'),
 * ]);
 * </code>
 *
 * The declaration order is kept: it is the order of FeatureFlags::all().
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagRegistry implements Countable
{
    /** @var array<string, FeatureFlagDefinition> */
    private array $definitions = [];

    /**
     * @param iterable<FeatureFlagDefinition> $definitions Declarations, in display order
     * @throws InvalidArgumentException When an item is not a FeatureFlagDefinition or a code is declared twice
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            if (!$definition instanceof FeatureFlagDefinition) {
                throw new InvalidArgumentException(sprintf(
                    'A feature flag registry holds FeatureFlagDefinition instances, %s given.',
                    get_debug_type($definition)
                ));
            }
            $this->declare($definition);
        }
    }

    /**
     * Builds a registry from an application array keyed by code (the shape of a `REGISTRY` constant:
     * `code => ['name' => ..., 'impact_on' => ..., ...]`; see FeatureFlagDefinition::fromArray()).
     *
     * @param array<string, array<string, mixed>> $registry Entries by code
     * @return self
     * @throws InvalidArgumentException When a code, a key or a value is not valid
     */
    public static function fromArray(array $registry): self
    {
        $definitions = [];
        foreach ($registry as $code => $entry) {
            if (!is_string($code) || !is_array($entry)) {
                throw new InvalidArgumentException('A feature flag registry array maps a code to an array of texts.');
            }
            $definitions[] = FeatureFlagDefinition::fromArray($code, $entry);
        }

        return new self($definitions);
    }

    /**
     * Declares one more flag.
     *
     * @return $this
     * @throws InvalidArgumentException When the code is already declared
     */
    public function declare(FeatureFlagDefinition $definition): self
    {
        if (isset($this->definitions[$definition->code])) {
            throw new InvalidArgumentException(sprintf('Feature flag "%s" is declared twice.', $definition->code));
        }
        $this->definitions[$definition->code] = $definition;

        return $this;
    }

    /** True when the code is declared. */
    public function has(string $code): bool
    {
        return isset($this->definitions[$code]);
    }

    /** The declaration of a code, or null when it is not declared. */
    public function get(string $code): ?FeatureFlagDefinition
    {
        return $this->definitions[$code] ?? null;
    }

    /**
     * Every declaration, by code, in declaration order.
     *
     * @return array<string, FeatureFlagDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * The declared codes, in declaration order.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->definitions);
    }

    /** Number of declared flags. */
    public function count(): int
    {
        return count($this->definitions);
    }
}
