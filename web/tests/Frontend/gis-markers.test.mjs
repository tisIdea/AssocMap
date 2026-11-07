import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';
import { siteSymbol } from '../../resources/js/pages/gis-site-symbols.js';

const codes = ['fish_cage', 'fish_pen', 'culture_pond', 'seaweed_cultivation_site', 'hatchery_nursery', 'payao_installation', 'post_harvest_facility'];
test('all seven site symbols have distinct artwork and never interpolate catalog content', () => {
    const artwork = codes.map(code => siteSymbol(code).replace(/fill="[^"]*"/g, ''));
    assert.equal(new Set(artwork).size, 7);
    for (const code of [...codes, null, 'future_type', '<img src=x onerror=alert(1)>', '__proto__']) {
        const svg = siteSymbol(code);
        assert.match(svg, /^<svg /);
        assert.doesNotMatch(svg, /<image|href=|onerror|<img/);
    }
    assert.equal(siteSymbol('future_type'), siteSymbol(null));
    assert.notEqual(siteSymbol('fishing_gear_site'), siteSymbol(null));
});

test('classification colors are distinct and contrast with white icon strokes', () => {
    const colors = [...codes, null].map(code => siteSymbol(code).match(/fill="(#[a-f0-9]{6})"/)[1]);
    assert.equal(new Set(colors).size, 8);
    assert.equal(colors.at(-1), '#64748b');
    for (const color of colors) {
        const linear = color.slice(1).match(/../g).map(channel => {
            const value = parseInt(channel, 16) / 255;
            return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4;
        });
        const luminance = linear[0] * .2126 + linear[1] * .7152 + linear[2] * .0722;
        assert.ok(1.05 / (luminance + .05) >= 4.5, `${color} must contrast with white`);
    }
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
            const element = { attributes: {}, classes: new Set(), setAttribute(k, v) { this.attributes[k] = v; } };
            element.classList = { toggle(name, active) { active ? element.classes.add(name) : element.classes.delete(name); } };
            const layer = feature.geometry.type === 'Point' ? options.pointToLayer(feature, feature.geometry.coordinates) : { options: options.style };
            Object.assign(layer, { events: {}, on(name, fn) { this.events[name] = fn; }, bindPopup(node) { this.popup = node; },
                getElement: () => element, openPopup() { this.opened = true; }, eachLayer(fn) { fn(this); },
                setStyle(style) { this.style = style; }, bringToFront() { this.front = true; } });
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
    assert.match(siteSymbol(null), /#64748b/);
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

test('all seven classifications use their own symbol in actual point and boundary markers', () => {
    const f = fixture();
    for (const code of codes) {
        const layer = f.render({ id: code, geometry: { type: 'Point', coordinates: [123, 10] },
            properties: { location_type: 'project_point', title: code, site_type_code: code, site_type_name: 'Proposed '+code } });
        assert.equal(layer.options.icon.html, siteSymbol(code));
        assert.notEqual(layer.options.icon.html, siteSymbol(null));
        assert.match(f.nodes.at(-1).element.attributes['aria-label'], new RegExp('Proposed '+code));
        assert.deepEqual(Array.from(layer.options.icon.iconSize), [44, 44]);
        const boundary = f.render({ id: code + '-boundary', geometry: { type: 'Polygon' }, properties: { location_type: 'project_boundary',
            site_type_code: code, display_point: { type: 'Point', coordinates: [123, 10] } } }, true);
        assert.equal(boundary.options.icon.html, siteSymbol(code));
        assert.match(boundary.options.icon.className, /gis-boundary-marker/);
        assert.match(f.nodes.at(-1).element.attributes['aria-label'], /representative position/);
    }
    assert.equal(f.icons.length, 14);
});

test('selected point and boundary emphasis follows identity without changing their geometries', () => {
    const f = fixture();
    const source = [{ id: 'point', geometry: { type: 'Point', coordinates: [123, 10] }, properties: { location_type: 'project_point' } },
        { id: 'area', geometry: { type: 'MultiPolygon', coordinates: [] }, properties: { location_type: 'project_boundary' } }];
    const before = JSON.stringify(source);
    const layers = source.map(feature => f.render(feature));
    const group = { eachLayer(fn) { layers.forEach(fn); } };
    f.context.setFeatureSelection(group, 'area');
    assert.equal(f.nodes[0].element.attributes['aria-pressed'], 'false');
    assert.equal(f.nodes[1].element.attributes['aria-pressed'], 'true');
    assert.ok(f.nodes[1].element.classes.has('is-selected'));
    assert.equal(layers[1].style.weight, 5);
    assert.equal(layers[1].style.color, '#174d36');
    f.context.setFeatureSelection(group, 'point');
    assert.equal(f.nodes[0].element.attributes['aria-pressed'], 'true');
    assert.equal(f.nodes[1].element.attributes['aria-pressed'], 'false');
    assert.equal(layers[1].style.color, '#7444a7');
    f.context.setFeatureSelection(group, null);
    assert.ok(f.nodes.every(({ element }) => element.attributes['aria-pressed'] === 'false' && !element.classes.has('is-selected')));
    assert.equal(JSON.stringify(source), before);
});
