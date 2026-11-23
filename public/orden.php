<?php
// d) Detalle de una Orden de Trabajo.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$o = consulta_ordenes('id = ?', [(int) ($_GET['id'] ?? 0)])[0] ?? null;
if (!$o) {
    http_response_code(404);
    cabecera('Orden no encontrada');
    echo '<p>La orden solicitada no existe. <a href="ordenes.php">Volver al reporte</a></p>';
    pie();
    exit;
}
$fechaHora = fn($ts) => $ts ? fecha_es(date('Y-m-d H:i:s', strtotime($ts))) : '—';

cabecera('Orden de Trabajo N° ' . $o['id'], true);
?>
<p class="no-imprimir">
  <a href="javascript:history.back()">← Volver</a> · <a href="javascript:window.print()">Imprimir</a>
  <?php if ($o['estado'] === 'pendiente'): ?>
    · <a href="registrar.php?id=<?= h($o['id']) ?>">Registrar trabajo realizado</a> · <a href="reclamos.php?editar=<?= h($o['id']) ?>">Editar reclamo</a>
  <?php elseif ($o['estado'] === 'finalizada'): ?>
    · <a href="registrar.php?id=<?= h($o['id']) ?>">Corregir registro</a>
  <?php endif; ?>
</p>
<div class="dos-columnas iguales">
  <div>
    <section class="tarjeta">
      <h2>Reclamo <span class="estado <?= h($o['estado']) ?>"><?= h(ESTADOS_ORDEN[$o['estado']]) ?></span></h2>
      <dl class="ficha">
        <dt>Fecha</dt><dd><?= fecha_es($o['fecha']) ?></dd>
        <dt>Cuenta</dt><dd><?= h($o['cuenta'] ?: '—') ?></dd>
        <dt>Ciudadano</dt><dd><?= h($o['nombre']) ?></dd>
        <dt>Dirección</dt><dd><?= h($o['direccion']) ?></dd>
        <dt>Teléfono</dt><dd><?= h($o['telefono'] ?: '—') ?></dd>
        <dt>Reclamo</dt><dd><span class="punto" style="background:<?= h($o['trabajo_color']) ?>"></span><?= h($o['trabajo']) ?></dd>
        <dt>Observación</dt><dd><?= h($o['observacion'] ?: '—') ?></dd>
        <dt>Registrado por</dt><dd><?= h($o['creador'] ?: '—') ?></dd>
      </dl>
    </section>
    <?php if ($o['estado'] === 'finalizada'): ?>
    <section class="tarjeta">
      <h2>Trabajo realizado</h2>
      <dl class="ficha">
        <dt>Fecha de registro</dt><dd><?= fecha_es($o['fecha_registro']) ?></dd>
        <dt>Realizado por</dt><dd><?= h(grupo_texto($o)) ?></dd>
        <dt>Readoquinamiento</dt><dd><?= $o['readoquinamiento'] ? 'Sí' : 'No' ?></dd>
        <dt>Detalle adicional</dt><dd><?= nl2br(h($o['detalle'] ?: '—')) ?></dd>
        <dt>Registrado por</dt><dd><?= h($o['registrador'] ?: '—') ?> · <?= $fechaHora($o['registrado_en']) ?></dd>
      </dl>
    </section>
    <?php endif; ?>
    <?php if ($o['sincronizado_en']): ?>
    <section class="tarjeta">
      <h2>Datos de campo (app móvil)</h2>
      <dl class="ficha">
        <dt>Fecha y hora</dt><dd><?= fecha_es($o['fecha_campo']) ?></dd>
        <dt>Coordenadas</dt><dd><?= $o['latitud_campo'] !== null ? h(round((float) $o['latitud_campo'], 6) . ', ' . round((float) $o['longitud_campo'], 6)) : '—' ?></dd>
        <dt>Detalle</dt><dd><?= nl2br(h($o['detalle_campo'] ?: '—')) ?></dd>
        <dt>Enviado por</dt><dd><?= h($o['enviado_por_nombre'] ?: '—') ?> · <?= $fechaHora($o['sincronizado_en']) ?></dd>
      </dl>
    </section>
    <?php endif; ?>
  </div>
  <div>
    <div id="mapa" class="mapa"></div>
    <?php if ($o['foto']): ?>
    <section class="tarjeta">
      <h2>Foto del trabajo</h2>
      <a href="foto.php?id=<?= h($o['id']) ?>" target="_blank"><img class="foto" src="foto.php?id=<?= h($o['id']) ?>" alt="Foto del trabajo"></a>
    </section>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  const o = <?= json_js(['lat' => num($o['latitud']), 'lng' => num($o['longitud']), 'latc' => num($o['latitud_campo']), 'lngc' => num($o['longitud_campo']), 'color' => $o['trabajo_color'], 'hueco' => $o['estado'] !== 'finalizada']) ?>;
  const mapa = OT.crearMapa('mapa');
  const puntos = [[o.lat, o.lng]];
  OT.circulo(o.lat, o.lng, o.color, o.hueco).addTo(mapa).bindTooltip('Ubicación del reclamo');
  if (o.latc !== null) {
    L.marker([o.latc, o.lngc]).addTo(mapa).bindTooltip('Ubicación tomada en campo (app móvil)');
    puntos.push([o.latc, o.lngc]);
  }
  mapa.fitBounds(puntos, {padding: [50, 50], maxZoom: 17});
})();
</script>
<?php pie();
