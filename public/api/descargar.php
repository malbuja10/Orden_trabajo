<?php
// API c) Descargar al servidor la informacion de los trabajos realizados.
// POST con "Authorization: Bearer <token>", en uno de dos formatos:
//   - application/json: {"ordenes": [{..., "foto": "<base64>"}]}
//   - multipart/form-data: campo "datos" con el mismo JSON y archivos "foto_<id>"
// Cada orden: id, realizada, fecha_realizacion, trabajo_id, detalle, readoquinamiento,
//             latitud, longitud, motivo_no_realizada.
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

api_metodo('POST');
$u = api_usuario();

$in = isset($_POST['datos']) ? json_decode((string) $_POST['datos'], true) : api_cuerpo();
if (!is_array($in) || !isset($in['ordenes']) || !is_array($in['ordenes'])) {
    api_error(400, 'Formato no válido: se espera {"ordenes": [...]}.');
}

$resultados = [];
foreach ($in['ordenes'] as $d) {
    $id = is_array($d) ? (int) ($d['id'] ?? 0) : 0;
    try {
        $orden = q('SELECT estado, operador1_id, operador2_id FROM ordenes_trabajo WHERE id = ?', [$id])->fetch();
        if (!$orden) {
            throw new RuntimeException('La orden no existe.');
        }
        if (!in_array($u['id'], [$orden['operador1_id'], $orden['operador2_id']], true)) {
            throw new RuntimeException('La orden no está asignada a este operador.');
        }
        if ($orden['estado'] !== 'asignada') {
            // Reenvio de algo ya sincronizado: se informa como correcto para que la app lo retire.
            $resultados[] = ['id' => $id, 'ok' => true, 'mensaje' => 'La orden ya estaba registrada.'];
            continue;
        }
        if (!array_key_exists('realizada', $d)) {
            throw new RuntimeException('Falta el campo "realizada".');
        }

        $foto = null;
        $archivo = $_FILES['foto_' . $id] ?? null;
        if ($archivo && $archivo['error'] === UPLOAD_ERR_OK) {
            $foto = file_get_contents($archivo['tmp_name']);
        } elseif ($archivo && $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No se pudo recibir la foto (código ' . $archivo['error'] . ').');
        } elseif (!empty($d['foto'])) {
            $b64 = preg_replace('#^data:image/\w+;base64,#', '', (string) $d['foto']);
            $foto = base64_decode($b64, true);
            if ($foto === false) {
                throw new RuntimeException('La foto no está en base64 válido.');
            }
        }

        registrar_resultado($id, $d, $foto, (int) $u['id']);
        $resultados[] = ['id' => $id, 'ok' => true];
    } catch (RuntimeException $e) {
        $resultados[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
    }
}

api_json([
    'ok' => true,
    'recibidas' => count($resultados),
    'registradas' => count(array_filter($resultados, fn($r) => $r['ok'])),
    'resultados' => $resultados,
]);
