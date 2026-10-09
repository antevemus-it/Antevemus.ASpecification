-- =====================================================================
-- Antevemus.ASpecification - relational rule catalog (reference DDL)
-- =====================================================================
-- Read by Antevemus\ASpecification\Engine\PdoRuleCatalog (since 1.5.0).
--
-- Portable ANSI SQL: no schema, no dialect types, unquoted identifiers.
-- Only the columns the engine reads are declared; a consumer table may
-- carry more (audit columns, foreign keys to product and plan tables,
-- identity/serial keys) and may live in any schema, passed to
-- PdoRuleCatalog through its constructor together with the table names.
--
-- Mirrors regra_negocio, grupo_documento_obrigatorio and
-- grupo_documento_obrigatorio_tipo. Conventions:
--   active                  'Y' or 'N'; only 'Y' rows are read
--   escopo (rule)           NULL or empty = global rule (every scope)
--   cenario                 NULL or empty = whole scope; otherwise exact match
--   codigo_produto/plano    NULL or empty = no restriction (RN-17)
--   data_*_vigencia         NULL = open bound; ISO dates (YYYY-MM-DD)
--   parametros              JSON object as text (NULL = no parameters);
--                           applicability keys inside it are ignored
--   acao_ao_violar          bloquear | alertar | apenas_log
--   regra_obrigatoriedade   all | any | one_of_set (any/one_of_set need a set)
-- =====================================================================

CREATE TABLE regra_negocio (
    codigo                   VARCHAR(80)    NOT NULL,
    nome                     VARCHAR(200)   NOT NULL,
    descricao                VARCHAR(2000),
    tipo_regra               VARCHAR(80)    NOT NULL,
    acao_ao_violar           VARCHAR(20)    DEFAULT 'bloquear' NOT NULL,
    valor_inteiro            INTEGER,
    valor_decimal            NUMERIC(14,4),
    valor_texto              VARCHAR(2000),
    fundamento_legal         VARCHAR(255),
    mensagem_violacao        VARCHAR(2000),
    condicional_expressao    VARCHAR(255),
    prioridade               INTEGER        DEFAULT 0 NOT NULL,
    escopo                   VARCHAR(80),
    cenario                  VARCHAR(80),
    parametros               VARCHAR(4000),
    codigo_produto           VARCHAR(40),
    codigo_plano             VARCHAR(40),
    data_inicio_vigencia     DATE,
    data_fim_vigencia        DATE,
    active                   CHAR(1)        DEFAULT 'Y' NOT NULL,
    CONSTRAINT pk_regra_negocio PRIMARY KEY (codigo),
    CONSTRAINT chk_regra_negocio_active CHECK (active IN ('Y', 'N')),
    CONSTRAINT chk_regra_negocio_acao CHECK (acao_ao_violar IN ('bloquear', 'alertar', 'apenas_log')),
    CONSTRAINT chk_regra_negocio_plano CHECK (codigo_plano IS NULL OR codigo_produto IS NOT NULL),
    CONSTRAINT chk_regra_negocio_vigencia CHECK (
        data_fim_vigencia IS NULL OR data_inicio_vigencia IS NULL OR data_fim_vigencia >= data_inicio_vigencia
    )
);

CREATE INDEX idx_regra_negocio_escopo ON regra_negocio (escopo, cenario);

CREATE TABLE grupo_documento_obrigatorio (
    id                       BIGINT         NOT NULL,
    codigo                   VARCHAR(80)    NOT NULL,
    escopo                   VARCHAR(40)    NOT NULL,
    cenario                  VARCHAR(80),
    ordem                    INTEGER        DEFAULT 0 NOT NULL,
    active                   CHAR(1)        DEFAULT 'Y' NOT NULL,
    CONSTRAINT pk_grupo_documento_obrigatorio PRIMARY KEY (id),
    CONSTRAINT uq_grupo_documento_obrigatorio UNIQUE (codigo),
    CONSTRAINT chk_gdo_active CHECK (active IN ('Y', 'N'))
);

CREATE INDEX idx_gdo_escopo ON grupo_documento_obrigatorio (escopo, cenario);

CREATE TABLE grupo_documento_obrigatorio_tipo (
    id                       BIGINT         NOT NULL,
    grupo_id                 BIGINT         NOT NULL,
    codigo_tipo_documento    VARCHAR(50)    NOT NULL,
    regra_obrigatoriedade    VARCHAR(20)    DEFAULT 'all' NOT NULL,
    codigo_set_alternativas  VARCHAR(40),
    condicional_expressao    VARCHAR(255),
    ordem                    INTEGER        DEFAULT 0 NOT NULL,
    active                   CHAR(1)        DEFAULT 'Y' NOT NULL,
    CONSTRAINT pk_grupo_documento_obrigatorio_tipo PRIMARY KEY (id),
    CONSTRAINT fk_gdot_grupo FOREIGN KEY (grupo_id)
        REFERENCES grupo_documento_obrigatorio (id) ON DELETE CASCADE,
    CONSTRAINT uq_gdot UNIQUE (grupo_id, codigo_tipo_documento, codigo_set_alternativas),
    CONSTRAINT chk_gdot_active CHECK (active IN ('Y', 'N')),
    CONSTRAINT chk_gdot_regra CHECK (regra_obrigatoriedade IN ('all', 'any', 'one_of_set')),
    CONSTRAINT chk_gdot_set CHECK (regra_obrigatoriedade = 'all' OR codigo_set_alternativas IS NOT NULL)
);

CREATE INDEX idx_gdot_grupo ON grupo_documento_obrigatorio_tipo (grupo_id);
