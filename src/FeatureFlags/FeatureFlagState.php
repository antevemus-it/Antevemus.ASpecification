<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * FeatureFlagState - What the Store Holds for One Flag
 *
 * One row of the flag table as read by an IFeatureFlagStore (forward 034, D3 and D4): the switch,
 * the validity window, who turned it on and off last, the subject list (D6), the rollout percentage
 * (forward 040, D4: null = no rollout) and the optimistic version that the changes of the window and of
 * the rollout compare against (forward 040, D1). The texts of the
 * row (name, description, category, audience) are only shown when the flag is not declared in code;
 * a declared flag always shows the texts of its FeatureFlagDefinition (D11).
 *
 * Instants are read in the time zone of the store. A validity bound stored as a date only covers the
 * whole day: `validFrom` is the first instant of that day, `validUntil` the last one, so both bounds
 * are inclusive, as in the rule catalog.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagState
{
    /** @var list<string> */
    public readonly array $subjects;

    /**
     * @param string $code Flag code
     * @param bool $enabled The switch (the row is born off)
     * @param string|null $name Name stored with the row
     * @param string|null $description Description stored with the row
     * @param string|null $category Category stored with the row
     * @param string|null $audience Audience stored with the row
     * @param string|null $notes Free notes of the row
     * @param DateTimeImmutable|null $validFrom First instant the flag may be on (null = open)
     * @param DateTimeImmutable|null $validUntil Last instant the flag may be on (null = open)
     * @param DateTimeImmutable|null $enabledAt Last time it was turned on
     * @param string|null $enabledBy Who turned it on last (null = unknown or a script)
     * @param DateTimeImmutable|null $disabledAt Last time it was turned off
     * @param string|null $disabledBy Who turned it off last
     * @param int|null $version Optimistic version, when the table has one
     * @param list<string|int> $subjects Subject keys of the subject list (empty = no list restriction)
     * @param int|null $rolloutPercent Rollout percentage, 0 to 100 (null = no rollout: every subject)
     * @throws InvalidArgumentException When the rollout percentage is outside 0..100
     */
    public function __construct(
        public readonly string $code,
        public readonly bool $enabled = false,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $category = null,
        public readonly ?string $audience = null,
        public readonly ?string $notes = null,
        public readonly ?DateTimeImmutable $validFrom = null,
        public readonly ?DateTimeImmutable $validUntil = null,
        public readonly ?DateTimeImmutable $enabledAt = null,
        public readonly ?string $enabledBy = null,
        public readonly ?DateTimeImmutable $disabledAt = null,
        public readonly ?string $disabledBy = null,
        public readonly ?int $version = null,
        array $subjects = [],
        public readonly ?int $rolloutPercent = null
    ) {
        if ($rolloutPercent !== null && ($rolloutPercent < 0 || $rolloutPercent > 100)) {
            throw new InvalidArgumentException(sprintf(
                'The rollout percentage of feature flag "%s" must be between 0 and 100, %d given.',
                $code,
                $rolloutPercent
            ));
        }
        $keys = array_values(array_unique(array_map(static fn(string|int $key): string => (string) $key, $subjects)));
        sort($keys, SORT_STRING);
        $this->subjects = $keys;
    }

    /** True when the subject key is in the subject list. */
    public function hasSubject(string $subjectKey): bool
    {
        return in_array($subjectKey, $this->subjects, true);
    }

    /** True when the instant lies inside the validity window (both bounds inclusive). */
    public function isValidAt(DateTimeInterface $instant): bool
    {
        return ($this->validFrom === null || $instant >= $this->validFrom)
            && ($this->validUntil === null || $instant <= $this->validUntil);
    }

    /**
     * The most recent of the last "on" and the last "off": `[instant, actor]`, or `[null, null]` when
     * the flag was never switched. On the same instant the current state wins.
     *
     * @return array{0: DateTimeImmutable|null, 1: string|null}
     */
    public function lastChange(): array
    {
        if ($this->enabledAt === null && $this->disabledAt === null) {
            return [null, null];
        }
        if ($this->disabledAt === null
            || ($this->enabledAt !== null && ($this->enabledAt > $this->disabledAt || ($this->enabledAt == $this->disabledAt && $this->enabled)))) {
            return [$this->enabledAt, $this->enabledBy];
        }

        return [$this->disabledAt, $this->disabledBy];
    }
}
