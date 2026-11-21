<?php
// API c) Descargar al servidor los datos de campo de los trabajos realizados.
// POST con "Authorization: Bearer <token>", en uno de dos formatos:
//   - application/json: {"ordenes": [{"id": 1, "fecha": "...", "latitud": ..., "longitud": ..., "detalle": "...", "foto": "<base64>"}]}
//   - multipart/form-data: campo "datos" con el mismo JSON (sin foto) y archivos "foto_<id>"
// La orden sigue pendiente hasta que el supervisor registra quien hizo el trabajo.
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
        if (!$id) {
            throw new RuntimeException('Falta el id de la orden.');
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

        $nuevo = registrar_datos_campo($id, $d, $foto, (int) $u['id']);
        // Un reenvio de algo ya recibido se informa como correcto para que la app lo retire.
        $resultados[] = $nuevo ? ['id' => $id, 'ok' => true] : ['id' => $id, 'ok' => true, 'mensaje' => 'La orden ya tenía datos de campo.'];
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
