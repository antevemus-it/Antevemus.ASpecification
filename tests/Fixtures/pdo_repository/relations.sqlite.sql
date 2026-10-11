-- Forward 031, E3 (relationships) test schema, SQLite (foreign keys enforced by the test connection).
DROP TABLE IF EXISTS aspec_rel_invoice_payment;
DROP TABLE IF EXISTS aspec_rel_invoice_line;
DROP TABLE IF EXISTS aspec_rel_invoice;
DROP TABLE IF EXISTS aspec_rel_order_item;
DROP TABLE IF EXISTS aspec_rel_order;
DROP TABLE IF EXISTS aspec_rel_product;
DROP TABLE IF EXISTS aspec_rel_customer;
CREATE TABLE aspec_rel_customer (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50) NOT NULL, country VARCHAR(2) NOT NULL, deleted_at TEXT NULL);
CREATE TABLE aspec_rel_product (sku VARCHAR(20) NOT NULL PRIMARY KEY, title VARCHAR(50) NOT NULL);
CREATE TABLE aspec_rel_order (id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR(20) NOT NULL, customer_id INTEGER NULL REFERENCES aspec_rel_customer (id), version INTEGER NULL);
CREATE TABLE aspec_rel_order_item (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES aspec_rel_order (id), product_sku VARCHAR(20) NULL REFERENCES aspec_rel_product (sku), qty INTEGER NOT NULL CHECK (qty > 0), deleted_at TEXT NULL);
CREATE INDEX aspec_rel_order_item_order ON aspec_rel_order_item (order_id);
CREATE TABLE aspec_rel_invoice (code VARCHAR(20) NOT NULL PRIMARY KEY, customer_name VARCHAR(50) NOT NULL, deleted_at TEXT NULL);
CREATE TABLE aspec_rel_invoice_line (id INTEGER NOT NULL PRIMARY KEY, invoice_code VARCHAR(20) NOT NULL REFERENCES aspec_rel_invoice (code), description VARCHAR(50) NOT NULL);
CREATE TABLE aspec_rel_invoice_payment (id VARCHAR(26) NOT NULL PRIMARY KEY, invoice_code VARCHAR(20) NOT NULL REFERENCES aspec_rel_invoice (code), cents INTEGER NOT NULL CHECK (cents > 0), deleted_at TEXT NULL);
