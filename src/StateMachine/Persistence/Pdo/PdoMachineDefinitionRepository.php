<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\DefinitionSupport;
use Antevemus\ASpecification\StateMachine\Calendar\DeadlineCalendarRegistry;
use Antevemus\ASpecification\StateMachine\Exceptions\DefinitionDraftConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionError;
use Antevemus\ASpecification\StateMachine\Persistence\DefinitionDraftSupport;
use Antevemus\ASpecification\StateMachine\Persistence\DraftInfo;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionDrafts;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\ITransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\MachineDefinitionDraft;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Persistence\PublishedMachineDefinition;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * PdoMachineDefinitionRepository - Versioned Machine Definitions over PDO, With Drafts (2.7.0)
 *
 * The definitions live in the database, so a screen can edit a machine and publish a new version
 * without a deploy. Two tables of `resources/sql/state_machine.ansi.sql`:
 * - `state_machine_definition`: one row per published version, (machine_key, version) as primary key,
 *   the canonical JSON of the definition (TEXT: a real definition is larger than VARCHAR(4000)), its
 *   fingerprint and the trail (published_at, published_by, reason; null for a register());
 * - `state_machine_definition_draft`: at most one draft per machine key, the array the screen saved,
 *   the version it started from, its revision (compare-and-set) and who saved it, when.
 *
 * IMachineDefinitionRepository (the port of the runner, unchanged): register() publishes without a
 * draft (the same fingerprint again is a no-op, another one under a published pair is refused as
 * KIND_DUPLICATE, as in memory); get() memorizes each pair per repository instance (a published pair
 * is immutable, so the runner stops going to the database on every event); has() and latestVersion()
 * read. A row is trusted only after its fingerprint is computed again and compared with the column,
 * and its key and version with the row's: a row edited by hand or corrupted throws
 * MachinePersistenceException and never runs.
 *
 * IMachineDefinitionDrafts (the screen): see the interface. publish() runs in one transaction of the
 * transaction manager (which joins a transaction already open on the connection, like Adianti's
 * TTransaction); a concurrent publication that took the version first is a primary key violation,
 * reported as DefinitionDraftConflictException.
 *
 * Conventions of the other PDO stores: optional schema, table names by role (`definition`, `draft`),
 * column map (canonical => real, a canonical column shared by both tables maps to the same real name),
 * unquoted identifiers validated, named placeholders, the PDO error mode left untouched, instants as
 * fixed-width UTC text, no clock.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMachineDefinitionRepository implements IMachineDefinitionRepository, IMachineDefinitionDrafts
{
    /** Default table names by role, mirroring the reference DDL. */
    public const DEFAULT_TABLES = [
        'definition' => 'state_machine_definition',
        'draft' => 'state_machine_definition_draft',
    ];

    /** Canonical columns of each table, in DDL order. */
    public const TABLE_COLUMNS = [
        'definition' => ['machine_key', 'version', 'definition', 'fingerprint', 'published_at', 'published_by', 'reason'],
        'draft' => ['machine_key', 'definition', 'based_on_version', 'revision', 'updated_at', 'updated_by'],
    ];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, string> Role => schema-qualified table name */
    private readonly array $tables;

    /** @var array<string, string> Canonical column => real column */
    private readonly array $columns;

    private readonly ITransactionManager $transactions;

    /** @var array<string, array<int, MachineDefinition>> Published pairs already read (immutable) */
    private array $memo = [];

    /**
     * @param PDO $pdo Connection to the database holding the tables (its error mode is left untouched)
     * @param string $schema Optional schema (or database) qualifying both tables; '' = unqualified
     * @param array<string, string> $tables Table names by role (`definition`, `draft`); missing roles keep the default
     * @param array<string, string> $columns Real column names by canonical column; missing columns keep the canonical name
     * @param ITransactionManager|null $transactions Unit of work (null = a PdoTransactionManager on $pdo)
     * @throws InvalidArgumentException When a name is not a plain SQL identifier, or a role or a canonical column is unknown
     */
    public function __construct(
        private readonly PDO $pdo,
        string $schema = '',
        array $tables = [],
        array $columns = [],
        ?ITransactionManager $transactions = null
    ) {
        if ($schema !== '' && preg_match(self::IDENTIFIER, $schema) !== 1) {
            throw new InvalidArgumentException(sprintf('State machine schema "%s" is not a plain SQL identifier.', $schema));
        }
        foreach ($tables as $role => $_) {
            if (!array_key_exists($role, self::DEFAULT_TABLES)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown machine definition table role "%s". Expected: %s.',
                    (string) $role,
                    implode(', ', array_keys(self::DEFAULT_TABLES))
                ));
            }
        }
        $resolved = [];
        foreach (self::DEFAULT_TABLES as $role => $default) {
            $name = $tables[$role] ?? $default;
            if (!is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Machine definition table "%s" (role %s) is not a plain SQL identifier.',
                    is_string($name) ? $name : get_debug_type($name),
                    $role
                ));
            }
            $resolved[$role] = $schema !== '' ? $schema . '.' . $name : $name;
        }
        $this->tables = $resolved;

        $known = array_values(array_unique(array_merge(...array_values(self::TABLE_COLUMNS))));
        $map = array_combine($known, $known);
        foreach ($columns as $canonical => $real) {
            if (!is_string($canonical) || !in_array($canonical, $known, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown machine definition column "%s". Expected one of: %s.',
                    (string) $canonical,
                    implode(', ', $known)
                ));
            }
            if (!is_string($real) || preg_match(self::IDENTIFIER, $real) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Machine definition column "%s" (canonical %s) is not a plain SQL identifier.',
                    is_string($real) ? $real : get_debug_type($real),
                    $canonical
                ));
            }
            $map[$canonical] = $real;
        }
        $this->columns = $map;
        $this->transactions = $transactions ?? new PdoTransactionManager($pdo);
    }

    /**
     * Schema-qualified table names in use, by role.
     *
     * @return array<string, string>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Real column name of every canonical column.
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    // ------------------------------------------------------------------------------------------
    // IMachineDefinitionRepository
    // ------------------------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function register(MachineDefinition $definition): void
    {
        // One statement at a time (no transaction of its own): a register() racing another one sees
        // the primary key violation and reads the winner, which a failed statement inside an open
        // PostgreSQL transaction would not allow.
        $stored = $this->storedFingerprint($definition->key, $definition->version);
        if ($stored === null) {
            try {
                $this->insertDefinition($definition, null, null, null);
                $this->memo[$definition->key][$definition->version] = $definition;
                return;
            } catch (MachinePersistenceException $e) {
                if (!$e->isIntegrityViolation()) {
                    throw $e;
                }
                $stored = $this->storedFingerprint($definition->key, $definition->version);
            }
        }
        if ($stored !== $definition->fingerprint()) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_DUPLICATE,
                sprintf(
                    'Machine "%s" v%d is already registered with another definition (fingerprint %s): a changed definition needs a new version.',
                    $definition->key,
                    $definition->version,
                    (string) $stored
                )
            );
        }
    }

    /** {@inheritdoc} */
    public function get(string $key, int $version): MachineDefinition
    {
        if (isset($this->memo[$key][$version])) {
            return $this->memo[$key][$version];
        }
        $rows = $this->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :machine_key AND %s = :version',
            $this->selectList(['definition', 'fingerprint']),
            $this->tables['definition'],
            $this->columns['machine_key'],
            $this->columns['version']
        ), [':machine_key' => $key, ':version' => $version]);
        if ($rows === []) {
            throw new UnknownMachineDefinitionException(sprintf('Machine "%s" v%d is not registered.', $key, $version));
        }
        $definition = $this->trusted($rows[0], $key, $version);

        return $this->memo[$key][$version] = $definition;
    }

    /** {@inheritdoc} */
    public function has(string $key, int $version): bool
    {
        return isset($this->memo[$key][$version]) || $this->storedFingerprint($key, $version) !== null;
    }

    /** {@inheritdoc} */
    public function latestVersion(string $key): ?int
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT MAX(%s) AS latest FROM %s WHERE %s = :machine_key',
            $this->columns['version'],
            $this->tables['definition'],
            $this->columns['machine_key']
        ), [':machine_key' => $key]);
        $latest = $rows[0]['latest'] ?? null;

        return $latest === null ? null : (int) $latest;
    }

    // ------------------------------------------------------------------------------------------
    // IMachineDefinitionDrafts
    // ------------------------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function saveDraft(string $machineKey, array $definition, ?int $expectedRevision, ?string $actor, DateTimeImmutable $at): DraftInfo
    {
        DefinitionDraftSupport::assertDraft($machineKey, $definition);

        return $this->transactions->transactional(function () use ($machineKey, $definition, $expectedRevision, $actor, $at): DraftInfo {
            $current = $this->readDraft($machineKey);
            $basedOn = $current !== null ? $current['info']->basedOnVersion : $this->latestVersion($machineKey);

            return $this->writeDraft($machineKey, $definition, $basedOn, $expectedRevision, $actor, $at);
        });
    }

    /** {@inheritdoc} */
    public function draft(string $machineKey, ?GuardRegistry $guards = null, ?array $knownActions = null, ?DeadlineCalendarRegistry $calendars = null): ?MachineDefinitionDraft
    {
        $draft = $this->readDraft($machineKey);
        if ($draft === null) {
            return null;
        }

        return new MachineDefinitionDraft(
            $draft['info'],
            $draft['definition'],
            DefinitionDraftSupport::analyze($machineKey, $draft['definition'], $guards ?? new GuardRegistry(), $knownActions, $calendars)
        );
    }

    /** {@inheritdoc} */
    public function draftFrom(string $machineKey, int $version, ?int $expectedRevision, ?string $actor, DateTimeImmutable $at): DraftInfo
    {
        return $this->transactions->transactional(function () use ($machineKey, $version, $expectedRevision, $actor, $at): DraftInfo {
            $published = $this->get($machineKey, $version);

            return $this->writeDraft($machineKey, $published->toArray(), $version, $expectedRevision, $actor, $at);
        });
    }

    /** {@inheritdoc} */
    public function discardDraft(string $machineKey, int $expectedRevision): void
    {
        $this->transactions->transactional(function () use ($machineKey, $expectedRevision): void {
            $this->deleteDraft($machineKey, $expectedRevision);
        });
    }

    /** {@inheritdoc} */
    public function publish(
        string $machineKey,
        int $expectedRevision,
        GuardRegistry $guards,
        ?array $knownActions,
        string $reason,
        ?string $actor,
        DateTimeImmutable $at,
        ?DeadlineCalendarRegistry $calendars = null
    ): PublishedMachineDefinition {
        return $this->transactions->transactional(function () use ($machineKey, $expectedRevision, $guards, $knownActions, $reason, $actor, $at, $calendars): PublishedMachineDefinition {
            $draft = $this->readDraft($machineKey);
            if ($draft === null || $draft['info']->revision !== $expectedRevision) {
                throw new DefinitionDraftConflictException($machineKey, $expectedRevision, $draft['info']->revision ?? null);
            }
            [$definition, $warnings] = DefinitionDraftSupport::publishable(
                $machineKey,
                $draft['definition'],
                ($this->latestVersion($machineKey) ?? 0) + 1,
                $guards,
                $knownActions,
                $reason,
                $calendars
            );
            try {
                $this->insertDefinition($definition, $at, $actor, $reason);
            } catch (MachinePersistenceException $e) {
                if ($e->isIntegrityViolation()) {
                    throw new DefinitionDraftConflictException(
                        $machineKey,
                        $expectedRevision,
                        $expectedRevision,
                        sprintf('Machine "%s" v%d was published by someone else first. Reload the draft and retry.', $machineKey, $definition->version)
                    );
                }
                throw $e;
            }
            $this->deleteDraft($machineKey, $expectedRevision);
            $this->memo[$machineKey][$definition->version] = $definition;

            return new PublishedMachineDefinition($definition, $warnings, $reason, $actor, $at);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Rows
    // ------------------------------------------------------------------------------------------

    /**
     * The definition of a stored row, after checking that the row is what was published: its key and
     * version, and the fingerprint computed again from its JSON equal to the column.
     *
     * @param array<string, mixed> $row
     * @throws MachinePersistenceException
     */
    private function trusted(array $row, string $key, int $version): MachineDefinition
    {
        try {
            $definition = MachineDefinition::fromArray(PersistenceSupport::decodeJson((string) $row['definition'], 'machine definition'));
        } catch (Throwable $e) {
            throw new MachinePersistenceException(sprintf('Stored definition of machine "%s" v%d is malformed: %s', $key, $version, $e->getMessage()), previous: $e);
        }
        // The row was selected by (key, version): its JSON must hold that same pair
        if ($definition->key !== $key || $definition->version !== $version) {
            throw new MachinePersistenceException(sprintf(
                'Stored definition of machine "%s" v%d holds machine "%s" v%d: the row was changed outside the repository.',
                $key,
                $version,
                $definition->key,
                $definition->version
            ));
        }
        $fingerprint = $definition->fingerprint();
        if ($fingerprint !== (string) $row['fingerprint']) {
            throw new MachinePersistenceException(sprintf(
                'Stored definition of machine "%s" v%d does not match its fingerprint (stored %s, computed %s): the row was changed outside the repository or is corrupted, so it never runs.',
                $key,
                $version,
                (string) $row['fingerprint'],
                $fingerprint
            ));
        }

        return $definition;
    }

    private function storedFingerprint(string $key, int $version): ?string
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :machine_key AND %s = :version',
            $this->selectList(['fingerprint']),
            $this->tables['definition'],
            $this->columns['machine_key'],
            $this->columns['version']
        ), [':machine_key' => $key, ':version' => $version]);

        return $rows === [] ? null : (string) $rows[0]['fingerprint'];
    }

    private function insertDefinition(MachineDefinition $definition, ?DateTimeImmutable $at, ?string $actor, ?string $reason): void
    {
        $this->insert('definition', [
            'machine_key' => $definition->key,
            'version' => $definition->version,
            'definition' => DefinitionSupport::canonicalJson($definition->toArray()),
            'fingerprint' => $definition->fingerprint(),
            'published_at' => $at === null ? null : PersistenceSupport::toText($at),
            'published_by' => $actor,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array{info: DraftInfo, definition: array<string, mixed>}|null
     */
    private function readDraft(string $machineKey): ?array
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :machine_key',
            $this->selectList(self::TABLE_COLUMNS['draft']),
            $this->tables['draft'],
            $this->columns['machine_key']
        ), [':machine_key' => $machineKey]);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return [
            'info' => new DraftInfo(
                $machineKey,
                (int) $row['revision'],
                $row['based_on_version'] === null ? null : (int) $row['based_on_version'],
                PersistenceSupport::fromText((string) $row['updated_at']),
                $row['updated_by'] === null ? null : (string) $row['updated_by']
            ),
            'definition' => PersistenceSupport::decodeJson((string) $row['definition'], 'machine definition draft'),
        ];
    }

    /**
     * Creates the draft (null expected: an INSERT, which the primary key refuses when a draft exists) or
     * replaces it by compare-and-set on the revision (an UPDATE whose row count decides).
     *
     * @param array<string, mixed> $definition
     * @throws DefinitionDraftConflictException
     */
    private function writeDraft(
        string $machineKey,
        array $definition,
        ?int $basedOn,
        ?int $expectedRevision,
        ?string $actor,
        DateTimeImmutable $at
    ): DraftInfo {
        $info = new DraftInfo($machineKey, ($expectedRevision ?? 0) + 1, $basedOn, $at, $actor);
        $json = PersistenceSupport::encodeJson($definition);
        if ($expectedRevision === null) {
            try {
                $this->insert('draft', [
                    'machine_key' => $machineKey,
                    'definition' => $json,
                    'based_on_version' => $basedOn,
                    'revision' => $info->revision,
                    'updated_at' => PersistenceSupport::toText($info->updatedAt),
                    'updated_by' => $actor,
                ]);
            } catch (MachinePersistenceException $e) {
                if ($e->isIntegrityViolation()) {
                    throw new DefinitionDraftConflictException($machineKey, null, null, sprintf(
                        'The draft of machine "%s" already exists (created by someone else first). Reload the draft and retry.',
                        $machineKey
                    ));
                }
                throw $e;
            }

            return $info;
        }
        $statement = $this->execute(sprintf(
            'UPDATE %s SET %s = :definition, %s = :based_on_version, %s = :revision, %s = :updated_at, %s = :updated_by WHERE %s = :machine_key AND %s = :expected_revision',
            $this->tables['draft'],
            $this->columns['definition'],
            $this->columns['based_on_version'],
            $this->columns['revision'],
            $this->columns['updated_at'],
            $this->columns['updated_by'],
            $this->columns['machine_key'],
            $this->columns['revision']
        ), [
            ':definition' => $json,
            ':based_on_version' => $basedOn,
            ':revision' => $info->revision,
            ':updated_at' => PersistenceSupport::toText($info->updatedAt),
            ':updated_by' => $actor,
            ':machine_key' => $machineKey,
            ':expected_revision' => $expectedRevision,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new DefinitionDraftConflictException($machineKey, $expectedRevision, $this->readDraft($machineKey)['info']->revision ?? null);
        }

        return $info;
    }

    /**
     * @throws DefinitionDraftConflictException When no draft has the expected revision
     */
    private function deleteDraft(string $machineKey, int $expectedRevision): void
    {
        $statement = $this->execute(sprintf(
            'DELETE FROM %s WHERE %s = :machine_key AND %s = :expected_revision',
            $this->tables['draft'],
            $this->columns['machine_key'],
            $this->columns['revision']
        ), [':machine_key' => $machineKey, ':expected_revision' => $expectedRevision]);
        if ($statement->rowCount() !== 1) {
            throw new DefinitionDraftConflictException($machineKey, $expectedRevision, $this->readDraft($machineKey)['info']->revision ?? null);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Statements
    // ------------------------------------------------------------------------------------------

    /**
     * @param list<string> $canonical
     */
    private function selectList(array $canonical): string
    {
        return implode(', ', array_map(function (string $c): string {
            $real = $this->columns[$c];
            return $real === $c ? $real : $real . ' AS ' . $c;
        }, $canonical));
    }

    /**
     * @param array<string, mixed> $row Canonical column => value
     * @throws MachinePersistenceException
     */
    private function insert(string $role, array $row): void
    {
        $params = [];
        foreach ($row as $column => $value) {
            $params[':' . $column] = $value;
        }
        $this->execute(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tables[$role],
            implode(', ', array_map(fn(string $c): string => $this->columns[$c], array_keys($row))),
            implode(', ', array_keys($params))
        ), $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     * @throws MachinePersistenceException
     */
    private function fetchAll(string $sql, array $params): array
    {
        $rows = $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * @param array<string, mixed> $params
     * @throws MachinePersistenceException
     */
    private function execute(string $sql, array $params): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->driverError($this->pdo->errorInfo());
            }
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value, match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                });
            }
            if (!$statement->execute()) {
                throw $this->driverError($statement->errorInfo());
            }
        } catch (PDOException $e) {
            $info = $e->errorInfo;
            throw new MachinePersistenceException(
                'Machine definition statement failed: ' . $e->getMessage(),
                previous: $e,
                sqlState: is_array($info) && isset($info[0]) ? (string) $info[0] : (is_string($e->getCode()) ? $e->getCode() : null)
            );
        }

        return $statement;
    }

    /**
     * @param array<int, mixed> $errorInfo
     */
    private function driverError(array $errorInfo): MachinePersistenceException
    {
        return new MachinePersistenceException(
            sprintf(
                'Machine definition statement failed: [%s] %s',
                (string) ($errorInfo[0] ?? ''),
                (string) ($errorInfo[2] ?? 'unknown driver error')
            ),
            sqlState: isset($errorInfo[0]) ? (string) $errorInfo[0] : null
        );
    }
}
