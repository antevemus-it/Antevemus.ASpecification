-- =====================================================================
-- Antevemus.ASpecification - domain event outbox (reference DDL)
-- =====================================================================
-- Written and read by
-- Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox
-- (since 1.11.0, forward 029, D5): the events emitted by specification
-- triggers (TriggeringRepository, OutboxDomainEventPublisher), written
-- in the transaction of the write when the repository and the outbox
-- share one, and delivered after the commit by DomainEventDispatcher.
--
-- Portable SQL: no schema, no dialect types, unquoted lowercase
-- identifiers, a PRIMARY KEY on the table. The script is idempotent:
-- the statement is CREATE TABLE IF NOT EXISTS (the one construct outside
-- the ANSI core, accepted by PostgreSQL, MySQL/MariaDB and SQLite).
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
