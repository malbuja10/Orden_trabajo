<?php
// d) Detalle de una Orden de trabajo. Permite ademas registrar el resultado desde la Web
//    (respaldo para cuando no se pudo usar la app movil).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
$u = requerir_login();

$id = (int) ($_GET['id'] ?? 0);

if (es_post()) {
    try {
        $foto = null;
        $archivo = $_FILES['foto'] ?? null;
        if ($archivo && $archivo['error'] === UPLOAD_ERR_OK) {
            $foto = file_get_contents($archivo['tmp_name']);
        } elseif ($archivo && $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No se pudo subir la foto (¿supera el tamaño máximo del servidor?).');
        }
        registrar_resultado($id, [
            'realizada' => ($_POST['realizada'] ?? '') === '1',
            'fecha_realizacion' => $_POST['fecha_realizacion'] ?? '',
            'trabajo_id' => $_POST['trabajo_id'] ?? '',
            'detalle' => $_POST['detalle'] ?? '',
            'readoquinamiento' => !empty($_POST['readoquinamiento']),
            'latitud' => $_POST['latitud'] ?? '',
            'longitud' => $_POST['longitud'] ?? '',
            'motivo_no_realizada' => $_POST['motivo_no_realizada'] ?? '',
        ], $foto, (int) $u['id']);
        aviso('Resultado de la orden registrado.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('orden.php?id=' . $id);
}

$o = q(SQL_ORDENES . ' WHERE o.id = ?', [$id])->fetch();
if (!$o) {
    http_response_code(404);
    cabecera('Orden no encontrada');
    echo '<p>La orden solicitada no existe. <a href="ordenes.php">Volver al reporte</a></p>';
    pie();
    exit;
}
$si_no = fn($v) => $v === null ? '—' : ($v ? 'Sí' : 'No');

cabecera('Orden de Trabajo N° ' . $o['id'], true);
?>
<p class="no-imprimir"><a href="javascript:history.back()">← Volver</a> · <a href="javascript:window.print()">Imprimir</a></p>
<div class="dos-columnas iguales">
  <div>
    <section class="tarjeta">
      <h2>Reclamo N° <?= h($o['reclamo_id']) ?></h2>
      <dl class="ficha">
        <dt>Fecha</dt><dd><?= fecha_es($o['reclamo_fecha']) ?></dd>
        <dt>Ciudadano</dt><dd><?= h($o['nombre']) ?></dd>
        <dt>Dirección</dt><dd><?= h($o['direccion']) ?></dd>
        <dt>Teléfono</dt><dd><?= h($o['telefono'] ?: '—') ?></dd>
        <dt>Medidor</dt><dd><?= h($o['medidor'] ?: '—') ?></dd>
        <dt>Reclamo</dt><dd><?= nl2br(h($o['reclamo'])) ?></dd>
        <dt>Observaciones</dt><dd><?= nl2br(h($o['reclamo_observaciones'] ?: '—')) ?></dd>
      </dl>
    </section>
    <section class="tarjeta">
      <h2>Asignación <span class="estado <?= h($o['estado']) ?>"><?= h(ESTADOS_ORDEN[$o['estado']]) ?></span></h2>
      <dl class="ficha">
        <dt>Fecha de asignación</dt><dd><?= fecha_es($o['fecha_asignacion']) ?></dd>
        <dt>Trabajo a realizar</dt><dd><span class="punto" style="background:<?= h($o['trabajo_color']) ?>"></span><?= h($o['trabajo']) ?></dd>
        <dt>Grupo de trabajo</dt><dd><?= h(grupo_texto($o)) ?></dd>
        <dt>Readoquinamiento</dt><dd><?= $si_no($o['readoquinamiento']) ?></dd>
        <dt>Observaciones</dt><dd><?= nl2br(h($o['observaciones'] ?: '—')) ?></dd>
        <dt>Supervisor</dt><dd><?= h($o['supervisor'] ?: '—') ?></dd>
      </dl>
    </section>
    <?php if ($o['estado'] !== 'asignada'): ?>
    <section class="tarjeta">
      <h2>Trabajo en campo</h2>
      <dl class="ficha">
        <dt>Fecha y hora</dt><dd><?= fecha_es($o['fecha_realizacion']) ?></dd>
        <?php if ($o['estado'] === 'realizada'): ?>
        <dt>Trabajo realizado</dt><dd><span class="punto" style="background:<?= h($o['color_final']) ?>"></span><?= h($o['trabajo_final']) ?></dd>
        <dt>Detalle</dt><dd><?= nl2br(h($o['detalle_realizado'] ?: '—')) ?></dd>
        <dt>Readoquinamiento</dt><dd><?= $si_no($o['readoquinamiento_real']) ?></dd>
        <?php else: ?>
        <dt>Motivo</dt><dd><?= nl2br(h($o['motivo_no_realizada'] ?: '—')) ?></dd>
        <dt>Detalle</dt><dd><?= nl2br(h($o['detalle_realizado'] ?: '—')) ?></dd>
        <?php endif; ?>
        <dt>Coordenadas</dt><dd><?= $o['latitud_real'] !== null ? h(round((float) $o['latitud_real'], 6) . ', ' . round((float) $o['longitud_real'], 6)) : '— (se usa la ubicación del reclamo)' ?></dd>
        <dt>Sincronizado</dt><dd><?= fecha_es($o['sincronizado_en'] ? date('Y-m-d H:i:s', strtotime($o['sincronizado_en'])) : null) ?></dd>
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

    <?php if ($o['estado'] === 'asignada'): ?>
    <section class="tarjeta no-imprimir">
      <h2>Registrar resultado manualmente</h2>
      <p class="suave">Normalmente lo envían los operadores desde la app móvil. Use este formulario solo como respaldo.</p>
      <form method="post" enctype="multipart/form-data" class="rejilla dos">
        <?= csrf_campo() ?>
        <label>Resultado
          <select name="realizada" id="realizada">
            <option value="1">Realizada</option>
            <option value="0">No realizada</option>
          </select>
        </label>
        <label>Fecha y hora
          <input type="datetime-local" name="fecha_realizacion" value="<?= date('Y-m-d\TH:i') ?>" required>
        </label>
        <label class="si-realizada">Trabajo realizado
          <select name="trabajo_id"><?= opciones(lista_trabajos(), $o['trabajo_id']) ?></select>
        </label>
        <label class="casilla si-realizada"><input type="checkbox" name="readoquinamiento" value="1" <?= $o['readoquinamiento'] ? 'checked' : '' ?>> Se hizo readoquinamiento</label>
        <label class="ancho2 no-realizada" hidden>Motivo por el que no se realizó
          <input name="motivo_no_realizada">
        </label>
        <label class="ancho2">Detalle del trabajo
          <textarea name="detalle" rows="2"></textarea>
        </label>
        <label>Latitud real <input name="latitud" id="latitud" inputmode="decimal" value="<?= h($o['latitud']) ?>"></label>
        <label>Longitud real <input name="longitud" id="longitud" inputmode="decimal" value="<?= h($o['longitud']) ?>"></label>
        <label class="ancho2">Foto <input type="file" name="foto" accept="image/jpeg,image/png,image/webp"></label>
        <div class="acciones ancho2"><button type="submit" class="primario">Registrar resultado</button>
          <span class="suave">Puede hacer clic en el mapa para fijar las coordenadas reales.</span></div>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  const o = <?= json_js(['lat' => num($o['latitud']), 'lng' => num($o['longitud']), 'latr' => num($o['latitud_real']), 'lngr' => num($o['longitud_real']), 'color' => $o['color_final'], 'editable' => $o['estado'] === 'asignada']) ?>;
  const mapa = OT.crearMapa('mapa');
  const puntos = [];
  if (o.lat !== null) {
    OT.circulo(o.lat, o.lng, '#6b7280').addTo(mapa).bindTooltip('Ubicación del reclamo');
    puntos.push([o.lat, o.lng]);
  }
  if (o.latr !== null) {
    OT.circulo(o.latr, o.lngr, o.color).addTo(mapa).bindTooltip('Ubicación real del trabajo');
    puntos.push([o.latr, o.lngr]);
  }
  if (puntos.length) mapa.fitBounds(puntos, {padding: [50, 50], maxZoom: 17});

  if (o.editable) {
    let marca = null;
    mapa.on('click', e => {
      marca = marca ? marca.setLatLng(e.latlng) : L.marker(e.latlng).addTo(mapa);
      document.getElementById('latitud').value = e.latlng.lat.toFixed(7);
      document.getElementById('longitud').value = e.latlng.lng.toFixed(7);
    });
    const sel = document.getElementById('realizada');
    sel.addEventListener('change', () => {
      const si = sel.value === '1';
      document.querySelectorAll('.si-realizada').forEach(e => e.hidden = !si);
      document.querySelectorAll('.no-realizada').forEach(e => e.hidden = si);
    });
  }
})();
</script>
<?php pie();
