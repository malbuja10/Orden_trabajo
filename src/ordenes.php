<?php
// Logica compartida entre el aplicativo Web y el API movil.
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** id => nombre de los tipos de trabajo (reclamos). */
function lista_trabajos(bool $soloActivos = true): array
{
    $sql = 'SELECT id, nombre FROM trabajos' . ($soloActivos ? ' WHERE activo' : '') . ' ORDER BY nombre';
    return q($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** id => nombre de las observaciones. */
function lista_observaciones(bool $soloActivos = true): array
{
    $sql = 'SELECT id, nombre FROM observaciones' . ($soloActivos ? ' WHERE activo' : '') . ' ORDER BY nombre';
    return q($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** id => nombre de los operadores. */
function lista_operadores(bool $soloActivos = true): array
{
    $sql = "SELECT id, nombre FROM usuarios WHERE rol = 'operador'" . ($soloActivos ? ' AND activo' : '') . ' ORDER BY nombre';
    return q($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Consulta base de ordenes con nombres relacionados.
 *  - lat/lng: ubicacion tomada en campo (app movil) o, si no hay, la del reclamo.
 *  - fecha_orden: fecha de registro si esta finalizada; si no, fecha del reclamo.
 */
const SQL_ORDENES = "
    SELECT o.*, t.nombre AS trabajo, t.color AS trabajo_color, ob.nombre AS observacion,
           u1.nombre AS operador1, u2.nombre AS operador2, r.nombre AS registrador,
           e.nombre AS enviado_por_nombre, c.nombre AS creador,
           COALESCE(o.latitud_campo, o.latitud) AS lat, COALESCE(o.longitud_campo, o.longitud) AS lng,
           CASE WHEN o.estado = 'finalizada' THEN o.fecha_registro ELSE o.fecha END AS fecha_orden
    FROM ordenes_trabajo o
    JOIN trabajos t            ON t.id = o.trabajo_id
    LEFT JOIN observaciones ob ON ob.id = o.observacion_id
    LEFT JOIN usuarios u1      ON u1.id = o.operador1_id
    LEFT JOIN usuarios u2      ON u2.id = o.operador2_id
    LEFT JOIN usuarios r       ON r.id = o.registrado_por
    LEFT JOIN usuarios e       ON e.id = o.enviado_por
    LEFT JOIN usuarios c       ON c.id = o.creado_por";

/** Ordenes que cumplen $where (puede usar las columnas calculadas de SQL_ORDENES). */
function consulta_ordenes(string $where = 'TRUE', array $params = [], string $orden = 'id'): array
{
    return q('SELECT * FROM (' . SQL_ORDENES . ") x WHERE $where ORDER BY $orden", $params)->fetchAll();
}

function grupo_texto(array $o): string
{
    return implode(' / ', array_filter([$o['operador1'] ?? null, $o['operador2'] ?? null]));
}

/** Datos de una cuenta para llenar el reclamo, o null si no existe. */
function buscar_cuenta(string $cuenta): ?array
{
    $cuenta = trim($cuenta);
    if ($cuenta === '') {
        return null;
    }
    $c = q(
        'SELECT c.cuenta, c.nombre, c.direccion, c.medidor,
                COALESCE(c.latitud, m.latitud) AS latitud, COALESCE(c.longitud, m.longitud) AS longitud
         FROM cuentas c
         LEFT JOIN LATERAL (SELECT latitud, longitud FROM ubicacion_medidor u
                            WHERE u.medidor = btrim(c.medidor) ORDER BY u.tiempo DESC LIMIT 1) m ON TRUE
         WHERE c.cuenta = ?',
        [$cuenta]
    )->fetch();
    if (!$c) {
        return null;
    }
    $c['latitud'] = num($c['latitud']);
    $c['longitud'] = num($c['longitud']);
    return $c;
}

/**
 * El supervisor registra la orden como realizada: fecha de registro, operadores,
 * readoquinamiento y detalle. Trabajo y observacion vienen del reclamo y se pueden corregir.
 * Sirve tambien para corregir una orden ya finalizada.
 */
function registrar_orden(int $id, array $p, int $usuarioId): void
{
    $o = q('SELECT estado, fecha FROM ordenes_trabajo WHERE id = ?', [$id])->fetch();
    if (!$o) {
        throw new RuntimeException('La orden no existe.');
    }
    if ($o['estado'] === 'anulada') {
        throw new RuntimeException('La orden está anulada.');
    }
    $fecha = fecha_valida($p['fecha_registro'] ?? '');
    if (!$fecha) {
        throw new RuntimeException('Indique la fecha de registro.');
    }
    if ($fecha > date('Y-m-d') || $fecha < $o['fecha']) {
        throw new RuntimeException('La fecha de registro debe estar entre la fecha del reclamo (' . fecha_es($o['fecha']) . ') y hoy.');
    }
    $ops = lista_operadores(false);
    $op1 = (int) ($p['operador1_id'] ?? 0);
    $op2 = (int) ($p['operador2_id'] ?? 0) ?: null;
    if (!isset($ops[$op1])) {
        throw new RuntimeException('Seleccione el operador 1.');
    }
    if ($op2 !== null && ($op2 === $op1 || !isset($ops[$op2]))) {
        throw new RuntimeException('El operador 2 debe ser distinto del operador 1.');
    }
    $trabajo = (int) ($p['trabajo_id'] ?? 0);
    if (!isset(lista_trabajos(false)[$trabajo])) {
        throw new RuntimeException('Seleccione el trabajo.');
    }
    $obs = (int) ($p['observacion_id'] ?? 0) ?: null;
    if ($obs !== null && !isset(lista_observaciones(false)[$obs])) {
        throw new RuntimeException('La observación no existe.');
    }
    q(
        "UPDATE ordenes_trabajo SET estado = 'finalizada', fecha_registro = ?, operador1_id = ?, operador2_id = ?,
                readoquinamiento = ?, trabajo_id = ?, observacion_id = ?, detalle = ?, registrado_por = ?, registrado_en = now()
         WHERE id = ? AND estado IN ('pendiente', 'finalizada')",
        [$fecha, $op1, $op2, pgbool(!empty($p['readoquinamiento'])), $trabajo, $obs, texto((string) ($p['detalle'] ?? '')), $usuarioId, $id]
    );
}

/**
 * Guarda una foto (binario JPEG/PNG/WebP) y devuelve el nombre del archivo.
 * Lanza RuntimeException si no es una imagen valida.
 */
function guardar_foto(int $ordenId, string $binario): string
{
    if (strlen($binario) > config('foto_max_bytes')) {
        throw new RuntimeException('La foto supera el tamaño máximo permitido.');
    }
    $info = @getimagesizefromstring($binario);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if ($ext === null) {
        throw new RuntimeException('La foto no es una imagen JPEG, PNG o WebP válida.');
    }
    $dir = config('dir_fotos');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('No se puede crear la carpeta de fotos.');
    }
    $nombre = sprintf('orden_%d_%s.%s', $ordenId, bin2hex(random_bytes(6)), $ext);
    if (file_put_contents($dir . '/' . $nombre, $binario) === false) {
        throw new RuntimeException('No se pudo guardar la foto.');
    }
    return $nombre;
}

/**
 * Datos de campo enviados desde la app movil: fecha y hora, coordenadas exactas, detalle y foto.
 * $d: fecha, latitud, longitud, detalle.  $foto: binario de la imagen o null.
 * No cambia el estado: la orden se finaliza cuando el supervisor registra quien hizo el trabajo.
 * Devuelve false si la orden ya tenia datos de campo (reenvio).
 */
function registrar_datos_campo(int $id, array $d, ?string $foto, int $usuarioId): bool
{
    $o = q('SELECT estado, sincronizado_en FROM ordenes_trabajo WHERE id = ?', [$id])->fetch();
    if (!$o) {
        throw new RuntimeException('La orden no existe.');
    }
    if ($o['estado'] === 'anulada') {
        throw new RuntimeException('La orden fue anulada.');
    }
    if ($o['sincronizado_en'] !== null) {
        return false;
    }

    $fechaTxt = (string) ($d['fecha'] ?? $d['fecha_realizacion'] ?? '');
    $fecha = date('Y-m-d H:i:s');
    if ($fechaTxt !== '') {
        $t = strtotime($fechaTxt);
        if ($t === false) {
            throw new RuntimeException('Fecha no válida.');
        }
        $fecha = date('Y-m-d H:i:s', $t);
    }
    $lat = isset($d['latitud']) && is_numeric($d['latitud']) ? (float) $d['latitud'] : null;
    $lng = isset($d['longitud']) && is_numeric($d['longitud']) ? (float) $d['longitud'] : null;
    if (($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) {
        throw new RuntimeException('Coordenadas fuera de rango.');
    }

    $nombreFoto = $foto !== null && $foto !== '' ? guardar_foto($id, $foto) : null;
    $n = q(
        'UPDATE ordenes_trabajo SET fecha_campo = ?, latitud_campo = ?, longitud_campo = ?, detalle_campo = ?,
                foto = COALESCE(?, foto), enviado_por = ?, sincronizado_en = now()
         WHERE id = ? AND sincronizado_en IS NULL',
        [$fecha, $lat, $lng, texto((string) ($d['detalle'] ?? '')), $nombreFoto, $usuarioId, $id]
    )->rowCount();
    if (!$n && $nombreFoto) {
        @unlink(config('dir_fotos') . '/' . $nombreFoto);
    }
    return $n > 0;
}

/** Datos de una orden para dibujarla en un mapa. */
function punto_mapa(array $o, ?string $color = null, bool $hueco = false): array
{
    return [
        'id' => $o['id'],
        'lat' => num($o['lat']),
        'lng' => num($o['lng']),
        'color' => $color ?? $o['trabajo_color'],
        'hueco' => $hueco,
        'estado_txt' => ESTADOS_ORDEN[$o['estado']],
        'trabajo' => $o['trabajo'],
        'observacion' => $o['observacion'],
        'cuenta' => $o['cuenta'],
        'nombre' => $o['nombre'],
        'direccion' => $o['direccion'],
        'grupo' => grupo_texto($o),
        'fecha' => fecha_es($o['fecha_orden']),
        'detalle' => $o['detalle'] ?? $o['detalle_campo'],
        'foto' => (bool) $o['foto'],
    ];
}
