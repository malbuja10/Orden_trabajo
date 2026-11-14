<?php
// Entrega la foto de una orden (solo con sesion iniciada; las fotos estan fuera de public/).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$foto = q('SELECT foto FROM ordenes_trabajo WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetchColumn();
$ruta = $foto ? config('dir_fotos') . '/' . basename($foto) : null;
if (!$ruta || !is_file($ruta)) {
    http_response_code(404);
    exit('Foto no encontrada.');
}
$tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($tipos[pathinfo($ruta, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, max-age=86400');
readfile($ruta);
