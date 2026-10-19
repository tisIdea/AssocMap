export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

export function setDisclosure(button, expanded) {
    const panel = document.getElementById(button.dataset.disclosure);
    if (!panel) return;
    panel.hidden = !expanded;
    button.setAttribute('aria-expanded', String(expanded));
}

export function setFieldVisibility(container, visible) {
    if (!container) return;
    container.hidden = !visible;
    $$('input, select, textarea', container).forEach(input => { input.disabled = !visible; });
}

export function initDisclosures() {
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-disclosure]');
        if (button) setDisclosure(button, button.getAttribute('aria-expanded') !== 'true');
    });
}
