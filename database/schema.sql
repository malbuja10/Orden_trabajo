-- Sistema de Ordenes de Trabajo - Esquema PostgreSQL (version 2)
-- Cada reclamo registrado es directamente una Orden de Trabajo pendiente (una sola tabla).
-- Cualquier operador puede realizarla; al regresar a la oficina, el supervisor registra
-- quien hizo el trabajo y la orden queda finalizada.

CREATE TABLE IF NOT EXISTS usuarios (
    id          SERIAL PRIMARY KEY,
    nombre      VARCHAR(120) NOT NULL,
    correo      VARCHAR(160) NOT NULL UNIQUE,
    clave_hash  VARCHAR(255) NOT NULL,
    rol         VARCHAR(20)  NOT NULL CHECK (rol IN ('supervisor', 'operador')),
    activo      BOOLEAN      NOT NULL DEFAULT TRUE,
    creado_en   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Tipos de trabajo (acometida, fuga, adoquinamiento, ...). Es tambien el "reclamo".
-- El color se usa en el mapa tematico.
CREATE TABLE IF NOT EXISTS trabajos (
    id      SERIAL PRIMARY KEY,
    nombre  VARCHAR(80) NOT NULL UNIQUE,
    color   CHAR(7)     NOT NULL DEFAULT '#1f77b4' CHECK (color ~ '^#[0-9a-fA-F]{6}$'),
    activo  BOOLEAN     NOT NULL DEFAULT TRUE
);

-- Catalogo de observaciones del reclamo
CREATE TABLE IF NOT EXISTS observaciones (
    id      SERIAL PRIMARY KEY,
    nombre  VARCHAR(120) NOT NULL UNIQUE,
    activo  BOOLEAN      NOT NULL DEFAULT TRUE
);

-- Cuentas de los clientes (se cargan desde el sistema comercial, p. ej. con un CSV).
-- Si la cuenta no tiene coordenadas, se usan las de su medidor en ubicacion_medidor.
CREATE TABLE IF NOT EXISTS cuentas (
    cuenta     VARCHAR(30) PRIMARY KEY,
    nombre     VARCHAR(160),
    direccion  VARCHAR(200),
    medidor    VARCHAR(30),
    latitud    DOUBLE PRECISION,
    longitud   DOUBLE PRECISION
);

-- Ubicacion geografica de los medidores. Puede haber varios registros por medidor;
-- se usa el mas reciente (mayor "tiempo").
CREATE TABLE IF NOT EXISTS ubicacion_medidor (
    medidor   VARCHAR(30)      NOT NULL,   -- numero de serie
    latitud   DOUBLE PRECISION NOT NULL,
    longitud  DOUBLE PRECISION NOT NULL,
    tiempo    BIGINT           NOT NULL    -- unix time (segundos)
);
CREATE INDEX IF NOT EXISTS idx_ubicacion_medidor ON ubicacion_medidor(medidor, tiempo DESC);

CREATE TABLE IF NOT EXISTS ordenes_trabajo (
    id               SERIAL PRIMARY KEY,
    -- Reclamo (pagina Reclamos)
    fecha            DATE         NOT NULL DEFAULT CURRENT_DATE,
    cuenta           VARCHAR(30),
    nombre           VARCHAR(160) NOT NULL,
    direccion        VARCHAR(200) NOT NULL,
    telefono         VARCHAR(30),
    trabajo_id       INTEGER      NOT NULL REFERENCES trabajos(id),      -- reclamo / trabajo a realizar
    observacion_id   INTEGER      REFERENCES observaciones(id),
    latitud          DOUBLE PRECISION NOT NULL,
    longitud         DOUBLE PRECISION NOT NULL,
    estado           VARCHAR(12)  NOT NULL DEFAULT 'pendiente'
                     CHECK (estado IN ('pendiente', 'finalizada', 'anulada')),
    creado_por       INTEGER      REFERENCES usuarios(id),
    creado_en        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    -- Registro del trabajo realizado (pagina Registrar ordenes, supervisor)
    fecha_registro   DATE,
    operador1_id     INTEGER      REFERENCES usuarios(id),
    operador2_id     INTEGER      REFERENCES usuarios(id),
    readoquinamiento BOOLEAN      NOT NULL DEFAULT FALSE,
    detalle          TEXT,
    registrado_por   INTEGER      REFERENCES usuarios(id),
    registrado_en    TIMESTAMPTZ,
    -- Datos de campo enviados desde la app movil
    fecha_campo      TIMESTAMP,
    latitud_campo    DOUBLE PRECISION,
    longitud_campo   DOUBLE PRECISION,
    foto             VARCHAR(120),
    detalle_campo    TEXT,
    enviado_por      INTEGER      REFERENCES usuarios(id),
    sincronizado_en  TIMESTAMPTZ,
    CHECK (operador2_id IS NULL OR operador2_id <> operador1_id),
    CHECK (estado <> 'finalizada' OR (fecha_registro IS NOT NULL AND operador1_id IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_ot_estado         ON ordenes_trabajo(estado);
CREATE INDEX IF NOT EXISTS idx_ot_fecha          ON ordenes_trabajo(fecha);
CREATE INDEX IF NOT EXISTS idx_ot_fecha_registro ON ordenes_trabajo(fecha_registro);
CREATE INDEX IF NOT EXISTS idx_ot_cuenta         ON ordenes_trabajo(cuenta);

-- Tokens de sesion de la app movil (se guarda solo el SHA-256 del token)
CREATE TABLE IF NOT EXISTS api_tokens (
    token_hash  CHAR(64) PRIMARY KEY,
    usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    creado_en   TIMESTAMPTZ NOT NULL DEFAULT now(),
    expira_en   TIMESTAMPTZ NOT NULL
);
