<?php
// Instalador por linea de comandos: crea las tablas, los tipos de trabajo iniciales
// y el primer usuario supervisor.
//   php database/instalar.php correo@empresa.gob.ec "Nombre Supervisor" [contraseña]
// Se puede ejecutar varias veces sin perder datos.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo por línea de comandos.');
}
require __DIR__ . '/../src/bootstrap.php';

// Base de datos de la version 1 (tabla reclamos separada): hay que migrar primero.
if (q("SELECT to_regclass('reclamos') IS NOT NULL")->fetchColumn()) {
    fwrite(STDERR, "La base de datos tiene la estructura anterior (tabla reclamos).\n"
        . "Ejecute primero: php database/migrar_v2.php --borrar-datos  (borra reclamos y órdenes)\n");
    exit(1);
}
db()->exec(file_get_contents(__DIR__ . '/schema.sql'));
echo "Tablas creadas/verificadas.\n";

$trabajos = [
    'Acometida' => '#1f77b4',
    'Fuga' => '#d62728',
    'Adoquinamiento' => '#8c564b',
    'Cambio de medidor' => '#2ca02c',
    'Reparación de tubería' => '#ff7f0e',
    'Otros' => '#7f7f7f',
];
if (!q('SELECT 1 FROM trabajos LIMIT 1')->fetch()) {
    foreach ($trabajos as $nombre => $color) {
        q('INSERT INTO trabajos (nombre, color) VALUES (?, ?)', [$nombre, $color]);
    }
    echo "Tipos de trabajo iniciales creados.\n";
}

$correo = strtolower($argv[1] ?? '');
if ($correo === '') {
    echo "No se indicó correo: no se creó usuario supervisor.\n";
    exit(0);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Correo no válido.\n");
    exit(1);
}
$nombre = $argv[2] ?? 'Supervisor';
$clave = $argv[3] ?? bin2hex(random_bytes(6));
q(
    "INSERT INTO usuarios (nombre, correo, clave_hash, rol) VALUES (?, ?, ?, 'supervisor')
     ON CONFLICT (correo) DO UPDATE SET clave_hash = EXCLUDED.clave_hash, activo = TRUE",
    [$nombre, $correo, password_hash($clave, PASSWORD_DEFAULT)]
);
echo "Supervisor listo: $correo" . (isset($argv[3]) ? '' : "  contraseña: $clave") . "\n";
