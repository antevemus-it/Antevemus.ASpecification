<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagStoreException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\InvalidFeatureFlagReasonException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\StaleFeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;
use Throwable;

/**
 * FeatureFlags - Declared Feature Flags With Targeting and an Audit Trail
 *
 * The feature flag service (forward 034, marco 24): a FeatureFlagRegistry (what the code declares) over
 * an IFeatureFlagStore (what the database holds). Every flag is born off.
 *
 * Reading (D6, D7, D9, D10), never throws:
 * - isEnabled($code, $subject) / evaluate($code, $subject): on = the switch is on AND the reference
 *   instant is inside the validity window AND the subject is in the target. The target is the subject
 *   list stored with the flag (empty = no restriction), matched by the subject key read from the
 *   subject through the declared path (`subjectKey`, shared accessor `antevemus/adeep-property-access`;
 *   a string or integer subject is the key itself), AND the specification declared in code (none = no
 *   restriction). With a target, a null subject is not in it
 * - an undeclared code is off (UNKNOWN_FLAG), whatever the database says: nothing in the code reads it
 * - fail closed: when the store cannot be read (no table, no connection) or the target cannot be
 *   evaluated, the flag is off with the reason ERROR and the failure goes to the error reporter, once
 *   per load (lastError() keeps it for a settings screen)
 * - one load per instance: the state of every flag (and the subject lists) is read on the first
 *   evaluation and kept; refresh() reads again. A change made through this instance refreshes it.
 *   When a change runs inside a transaction of the caller that is later rolled back, call refresh()
 * - spec($code): a FeatureEnabledSpecification over the subject, composable with any specification
 *
 * Writing (D5, D6): toggle(), addSubject(), removeSubject(), each in one transaction of the store with
 * its history row:
 * - the reason is required: whitespace runs collapsed, the ends trimmed, between the minimum and the
 *   maximum length (10 and 1000 characters by default); the origin is the caller's text (ORIGIN_*)
 * - compare-and-set: turning on expects off, turning off expects on, adding a subject expects it absent,
 *   removing one expects it present; otherwise StaleFeatureFlagException and nothing is written
 * - turning on (or adding a subject to) an undeclared flag is refused (UndeclaredFeatureFlagException):
 *   it would have no effect; turning off and removing subjects stay allowed (cleanup of an orphan row)
 * - a declared flag without a row gets one, off, on its first change (the history row then says
 *   `oldEnabled = null`: it did not exist)
 * - who may change a flag is a decision of the application (its roles): the service only records the
 *   actor it is given
 *
 * Listing (D11): all() is the union of the registry and the store (the texts of the code win; a flag
 * only in the store is listed as not declared); history() is the trail, most recent first.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlags
{
    /** Origin of a change made on a screen. */
    public const ORIGIN_UI = 'ui';

    /** Origin of a change made by a migration. */
    public const ORIGIN_MIGRATION = 'migration';

    /** Origin of a change made by a script or a job. */
    public const ORIGIN_SCRIPT = 'script';

    /** Default minimum length of a reason, in characters. */
    public const DEFAULT_REASON_MIN = 10;

    /** Default maximum length of a reason, in characters. */
    public const DEFAULT_REASON_MAX = 1000;

    /** Default number of history rows returned. */
    public const DEFAULT_HISTORY_LIMIT = 50;

    private readonly Closure $clock;

    /** @var array<string, FeatureFlagState>|null Loaded state (null = not loaded) */
    private ?array $states = null;

    private ?Throwable $loadError = null;

    private ?DateTimeImmutable $asOf = null;

    /**
     * @param FeatureFlagRegistry $registry The declared flags
     * @param IFeatureFlagStore $store Where their state lives
     * @param IFeatureFlagErrorReporter|null $errors Where read failures go (null = discarded; evaluate() still carries them)
     * @param (Closure(): DateTimeInterface)|null $clock Instant of changes and of the validity check (null = system clock)
     * @param int $reasonMinLength Minimum length of a reason, in characters (>= 1)
     * @param int $reasonMaxLength Maximum length of a reason, in characters (>= minimum)
     * @throws InvalidArgumentException When the reason bounds are not valid
     */
    public function __construct(
        private readonly FeatureFlagRegistry $registry,
        private readonly IFeatureFlagStore $store,
        private readonly ?IFeatureFlagErrorReporter $errors = null,
        ?Closure $clock = null,
        private readonly int $reasonMinLength = self::DEFAULT_REASON_MIN,
        private readonly int $reasonMaxLength = self::DEFAULT_REASON_MAX
    ) {
        if ($reasonMinLength < 1 || $reasonMaxLength < $reasonMinLength) {
            throw new InvalidArgumentException(sprintf(
                'Invalid reason bounds: minimum %d, maximum %d (the minimum must be at least 1 and not above the maximum).',
                $reasonMinLength,
                $reasonMaxLength
            ));
        }
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /** The declared flags. */
    public function getRegistry(): FeatureFlagRegistry
    {
        return $this->registry;
    }

    /** The store. */
    public function getStore(): IFeatureFlagStore
    {
        return $this->store;
    }

    /**
     * Returns a copy that checks the validity window at $asOf instead of the clock (the state already
     * loaded is shared by value; the original instance is left untouched).
     *
     * @return static
     */
    public function withAsOf(DateTimeInterface $asOf): static
    {
        $clone = clone $this;
        $clone->asOf = DateTimeImmutable::createFromInterface($asOf);

        return $clone;
    }

    /** The reference instant of the validity check, or null when it is the clock. */
    public function getAsOf(): ?DateTimeImmutable
    {
        return $this->asOf;
    }

    /** True when the flag is on for the subject (never throws). */
    public function isEnabled(string $code, mixed $subject = null): bool
    {
        return $this->evaluate($code, $subject)->value;
    }

    /**
     * The value of the flag for the subject, with its reason (never throws).
     */
    public function evaluate(string $code, mixed $subject = null): FeatureFlagEvaluation
    {
        $definition = $this->registry->get($code);
        if ($definition === null) {
            return new FeatureFlagEvaluation($code, FeatureFlagReason::UNKNOWN_FLAG);
        }

        $states = $this->load($code);
        if ($states === null) {
            return new FeatureFlagEvaluation($code, FeatureFlagReason::ERROR, null, $this->loadError);
        }

        $state = $states[$code] ?? null;
        if ($state === null || !$state->enabled) {
            return new FeatureFlagEvaluation($code, FeatureFlagReason::DISABLED);
        }
        if (!$state->isValidAt($this->asOf ?? DateTimeImmutable::createFromInterface(($this->clock)()))) {
            return new FeatureFlagEvaluation($code, FeatureFlagReason::OUTSIDE_VALIDITY);
        }

        $listed = $state->subjects !== [];
        if (!$listed && $definition->target === null) {
            return new FeatureFlagEvaluation($code, FeatureFlagReason::ENABLED);
        }

        try {
            $subjectKey = $this->subjectKey($definition, $subject);
        } catch (Throwable $e) {
            $this->report($code, $e);

            return new FeatureFlagEvaluation($code, FeatureFlagReason::ERROR, null, $e);
        }
        if ($subject === null || ($listed && ($subjectKey === null || !$state->hasSubject($subjectKey)))) {
            return new FeatureFlagEvaluation($code, FeatureFlagReason::NOT_IN_TARGET, $subjectKey);
        }

        if ($definition->target !== null) {
            try {
                $result = $definition->target->evaluate($subject);
                $error = $result->isError
                    ? ($result->exception ?? new FeatureFlagException(sprintf('The target of feature flag "%s" could not be evaluated.', $code)))
                    : null;
            } catch (Throwable $e) {
                $result = null;
                $error = $e;
            }
            if ($error !== null || $result === null) {
                $error ??= new FeatureFlagException(sprintf('The target of feature flag "%s" could not be evaluated.', $code));
                $this->report($code, $error);

                return new FeatureFlagEvaluation($code, FeatureFlagReason::ERROR, $subjectKey, $error);
            }
            if (!$result->isSatisfied) {
                return new FeatureFlagEvaluation($code, FeatureFlagReason::NOT_IN_TARGET, $subjectKey);
            }
        }

        return new FeatureFlagEvaluation($code, FeatureFlagReason::TARGETING_MATCH, $subjectKey);
    }

    /**
     * A specification satisfied by the subjects for which the flag is on:
     * `$flags->spec('renewal_notice')->and($other)`.
     */
    public function spec(string $code): FeatureEnabledSpecification
    {
        return new FeatureEnabledSpecification($this, $code);
    }

    /**
     * Reads the state of every flag again on the next evaluation (and forgets a load failure).
     */
    public function refresh(): void
    {
        $this->states = null;
        $this->loadError = null;
    }

    /** The failure of the last load, or null (a settings screen can warn that every flag is off). */
    public function lastError(): ?Throwable
    {
        $this->load(null);

        return $this->loadError;
    }

    /**
     * Turns a flag on or off, with a reason, in one transaction with its history row.
     *
     * @param string $code Flag code
     * @param bool $enable True to turn it on, false to turn it off
     * @param string $reason Why (normalized; between the minimum and the maximum length)
     * @param string|int|null $actor Who (null = a script without a user)
     * @param string $origin Where the change came from (ORIGIN_* or the application's text)
     * @return FeatureFlagState The stored state after the change
     * @throws InvalidArgumentException When the code or the origin is not valid
     * @throws InvalidFeatureFlagReasonException When the reason is too short or too long
     * @throws UndeclaredFeatureFlagException When turning on a flag that is not declared
     * @throws StaleFeatureFlagException When the flag is already in the requested state
     * @throws FeatureFlagException When an undeclared flag has no row to turn off, or the store fails
     */
    public function toggle(string $code, bool $enable, string $reason, string|int|null $actor = null, string $origin = self::ORIGIN_UI): FeatureFlagState
    {
        FeatureFlagDefinition::assertCode($code);
        $definition = $this->registry->get($code);
        if ($enable && $definition === null) {
            throw new UndeclaredFeatureFlagException($code, sprintf(
                'Feature flag "%s" is not declared in code: turning it on would have no effect.',
                $code
            ));
        }
        $reason = $this->normalizeReason($reason);
        $origin = self::assertOrigin($origin);
        $at = $this->now();
        $by = $actor === null ? null : (string) $actor;

        try {
            $this->store->getTransactionManager()->transactional(function () use ($code, $enable, $reason, $actor, $origin, $at, $by, $definition): void {
                $created = $definition !== null && $this->store->insertIfAbsent($definition, $at, $actor);
                if (!$this->store->compareAndSetEnabled($code, !$enable, $enable, $at, $actor)) {
                    if (!$created && $definition === null && $this->store->find($code) === null) {
                        throw new FeatureFlagException(sprintf('Feature flag "%s" is neither declared nor stored.', $code));
                    }
                    throw new StaleFeatureFlagException($code, sprintf(
                        'Feature flag "%s" is already %s (someone else may have changed it): reload and try again.',
                        $code,
                        $enable ? 'on' : 'off'
                    ));
                }
                $this->store->appendChange(new FeatureFlagChange($code, $created ? null : !$enable, $enable, $reason, $origin, $at, $by));
            });
        } finally {
            $this->refresh();
        }

        return $this->store->find($code) ?? throw new FeatureFlagStoreException(sprintf('Feature flag "%s" disappeared after its change.', $code));
    }

    /**
     * Adds a subject to the subject list of a declared flag (the pilot: on only for these subjects),
     * with a reason, in one transaction with its history row. The flag gets its row (off) when it has
     * none. Adding the first subject restricts a flag that was on for everyone.
     *
     * @param string|int $subjectKey Subject key (as read from the subject through the declared path)
     * @throws InvalidArgumentException When the code, the subject key or the origin is not valid
     * @throws InvalidFeatureFlagReasonException When the reason is too short or too long
     * @throws UndeclaredFeatureFlagException When the flag is not declared
     * @throws StaleFeatureFlagException When the subject is already in the list
     * @throws FeatureFlagException When the store keeps no subject lists, or fails
     */
    public function addSubject(string $code, string|int $subjectKey, string $reason, string|int|null $actor = null, string $origin = self::ORIGIN_UI): void
    {
        FeatureFlagDefinition::assertCode($code);
        $definition = $this->registry->get($code);
        if ($definition === null) {
            throw new UndeclaredFeatureFlagException($code, sprintf(
                'Feature flag "%s" is not declared in code: targeting it would have no effect.',
                $code
            ));
        }
        [$key, $reason, $origin, $at, $by] = $this->prepareSubjectChange($subjectKey, $reason, $origin, $actor);

        try {
            $this->store->getTransactionManager()->transactional(function () use ($code, $key, $reason, $origin, $at, $by, $actor, $definition): void {
                $this->store->insertIfAbsent($definition, $at, $actor);
                if (!$this->store->addSubject($code, $key)) {
                    throw new StaleFeatureFlagException($code, sprintf(
                        'Subject "%s" is already in the list of feature flag "%s" (someone else may have changed it): reload and try again.',
                        $key,
                        $code
                    ));
                }
                $this->store->appendChange(new FeatureFlagChange($code, false, true, $reason, $origin, $at, $by, $key));
            });
        } finally {
            $this->refresh();
        }
    }

    /**
     * Removes a subject from the subject list of a flag (declared or not), with a reason, in one
     * transaction with its history row. Removing the last subject lifts the list restriction.
     *
     * @param string|int $subjectKey Subject key
     * @throws InvalidArgumentException When the code, the subject key or the origin is not valid
     * @throws InvalidFeatureFlagReasonException When the reason is too short or too long
     * @throws StaleFeatureFlagException When the subject is not in the list
     * @throws FeatureFlagException When the store keeps no subject lists, or fails
     */
    public function removeSubject(string $code, string|int $subjectKey, string $reason, string|int|null $actor = null, string $origin = self::ORIGIN_UI): void
    {
        FeatureFlagDefinition::assertCode($code);
        [$key, $reason, $origin, $at, $by] = $this->prepareSubjectChange($subjectKey, $reason, $origin, $actor);

        try {
            $this->store->getTransactionManager()->transactional(function () use ($code, $key, $reason, $origin, $at, $by): void {
                if (!$this->store->removeSubject($code, $key)) {
                    throw new StaleFeatureFlagException($code, sprintf(
                        'Subject "%s" is not in the list of feature flag "%s" (someone else may have changed it): reload and try again.',
                        $key,
                        $code
                    ));
                }
                $this->store->appendChange(new FeatureFlagChange($code, true, false, $reason, $origin, $at, $by, $key));
            });
        } finally {
            $this->refresh();
        }
    }

    /**
     * Every flag: the declared ones in declaration order, then the ones found only in the store (not
     * declared), by code. When the store cannot be read, the declared flags are listed off (see
     * lastError()).
     *
     * @return list<FeatureFlagView>
     */
    public function all(): array
    {
        $states = $this->load(null) ?? [];
        $views = [];
        foreach ($this->registry->all() as $code => $definition) {
            $views[] = new FeatureFlagView($code, $definition, $states[$code] ?? null);
        }
        $undeclared = array_diff_key($states, $this->registry->all());
        ksort($undeclared, SORT_STRING);
        foreach ($undeclared as $code => $state) {
            $views[] = new FeatureFlagView((string) $code, null, $state);
        }

        return $views;
    }

    /**
     * The change history, most recent first, of one flag or of every flag.
     *
     * @param string|null $code Flag code (null = every flag)
     * @param int $limit Maximum number of rows (>= 1)
     * @return list<FeatureFlagChange>
     * @throws FeatureFlagStoreException When the store fails
     */
    public function history(?string $code = null, int $limit = self::DEFAULT_HISTORY_LIMIT): array
    {
        return $this->store->history($code, $limit);
    }

    /**
     * Normalizes a reason: whitespace runs collapsed to one space, the ends trimmed, length checked.
     *
     * @throws InvalidFeatureFlagReasonException When it is too short or too long
     */
    public function normalizeReason(string $reason): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $length = mb_strlen($normalized);
        if ($length < $this->reasonMinLength) {
            throw new InvalidFeatureFlagReasonException(sprintf(
                'Give the reason of the change (at least %d characters).',
                $this->reasonMinLength
            ));
        }
        if ($length > $this->reasonMaxLength) {
            throw new InvalidFeatureFlagReasonException(sprintf(
                'The reason is too long (at most %d characters).',
                $this->reasonMaxLength
            ));
        }

        return $normalized;
    }

    /**
     * The loaded state, loading it when needed; null when the load failed (reported once).
     *
     * @return array<string, FeatureFlagState>|null
     */
    private function load(?string $code): ?array
    {
        if ($this->states !== null) {
            return $this->states;
        }
        if ($this->loadError !== null) {
            return null;
        }
        try {
            $this->states = $this->store->loadAll();
        } catch (Throwable $e) {
            $this->loadError = $e;
            $this->report($code, $e);

            return null;
        }

        return $this->states;
    }

    /**
     * The subject key: a string or integer subject is the key itself; otherwise the declared path is read
     * from the subject (null when the flag declares no path, or the path is missing or holds null).
     */
    private function subjectKey(FeatureFlagDefinition $definition, mixed $subject): ?string
    {
        if (is_string($subject) || is_int($subject)) {
            return (string) $subject;
        }
        if ($definition->subjectKey === null || (!is_object($subject) && !is_array($subject))) {
            return null;
        }
        if (!PropertyAccess::hasProperty($subject, $definition->subjectKey)) {
            return null;
        }
        $value = PropertyAccess::getValue($subject, $definition->subjectKey);
        if ($value === null) {
            return null;
        }
        if (is_string($value) || is_int($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new FeatureFlagException(sprintf(
            'The subject key "%s" of feature flag "%s" holds a %s, not a string or an integer.',
            $definition->subjectKey,
            $definition->code,
            get_debug_type($value)
        ));
    }

    /**
     * Validates the common arguments of a subject change.
     *
     * @return array{0: string, 1: string, 2: string, 3: DateTimeImmutable, 4: string|null}
     */
    private function prepareSubjectChange(string|int $subjectKey, string $reason, string $origin, string|int|null $actor): array
    {
        $key = trim((string) $subjectKey);
        if ($key === '') {
            throw new InvalidArgumentException('The subject key cannot be empty.');
        }

        return [$key, $this->normalizeReason($reason), self::assertOrigin($origin), $this->now(), $actor === null ? null : (string) $actor];
    }

    /**
     * @throws InvalidArgumentException When the origin is empty
     */
    private static function assertOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === '') {
            throw new InvalidArgumentException('The origin of a change cannot be empty.');
        }

        return $origin;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(($this->clock)());
    }

    /**
     * Hands a failure to the reporter; a failing reporter is ignored.
     */
    private function report(?string $code, Throwable $error): void
    {
        if ($this->errors === null) {
            return;
        }
        try {
            $this->errors->report($code, $error);
        } catch (Throwable) {
            // A broken reporter never changes the value of a flag
        }
    }
}
