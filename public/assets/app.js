// Utilidades comunes de los mapas (Leaflet).
window.OT = {
  crearMapa(id) {
    const c = window.OT_MAPA;
    const mapa = L.map(id).setView([c.lat, c.lng], c.zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(mapa);
    return mapa;
  },

  // Marcador circular; hueco = anillo del color (se usa para las ordenes pendientes).
  circulo(lat, lng, color, hueco) {
    return L.circleMarker([lat, lng], hueco
      ? {radius: 8, color: color, weight: 4, fillColor: '#ffffff', fillOpacity: 1}
      : {radius: 9, color: '#ffffff', weight: 2, fillColor: color, fillOpacity: 0.95});
  },

  // Agrega al mapa un buscador de calles y referencias (Nominatim de OpenStreetMap).
  // alElegir(lat, lng, nombre) se llama cuando el usuario elige un resultado.
  // Solo consulta al presionar Buscar (la politica de Nominatim no permite autocompletar).
  buscador(mapa, alElegir) {
    const cfg = window.OT_BUSQUEDA;
    const Control = L.Control.extend({
      onAdd() {
        const div = L.DomUtil.create('div', 'ot-buscador');
        div.innerHTML =
          '<form><input type="search" placeholder="Buscar calle o referencia…" aria-label="Buscar calle o referencia">' +
          '<button type="submit">Buscar</button></form><div class="ot-resultados" hidden></div>';
        L.DomEvent.disableClickPropagation(div);
        L.DomEvent.disableScrollPropagation(div);
        return div;
      }
    });
    const control = new Control({position: 'topright'}).addTo(mapa);
    const div = control.getContainer();
    const input = div.querySelector('input');
    const lista = div.querySelector('.ot-resultados');
    let temporal = null;

    function mostrar(html) {
      lista.innerHTML = html;
      lista.hidden = false;
    }

    div.querySelector('form').addEventListener('submit', async e => {
      e.preventDefault();
      const texto = input.value.trim();
      if (texto.length < 3) return;
      mostrar('<div class="suave">Buscando…</div>');
      const params = new URLSearchParams({
        q: texto, format: 'jsonv2', limit: '8', 'accept-language': 'es',
        viewbox: cfg.viewbox, bounded: '1'
      });
      if (cfg.pais) params.set('countrycodes', cfg.pais);
      try {
        const r = await fetch(cfg.url + '?' + params);
        if (!r.ok) throw new Error(r.status);
        const datos = await r.json();
        if (!datos.length) {
          mostrar('<div class="suave">Sin resultados. Pruebe solo el nombre de la calle o una referencia cercana (parque, escuela…).</div>');
          return;
        }
        mostrar(datos.map((d, i) => '<button type="button" data-i="' + i + '">' + OT.esc(d.display_name) + '</button>').join(''));
        lista.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
          const d = datos[b.dataset.i];
          const lat = parseFloat(d.lat), lng = parseFloat(d.lon);
          lista.hidden = true;
          mapa.setView([lat, lng], 18);
          if (temporal) mapa.removeLayer(temporal);
          temporal = L.circleMarker([lat, lng], {radius: 14, color: '#0b5fa5', weight: 2, dashArray: '4 3', fill: false}).addTo(mapa);
          if (alElegir) alElegir(lat, lng, d.display_name);
        }));
      } catch (err) {
        mostrar('<div class="suave">No se pudo consultar el servicio de búsqueda. Verifique la conexión a Internet.</div>');
      }
    });
    input.addEventListener('input', () => { if (!input.value) lista.hidden = true; });

    return {
      // Pone un texto en el buscador sin ejecutar la busqueda
      ponerTexto(t) { input.value = t || ''; lista.hidden = true; }
    };
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  },

  // Dibuja ordenes [{id, lat, lng, color, hueco, ...}] con su popup y ajusta la vista.
  dibujarOrdenes(mapa, ordenes) {
    const puntos = [];
    ordenes.forEach(o => {
      if (o.lat === null || o.lng === null) return;
      const html =
        '<b>Orden N° ' + o.id + '</b> · ' + OT.esc(o.estado_txt) + ' · ' + OT.esc(o.fecha) + '<br>' +
        '<b>' + OT.esc(o.trabajo) + '</b>' + (o.observacion ? ' · ' + OT.esc(o.observacion) : '') + '<br>' +
        (o.cuenta ? 'Cuenta ' + OT.esc(o.cuenta) + ' · ' : '') + OT.esc(o.nombre) + '<br>' + OT.esc(o.direccion) + '<br>' +
        (o.grupo ? 'Realizado por: ' + OT.esc(o.grupo) + '<br>' : '') +
        (o.detalle ? '<i>' + OT.esc(o.detalle) + '</i><br>' : '') +
        (o.foto ? '<a href="orden.php?id=' + o.id + '"><img class="foto-popup" src="foto.php?id=' + o.id + '" alt="Foto"></a><br>' : '') +
        '<a href="orden.php?id=' + o.id + '">Ver detalle</a>';
      OT.circulo(o.lat, o.lng, o.color, o.hueco).addTo(mapa).bindPopup(html, {minWidth: 200});
      puntos.push([o.lat, o.lng]);
    });
    if (puntos.length) mapa.fitBounds(puntos, {padding: [40, 40], maxZoom: 16});
    return puntos.length;
  }
};
