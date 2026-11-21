<?php
// Datos de demostracion (OPCIONAL, solo para pruebas): operadores, observaciones, cuentas
// y ordenes de los ultimos meses alrededor del centro del mapa configurado.
//   php database/demo.php
// Operadores: operador1@demo.local ... operador4@demo.local, contraseña "demo12345".
// Cuentas de prueba: 1001 a 1030.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo por línea de comandos.');
}
require __DIR__ . '/../src/bootstrap.php';
mt_srand(2026);

$hash = password_hash('demo12345', PASSWORD_DEFAULT);
$ops = [];
foreach (['Juan Pérez', 'Luis Andrade', 'Carlos Vera', 'Pedro Mora'] as $i => $n) {
    $ops[] = q(
        "INSERT INTO usuarios (nombre, correo, clave_hash, rol) VALUES (?, ?, ?, 'operador')
         ON CONFLICT (correo) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id",
        [$n, 'operador' . ($i + 1) . '@demo.local', $hash]
    )->fetchColumn();
}
foreach (['Urgente', 'Sin agua en el sector', 'Daño en la calzada', 'Cliente reincidente'] as $ob) {
    q('INSERT INTO observaciones (nombre) VALUES (?) ON CONFLICT (nombre) DO NOTHING', [$ob]);
}
$sup = q("SELECT id FROM usuarios WHERE rol = 'supervisor' ORDER BY id LIMIT 1")->fetchColumn() ?: null;
$trabajos = q('SELECT id FROM trabajos WHERE activo ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$obs = q('SELECT id FROM observaciones WHERE activo ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

$nombres = ['Ana Torres', 'José Cárdenas', 'Rosa Jiménez', 'Miguel Salazar', 'Carmen Ortiz', 'Diego Paredes', 'Lucía Narváez', 'Fernando Ruiz'];
$calles = ['Av. Amazonas', 'Calle Sucre', 'Av. 10 de Agosto', 'Calle Bolívar', 'Av. Colón', 'Calle Rocafuerte', 'Av. América', 'Calle Olmedo'];
$c = config('mapa');
$azar = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$coord = fn(float $base) => $base + (mt_rand(-1500, 1500) / 100000);

// Cuentas 1001-1030; las 1026-1030 sin coordenadas
$cuentas = [];
for ($i = 1001; $i <= 1030; $i++) {
    $cuentas[$i] = [$azar($nombres), $azar($calles) . ' N' . mt_rand(10, 99) . '-' . mt_rand(10, 99), $coord($c['lat']), $coord($c['lng'])];
    q('INSERT INTO cuentas (cuenta, nombre, direccion, medidor, latitud, longitud) VALUES (?,?,?,?,?,?)
       ON CONFLICT (cuenta) DO NOTHING',
        [(string) $i, $cuentas[$i][0], $cuentas[$i][1], 'M-' . $i, $i > 1025 ? null : $cuentas[$i][2], $i > 1025 ? null : $cuentas[$i][3]]);
}

$n = 0;
for ($dias = 120; $dias >= 0; $dias -= mt_rand(0, 2)) {
    $fecha = date('Y-m-d', strtotime("-$dias days"));
    $cta = mt_rand(1001, 1025);
    [$nombre, $dir, $lat, $lng] = $cuentas[$cta];
    $id = q(
        'INSERT INTO ordenes_trabajo (fecha, cuenta, nombre, direccion, telefono, trabajo_id, observacion_id, latitud, longitud, creado_por)
         VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
        [$fecha, (string) $cta, $nombre, $dir, '09' . mt_rand(10000000, 99999999), $azar($trabajos),
            mt_rand(0, 2) ? null : $azar($obs), $lat, $lng, $sup]
    )->fetchColumn();
    $n++;
    if ($dias < 4 && mt_rand(0, 2)) {
        continue; // pendiente
    }
    $reg = date('Y-m-d', min(time(), strtotime($fecha . ' +' . mt_rand(0, 3) . ' days')));
    $op1 = $azar($ops);
    $op2 = mt_rand(0, 2) ? $azar(array_values(array_diff($ops, [$op1]))) : null;
    q(
        "UPDATE ordenes_trabajo SET estado='finalizada', fecha_registro=?, operador1_id=?, operador2_id=?, readoquinamiento=?,
                detalle=?, registrado_por=?, registrado_en=now(),
                fecha_campo=?, latitud_campo=?, longitud_campo=?, enviado_por=?, sincronizado_en=now()
         WHERE id=?",
        [$reg, $op1, $op2, pgbool(mt_rand(0, 3) === 0), mt_rand(0, 2) ? null : 'Trabajo completado sin novedad.', $sup,
            $reg . sprintf(' %02d:%02d:00', mt_rand(8, 16), mt_rand(0, 59)), $lat + mt_rand(-20, 20) / 100000, $lng + mt_rand(-20, 20) / 100000, $op1, $id]
    );
}
echo "Datos de demostración creados: $n órdenes, cuentas 1001-1030, operadores operador1..4@demo.local (contraseña demo12345).\n";
