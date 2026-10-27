import { photoInputError } from '../components/photo-input.js';
export { photoInputError } from '../components/photo-input.js';

export function initMonitoringPhotos(scope = document) {
    scope.querySelectorAll('[data-monitoring-photos]').forEach(root => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const find = selector => root.querySelector(selector);
        const status = find('[data-photo-status]');
        const form = find('[data-photo-upload]');
        const input = form?.querySelector('input[type=file]');
        const preview = find('[data-photo-preview]');
        const viewer = find('[data-photo-viewer]');
        const confirmation = find('[data-photo-confirm]');
        const display = find('[data-photo-display]');
        const endpoint = root.dataset.endpoint.replace(/\/$/, '');
        const canManage = root.dataset.manage === 'true';
        let loaded = false;
        let busy = false;
        let usedSlots = 0;
        let previewUrl = null;
        let requestId = null;
        let removalId = null;
        let opener = null;
        const message = text => { status.textContent = text; };
        const controls = () => {
            root.querySelectorAll('button, input').forEach(control => { control.disabled = busy; });
            if (input) input.disabled = busy || usedSlots >= 5;
            if (form) form.querySelector('[type=submit]').disabled = busy || usedSlots >= 5 || !input.files[0];
            root.setAttribute('aria-busy', String(busy));
        };
        const request = async (url, method = 'GET', body = undefined) => {
            const response = await fetch(url, {
                method, body, credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf || document.querySelector('meta[name=csrf-token]')?.content || '' },
            });
            if (response.redirected) throw new Error('Your session changed. Sign in again and refresh this page.');
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const errors = Object.values(result.errors || {}).flat().filter(value => typeof value === 'string');
                throw new Error(errors[0] || result.message || (response.status === 413
                    ? 'The upload exceeds the server limit. Choose a photo at most 5 MiB.'
                    : 'Photos are unavailable. Refresh the gallery before retrying.'));
            }
            return result;
        };
        const button = (label, action) => {
            const node = document.createElement('button');
            node.type = 'button';
            node.className = 'button';
            node.textContent = label;
            node.setAttribute('aria-label', label);
            node.addEventListener('click', action);
            return node;
        };
        const mutate = async (action, success) => {
            if (busy) return;
            busy = true;
            controls();
            message('Saving photo changes. Please wait.');
            try {
                await action();
                await load();
                message(success);
            } catch (error) {
                // Retain the selected file and its idempotency ID after an unknown result.
                const failure = error.message;
                try { await load(); } catch { /* The explicit Refresh control remains available. */ }
                message(failure);
            } finally {
                busy = false;
                controls();
            }
        };
        const show = (photo, source) => {
            opener = source;
            find('[data-photo-image-status]').textContent = 'Loading photo...';
            display.onload = () => { find('[data-photo-image-status]').textContent = ''; };
            display.onerror = () => { find('[data-photo-image-status]').textContent = 'Photo unavailable. Close and refresh the gallery.'; };
            display.src = photo.display_url;
            viewer.showModal();
        };
        const render = gallery => {
            usedSlots = gallery.used_slots;
            find('[data-photo-count]').textContent = usedSlots + ' / 5 slots';
            const grid = find('[data-photo-grid]');
            const existing = new Map(Array.from(grid.children, node => [node.dataset.photoId, node]));
            const figures = [];
            gallery.photos.forEach((photo, index) => {
                if (existing.has(photo.id)) { figures.push(existing.get(photo.id)); return; }
                const figure = document.createElement('figure');
                figure.dataset.photoId = photo.id;
                const open = button('View photo ' + (index + 1), () => show(photo, open));
                const image = document.createElement('img');
                image.src = photo.thumbnail_url;
                image.alt = 'Monitoring photo ' + (index + 1);
                image.loading = 'lazy';
                image.decoding = 'async';
                image.width = photo.thumbnail_width;
                image.height = photo.thumbnail_height;
                image.addEventListener('error', () => {
                    image.remove();
                    open.textContent = 'Photo unavailable. Refresh to retry';
                }, { once: true });
                open.prepend(image);
                figure.append(open);
                if (canManage) figure.append(button('Remove photo ' + (index + 1), event => {
                    removalId = photo.id;
                    opener = event.currentTarget;
                    confirmation.showModal();
                    find('[data-photo-cancel]').focus();
                }));
                figures.push(figure);
            });
            grid.replaceChildren(...figures);
            const pending = find('[data-photo-pending]');
            pending.replaceChildren();
            gallery.pending.forEach(photo => {
                const item = document.createElement('li');
                const description = document.createElement('span');
                description.textContent = photo.status === 'deleting' ? 'Removal awaiting storage cleanup.' : 'Upload pending verification.';
                item.append(description);
                if (photo.retry_after > 0) {
                    const hint = document.createElement('span');
                    hint.textContent = ' Check again after ' + Math.ceil(photo.retry_after / 60) + ' minute(s).';
                    item.append(hint);
                }
                if (canManage && photo.retry_after === 0) item.append(button('Check saved state', () => mutate(
                    () => request(endpoint + '/' + photo.id + '/reconcile', 'POST'), 'Saved state checked.')));
                if (canManage && photo.status !== 'deleting') item.append(button('Remove pending photo', event => {
                    removalId = photo.id;
                    opener = event.currentTarget;
                    confirmation.showModal();
                    find('[data-photo-cancel]').focus();
                }));
                pending.append(item);
            });
            message(gallery.photos.length ? 'Gallery updated.' : 'No photos saved yet.');
            controls();
        };
        const load = async () => {
            const gallery = await request(endpoint);
            render(gallery);
            loaded = true;
        };
        const refresh = async () => {
            if (busy) return;
            busy = true;
            controls();
            message('Loading photos...');
            try { await load(); } catch (error) { message(error.message); }
            finally { busy = false; controls(); }
        };
        root.addEventListener('toggle', () => { if (root.open && !loaded) void refresh(); });
        find('[data-photo-refresh]').addEventListener('click', refresh);
        input?.addEventListener('change', () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            preview.hidden = true;
            preview.removeAttribute('src');
            requestId = null;
            const error = photoInputError(input.files[0]);
            input.setCustomValidity(error);
            message(error);
            if (!error) {
                previewUrl = URL.createObjectURL(input.files[0]);
                preview.src = previewUrl;
                preview.hidden = false;
                requestId = crypto.randomUUID();
            }
            controls();
        });
        form?.addEventListener('submit', event => {
            event.preventDefault();
            const error = photoInputError(input.files[0]);
            if (error) { message(error); return; }
            const body = new FormData();
            body.append('photo', input.files[0]);
            body.append('request_id', requestId ||= crypto.randomUUID());
            void mutate(async () => {
                await request(endpoint, 'POST', body);
                input.value = '';
                requestId = null;
                if (previewUrl) URL.revokeObjectURL(previewUrl);
                previewUrl = null;
                preview.hidden = true;
                preview.removeAttribute('src');
            }, 'Photo uploaded.');
        });
        find('[data-photo-remove]').addEventListener('click', () => {
            confirmation.close();
            if (removalId) void mutate(() => request(endpoint + '/' + removalId, 'DELETE'), 'Photo removed. Any pending cleanup is shown below.');
        });
        find('[data-photo-cancel]').addEventListener('click', () => confirmation.close());
        find('[data-photo-close]').addEventListener('click', () => viewer.close());
        viewer.addEventListener('close', () => { display.removeAttribute('src'); opener?.focus(); });
        confirmation.addEventListener('close', () => opener?.focus());
        window.addEventListener('pagehide', () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, { once: true });
        controls();
    });
}
