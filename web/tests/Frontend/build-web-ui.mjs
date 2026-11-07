import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { build } from 'vite';
import path from 'node:path';

const [directory, phase] = process.argv.slice(2);
assert.match(path.basename(directory || ''), /^assocmap-ui-f1-[a-f0-9]{32}$/);
assert.ok(['before', 'after'].includes(phase));
const envDir = path.join(directory, 'empty-environment');
await mkdir(envDir, { recursive: true });
await build({ root: path.resolve(import.meta.dirname, '../..'), configFile: path.resolve(import.meta.dirname, '../../vite.config.js'),
    envDir, build: { outDir: path.join(directory, 'build-' + phase), emptyOutDir: false } });
