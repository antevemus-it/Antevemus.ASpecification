-- =====================================================================
-- Antevemus.ASpecification - domain event outbox and trigger state
-- (reference DDL)
-- =====================================================================
-- domain_event_outbox is written and read by
-- Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox
-- (since 1.11.0, forward 029, D5): the events emitted by specification
-- triggers (TriggeringRepository, OutboxDomainEventPublisher), written
-- in the transaction of the write when the repository and the outbox
-- share one, and delivered after the commit by DomainEventDispatcher.
--
-- domain_event_trigger_state (since 1.12.0, forward 030, D2) is written
-- and read by
-- Antevemus\ASpecification\DomainEvents\State\PdoTriggerStateStore:
-- the last known state of every trigger for every entity, compared by
-- TriggerSweeper with the truth at the sweep instant (crossings caused
-- by time alone) and kept up to date by TriggeringRepository. Adding it
-- to a 1.11.0 database is running this script again: the outbox table
-- is left untouched.
--
-- Portable SQL: no schema, no dialect types, unquoted lowercase
-- identifiers, a PRIMARY KEY on every table. The script is idempotent:
-- every statement is CREATE TABLE IF NOT EXISTS (the one construct
-- outside the ANSI core, accepted by PostgreSQL, MySQL/MariaDB and
-- SQLite).
-- There is no CREATE INDEX statement, because MySQL has no CREATE INDEX
-- IF NOT EXISTS: the lookup indexes are declared as UNIQUE constraints
-- whose leading columns are the searched ones (a UNIQUE constraint
-- always creates an index, and adding the primary key column at its end
-- keeps it satisfiable by construction).
--
-- A consumer table may carry more columns and live in any schema, given
-- to PdoDomainEventOutbox together with the table name.
--
-- Conventions (those of resources/sql/state_machine.ansi.sql):
--   event_id           ULID of the row (26 characters); its order is the
--                      delivery order within an entity
--   idempotency_key    'de:' + SHA-256 (hex) of trigger, entity class,
--                      entity id, kind and occurred_at (67 characters);
--                      UNIQUE: the same crossing is stored once
--   kind               enter | exit
--   payload            JSON as text: the trigger and the entity id only,
--                      never the entity (D4)
--   *_at columns       instants in UTC, as fixed-width text
--                      'YYYY-MM-DD HH:MM:SS.ffffff'
--   status             pending | delivered | failed
--   last_error         message of the last failed attempt, never a
--                      stack trace
--
-- Lookup indexes:
--   uq_deo_pending     (status, event_id): the open rows, in order
--   uq_deo_entity      (entity_class, entity_id, event_id): the queue of
--                      one entity (queue heads, findByEntity)
--
-- Index size: entity_class 255 + entity_id 190 + event_id 26 characters
-- stay under the 3072-byte InnoDB key limit with utf8mb4.
-- =====================================================================

CREATE TABLE IF NOT EXISTS domain_event_outbox (
    event_id                 VARCHAR(26)    NOT NULL,
    idempotency_key          VARCHAR(190)   NOT NULL,
    event_name               VARCHAR(120)   NOT NULL,
    trigger_name             VARCHAR(120)   NOT NULL,
    kind                     VARCHAR(10)    NOT NULL,
    entity_class             VARCHAR(255)   NOT NULL,
    entity_id                VARCHAR(190)   NOT NULL,
    payload                  VARCHAR(4000),
    occurred_at              VARCHAR(26)    NOT NULL,
    status                   VARCHAR(10)    DEFAULT 'pending' NOT NULL,
    attempts                 INTEGER        DEFAULT 0 NOT NULL,
    last_error               VARCHAR(2000),
    delivered_at             VARCHAR(26),
    last_attempt_at          VARCHAR(26),
    CONSTRAINT pk_domain_event_outbox PRIMARY KEY (event_id),
    CONSTRAINT uq_deo_idempotency UNIQUE (idempotency_key),
    CONSTRAINT uq_deo_pending UNIQUE (status, event_id),
    CONSTRAINT uq_deo_entity UNIQUE (entity_class, entity_id, event_id),
    CONSTRAINT chk_deo_kind CHECK (kind IN ('enter', 'exit')),
    CONSTRAINT chk_deo_status CHECK (status IN ('pending', 'delivered', 'failed')),
    CONSTRAINT chk_deo_attempts CHECK (attempts >= 0)
);

-- ---------------------------------------------------------------------
-- domain_event_trigger_state (since 1.12.0)
-- ---------------------------------------------------------------------
-- One row per (trigger, entity class, entity id); no row = never
-- recorded, which the sweeper reads as "not satisfied".
--
--   satisfied          1 when the entity satisfied the trigger at the
--                      last recorded evaluation, 0 otherwise
--   evaluated_at       instant of that evaluation, UTC fixed-width text
--   version            1 on insert, + 1 on every change: the writes are
--                      compare-and-set on it (UPDATE ... WHERE version =
--                      the version read), so two sweepers, or a sweeper
--                      and a write, record and publish a crossing once
--
-- Lookup indexes:
--   uq_dets_satisfied  (trigger_name, entity_class, satisfied,
--                      entity_id): the satisfied rows of a trigger, in
--                      entity id order (the sweeper's keyset pages)
--   uq_dets_entity     (entity_class, entity_id, trigger_name): every
--                      state of one entity (the decorator's "before")
--
-- Index size: trigger_name 120 + entity_class 255 + entity_id 190
-- characters (plus a SMALLINT) stay under the 3072-byte InnoDB key limit
-- with utf8mb4.

CREATE TABLE IF NOT EXISTS domain_event_trigger_state (
    trigger_name             VARCHAR(120)   NOT NULL,
    entity_class             VARCHAR(255)   NOT NULL,
    entity_id                VARCHAR(190)   NOT NULL,
    satisfied                SMALLINT       NOT NULL,
    evaluated_at             VARCHAR(26)    NOT NULL,
    version                  INTEGER        DEFAULT 1 NOT NULL,
    CONSTRAINT pk_domain_event_trigger_state PRIMARY KEY (trigger_name, entity_class, entity_id),
    CONSTRAINT uq_dets_satisfied UNIQUE (trigger_name, entity_class, satisfied, entity_id),
    CONSTRAINT uq_dets_entity UNIQUE (entity_class, entity_id, trigger_name),
    CONSTRAINT chk_dets_satisfied CHECK (satisfied IN (0, 1)),
    CONSTRAINT chk_dets_version CHECK (version >= 1)
);
