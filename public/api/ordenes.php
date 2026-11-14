<?php
// API b) Cargar las Ordenes de Trabajo asignadas al operador (pendientes de realizar).
// GET con cabecera "Authorization: Bearer <token>".
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

api_metodo('GET');
$u = api_usuario();

$filas = q(
    SQL_ORDENES . " WHERE o.estado = 'asignada' AND ? IN (o.operador1_id, o.operador2_id)
     ORDER BY o.fecha_asignacion, o.id",
    [$u['id']]
)->fetchAll();

$ordenes = array_map(fn(array $o): array => [
    'id' => $o['id'],
    'fecha_asignacion' => $o['fecha_asignacion'],
    'trabajo_id' => $o['trabajo_id'],
    'trabajo' => $o['trabajo'],
    'grupo' => array_values(array_filter([$o['operador1'], $o['operador2']])),
    'observaciones' => $o['observaciones'],
    'readoquinamiento' => $o['readoquinamiento'],
    'latitud' => num($o['latitud']),
    'longitud' => num($o['longitud']),
    'reclamo' => [
        'id' => $o['reclamo_id'],
        'fecha' => $o['reclamo_fecha'],
        'nombre' => $o['nombre'],
        'direccion' => $o['direccion'],
        'reclamo' => $o['reclamo'],
        'telefono' => $o['telefono'],
        'medidor' => $o['medidor'],
        'observaciones' => $o['reclamo_observaciones'],
    ],
], $filas);

api_json([
    'ok' => true,
    'fecha_servidor' => date('Y-m-d\TH:i:s'),
    'ordenes' => $ordenes,
    // Catalogo para que la app permita indicar el trabajo realmente ejecutado
    'trabajos' => q('SELECT id, nombre, color FROM trabajos WHERE activo ORDER BY nombre')->fetchAll(),
]);
