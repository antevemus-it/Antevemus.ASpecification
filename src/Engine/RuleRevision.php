<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogStoreException;
use DateTimeImmutable;

/**
 * RuleRevision - One Version of a Catalog Rule, as the History Keeps It
 *
 * A row of `business_rule_change` (2.13.0, forward 060, D4): the full photo of the rule after the change
 * (RuleSnapshot), its hash, the kind of the change, the canonical columns it changed (alphabetical, empty for a
 * baseline and a creation), the reason, the origin, the instant and the actor. "Version 3 of rule R" is one
 * revision, citable from an analysis or a quotation that applied it.
 *
 * isIntact() computes the hash of the photo again and checks that the photo is of this rule and of this version:
 * false means the history row was edited by hand.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleRevision
{
    /**
     * @param string $code Rule code
     * @param int $version Version of the rule this row photographs
     * @param RuleChangeKind $kind What the row records
     * @param string $snapshot The photo (RuleSnapshot::encode())
     * @param string $snapshotHash Its hash (`sha256:<64 hex>`)
     * @param list<string> $changedFields Canonical columns changed, alphabetical (empty for baseline and create)
     * @param string $reason Why (normalized)
     * @param string $origin Where the change came from (RuleCatalogEditor::ORIGIN_* or the application's text)
     * @param DateTimeImmutable $changedAt When (UTC)
     * @param string|null $changedBy Who (null = a script without a user)
     * @param string|null $changeId Key of the history row (a ULID)
     */
    public function __construct(
        public string $code,
        public int $version,
        public RuleChangeKind $kind,
        public string $snapshot,
        public string $snapshotHash,
        public array $changedFields,
        public string $reason,
        public string $origin,
        public DateTimeImmutable $changedAt,
        public ?string $changedBy,
        public ?string $changeId = null
    ) {
    }

    /**
     * The revision of a canonical row: its photo and hash computed here.
     *
     * @param array<string, mixed> $row A canonical row (RuleSnapshot::fromStored())
     * @param list<string> $changedFields
     * @throws RuleCatalogStoreException When the row cannot be photographed
     */
    public static function of(
        array $row,
        RuleChangeKind $kind,
        array $changedFields,
        string $reason,
        string $origin,
        DateTimeImmutable $changedAt,
        ?string $changedBy,
        ?string $changeId = null
    ): self {
        $snapshot = RuleSnapshot::encode($row);

        return new self(
            (string) $row['code'],
            (int) $row['version'],
            $kind,
            $snapshot,
            RuleSnapshot::hash($snapshot),
            $changedFields,
            $reason,
            $origin,
            $changedAt,
            $changedBy,
            $changeId
        );
    }

    /**
     * The canonical row of the photo.
     *
     * @return array<string, mixed>
     * @throws RuleCatalogStoreException When the photo is not the photo of a rule
     */
    public function row(): array
    {
        return RuleSnapshot::decode($this->snapshot);
    }

    /**
     * The rule as it was in this version, as PdoRuleCatalog reads it (getVersion() is this version).
     *
     * @throws RuleCatalogStoreException When the photo is not the photo of a rule
     */
    public function definition(): RuleDefinition
    {
        return RuleSnapshot::definition($this->row());
    }

    /** True when the hash is the hash of the photo and the photo is of this rule and this version. */
    public function isIntact(): bool
    {
        if (!hash_equals($this->snapshotHash, RuleSnapshot::hash($this->snapshot))) {
            return false;
        }
        try {
            $row = $this->row();
        } catch (RuleCatalogStoreException) {
            return false;
        }

        return $row['code'] === $this->code && $row['version'] === $this->version;
    }
}
