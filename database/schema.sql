-- Sistema de Ordenes de Trabajo - Esquema PostgreSQL
-- Reclamos y Ordenes de trabajo son tablas separadas: un reclamo puede generar
-- mas de una orden (p. ej. si la primera no se pudo realizar y se reasigna).

CREATE TABLE IF NOT EXISTS usuarios (
    id          SERIAL PRIMARY KEY,
    nombre      VARCHAR(120) NOT NULL,
    correo      VARCHAR(160) NOT NULL UNIQUE,
    clave_hash  VARCHAR(255) NOT NULL,
    rol         VARCHAR(20)  NOT NULL CHECK (rol IN ('supervisor', 'operador')),
    activo      BOOLEAN      NOT NULL DEFAULT TRUE,
    creado_en   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Tipos de trabajo (acometida, fuga, adoquinamiento, ...). El color se usa en el mapa tematico.
CREATE TABLE IF NOT EXISTS trabajos (
    id      SERIAL PRIMARY KEY,
    nombre  VARCHAR(80) NOT NULL UNIQUE,
    color   CHAR(7)     NOT NULL DEFAULT '#1f77b4' CHECK (color ~ '^#[0-9a-fA-F]{6}$'),
    activo  BOOLEAN     NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS reclamos (
    id             SERIAL PRIMARY KEY,
    fecha          DATE         NOT NULL DEFAULT CURRENT_DATE,
    nombre         VARCHAR(120) NOT NULL,
    direccion      VARCHAR(200) NOT NULL,
    reclamo        TEXT         NOT NULL,
    telefono       VARCHAR(30),
    medidor        VARCHAR(30),
    observaciones  TEXT,
    latitud        DOUBLE PRECISION,   -- ubicacion aproximada puesta por el supervisor
    longitud       DOUBLE PRECISION,
    estado         VARCHAR(12)  NOT NULL DEFAULT 'pendiente'
                   CHECK (estado IN ('pendiente', 'asignado', 'atendido', 'anulado')),
    creado_por     INTEGER REFERENCES usuarios(id),
    creado_en      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_reclamos_estado ON reclamos(estado);

CREATE TABLE IF NOT EXISTS ordenes_trabajo (
    id                   SERIAL PRIMARY KEY,
    reclamo_id           INTEGER NOT NULL REFERENCES reclamos(id),
    -- Asignacion (supervisor)
    fecha_asignacion     DATE    NOT NULL DEFAULT CURRENT_DATE,
    trabajo_id           INTEGER NOT NULL REFERENCES trabajos(id),
    operador1_id         INTEGER NOT NULL REFERENCES usuarios(id),
    operador2_id         INTEGER REFERENCES usuarios(id),
    observaciones        TEXT,
    readoquinamiento     BOOLEAN NOT NULL DEFAULT FALSE,
    supervisor_id        INTEGER REFERENCES usuarios(id),
    estado               VARCHAR(12) NOT NULL DEFAULT 'asignada'
                         CHECK (estado IN ('asignada', 'realizada', 'no_realizada')),
    creado_en            TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Ejecucion (operadores, desde la app movil)
    fecha_realizacion    TIMESTAMP,
    trabajo_realizado_id INTEGER REFERENCES trabajos(id),
    detalle_realizado    TEXT,
    readoquinamiento_real BOOLEAN,
    latitud_real         DOUBLE PRECISION,
    longitud_real        DOUBLE PRECISION,
    foto                 VARCHAR(120),
    motivo_no_realizada  TEXT,
    registrado_por       INTEGER REFERENCES usuarios(id),
    sincronizado_en      TIMESTAMPTZ,
    CHECK (operador2_id IS NULL OR operador2_id <> operador1_id)
);
CREATE INDEX IF NOT EXISTS idx_ordenes_fecha_asig ON ordenes_trabajo(fecha_asignacion);
CREATE INDEX IF NOT EXISTS idx_ordenes_fecha_real ON ordenes_trabajo(fecha_realizacion);
CREATE INDEX IF NOT EXISTS idx_ordenes_estado     ON ordenes_trabajo(estado);
CREATE INDEX IF NOT EXISTS idx_ordenes_reclamo    ON ordenes_trabajo(reclamo_id);

-- Tokens de sesion de la app movil (se guarda solo el SHA-256 del token)
CREATE TABLE IF NOT EXISTS api_tokens (
    token_hash  CHAR(64) PRIMARY KEY,
    usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    creado_en   TIMESTAMPTZ NOT NULL DEFAULT now(),
    expira_en   TIMESTAMPTZ NOT NULL
);
