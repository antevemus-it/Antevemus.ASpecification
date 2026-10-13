<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Antevemus\ASpecification\Contracts\ISpecification;
use InvalidArgumentException;

/**
 * FeatureFlagDefinition - A Feature Flag Declared in Code
 *
 * The declaration of one flag (forward 034, D2): its code, what a settings screen shows (name,
 * description, category, audience), what the confirmation says when turning it on and off
 * (impactOn, impactOff) and where it came from (origin: the task, ticket or release that created
 * it). Declared once, in a FeatureFlagRegistry: the database holds only the switch, the validity
 * window and the subject list, never the texts the code relies on.
 *
 * Targeting (D6), both optional and combined with AND:
 * - $target: a specification in code, evaluated against the subject (`Spec::...` over the tenant,
 *   the customer, the contract); a subject that does not satisfy it gets the flag off
 * - $subjectKey: the property path of the subject key (shared accessor notation of
 *   `antevemus/adeep-property-access`, e.g. `unit_id` or `unit.id`), read from the subject to match
 *   the subject list stored with the flag (the pilot "only these two agencies", without a deploy)
 *
 * The code follows `^[a-z][a-z0-9_]{2,79}$`: a lowercase letter, then 2 to 79 lowercase letters,
 * digits or underscores (the CHECK used by the first adopter's table).
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagDefinition
{
    /** Format of a flag code. */
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{2,79}$/';

    /** Keys accepted by fromArray(), in snake_case. */
    public const ARRAY_KEYS = [
        'name', 'description', 'category', 'audience', 'impact_on', 'impact_off', 'origin', 'target', 'subject_key',
    ];

    /**
     * @param string $code Flag code (CODE_PATTERN)
     * @param string $name Name shown to people (not empty)
     * @param string $description What the flag turns on
     * @param string $category Free text grouping of the application (e.g. 'external_notification')
     * @param string $audience Who is affected when it is on
     * @param string $impactOn What happens when it is turned on (confirmation text)
     * @param string $impactOff What happens when it is turned off (confirmation text)
     * @param string $origin Where it came from (task, ticket, release)
     * @param ISpecification|null $target Specification the subject must satisfy (null = no restriction)
     * @param string|null $subjectKey Property path of the subject key matched against the subject list
     * @throws InvalidArgumentException When the code is not valid, the name is empty or the subject key is blank
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $category = '',
        public readonly string $audience = '',
        public readonly string $impactOn = '',
        public readonly string $impactOff = '',
        public readonly string $origin = '',
        public readonly ?ISpecification $target = null,
        public readonly ?string $subjectKey = null
    ) {
        self::assertCode($code);
        if (trim($name) === '') {
            throw new InvalidArgumentException(sprintf('Feature flag "%s" needs a name.', $code));
        }
        if ($subjectKey !== null && trim($subjectKey) === '') {
            throw new InvalidArgumentException(sprintf('Feature flag "%s": the subject key path cannot be blank.', $code));
        }
    }

    /**
     * Builds a definition from an array entry such as `REGISTRY[$code]` of an application
     * (keys in snake_case: name, description, category, audience, impact_on, impact_off, origin,
     * target, subject_key). An unknown key is refused, so that a typo never silently drops a text.
     *
     * @param string $code Flag code
     * @param array<string, mixed> $entry Entry of the application registry
     * @return self
     * @throws InvalidArgumentException When a key is unknown or a value has the wrong type
     */
    public static function fromArray(string $code, array $entry): self
    {
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, self::ARRAY_KEYS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Feature flag "%s": unknown key "%s". Expected: %s.',
                    $code,
                    (string) $key,
                    implode(', ', self::ARRAY_KEYS)
                ));
            }
        }
        $text = static function (string $key) use ($code, $entry): string {
            $value = $entry[$key] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('Feature flag "%s": "%s" must be a string.', $code, $key));
            }

            return $value;
        };
        $target = $entry['target'] ?? null;
        if ($target !== null && !$target instanceof ISpecification) {
            throw new InvalidArgumentException(sprintf('Feature flag "%s": "target" must be a specification.', $code));
        }
        $subjectKey = $entry['subject_key'] ?? null;
        if ($subjectKey !== null && !is_string($subjectKey)) {
            throw new InvalidArgumentException(sprintf('Feature flag "%s": "subject_key" must be a string.', $code));
        }

        return new self(
            $code,
            $text('name'),
            $text('description'),
            $text('category'),
            $text('audience'),
            $text('impact_on'),
            $text('impact_off'),
            $text('origin'),
            $target,
            $subjectKey
        );
    }

    /** True when the code follows CODE_PATTERN. */
    public static function isValidCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    /**
     * @throws InvalidArgumentException When the code does not follow CODE_PATTERN
     */
    public static function assertCode(string $code): void
    {
        if (!self::isValidCode($code)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid feature flag code "%s": expected a lowercase letter followed by 2 to 79 lowercase letters, digits or underscores.',
                $code
            ));
        }
    }
}
