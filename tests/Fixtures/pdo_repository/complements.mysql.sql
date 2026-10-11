-- Forward 031 (PdoRepository complements) test schema, MySQL 8.4. Runs only in a disposable container.
-- The lifecycle columns are DATETIME without fractional seconds: the repository stamps whole seconds.
DROP TABLE IF EXISTS aspec_flag;
CREATE TABLE aspec_flag (id INT NOT NULL PRIMARY KEY, active SMALLINT NULL, native TINYINT(1) NULL, label VARCHAR(50) COLLATE utf8mb4_bin NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_local_event;
CREATE TABLE aspec_local_event (id INT NOT NULL PRIMARY KEY, happened_at DATETIME(6) NULL, utc_at DATETIME(6) NULL, day DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_note;
CREATE TABLE aspec_note (id VARCHAR(26) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, score INT NOT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_trash_doc;
CREATE TABLE aspec_trash_doc (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, version INT NULL, deleted_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_sale;
CREATE TABLE aspec_sale (id INT NOT NULL PRIMARY KEY, region VARCHAR(10) COLLATE utf8mb4_bin NULL, product VARCHAR(20) COLLATE utf8mb4_bin NULL, qty INT NULL, amount DECIMAL(12,2) NULL, weight DOUBLE NULL, sold_on DATE NULL, version INT NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_region;
CREATE TABLE aspec_region (code VARCHAR(10) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY, zone VARCHAR(10) COLLATE utf8mb4_bin NULL, deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
