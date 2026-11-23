<?php
// API b) Cargar las Ordenes de Trabajo pendientes y los catalogos para registrarlas en campo
// (trabajos, observaciones y operadores). Cualquier operador puede realizar cualquier orden.
// GET con cabecera "Authorization: Bearer <token>" (solo supervisores).
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

api_metodo('GET');
api_usuario();

$filas = consulta_ordenes("estado = 'pendiente'", [], 'fecha, id');

$ordenes = array_map(fn(array $o): array => [
    'id' => $o['id'],
    'fecha' => $o['fecha'],
    'cuenta' => $o['cuenta'],
    'nombre' => $o['nombre'],
    'direccion' => $o['direccion'],
    'telefono' => $o['telefono'],
    'trabajo_id' => $o['trabajo_id'],
    'trabajo' => $o['trabajo'],
    'observacion_id' => $o['observacion_id'],
    'observacion' => $o['observacion'],
    'latitud' => num($o['latitud']),
    'longitud' => num($o['longitud']),
], $filas);

$pares = fn(array $lista): array => array_map(
    fn($id, $nombre) => ['id' => $id, 'nombre' => $nombre],
    array_keys($lista),
    array_values($lista)
);

api_json([
    'ok' => true,
    'fecha_servidor' => date('Y-m-d\TH:i:s'),
    'ordenes' => $ordenes,
    'trabajos' => q('SELECT id, nombre, color FROM trabajos WHERE activo ORDER BY nombre')->fetchAll(),
    'observaciones' => $pares(lista_observaciones()),
    'operadores' => $pares(lista_operadores()),
]);
