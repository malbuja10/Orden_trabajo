<?php
// Migracion a la version 2 (reclamos = ordenes de trabajo, sin asignacion).
// BORRA todos los reclamos, ordenes de trabajo y fotos, y crea las tablas nuevas.
// Conserva usuarios, trabajos y ubicacion_medidor.
//   php database/migrar_v2.php --borrar-datos
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo por línea de comandos.');
}
if (($argv[1] ?? '') !== '--borrar-datos') {
    fwrite(STDERR, "Este script BORRA todos los reclamos, órdenes de trabajo y fotos.\n"
        . "Para confirmar, ejecute: php database/migrar_v2.php --borrar-datos\n");
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$pdo->beginTransaction();
$pdo->exec('DROP TABLE IF EXISTS ordenes_trabajo');
$pdo->exec('DROP TABLE IF EXISTS reclamos');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
$pdo->commit();
echo "Reclamos y órdenes borrados; tablas de la versión 2 creadas.\n";

$borradas = 0;
foreach (glob(config('dir_fotos') . '/orden_*') ?: [] as $foto) {
    $borradas += (int) @unlink($foto);
}
echo "Fotos borradas: $borradas\n";
