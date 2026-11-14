<?php
// Logica compartida entre el aplicativo Web y el API movil.
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** id => nombre de los tipos de trabajo. */
function lista_trabajos(bool $soloActivos = true): array
{
    $sql = 'SELECT id, nombre FROM trabajos' . ($soloActivos ? ' WHERE activo' : '') . ' ORDER BY nombre';
    return q($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** id => nombre de los operadores. */
function lista_operadores(bool $soloActivos = true): array
{
    $sql = "SELECT id, nombre FROM usuarios WHERE rol = 'operador'" . ($soloActivos ? ' AND activo' : '') . ' ORDER BY nombre';
    return q($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Consulta base de ordenes con todos los datos del reclamo y nombres relacionados.
 * La ubicacion (lat/lng) es la real registrada en campo o, si no hay, la del reclamo.
 */
const SQL_ORDENES = "
    SELECT o.*, r.fecha AS reclamo_fecha, r.nombre, r.direccion, r.reclamo, r.telefono, r.medidor,
           r.observaciones AS reclamo_observaciones, r.latitud, r.longitud,
           t.nombre AS trabajo, t.color AS trabajo_color,
           tr.nombre AS trabajo_realizado, tr.color AS trabajo_realizado_color,
           COALESCE(tr.nombre, t.nombre) AS trabajo_final, COALESCE(tr.color, t.color) AS color_final,
           u1.nombre AS operador1, u2.nombre AS operador2, s.nombre AS supervisor,
           COALESCE(o.latitud_real, r.latitud) AS lat, COALESCE(o.longitud_real, r.longitud) AS lng
    FROM ordenes_trabajo o
    JOIN reclamos r  ON r.id = o.reclamo_id
    JOIN trabajos t  ON t.id = o.trabajo_id
    JOIN usuarios u1 ON u1.id = o.operador1_id
    LEFT JOIN usuarios u2 ON u2.id = o.operador2_id
    LEFT JOIN usuarios s  ON s.id = o.supervisor_id
    LEFT JOIN trabajos tr ON tr.id = o.trabajo_realizado_id";

function grupo_texto(array $o): string
{
    return $o['operador1'] . ($o['operador2'] ? ' / ' . $o['operador2'] : '');
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
 * Registra el resultado de una orden (realizada o no realizada).
 *
 * $d: realizada (bool), fecha_realizacion, trabajo_id, detalle, readoquinamiento,
 *     latitud, longitud, motivo_no_realizada.  $foto: binario de la imagen o null.
 * Si la orden no se realizo, el reclamo vuelve a "pendiente" para poder reasignarlo.
 */
function registrar_resultado(int $ordenId, array $d, ?string $foto, int $usuarioId): void
{
    $pdo = db();
    $orden = q('SELECT * FROM ordenes_trabajo WHERE id = ?', [$ordenId])->fetch();
    if (!$orden) {
        throw new RuntimeException('La orden no existe.');
    }

    $realizada = !empty($d['realizada']);
    $fecha = null;
    if (!empty($d['fecha_realizacion'])) {
        $t = strtotime((string) $d['fecha_realizacion']);
        if ($t === false) {
            throw new RuntimeException('Fecha de realización no válida.');
        }
        $fecha = date('Y-m-d H:i:s', $t);
    }
    $fecha ??= date('Y-m-d H:i:s');

    $lat = isset($d['latitud']) && is_numeric($d['latitud']) ? (float) $d['latitud'] : null;
    $lng = isset($d['longitud']) && is_numeric($d['longitud']) ? (float) $d['longitud'] : null;
    if (($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) {
        throw new RuntimeException('Coordenadas fuera de rango.');
    }

    $trabajoId = null;
    if ($realizada) {
        $trabajoId = !empty($d['trabajo_id']) ? (int) $d['trabajo_id'] : (int) $orden['trabajo_id'];
        if (!q('SELECT 1 FROM trabajos WHERE id = ?', [$trabajoId])->fetch()) {
            throw new RuntimeException('El tipo de trabajo indicado no existe.');
        }
    }

    $nombreFoto = $foto !== null && $foto !== '' ? guardar_foto($ordenId, $foto) : null;

    $pdo->beginTransaction();
    try {
        q(
            'UPDATE ordenes_trabajo SET estado = ?, fecha_realizacion = ?, trabajo_realizado_id = ?,
                    detalle_realizado = ?, readoquinamiento_real = ?, latitud_real = ?, longitud_real = ?,
                    foto = COALESCE(?, foto), motivo_no_realizada = ?, registrado_por = ?, sincronizado_en = now()
             WHERE id = ?',
            [
                $realizada ? 'realizada' : 'no_realizada',
                $fecha,
                $trabajoId,
                texto((string) ($d['detalle'] ?? '')),
                $realizada ? pgbool(!empty($d['readoquinamiento'])) : null,
                $lat,
                $lng,
                $nombreFoto,
                $realizada ? null : texto((string) ($d['motivo_no_realizada'] ?? '')),
                $usuarioId,
                $ordenId,
            ]
        );
        q('UPDATE reclamos SET estado = ? WHERE id = ?', [$realizada ? 'atendido' : 'pendiente', $orden['reclamo_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($nombreFoto) {
            @unlink(config('dir_fotos') . '/' . $nombreFoto);
        }
        throw $e;
    }
    if ($nombreFoto && $orden['foto'] && $orden['foto'] !== $nombreFoto) {
        @unlink(config('dir_fotos') . '/' . basename($orden['foto']));
    }
}

/** Datos de una orden para dibujarla en un mapa. $color: null = color del tipo de trabajo. */
function punto_mapa(array $o, ?string $color = null): array
{
    return [
        'id' => $o['id'],
        'lat' => num($o['lat']),
        'lng' => num($o['lng']),
        'color' => $color ?? $o['color_final'],
        'estado_txt' => ESTADOS_ORDEN[$o['estado']],
        'trabajo' => $o['trabajo_final'],
        'nombre' => $o['nombre'],
        'direccion' => $o['direccion'],
        'grupo' => grupo_texto($o),
        'fecha' => fecha_es($o['fecha_realizacion'] ?? $o['fecha_asignacion']),
        'detalle' => $o['detalle_realizado'] ?? $o['motivo_no_realizada'],
        'foto' => (bool) $o['foto'],
    ];
}
