// Real production bundles and rendered Blade; all data/media HTTP responses are synthetic.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [directory, playwrightPackage] = process.argv.slice(2);
assert.match(path.basename(directory || ''), /^assocmap-gisclass-ui-[a-f0-9]{32}$/);
const { chromium } = await import(pathToFileURL(path.join(playwrightPackage, 'index.mjs')));
const web = path.resolve(import.meta.dirname, '../..');
const manifest = JSON.parse(await readFile(path.join(web, 'public/build/manifest.json'), 'utf8'));
const styles = [...new Set(Object.values(manifest).map(entry => entry.file).filter(file => file.endsWith('.css')))];
let origin, browser, checks = 0;
const check = (condition, label) => { assert.ok(condition, label); checks++; };
const server = createServer(async (req, res) => {
    const pathname = new URL(req.url, origin).pathname;
    try {
        if (['/staff', '/public', '/editor', '/empty-editor', '/empty-public', '/boundary-editor', '/multipolygon-editor'].includes(pathname)) {
            let html = await readFile(path.join(directory, pathname.slice(1) + '.html'), 'utf8');
            html = html.replaceAll('http://localhost', origin);
            html = html.replace('</head>', styles.map(file => `<link rel="stylesheet" href="/build/${file}">`).join('') + '</head>')
                .replace('</body>', `<script type="module" src="/build/${manifest['resources/js/app.js'].file}"></script></body>`);
            res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html);
        } else if (pathname.startsWith('/build/assets/') && !pathname.includes('..')) {
            const file = pathname.slice('/build/'.length);
            res.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'image/png');
            res.end(await readFile(path.join(web, 'public/build', file)));
        } else { res.statusCode = 404; res.end(); }
    } catch { res.statusCode = 500; res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
origin = 'http://127.0.0.1:' + server.address().port;
const types = [
    ['fish_cage', 'Fish cage'], ['fish_pen', 'Fish pen'], ['seaweed_cultivation_site', 'Seaweed cultivation site'],
    ['fishing_gear_site', 'Fishing gear site'], ['post_harvest_facility', 'Post-harvest facility'],
];
const makeFeature = (index, boundary = false) => ({ type: 'Feature', id: 'site-' + index,
    geometry: boundary ? { type: 'Polygon', coordinates: [[[123.7, 10.2], [123.75, 10.2], [123.75, 10.25], [123.7, 10.2]]] }
        : { type: 'Point', coordinates: [123.8 + index * .025, 10.3 + index * .018] },
    properties: { location_type: boundary ? 'project_boundary' : 'project_point', title: boundary ? 'Published boundary' : 'Published site ' + index,
        site_type_id: index < 5 ? index + 1 : null, site_type_code: types[index]?.[0] || null, site_type_name: types[index]?.[1] || null,
        address: 'Approved address', version: 'synthetic-version', is_published: true, archived_at: null },
});
const polygon = { ...makeFeature(0, true), id: 'boundary' };
polygon.properties.display_point = { type: 'Point', coordinates: [123.73, 10.22] };
const multi = { ...makeFeature(0, true), id: 'multi-boundary', geometry: { type: 'MultiPolygon', coordinates: [
    [[[123.7, 10.2], [123.75, 10.2], [123.75, 10.25], [123.7, 10.2]]],
    [[[123.8, 10.3], [123.85, 10.3], [123.85, 10.35], [123.8, 10.3]]],
] }, properties: { ...makeFeature(0, true).properties, title: 'Published multipart boundary', display_point: { type: 'Point', coordinates: [123.83, 10.32] } } };
const neutral = { ...multi, id: 'neutral-boundary', properties: { ...multi.properties, title: 'Unclassified multipart boundary',
    site_type_id: null, site_type_code: null, site_type_name: null, display_point: { type: 'Point', coordinates: [123.72, 10.21] } } };
const records = [...Array.from({ length: 6 }, (_, index) => makeFeature(index)), polygon, multi, neutral];
const png = await readFile(path.join(directory, 'photo.png'));
try {
    browser = await chromium.launch({ channel: 'msedge', headless: true });
    for (const width of [1280, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        const errors = [], requests = [], writes = [], external = [], galleryRequests = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (url.origin !== origin) { external.push(url.pathname); return route.abort(); }
            if (url.pathname.endsWith('/parents') || url.pathname.endsWith('/associations')) {
                return route.fulfill({ json: { options: [{ value: '11111111-1111-4111-8111-111111111111', label: 'Synthetic parent' }], truncated: false } });
            }
            if (url.pathname.endsWith('/data')) {
                requests.push(url);
                const type = url.searchParams.get('site_type_id');
                const available = page.url().endsWith('/empty-public') ? records.filter(feature => feature.properties.site_type_id === null) : records;
                const features = available.filter(feature => !type || (type === 'unclassified' ? feature.properties.site_type_id === null : String(feature.properties.site_type_id) === type))
                    .map(feature => page.url().endsWith('/staff') && feature.id === 'site-0' ? { ...feature, properties: { ...feature.properties, is_published: false } } : feature);
                if (url.searchParams.get('mode') === 'overview') return route.fulfill({ json: { type: 'FeatureCollection', features: [], meta: { bounds: [123.65, 10.15, 124, 10.5] } } });
                return route.fulfill({ json: { type: 'FeatureCollection', features, meta: { limit: 100, truncated: false, page: 1, last_page: 1, total: features.length } } });
            }
            if (/\/photos\/.+\/(thumbnail|display)$/.test(url.pathname)) return route.fulfill({ contentType: 'image/png', body: png });
            if (url.pathname.endsWith('/photos')) {
                galleryRequests.push(url.pathname);
                const photos = url.pathname.includes('/boundary/photos') ? [{ id: 'photo-boundary', approved: true,
                    thumbnail_url: url.pathname + '/photo-boundary/thumbnail', display_url: url.pathname + '/photo-boundary/display', thumbnail_width: 64, thumbnail_height: 32 }] : [];
                return route.fulfill({ json: { photos, pending: [], used_slots: photos.length, limit: 5, can_manage: !url.pathname.startsWith('/map/') } });
            }
            if (request.method() === 'PATCH') {
                writes.push({ payload: request.postDataJSON(), csrf: request.headers()['x-csrf-token'] });
                return route.fulfill({ json: { ...makeFeature(0), id: '11111111-1111-4111-8111-111111111111' } });
            }
            return route.continue();
        });
        const idle = () => page.waitForFunction(() => document.querySelector('[data-gis-workspace]')?.getAttribute('aria-busy') !== 'true'
            && document.querySelector('[data-gis-status]')?.textContent.includes('locations loaded')).catch(async error => {
            console.log({ status: await page.locator('[data-gis-status]').textContent(), errors, requests: requests.map(url => url.toString()) });
            throw error;
        });
        for (const viewer of ['staff', 'public']) {
            await page.goto(origin + '/' + viewer); await idle();
            const root = page.locator('[data-gis-workspace]');
            check(await root.locator('.gis-site-marker').count() === (viewer === 'public' ? 9 : 6), viewer + ' renders one marker per eligible displayed location');
            check(await root.locator('path.leaflet-interactive').count() === (viewer === 'public' ? 0 : 3), viewer + ' uses the correct public/staff boundary representation');
            if (viewer === 'staff') check((await root.locator('path.leaflet-interactive').nth(1).getAttribute('d')).match(/M/g).length === 2, 'staff multipart boundary retains both polygon parts');
            check(await root.locator('.gis-legend [data-gis-site-symbol] svg').count() === 6, viewer + ' legend uses matching vector symbols');
            const symbols = await root.locator('.gis-site-marker').evaluateAll(nodes => nodes.map(node => node.innerHTML));
            check(new Set(symbols).size === 6, viewer + ' distinct artwork for each type and legacy');
            check(await root.locator('.gis-legend').textContent().then(text => types.every(([, name]) => text.includes(name))), viewer + ' legend names each synthetic type');
            const marker = root.locator('.gis-site-marker').first();
            await marker.focus(); await page.keyboard.press('Enter');
            await page.waitForFunction(() => document.querySelector('[data-gis-selection-title]').textContent === 'Published site 0');
            check(await root.locator('[data-gis-details]').textContent().then(text => text.includes('Fish cage')), viewer + ' Enter selects classification details');
            if (viewer === 'staff') {
                await root.getByRole('button', { name: 'Publish', exact: true }).click();
                check(await root.locator('[data-gis-confirm-preview]').textContent().then(text => text.includes('Site classification') && text.includes('Fish cage')), 'publication preview includes classification');
                await root.locator('[data-gis-confirm-cancel]').click();
            }
            await root.locator('[data-gis-dismiss]').click(); await marker.focus(); await page.keyboard.press('Space');
            check(await root.locator('[data-gis-selection-title]').textContent() === 'Published site 0', viewer + ' Space selects point');
            const boundary = viewer === 'public' ? root.locator('.gis-site-marker[aria-label^="View Published boundary,"]') : root.locator('path.leaflet-interactive').first();
            await boundary.focus(); await page.keyboard.press('Enter');
            check(await root.locator('[data-gis-selection-title]').textContent() === 'Published boundary', viewer + ' boundary keyboard selection');
            await root.locator('[data-gis-photos] [data-photo-viewer]').waitFor({ state: 'attached' });
            await root.getByRole('button', { name: 'View photo 1', exact: true }).click();
            check(await root.locator('[data-photo-viewer]').evaluate(node => node.open), viewer + ' boundary photo gallery remains usable');
            await page.keyboard.press('Escape');
            if (viewer === 'public') {
                check(await root.locator('[data-gis-details]').textContent().then(text => text.includes('not a surveyed site coordinate')), 'boundary selection explains representative position');
                check(await root.locator('.leaflet-popup-content').filter({ has: page.getByRole('heading', { name: 'Published boundary', exact: true }) }).textContent().then(text => text.includes('not a surveyed site coordinate')), 'boundary popup explains representative position');
                check(galleryRequests.includes('/map/boundary/photos'), 'public marker requests original boundary gallery');
                await root.locator('.gis-site-marker[aria-label^="View Published multipart boundary,"]').focus(); await page.keyboard.press('Space');
                check(await root.locator('[data-gis-selection-title]').textContent() === 'Published multipart boundary', 'multipart boundary uses one keyboard-accessible marker');
                check(await root.locator('.gis-site-marker[aria-label^="View Unclassified multipart boundary,"] svg path').first().getAttribute('fill') === '#596b78', 'unclassified boundary uses neutral fallback');
            }
            await root.locator('.gis-filters summary').click();
            await root.locator('[name=site_type_id]').selectOption('2'); await idle();
            check(await root.locator('.gis-site-marker').count() === 1, viewer + ' filter narrows map');
            check(requests.at(-1).searchParams.get('site_type_id') === '2' && requests.at(-1).searchParams.has('bbox[0]'), viewer + ' viewport and site filter coexist');
            await root.locator('[data-gis-view=list]').click(); await idle();
            check(await root.locator('.gis-record-card').count() === 1, viewer + ' list uses same filter');
            check(await root.locator('.gis-record-card').textContent().then(text => text.includes('Fish pen')), viewer + ' list shows current classification');
            await root.locator('[name=site_type_id]').selectOption('unclassified'); await idle();
            check(await root.locator('.gis-record-card').allTextContents().then(texts => texts.length === 2 && texts.every(text => text.includes('Unclassified site'))), viewer + ' legacy point and boundary list entries are usable');
            await root.locator('[type=reset]').click(); await idle();
            check(await root.locator('[name=site_type_id]').inputValue() === '', viewer + ' reset clears classification');
            await root.locator('[data-gis-view=map]').click(); await idle();
            check(await root.locator('.gis-site-marker').count() === (viewer === 'public' ? 9 : 6), viewer + ' reset restores locations without duplicate markers');
            if (viewer === 'public') {
                await root.locator('[data-gis-view=list]').click(); await idle();
                const card = root.locator('.gis-record-card').filter({ has: page.getByRole('heading', { name: 'Published boundary', exact: true }) });
                await card.getByRole('button', { name: 'View location', exact: true }).click();
                check(await root.locator('.gis-site-marker[aria-label^="View Published boundary,"]').count() === 1, 'list selection fits a single representative marker');
                check(await root.locator('path.leaflet-interactive').count() === 0, 'public list selection never draws the original polygon');
            }
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), viewer + ' responsive page has no horizontal overflow');
            await page.screenshot({ path: path.join(directory, viewer + '-' + width + '.png'), fullPage: true });
        }
        await page.goto(origin + '/editor');
        await page.locator('[data-gis-save]:not([disabled])').waitFor();
        check(await page.locator('[name=site_type_id]').inputValue() === '1', 'editor preselects saved classification');
        await page.locator('[name=site_type_id]').selectOption('4');
        await page.locator('[data-gis-save]').click();
        await page.waitForFunction(() => document.querySelector('[data-global-feedback]')?.open);
        check(writes.length === 1 && writes[0].payload.site_type_id === '4', 'editor submits changed classification once');
        check(writes[0].payload.version === 'saved-version' && Boolean(writes[0].csrf), 'editor retains version and CSRF');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'responsive editor has no horizontal overflow');
        for (const [view, type, parts] of [['boundary-editor', 'Polygon', 1], ['multipolygon-editor', 'MultiPolygon', 2]]) {
            await page.goto(origin + '/' + view); await page.locator('[data-gis-save]:not([disabled])').waitFor();
            const geometry = JSON.parse(await page.locator('[name=geometry]').inputValue());
            check(geometry.type === type, type + ' editor retains original geometry type');
            check(await page.locator('path.leaflet-interactive').count() === parts, type + ' editor shows all boundary parts');
            check(await page.locator('.leaflet-pm-toolbar').count() > 0, type + ' geometry tools remain available');
            await page.locator('[data-gis-save]').click(); await page.waitForFunction(() => document.querySelector('[data-global-feedback]')?.open);
            check(JSON.stringify(writes.at(-1).payload.geometry) === JSON.stringify(geometry), type + ' save preserves original boundary coordinates');
            check(!Object.hasOwn(writes.at(-1).payload, 'display_point'), type + ' save never submits a calculated coordinate');
        }
        await page.goto(origin + '/empty-editor');
        await page.waitForFunction(() => document.querySelector('[data-gis-editor]').dataset.gisInitialized === 'true');
        check(await page.locator('[name=site_type_id] option').count() === 1, 'empty editor offers only optional unclassified');
        check(await page.getByText('No site classifications have been approved yet.', { exact: false }).isVisible(), 'empty editor explains catalog availability');
        await page.goto(origin + '/empty-public'); await idle();
        check(await page.locator('.gis-site-marker').count() === 2, 'empty catalog preserves legacy point and unclassified boundary marker');
        check(await page.locator('.gis-legend [data-gis-site-symbol]').count() === 1, 'empty catalog legend has generic point');
        check(errors.length === 0, 'no browser errors: ' + errors.join('; '));
        check(external.every(url => !/marker|\.svg/.test(url)), 'no external marker assets requested');
        await page.close();
    }
    await writeFile(path.join(directory, 'results.json'), JSON.stringify({ checks, widths: [1280, 390], boundary: 'real built app/Leaflet/Blade; synthetic HTTP data, external requests blocked' }, null, 2));
    console.log('PASS: ' + checks + ' GIS classification browser checks.');
} finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
