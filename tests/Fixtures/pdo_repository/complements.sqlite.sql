-- Forward 031 (PdoRepository complements) and forward 032, D4 (CHAR booleans) test schema, SQLite. Loaded after schema.sqlite.sql.
DROP TABLE IF EXISTS aspec_flag;
CREATE TABLE aspec_flag (id INTEGER PRIMARY KEY, active SMALLINT NULL, native BOOLEAN NULL, label VARCHAR(50) NULL);
DROP TABLE IF EXISTS aspec_local_event;
CREATE TABLE aspec_local_event (id INTEGER PRIMARY KEY, happened_at TEXT NULL, utc_at TEXT NULL, day TEXT NULL);
DROP TABLE IF EXISTS aspec_note;
CREATE TABLE aspec_note (id VARCHAR(26) PRIMARY KEY, title VARCHAR(100) NOT NULL, score INTEGER NOT NULL, created_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL);
DROP TABLE IF EXISTS aspec_trash_doc;
CREATE TABLE aspec_trash_doc (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(100) NOT NULL, version INTEGER NULL, deleted_at TEXT NULL, updated_at TEXT NULL);
DROP TABLE IF EXISTS aspec_sale;
CREATE TABLE aspec_sale (id INTEGER NOT NULL PRIMARY KEY, region VARCHAR(10) NULL, product VARCHAR(20) NULL, qty INTEGER NULL, amount TEXT NULL, weight REAL NULL, sold_on TEXT NULL, version INTEGER NULL, updated_at TEXT NULL, deleted_at TEXT NULL);
DROP TABLE IF EXISTS aspec_region;
CREATE TABLE aspec_region (code VARCHAR(10) NOT NULL PRIMARY KEY, zone VARCHAR(10) NULL, deleted_at TEXT NULL);
-- Forward 032, D4: booleans stored as text.
DROP TABLE IF EXISTS aspec_char_flag;
CREATE TABLE aspec_char_flag (id INTEGER PRIMARY KEY, active CHAR(1) NULL, removed CHAR(1) NULL, answer VARCHAR(5) NULL);
