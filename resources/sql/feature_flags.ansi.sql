-- =====================================================================
-- Antevemus.ASpecification - declared feature flags (reference DDL)
-- =====================================================================
-- Written and read by Antevemus\ASpecification\FeatureFlags\
-- PdoFeatureFlagStore (since 2.1.0, forward 034, D4), behind the
-- FeatureFlags service. 2.3.0 (forward 040) added three columns:
-- feature_flag.rollout_percent and feature_flag_change.change_kind
-- and .detail; PdoFeatureFlagStore reads and writes them when told so
-- (columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3), so that a table
-- created by the DDL of 2.1.0 keeps working untouched. The additive
-- migration for such a table is at the end of this header.
--
-- Portable SQL: no schema, no dialect types, unquoted lowercase
-- identifiers, a PRIMARY KEY on every table. The script is idempotent:
-- every statement is CREATE TABLE IF NOT EXISTS (the one construct
-- outside the ANSI core, accepted by PostgreSQL, MySQL/MariaDB and
-- SQLite). There is no CREATE INDEX statement, because MySQL has no
-- CREATE INDEX IF NOT EXISTS: the lookup index of the history is a
-- UNIQUE constraint whose leading columns are the searched ones.
--
-- A consumer table may carry more columns (identity keys, audit
-- columns, soft delete) and live in any schema, given to
-- PdoFeatureFlagStore together with the table names by role (flag,
-- change, subject) and, when they differ, the column names by role
-- (canonical column => real column; null = the table does not have
-- it). A history table shared with other audit trails is read and
-- written through fixed column values ($changeScope).
--
-- What lives where: the code declares the flags (FeatureFlagRegistry:
-- name, description, impact texts, origin, targeting specification);
-- the database holds the switch, the validity window, the subject list
-- and the history. The texts of a row are only shown for a flag that
-- is not declared any more.
--
-- Conventions (those of resources/sql/state_machine.ansi.sql):
--   code                 lowercase letter followed by 2 to 79 lowercase
--                        letters, digits or underscores (validated by
--                        the library: a regular expression CHECK is not
--                        portable)
--   enabled              'Y' or 'N', DEFAULT 'N': every flag is born off
--                        (BooleanStorage::Char; a native boolean or an
--                        integer column is configured with $booleans)
--   old_enabled          NULL = the row of the flag did not exist yet
--   new_enabled          'Y' or 'N'
--   *_at, valid_from,    instants as fixed-width text
--   valid_until          'YYYY-MM-DD HH:MM:SS.ffffff' (UTC by default),
--                        so that ordering and comparison are
--                        lexicographic and identical in every database;
--                        a validity bound written as a date only
--                        ('YYYY-MM-DD') covers the whole day; both
--                        bounds are inclusive; NULL = open
--   *_by, changed_by     the actor given by the application (NULL = a
--                        script without a user)
--   version              optimistic version, incremented on every
--                        change of the switch, of the validity window
--                        and of the rollout percentage; the window and
--                        the rollout are compare-and-set by version
--   rollout_percent      0 to 100, NULL = no rollout (every subject);
--                        a subject is in when its bucket (xxh3 of the
--                        code and the subject key, 0 to 9999) is below
--                        rollout_percent * 100
--   reason               normalized by the library (whitespace runs
--                        collapsed), 10 to 1000 characters by default
--   origin               the caller's text: ui | migration | script, or
--                        the application's own
--   change_id            ULID written by the library (26 characters);
--                        with an identity/serial key instead, set
--                        $databaseGeneratedChangeId
--   subject_key (change) NULL = the switch changed; otherwise the subject
--                        was added (new_enabled 'Y') or removed ('N')
--   change_kind          toggle | subject | validity | rollout; DEFAULT
--                        'toggle' (a subject_key makes a row written
--                        before 2.3.0 read as subject)
--   detail               what a validity or rollout change set, in
--                        words: '2026-11-01T00:00:00+00:00 → open',
--                        'cleared', '25% → 50%', 'none → 25%'
--
-- Tables:
--   feature_flag          one row per flag
--   feature_flag_change   append-only history: the library writes one
--                         row per change, in the transaction of the
--                         change, and never updates nor deletes it
--   feature_flag_subject  subject list of a flag (the pilot "only these
--                         subjects"); an empty list = no restriction
--
-- Index size: flag_code 80 + changed_at 26 + change_id 26 characters
-- stay under the 3072-byte InnoDB key limit with utf8mb4.
--
-- Optional, PostgreSQL only (a trigger is not portable): make the
-- history append-only in the database too. Run it by hand, once:
--
--   CREATE OR REPLACE FUNCTION feature_flag_change_append_only()
--   RETURNS trigger LANGUAGE plpgsql AS $$
--   BEGIN
--       RAISE EXCEPTION 'feature_flag_change is append-only';
--   END;
--   $$;
--
--   CREATE TRIGGER trg_feature_flag_change_append_only
--       BEFORE UPDATE OR DELETE ON feature_flag_change
--       FOR EACH ROW EXECUTE FUNCTION feature_flag_change_append_only();
--
-- Additive migration 2.1.0 -> 2.3.0 (forward 040), for tables created
-- by the DDL of 2.1.0 or 2.2.0. Nothing changes for an application that
-- does not run it: without the columns there is no rollout and the
-- history rows have no kind nor detail. Run it once (PostgreSQL, MySQL
-- 8.0.16+, MariaDB and SQLite accept the column CHECK of ADD COLUMN),
-- then pass columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3. The last
-- statement marks the subject rows written before 2.3.0 (they read as
-- subject without it too):
--
--   ALTER TABLE feature_flag ADD COLUMN rollout_percent SMALLINT CHECK (rollout_percent IS NULL OR (rollout_percent >= 0 AND rollout_percent <= 100));
--   ALTER TABLE feature_flag_change ADD COLUMN change_kind VARCHAR(20) DEFAULT 'toggle' NOT NULL CHECK (change_kind IN ('toggle', 'subject', 'validity', 'rollout'));
--   ALTER TABLE feature_flag_change ADD COLUMN detail VARCHAR(400);
--   UPDATE feature_flag_change SET change_kind = 'subject' WHERE subject_key IS NOT NULL;
-- =====================================================================

CREATE TABLE IF NOT EXISTS feature_flag (
    code                     VARCHAR(80)    NOT NULL,
    name                     VARCHAR(200)   NOT NULL,
    description              VARCHAR(2000),
    category                 VARCHAR(80),
    enabled                  CHAR(1)        DEFAULT 'N' NOT NULL,
    audience                 VARCHAR(255),
    notes                    VARCHAR(2000),
    valid_from               VARCHAR(26),
    valid_until              VARCHAR(26),
    enabled_at               VARCHAR(26),
    enabled_by               VARCHAR(120),
    disabled_at              VARCHAR(26),
    disabled_by              VARCHAR(120),
    version                  INTEGER        DEFAULT 1 NOT NULL,
    rollout_percent          SMALLINT,
    CONSTRAINT pk_feature_flag PRIMARY KEY (code),
    CONSTRAINT chk_ff_enabled CHECK (enabled IN ('Y', 'N')),
    CONSTRAINT chk_ff_version CHECK (version >= 1),
    CONSTRAINT chk_ff_rollout CHECK (
        rollout_percent IS NULL OR (rollout_percent >= 0 AND rollout_percent <= 100)
    ),
    CONSTRAINT chk_ff_validity CHECK (
        valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from
    )
);

CREATE TABLE IF NOT EXISTS feature_flag_change (
    change_id                VARCHAR(26)    NOT NULL,
    flag_code                VARCHAR(80)    NOT NULL,
    subject_key              VARCHAR(190),
    old_enabled              CHAR(1),
    new_enabled              CHAR(1)        NOT NULL,
    reason                   VARCHAR(1000)  NOT NULL,
    origin                   VARCHAR(20)    NOT NULL,
    changed_at               VARCHAR(26)    NOT NULL,
    changed_by               VARCHAR(120),
    change_kind              VARCHAR(20)    DEFAULT 'toggle' NOT NULL,
    detail                   VARCHAR(400),
    CONSTRAINT pk_feature_flag_change PRIMARY KEY (change_id),
    CONSTRAINT uq_ffc_flag UNIQUE (flag_code, changed_at, change_id),
    CONSTRAINT chk_ffc_old CHECK (old_enabled IS NULL OR old_enabled IN ('Y', 'N')),
    CONSTRAINT chk_ffc_new CHECK (new_enabled IN ('Y', 'N')),
    CONSTRAINT chk_ffc_kind CHECK (change_kind IN ('toggle', 'subject', 'validity', 'rollout'))
);

CREATE TABLE IF NOT EXISTS feature_flag_subject (
    flag_code                VARCHAR(80)    NOT NULL,
    subject_key              VARCHAR(190)   NOT NULL,
    CONSTRAINT pk_feature_flag_subject PRIMARY KEY (flag_code, subject_key),
    CONSTRAINT fk_ffs_flag FOREIGN KEY (flag_code)
        REFERENCES feature_flag (code) ON DELETE CASCADE
);
