-- =====================================================================
-- Antevemus.ASpecification - lease locks (reference DDL)
-- =====================================================================
-- Since 2.16.0 (forward 065, D9). Additive: nothing else of the library
-- reads or writes this table, and a database without it answers as on
-- 2.15.0. It is needed only by an application that gives a dispatcher
-- of an outbox a lock (OutboxRelay, DomainEventDispatcher,
-- EffectDispatcher: `lock: new PdoLeaseLock($pdo)`).
--
-- lease_lock is written and read by
-- Antevemus\ASpecification\Persistence\PdoLeaseLock: one row per lock
-- name, the worker that holds it and the end of its lease, so that one
-- dispatcher works on an outbox at a time. One table serves every
-- dispatcher of the application; each one takes the lock under its own
-- name (by default its kind and the table of its outbox).
--
-- Portable SQL: no schema, no dialect types, unquoted lowercase
-- identifiers, a PRIMARY KEY. The script is idempotent: the statement is
-- CREATE TABLE IF NOT EXISTS (the one construct outside the ANSI core,
-- accepted by PostgreSQL, MySQL/MariaDB and SQLite).
--
-- A consumer table may carry more columns, have other names and live in
-- any schema, given to PdoLeaseLock together with the table name and the
-- column map (columns: canonical name => real name).
--
-- Conventions (those of resources/sql/messaging.ansi.sql):
--   lock_name          the lock of one outbox (at most 120)
--   owner              the worker that holds it (at most 120); names
--                      and owners that differ only by case are the same
--                      on MySQL and MariaDB
--   locked_until       end of the lease of the owner, in UTC, as
--                      fixed-width text 'YYYY-MM-DD HH:MM:SS.ffffff':
--                      once it is past, another worker may take the lock
--   acquired_at        when the owner took the lock (kept on renewal)
--
-- Lookup index:
--   pk_lease_lock      (lock_name): the lock of one outbox
-- =====================================================================

CREATE TABLE IF NOT EXISTS lease_lock (
    lock_name                VARCHAR(120)   NOT NULL,
    owner                    VARCHAR(120)   NOT NULL,
    locked_until             VARCHAR(26)    NOT NULL,
    acquired_at              VARCHAR(26)    NOT NULL,
    CONSTRAINT pk_lease_lock PRIMARY KEY (lock_name)
);
