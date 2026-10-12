-- =====================================================================
-- Antevemus.ASpecification - relational rule catalog (reference DDL)
-- =====================================================================
-- Read by Antevemus\ASpecification\Engine\PdoRuleCatalog (since 1.5.0;
-- English vocabulary since 1.7.0, only English since 2.0.0).
--
-- Portable ANSI SQL: no schema, no dialect types, unquoted identifiers.
-- Only the columns the engine reads are declared; a consumer table may
-- carry more (audit columns, foreign keys to product and plan tables,
-- identity/serial keys) and may live in any schema, passed to
-- PdoRuleCatalog through its constructor together with the table names
-- and, when they differ from the ones below, the column names ($columns,
-- canonical column => real column).
--
-- Tables: business_rule, document_requirement_group and
-- document_requirement. Conventions:
--   active                  'Y' or 'N'; only 'Y' rows are read
--   scope (rule)            NULL or empty = global rule (every scope)
--   scenario                NULL or empty = whole scope; otherwise exact match
--   product_code/plan_code  NULL or empty = no restriction (RN-17)
--   valid_from/valid_until  NULL = open bound; ISO dates (YYYY-MM-DD)
--   parameters              JSON object as text (NULL = no parameters);
--                           applicability keys inside it are ignored
--   violation_action        block | warn | log
--   requirement_mode        all | any | one_of_set (any/one_of_set need a set)
--
-- Tables created with the Portuguese schema of 1.5.0/1.6.x (regra_negocio,
-- grupo_documento_obrigatorio, grupo_documento_obrigatorio_tipo) are read
-- by name through PdoRuleCatalog::legacyPortugueseTables() and
-- PdoRuleCatalog::legacyPortugueseColumns(); since 2.0.0 their action
-- values must be English (bloquear, alertar and apenas_log are refused).
-- UPGRADE-2.0.md has the SQL that copies such tables into this DDL, or
-- updates them in place.
-- =====================================================================

CREATE TABLE business_rule (
    code                     VARCHAR(80)    NOT NULL,
    name                     VARCHAR(200)   NOT NULL,
    description              VARCHAR(2000),
    rule_type                VARCHAR(80)    NOT NULL,
    violation_action         VARCHAR(20)    DEFAULT 'block' NOT NULL,
    int_value                INTEGER,
    decimal_value            NUMERIC(14,4),
    text_value               VARCHAR(2000),
    legal_basis              VARCHAR(255),
    violation_message        VARCHAR(2000),
    condition_expression     VARCHAR(255),
    priority                 INTEGER        DEFAULT 0 NOT NULL,
    scope                    VARCHAR(80),
    scenario                 VARCHAR(80),
    parameters               VARCHAR(4000),
    product_code             VARCHAR(40),
    plan_code                VARCHAR(40),
    valid_from               DATE,
    valid_until              DATE,
    active                   CHAR(1)        DEFAULT 'Y' NOT NULL,
    CONSTRAINT pk_business_rule PRIMARY KEY (code),
    CONSTRAINT chk_business_rule_active CHECK (active IN ('Y', 'N')),
    CONSTRAINT chk_business_rule_action CHECK (violation_action IN ('block', 'warn', 'log')),
    CONSTRAINT chk_business_rule_plan CHECK (plan_code IS NULL OR product_code IS NOT NULL),
    CONSTRAINT chk_business_rule_validity CHECK (
        valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from
    )
);

CREATE INDEX idx_business_rule_scope ON business_rule (scope, scenario);

CREATE TABLE document_requirement_group (
    id                       BIGINT         NOT NULL,
    code                     VARCHAR(80)    NOT NULL,
    scope                    VARCHAR(40)    NOT NULL,
    scenario                 VARCHAR(80),
    sort_order               INTEGER        DEFAULT 0 NOT NULL,
    active                   CHAR(1)        DEFAULT 'Y' NOT NULL,
    CONSTRAINT pk_document_requirement_group PRIMARY KEY (id),
    CONSTRAINT uq_document_requirement_group UNIQUE (code),
    CONSTRAINT chk_drg_active CHECK (active IN ('Y', 'N'))
);

CREATE INDEX idx_drg_scope ON document_requirement_group (scope, scenario);

CREATE TABLE document_requirement (
    id                       BIGINT         NOT NULL,
    group_id                 BIGINT         NOT NULL,
    document_type_code       VARCHAR(50)    NOT NULL,
    requirement_mode         VARCHAR(20)    DEFAULT 'all' NOT NULL,
    alternative_set_code     VARCHAR(40),
    condition_expression     VARCHAR(255),
    sort_order               INTEGER        DEFAULT 0 NOT NULL,
    active                   CHAR(1)        DEFAULT 'Y' NOT NULL,
    CONSTRAINT pk_document_requirement PRIMARY KEY (id),
    CONSTRAINT fk_dr_group FOREIGN KEY (group_id)
        REFERENCES document_requirement_group (id) ON DELETE CASCADE,
    CONSTRAINT uq_dr UNIQUE (group_id, document_type_code, alternative_set_code),
    CONSTRAINT chk_dr_active CHECK (active IN ('Y', 'N')),
    CONSTRAINT chk_dr_mode CHECK (requirement_mode IN ('all', 'any', 'one_of_set')),
    CONSTRAINT chk_dr_set CHECK (requirement_mode = 'all' OR alternative_set_code IS NOT NULL)
);

CREATE INDEX idx_dr_group ON document_requirement (group_id);
