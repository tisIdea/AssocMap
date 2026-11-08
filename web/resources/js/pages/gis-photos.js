import { photoInputError } from '../components/photo-input.js';

export function createGisPhotos(root, { base, manager, csrf }) {
    const find = selector => root.querySelector(selector);
    const status = find('[data-photo-status]');
    const form = find('[data-photo-upload]');
    const input = form?.querySelector('input[type=file]');
    const preview = find('[data-photo-preview]');
    const viewer = find('[data-photo-viewer]');
    const display = find('[data-photo-display]');
    const confirmation = find('[data-photo-confirm]');
    const grid = find('[data-photo-grid]');
    let current, opener, removalId, previewUrl, requestId, busy = false, canManage = false, slots = 0, photos = [];
    const message = text => { status.textContent = text; };
    const resetFile = (clearInput = true) => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null; requestId = null;
        if (input) { if (clearInput) input.value = ''; input.setCustomValidity(''); }
        if (preview) { preview.hidden = true; preview.removeAttribute('src'); }
    };
    const controls = () => {
        root.querySelectorAll('button, input').forEach(control => { control.disabled = busy; });
        if (form) {
            form.hidden = !canManage;
            input.disabled = busy || !canManage || slots >= 5;
            form.querySelector('[type=submit]').disabled = input.disabled || !input.files[0];
        }
        root.setAttribute('aria-busy', String(busy));
    };
    const request = async (url, method = 'GET', body, signal) => {
        const target = new URL(url, window.location.origin);
        if (target.origin !== window.location.origin) throw new Error('Photo endpoint unavailable.');
        const response = await fetch(target, { method, body, signal, cache: 'no-store', redirect: 'error', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || typeof result !== 'object') {
            const errors = Object.values(result?.errors || {}).flat().filter(value => typeof value === 'string');
            throw new Error(errors[0] || result?.message || 'Photos unavailable. Refresh the gallery before retrying.');
        }
        return result;
    };
    const button = (label, action) => {
        const node = document.createElement('button'); node.type = 'button'; node.className = 'button';
        node.textContent = label; node.setAttribute('aria-label', label); node.addEventListener('click', action); return node;
    };
    const formBody = values => {
        const body = new FormData();
        Object.entries(values).forEach(([key, value]) => {
            if (Array.isArray(value)) value.forEach(item => body.append(key + '[]', item));
            else body.append(key, value);
        });
        return body;
    };
    const openPhoto = (photo, source) => {
        opener = source;
        find('[data-photo-image-status]').textContent = 'Loading photo...';
        display.onload = () => { find('[data-photo-image-status]').textContent = ''; };
        display.onerror = () => { display.removeAttribute('src'); find('[data-photo-image-status]').textContent = 'Photo unavailable. Close and refresh the gallery.'; };
        display.src = photo.display_url; viewer.showModal();
    };
    const remove = (id, source) => { removalId = id; opener = source; confirmation.showModal(); find('[data-photo-cancel]').focus(); };
    const render = gallery => {
        if (!Array.isArray(gallery.photos)) throw new Error('Gallery response unavailable.');
        photos = gallery.photos; slots = gallery.used_slots || 0; canManage = manager && gallery.can_manage === true;
        root.dataset.photoState = photos.length ? 'ready' : 'empty';
        find('[data-photo-count]').textContent = manager ? `${slots} / 5 slots` : `${photos.length} approved ${photos.length === 1 ? 'photo' : 'photos'}`;
        const existing = new Map(Array.from(grid.children, node => [node.dataset.photoId, node]));
        grid.replaceChildren(...photos.map((photo, index) => {
            const previous = existing.get(photo.id);
            const figure = document.createElement('figure'); figure.dataset.photoId = photo.id;
            const open = button('View photo ' + (index + 1), () => openPhoto(photo, open));
            open.classList.add('gis-photo-open');
            const caption = document.createElement('span'); caption.textContent = 'View photo ' + (index + 1);
            open.replaceChildren(caption);
            const image = previous?.querySelector('img') || document.createElement('img');
            if (!image.getAttribute('src')) image.src = photo.thumbnail_url;
            image.alt = 'Location photo ' + (index + 1); image.loading = 'lazy'; image.decoding = 'async';
            image.width = photo.thumbnail_width; image.height = photo.thumbnail_height;
            image.onerror = () => { image.remove(); open.textContent = 'Photo unavailable. Refresh to retry'; };
            open.prepend(image); figure.append(open);
            if (manager) {
                const label = document.createElement('figcaption'); label.textContent = photo.approved ? 'Approved for eligible public display' : 'Private'; figure.append(label);
            }
            if (canManage) {
                figure.append(button(photo.approved ? 'Withdraw approval' : 'Approve for public display', () => mutate(
                    context => request(context.endpoint + '/' + photo.id + '/approval', 'POST', formBody({ _method: 'PATCH', approved: photo.approved ? '0' : '1' })),
                    photo.approved ? 'Approval withdrawn.' : 'Photo approved. Public delivery also requires an eligible published location.')));
                figure.append(button('Remove photo ' + (index + 1), event => remove(photo.id, event.currentTarget)));
                if (index > 0) figure.append(button('Move photo ' + (index + 1) + ' earlier', () => {
                    const ids = photos.map(item => item.id); [ids[index - 1], ids[index]] = [ids[index], ids[index - 1]];
                    void mutate(context => request(context.endpoint + '/order', 'POST', formBody({ _method: 'PATCH', ids })), 'Photo order saved.');
                }));
            }
            return figure;
        }));
        const pending = find('[data-photo-pending]'); pending.replaceChildren();
        (gallery.pending || []).forEach(photo => {
            const item = document.createElement('li');
            item.textContent = photo.status === 'deleting' ? 'Removal awaiting cleanup. ' : 'Upload pending verification. ';
            if (photo.retry_after > 0) item.append(`Check again after ${Math.ceil(photo.retry_after / 60)} minute(s). `);
            if (canManage && photo.retry_after === 0) item.append(button('Check saved state', () => mutate(
                context => request(context.endpoint + '/' + photo.id + '/reconcile', 'POST'), 'Saved state checked.')));
            if (canManage && photo.status !== 'deleting') item.append(button('Remove pending photo', event => remove(photo.id, event.currentTarget)));
            pending.append(item);
        });
        message(photos.length ? '' : (manager ? 'No ready photos. Pending uploads appear below.' : 'No approved photos are available for this location.'));
        controls();
    };
    const load = async context => {
        const gallery = await request(context.endpoint, 'GET', undefined, context.controller.signal);
        if (current === context) render(gallery);
    };
    const refresh = async () => {
        if (!current || busy) return;
        const context = current; busy = true; root.dataset.photoState = 'loading'; controls(); message('Loading photos...');
        try { await load(context); } catch (error) {
            if (current === context) { root.dataset.photoState = 'unavailable'; grid.replaceChildren(); display.removeAttribute('src'); viewer.close(); canManage = false; message(error.message); }
        } finally { if (current === context) { busy = false; controls(); } }
    };
    const mutate = async (action, success) => {
        if (!current || busy || !canManage) return;
        const context = current; busy = true; controls(); message('Saving photo changes...');
        try {
            await action(context);
            if (current !== context) return;
            await load(context);
            if (current === context) message(success);
        } catch (error) {
            if (current !== context) return;
            try { await load(context); } catch { grid.replaceChildren(); canManage = false; }
            if (current === context) message(error.message);
        } finally { if (current === context) { busy = false; controls(); } }
    };
    input?.addEventListener('change', () => {
        const file = input.files[0]; resetFile(false);
        const error = photoInputError(file); input.setCustomValidity(error); message(error);
        if (!error) { requestId = crypto.randomUUID(); previewUrl = URL.createObjectURL(file); preview.src = previewUrl; preview.hidden = false; }
        controls();
    });
    form?.addEventListener('submit', event => {
        event.preventDefault(); const error = photoInputError(input.files[0]);
        if (error) { message(error); return; }
        const body = formBody({ photo: input.files[0], request_id: requestId ||= crypto.randomUUID() });
        void mutate(async context => { await request(context.endpoint, 'POST', body); if (current === context) resetFile(); }, 'Photo uploaded privately.');
    });
    find('[data-photo-refresh]').addEventListener('click', refresh);
    find('[data-photo-close]').addEventListener('click', () => viewer.close());
    viewer.addEventListener('close', () => { display.removeAttribute('src'); opener?.isConnected && opener.focus(); });
    if (confirmation) {
        find('[data-photo-cancel]').addEventListener('click', () => confirmation.close());
        find('[data-photo-remove]').addEventListener('click', () => { confirmation.close(); if (removalId) void mutate(
            context => request(context.endpoint + '/' + removalId, 'DELETE'), 'Photo removed. Pending cleanup is shown below.'); });
        confirmation.addEventListener('close', () => { opener?.isConnected && opener.focus(); });
    }
    const clear = () => {
        current?.controller.abort(); current = null; busy = false; canManage = false; photos = []; slots = 0;
        resetFile(); viewer.close(); confirmation?.close(); display.removeAttribute('src');
        grid.replaceChildren(); find('[data-photo-pending]').replaceChildren(); root.hidden = true;
    };
    window.addEventListener('pagehide', clear, { once: true });
    return { clear, select(id, version) {
        if (current?.id === id && current?.version === version) return;
        clear(); current = { id, version, endpoint: `${base}/${encodeURIComponent(id)}/photos`, controller: new AbortController() };
        root.hidden = false; void refresh();
    } };
}
