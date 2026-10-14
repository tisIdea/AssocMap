import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export { L };
export const locationLabels = { project_point: 'Project point', project_boundary: 'Project boundary' };

export function createGisMap(element, tileNotice) {
    const map = L.map(element, { minZoom: 2, maxZoom: 19, maxBounds: [[-90, -180], [90, 180]], maxBoundsViscosity: 1 });
    // This is a regional starting view, never a saved location coordinate.
    map.setView([10.3157, 123.8854], 9);
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, noWrap: true, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
    tiles.on('tileerror', () => { if (tileNotice) tileNotice.hidden = false; });
    L.control.scale({ imperial: false }).addTo(map);
    return map;
}

export function featureLayer(feature, select) {
    const color = feature.properties.location_type === 'project_point' ? '#228855' : '#7444a7';
    return L.geoJSON(feature, {
        pmIgnore: true,
        style: { color, weight: 3, fillOpacity: .16, pmIgnore: true },
        pointToLayer: (_feature, latlng) => L.circleMarker(latlng, { radius: 8, color, fillColor: color, fillOpacity: .9, weight: 2, pmIgnore: true }),
        onEachFeature: (_feature, layer) => {
            const popup = document.createElement('div');
            popup.className = 'gis-popup';
            const title = document.createElement('h3');
            title.textContent = feature.properties.title || 'Location';
            popup.append(title);
            [locationLabels[feature.properties.location_type], feature.properties.address].filter(Boolean).forEach(value => {
                const text = document.createElement('p'); text.textContent = value; popup.append(text);
            });
            layer.bindPopup(popup);
            layer.on('click', () => select(feature));
            layer.on('add', () => {
                const path = layer.getElement?.();
                if (!path) return;
                path.setAttribute('tabindex', '0'); path.setAttribute('role', 'button');
                path.setAttribute('aria-label', `View ${feature.properties.title || 'location'}`);
                path.addEventListener('keydown', event => {
                    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(feature); layer.openPopup(); }
                });
            });
        },
    });
}

export function fitLayer(map, layer) {
    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds.pad(.15), { maxZoom: 16 });
}
