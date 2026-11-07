import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { siteSymbol } from './gis-site-symbols.js';

export { L };
export const locationLabels = { project_point: 'Project Point', project_boundary: 'Project Boundary' };
const pointIcons = new Map();

function pointIcon(code, boundary = false) {
    // Bound cache keys to fixed artwork and the two existing geometry presentations.
    const html = siteSymbol(code);
    const key = html + (boundary ? ':boundary' : ':point');
    if (!pointIcons.has(key)) pointIcons.set(key, L.divIcon({
        html, className: 'gis-site-marker' + (boundary ? ' gis-boundary-marker' : ''),
        iconSize: [44, 44], iconAnchor: [22, 43], popupAnchor: [0, -38],
    }));
    return pointIcons.get(key);
}

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

export function featureLayer(feature, select, publicView = false) {
    const representative = publicView && feature.properties.location_type === 'project_boundary';
    let rendered = feature;
    if (representative) {
        const point = feature.properties.display_point;
        if (point?.type !== 'Point' || !Array.isArray(point.coordinates) || point.coordinates.length !== 2
            || !point.coordinates.every(Number.isFinite) || Math.abs(point.coordinates[0]) > 180 || Math.abs(point.coordinates[1]) > 90) {
            return L.geoJSON(null);
        }
        rendered = { ...feature, geometry: point };
    }
    const color = feature.properties.location_type === 'project_point' ? '#228855' : '#7444a7';
    const group = L.geoJSON(rendered, {
        pmIgnore: true,
        style: { color, weight: 3, fillOpacity: .16, pmIgnore: true },
        pointToLayer: (_feature, latlng) => L.marker(latlng, { icon: pointIcon(feature.properties.site_type_code, representative), keyboard: false, pmIgnore: true }),
        onEachFeature: (_feature, layer) => {
            const popup = document.createElement('div');
            popup.className = 'gis-popup';
            const title = document.createElement('h3');
            title.textContent = feature.properties.title || 'Location';
            popup.append(title);
            [`Geometry type: ${locationLabels[feature.properties.location_type]}`, `Site classification: ${feature.properties.site_type_name || 'Unclassified'}`, feature.properties.address].filter(Boolean).forEach(value => {
                const text = document.createElement('p'); text.textContent = value; popup.append(text);
            });
            if (representative) {
                const note = document.createElement('p');
                note.textContent = 'Calculated representative position within the boundary, not a surveyed site coordinate.';
                popup.append(note);
            }
            layer.bindPopup(popup);
            layer.on('click', () => select(feature));
            layer.on('add', () => {
                const path = layer.getElement?.();
                if (!path) return;
                path.setAttribute('tabindex', '0'); path.setAttribute('role', 'button');
                path.setAttribute('aria-pressed', 'false');
                path.setAttribute('aria-label', `View ${feature.properties.title || 'location'}, ${feature.properties.site_type_name || 'unclassified site'}, ${locationLabels[feature.properties.location_type]}${representative ? ', representative position' : ''}`);
                path.onkeydown = event => {
                    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(feature); layer.openPopup(); }
                };
            });
        },
    });
    group.gisFeatureId = feature.id;
    return group;
}

export function setFeatureSelection(group, id) {
    group.eachLayer(feature => {
        const selected = feature.gisFeatureId === id;
        feature.setStyle?.({ color: selected ? '#174d36' : '#7444a7', weight: selected ? 5 : 3, fillOpacity: selected ? .24 : .16 });
        feature.eachLayer?.(layer => {
            const element = layer.getElement?.();
            element?.classList.toggle('is-selected', selected);
            element?.setAttribute('aria-pressed', String(selected));
            if (selected) layer.bringToFront?.();
        });
    });
}

export function fitLayer(map, layer) {
    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds.pad(.15), { maxZoom: 16 });
}
