<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Account Activation') · AssocMap</title>
    <script nonce="{{ request()->attributes->get('activation_nonce') }}">
        (() => {
            const hashes = new URLSearchParams(window.location.hash.slice(1)).getAll('token_hash');
            let tokenHash = hashes.length === 1 && hashes[0].length === 56 && /^[a-fA-F0-9]{56}$/.test(hashes[0]) ? hashes[0] : null;
            hashes.length = 0;
            window.history.replaceState(null, '', window.location.pathname);
            const initialize = () => {
                const verification = document.getElementById('invitation-verification');
                if (verification) {
                    const button = verification.querySelector('button[type="submit"]');
                    const note = document.getElementById('invitation-link-note');
                    const valid = tokenHash !== null;
                    button.disabled = !valid;
                    note.hidden = valid;
                    if (!valid) {
                        note.textContent = 'This page has no usable invitation token. Reopen the original activation link from your invitation email. If the link is unavailable, expired, or already used, contact the administrator for recovery.';
                        note.setAttribute('role', 'alert');
                    }
                    verification.addEventListener('submit', (event) => {
                        if (!tokenHash) { event.preventDefault(); return; }
                        verification.elements.token_hash.value = tokenHash;
                        tokenHash = null;
                        verification.setAttribute('aria-busy', 'true');
                        button.disabled = true;
                        button.textContent = 'Verifying…';
                    });
                } else {
                    tokenHash = null;
                    document.querySelectorAll('[data-invitation-password-toggle]').forEach((toggle) => {
                        const password = document.getElementById(toggle.getAttribute('aria-controls'));
                        if (!password || password.type !== 'password') return;
                        const label = toggle.getAttribute('data-password-label');
                        toggle.hidden = false;
                        toggle.addEventListener('click', () => {
                            const show = password.type === 'password';
                            password.type = show ? 'text' : 'password';
                            toggle.querySelector('[data-password-toggle-text]').textContent = show ? 'Hide' : 'Show';
                            toggle.querySelector('[data-password-eye-off]').toggleAttribute('hidden', !show);
                            toggle.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} ${label}`);
                        });
                    });
                    document.querySelectorAll('form').forEach((form) => {
                        form.addEventListener('submit', () => {
                            const button = form.querySelector('button[type="submit"]');
                            if (button) { button.disabled = true; button.textContent = 'Please wait…'; }
                        });
                    });
                }
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initialize, { once: true });
            } else {
                initialize();
            }
        })();
    </script>
    <style nonce="{{ request()->attributes->get('activation_nonce') }}">
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #f5f7f6; color: #24352e; }
        main { min-height: 100vh; min-height: 100svh; display: grid; place-items: center; padding: 32px 16px; }
        .staff-login-wrap { width: min(100%, 440px); }
        .staff-login-brand { text-align: center; margin-bottom: 24px; font-size: 26px; font-weight: 750; }
        .staff-login-brand span { color: #19734c; }
        .staff-login-card { padding: 32px; background: white; border: 1px solid #dbe5df; border-radius: 14px; box-shadow: 0 8px 28px #1539240d; }
        h1 { margin: 0; font-size: 26px; }
        p { line-height: 1.6; }
        .staff-login-intro { color: #53675c; font-size: 14px; margin: 12px 0 24px; }
        .staff-login-field { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; }
        input { width: 100%; min-height: 46px; padding: 10px; border: 1px solid #bacdc1; border-radius: 6px; font: inherit; }
        input.activation-confirmation { width: auto; min-height: auto; padding: 0; }
        input[aria-invalid="true"] { border-color: #9d2b2b; }
        button { width: 100%; padding: 13px 16px; border: 0; border-radius: 7px; background: #19734c; color: white; font: inherit; font-weight: 600; cursor: pointer; }
        .activation-password-control { display: flex; align-items: stretch; gap: 8px; }
        .activation-password-control input { min-width: 0; flex: 1; }
        .activation-password-toggle { display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: auto; min-width: 78px; min-height: 44px; padding: 8px 10px; border: 1px solid #bacdc1; border-radius: 6px; background: white; color: #19734c; font-size: 13px; white-space: nowrap; }
        .activation-password-toggle:hover { background: #f5f7f6; }
        .activation-password-toggle svg { width: 17px; height: 17px; flex: none; }
        button:disabled { opacity: .6; cursor: not-allowed; }
        form[aria-busy="true"] button:disabled { cursor: wait; }
        a { color: #19734c; }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #d2ad27; outline-offset: 3px; }
        .staff-login-errors { margin: 0 0 20px; padding: 12px; border-radius: 6px; background: #fff1f1; color: #862424; font-size: 14px; }
        .staff-login-errors p { margin: 4px 0; }
        .staff-login-note { font-size: 13px; color: #53675c; }
        .staff-login-back { display: block; text-align: center; margin-top: 24px; }
        [hidden] { display: none !important; }
        @media (max-width: 480px) { .staff-login-card { padding: 24px 20px; } }
    </style>
</head>
<body class="public-page staff-login-page">
    <main aria-labelledby="activation-title">
        <div class="staff-login-wrap">
            <div class="staff-login-brand">Assoc<span>Map</span></div>
            <section class="staff-login-card" aria-labelledby="activation-title">
                @yield('content')
                <a class="staff-login-back" href="{{ route('login') }}">Go to sign in</a>
            </section>
        </div>
    </main>
</body>
</html>
