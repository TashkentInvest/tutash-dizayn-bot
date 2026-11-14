<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Xatolik xaritada</title>
    <link rel="stylesheet" href="/vendor/leaflet/leaflet.css">
    <style>html, body, #map { height: 100%; margin: 0; } .leaflet-popup-content { font: 13px/1.45 system-ui, sans-serif; }</style>
</head>
<body>
<div id="map"></div>
<script src="/vendor/leaflet/leaflet.js"></script>
<script>
    var d = @json($data);
    var map = L.map('map');
    var osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' });
    var sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' });
    osm.addTo(map);
    L.control.layers({ 'Xarita': osm, 'Sputnik': sat }).addTo(map);
    var group = [];
    if (d.street && d.street.length > 1) {
        var line = L.polyline(d.street, { color: '#f59e0b', weight: 6, opacity: .85 }).addTo(map).bindPopup(d.streetName);
        group.push(line);
    }
    var me = L.circleMarker([d.lat, d.lon], { radius: 9, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1 })
        .addTo(map).bindPopup(d.meLabel);
    group.push(me);
    if (d.accuracy) group.push(L.circle([d.lat, d.lon], { radius: d.accuracy, color: '#dc2626', weight: 1, fillOpacity: .08 }).addTo(map));
    if (d.tlat !== null) {
        var target = L.circleMarker([d.tlat, d.tlon], { radius: 9, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 })
            .addTo(map).bindPopup(d.targetLabel);
        group.push(target);
        L.polyline([[d.lat, d.lon], [d.tlat, d.tlon]], { color: '#111827', weight: 2, dashArray: '6 6' }).addTo(map)
            .bindTooltip(d.distance, { permanent: true, direction: 'center', className: '' });
    }
    map.fitBounds(L.featureGroup(group).getBounds().pad(0.25), { maxZoom: 18 });
    me.openPopup();
</script>
</body>
</html>
