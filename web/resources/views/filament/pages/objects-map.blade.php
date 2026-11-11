<x-filament-panels::page>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        .omap-bar { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; font-size: 14px; }
        .omap-bar select { border: 1px solid rgba(127, 127, 127, .4); border-radius: 8px; padding: 6px 10px; background: transparent; color: inherit; }
        .dark .omap-bar select { color-scheme: dark; }
        .omap-stat b { font-size: 16px; }
        #omap { height: calc(100vh - 230px); min-height: 460px; border-radius: 12px; border: 1px solid rgba(127, 127, 127, .3); z-index: 0; }
        .omap-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px; opacity: .85; }
        .omap-legend i { display: inline-block; width: 22px; height: 4px; border-radius: 2px; vertical-align: middle; margin-right: 6px; }
        .omap-legend u { display: inline-block; width: 11px; height: 11px; border-radius: 50%; vertical-align: middle; margin-right: 6px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.3); }
        .leaflet-popup-content { font-size: 13px; line-height: 1.45; }
        .leaflet-popup-content a { color: #d97706; font-weight: 600; }
    </style>

    <div class="omap-bar">
        <label>Tuman:
            <select id="omap-district">
                <option value="">Butun shahar</option>
                @foreach ($districts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <span class="omap-stat">Ko‘chalar: <b>{{ $totals['streets'] }}</b></span>
        <span class="omap-stat">Ro‘yxatdagi obyektlar: <b>{{ $totals['planned'] }}</b></span>
        <span class="omap-stat">Xaritadagi obyektlar: <b>{{ $totals['objects'] }}</b></span>
        <span class="omap-stat">Bajarilish: <b>{{ $totals['pct'] }}</b></span>
    </div>

    <div class="omap-legend">
        <span><i style="background:#94a3b8"></i>Ko‘cha: hali boshlanmagan</span>
        <span><i style="background:#f59e0b"></i>jarayonda</span>
        <span><i style="background:#22c55e"></i>to‘liq bajarilgan</span>
        <span><u style="background:#64748b"></u>Obyekt: kutilmoqda</span>
        <span><u style="background:#f59e0b"></u>yechildi</span>
        <span><u style="background:#22c55e"></u>qo‘yildi</span>
        <span><u style="background:#3b82f6"></u>tasdiq kutmoqda</span>
    </div>

    <div wire:ignore><div id="omap"></div></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        (function () {
            var data = @json($mapData);
            var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
            var osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' });
            var sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' });
            var labels = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });
            var map = L.map('omap', { layers: [osm] }).setView([41.3111, 69.2797], 11);
            L.control.layers({ 'Xarita': osm, 'Sputnik': sat, 'Gibrid': L.layerGroup([sat, labels]) }, null, { position: 'topright' }).addTo(map);
            L.control.scale({ imperial: false }).addTo(map);

            var streetColor = function (s) { return s.pct >= 100 ? '#22c55e' : (s.installed || s.removed ? '#f59e0b' : '#94a3b8'); };
            var pointColor = { pending: '#64748b', removed: '#f59e0b', installed: '#22c55e', review: '#3b82f6' };
            var statusName = { pending: 'Kutilmoqda', removed: 'Yechildi', installed: 'Qo‘yildi', review: 'Tasdiq kutmoqda' };
            var streets = L.featureGroup().addTo(map), points = L.featureGroup().addTo(map);

            function draw(district) {
                streets.clearLayers(); points.clearLayers();
                data.streets.filter(function (s) { return !district || s.district_id === district; }).forEach(function (s) {
                    var line = L.polyline(s.line, { color: streetColor(s), weight: 6, opacity: .85 }).addTo(streets);
                    // start and end as small dots, so a short street is still visible from far away
                    s.line.forEach(function (p) { L.circleMarker(p, { radius: 3, color: streetColor(s), weight: 2, fillOpacity: 1 }).addTo(streets); });
                    line.bindTooltip(esc(s.name), { sticky: true });
                    line.bindPopup('<b>' + esc(s.name) + '</b><br>' + esc(s.district) + '<br>Jami obyekt: <b>' + s.total + '</b> · Yechildi: ' + s.removed +
                        ' · Qo‘yildi: ' + s.installed + '<br>Bajarilish: <b>' + String(s.pct).replace('.', ',') + '%</b>');
                });
                data.objects.filter(function (o) { return !district || o.district_id === district; }).forEach(function (o) {
                    L.circleMarker(o.at, { radius: 7, color: '#fff', weight: 2, fillColor: pointColor[o.status] || '#64748b', fillOpacity: 1 })
                        .bindPopup('<b>' + esc(o.name) + '</b><br>' + esc(o.street || '') + (o.address ? '<br>' + esc(o.address) : '') +
                            '<br>' + statusName[o.status] + (o.exact ? '' : ' · <i>qo‘shilgan joyi</i>') + '<br><a href="' + esc(o.url) + '">Obyektni ochish →</a>')
                        .addTo(points);
                });
                // an empty group has no bounds and would spoil the union: add only the groups that have something
                var b = null;
                [streets, points].forEach(function (g) {
                    if (g.getLayers().length) { var gb = g.getBounds(); if (gb.isValid()) b = b ? b.extend(gb) : L.latLngBounds(gb.getSouthWest(), gb.getNorthEast()); }
                });
                if (b) map.fitBounds(b, { padding: [30, 30], maxZoom: 16 });
            }
            document.getElementById('omap-district').addEventListener('change', function (e) { draw(+e.target.value || 0); });
            draw(0);
            setTimeout(function () { map.invalidateSize(); }, 300);
        })();
    </script>
</x-filament-panels::page>
