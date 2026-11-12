<x-filament-panels::page>
    <link rel="stylesheet" href="/vendor/leaflet/leaflet.css">
    <style>
        .omap-bar { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; font-size: 14px; }
        .omap-bar select { border: 1px solid rgba(127, 127, 127, .4); border-radius: 8px; padding: 6px 10px; background: transparent; color: inherit; }
        .dark .omap-bar select { color-scheme: dark; }
        .omap-stat b { font-size: 16px; }
        #omap { height: calc(100vh - 230px); min-height: 460px; border-radius: 12px; border: 1px solid rgba(127, 127, 127, .3); z-index: 0; background: #f1f5f9; }
        /* a quiet grey base map, so the coloured streets and points are what the eye sees first */
        .omap-grey { filter: grayscale(1) brightness(1.04) contrast(.9); }
        .omap-legend { display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 13px; opacity: .9; align-items: center; }
        .omap-legend i { display: inline-block; width: 26px; height: 5px; border-radius: 3px; vertical-align: middle; margin-right: 6px; }
        .omap-legend u { display: inline-block; width: 11px; height: 11px; border-radius: 50%; vertical-align: middle; margin-right: 6px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.3); }
        .omap-legend .sep { width: 1px; height: 16px; background: rgba(127,127,127,.4); }
        .leaflet-popup-content { font-size: 13px; line-height: 1.45; }
        .leaflet-popup-content a { color: #d97706; font-weight: 600; }
        /* street names on the map itself, only when zoomed in enough to read them */
        .omap-label { background: none; border: 0; box-shadow: none; padding: 0; font: 600 12px/1 system-ui, sans-serif; color: #7f1d1d;
            text-shadow: 0 0 3px #fff, 0 0 3px #fff, 0 0 3px #fff; white-space: nowrap; display: none; }
        .omap-label::before { display: none; }
        #omap.omap-near .omap-label { display: block; }
    </style>

    <div class="omap-bar">
        <label>Tuman:
            <select id="omap-district">
                <option value="">Butun shahar</option>
                @foreach ($districts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label>Ko‘chalar rangi:
            <select id="omap-mode">
                <option value="kmz">KMZ chiziqlari qizil</option>
                <option value="pct">Bajarilish bo‘yicha</option>
            </select>
        </label>
        <span class="omap-stat">Ko‘chalar: <b>{{ $totals['streets'] }}</b></span>
        <span class="omap-stat">Ro‘yxatdagi obyektlar: <b>{{ $totals['planned'] }}</b></span>
        <span class="omap-stat">Xaritadagi obyektlar: <b>{{ $totals['objects'] }}</b></span>
        <span class="omap-stat">Bajarilish: <b>{{ $totals['pct'] }}</b></span>
    </div>

    <div class="omap-legend" id="omap-legend"></div>

    <div wire:ignore><div id="omap"></div></div>

    <script src="/vendor/leaflet/leaflet.js"></script>
    <script>
        (function () {
            var data = @json($mapData);
            var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
            var osmUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
            var osmOpt = { maxZoom: 19, referrerPolicy: 'strict-origin-when-cross-origin', attribution: '© OpenStreetMap' };
            var grey = L.tileLayer(osmUrl, Object.assign({ className: 'omap-grey' }, osmOpt));
            var osm = L.tileLayer(osmUrl, osmOpt);
            var sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' });
            var labels = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });
            var map = L.map('omap', { layers: [grey] }).setView([41.3111, 69.2797], 11);
            L.control.layers({ 'Oddiy (kulrang)': grey, 'Rangli': osm, 'Sputnik': sat, 'Gibrid': L.layerGroup([sat, labels]) }, null, { position: 'topright' }).addTo(map);
            L.control.scale({ imperial: false }).addTo(map);

            var RED = '#dc2626';
            var pctColor = function (s) { return s.pct >= 100 ? '#16a34a' : (s.installed || s.removed ? '#f59e0b' : '#64748b'); };
            var pointColor = { pending: '#64748b', removed: '#f59e0b', installed: '#22c55e', review: '#3b82f6' };
            var statusName = { pending: 'Kutilmoqda', removed: 'Yechildi', installed: 'Qo‘yildi', review: 'Tasdiq kutmoqda' };
            var streets = L.featureGroup().addTo(map), points = L.featureGroup().addTo(map);
            var district = 0, mode = 'kmz';

            function legend() {
                var dot = function (c, t) { return '<span><u style="background:' + c + '"></u>' + t + '</span>'; };
                var line = function (css, t) { return '<span><i style="' + css + '"></i>' + t + '</span>'; };
                var dashed = function (c) { return 'background:repeating-linear-gradient(90deg,' + c + ' 0 7px,transparent 7px 11px)'; };
                var ln = mode === 'kmz'
                    ? line('background:' + RED, '<b>KMZ bo‘yicha ko‘cha</b> (haqiqiy shakli)') + line(dashed('#64748b'), 'shakli yuklanmagan: faqat boshi–oxiri')
                    : line('background:#64748b', 'boshlanmagan') + line('background:#f59e0b', 'jarayonda') + line('background:#16a34a', 'to‘liq bajarilgan') +
                      line(dashed('#64748b'), 'uzuq: shakli yuklanmagan');
                document.getElementById('omap-legend').innerHTML = ln + '<span class="sep"></span>' +
                    dot('#64748b', 'Obyekt: kutilmoqda') + dot('#f59e0b', 'yechildi') + dot('#22c55e', 'qo‘yildi') + dot('#3b82f6', 'tasdiq kutmoqda');
            }

            function popup(s) {
                var at = function (p, label) {
                    return '<br>' + label + ': <a href="#" data-fly="' + p[0] + ',' + p[1] + '"><code>' + p[0].toFixed(6) + ', ' + p[1].toFixed(6) + '</code></a>';
                };
                return '<b>' + esc(s.name) + '</b><br>' + esc(s.district) + (s.path ? ' · <span style="color:' + RED + '">KMZ chizig‘i</span>' : '') +
                    '<br>Jami obyekt: <b>' + s.total + '</b> · Yechildi: ' + s.removed + ' · Qo‘yildi: ' + s.installed +
                    '<br>Bajarilish: <b>' + String(s.pct).replace('.', ',') + '%</b>' +
                    at(s.line[0], 'Дан (boshlanishi)') + at(s.line[1], 'Гача (tugashi)');
            }

            function draw(fit) {
                streets.clearLayers(); points.clearLayers();
                var list = data.streets.filter(function (s) { return !district || s.district_id === district; });
                // the straight, shapeless ones first, so the real KMZ shapes lie on top of them
                list.sort(function (a, b) { return (a.path ? 1 : 0) - (b.path ? 1 : 0); });
                list.forEach(function (s) {
                    var color = mode === 'kmz' ? (s.path ? RED : '#64748b') : pctColor(s);
                    var pts = s.path || s.line;
                    if (s.path) {
                        L.polyline(pts, { color: '#fff', weight: 10, opacity: .9, interactive: false }).addTo(streets);  // white casing: the line stands out on any map
                    }
                    var line = L.polyline(pts, s.path
                        ? { color: color, weight: 5, opacity: 1, lineCap: 'round', lineJoin: 'round' }
                        : { color: color, weight: 3, opacity: .75, dashArray: '8 7' }).addTo(streets);
                    s.line.forEach(function (p) {
                        L.circleMarker(p, { radius: s.path ? 4 : 3, color: color, weight: 2, fillColor: '#fff', fillOpacity: 1 }).bindPopup(popup(s)).addTo(streets);
                    });
                    line.bindPopup(popup(s));
                    if (s.path) {
                        line.bindTooltip(esc(s.name), { permanent: true, direction: 'center', className: 'omap-label' });
                    } else {
                        line.bindTooltip(esc(s.name) + ' · ' + String(s.pct).replace('.', ',') + '%', { sticky: true });
                    }
                });
                data.objects.filter(function (o) { return !district || o.district_id === district; }).forEach(function (o) {
                    L.circleMarker(o.at, { radius: 7, color: '#fff', weight: 2, fillColor: pointColor[o.status] || '#64748b', fillOpacity: 1 })
                        .bindPopup('<b>' + esc(o.name) + '</b><br>' + esc(o.street || '') + (o.address ? '<br>' + esc(o.address) : '') +
                            '<br>' + statusName[o.status] + (o.exact ? '' : ' · <i>qo‘shilgan joyi</i>') + '<br><a href="' + esc(o.url) + '">Obyektni ochish →</a>')
                        .addTo(points);
                });
                legend();
                if (!fit) return;
                // an empty group has no bounds and would spoil the union: add only the groups that have something
                var b = null;
                [streets, points].forEach(function (g) {
                    if (g.getLayers().length) { var gb = g.getBounds(); if (gb.isValid()) b = b ? b.extend(gb) : L.latLngBounds(gb.getSouthWest(), gb.getNorthEast()); }
                });
                if (b) map.fitBounds(b, { padding: [30, 30], maxZoom: 16 });
            }
            var near = function () { map.getContainer().classList.toggle('omap-near', map.getZoom() >= 15); };
            map.on('zoomend', near);
            map.getContainer().addEventListener('click', function (e) {
                var a = e.target.closest && e.target.closest('[data-fly]');
                if (!a) return;
                e.preventDefault();
                var p = a.dataset.fly.split(',').map(Number);
                map.flyTo(p, 17);
                L.circleMarker(p, { radius: 9, color: '#dc2626', weight: 3, fillOpacity: 0 }).addTo(streets);  // where the coordinate is
            });
            document.getElementById('omap-district').addEventListener('change', function (e) { district = +e.target.value || 0; draw(true); });
            document.getElementById('omap-mode').addEventListener('change', function (e) { mode = e.target.value; draw(false); });
            draw(true);
            near();
            setTimeout(function () { map.invalidateSize(); }, 300);
        })();
    </script>
</x-filament-panels::page>
