const controllers = new WeakMap();
let fieldErrorSequence = 0;

export function errorFeedback(status, { context = 'Operation', network = false } = {}) {
    if (network) return { type: 'error', title: 'Result Not Confirmed', message: 'The result could not be confirmed. Keep your entries and review the latest saved information before trying again.' };
    const messages = {
        401: ['Sign In Required', 'Your session has expired. Sign in again before continuing.'],
        403: ['Access Denied', 'You do not have permission to perform this action.'],
        404: ['Record Unavailable', 'The requested information is unavailable or outside your current access.'],
        409: ['Information Changed', 'The information changed or this action conflicts with the current saved state. Review the latest information before trying again.'],
        419: ['Session Expired', 'Your session has expired. Keep your entries before signing in again.'],
        422: ['Review Your Entries', 'The request was not completed. Review the form messages and correct the highlighted fields.'],
        429: ['Please Wait', 'Too many requests were received. Wait a moment before trying again.'],
    };
    const [title, message] = messages[status] ?? (context === 'Report'
        ? ['Report Unavailable', 'The requested report could not be generated. Please try again later.']
        : ['Operation Unavailable', 'The operation could not be completed. Keep your entries and try again later.']);
    return { type: 'error', title, message };
}

export function showFeedback(result) {
    const root = document.querySelector('[data-feedback-root]');
    return root ? initFeedback(root)?.show(result) : null;
}

export function showOperationError(error, options = {}) {
    return showFeedback({ ...errorFeedback(Number(error?.status), { ...options, network: !error?.status }), returnFocus: options.returnFocus });
}

export function initFeedback(root = document.querySelector('[data-feedback-root]')) {
    if (!root) return null;
    if (controllers.has(root)) return controllers.get(root);
    const dialog = root.querySelector('[data-global-feedback]');
    if (!dialog || typeof dialog.showModal !== 'function') return null;
    const button = dialog.querySelector('[data-feedback-acknowledge]');
    const title = dialog.querySelector('[data-feedback-title]');
    const message = dialog.querySelector('[data-feedback-message]');
    const queue = [], seen = new Set(), restores = new Set();
    let active = null, booting = true, acknowledging = false;
    function otherDialog() { return document.querySelector('dialog[open]:not([data-global-feedback])'); }
    function restoreFocus(target) {
        if (target && target !== document.body && target.isConnected && !target.disabled
            && !target.closest?.('[inert], dialog:not([open])')) target.focus();
        else {
            const fallback = [...document.querySelectorAll('form[data-feedback-error-bag] [aria-invalid="true"]')]
                .find(node => !node.closest('[inert], dialog:not([open])'))
                ?? document.querySelector('#main-content h1') ?? document.querySelector('#main-content');
            if (fallback) { if (!fallback.hasAttribute('tabindex')) fallback.setAttribute('tabindex', '-1'); fallback.focus(); }
        }
    }
    function collectRestores() {
        document.querySelectorAll('dialog[data-feedback-restore="true"]').forEach(node => restores.add(node));
    }
    function pump() {
        if (booting || active || otherDialog()) return;
        if (!queue.length) {
            collectRestores();
            const pending = [...restores].find(node => node.isConnected);
            if (pending) {
                applyFieldErrors(payload.errors ?? {});
                restores.delete(pending); delete pending.dataset.feedbackRestore;
                pending.showModal();
                (pending.feedbackFocusTarget ?? pending.querySelector('[aria-invalid="true"], input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button'))?.focus();
            }
            return;
        }
        active = queue.shift();
        title.textContent = active.title;
        message.textContent = active.message;
        dialog.dataset.feedbackType = active.type;
        dialog.querySelector('[data-feedback-success]').hidden = active.type !== 'success';
        dialog.querySelector('[data-feedback-error]').hidden = active.type !== 'error';
        button.textContent = active.type === 'success' ? 'OK' : 'Close';
        button.disabled = false;
        document.body.classList.add('feedback-open');
        dialog.showModal();
        button.focus();
    }
    function show(result) {
        if (!result || typeof result.message !== 'string' || !result.message.trim()) return Promise.resolve();
        if (result.id && seen.has(result.id)) return Promise.resolve();
        if (result.id) seen.add(result.id);
        return new Promise(resolve => {
            queue.push({ type: result.type === 'success' ? 'success' : 'error',
                title: typeof result.title === 'string' ? result.title : 'Operation Result', message: result.message,
                returnFocus: result.returnFocus ?? document.activeElement, resolve });
            pump();
        });
    }
    function finishAcknowledgement() {
        const previous = active;
        active = null;
        dialog.close();
        document.body.classList.remove('feedback-open');
        restoreFocus(previous?.returnFocus);
        previous?.resolve();
        acknowledging = false;
        pump();
    }
    button.addEventListener('click', () => {
        if (!active || acknowledging) return;
        acknowledging = true; button.disabled = true;
        if (typeof dialog.animate === 'function' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            dialog.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 100 }).finished.then(finishAcknowledgement, finishAcknowledgement);
        } else finishAcknowledgement();
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); button.focus(); });
    dialog.addEventListener('click', event => { if (event.target === dialog) event.preventDefault(); });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Tab') { event.preventDefault(); event.stopPropagation(); button.focus(); }
        if (event.key === 'Escape') event.stopPropagation();
    });
    document.addEventListener('close', () => { if (!acknowledging) pump(); }, true);
    const controller = { show, fields: applyFormErrors, error: (status, options = {}) => show({ ...errorFeedback(status, options), returnFocus: options.returnFocus }) };
    controllers.set(root, controller);
    window.AssocMapFeedback = controller;
    let payload = {};
    const source = root.querySelector('[data-feedback-payload]');
    try {
        payload = JSON.parse(source.textContent);
        source.textContent = '';
        document.documentElement.classList.add('feedback-ready');
    } catch { payload = {}; }
    if (payload.notification) void show(payload.notification);
    const observer = new MutationObserver(() => { collectRestores(); pump(); });
    observer.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['data-feedback-restore'] });
    setTimeout(() => { applyFieldErrors(payload.errors ?? {}); booting = false; collectRestores(); pump(); }, 0);
    return controller;
}

export function applyFieldErrors(bags) {
    document.querySelectorAll('form[data-feedback-error-bag]').forEach(form => {
        const errors = bags[form.dataset.feedbackErrorBag];
        if (!errors || typeof errors !== 'object') return;
        applyFormErrors(form, errors);
    });
}

export function applyFormErrors(form, errors) {
    if (!form || !errors || typeof errors !== 'object') return;
    Object.entries(errors).forEach(([key, messages]) => {
        if (!Array.isArray(messages)) return;
        const parts = key.split('.');
        const name = parts.shift() + parts.map(part => `[${part}]`).join('');
        const input = [...form.elements].find(field => (field.name === name || field.name === name + '[]'
            || field.name === name.replace(/\[\d+\]/g, '[]')) && field.type !== 'hidden' && !field.disabled);
        if (!input) return;
        input.setAttribute('aria-invalid', 'true');
        const container = input.closest('.field') ?? input.parentElement;
        const existing = (input.getAttribute('aria-describedby') ?? '').split(/\s+/).map(id => document.getElementById(id))
            .find(node => node?.classList.contains('field-error')) ?? container.querySelector?.('.field-error');
        const detail = existing ?? document.createElement('p');
        detail.className = 'field-error'; detail.hidden = false;
        if (!detail.id) detail.id = `feedback-field-error-${++fieldErrorSequence}`;
        detail.textContent = messages.filter(value => typeof value === 'string').join(' ');
        input.setAttribute('aria-describedby', [...new Set([...(input.getAttribute('aria-describedby') ?? '').split(/\s+/), detail.id])].filter(Boolean).join(' '));
        if (!existing) { detail.dataset.feedbackFieldError = ''; container.append(detail); }
    });
}
