-- =====================================================================
-- Antevemus.ASpecification - message outbox and inbox (reference DDL)
-- =====================================================================
-- Since 2.14.0 (forward 063, D4, D8). Additive: nothing else of the
-- library reads or writes these tables, and a database without them
-- answers as on 2.13.0 (RN-01).
--
-- message_outbox is written and read by
-- Antevemus\ASpecification\Messaging\Outbox\PdoMessageOutbox: the
-- messages published by MessagePublisher in the transaction of the write
-- that produced them (the same connection), sent to the broker after the
-- commit by OutboxRelay, which marks delivered only what the broker
-- confirmed.
--
-- message_inbox is written and read by
-- Antevemus\ASpecification\Messaging\Inbox\PdoProcessedMessageStore: one
-- row per consumer and message handled, written by MessageConsumer in the
-- transaction of the handlers, so that a message delivered twice is
-- handled once per consumer.
--
-- Portable SQL: no schema, no dialect types, unquoted lowercase
-- identifiers, a PRIMARY KEY on every table. The script is idempotent:
-- every statement is CREATE TABLE IF NOT EXISTS (the one construct
-- outside the ANSI core, accepted by PostgreSQL, MySQL/MariaDB and
-- SQLite). There is no CREATE INDEX statement, because MySQL has no
-- CREATE INDEX IF NOT EXISTS: the lookup indexes are declared as UNIQUE
-- constraints whose leading columns are the searched ones (a UNIQUE
-- constraint always creates an index, and adding the primary key column
-- at its end keeps it satisfiable by construction).
--
-- A consumer table may carry more columns, have other names and live in
-- any schema, given to the stores together with the table name and the
-- column map (columns: canonical name => real name).
--
-- Conventions (those of resources/sql/domain_events.ansi.sql):
--   entry_id           ULID of the row (26 characters), generated when
--                      the message is published: its order is the
--                      order of publication within an ordering key
--   message_id         ULID of the message; UNIQUE: publishing the same
--                      message twice stores one row
--   message_name       versioned name, module.fact.vN (at most 120)
--   ordering_key       the queue of order (at most 190); NULL = none:
--                      such a row never holds another one back
--   tenant             NULL = no tenant (at most 120)
--   headers            JSON object of texts (at most 32 headers of
--                      64 + 1 024 bytes)
--   payload            JSON as text, at most 65 535 bytes (Message
--                      refuses a larger one): TEXT, which holds exactly
--                      that in MySQL and MariaDB and has no limit in
--                      PostgreSQL and SQLite; CLOB on Oracle and DB2,
--                      NVARCHAR(MAX) on SQL Server
--   *_at columns       instants in UTC, as fixed-width text
--                      'YYYY-MM-DD HH:MM:SS.ffffff'
--   status             pending | delivered | failed
--   last_error         reason of the last send the broker did not
--                      confirm; never a stack trace nor the payload
--
-- Lookup indexes:
--   uq_mo_message      (message_id): find(), the idempotency of enqueue()
--   uq_mo_pending      (status, entry_id): the open rows, in order
--   uq_mo_ordering     (ordering_key, entry_id): the queue of one key
--                      (queue heads, findByOrderingKey)
--   uq_mo_delivered    (status, delivered_at, entry_id): the retention
--                      purge, oldest delivery first (since 2.16.0)
--   pk_message_inbox   (consumer, idempotency_key): the inbox lookup
--   uq_mi_processed    (consumer, processed_at, idempotency_key): the
--                      retention purge of one consumer
--
-- Text: the tables hold UTF-8 (a payload may carry any character); on
-- MySQL and MariaDB the database or the tables must use utf8mb4. Names of
-- consumers are lowercase, so that the case-insensitive collations of
-- MySQL and MariaDB answer as the other databases do; an ordering key
-- that differs from another only by case is the same queue there (the
-- order of each key still holds: the relay is only more careful).
--
-- Index size: ordering_key 190 + entry_id 26 characters, and consumer
-- 120 + idempotency_key 190 characters, stay under the 3072-byte InnoDB
-- key limit with utf8mb4.
--
-- Additive migration 2.14.x/2.15.x -> 2.16.0 (forward 065), for a
-- table created by the DDL of 2.14.0 or 2.15.0: no column changes. The
-- index of the retention purge (PdoMessageOutbox::purgeDeliveredBefore())
-- is optional: the purge is correct without it, only slower on a large
-- outbox. Run it by hand, once (PostgreSQL, MySQL, MariaDB and SQLite
-- accept it; it is the index of the constraint uq_mo_delivered of a new
-- table):
--
--   CREATE UNIQUE INDEX uq_mo_delivered ON message_outbox (status, delivered_at, entry_id);
--
-- The lock of the relay (one relay at a time), optional too, lives in
-- a table of its own: resources/sql/lease_lock.ansi.sql.
-- =====================================================================

CREATE TABLE IF NOT EXISTS message_outbox (
    entry_id                 VARCHAR(26)    NOT NULL,
    message_id               VARCHAR(26)    NOT NULL,
    message_name             VARCHAR(120)   NOT NULL,
    ordering_key             VARCHAR(190),
    tenant                   VARCHAR(120),
    headers                  TEXT           NOT NULL,
    payload                  TEXT           NOT NULL,
    occurred_at              VARCHAR(26)    NOT NULL,
    status                   VARCHAR(10)    DEFAULT 'pending' NOT NULL,
    attempts                 INTEGER        DEFAULT 0 NOT NULL,
    last_error               VARCHAR(2000),
    enqueued_at              VARCHAR(26)    NOT NULL,
    delivered_at             VARCHAR(26),
    last_attempt_at          VARCHAR(26),
    CONSTRAINT pk_message_outbox PRIMARY KEY (entry_id),
    CONSTRAINT uq_mo_message UNIQUE (message_id),
    CONSTRAINT uq_mo_pending UNIQUE (status, entry_id),
    CONSTRAINT uq_mo_ordering UNIQUE (ordering_key, entry_id),
    CONSTRAINT uq_mo_delivered UNIQUE (status, delivered_at, entry_id),
    CONSTRAINT chk_mo_status CHECK (status IN ('pending', 'delivered', 'failed')),
    CONSTRAINT chk_mo_attempts CHECK (attempts >= 0)
);

CREATE TABLE IF NOT EXISTS message_inbox (
    consumer                 VARCHAR(120)   NOT NULL,
    idempotency_key          VARCHAR(190)   NOT NULL,
    message_id               VARCHAR(26)    NOT NULL,
    message_name             VARCHAR(120)   NOT NULL,
    tenant                   VARCHAR(120),
    processed_at             VARCHAR(26)    NOT NULL,
    CONSTRAINT pk_message_inbox PRIMARY KEY (consumer, idempotency_key),
    CONSTRAINT uq_mi_processed UNIQUE (consumer, processed_at, idempotency_key)
);
