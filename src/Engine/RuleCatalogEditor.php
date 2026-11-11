<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalogStore;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\Exceptions\InvalidRuleChangeReasonException;
use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogDriftException;
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogStoreException;
use Antevemus\ASpecification\Engine\Exceptions\RuleChangeRefusedException;
use Antevemus\ASpecification\Engine\Exceptions\RuleNotFoundException;
use Antevemus\ASpecification\Engine\Exceptions\StaleRuleChangeException;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Util\UlidGenerator;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * RuleCatalogEditor - Audited, Versioned Writes to the Rule Catalog
 *
 * The write path of the rule catalog (2.13.0, forward 060), in the shape of the audited path of the feature flags
 * (FeatureFlags::toggle(), setValidity()): every change of a rule makes a new version of it, with the full photo of
 * the rule, its hash and the reason, in one transaction of the store (IRuleCatalogStore: PdoRuleCatalogStore over
 * the table PdoRuleCatalog reads, or InMemoryRuleCatalogStore). "Version 3 of rule R" is then one row of the
 * history that an analysis or a quotation can cite, and a DynamicSpecificationEngine over a catalog that reads the
 * version (PdoRuleCatalog::COLUMNS_SINCE_2_13) stamps it on every failure (`rule_version`) and lists the rules it
 * compiled (getCompiledRules()).
 *
 * Writing (D1 to D8), each in one transaction with its history row (RN-02):
 * - create($row, ...): version 1 of a new rule; update($code, $changes, ...): the given columns merged over the
 *   current row; setActive($code, $active, ...): a rule enters or leaves use. There is no deletion: a rule leaves
 *   use through setActive(false) and its history stays
 * - the row uses the canonical keys of RuleDefinition::fromArray() (RuleSnapshot::COLUMNS without `version`);
 *   an unknown key, a Portuguese key of 1.x, a value of the wrong type, a changed `code`, `active` given to
 *   update() (setActive() changes it) or `version` given at all is an InvalidArgumentException, whose message names
 *   the rule and the column and never repeats the value (RN-05)
 * - compare-and-set of the version (D3, RN-04): the version read inside the transaction, or the one the caller's
 *   screen read (`$expectedVersion`); another change committed in between, a change that leaves the row as it is
 *   and a rule already in the requested state are StaleRuleChangeException, and nothing is written
 * - the reason is required, under the rules of the feature flags (D7): whitespace runs collapsed, the ends trimmed,
 *   between the minimum and the maximum length (10 and 1000 characters by default); the origin is the caller's text
 *   (ORIGIN_*); the actor is recorded as given (who may change a rule is a decision of the application)
 * - a rule that existed before the migration has no history: its first change through the editor writes, in the
 *   same transaction and before the row of the change, a `baseline` row with the photo of the current version
 *   (D6), so that every version from then on has its photo (RN-03); the same when the version of the row was raised
 *   outside the editor. adopt() writes those baselines without changing anything
 * - the change guard (D8): withChangeGuard($specification) evaluates it over a RuleChangeCandidate inside the
 *   transaction, before writing; not satisfied, or not evaluable, the change is refused
 *   (RuleChangeRefusedException) and nothing is written, baseline included
 * - a rule whose row was changed outside the editor at its version (RuleDriftKind::Modified) is not changed: its
 *   row is not the photo of the version it would build on (RuleCatalogDriftException)
 *
 * Reading: current() (the last revision of a rule), revision() (one version), history() (most recent first) and
 * findDrift() (D11): every rule whose row is not the photo of the last version of its history, the portable safety
 * net against an UPDATE by hand (a trigger that forbids it is a choice of the application: it is not portable).
 *
 * The store must have the version column and the history table (the additive migration 2.13.0 in the header of
 * resources/sql/rule_catalog.ansi.sql): every call checks it first (IRuleCatalogStore::assertReady()). Without the
 * migration and without the editor nothing of the library changes (RN-01).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RuleCatalogEditor
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

    /** The columns a row given to create() may carry (every column of the photo but `version`). */
    public const INPUT_COLUMNS = [
        'active', 'code', 'condition_expression', 'decimal_value', 'description', 'int_value', 'legal_basis', 'name',
        'parameters', 'plan_code', 'priority', 'product_code', 'rule_type', 'scenario', 'scope', 'spec_json',
        'text_value', 'valid_from', 'valid_until', 'violation_action', 'violation_message',
    ];

    private readonly Closure $clock;

    private ?ISpecification $guard = null;

    /**
     * @param IRuleCatalogStore $store Where the rows and the history live
     * @param (Closure(): DateTimeInterface)|null $clock Instant of the changes (null = system clock)
     * @param int $reasonMinLength Minimum length of a reason, in characters (>= 1)
     * @param int $reasonMaxLength Maximum length of a reason, in characters (>= minimum)
     * @throws InvalidArgumentException When the reason bounds are not valid
     */
    public function __construct(
        private readonly IRuleCatalogStore $store,
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

    /** Where the rows and the history live. */
    public function getStore(): IRuleCatalogStore
    {
        return $this->store;
    }

    /**
     * A copy whose changes are evaluated by $guard (a specification over RuleChangeCandidate) before they are
     * written; it replaces the guard of this instance, if any (compose several with and()). The original is left
     * untouched.
     */
    public function withChangeGuard(ISpecification $guard): static
    {
        $clone = clone $this;
        $clone->guard = $guard;

        return $clone;
    }

    /** The change guard, or null. */
    public function getChangeGuard(): ?ISpecification
    {
        return $this->guard;
    }

    /**
     * Creates a rule: version 1, with its photo in the history.
     *
     * @param array<string, mixed> $row Canonical columns (INPUT_COLUMNS); `code`, `name` and `rule_type` required;
     *                                  absent columns are NULL, `violation_action` block, `priority` 0, `active` true
     * @param string $reason Why (normalized; between the minimum and the maximum length)
     * @param string|int|null $actor Who (null = a script without a user)
     * @param string $origin Where the change came from (ORIGIN_* or the application's text)
     * @return RuleRevision Version 1
     * @throws InvalidArgumentException When a column, a value or the origin is not valid
     * @throws InvalidRuleChangeReasonException When the reason is too short or too long
     * @throws StaleRuleChangeException When a rule with the code exists
     * @throws RuleCatalogDriftException When the history has the code but the table does not (a DELETE by hand)
     * @throws RuleChangeRefusedException When the change guard refuses it
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function create(array $row, string $reason, string|int|null $actor = null, string $origin = self::ORIGIN_UI): RuleRevision
    {
        $code = self::assertCode($row['code'] ?? null);
        // An absent violation_action is read as block and an absent priority as 0, as the catalog reads them
        // (RuleSnapshot::fromStored())
        $values = $this->input($code, $row, true) + [
            'active' => true,
            'version' => 1,
        ];
        foreach (RuleSnapshot::COLUMNS as $column) {
            $values[$column] ??= null;
        }
        self::assertConsistent($code, $values);
        [$reason, $origin, $at, $by] = $this->prepare($reason, $origin, $actor);
        $this->store->assertReady();

        return $this->store->getTransactionManager()->transactional(function () use ($code, $values, $reason, $origin, $at, $by, $actor): RuleRevision {
            if ($this->store->findRow($code) !== null) {
                throw new StaleRuleChangeException(sprintf('Rule "%s" already exists (someone else may have created it): reload and change it instead.', $code), $code);
            }
            $last = $this->store->lastRevisions($code)[$code] ?? null;
            if ($last !== null) {
                throw new RuleCatalogDriftException(
                    new RuleDrift($code, RuleDriftKind::Deleted, null, $last->version),
                    sprintf('Rule "%s" has a history up to version %d but no row (it was deleted outside the editor): it cannot be created again with the same code.', $code, $last->version)
                );
            }
            $after = RuleSnapshot::fromStored($values);
            $this->evaluateGuard(new RuleChangeCandidate(RuleChangeKind::Create, $code, null, RuleSnapshot::definition($after), null, 1, [], $actor, $origin, $reason));
            if (!$this->store->insertRow($after)) {
                throw new StaleRuleChangeException(sprintf('Rule "%s" already exists (someone else may have created it): reload and change it instead.', $code), $code);
            }

            return $this->record($this->stored($code), RuleChangeKind::Create, [], $reason, $origin, $at, $by);
        });
    }

    /**
     * Changes columns of a rule: the given columns merged over the current row make version N + 1.
     *
     * @param string $code Rule code
     * @param array<string, mixed> $changes Canonical columns to change (INPUT_COLUMNS but `active`; `code` only unchanged)
     * @param string $reason Why (normalized; between the minimum and the maximum length)
     * @param string|int|null $actor Who (null = a script without a user)
     * @param string $origin Where the change came from (ORIGIN_* or the application's text)
     * @param int|null $expectedVersion Version the caller read (null = the one read in the transaction)
     * @return RuleRevision The new version
     * @throws InvalidArgumentException When a column, a value or the origin is not valid
     * @throws InvalidRuleChangeReasonException When the reason is too short or too long
     * @throws RuleNotFoundException When there is no rule with the code
     * @throws StaleRuleChangeException When the version is not the expected one, or the change leaves the row as it is
     * @throws RuleCatalogDriftException When the row was changed outside the editor at its version
     * @throws RuleChangeRefusedException When the change guard refuses it
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function update(
        string $code,
        array $changes,
        string $reason,
        string|int|null $actor = null,
        string $origin = self::ORIGIN_UI,
        ?int $expectedVersion = null
    ): RuleRevision {
        self::assertCode($code);
        if (array_key_exists('active', $changes)) {
            throw new InvalidArgumentException(sprintf('Rule "%s": column active is changed by setActive(), not by update().', $code));
        }
        if (array_key_exists('code', $changes) && $changes['code'] !== $code) {
            throw new InvalidArgumentException(sprintf('Rule "%s": the code of a rule cannot be changed.', $code));
        }
        unset($changes['code']);
        $values = $this->input($code, $changes, false);

        return $this->change($code, $values, RuleChangeKind::Update, $reason, $actor, $origin, $expectedVersion);
    }

    /**
     * Puts a rule in use (true) or out of use (false): version N + 1, the history keeps every version.
     *
     * @param string $code Rule code
     * @param bool $active True to activate it, false to deactivate it
     * @param string $reason Why (normalized; between the minimum and the maximum length)
     * @param string|int|null $actor Who (null = a script without a user)
     * @param string $origin Where the change came from (ORIGIN_* or the application's text)
     * @param int|null $expectedVersion Version the caller read (null = the one read in the transaction)
     * @return RuleRevision The new version
     * @throws InvalidArgumentException When the code or the origin is not valid
     * @throws InvalidRuleChangeReasonException When the reason is too short or too long
     * @throws RuleNotFoundException When there is no rule with the code
     * @throws StaleRuleChangeException When the version is not the expected one, or the rule is already in that state
     * @throws RuleCatalogDriftException When the row was changed outside the editor at its version
     * @throws RuleChangeRefusedException When the change guard refuses it
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function setActive(
        string $code,
        bool $active,
        string $reason,
        string|int|null $actor = null,
        string $origin = self::ORIGIN_UI,
        ?int $expectedVersion = null
    ): RuleRevision {
        self::assertCode($code);

        return $this->change($code, ['active' => $active], $active ? RuleChangeKind::Activate : RuleChangeKind::Deactivate, $reason, $actor, $origin, $expectedVersion);
    }

    /**
     * Writes the baseline of every rule whose current version has no history row (it existed before the migration,
     * or its version was raised outside the editor), or of one rule; nothing else changes (D6).
     *
     * @param string|null $code Rule code (null = every rule of the table)
     * @param string $reason Why (normalized; between the minimum and the maximum length)
     * @param string|int|null $actor Who (null = a script without a user)
     * @param string $origin Where the change came from (ORIGIN_* or the application's text)
     * @return list<RuleRevision> The baselines written, by code (empty when every rule already has its photo)
     * @throws InvalidArgumentException When the code or the origin is not valid
     * @throws InvalidRuleChangeReasonException When the reason is too short or too long
     * @throws RuleNotFoundException When a code is given and there is no rule with it
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function adopt(?string $code, string $reason, string|int|null $actor = null, string $origin = self::ORIGIN_MIGRATION): array
    {
        if ($code !== null) {
            self::assertCode($code);
        }
        [$reason, $origin, $at, $by] = $this->prepare($reason, $origin, $actor);
        $this->store->assertReady();

        return $this->store->getTransactionManager()->transactional(function () use ($code, $reason, $origin, $at, $by): array {
            $rows = $code === null ? $this->store->findRows() : [$this->existing($code)];
            $last = $this->store->lastRevisions($code);
            $written = [];
            foreach ($rows as $row) {
                if (self::drift($row, $last[$row['code']] ?? null)?->needsBaseline() !== true) {
                    continue;
                }
                $baseline = $this->revisionOf($row, RuleChangeKind::Baseline, [], $reason, $origin, $at, $by);
                if ($this->store->appendRevision($baseline)) {
                    $written[] = $baseline;
                }
            }

            return $written;
        });
    }

    /**
     * The last revision of a rule (the photo of its last version through the editor), or null when it has no
     * history. findDrift() tells whether the row is still that photo.
     *
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function current(string $code): ?RuleRevision
    {
        $this->store->assertReady();

        return $this->store->lastRevisions($code)[$code] ?? null;
    }

    /**
     * One version of a rule, or null when the history has no row for it.
     *
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function revision(string $code, int $version): ?RuleRevision
    {
        $this->store->assertReady();

        return $this->store->findRevision($code, $version);
    }

    /**
     * The history, most recent first, of one rule or of every rule.
     *
     * @param string|null $code Rule code (null = every rule)
     * @param int $limit Maximum number of rows (>= 1)
     * @return list<RuleRevision>
     * @throws InvalidArgumentException When the limit is below 1
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function history(?string $code = null, int $limit = self::DEFAULT_HISTORY_LIMIT): array
    {
        $this->store->assertReady();

        return $this->store->history($code, $limit);
    }

    /**
     * Every rule (or one) whose row is not the photo of the last version of its history (D11): no history, a
     * version above the history, a row changed at its version, or a history without a row. By code.
     *
     * @param string|null $code Rule code (null = every rule of the table and of the history)
     * @return list<RuleDrift>
     * @throws RuleCatalogStoreException When the store is not migrated or fails
     */
    public function findDrift(?string $code = null): array
    {
        $this->store->assertReady();
        if ($code === null) {
            $rows = $this->store->findRows();
        } else {
            $one = $this->store->findRow($code);
            $rows = $one === null ? [] : [$one];
        }
        $last = $this->store->lastRevisions($code);
        $drifts = [];
        foreach ($rows as $row) {
            $kind = self::drift($row, $last[$row['code']] ?? null);
            if ($kind !== null) {
                $drifts[$row['code']] = new RuleDrift($row['code'], $kind, $row['version'], ($last[$row['code']] ?? null)?->version);
            }
            unset($last[$row['code']]);
        }
        foreach ($last as $missing => $revision) {
            $drifts[$missing] = new RuleDrift((string) $missing, RuleDriftKind::Deleted, null, $revision->version);
        }
        ksort($drifts, SORT_STRING);

        return array_values($drifts);
    }

    /**
     * Normalizes a reason as FeatureFlags::normalizeReason() does: whitespace runs collapsed to one space, the
     * ends trimmed, length checked. The message never repeats the reason.
     *
     * @throws InvalidRuleChangeReasonException When it is too short or too long
     */
    public function normalizeReason(string $reason): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $length = mb_strlen($normalized);
        if ($length < $this->reasonMinLength) {
            throw new InvalidRuleChangeReasonException(sprintf('Give the reason of the change (at least %d characters).', $this->reasonMinLength));
        }
        if ($length > $this->reasonMaxLength) {
            throw new InvalidRuleChangeReasonException(sprintf('The reason is too long (at most %d characters).', $this->reasonMaxLength));
        }

        return $normalized;
    }

    /**
     * The shared path of update() and setActive(): in one transaction, reads the row, checks the expected version
     * and the drift, refuses a no-op, evaluates the guard, compares and sets by version, writes the baseline when
     * the version has no photo, and appends the history row.
     *
     * @param array<string, mixed> $changes Canonical values
     */
    private function change(
        string $code,
        array $changes,
        RuleChangeKind $kind,
        string $reason,
        string|int|null $actor,
        string $origin,
        ?int $expectedVersion
    ): RuleRevision {
        [$reason, $origin, $at, $by] = $this->prepare($reason, $origin, $actor);
        $this->store->assertReady();

        return $this->store->getTransactionManager()->transactional(function () use ($code, $changes, $kind, $reason, $actor, $origin, $at, $by, $expectedVersion): RuleRevision {
            $before = $this->existing($code);
            $version = $before['version'];
            if ($expectedVersion !== null && $expectedVersion !== $version) {
                throw new StaleRuleChangeException(sprintf('Rule "%s" changed since it was read (version %d, now %d): reload and try again.', $code, $expectedVersion, $version), $code);
            }
            $last = $this->store->lastRevisions($code)[$code] ?? null;
            $drift = self::drift($before, $last);
            if ($drift === RuleDriftKind::Modified) {
                throw new RuleCatalogDriftException(
                    // drift() answers Modified only for a rule with history: $last is not null here
                    new RuleDrift($code, $drift, $version, $last->version),
                    sprintf('Rule "%s" was changed outside the editor: its row (version %d) is not the photo of version %d of its history. Raise its version by hand to record that change, then change it here.', $code, $version, $last->version)
                );
            }
            $after = $changes + $before;
            $after['version'] = $version + 1;
            self::assertConsistent($code, $after);
            $changed = array_values(array_filter(RuleSnapshot::COLUMNS, static fn(string $c): bool => $c !== 'version' && $after[$c] !== $before[$c]));
            if ($changed === []) {
                throw new StaleRuleChangeException(match ($kind) {
                    RuleChangeKind::Activate => sprintf('Rule "%s" is already active (version %d): reload and try again.', $code, $version),
                    RuleChangeKind::Deactivate => sprintf('Rule "%s" is already inactive (version %d): reload and try again.', $code, $version),
                    default => sprintf('The change leaves rule "%s" as it is (version %d): nothing to record.', $code, $version),
                }, $code);
            }
            $this->evaluateGuard(new RuleChangeCandidate(
                $kind,
                $code,
                RuleSnapshot::definition($before),
                RuleSnapshot::definition(RuleSnapshot::fromStored($after)),
                $version,
                $version + 1,
                $changed,
                $actor,
                $origin,
                $reason
            ));
            if (!$this->store->compareAndSetRow($code, $version, array_intersect_key($after, array_flip($changed)))) {
                throw new StaleRuleChangeException(sprintf('Rule "%s" changed since it was read (version %d): reload and try again.', $code, $version), $code);
            }
            if ($drift !== null) {
                // The version that was changed had no photo: its baseline goes before the row of the change
                $this->store->appendRevision($this->revisionOf($before, RuleChangeKind::Baseline, [], $reason, $origin, $at, $by));
            }

            return $this->record($this->stored($code), $kind, $changed, $reason, $origin, $at, $by);
        });
    }

    /**
     * How a row departs from the last revision of its rule, or null when it is its photo.
     *
     * @param array<string, mixed> $row Canonical row
     */
    private static function drift(array $row, ?RuleRevision $last): ?RuleDriftKind
    {
        if ($last === null) {
            return RuleDriftKind::NoHistory;
        }
        if ($row['version'] > $last->version) {
            return RuleDriftKind::VersionAhead;
        }
        if ($row['version'] === $last->version && hash_equals($last->snapshotHash, RuleSnapshot::hash(RuleSnapshot::encode($row)))) {
            return null;
        }

        return RuleDriftKind::Modified;
    }

    /**
     * Appends the history row of a version just written.
     *
     * @param array<string, mixed> $row The row read back after the write
     * @param list<string> $changed
     * @throws RuleCatalogStoreException When the history already has that version
     */
    private function record(array $row, RuleChangeKind $kind, array $changed, string $reason, string $origin, DateTimeImmutable $at, ?string $by): RuleRevision
    {
        $revision = $this->revisionOf($row, $kind, $changed, $reason, $origin, $at, $by);
        if (!$this->store->appendRevision($revision)) {
            throw new RuleCatalogStoreException(sprintf('The history of rule "%s" already has a version %d: it was written outside the editor.', $revision->code, $revision->version), $revision->code);
        }

        return $revision;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $changed
     */
    private function revisionOf(array $row, RuleChangeKind $kind, array $changed, string $reason, string $origin, DateTimeImmutable $at, ?string $by): RuleRevision
    {
        return RuleRevision::of($row, $kind, $changed, $reason, $origin, $at, $by, UlidGenerator::shared()->generate());
    }

    /**
     * @return array<string, mixed>
     * @throws RuleNotFoundException
     */
    private function existing(string $code): array
    {
        return $this->store->findRow($code)
            ?? throw new RuleNotFoundException(sprintf('There is no rule "%s" in the catalog (active or not).', $code), $code);
    }

    /**
     * @return array<string, mixed>
     * @throws RuleCatalogStoreException
     */
    private function stored(string $code): array
    {
        return $this->store->findRow($code)
            ?? throw new RuleCatalogStoreException(sprintf('Rule "%s" disappeared after its change.', $code), $code);
    }

    /**
     * Evaluates the change guard, when there is one.
     *
     * @throws RuleChangeRefusedException When it is not satisfied or cannot be evaluated
     */
    private function evaluateGuard(RuleChangeCandidate $candidate): void
    {
        if ($this->guard === null) {
            return;
        }
        $versions = sprintf('version %s to %d', $candidate->fromVersion ?? 'none', $candidate->toVersion);
        try {
            $result = $this->guard->evaluate($candidate);
        } catch (Throwable $e) {
            throw new RuleChangeRefusedException(
                $candidate,
                SpecificationResult::error($e, 'RuleChangeGuard'),
                sprintf('The change of rule "%s" (%s) was refused: the change guard could not be evaluated.', $candidate->code, $versions),
                $e
            );
        }
        if ($result->isSatisfied) {
            return;
        }
        $codes = $result->getCodes();
        throw new RuleChangeRefusedException(
            $candidate,
            $result,
            $result->isError
                ? sprintf('The change of rule "%s" (%s) was refused: the change guard could not be evaluated.', $candidate->code, $versions)
                : sprintf('The change of rule "%s" (%s) was refused by the change guard%s.', $candidate->code, $versions, $codes === [] ? '' : ': ' . implode(', ', $codes)),
            $result->exception
        );
    }

    /**
     * The canonical values of the columns given by the caller.
     *
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    private function input(string $code, array $values, bool $create): array
    {
        $legacy = RuleDefinition::LEGACY_KEYS + array_flip(RuleDefinition::LEGACY_APPLICABILITY_COLUMNS);
        $canonical = [];
        foreach ($values as $column => $value) {
            if (isset($legacy[$column])) {
                throw new InvalidArgumentException(sprintf(
                    'Rule "%s": column "%s" is Portuguese vocabulary of the rule engine, renamed "%s" in 1.7.0 and refused since 2.0.0 (see %s).',
                    $code,
                    $column,
                    $legacy[$column],
                    LegacyVocabularyException::UPGRADE_GUIDE
                ));
            }
            if ($column === 'version') {
                throw new InvalidArgumentException(sprintf('Rule "%s": the version is kept by the editor (pass expectedVersion to change the version a screen read).', $code));
            }
            if (!in_array($column, self::INPUT_COLUMNS, true)) {
                throw new InvalidArgumentException(sprintf('Rule "%s": unknown column "%s". Expected one of: %s.', $code, $column, implode(', ', self::INPUT_COLUMNS)));
            }
            if ($column === 'spec_json' && $value !== null && !$this->store->supportsSpecJson()) {
                throw new InvalidArgumentException(sprintf('Rule "%s": the store does not keep column spec_json (map it, e.g. with PdoRuleCatalog::COLUMNS_SINCE_2_4).', $code));
            }
            $canonical[$column] = $this->inputValue($code, $column, $value);
        }
        if ($create) {
            foreach (['name', 'rule_type'] as $required) {
                if (!isset($canonical[$required])) {
                    throw new InvalidArgumentException(sprintf('Rule "%s": column %s is required.', $code, $required));
                }
            }
        }

        return $canonical;
    }

    /**
     * The canonical value of one column given by the caller.
     *
     * @throws InvalidArgumentException When it is not of the type of the column
     */
    private function inputValue(string $code, string $column, mixed $value): mixed
    {
        $invalid = static fn(string $expected): InvalidArgumentException => new InvalidArgumentException(
            sprintf('Rule "%s": column %s needs %s.', $code, $column, $expected)
        );

        switch ($column) {
            case 'name':
            case 'rule_type':
                return is_string($value) && trim($value) !== '' ? $value : throw $invalid('a non-empty text');
            case 'active':
                return is_bool($value) ? $value : throw $invalid('a boolean');
            case 'violation_action':
                return $this->inputAction($code, $value) ?? throw $invalid('block, warn or log (a RuleAction or its value)');
            case 'int_value':
            case 'priority':
                if ($value === null && $column === 'int_value') {
                    return null;
                }
                if (is_int($value)) {
                    return $value;
                }

                return is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : throw $invalid($column === 'priority' ? 'an integer' : 'an integer or null');
            case 'decimal_value':
                if ($value === null || is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/D', $value) === 1)) {
                    return RuleSnapshot::canonicalDecimal($value);
                }
                throw $invalid('a number or null');
            case 'parameters':
                return $this->inputParameters($code, $value, $invalid);
            case 'valid_from':
            case 'valid_until':
                if ($value === null || $value instanceof DateTimeInterface) {
                    return $value?->format('Y-m-d');
                }
                if (is_string($value) && RuleSnapshot::isoDate($value) !== null) {
                    return $value;
                }
                throw $invalid('an ISO date (YYYY-MM-DD), a DateTimeInterface or null');
            default:
                // the optional texts and the code (validated by assertCode()); a blank spec_json is no body, as
                // fromArray() reads it
                if ($value !== null && !is_string($value)) {
                    throw $invalid('a text or null');
                }

                return $column === 'spec_json' && $value !== null && trim($value) === '' ? null : $value;
        }
    }

    /** The value of an action given by the caller, or null when it is not one; a Portuguese value is refused. */
    private function inputAction(string $code, mixed $value): ?string
    {
        if ($value instanceof RuleAction) {
            return $value->value;
        }
        if (!is_string($value)) {
            return null;
        }
        $key = strtolower(trim($value));
        if (isset(RuleAction::LEGACY_PORTUGUESE_VALUES[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Rule "%s": column violation_action holds Portuguese vocabulary of the rule engine, refused since 2.0.0 (use block, warn or log).',
                $code
            ));
        }

        return RuleAction::tryFrom($key)?->value;
    }

    /**
     * @param Closure(string): InvalidArgumentException $invalid
     * @return array<array-key, mixed>|null
     */
    private function inputParameters(string $code, mixed $value, Closure $invalid): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $invalid('an associative array (a JSON object) or null');
        }
        $applicability = [...RuleDefinition::APPLICABILITY_COLUMNS, ...RuleDefinition::LEGACY_APPLICABILITY_COLUMNS];
        foreach ($applicability as $key) {
            if (array_key_exists($key, $value)) {
                throw new InvalidArgumentException(sprintf(
                    'Rule "%s": parameters cannot carry "%s": product, plan and validity live in their columns (the catalog drops them from parameters).',
                    $code,
                    $key
                ));
            }
        }
        try {
            json_encode($value, RuleSnapshot::JSON_FLAGS | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $invalid('values that can be encoded as JSON');
        }

        return RuleSnapshot::canonicalParameters($value);
    }

    /**
     * The constraints of the reference DDL that cross columns: a plan needs a product, the validity window does
     * not end before it starts.
     *
     * @param array<string, mixed> $row Canonical row
     * @throws InvalidArgumentException
     */
    private static function assertConsistent(string $code, array $row): void
    {
        if ($row['plan_code'] !== null && $row['product_code'] === null) {
            throw new InvalidArgumentException(sprintf('Rule "%s": column plan_code needs product_code (a plan belongs to a product).', $code));
        }
        if ($row['valid_from'] !== null && $row['valid_until'] !== null && strcmp($row['valid_until'], $row['valid_from']) < 0) {
            throw new InvalidArgumentException(sprintf('Rule "%s": column valid_until is before valid_from.', $code));
        }
    }

    /**
     * @throws InvalidArgumentException When the code is not a non-empty text without surrounding spaces
     */
    private static function assertCode(mixed $code): string
    {
        if (!is_string($code) || $code === '' || trim($code) !== $code) {
            throw new InvalidArgumentException('The code of a rule needs a non-empty text without surrounding spaces.');
        }

        return $code;
    }

    /**
     * The normalized reason and origin, the instant (UTC) and the actor as text.
     *
     * @return array{0: string, 1: string, 2: DateTimeImmutable, 3: string|null}
     * @throws InvalidRuleChangeReasonException
     * @throws InvalidArgumentException When the origin is empty
     */
    private function prepare(string $reason, string $origin, string|int|null $actor): array
    {
        $reason = $this->normalizeReason($reason);
        $origin = trim($origin);
        if ($origin === '') {
            throw new InvalidArgumentException('The origin of a change cannot be empty.');
        }
        $at = DateTimeImmutable::createFromInterface(($this->clock)())->setTimezone(new DateTimeZone('UTC'));

        return [$reason, $origin, $at, $actor === null ? null : (string) $actor];
    }
}
