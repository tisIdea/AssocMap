// Synthetic browser component checks using the real Blade markup and JavaScript.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [directory, playwrightPackage] = process.argv.slice(2);
assert.match(path.basename(directory || ''), /^assocmap-gismedia-ui-[a-f0-9]{32}$/);
const { chromium } = await import(pathToFileURL(path.join(playwrightPackage, 'index.mjs')));
const web = path.resolve(import.meta.dirname, '../..');
const manifest = JSON.parse(await readFile(path.join(web, 'public/build/manifest.json'), 'utf8'));
const css = manifest['resources/css/app.css'].file;
const png = await readFile(path.join(directory, 'photo.png'));
let origin;
const modules = { '/pages/gis-photos.js': 'resources/js/pages/gis-photos.js', '/components/photo-input.js': 'resources/js/components/photo-input.js' };
const server = createServer(async (req, res) => {
    const pathname = new URL(req.url, origin).pathname;
    try {
        if (['/staff', '/public'].includes(pathname)) {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/' + css + '"></head><body><main>'
                + await readFile(path.join(directory, pathname.slice(1) + '.html'), 'utf8') + '</main></body></html>');
        } else if (modules[pathname]) {
            res.setHeader('Content-Type', 'application/javascript'); res.end(await readFile(path.join(web, modules[pathname])));
        } else if (pathname === '/build/' + css) {
            res.setHeader('Content-Type', 'text/css'); res.end(await readFile(path.join(web, 'public/build', css)));
        } else { res.statusCode = 404; res.end(); }
    } catch { res.statusCode = 500; res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
origin = 'http://127.0.0.1:' + server.address().port;
let checks = 0, browser;
const check = (condition, label) => { assert.ok(condition, label); checks++; };
try {
    browser = await chromium.launch({ channel: 'msedge', headless: true });
    for (const width of [1280, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        let photos = [], pending = [], slots = 0, canManage = true, fail = false, reads = 0, displays = 0, lists = 0;
        const posts = [], errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const photo = id => ({ id, approved: false, thumbnail_width: 64, thumbnail_height: 32,
            thumbnail_url: origin + '/admin/gis/site/photos/' + id + '/thumbnail', display_url: origin + '/admin/gis/site/photos/' + id + '/display' });
        await page.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (url.origin !== origin) return route.abort();
            if (!url.pathname.includes('/photos')) return route.continue();
            if (/\/(thumbnail|display)$/.test(url.pathname)) {
                reads++; if (url.pathname.endsWith('/display')) displays++;
                return route.fulfill({ contentType: 'image/png', body: png, headers: { 'Cache-Control': 'private, no-store' } });
            }
            if (request.method() !== 'GET') {
                check(request.headers()['x-csrf-token'] === 'synthetic-gis-csrf', 'writes include CSRF');
                const body = request.postData() || '';
                if (url.pathname.endsWith('/approval')) {
                    check(body.includes('PATCH'), 'approval uses method override'); photos[0].approved = !photos[0].approved;
                } else if (url.pathname.endsWith('/order')) photos.reverse();
                else if (url.pathname.endsWith('/reconcile')) { photos.push(photo('recovered')); pending = []; slots = photos.length; }
                else if (request.method() === 'DELETE') { photos = photos.filter(item => !url.pathname.endsWith('/' + item.id)); slots = photos.length; }
                else {
                    const token = body.match(/name="request_id"\r\n\r\n([^\r]+)/)?.[1]; posts.push(token); check(Boolean(token), 'upload carries UUID');
                    if (fail) return route.fulfill({ status: 503, json: { message: 'Upload result uncertain. Refresh before retrying.' } });
                    photos.push(photo('photo-' + posts.length)); slots = photos.length;
                }
                return route.fulfill({ json: { status: 'ready' } });
            }
            lists++;
            return route.fulfill({ json: { photos, pending, used_slots: slots, limit: 5, can_manage: canManage } });
        });
        await page.goto(origin + '/staff');
        await page.addStyleTag({ content: 'main{max-width:420px;margin:16px auto;padding:16px}button{max-width:100%}' });
        await page.addScriptTag({ type: 'module', content: "import {createGisPhotos} from '/pages/gis-photos.js'; window.gallery=createGisPhotos(document.querySelector('[data-gis-photos]'), {base:'/admin/gis',manager:true,csrf:'synthetic-gis-csrf'});" });
        await page.waitForFunction(() => Boolean(window.gallery));
        check(lists === 0 && reads === 0, 'initial map causes no gallery or image request');
        await page.evaluate(() => window.gallery.select('site'));
        const root = page.locator('[data-gis-photos]'), status = root.locator('[data-photo-status]');
        const idle = async () => page.waitForFunction(() => document.querySelector('[data-gis-photos]').getAttribute('aria-busy') === 'false');
        const refresh = async () => { await root.locator('[data-photo-refresh]').click(); await idle(); };
        await idle(); check(lists === 1 && reads === 0, 'selection loads empty gallery lazily');
        await page.evaluate(() => window.gallery.select('site')); check(lists === 1, 'repeated same selection causes no read');
        const input = root.locator('input[type=file]');
        await input.setInputFiles({ name: 'site.gif', mimeType: 'image/gif', buffer: png });
        check((await status.textContent()).includes('JPEG or PNG'), 'invalid format has clear feedback');
        await input.setInputFiles({ name: 'site.png', mimeType: 'image/png', buffer: Buffer.alloc(5242881) });
        check((await status.textContent()).includes('5 MiB'), 'oversize input rejected');
        await input.setInputFiles({ name: 'site.png', mimeType: 'image/png', buffer: png });
        check(await root.locator('[data-photo-preview]').isVisible(), 'local upload preview');
        fail = true; await root.locator('[type=submit]').click(); await idle();
        check((await status.textContent()).includes('uncertain'), 'uncertain upload keeps helpful state');
        fail = false; await root.locator('[type=submit]').click(); await idle();
        check(posts.length === 2 && posts[0] === posts[1], 'retry preserves idempotency UUID');
        check(await root.getByText('Private', { exact: true }).isVisible(), 'upload remains private');
        check(displays === 0, 'gallery does not download display images automatically');
        await root.getByRole('button', { name: 'Approve for public display', exact: true }).click(); await idle();
        check(await root.getByText('Approved for eligible public display', { exact: true }).isVisible(), 'explicit approval state');
        const before = reads; await refresh(); check(reads === before, 'metadata refresh reuses thumbnail element');
        await root.getByRole('button', { name: 'Withdraw approval', exact: true }).click(); await idle();
        check(await root.getByText('Private', { exact: true }).isVisible(), 'withdrawal state');
        const open = root.getByRole('button', { name: 'View photo 1', exact: true });
        await open.focus(); await page.keyboard.press('Enter'); await root.locator('[data-photo-display]').waitFor({ state: 'visible' });
        await page.waitForFunction(() => document.querySelector('[data-photo-display]').complete);
        check(displays === 1, 'display loads only when opened');
        await page.keyboard.press('Escape'); check(await open.evaluate(node => node === document.activeElement), 'Escape restores viewer opener focus');
        await root.getByRole('button', { name: 'Remove photo 1', exact: true }).click();
        check(await root.locator('[data-photo-cancel]').evaluate(node => node === document.activeElement), 'removal initially focuses Cancel');
        await root.locator('[data-photo-cancel]').click(); check(photos.length === 1, 'cancel preserves photo');
        await input.setInputFiles({ name: 'second.png', mimeType: 'image/png', buffer: png });
        await root.locator('[type=submit]').click(); await idle();
        const second = photos[1].id; await root.getByRole('button', { name: 'Move photo 2 earlier', exact: true }).click(); await idle();
        check(photos[0].id === second, 'photo order control works');
        await root.getByRole('button', { name: 'Remove photo 1', exact: true }).click(); await root.locator('[data-photo-remove]').click(); await idle();
        check(photos.length === 1, 'confirmed deletion works');
        pending = [{ id: 'uncertain', status: 'uncertain', retry_after: 0 }]; slots = 5; await refresh();
        check(await input.isDisabled(), 'pending reservations enforce five-slot limit');
        await root.getByRole('button', { name: 'Check saved state', exact: true }).click(); await idle();
        check(pending.length === 0 && photos.length === 2, 'recovery control refreshes saved state');
        await page.screenshot({ path: path.join(directory, 'staff-' + width + '.png'), fullPage: true });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'responsive gallery has no horizontal overflow');
        canManage = false; await refresh(); check(await root.locator('[data-photo-upload]').isHidden(), 'finalized or archived gallery has no upload');
        check(await root.getByRole('button', { name: 'Approve for public display', exact: true }).count() === 0, 'read-only gallery has no approval writes');
        await page.evaluate(() => window.gallery.clear()); check(await root.isHidden(), 'clearing selection removes gallery');
        await page.goto(origin + '/public');
        await page.addScriptTag({ type: 'module', content: "import {createGisPhotos} from '/pages/gis-photos.js'; window.gallery=createGisPhotos(document.querySelector('[data-gis-photos]'), {base:'/map',manager:false,csrf:''}); window.gallery.select('site');" });
        await idle(); check(await root.locator('input[type=file]').count() === 0, 'public markup has no staff upload');
        check(await root.getByRole('button', { name: 'Approve for public display', exact: true }).count() === 0, 'public gallery has no approval controls');
        check(errors.length === 0, 'no browser script errors'); await page.close();
    }
    await writeFile(path.join(directory, 'results.json'), JSON.stringify({ checks, widths: [1280, 390], boundary: 'synthetic Blade/JS component; mocked HTTP/storage' }, null, 2));
    console.log('PASS: ' + checks + ' GIS gallery browser checks at desktop and mobile widths.');
} finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
