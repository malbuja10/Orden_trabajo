<?php
// Consulta de una cuenta para el formulario de Reclamos (JSON).
// Devuelve tambien las ordenes pendientes de esa cuenta, para avisar de reclamos repetidos.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

header('Content-Type: application/json; charset=utf-8');
$cuenta = trim((string) ($_GET['cuenta'] ?? ''));
$c = buscar_cuenta($cuenta);
$pendientes = $cuenta === '' ? [] : q(
    "SELECT id FROM ordenes_trabajo WHERE cuenta = ? AND estado = 'pendiente' AND id <> ? ORDER BY id",
    [$cuenta, (int) ($_GET['excluir'] ?? 0)]
)->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(['encontrada' => $c !== null] + ($c ?? []) + ['pendientes' => $pendientes], JSON_UNESCAPED_UNICODE);
