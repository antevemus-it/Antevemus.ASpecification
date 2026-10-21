-- =====================================================================
-- Antevemus.ASpecification - state machine runner (reference DDL)
-- =====================================================================
-- Written and read by the PDO stores of
-- Antevemus\ASpecification\StateMachine\Persistence\Pdo (since 1.8.0,
-- forward 023, D14, RN-12) through MachinePersistence::pdo().
--
-- Portable SQL: no schema, no dialect types, unquoted lowercase
-- identifiers, a PRIMARY KEY on every table. The script is idempotent:
-- every statement is CREATE TABLE IF NOT EXISTS (the one construct
-- outside the ANSI core, accepted by PostgreSQL, MySQL/MariaDB and
-- SQLite). There is no CREATE INDEX statement, because MySQL has no
-- CREATE INDEX IF NOT EXISTS: the lookup indexes are declared as UNIQUE
-- constraints whose leading columns are the searched ones (a UNIQUE
-- constraint always creates an index, and adding the primary key
-- columns at its end keeps it satisfiable by construction).
--
-- A consumer table may carry more columns and live in any schema, given
-- to MachinePersistence::pdo() together with the table names by role
-- (instance, region, history, outbox, idempotency, rejection) and, when
-- they differ, the column names (canonical column => real column).
--
-- Conventions:
--   *_at columns       instants in UTC, as fixed-width text
--                      'YYYY-MM-DD HH:MM:SS.ffffff', so that ordering
--                      and '<=' are lexicographic and identical in every
--                      database (no TIMESTAMP time zone, precision or
--                      2038 surprises); the snapshot JSON keeps the
--                      original offsets
--   snapshot, payload, params, reason, transition_result
--                      JSON as text (VARCHAR(4000) for portability;
--                      widen to TEXT/JSONB/CLOB when payloads are larger)
--   done               'Y' or 'N'
--   event_origin       external | internal
--   status (outbox)    pending | delivered | failed
--   status (rejection) rejected | unhandled
--   phase              exit | transition | entry
--
-- Tables:
--   state_machine_instance     one row per instance: snapshot (the
--                              truth), pinned machine version (D16),
--                              optimistic lock version (CAS, RN-08)
--   state_machine_region       projection of the snapshot per region:
--                              current state and due instant of its
--                              deadline (D18; NULL once the instance is
--                              done); searched by (machine_key, due_at);
--                              paused_remaining (since 1.9.0, forward
--                              025, D11): whole seconds left of a paused
--                              deadline, whose due_at is then NULL (so
--                              a paused region is never due); since
--                              2.7.0 the seconds are in the unit of the
--                              deadline's calendar: working seconds of
--                              that calendar when the deadline names one
--                              (DeadlineCalendarRegistry), wall-clock
--                              seconds when it does not
--   state_machine_history      append-only trail (D15, RN-07): one row
--                              per region moved, plus start and
--                              migration rows; key = (instance,
--                              instance version, sort order)
--   state_machine_outbox       effects written with the step (D12,
--                              RN-10), delivered after the commit
--   state_machine_idempotency  result of each keyed accepted event
--                              (RN-09); UNIQUE (instance_id,
--                              idempotency_key)
--   state_machine_rejection    optional rejection log (RN-07, off by
--                              default)
--   state_machine_definition   (since 2.7.0) published machine
--                              definitions, read by
--                              PdoMachineDefinitionRepository: one row per
--                              (machine_key, version), immutable; the
--                              canonical JSON of the definition, its
--                              fingerprint (checked on every read) and
--                              who published it, when and why (NULL for
--                              a definition registered by code)
--   state_machine_definition_draft
--                              (since 2.7.0) at most one draft per
--                              machine key: the array a screen saved,
--                              the version it started from and its
--                              revision (compare-and-set)
--
-- Opt-in columns (since 2.7.0), read and written only when the stores
-- are given columns: PdoMachineSchema::COLUMNS_SINCE_2_7 (and the
-- definition repository, MachinePersistence::pdo(..., definitions:)):
--   tenant             state_machine_instance and state_machine_region:
--                      the tenant the instance belongs to (an opaque
--                      text of the application); the region copy lets
--                      findDueForTenant() filter without a join, over
--                      UNIQUE (tenant, machine_key, due_at, ...)
--   current_state_code state_machine_region: the legacy code of the
--                      current state (StateDefinition::$code), derived
--                      from the definition; from_state_code and
--                      to_state_code in state_machine_history. Declared
--                      VARCHAR(120) here, which holds integer and text
--                      codes; when every code is an integer, declare
--                      them INTEGER and add a foreign key to the legacy
--                      table (the stores write an integer code as an
--                      integer)
--   definition (TEXT)  a real definition is larger than VARCHAR(4000);
--                      TEXT is accepted by PostgreSQL, MySQL/MariaDB
--                      (64 KiB; MEDIUMTEXT above that) and SQLite; use
--                      CLOB (Oracle, DB2) or NVARCHAR(MAX) (SQL Server)
--                      elsewhere
--
-- Upgrading a database created by the 1.8 script (1.9.0): CREATE TABLE
-- IF NOT EXISTS does not add a column to an existing table, so add the
-- nullable column once, by hand (portable ANSI; MySQL, PostgreSQL and
-- SQLite accept it as is):
--
--   ALTER TABLE state_machine_region ADD COLUMN paused_remaining INTEGER;
--
-- Until then the 1.9 stores keep working with the old table for every
-- definition that never pauses a deadline (the column is only written
-- for a paused region); the first paused region fails without it.
--
-- Additive migration 2.6 -> 2.7.0, for tables created by an older
-- script. This script creates the two new tables by itself (CREATE
-- TABLE IF NOT EXISTS); the opt-in columns of the existing tables and
-- their index are added once, by hand (PostgreSQL, MySQL, MariaDB and
-- SQLite accept them as they are), then the stores are given columns:
-- PdoMachineSchema::COLUMNS_SINCE_2_7. Nothing changes for an
-- application that does not run it: without the columns there is no
-- tenant nor code projection, and everything else works as before.
--
--   ALTER TABLE state_machine_instance ADD COLUMN tenant VARCHAR(120);
--   ALTER TABLE state_machine_region ADD COLUMN tenant VARCHAR(120);
--   ALTER TABLE state_machine_region ADD COLUMN current_state_code VARCHAR(120);
--   ALTER TABLE state_machine_history ADD COLUMN from_state_code VARCHAR(120);
--   ALTER TABLE state_machine_history ADD COLUMN to_state_code VARCHAR(120);
--   CREATE UNIQUE INDEX uq_smr_tenant_due ON state_machine_region (tenant, machine_key, due_at, instance_id, region);
-- =====================================================================

CREATE TABLE IF NOT EXISTS state_machine_instance (
    instance_id              VARCHAR(120)   NOT NULL,
    machine_key              VARCHAR(120)   NOT NULL,
    machine_version          INTEGER        NOT NULL,
    version                  INTEGER        NOT NULL,
    snapshot                 VARCHAR(4000)  NOT NULL,
    done                     CHAR(1)        DEFAULT 'N' NOT NULL,
    created_at               VARCHAR(26)    NOT NULL,
    updated_at               VARCHAR(26)    NOT NULL,
    tenant                   VARCHAR(120),
    CONSTRAINT pk_state_machine_instance PRIMARY KEY (instance_id),
    CONSTRAINT chk_smi_done CHECK (done IN ('Y', 'N')),
    CONSTRAINT chk_smi_version CHECK (version >= 1 AND machine_version >= 1)
);

CREATE TABLE IF NOT EXISTS state_machine_region (
    instance_id              VARCHAR(120)   NOT NULL,
    region                   VARCHAR(120)   NOT NULL,
    machine_key              VARCHAR(120)   NOT NULL,
    current_state            VARCHAR(120)   NOT NULL,
    due_at                   VARCHAR(26),
    sort_order               INTEGER        NOT NULL,
    paused_remaining         INTEGER,
    tenant                   VARCHAR(120),
    current_state_code       VARCHAR(120),
    CONSTRAINT pk_state_machine_region PRIMARY KEY (instance_id, region),
    CONSTRAINT fk_smr_instance FOREIGN KEY (instance_id)
        REFERENCES state_machine_instance (instance_id) ON DELETE CASCADE,
    CONSTRAINT uq_smr_due UNIQUE (machine_key, due_at, instance_id, region),
    CONSTRAINT uq_smr_tenant_due UNIQUE (tenant, machine_key, due_at, instance_id, region)
);

CREATE TABLE IF NOT EXISTS state_machine_history (
    instance_id              VARCHAR(120)   NOT NULL,
    instance_version         INTEGER        NOT NULL,
    sort_order               INTEGER        NOT NULL,
    machine_key              VARCHAR(120)   NOT NULL,
    machine_version          INTEGER        NOT NULL,
    region                   VARCHAR(120)   NOT NULL,
    from_state               VARCHAR(120),
    to_state                 VARCHAR(120)   NOT NULL,
    event_type               VARCHAR(120)   NOT NULL,
    transition_id            VARCHAR(120),
    payload                  VARCHAR(4000),
    actor                    VARCHAR(120),
    idempotency_key          VARCHAR(190),
    event_origin             VARCHAR(10)    NOT NULL,
    occurred_at              VARCHAR(26)    NOT NULL,
    from_state_code          VARCHAR(120),
    to_state_code            VARCHAR(120),
    CONSTRAINT pk_state_machine_history PRIMARY KEY (instance_id, instance_version, sort_order),
    CONSTRAINT chk_smh_origin CHECK (event_origin IN ('external', 'internal'))
);

CREATE TABLE IF NOT EXISTS state_machine_outbox (
    instance_id              VARCHAR(120)   NOT NULL,
    instance_version         INTEGER        NOT NULL,
    sort_order               INTEGER        NOT NULL,
    machine_key              VARCHAR(120)   NOT NULL,
    machine_version          INTEGER        NOT NULL,
    action_name              VARCHAR(120)   NOT NULL,
    params                   VARCHAR(4000),
    region                   VARCHAR(120)   NOT NULL,
    phase                    VARCHAR(10)    NOT NULL,
    state_name               VARCHAR(120)   NOT NULL,
    transition_id            VARCHAR(120),
    status                   VARCHAR(10)    DEFAULT 'pending' NOT NULL,
    attempts                 INTEGER        DEFAULT 0 NOT NULL,
    last_error               VARCHAR(2000),
    enqueued_at              VARCHAR(26)    NOT NULL,
    delivered_at             VARCHAR(26),
    last_attempt_at          VARCHAR(26),
    CONSTRAINT pk_state_machine_outbox PRIMARY KEY (instance_id, instance_version, sort_order),
    CONSTRAINT uq_smo_pending UNIQUE (status, enqueued_at, instance_id, instance_version, sort_order),
    CONSTRAINT chk_smo_status CHECK (status IN ('pending', 'delivered', 'failed')),
    CONSTRAINT chk_smo_phase CHECK (phase IN ('exit', 'transition', 'entry')),
    CONSTRAINT chk_smo_attempts CHECK (attempts >= 0)
);

CREATE TABLE IF NOT EXISTS state_machine_idempotency (
    instance_id              VARCHAR(120)   NOT NULL,
    instance_version         INTEGER        NOT NULL,
    idempotency_key          VARCHAR(190)   NOT NULL,
    transition_result        VARCHAR(4000)  NOT NULL,
    recorded_at              VARCHAR(26)    NOT NULL,
    CONSTRAINT pk_state_machine_idempotency PRIMARY KEY (instance_id, instance_version),
    CONSTRAINT uq_smi_idempotency UNIQUE (instance_id, idempotency_key)
);

CREATE TABLE IF NOT EXISTS state_machine_rejection (
    rejection_id             VARCHAR(40)    NOT NULL,
    instance_id              VARCHAR(120)   NOT NULL,
    instance_version         INTEGER        NOT NULL,
    machine_key              VARCHAR(120)   NOT NULL,
    machine_version          INTEGER        NOT NULL,
    status                   VARCHAR(10)    NOT NULL,
    event_type               VARCHAR(120)   NOT NULL,
    payload                  VARCHAR(4000),
    actor                    VARCHAR(120),
    idempotency_key          VARCHAR(190),
    event_origin             VARCHAR(10)    NOT NULL,
    reason                   VARCHAR(4000)  NOT NULL,
    occurred_at              VARCHAR(26)    NOT NULL,
    CONSTRAINT pk_state_machine_rejection PRIMARY KEY (rejection_id),
    CONSTRAINT uq_smrj_instance UNIQUE (instance_id, rejection_id),
    CONSTRAINT chk_smrj_status CHECK (status IN ('rejected', 'unhandled')),
    CONSTRAINT chk_smrj_origin CHECK (event_origin IN ('external', 'internal'))
);

CREATE TABLE IF NOT EXISTS state_machine_definition (
    machine_key              VARCHAR(120)   NOT NULL,
    version                  INTEGER        NOT NULL,
    definition               TEXT           NOT NULL,
    fingerprint              CHAR(64)       NOT NULL,
    published_at             VARCHAR(26),
    published_by             VARCHAR(120),
    reason                   VARCHAR(2000),
    CONSTRAINT pk_state_machine_definition PRIMARY KEY (machine_key, version),
    CONSTRAINT chk_smd_version CHECK (version >= 1)
);

CREATE TABLE IF NOT EXISTS state_machine_definition_draft (
    machine_key              VARCHAR(120)   NOT NULL,
    definition               TEXT           NOT NULL,
    based_on_version         INTEGER,
    revision                 INTEGER        NOT NULL,
    updated_at               VARCHAR(26)    NOT NULL,
    updated_by               VARCHAR(120),
    CONSTRAINT pk_state_machine_definition_draft PRIMARY KEY (machine_key),
    CONSTRAINT chk_smdd_revision CHECK (revision >= 1)
);
