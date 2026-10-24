@php($reactivation = $reactivation ?? false)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $reactivation ? 'Account Reactivation' : 'Password Recovery' }} · AssocMap</title>
    <script nonce="{{ request()->attributes->get('activation_nonce') }}">
        (() => {
            const hashes = new URLSearchParams(window.location.hash.slice(1)).getAll('token_hash');
            let hash = hashes.length === 1 && hashes[0].length === 56 && /^[a-fA-F0-9]{56}$/.test(hashes[0]) ? hashes[0] : null;
            hashes.length = 0;
            window.history.replaceState(null, '', window.location.pathname);
            document.addEventListener('DOMContentLoaded', () => {
                const verification = document.getElementById('recovery-verification');
                if (verification) {
                    const button = verification.querySelector('button[type="submit"]');
                    button.disabled = hash === null;
                    document.getElementById('recovery-link-note').hidden = hash !== null;
                    verification.addEventListener('submit', (event) => {
                        if (!hash) { event.preventDefault(); return; }
                        verification.elements.token_hash.value = hash;
                        hash = null;
                    });
                } else {
                    hash = null;
                }
                ['recovery-password', 'recovery-password-confirmation'].forEach((id) => {
                    const password = document.getElementById(id);
                    const toggle = document.getElementById(`${id}-toggle`);
                    if (!password || !toggle || password.type !== 'password') return;
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
                const success = document.getElementById('recovery-success');
                if (success) {
                    const signIn = document.getElementById('recovery-sign-in');
                    window.setTimeout(() => window.location.replace(signIn.href), 2500);
                }
                const resend = document.getElementById('recovery-resend');
                if (resend) {
                    const button = resend.querySelector('button[type="submit"]');
                    const countdown = document.getElementById('recovery-countdown');
                    const availableAt = Date.now() + Number(resend.dataset.waitSeconds) * 1000;
                    const update = () => {
                        const remaining = Math.max(0, Math.ceil((availableAt - Date.now()) / 1000));
                        button.disabled = remaining > 0;
                        countdown.textContent = remaining > 0 ? `You can request another link in ${remaining} second${remaining === 1 ? '' : 's'}.` : 'You can request another link now.';
                        if (remaining > 0) window.setTimeout(update, 1000);
                    };
                    update();
                }
                document.querySelectorAll('form').forEach((form) => form.addEventListener('submit', () => {
                    form.setAttribute('aria-busy', 'true');
                    form.querySelector('button[type="submit"]').disabled = true;
                }));
            }, { once: true });
        })();
    </script>
    <style nonce="{{ request()->attributes->get('activation_nonce') }}">
        {!! file_get_contents(resource_path('css/base/foundation.css')) !!}
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page-bg); color: var(--text-primary); font: 16px system-ui, sans-serif; }
        main { min-height: 100vh; min-height: 100svh; display: grid; place-items: center; padding: 24px 16px; }
        section { width: min(100%, 440px); background: var(--card-bg); padding: 28px; border: 1px solid var(--border-color); border-radius: 14px; }
        h1 { margin: 0 0 .67em; font-size: 26px; letter-spacing: normal; line-height: normal; } p { margin: 1em 0; line-height: 1.6; color: inherit; }
        label { display: block; margin: 18px 0 8px; font-weight: 600; }
        input { width: 100%; min-height: 46px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font: inherit; }
        .sign-out-consent { display: flex; align-items: flex-start; gap: 10px; font-weight: 400; line-height: 1.5; }
        .sign-out-consent input { width: auto; min-height: auto; margin-top: 5px; flex-shrink: 0; accent-color: var(--primary); }
        button { width: 100%; margin-top: 24px; padding: 13px; border: 0; border-radius: 7px; background: var(--primary); color: white; font: inherit; font-weight: 600; cursor: pointer; }
        button[type="submit"]:hover:not(:disabled) { background: var(--primary-hover); }
        button:disabled { opacity: .6; cursor: not-allowed; } a { color: var(--primary); text-decoration: underline; }
        a:hover { color: var(--primary-hover); }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid var(--primary); outline-offset: 3px; }
        .recovery-password-control { display: flex; align-items: stretch; gap: 8px; }
        .recovery-password-control input { min-width: 0; flex: 1; }
        .recovery-password-toggle { display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: auto; min-width: 78px; min-height: 46px; margin-top: 0; padding: 8px 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--card-bg); color: var(--primary); font-size: 13px; white-space: nowrap; flex-shrink: 0; }
        .recovery-password-toggle:hover { background: var(--page-bg); }
        .recovery-password-toggle .icon { width: 17px; height: 17px; }
        #recovery-success { color: var(--success); }
        .error { padding: 12px; background: #fff1f1; color: #862424; } [hidden] { display: none !important; }
        @media (max-width: 480px) { section { padding: 24px 20px; } }
    </style>
</head>
<body>
<main aria-labelledby="recovery-title"><section>
    <h1 id="recovery-title">{{ $reactivation ? ($step === 'success' ? 'Account reactivated' : 'Account reactivation') : ($step === 'confirmation' ? 'Check your email' : ($step === 'success' ? 'Password updated successfully' : ($step === 'invalid' ? 'Recovery could not be completed' : 'Password recovery'))) }}</h1>
    @if($reactivation && in_array($step, ['verify', 'reset']))<p>Your Administrator approved restoring this account. Verify your existing email and replace your password. Access remains restricted until completion; afterward you must sign in afresh.</p>@endif
    @if($recoveryFailureMessage ?? null)<p class="error" role="alert">{{ $recoveryFailureMessage }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($step === 'forgot')
        <p>Enter your account email to request a recovery link. This does not activate an account or restore revoked access.</p>
        <form method="POST" action="{{ route('password.request') }}">
            @csrf
            <label for="recovery-email">Email</label>
            <input id="recovery-email" name="email" type="email" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="254" required>
            <button type="submit">Request recovery link</button>
        </form>
    @elseif($step === 'confirmation')
        <p role="status">If an eligible account exists for this email, you will receive a password recovery link. Please check your inbox and spam folder.</p>
        <p>Email delivery is not confirmed.</p>
        <p id="recovery-countdown" role="timer" aria-live="off">{{ $waitSeconds > 0 ? 'You can request another link in '.$waitSeconds.' seconds.' : 'You can request another link now.' }}</p>
        <form id="recovery-resend" method="POST" action="{{ route('password.resend') }}" data-wait-seconds="{{ $waitSeconds }}">
            @csrf
            <button type="submit" @disabled($waitSeconds > 0)>Request another link</button>
        </form>
        <noscript><p>After the waiting period, reload this page to enable the button.</p></noscript>
        <p><a href="{{ route('password.request.edit') }}">Use a different email address</a></p>
    @elseif($step === 'success')
        <p id="recovery-success" role="status">{{ $reactivation ? 'Account reactivated. Use your new password to sign in. Previous sessions cannot be resumed.' : 'Password updated successfully. Redirecting you to sign in...' }}</p>
    @elseif($step === 'invalid')
        <p>{{ $reactivation ? 'Contact your Administrator to review this reactivation request, or return to Sign In.' : 'Follow the message above. You can request another recovery link or return to Sign In.' }}</p>
    @elseif($step === 'verify')
        @if($signedIn ?? false)
            <p role="alert">This browser has an existing AssocMap sign-in session. To continue recovery, confirm that you want to end that local session. Opening this page does not sign you out.</p>
        @else
            <p>Verify the link from your recovery email to continue. The link can be used once.</p>
        @endif
        <p id="recovery-link-note" role="alert">No usable recovery token is available. Reopen your email link or request a new one.</p>
        <noscript><p>JavaScript is required to securely submit this recovery link.</p></noscript>
        <form id="recovery-verification" method="POST" action="{{ $verificationUrl ?? route('password.verify') }}" autocomplete="off">
            @csrf
            <input type="hidden" name="token_hash" value="" autocomplete="off">
            @if($signedIn ?? false)
                <label class="sign-out-consent" for="recovery-sign-out">
                    <input id="recovery-sign-out" type="checkbox" name="sign_out_current_account" value="1" required>
                    <span>Sign out of the current account in this browser and verify this recovery link.</span>
                </label>
            @endif
            <button type="submit" disabled>{{ ($signedIn ?? false) ? 'Sign out and continue recovery' : 'Verify recovery link' }}</button>
        </form>
    @elseif($step === 'reset')
        <p>Use at least 12 characters, including uppercase and lowercase letters, a number and a symbol. This recovery session expires within 10 minutes.</p>
        <form method="POST" action="{{ $passwordUpdateUrl ?? route('password.update') }}">
            @csrf
            <label for="recovery-password">New password</label>
            <div class="recovery-password-control">
                <input id="recovery-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
                <button id="recovery-password-toggle" type="button" class="recovery-password-toggle" data-password-label="new password" aria-controls="recovery-password" aria-label="Show new password" hidden>
                    <x-icon name="eye">
                        <path data-password-eye-off d="m3 3 18 18" hidden />
                    </x-icon>
                    <span data-password-toggle-text>Show</span>
                </button>
            </div>
            <label for="recovery-password-confirmation">Confirm new password</label>
            <div class="recovery-password-control">
                <input id="recovery-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
                <button id="recovery-password-confirmation-toggle" type="button" class="recovery-password-toggle" data-password-label="confirm password" aria-controls="recovery-password-confirmation" aria-label="Show confirm password" hidden>
                    <x-icon name="eye">
                        <path data-password-eye-off d="m3 3 18 18" hidden />
                    </x-icon>
                    <span data-password-toggle-text>Show</span>
                </button>
            </div>
            <button type="submit">Save new password</button>
        </form>
    @endif
    <p><a id="recovery-sign-in" href="{{ route('login') }}">Go to Sign In</a></p>
    @if(!$reactivation && in_array($step, ['verify', 'reset', 'invalid'], true))<p><a href="{{ route('password.request.edit') }}">Request another recovery link</a></p>@endif
</section></main>
</body>
</html>
