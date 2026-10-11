<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Closure;

/**
 * JoinTableHop - The Join Table Step of a Filter Through a #[ManyToMany] (Internal)
 *
 * Built by PdoRepository::whereRelation() for an association (since 1.14.0, forward 032, D1) and carried
 * by the InQuerySpecification of the filter: the subquery over the targets is wrapped as
 * `SELECT "owner" FROM "join" WHERE "target" IN (<targets>) AND "owner" IS NOT NULL`, so the filter
 * stays one statement; in memory, the owners linked to the targets found are read from the join table
 * by the owner's repository. The table and column names come from the mapping (validated identifiers)
 * and are quoted by the dialect.
 *
 * @internal
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class JoinTableHop
{
    /**
     * @param PdoRepository $owner Repository of the entity that maps the association (holds the join table)
     * @param string $relation The association property
     * @param string $table Join table
     * @param string $ownerColumn Column of the join table holding the owner's identifier
     * @param string $targetColumn Column of the join table holding the target's identifier
     * @param Closure(list<mixed>): list<mixed> $ownersOf Owner identifiers linked to some target identifiers
     */
    public function __construct(
        public readonly PdoRepository $owner,
        public readonly string $relation,
        public readonly string $table,
        public readonly string $ownerColumn,
        public readonly string $targetColumn,
        private readonly Closure $ownersOf
    ) {
    }

    /**
     * The subquery of the owners linked to the targets the given subquery selects.
     */
    public function wrap(ISqlDialect $dialect, string $targetSubquery): string
    {
        $owner = $dialect->escapeIdentifier($this->ownerColumn);

        return 'SELECT ' . $owner . ' FROM ' . $dialect->escapeIdentifier($this->table)
            . ' WHERE ' . $dialect->escapeIdentifier($this->targetColumn) . ' IN (' . $targetSubquery . ')'
            . ' AND ' . $owner . ' IS NOT NULL';
    }

    /**
     * The identifiers (property values) of the owners linked to the given target identifiers.
     *
     * @param list<mixed> $targetIds
     * @return list<mixed>
     */
    public function ownersOf(array $targetIds): array
    {
        return $targetIds === [] ? [] : ($this->ownersOf)($targetIds);
    }

    /** Same owner repository instance, same association. */
    public function equals(self $other): bool
    {
        return $other->owner === $this->owner && $other->relation === $this->relation;
    }
}
