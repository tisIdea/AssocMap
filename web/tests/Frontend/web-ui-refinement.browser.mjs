// Real Blade and production bundles. Loopback-only server; every external request is blocked.
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [directory, phase = 'after', playwrightPackage = path.join(directory, 'tools/node_modules/playwright')] = process.argv.slice(2);
assert.match(path.basename(directory || ''), /^assocmap-ui-f1-[a-f0-9]{32}$/);
assert.ok(['before', 'after'].includes(phase));
const { chromium } = await import(pathToFileURL(path.join(playwrightPackage, 'index.mjs')));
const build = path.join(directory, 'build-' + phase);
const manifest = JSON.parse(await readFile(path.join(build, 'manifest.json'), 'utf8'));
const styles = Object.values(manifest).filter(entry => entry.file.endsWith('.css')).map(entry => entry.file);
const screenshots = path.join(directory, phase);
await mkdir(screenshots, { recursive: true });
let origin, browser, checks = 0;
const observations = [], errors = [];
const check = (value, label) => { assert.ok(value, label); checks++; };
const cardAppearance = node => {
    const style = getComputedStyle(node), number = getComputedStyle(node.querySelector('strong')),
        note = getComputedStyle(node.querySelector('small')), icon = getComputedStyle(node.querySelector('.stat-icon'));
    return { background: style.backgroundColor, border: style.borderColor, shadow: style.boxShadow, padding: style.padding,
        numberColor: number.color, numberSize: number.fontSize, noteColor: note.color, noteSize: note.fontSize,
        iconColor: icon.color, iconBackground: icon.backgroundColor, value: node.querySelector('strong').textContent };
};
const contrast = (first, second) => {
    const luminance = color => color.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => v / 255)
        .map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
    const values = [luminance(first), luminance(second)].sort((a, b) => b - a);
    return (values[0] + .05) / (values[1] + .05);
};
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, origin), pathname = url.pathname;
        if (pathname === '/' || /^\/(home|login|staff|officer|association|public|editor|controls|areas|members)$/.test(pathname) || pathname === '/admin/areas') {
            const fixture = pathname === '/admin/areas' ? (url.searchParams.get('tab') === 'barangays' ? 'areas-filtered' : 'areas') : pathname === '/' ? 'home' : pathname.slice(1);
            let html = await readFile(path.join(directory, fixture + '.html'), 'utf8');
            html = html.replaceAll('http://localhost', origin)
                .replace('</head>', styles.map(file => `<link rel="stylesheet" href="/build/${file}">`).join('') + '</head>')
                .replace('</body>', `<script type="module" src="/build/${manifest['resources/js/app.js'].file}"></script></body>`);
            res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html);
        } else if (/^\/build\/assets\/[\w.-]+$/.test(pathname)) {
            res.setHeader('Content-Type', pathname.endsWith('.js') ? 'application/javascript' : pathname.endsWith('.css') ? 'text/css' : 'image/png');
            res.end(await readFile(path.join(build, pathname.slice(7))));
        } else { res.statusCode = 404; res.end(); }
    } catch (error) { res.statusCode = 500; res.end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
origin = 'http://127.0.0.1:' + server.address().port;
try {
    browser = await chromium.launch({ channel: 'msedge', headless: true });
    for (const width of [320, 390, 768, 1280]) {
        let dataMode = 'empty', releaseData, started;
        let areaAppearance;
        const requests = [];
        const point = { type: 'Feature', id: '11111111-1111-4111-8111-111111111111', geometry: { type: 'Point', coordinates: [123.8, 10.3] },
            properties: { title: 'Synthetic lagoon', address: 'Synthetic address', location_type: 'project_point', site_type_id: 1,
                site_type_code: 'fish_cage', site_type_name: 'Fish Cage', is_published: true, archived_at: null, version: 'synthetic-version' } };
        const boundary = { ...point, id: '22222222-2222-4222-8222-222222222222', geometry: { type: 'Polygon', coordinates: [[[123.81, 10.31], [123.82, 10.31], [123.82, 10.32], [123.81, 10.31]]] },
            properties: { ...point.properties, title: 'Synthetic boundary', location_type: 'project_boundary' } };
        const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.origin !== origin) return route.abort();
            if (url.pathname.endsWith('/data')) {
                requests.push(url);
                if (dataMode === 'error') return route.fulfill({ status: 503, json: { message: 'Synthetic records are temporarily unavailable.' } });
                if (dataMode === 'hold') await new Promise(resolve => { releaseData = resolve; started(); });
                const features = dataMode === 'records' ? [point, boundary].filter(feature => (!url.searchParams.get('q') || feature.properties.title.toLowerCase().includes(url.searchParams.get('q').toLowerCase()))
                    && (!url.searchParams.get('location_type') || feature.properties.location_type === url.searchParams.get('location_type'))) : [];
                return route.fulfill({ json: { type: 'FeatureCollection', features, meta: { page: 1, last_page: 1, total: features.length, bounds: features.length ? [123.8, 10.3, 123.82, 10.32] : null, empty_reason: 'no_records' } } });
            }
            if (url.pathname.endsWith('/photos')) return route.fulfill({ json: { photos: [], pending: [], used_slots: 0, limit: 5, can_manage: false } });
            if (/\/(parents|associations)$/.test(url.pathname)) return route.fulfill({ json: { options: [{ value: '11111111-1111-4111-8111-111111111111', label: 'Synthetic association', address_info: { address: 'Synthetic area', context: 'Synthetic municipality' } }], truncated: false } });
            return route.continue();
        });
        for (const view of ['home', 'login', 'staff', 'officer', 'association', 'public', 'controls', 'editor', 'areas', 'members']) {
            dataMode = view === 'association' ? 'records' : 'empty';
            await page.goto(origin + '/' + view);
            await page.mouse.move(0, 0);
            if (view === 'editor') await page.waitForFunction(() => document.querySelector('[data-gis-editor]')?.dataset.gisInitialized === 'true');
            else if (['staff', 'officer', 'association', 'public'].includes(view)) await page.waitForFunction(() => document.querySelector('[data-gis-workspace]')?.dataset.gisInitialized === 'true' && document.querySelector('[data-gis-workspace]').getAttribute('aria-busy') !== 'true');
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
            observations.push({ view, width, overflow });
            if (['staff', 'officer', 'association', 'areas', 'members'].includes(view)) observations.push({ view: view + '-sidebar', width,
                closeVisible: await page.locator('[data-close-workspace-sidebar]').isVisible() });
            if (phase === 'after') check(!overflow, `${view} has no document overflow at ${width}`);
            if (phase === 'after' && ['staff', 'officer', 'association', 'public'].includes(view)) {
                const search = page.locator('.gis-search input');
                for (const state of ['normal', 'focus', 'populated', 'disabled']) {
                    await search.evaluate((node, state) => { node.disabled = state === 'disabled'; node.value = state === 'populated' ? 'Synthetic typed query' : ''; if (state === 'focus') node.focus(); else node.blur(); }, state);
                    check(await search.evaluate(node => {
                        const icon = node.parentElement.querySelector('.icon').getBoundingClientRect(), field = node.getBoundingClientRect();
                        return field.left + parseFloat(getComputedStyle(node).paddingLeft) >= icon.right + 6 && Math.abs((icon.top + icon.height / 2) - (field.top + field.height / 2)) < 3;
                    }), `${view} search text clears its icon in ${state} state at ${width}`);
                }
                await search.evaluate(node => { node.disabled = false; node.value = ''; });
                const active = page.locator('[data-gis-view][aria-pressed=true]');
                check(await active.evaluate(node => getComputedStyle(node).backgroundColor === 'rgb(11, 76, 140)'), 'GIS selected view uses primary blue');
                check(await active.evaluate(node => getComputedStyle(node).color === 'rgb(255, 255, 255)'), 'GIS selected view uses white text');
                await active.hover();
                check(await active.evaluate(node => getComputedStyle(node).backgroundColor === 'rgb(8, 59, 112)'), 'GIS active hover uses primary hover blue');
                await active.focus();
                await page.keyboard.press('Tab'); await page.keyboard.press('Shift+Tab');
                check(await active.evaluate(node => getComputedStyle(node).outlineStyle !== 'none'), 'GIS view has a keyboard focus indicator');
                await active.evaluate(node => { node.disabled = true; });
                check(await active.evaluate(node => getComputedStyle(node).backgroundColor === 'rgb(11, 76, 140)' && parseFloat(getComputedStyle(node).opacity) < 1), 'GIS disabled active style retains selected state');
                await active.evaluate(node => { node.disabled = false; node.blur(); });
                await page.mouse.move(0, 0);
            }
            await page.screenshot({ path: path.join(screenshots, `${view}-${width}.png`), fullPage: true });
            if (phase === 'after' && ['staff', 'officer', 'association', 'controls', 'areas', 'members'].includes(view)) {
                const sidebar = page.locator('[data-workspace-sidebar]');
                check(!await page.locator('[data-close-workspace-sidebar]').isVisible(), 'Close navigation is hidden outside an open mobile drawer');
                check(await page.locator('.app-footer > span').count() === 1, 'page footer retains system information without redundant tagline');
                if (width <= 1020) {
                    await page.locator('.mobile-menu').click();
                    check(await page.locator('[data-close-workspace-sidebar]').isVisible(), 'mobile drawer exposes Close navigation');
                    check(await sidebar.evaluate(node => !node.inert) && await page.locator('.main-shell').evaluate(node => node.inert), 'drawer isolates background');
                    await page.keyboard.press('Shift+Tab');
                    check(await sidebar.evaluate(node => node.contains(document.activeElement)), 'drawer traps reverse Tab');
                    await page.keyboard.press('Escape');
                    check(await page.locator('.mobile-menu').evaluate(node => document.activeElement === node), 'Escape restores navigation focus');
                    await page.locator('.mobile-menu').click();
                    await page.locator('[data-close-workspace-sidebar]').click();
                    check(await page.locator('.mobile-menu').evaluate(node => document.activeElement === node), 'visible Close navigation restores focus');
                } else {
                    check(await page.locator('[data-close-workspace-sidebar]').evaluate(node => node.hidden), 'desktop Close navigation is explicitly hidden');
                    await page.locator('[data-collapse-sidebar]').click();
                    check(await page.locator('body').evaluate(node => node.classList.contains('sidebar-collapsed')), 'desktop collapse works');
                    await page.locator('[data-collapse-sidebar]').click();
                }
                check(await sidebar.locator('[aria-current="page"]').count() === 1, 'one current navigation item');
            }
            if (phase === 'after' && view === 'association') {
                const idle = () => page.waitForFunction(() => !document.querySelector('[data-gis-workspace]').hasAttribute('aria-busy'));
                const list = page.locator('[data-gis-view=list]'), map = page.locator('[data-gis-view=map]');
                await page.locator('.gis-site-marker').focus(); await page.keyboard.press('Enter');
                check(await page.locator('[data-gis-selection-title]').textContent() === point.properties.title, 'keyboard selection opens original location');
                check(await page.locator('.gis-site-marker svg > path').getAttribute('fill') === '#2563eb', 'classification marker color remains unchanged');
                await list.click(); await idle();
                check(await list.getAttribute('aria-pressed') === 'true' && await page.locator('.gis-record-card').count() === 2, 'Location list shows both existing records');
                await page.locator('.gis-record-card').filter({ hasText: point.properties.title }).getByRole('button', { name: 'View location', exact: true }).click();
                check(await map.getAttribute('aria-pressed') === 'true' && await page.locator('[data-gis-selection-title]').textContent() === point.properties.title, 'list selection returns to map with the same location');
                const search = page.locator('#gis-search');
                const searchResponse = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname.endsWith('/data') && url.searchParams.get('q') === 'lagoon'; });
                await search.fill('lagoon'); await searchResponse; await idle();
                check(requests.at(-1).searchParams.get('q') === 'lagoon', 'typing sends unchanged GIS search parameter');
                await list.click(); await idle();
                check(await page.locator('.gis-record-card').count() === 1 && await page.locator('.gis-record-card').textContent().then(text => text.includes(point.properties.title)), 'search narrows the location list');
                await page.locator('.gis-filters summary').click();
                await page.locator('[name=location_type]').selectOption('project_boundary'); await idle();
                await page.locator('[data-gis-filters] button[type=submit]').click(); await idle();
                check(requests.at(-1).searchParams.get('q') === 'lagoon' && requests.at(-1).searchParams.get('location_type') === 'project_boundary' && await page.locator('.gis-record-card').count() === 0, 'Apply Filters combines search and geometry filter');
                await page.locator('[data-gis-filters] button[type=reset]').click();
                await page.waitForFunction(() => document.querySelectorAll('.gis-record-card').length === 2); await idle();
                check(await search.inputValue() === '' && await page.locator('[name=location_type]').inputValue() === '', 'Reset clears search and filters');
                await map.click(); await idle();
                check(await map.getAttribute('aria-pressed') === 'true' && await page.locator('.gis-site-marker').count() === 1, 'map restores original records after Reset');
                await page.screenshot({ path: path.join(screenshots, `gis-controls-${width}.png`), fullPage: true });
            }
            if (phase === 'after' && ['areas', 'members'].includes(view)) {
                await page.mouse.move(0, 0);
                const cards = page.locator('.summary-cards > a');
                const appearance = await cards.locator('.stat-card').evaluateAll(nodes => nodes.map(node => ({ background: getComputedStyle(node).backgroundColor, number: node.querySelector('strong').textContent })));
                check(appearance[0].background === 'rgb(11, 66, 123)' && appearance.slice(1).every(card => card.background === 'rgb(255, 255, 255)'), `${view} first KPI is blue and other KPIs are white at ${width}: ${JSON.stringify(appearance)}`);
                const first = await cards.first().locator('.stat-card').evaluate(cardAppearance);
                check(first.numberColor === 'rgb(255, 255, 255)' && first.noteColor === 'rgb(255, 255, 255)' && first.iconColor === 'rgb(255, 255, 255)', 'selected KPI foreground and icon are white');
                check(contrast(first.numberColor, first.background) >= 4.5 && contrast(first.noteColor, first.background) >= 4.5, 'selected KPI text contrast passes 4.5:1');
                for (const card of await cards.all()) {
                    await card.focus(); await page.keyboard.press('Tab'); await page.keyboard.press('Shift+Tab');
                    check(await card.evaluate(node => document.activeElement === node && getComputedStyle(node).outlineStyle !== 'none'), 'KPI links retain visible keyboard focus');
                }
                await cards.first().hover();
                check(await cards.first().locator('.stat-card').evaluate(node => getComputedStyle(node).backgroundColor === 'rgb(11, 66, 123)'), 'selected KPI remains blue on hover');
                if (view === 'areas') {
                    check(appearance.map(card => card.number.trim()).join(',') === '4,4,3,1', 'Area KPI values are unchanged');
                    areaAppearance = first;
                    await page.locator('[data-area-summary=current_barangays]').click();
                    check(await page.locator('[data-area-summary=current_barangays]').getAttribute('aria-current') === 'page', 'Area KPI destination retains its filter selection');
                    check(await page.locator('[data-area-summary=current_barangays] .stat-card').evaluate(node => getComputedStyle(node).backgroundColor === 'rgb(11, 66, 123)'), 'Area filtered KPI uses the same selected pattern');
                } else {
                    check(appearance.map(card => card.number.trim()).join(',') === '4,3,1,1', 'Member KPI values are unchanged');
                    assert.deepEqual(first, areaAppearance, 'Area and Member selected KPI presentation matches'); checks++;
                }
            }
            if (phase === 'after' && view === 'home') {
                check(await page.getByText('Capstone Project Prototype', { exact: true }).count() === 0, 'branding has no prototype label');
                check(await page.locator('#about').textContent().then(text => text.includes('Capstone')), 'About preserves academic attribution');
                if (width <= 760) {
                    await page.locator('[data-public-menu-toggle]').click();
                    check(await page.locator('#public-navigation').isVisible(), 'public menu opens');
                    await page.keyboard.press('Escape');
                    check(!await page.locator('#public-navigation').isVisible(), 'public menu closes with Escape');
                }
            }
            if (phase === 'after' && view === 'public') {
                if (width <= 760) await page.locator('[data-public-menu-toggle]').click();
                await page.locator('#public-navigation').getByRole('link', { name: 'About', exact: true }).click();
                await page.waitForURL(origin + '/#about');
                check(await page.locator('#public-about-title').isVisible(), 'About navigates from map to landing page');
            }
            if (phase === 'after' && view === 'staff' && width === 320) {
                dataMode = 'hold';
                const requestStarted = new Promise(resolve => { started = resolve; });
                await page.locator('[data-gis-reload]').click(); await requestStarted;
                check(await page.locator('[data-gis-workspace]').getAttribute('aria-busy') === 'true', 'loading is announced while records are pending');
                await page.screenshot({ path: path.join(screenshots, 'loading-320.png') });
                dataMode = 'empty'; releaseData();
                await page.waitForFunction(() => !document.querySelector('[data-gis-workspace]').hasAttribute('aria-busy'));
                dataMode = 'error'; await page.locator('[data-gis-reload]').click();
                await page.locator('[data-global-feedback][open]').waitFor();
                check(await page.locator('[data-global-feedback]').textContent().then(text => text.toLowerCase().includes('unavailable')), 'failed reload provides error feedback');
                await page.screenshot({ path: path.join(screenshots, 'error-320.png') });
                await page.locator('[data-feedback-acknowledge]').click();
                dataMode = 'empty'; await page.locator('[data-gis-reload]').click();
                await page.waitForFunction(() => !document.querySelector('[data-gis-workspace]').hasAttribute('aria-busy'));
                check(!await page.locator('[data-global-feedback]').evaluate(node => node.open), 'reload recovers to the empty state');
            }
            if (phase === 'after' && view === 'login') {
                await page.locator('#staff-password-toggle').click();
                check(await page.locator('#staff-password').getAttribute('type') === 'text', 'password visibility toggle works');
                await page.locator('#staff-password-toggle').click();
                check(await page.locator('#staff-password').getAttribute('type') === 'password', 'password visibility restored');
            }
            if (phase === 'after' && view === 'controls') {
                check(await page.locator('.page-header-actions .button').isVisible(), 'header action available on mobile');
                check(await page.locator('.panel-heading .badge').isVisible(), 'status available on mobile');
                await page.locator('#example-form button[type=submit]').click();
                check(await page.locator('#example-name').evaluate(node => node.validity.valueMissing && node.validationMessage.length > 0), 'native required-field feedback remains available');
                await page.getByRole('button', { name: 'View details', exact: true }).click();
                const modal = page.locator('#review-dialog');
                check(await modal.evaluate(node => node.open && node.scrollHeight > node.clientHeight), 'long dialog scrolls');
                await modal.getByRole('button', { name: 'Continue', exact: true }).scrollIntoViewIfNeeded();
                await page.screenshot({ path: path.join(screenshots, `dialog-${width}.png`) });
                await page.keyboard.press('Escape');
                check(await page.getByRole('button', { name: 'View details', exact: true }).evaluate(node => document.activeElement === node), 'dialog restores opener focus');
            }
            if (view === 'editor') {
                await page.locator('[name=location_type]').selectOption('project_boundary');
                await page.locator('.leaflet-pm-icon-polygon').click();
                const map = page.locator('[data-gis-editor-map]');
                const box = await map.boundingBox();
                // Draw below the toolbar and its stacked actions on narrow maps.
                for (const [x, y] of [[.30, .68], [.80, .68], [.80, .88]]) await map.click({ position: { x: box.width * x, y: box.height * y } });
                const actions = page.locator('.button-container.active .leaflet-pm-actions-container');
                const rects = await actions.locator('a').evaluateAll(nodes => nodes.map(node => {
                    const r = node.getBoundingClientRect(), map = node.closest('.leaflet-container').getBoundingClientRect();
                    return { text: node.textContent, visible: r.width > 0, fits: r.left >= map.left && r.right <= map.right, width: r.width, height: r.height };
                }));
                observations.push({ view: 'geoman-actions', width, rects });
                await page.screenshot({ path: path.join(screenshots, `geoman-${width}.png`), fullPage: true });
                if (phase === 'after') {
                    if (rects.length !== 3 || !rects.every(r => r.visible && r.fits && r.height >= 40)) console.log({ rects, box, geometry: await page.locator('[name=geometry]').inputValue(), actions: await page.locator('.leaflet-pm-toolbar').evaluateAll(nodes => nodes.map(node => node.outerHTML)) });
                    check(rects.length === 3 && rects.every(r => r.visible && r.fits && r.height >= 40), 'all drawing actions fit map at ' + width);
                    await actions.locator('.action-removeLastVertex').click();
                    await map.click({ position: { x: box.width * .80, y: box.height * .88 } });
                    await actions.locator('.action-finish').click();
                    const geometry = JSON.parse(await page.locator('[name=geometry]').inputValue());
                    check(geometry.type === 'Polygon' && geometry.coordinates[0].length === 4, 'Finish saves drawn polygon');
                    await page.locator('.leaflet-pm-icon-polygon').click();
                    await page.locator('.button-container.active .action-cancel').click();
                    check(JSON.parse(await page.locator('[name=geometry]').inputValue()).type === 'Polygon', 'Cancel preserves completed boundary');
                    await page.locator('[data-gis-clear]').click();
                    await page.locator('[name=location_type]').selectOption('project_point');
                    await map.click({ position: { x: box.width * .55, y: box.height * .55 } });
                    check(JSON.parse(await page.locator('[name=geometry]').inputValue()).type === 'Point', 'point operation remains usable');
                }
            }
        }
        await page.close();
    }
    check(errors.length === 0, 'no browser JavaScript errors: ' + errors.join('; '));
    await writeFile(path.join(directory, phase + '-results.json'), JSON.stringify({ checks, observations, errors, boundary: 'Synthetic Blade fixtures, production bundles, headless Edge; external requests blocked' }, null, 2));
    console.log(`${phase}: ${checks} checks passed; screenshots and results in ${screenshots}`);
} finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
