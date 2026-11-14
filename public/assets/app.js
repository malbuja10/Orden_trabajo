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

  circulo(lat, lng, color) {
    return L.circleMarker([lat, lng], {
      radius: 9, color: '#ffffff', weight: 2, fillColor: color, fillOpacity: 0.95
    });
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  },

  // Dibuja ordenes [{id, lat, lng, color, ...}] con su popup y ajusta la vista.
  dibujarOrdenes(mapa, ordenes) {
    const puntos = [];
    ordenes.forEach(o => {
      if (o.lat === null || o.lng === null) return;
      const html =
        '<b>Orden N° ' + o.id + '</b> · ' + OT.esc(o.estado_txt) + '<br>' +
        '<b>' + OT.esc(o.trabajo) + '</b><br>' +
        OT.esc(o.nombre) + '<br>' + OT.esc(o.direccion) + '<br>' +
        'Grupo: ' + OT.esc(o.grupo) + '<br>' +
        (o.fecha ? OT.esc(o.fecha) + '<br>' : '') +
        (o.detalle ? '<i>' + OT.esc(o.detalle) + '</i><br>' : '') +
        (o.foto ? '<a href="orden.php?id=' + o.id + '"><img class="foto-popup" src="foto.php?id=' + o.id + '" alt="Foto"></a><br>' : '') +
        '<a href="orden.php?id=' + o.id + '">Ver detalle</a>';
      OT.circulo(o.lat, o.lng, o.color).addTo(mapa).bindPopup(html, {minWidth: 200});
      puntos.push([o.lat, o.lng]);
    });
    if (puntos.length) mapa.fitBounds(puntos, {padding: [40, 40], maxZoom: 16});
    return puntos.length;
  }
};
