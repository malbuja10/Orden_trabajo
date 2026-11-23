<?php
// API b) Cargar las Ordenes de Trabajo pendientes (cualquier operador puede realizarlas).
// Se excluyen las que ya tienen datos de campo enviados.
// GET con cabecera "Authorization: Bearer <token>".
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

api_metodo('GET');
api_usuario();

$filas = consulta_ordenes("estado = 'pendiente' AND sincronizado_en IS NULL", [], 'fecha, id');

$ordenes = array_map(fn(array $o): array => [
    'id' => $o['id'],
    'fecha' => $o['fecha'],
    'cuenta' => $o['cuenta'],
    'nombre' => $o['nombre'],
    'direccion' => $o['direccion'],
    'telefono' => $o['telefono'],
    'trabajo_id' => $o['trabajo_id'],
    'trabajo' => $o['trabajo'],
    'observacion' => $o['observacion'],
    'latitud' => num($o['latitud']),
    'longitud' => num($o['longitud']),
], $filas);

api_json([
    'ok' => true,
    'fecha_servidor' => date('Y-m-d\TH:i:s'),
    'ordenes' => $ordenes,
]);
