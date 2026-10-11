<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

/**
 * IdStrategy - Where the Identifier of a New Entity Comes From
 *
 * - Auto: the database generates it (identity, serial, auto-increment). The id column is left out of
 *   the INSERT when the property is null or uninitialized and read back afterwards: with
 *   `INSERT ... RETURNING` in PostgreSQL (or `lastInsertId()` of the configured sequence), with
 *   `lastInsertId()` elsewhere. An id already set is inserted as given.
 * - Ulid / Uuid7: the repository generates a ULID (UlidGenerator) or an RFC 9562 UUIDv7
 *   (UuidV7Generator) when the property is null or uninitialized; entities extending
 *   AbstractUlidEntity/AbstractUuidV7Entity already carry one.
 * - Assigned: the caller sets the identifier; putting an entity without one is refused.
 * - Max: `SELECT COALESCE(MAX(id), 0) + 1` inside the write transaction, compatible with the
 *   IDPOLICY 'max' of Adianti TRecord. Integer identifiers only. Without a lock it is subject to a
 *   race between concurrent writers (two transactions may read the same maximum; the primary key then
 *   rejects the second INSERT): prefer Auto, Ulid or Uuid7 for new tables.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum IdStrategy: string
{
    case Auto = 'auto';
    case Ulid = 'ulid';
    case Uuid7 = 'uuid7';
    case Assigned = 'assigned';
    case Max = 'max';
}
