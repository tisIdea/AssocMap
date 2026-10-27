import assert from 'node:assert/strict';
import test from 'node:test';
import { photoInputError } from '../../resources/js/pages/monitoring-photos.js';

test('photo selection enforces the exact 5 MiB boundary and supported matching formats', () => {
    assert.match(photoInputError(null), /Choose/);
    assert.equal(photoInputError({ name: 'photo.PNG', type: 'image/png', size: 5 * 1024 * 1024 }), '');
    assert.equal(photoInputError({ name: 'photo.jpeg', type: 'image/jpeg', size: 1 }), '');
    assert.match(photoInputError({ name: 'photo.png', type: 'image/png', size: 5 * 1024 * 1024 + 1 }), /5 MiB/);
    for (const file of [
        { name: 'photo.jpg', type: 'image/png' }, { name: 'photo.png', type: 'image/jpeg' },
        { name: 'photo.svg', type: 'image/svg+xml' }, { name: 'photo.gif', type: 'image/gif' },
    ]) assert.match(photoInputError({ ...file, size: 1 }), /JPEG or PNG/);
});
