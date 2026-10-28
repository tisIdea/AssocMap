import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';
import { siteSymbol } from '../../resources/js/pages/gis-site-symbols.js';

const codes = ['fish_cage', 'fish_pen', 'seaweed_cultivation_site', 'fishing_gear_site', 'post_harvest_facility'];
test('proposed site symbols differ by artwork and never interpolate catalog content', () => {
    const artwork = codes.map(code => siteSymbol(code).replace(/fill="[^"]*"/g, ''));
    assert.equal(new Set(artwork).size, 5);
    for (const code of [...codes, null, 'future_type', '<img src=x onerror=alert(1)>', '__proto__']) {
        const svg = siteSymbol(code);
        assert.match(svg, /^<svg /);
        assert.doesNotMatch(svg, /<image|href=|onerror|<img/);
    }
    assert.equal(siteSymbol('future_type'), siteSymbol(null));
});

function fixture() {
    const source = readFileSync(new URL('../../resources/js/pages/gis-map.js', import.meta.url), 'utf8')
        .replace(/^import .*;\r?\n/gm, '').replace(/^export \{ L \};\r?\n/m, '').replace(/export /g, '');
    const selected = [], icons = [], nodes = [];
    const L = {
        divIcon(options) { icons.push(options); return options; },
        marker(latlng, options) { return { latlng, options }; },
        geoJSON(feature, options) {
            if (feature === null) return { empty: true };
            const element = { attributes: {}, setAttribute(k, v) { this.attributes[k] = v; } };
            const layer = feature.geometry.type === 'Point' ? options.pointToLayer(feature, feature.geometry.coordinates) : { options: options.style };
            Object.assign(layer, { events: {}, on(name, fn) { this.events[name] = fn; }, bindPopup(node) { this.popup = node; },
                getElement: () => element, openPopup() { this.opened = true; } });
            options.onEachFeature(feature, layer); layer.events.add();
            layer.rendered = feature;
            nodes.push({ layer, element }); return layer;
        },
    };
    const document = { createElement: () => ({ children: [], append(child) { this.children.push(child); } }) };
    const context = createContext({ L, document, siteSymbol }); runInContext(source, context);
    return { context, selected, icons, nodes,
        render: (feature, publicView = false) => context.featureLayer(feature, item => selected.push(item), publicView) };
}

test('real point renderer uses cached local icons and preserves click, Enter and Space selection', () => {
    const f = fixture();
    const feature = { id: 'site', geometry: { type: 'Point', coordinates: [123.8, 10.3] },
        properties: { location_type: 'project_point', title: '<script>saved title</script>', site_type_code: 'fish_cage', site_type_name: 'Synthetic cage' } };
    const layer = f.render(feature), element = f.nodes[0].element;
    assert.equal(layer.options.keyboard, false); // Custom handler owns both keys; no duplicate Leaflet Enter selection.
    assert.equal(element.attributes.role, 'button'); assert.equal(element.attributes.tabindex, '0');
    assert.match(element.attributes['aria-label'], /Synthetic cage/);
    assert.equal(layer.popup.children[0].textContent, feature.properties.title);
    layer.events.click();
    let prevented = 0;
    for (const key of ['Enter', ' ', 'Escape']) element.onkeydown({ key, preventDefault() { prevented++; } });
    assert.deepEqual(f.selected.map(item => item.id), ['site', 'site', 'site']); assert.equal(prevented, 2); assert.equal(layer.opened, true);
    const second = f.render(feature); assert.equal(second.options.icon, layer.options.icon); assert.equal(f.icons.length, 1);
});

test('public Polygon and MultiPolygon use one classified display marker and retain original selection geometry', () => {
    const f = fixture();
    for (const type of ['Polygon', 'MultiPolygon']) {
        const feature = { id: type, geometry: { type, coordinates: [] }, properties: {
            location_type: 'project_boundary', site_type_code: 'fish_pen', display_point: { type: 'Point', coordinates: [123.4, 10.5] },
        } };
        const before = JSON.stringify(feature);
        const layer = f.render(feature, true);
        assert.deepEqual(layer.rendered.geometry, feature.properties.display_point);
        assert.equal(layer.options.icon.html, siteSymbol('fish_pen'));
        assert.match(layer.popup.children.at(-1).textContent, /not a surveyed site coordinate/);
        layer.events.click();
        f.nodes.at(-1).element.onkeydown({ key: ' ', preventDefault() {} });
        assert.equal(f.selected.at(-1), feature);
        assert.equal(f.selected.at(-1).geometry.type, type);
        assert.equal(JSON.stringify(feature), before);
    }
    assert.equal(f.icons.length, 1);
    assert.equal(f.nodes.length, 2);
});

test('public boundaries never invent a point or fall back to outlines when the display point is unavailable', () => {
    const f = fixture();
    for (const point of [null, { type: 'Point', coordinates: [NaN, 0] }, { type: 'Point', coordinates: [181, 0] }]) {
        assert.equal(f.render({ geometry: { type: 'Polygon' }, properties: { location_type: 'project_boundary', display_point: point } }, true).empty, true);
    }
    assert.equal(f.icons.length, 0);
    assert.match(siteSymbol(null), /#596b78/);
});

test('legacy and future codes have a safe fallback; classified boundaries retain polygon styling', () => {
    const f = fixture();
    for (const code of [null, 'future_type']) f.render({ id: 'legacy', geometry: { type: 'Point' },
        properties: { location_type: 'project_point', site_type_code: code } });
    assert.equal(f.icons.length, 1);
    const boundary = f.render({ id: 'boundary', geometry: { type: 'MultiPolygon' },
        properties: { location_type: 'project_boundary', site_type_code: 'fish_cage' } });
    assert.equal(boundary.options.color, '#7444a7'); assert.equal(boundary.options.fillOpacity, .16);
    assert.equal(f.icons.length, 1);
});
