<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

/**
 * RuleChangeCandidate - A Change of a Catalog Rule, as the Change Guard Sees It
 *
 * The candidate of the specification given to RuleCatalogEditor::withChangeGuard() (2.13.0, forward 060, D8),
 * evaluated inside the transaction of the change, before anything is written: the kind of the change, the rule
 * before (null for a creation) and after, the version it leaves and the one it makes, the canonical columns it
 * changes (alphabetical; empty for a creation), who, from where and why. A guard reads it as any candidate
 * (`Spec::property('after.active', ...)`), which is where an application plugs "a rule that needs a legal opinion
 * is not activated without a favourable one for this version" without the library knowing what an opinion is.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleChangeCandidate
{
    /**
     * @param RuleChangeKind $kind Create, Update, Activate or Deactivate
     * @param string $code Rule code
     * @param RuleDefinition|null $before The rule before the change (null = it does not exist yet)
     * @param RuleDefinition $after The rule after the change (its version is $toVersion)
     * @param int|null $fromVersion Version before the change (null = a creation)
     * @param int $toVersion Version the change makes
     * @param list<string> $changedFields Canonical columns changed, alphabetical
     * @param string|int|null $actor Who (null = a script without a user)
     * @param string $origin Where the change comes from
     * @param string $reason Why (normalized)
     */
    public function __construct(
        public RuleChangeKind $kind,
        public string $code,
        public ?RuleDefinition $before,
        public RuleDefinition $after,
        public ?int $fromVersion,
        public int $toVersion,
        public array $changedFields,
        public string|int|null $actor,
        public string $origin,
        public string $reason
    ) {
    }
}
