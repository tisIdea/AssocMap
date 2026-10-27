// Synthetic component verification. No deployed app or external API is used.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [directory, playwrightPackage] = process.argv.slice(2);
assert.match(path.basename(directory || ''), /^assocmap-projectmedia-ui-[a-f0-9]{32}$/);
const { chromium } = await import(pathToFileURL(path.join(playwrightPackage, 'index.mjs')));
const web = path.resolve(import.meta.dirname, '../..');
const manifest = JSON.parse(await readFile(path.join(web, 'public/build/manifest.json'), 'utf8'));
const css = manifest['resources/css/app.css'].file;
const png = await readFile(path.join(directory, 'photo.png'));
const modules = { '/pages/project-cover.js': 'resources/js/pages/project-cover.js',
    '/components/photo-input.js': 'resources/js/components/photo-input.js' };
let origin;
const server = createServer(async (req, res) => {
    try {
        const pathname = new URL(req.url, origin).pathname;
        if (['/cover', '/readonly'].includes(pathname)) {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end((await readFile(path.join(directory, pathname.slice(1) + '.html'), 'utf8')).replaceAll('http://localhost', origin));
        } else if (modules[pathname]) {
            res.setHeader('Content-Type', 'application/javascript');
            res.end(await readFile(path.join(web, modules[pathname])));
        } else if (pathname === '/build/' + css) {
            res.setHeader('Content-Type', 'text/css');
            res.end(await readFile(path.join(web, 'public/build', css)));
        } else { res.statusCode = 404; res.end(); }
    } catch { res.statusCode = 500; res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
origin = 'http://127.0.0.1:' + server.address().port;
let browser;
const results = [];
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
try {
    browser = await chromium.launch({ channel: 'msedge', headless: true });
    for (const width of [1280, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        let cover = null;
        let pending = [];
        let uploadPending = false;
        let canManage = true;
        let serial = 0;
        let imageReads = 0;
        let fail = false;
        let hold = false;
        let release;
        const posts = [];
        const errors = [];
        const newCover = id => ({ id, display_url: origin + '/admin/projects/project/cover/' + id + '/display',
            thumbnail_url: origin + '/admin/projects/project/cover/' + id + '/thumbnail' });
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin !== origin) return route.abort();
            if (!url.pathname.includes('/projects/')) return route.continue();
            const parts = url.pathname.split('/');
            const last = parts.at(-1);
            if (['thumbnail', 'display'].includes(last)) {
                check(last === 'display', 'detail uses only optimized display images');
                imageReads++;
                return route.fulfill({ contentType: 'image/png', body: png });
            }
            if (request.method() === 'POST' && last !== 'reconcile') {
                const body = request.postData();
                const token = body?.match(/name="request_id"\r\n\r\n([^\r]+)/)?.[1];
                const replaces = body?.match(/name="replaces"\r\n\r\n([^\r]+)/)?.[1] || null;
                posts.push({ token, replaces });
                check(Boolean(token), 'upload sends a stable request UUID');
                check(request.headers()['x-csrf-token'] === 'synthetic-cover-csrf', 'upload sends component CSRF header without relying on a layout meta tag');
                check(!body.includes('association_id') && !body.includes('program_year'), 'cover request excludes Project form data');
                if (hold) await new Promise(resolve => { release = resolve; });
                if (fail) return route.fulfill({ status: 503, json: { message: 'Synthetic storage timeout. Refresh before retrying.' } });
                if (replaces !== (cover?.id || null)) return route.fulfill({ status: 409, json: {
                    message: 'The cover changed. Refresh, then choose the photo again to replace the current cover.' } });
                cover = newCover('cover-' + (++serial));
                return route.fulfill({ json: { photo: { id: cover.id, status: 'ready' } } });
            }
            if (request.method() === 'DELETE') {
                if (last === cover?.id) cover = null;
                pending = pending.filter(photo => photo.id !== last);
                uploadPending = false;
                return route.fulfill({ json: { status: 'deleted' } });
            }
            if (last === 'reconcile') {
                cover = newCover('recovered-' + (++serial));
                pending = [];
                uploadPending = false;
                return route.fulfill({ json: { status: 'ready' } });
            }
            return route.fulfill({ json: { cover, pending, upload_pending: uploadPending, can_manage: canManage } });
        });
        await page.goto(origin + '/cover');
        await page.addStyleTag({ url: origin + '/build/' + css });
        await page.addStyleTag({ content: 'main { width: min(100% - 32px, 960px); margin: 20px auto; min-height: 0; }' });
        await page.addScriptTag({ type: 'module', content: "import {initProjectCovers} from '/pages/project-cover.js'; initProjectCovers();" });
        const root = page.locator('[data-project-cover]');
        const input = root.locator('input[type=file]');
        const submit = root.locator('[data-cover-upload] button');
        const status = root.locator('[data-cover-status]');
        const refresh = async () => {
            await root.locator('[data-cover-refresh]').click();
            await page.waitForFunction(() => document.querySelector('[data-project-cover]').getAttribute('aria-busy') === 'false');
        };
        await status.filter({ hasText: 'Cover updated.' }).waitFor();
        check(await root.locator('[data-cover-empty]').isVisible(), 'clean empty-cover state');
        check(imageReads === 0, 'empty cover causes no object read');
        await input.setInputFiles({ name: 'invalid.gif', mimeType: 'image/gif', buffer: png });
        check((await status.textContent()).includes('JPEG or PNG'), 'helpful format validation');
        await input.setInputFiles({ name: 'large.png', mimeType: 'image/png', buffer: Buffer.alloc(5 * 1024 * 1024 + 1) });
        check((await status.textContent()).includes('5 MiB'), 'helpful exact size validation');
        await input.setInputFiles({ name: 'cover.png', mimeType: 'image/png', buffer: png });
        check(await root.locator('[data-cover-preview]').isVisible(), 'local cover preview');
        hold = true;
        await submit.click();
        await page.waitForFunction(() => document.querySelector('[data-project-cover]').getAttribute('aria-busy') === 'true');
        check(await input.isDisabled() && await submit.isDisabled(), 'busy state prevents duplicate submissions');
        await page.waitForFunction(() => document.querySelector('[data-cover-status]').textContent.includes('Saving'));
        for (let attempt = 0; !release && attempt < 250; attempt++) await page.waitForTimeout(20);
        assert.ok(release, 'Upload reached the synthetic server.');
        hold = false;
        release();
        await status.filter({ hasText: 'Cover saved.' }).waitFor();
        await page.waitForFunction(() => document.querySelector('[data-cover-display]').naturalWidth > 0);
        const first = cover.id;
        check(posts[0].replaces === null, 'initial upload has no replacement target');
        check(await submit.textContent() === 'Replace cover', 'replacement control follows initial upload');
        check(imageReads === 1, 'one optimized detail read');
        await refresh();
        check(imageReads === 1, 'metadata refresh reuses unchanged image');
        await input.setInputFiles({ name: 'replacement.png', mimeType: 'image/png', buffer: png });
        hold = true;
        release = null;
        await submit.click();
        await page.waitForFunction(() => document.querySelector('[data-project-cover]').getAttribute('aria-busy') === 'true');
        check((await root.locator('[data-cover-display]').getAttribute('src')).includes(first), 'original remains displayed while replacing');
        for (let attempt = 0; !release && attempt < 250; attempt++) await page.waitForTimeout(20);
        assert.ok(release, 'Upload reached the synthetic server.');
        hold = false;
        release();
        await status.filter({ hasText: 'Cover saved.' }).waitFor();
        check(posts.at(-1).replaces === first && cover.id !== first, 'successful replacement targets the previous cover');
        await page.waitForFunction(() => document.querySelector('[data-cover-display]').naturalWidth > 0);
        const retained = cover.id;
        fail = true;
        await input.setInputFiles({ name: 'retry.png', mimeType: 'image/png', buffer: png });
        await submit.click();
        await status.filter({ hasText: 'Synthetic storage timeout.' }).waitFor();
        check((await root.locator('[data-cover-display]').getAttribute('src')).includes(retained), 'failure preserves current cover');
        check(await input.evaluate(node => node.files.length === 1), 'failure preserves selected file');
        fail = false;
        await submit.click();
        await status.filter({ hasText: 'Cover saved.' }).waitFor();
        check(posts.at(-1).token === posts.at(-2).token && posts.at(-1).replaces === posts.at(-2).replaces, 'retry preserves UUID and original replacement target');
        await input.setInputFiles({ name: 'stale.png', mimeType: 'image/png', buffer: png });
        const intended = cover.id;
        cover = newCover('another-tab');
        await refresh();
        await submit.click();
        await status.filter({ hasText: 'The cover changed.' }).waitFor();
        check(posts.at(-1).replaces === intended && cover.id === 'another-tab', 'stale tab cannot silently target a newer cover');
        await input.setInputFiles({ name: 'replacement-again.png', mimeType: 'image/png', buffer: png });
        await submit.click();
        await status.filter({ hasText: 'Cover saved.' }).waitFor();
        pending = [{ id: 'pending', status: 'uncertain', retry_after: 60 }];
        uploadPending = true;
        await refresh();
        check(await input.isDisabled() && await root.locator('[data-cover-remove]').isDisabled(), 'pending replacement excludes new uploads and current-cover removal in UI');
        check((await root.locator('[data-cover-pending]').textContent()).includes('current cover is retained'), 'pending replacement explains original-cover retention');
        await page.screenshot({ path: path.join(directory, 'cover-' + width + '-active.png'), fullPage: true });
        pending[0].retry_after = 0;
        await refresh();
        await root.getByRole('button', { name: 'Check saved state', exact: true }).click();
        await status.filter({ hasText: 'Saved state checked.' }).waitFor();
        check(!(await input.isDisabled()), 'recovery reenables cover controls');
        await root.locator('[data-cover-remove]').click();
        check(await root.locator('[data-cover-cancel]').evaluate(node => node === document.activeElement), 'confirmation focuses safe action');
        await page.keyboard.press('Escape');
        check(await root.locator('[data-cover-remove]').evaluate(node => node === document.activeElement), 'Escape restores opener focus');
        check(cover !== null, 'cancelled removal preserves cover');
        await root.locator('[data-cover-remove]').click();
        await root.locator('[data-cover-confirm-remove]').click();
        await status.filter({ hasText: 'Photo withdrawn.' }).waitFor();
        check(await root.locator('[data-cover-empty]').isVisible(), 'confirmed removal returns empty state');
        canManage = false;
        await refresh();
        check(!(await root.locator('[data-cover-upload]').isVisible()), 'changed lifecycle hides mutation form');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'responsive component has no horizontal overflow');
        check(errors.length === 0, 'browser script errors: ' + errors.join(','));
        await page.screenshot({ path: path.join(directory, 'cover-' + width + '.png'), fullPage: true });
        results.push({ width, imageReads, posts: posts.length });
        await page.close();
    }
    const report = { checks, results };
    await writeFile(path.join(directory, 'browser-results.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
} finally {
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
}
