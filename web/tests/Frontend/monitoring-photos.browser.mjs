// Explicit synthetic UI run: node this-file <exported-fixture-directory> <temporary-playwright-package>.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [directory, playwrightPackage] = process.argv.slice(2);
assert.match(path.basename(directory || ''), /^assocmap-media1-ui-[a-f0-9]{32}$/);
const { chromium } = await import(pathToFileURL(path.join(playwrightPackage, 'index.mjs')));
const web = path.resolve(import.meta.dirname, '../..');
const manifest = JSON.parse(await readFile(path.join(web, 'public/build/manifest.json'), 'utf8'));
const css = manifest['resources/css/app.css'].file;
const png = await readFile(path.join(directory, 'photo.png'));
let origin;
const server = createServer(async (req, res) => {
    try {
        const pathname = new URL(req.url, origin).pathname;
        if (['/visits', '/materials'].includes(pathname)) {
            const html = (await readFile(path.join(directory, pathname.slice(1) + '.html'), 'utf8')).replaceAll('http://localhost', origin);
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end(html);
        } else if (pathname === '/gallery.js') {
            res.setHeader('Content-Type', 'application/javascript');
            res.end(await readFile(path.join(web, 'resources/js/pages/monitoring-photos.js')));
        } else if (pathname === '/' + 'build/' + css) {
            res.setHeader('Content-Type', 'text/css');
            res.end(await readFile(path.join(web, 'public', 'build', css)));
        } else { res.statusCode = 404; res.end(); }
    } catch { res.statusCode = 500; res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
origin = 'http://127.0.0.1:' + server.address().port;
let browser;
const results = [];
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
try {
    browser = await chromium.launch({ channel: 'msedge', headless: true });
    for (const [kind, width, height] of [['visits', 1280, 900], ['materials', 390, 844]]) {
        const page = await browser.newPage({ viewport: { width, height } });
        let photos = [];
        let pending = [];
        let used = 0;
        let serial = 1;
        let apiCalls = 0;
        let thumbReads = 0;
        let displayReads = 0;
        let failUpload = false;
        let release;
        let holdUpload = false;
        const requestIds = [];
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin !== origin) return route.abort();
            if (!url.pathname.includes('/photos/')) return route.continue();
            apiCalls++;
            const endpoint = url.pathname.split('/').slice(0, 7).join('/');
            const last = url.pathname.split('/').at(-1);
            if (['thumbnail', 'display'].includes(last)) {
                if (last === 'thumbnail') thumbReads++; else displayReads++;
                return route.fulfill({ contentType: 'image/png', body: png });
            }
            if (request.method() === 'POST' && last !== 'reconcile') {
                const token = request.postData()?.match(/name="request_id"\r\n\r\n([^\r]+)/)?.[1];
                requestIds.push(token);
                check(Boolean(token), kind + ': upload includes an idempotency UUID');
                check(!request.postData().includes('materials_revision') && !request.postData().includes('monitoring_date'), kind + ': upload excludes Monitoring drafts');
                if (holdUpload) await new Promise(resolve => { release = resolve; });
                if (failUpload) return route.fulfill({ status: 503, json: { message: 'Synthetic storage timeout. Refresh before retrying.' } });
                const id = '99999999-9999-4999-8999-' + String(serial++).padStart(12, '0');
                photos.push({ id, thumbnail_width: 64, thumbnail_height: 32,
                    thumbnail_url: origin + endpoint + '/' + id + '/thumbnail', display_url: origin + endpoint + '/' + id + '/display' });
                used++;
                return route.fulfill({ json: { photo: { id, status: 'ready' } } });
            }
            if (request.method() === 'DELETE') {
                photos = photos.filter(photo => photo.id !== last);
                used--;
                return route.fulfill({ json: { status: 'deleted' } });
            }
            if (request.method() === 'POST' && last === 'reconcile') {
                pending = [];
                used = photos.length;
                return route.fulfill({ json: { status: 'deleted' } });
            }
            return route.fulfill({ json: { photos, pending, used_slots: used, limit: 5 } });
        });
        await page.goto(origin + '/' + kind);
        await page.addStyleTag({ url: origin + '/build/' + css });
        await page.addScriptTag({ type: 'module', content: "import {initMonitoringPhotos} from '/gallery.js'; initMonitoringPhotos();" });
        const root = page.locator('[data-monitoring-photos]').first();
        await page.waitForFunction(() => document.querySelector('[data-monitoring-photos]')?.dataset.initialized);
        check(apiCalls === 0, kind + ': collapsed galleries make no storage/API requests');
        await root.locator('summary').click();
        await root.locator('[data-photo-status]').filter({ hasText: 'No photos saved yet.' }).waitFor();
        const input = root.locator('input[type=file]');
        await input.setInputFiles({ name: 'bad.gif', mimeType: 'image/gif', buffer: png });
        check((await root.locator('[data-photo-status]').textContent()).includes('JPEG or PNG'), kind + ': format feedback');
        await input.setInputFiles({ name: 'large.png', mimeType: 'image/png', buffer: Buffer.alloc(5 * 1024 * 1024 + 1) });
        check((await root.locator('[data-photo-status]').textContent()).includes('5 MiB'), kind + ': size feedback');
        await input.setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: png });
        check(await root.locator('[data-photo-preview]').isVisible(), kind + ': local image preview');
        holdUpload = true;
        await root.locator('[data-photo-upload] button').click();
        await page.waitForFunction(() => document.querySelector('[data-monitoring-photos]').getAttribute('aria-busy') === 'true');
        check(await input.isDisabled(), kind + ': busy state blocks duplicate selection/submission');
        await page.waitForTimeout(50);
        assert.ok(release);
        holdUpload = false;
        release();
        await root.locator('[data-photo-count]').filter({ hasText: '1 / 5' }).waitFor();
        await root.locator('[data-photo-status]').filter({ hasText: 'Photo uploaded.' }).waitFor();
        await root.locator('[data-photo-grid] img').first().waitFor();
        await page.waitForFunction(() => document.querySelector('[data-photo-grid] img')?.complete);
        check(thumbReads === 1 && displayReads === 0, kind + ': gallery loads only thumbnails');
        await root.getByRole('button', { name: 'View photo 1', exact: true }).click();
        await root.locator('[data-photo-viewer][open]').waitFor();
        await page.waitForFunction(() => document.querySelector('[data-photo-display]')?.complete);
        check(displayReads === 1, kind + ': display variant fetched only on demand');
        await page.keyboard.press('Escape');
        check(await root.getByRole('button', { name: 'View photo 1', exact: true }).evaluate(node => node === document.activeElement), kind + ': Escape restores opener focus');
        await root.locator('[data-photo-refresh]').click();
        await root.locator('[data-photo-status]').filter({ hasText: 'Gallery updated.' }).waitFor();
        check(thumbReads === 1, kind + ': refresh reuses unchanged thumbnail nodes');
        await root.getByRole('button', { name: 'Remove photo 1', exact: true }).click();
        check(await root.locator('[data-photo-cancel]').evaluate(node => node === document.activeElement), kind + ': confirmation focuses safe action');
        await root.locator('[data-photo-cancel]').click();
        check(photos.length === 1, kind + ': cancelled removal retains photo');
        await root.getByRole('button', { name: 'Remove photo 1', exact: true }).click();
        await root.locator('[data-photo-remove]').click();
        await root.locator('[data-photo-count]').filter({ hasText: '0 / 5' }).waitFor();
        failUpload = true;
        await input.setInputFiles({ name: 'retry.png', mimeType: 'image/png', buffer: png });
        await root.locator('[data-photo-upload] button').click();
        await root.locator('[data-photo-status]').filter({ hasText: 'Synthetic storage timeout.' }).waitFor();
        check((await input.evaluate(node => node.files.length)) === 1, kind + ': unknown outcome retains selected file');
        failUpload = false;
        await root.locator('[data-photo-upload] button').click();
        await root.locator('[data-photo-status]').filter({ hasText: 'Photo uploaded.' }).waitFor();
        check(requestIds.at(-1) === requestIds.at(-2), kind + ': retry preserves original idempotency UUID');
        used = 5;
        pending = [{ id: '99999999-9999-4999-8999-999999999999', status: 'uncertain', retry_after: 0 }];
        await root.locator('[data-photo-refresh]').click();
        await root.locator('[data-photo-count]').filter({ hasText: '5 / 5' }).waitFor();
        check(await input.isDisabled(), kind + ': reservations enforce the five-slot indicator');
        await root.getByRole('button', { name: 'Check saved state', exact: true }).click();
        await root.locator('[data-photo-count]').filter({ hasText: '1 / 5' }).waitFor();
        check(!(await input.isDisabled()), kind + ': recovery releases an abandoned slot');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), kind + ': responsive page has no horizontal overflow');
        check(errors.length === 0, kind + ': no browser script errors: ' + errors.join(','));
        await page.screenshot({ path: path.join(directory, kind + '-' + width + '.png'), fullPage: true });
        results.push({ kind, width, apiCalls, thumbReads, displayReads });
        await page.close();
    }
    const report = { checks, results };
    await writeFile(path.join(directory, 'browser-results.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
} finally {
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
}
