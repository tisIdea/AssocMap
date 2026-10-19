export function initStaffLogin() {
    const password = document.getElementById('staff-password');
    const toggle = document.getElementById('staff-password-toggle');
    if (!password || !toggle) return;
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const show = password.type === 'password';
        password.type = show ? 'text' : 'password';
        toggle.querySelector('span').textContent = show ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
}
