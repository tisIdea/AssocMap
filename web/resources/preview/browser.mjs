import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

// Uses the installed Edge browser; all test artifacts stay under resources/preview.
const directory = dirname(fileURLToPath(import.meta.url));
const generated = join(directory, 'generated');
const html = readFileSync(join(generated, 'index.html'), 'utf8');
const checks = readFileSync(join(directory, 'checks.js'), 'utf8');
const source = JSON.stringify(html).replaceAll('<', '\\u003c');
const harness = '<!doctype html><html><head><meta charset="utf-8"><title>AssocMap UI checks</title></head><body><iframe id="preview" title="Admin preview" style="width:1440px;height:1000px;border:0"></iframe><pre id="results">RUNNING</pre><script>const previewSource = ' + source + ';\n' + checks + '</script></body></html>';
writeFileSync(join(generated, 'checks.html'), harness);
mkdirSync(join(generated, 'edge-profile'), { recursive: true });
const executable = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const screenshot = process.argv.includes('--screenshot');
const page = process.argv.find(value => value.startsWith('--page='))?.slice(7) || 'dashboard';
const width = Number(process.argv.find(value => value.startsWith('--width='))?.slice(8) || 1440);
const screenshotName = `${page.replace(/[^a-z0-9-]/g, '-')}-${width}.png`;
const previewUrl = pathToFileURL(join(generated, screenshot ? 'index.html' : 'checks.html'));
if (screenshot) previewUrl.hash = page;
if (screenshot && width < 500) {
    const mobilePreview = `<!doctype html><html><body style="margin:0"><iframe id="preview" title="Mobile preview" style="width:${width}px;height:1100px;border:0"></iframe><script>const frame = document.getElementById('preview'); frame.onload = () => { frame.contentWindow.location.hash = ${JSON.stringify(page)}; }; frame.srcdoc = ${source};</script></body></html>`;
    writeFileSync(join(generated, 'mobile-preview.html'), mobilePreview);
    previewUrl.pathname = pathToFileURL(join(generated, 'mobile-preview.html')).pathname;
}
const args = [
    '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions',
    '--disable-background-networking', '--disable-component-update', '--disable-sync',
    `--window-size=${width},1100`, '--virtual-time-budget=12000',
    `--user-data-dir=${join(generated, 'edge-profile')}`,
    ...(screenshot ? [`--screenshot=${join(generated, screenshotName)}`] : ['--dump-dom']),
    previewUrl.href,
];
const result = spawnSync(executable, args, { encoding: 'utf8', timeout: 45000, maxBuffer: 8 * 1024 * 1024, windowsHide: true });
if (result.error) throw result.error;
if (screenshot) {
    console.log(result.status === 0 ? `Screenshot captured: ${screenshotName}` : result.stderr);
    process.exit(result.status ?? 1);
}
const match = result.stdout.match(/<pre id="results">([\s\S]*?)<\/pre>/);
if (!match || match[1] === 'RUNNING') {
    console.error(result.stderr || 'Browser checks did not finish.');
    process.exit(1);
}
const report = match[1].replaceAll('&quot;', '"').replaceAll('&gt;', '>').replaceAll('&lt;', '<').replaceAll('&amp;', '&');
writeFileSync(join(generated, 'browser-results.json'), report);
const parsed = JSON.parse(report);
for (const check of parsed.checks) console.log(`${check.pass ? 'PASS' : 'FAIL'} ${check.label}${check.detail ? ': ' + check.detail : ''}`);
console.log(`JavaScript errors: ${parsed.errors.length}; page requests after load: ${parsed.requests.length}`);
process.exit(parsed.checks.every(check => check.pass) && !parsed.errors.length && !parsed.requests.length ? 0 : 1);
