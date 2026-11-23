<?php
// API c) Descargar al servidor las Ordenes de Trabajo finalizadas en campo por el supervisor.
// POST con "Authorization: Bearer <token>" (solo supervisores), en uno de dos formatos:
//   - application/json: {"ordenes": [{...}]} (con "foto" en base64)
//   - multipart/form-data: campo "datos" con el mismo JSON (sin foto) y archivos "foto_<id>"
// Cada orden: id, fecha_registro (AAAA-MM-DD), operador1_id, operador2_id, trabajo_id,
//   observacion_id, readoquinamiento, detalle y, opcionales, fecha (fecha y hora de la captura),
//   latitud, longitud y foto.
// La orden queda finalizada, igual que al registrarla en la pagina Registrar ordenes.
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

api_metodo('POST');
$u = api_usuario();

$in = isset($_POST['datos']) ? json_decode((string) $_POST['datos'], true) : api_cuerpo();
if (!is_array($in) || !isset($in['ordenes']) || !is_array($in['ordenes'])) {
    api_error(400, 'Formato no válido: se espera {"ordenes": [...]}.');
}

$pdo = db();
$resultados = [];
foreach ($in['ordenes'] as $d) {
    $id = is_array($d) ? (int) ($d['id'] ?? 0) : 0;
    try {
        if (!$id) {
            throw new RuntimeException('Falta el id de la orden.');
        }
        $o = q('SELECT estado, sincronizado_en FROM ordenes_trabajo WHERE id = ?', [$id])->fetch();
        if (!$o) {
            throw new RuntimeException('La orden no existe.');
        }
        if ($o['estado'] === 'finalizada') {
            // Reenvio, o la orden ya se registro en la Web: no se modifica y la app la retira.
            $resultados[] = ['id' => $id, 'ok' => true, 'ya_registrada' => true, 'mensaje' => $o['sincronizado_en'] !== null
                ? 'La orden ya había sido recibida.'
                : 'La orden ya estaba registrada en la Web; no se modificó.'];
            continue;
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

        $pdo->beginTransaction();
        try {
            registrar_orden($id, $d, (int) $u['id']);
            // Fecha y hora de la captura, coordenadas exactas y foto (el detalle ya va en la orden)
            registrar_datos_campo($id, [
                'fecha' => $d['fecha'] ?? null,
                'latitud' => $d['latitud'] ?? null,
                'longitud' => $d['longitud'] ?? null,
            ], $foto, (int) $u['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
