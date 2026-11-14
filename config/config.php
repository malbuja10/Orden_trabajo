<?php
// Configuracion del sistema. Los valores se pueden sobreescribir con variables de
// entorno (OT_DB_HOST, ...) o creando config/config.local.php que devuelva un array.

$config = [
    'db' => [
        'host'     => getenv('OT_DB_HOST') ?: 'localhost',
        'port'     => getenv('OT_DB_PORT') ?: '5432',
        'name'     => getenv('OT_DB_NAME') ?: 'ordenes_trabajo',
        'user'     => getenv('OT_DB_USER') ?: 'ordenes',
        'password' => getenv('OT_DB_PASSWORD') ?: 'ordenes',
    ],
    'empresa'       => 'Empresa Pública de Agua Potable',
    'zona_horaria'  => 'America/Guayaquil',
    // Centro y zoom inicial de los mapas (ajustar a la ciudad de la empresa)
    'mapa' => ['lat' => -0.2202, 'lng' => -78.5123, 'zoom' => 14],
    // Carpeta donde se guardan las fotos de los trabajos (fuera de public/)
    'dir_fotos'     => __DIR__ . '/../storage/fotos',
    'foto_max_bytes' => 8 * 1024 * 1024,
    // Duracion del token de la app movil, en dias
    'api_token_dias' => 30,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
return $config;
