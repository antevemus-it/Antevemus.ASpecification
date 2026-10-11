-- Forward 028 (PdoRepository) test schema, MySQL 8.4. Runs only in a disposable container.
-- Text columns use utf8mb4_bin: with the default accent/case-insensitive collation `=` and LIKE
-- would match more rows than the in-memory evaluation (parity of RN-02/RN-03).
DROP TABLE IF EXISTS aspec_article;
CREATE TABLE aspec_article (id VARCHAR(26) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(200) COLLATE utf8mb4_bin NOT NULL, status VARCHAR(20) COLLATE utf8mb4_bin NOT NULL, views INT NOT NULL, price DECIMAL(10,2) NOT NULL, published TINYINT(1) NOT NULL, published_at DATETIME(6) NULL, tags JSON NOT NULL, note TEXT COLLATE utf8mb4_bin NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_counter;
CREATE TABLE aspec_counter (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) COLLATE utf8mb4_bin NOT NULL UNIQUE, lock_version INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_counter_plain;
CREATE TABLE aspec_counter_plain (id INT NOT NULL PRIMARY KEY, label VARCHAR(100) COLLATE utf8mb4_bin NOT NULL UNIQUE, lock_version INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_typed;
CREATE TABLE aspec_typed (id VARCHAR(40) COLLATE utf8mb4_bin PRIMARY KEY, i INT NULL, f DOUBLE NULL, d DECIMAL(12,4) NULL, b TINYINT(1) NULL, day DATE NULL, moment DATETIME(6) NULL, moment_mut DATETIME(6) NULL, payload JSON NULL, priority INT NULL, color VARCHAR(10) COLLATE utf8mb4_bin NULL, raw VARCHAR(50) COLLATE utf8mb4_bin NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_document;
CREATE TABLE aspec_document (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) COLLATE utf8mb4_bin NOT NULL, version INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_secret;
CREATE TABLE aspec_secret (id INT NOT NULL PRIMARY KEY, secret VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, marked VARCHAR(100) COLLATE utf8mb4_bin NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_tag;
CREATE TABLE aspec_tag (id VARCHAR(36) COLLATE utf8mb4_bin PRIMARY KEY, name VARCHAR(100) COLLATE utf8mb4_bin NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS aspec_null_parity;
CREATE TABLE aspec_null_parity (id INT NOT NULL PRIMARY KEY, n INT NULL, m INT NULL, s VARCHAR(20) COLLATE utf8mb4_bin NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
