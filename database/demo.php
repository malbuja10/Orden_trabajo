<?php
// Datos de demostracion (OPCIONAL, solo para pruebas): operadores, reclamos y ordenes
// de los ultimos meses alrededor del centro del mapa configurado.
//   php database/demo.php
// Operadores creados: operador1@demo.local ... operador4@demo.local, contraseña "demo12345".
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
$sup = q("SELECT id FROM usuarios WHERE rol = 'supervisor' ORDER BY id LIMIT 1")->fetchColumn() ?: null;
$trabajos = q('SELECT id FROM trabajos WHERE activo ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

$nombres = ['Ana Torres', 'José Cárdenas', 'Rosa Jiménez', 'Miguel Salazar', 'Carmen Ortiz', 'Diego Paredes', 'Lucía Narváez', 'Fernando Ruiz'];
$calles = ['Av. Amazonas', 'Calle Sucre', 'Av. 10 de Agosto', 'Calle Bolívar', 'Av. Colón', 'Calle Rocafuerte', 'Av. América', 'Calle Olmedo'];
$quejas = ['Fuga de agua en la vereda', 'Solicita nueva acometida', 'Baja presión de agua', 'Medidor dañado', 'Adoquín levantado tras reparación', 'Fuga en la calzada', 'Tubería rota'];
$c = config('mapa');
$azar = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$coord = fn(float $base) => $base + (mt_rand(-1500, 1500) / 100000);

$n = 0;
for ($dias = 150; $dias >= 0; $dias -= mt_rand(1, 3)) {
    $fecha = date('Y-m-d', strtotime("-$dias days"));
    $lat = $coord($c['lat']);
    $lng = $coord($c['lng']);
    $pendiente = $dias < 3 && mt_rand(0, 1);
    $rid = q(
        'INSERT INTO reclamos (fecha, nombre, direccion, reclamo, telefono, medidor, latitud, longitud, estado, creado_por)
         VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
        [$fecha, $azar($nombres), $azar($calles) . ' N' . mt_rand(10, 99) . '-' . mt_rand(10, 99), $azar($quejas),
            '09' . mt_rand(10000000, 99999999), mt_rand(0, 2) ? 'M-' . mt_rand(10000, 99999) : null,
            $pendiente ? null : $lat, $pendiente ? null : $lng, $pendiente ? 'pendiente' : 'asignado', $sup]
    )->fetchColumn();
    $n++;
    if ($pendiente) {
        continue;
    }
    $op1 = $azar($ops);
    $op2 = mt_rand(0, 2) ? $azar(array_values(array_diff($ops, [$op1]))) : null;
    $trabajo = $azar($trabajos);
    $oid = q(
        'INSERT INTO ordenes_trabajo (reclamo_id, fecha_asignacion, trabajo_id, operador1_id, operador2_id, readoquinamiento, supervisor_id)
         VALUES (?,?,?,?,?,?,?) RETURNING id',
        [$rid, $fecha, $trabajo, $op1, $op2, pgbool(mt_rand(0, 3) === 0), $sup]
    )->fetchColumn();
    if ($dias === 0) {
        continue; // las de hoy quedan asignadas, por realizar
    }
    $hecha = mt_rand(0, 9) > 1;
    q(
        'UPDATE ordenes_trabajo SET estado=?, fecha_realizacion=?, trabajo_realizado_id=?, detalle_realizado=?,
                readoquinamiento_real=?, latitud_real=?, longitud_real=?, motivo_no_realizada=?, registrado_por=?, sincronizado_en=now()
         WHERE id=?',
        [$hecha ? 'realizada' : 'no_realizada', $fecha . sprintf(' %02d:%02d:00', mt_rand(8, 16), mt_rand(0, 59)),
            $hecha ? (mt_rand(0, 5) ? $trabajo : $azar($trabajos)) : null, $hecha ? 'Trabajo completado sin novedad.' : null,
            $hecha ? pgbool(mt_rand(0, 3) === 0) : null, $lat + mt_rand(-20, 20) / 100000, $lng + mt_rand(-20, 20) / 100000,
            $hecha ? null : 'No se encontró al propietario.', $op1, $oid]
    );
    q('UPDATE reclamos SET estado=? WHERE id=?', [$hecha ? 'atendido' : 'pendiente', $rid]);
}
echo "Datos de demostración creados: $n reclamos, operadores operador1..4@demo.local (contraseña demo12345).\n";
