import { photoInputError } from '../components/photo-input.js';

export function initProjectCovers(scope = document) {
    scope.querySelectorAll('[data-project-cover-thumbnail]').forEach(image => {
        const unavailable = () => {
            image.hidden = true;
            image.parentElement.querySelector('[data-cover-thumbnail-empty]').hidden = false;
        };
        image.addEventListener('error', unavailable, { once: true });
        if (image.complete && image.naturalWidth === 0) unavailable();
    });
    scope.querySelectorAll('[data-project-cover]').forEach(root => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const find = selector => root.querySelector(selector);
        const form = find('[data-cover-upload]');
        const input = form?.querySelector('input[type=file]');
        const preview = find('[data-cover-preview]');
        const display = find('[data-cover-display]');
        const empty = find('[data-cover-empty]');
        const remove = find('[data-cover-remove]');
        const confirmation = find('[data-cover-confirm]');
        const status = find('[data-cover-status]');
        const endpoint = root.dataset.endpoint.replace(/\/$/, '');
        let canManage = root.dataset.manage === 'true';
        let busy = false;
        let loaded = false;
        let cover = null;
        let pendingUpload = false;
        let selection = null;
        let previewUrl = null;
        let removal = null;
        let opener = null;
        const message = value => { status.textContent = value; };
        const controls = () => {
            root.setAttribute('aria-busy', String(busy));
            root.querySelectorAll('button, input').forEach(control => { control.disabled = busy; });
            if (form) {
                form.hidden = !canManage;
                input.disabled = busy || !loaded || pendingUpload;
                form.querySelector('button[type=submit]').disabled = busy || !loaded || pendingUpload || !selection;
                form.querySelector('button[type=submit]').textContent = cover ? 'Replace cover' : 'Upload cover';
            }
            if (remove) {
                remove.hidden = !canManage || !cover;
                remove.disabled = busy || pendingUpload;
            }
        };
        const clearSelection = () => {
            selection = null;
            if (input) { input.value = ''; input.setCustomValidity(''); }
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            if (preview) { preview.hidden = true; preview.removeAttribute('src'); }
        };
        const request = async (url, method = 'GET', body = undefined) => {
            const response = await fetch(url, { method, body, credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf || document.querySelector('meta[name=csrf-token]')?.content || '' } });
            if (response.redirected) throw new Error('Your session changed. Sign in again and refresh this page.');
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().find(value => typeof value === 'string')
                || result.message || (response.status === 413 ? 'Choose a cover photo at most 5 MiB.' : 'Cover unavailable. Refresh before retrying.'));
            return result;
        };
        const actionButton = (label, action) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button';
            button.textContent = label;
            button.addEventListener('click', action);
            return button;
        };
        const confirmRemoval = (photo, button) => {
            removal = photo;
            opener = button;
            confirmation.showModal();
            find('[data-cover-cancel]').focus();
        };
        const load = async () => {
            const snapshot = await request(endpoint);
            cover = snapshot.cover;
            pendingUpload = snapshot.upload_pending;
            canManage = root.dataset.manage === 'true' && snapshot.can_manage;
            if (cover) {
                if (display.getAttribute('src') !== cover.display_url) display.src = cover.display_url;
                display.hidden = false;
                empty.hidden = true;
            } else {
                display.hidden = true;
                display.removeAttribute('src');
                empty.hidden = false;
                empty.textContent = 'No cover photo.';
            }
            const pending = find('[data-cover-pending]');
            pending.replaceChildren();
            snapshot.pending.forEach(photo => {
                const item = document.createElement('li');
                const text = document.createElement('span');
                text.textContent = photo.status === 'deleting' ? 'Photo withdrawn; cleanup pending. '
                    : (cover ? 'Replacement pending. The current cover is retained. ' : 'Cover upload pending verification. ');
                if (photo.retry_after > 0) text.textContent += 'Refresh after ' + Math.ceil(photo.retry_after / 60) + ' minute(s). ';
                item.append(text);
                if (canManage && photo.retry_after === 0) item.append(actionButton('Check saved state', () => mutate(async () => {
                    const result = await request(endpoint + '/' + photo.id + '/reconcile', 'POST');
                    if (result.status === 'ready' || (result.status === 'deleted' && photo.status !== 'deleting')) clearSelection();
                }, 'Saved state checked.')));
                if (canManage && photo.status !== 'deleting') item.append(actionButton('Cancel pending upload',
                    event => confirmRemoval(photo, event.currentTarget)));
                pending.append(item);
            });
            loaded = true;
            message(pendingUpload ? 'An upload is pending. Check its saved state before starting another.'
                : (canManage ? 'Cover updated.' : 'Cover photo is read-only.'));
            controls();
        };
        const mutate = async (work, success) => {
            if (busy) return;
            busy = true;
            controls();
            message('Saving cover changes. Please wait.');
            try {
                await work();
                await load();
                message(success);
            } catch (error) {
                const failure = error.message;
                try { await load(); } catch { /* Refresh remains available; keep retry identity and current cover. */ }
                message(failure);
            } finally { busy = false; controls(); }
        };
        const refresh = async () => {
            if (busy) return;
            busy = true;
            controls();
            message('Loading cover photo...');
            try { await load(); } catch (error) { message(error.message); }
            finally { busy = false; controls(); }
        };
        display.addEventListener('error', () => {
            display.hidden = true;
            empty.hidden = false;
            empty.textContent = 'Cover photo unavailable. Refresh to retry.';
        });
        // A failed image may be retried explicitly; unchanged successful images are reused.
        find('[data-cover-refresh]').addEventListener('click', () => {
            if (display.hidden) display.removeAttribute('src');
            void refresh();
        });
        input?.addEventListener('change', () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            selection = null;
            preview.hidden = true;
            preview.removeAttribute('src');
            const file = input.files[0];
            const error = photoInputError(file);
            input.setCustomValidity(error);
            message(error);
            if (!error) {
                selection = { file, requestId: crypto.randomUUID(), replaces: cover?.id || null };
                previewUrl = URL.createObjectURL(file);
                preview.src = previewUrl;
                preview.hidden = false;
            }
            controls();
        });
        form?.addEventListener('submit', event => {
            event.preventDefault();
            if (!selection) return;
            const operation = selection;
            const body = new FormData();
            body.append('photo', operation.file);
            body.append('request_id', operation.requestId);
            if (operation.replaces) body.append('replaces', operation.replaces);
            void mutate(async () => { await request(endpoint, 'POST', body); clearSelection(); }, 'Cover saved.');
        });
        remove?.addEventListener('click', event => { if (cover) confirmRemoval(cover, event.currentTarget); });
        find('[data-cover-confirm-remove]').addEventListener('click', () => {
            confirmation.close();
            if (removal) void mutate(async () => {
                await request(endpoint + '/' + removal.id, 'DELETE');
                clearSelection();
            }, 'Photo withdrawn. Any pending cleanup is shown below.');
        });
        find('[data-cover-cancel]').addEventListener('click', () => confirmation.close());
        confirmation.addEventListener('close', () => opener?.focus());
        window.addEventListener('pagehide', () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, { once: true });
        controls();
        void refresh();
    });
}
