export async function checkAdminEmailPlaceholder({ check }) {
    const dialog = document.getElementById('email-modal');
    const form = dialog.querySelector('form');
    const email = form.elements.namedItem('new_email');
    const cancel = form.querySelector('[data-close-dialog]');
    const changeEmail = form.querySelector('button[disabled]');
    const logout = document.querySelector('.workspace-header form');
    const initialUrl = location.href;
    const logoutPayload = Array.from(new FormData(logout));

    check(form.dataset.formInitialized === 'true', 'Vite app initializes the existing placeholder submit listener');
    check(!dialog.open && changeEmail.disabled && changeEmail.type === 'button', 'placeholder stays closed and its unavailable action stays disabled');
    check(dialog.getAttribute('aria-labelledby') === 'email-modal-title'
        && document.getElementById('email-modal-title').textContent === 'Change email', 'dialog retains its accessible name');
    check(email.type === 'email' && email.required && email.labels[0].htmlFor === email.id, 'native email constraint and label remain associated');
    check(email.getAttribute('aria-describedby').split(/\s+/).every(id => document.getElementById(id)), 'email descriptions reference existing nodes');
    // The placeholder currently has no user-facing opener; exercise its native dialog directly.
    dialog.showModal();
    email.focus();
    check(document.activeElement === email, 'email remains keyboard focusable');
    const submissions = [];
    let invalid = 0;
    email.addEventListener('invalid', () => invalid++);
    form.addEventListener('submit', event => submissions.push({ prevented: event.defaultPrevented, data: Array.from(new FormData(form)) }));
    form.requestSubmit();
    check(invalid === 1 && submissions.length === 0 && email.validity.valueMissing
        && email.validationMessage.length > 0 && document.activeElement === email, 'empty input retains native validation feedback and focus');
    email.value = 'invalid-address';
    form.requestSubmit();
    check(invalid === 2 && submissions.length === 0 && email.validity.typeMismatch
        && email.validationMessage.length > 0 && document.activeElement === email, 'malformed email retains native validation feedback and focus');
    email.value = 'synthetic@example.test';
    email.dispatchEvent(new Event('change', { bubbles: true }));
    form.requestSubmit();
    check(submissions.length === 1 && submissions[0].prevented
        && JSON.stringify(submissions[0].data) === JSON.stringify([['new_email', 'synthetic@example.test']]), 'existing module cancels valid submission without changing its payload');
    check(dialog.open && document.activeElement === email && email.value === 'synthetic@example.test', 'cancelled submission preserves dialog, entered value and focus');
    check(!cancel.disabled && cancel.type === 'button', 'Cancel remains an enabled non-submit control');
    const closed = new Promise(resolve => dialog.addEventListener('close', resolve, { once: true }));
    cancel.click();
    await closed;
    check(!dialog.open && location.href === initialUrl, 'existing Cancel listener closes the dialog without navigation');
    check(logout.method === 'post' && logout.action.endsWith('/logout')
        && logoutPayload.some(([name, value]) => name === '_token' && value.length > 10)
        && JSON.stringify(Array.from(new FormData(logout))) === JSON.stringify(logoutPayload), 'authorized logout destination and CSRF payload remain intact');
    check(!form.hasAttribute('onsubmit'), 'rendered placeholder contains no CSP-incompatible inline handler');
}
