<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Verified email change · AssocMap</title>
    <script nonce="{{ request()->attributes->get('activation_nonce') }}">
        (() => {
            const values = new URLSearchParams(location.hash.slice(1)).getAll('token_hash');
            let proof = values.length === 1 && /^[a-fA-F0-9]{56}$/.test(values[0]) ? values[0] : null;
            values.length = 0;
            history.replaceState(null, '', location.pathname);
            document.addEventListener('DOMContentLoaded', () => {
                const form = document.getElementById('email-change-proof');
                if (form) {
                    form.querySelector('button').disabled = proof === null;
                    form.addEventListener('submit', (event) => {
                        if (!proof) { event.preventDefault(); return; }
                        form.elements.token_hash.value = proof;
                        proof = null;
                    });
                } else { proof = null; }
            }, { once: true });
        })();
    </script>
    <style nonce="{{ request()->attributes->get('activation_nonce') }}">
        {!! file_get_contents(resource_path('css/base/foundation.css')) !!}
        * { box-sizing: border-box; } body { margin: 0; background: var(--page-bg); color: var(--text-primary); font: 16px system-ui, sans-serif; }
        main { min-height: 100svh; display: grid; place-items: center; padding: 24px 16px; }
        section { width: min(100%, 480px); padding: 28px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; }
        h1 { font-size: 26px; } p { line-height: 1.6; } label { display: block; margin: 16px 0 8px; }
        input:not([type="checkbox"]) { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; font: inherit; }
        button { width: 100%; padding: 13px; margin-top: 20px; background: var(--primary); color: white; border: 0; border-radius: 7px; font: inherit; cursor: pointer; }
        button:disabled { opacity: .5; cursor: not-allowed; } a { color: var(--primary); }
        :focus-visible { outline: 3px solid var(--primary); outline-offset: 3px; }
    </style>
</head>
<body><main><section aria-labelledby="email-change-title">
    <h1 id="email-change-title">Verified email change</h1>
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($step === 'verify')
        <p>{{ $mailbox === 'old' ? 'Your Administrator approved an email change for the same holder. Verify this recovery link to begin.' : 'Confirm this mailbox link. Both old and new mailbox links must be confirmed in the same browser used to begin this request.' }}</p>
        <p>Use a private browser window with no existing AssocMap sign-in. Keep that browser session open throughout the change. Opening this page does not consume the link.</p>
        <form id="email-change-proof" method="POST" action="{{ route($mailbox === 'old' ? 'account-email-change.verify-old' : 'account-email-change.confirm', $reference) }}" autocomplete="off">
            @csrf
            <input type="hidden" name="token_hash" value="" autocomplete="off">
            <button type="submit" disabled>Verify Mailbox Link</button>
        </form>
        <noscript><p>JavaScript is required to submit the link securely.</p></noscript>
    @elseif($step === 'old_verified')
        <p>Current mailbox verified. Continue to request Supabase confirmation at both mailboxes for the approved destination: {{ $row->new_email }}.</p>
        <p>This is a single attempt. If delivery is uncertain, contact the Administrator instead of repeating it.</p>
        <form method="POST" action="{{ route('account-email-change.begin', $reference) }}">@csrf
            <label><input type="checkbox" name="confirm_change" value="1" required> I recognize the approved destination and can access both mailboxes.</label>
            <button type="submit">Request Both Mailbox Confirmations</button>
        </form>
    @elseif($step === 'awaiting_confirmations')
        <p role="status">{{ $row->first_confirmation_at ? 'One mailbox confirmed. Confirm the other mailbox link to continue.' : 'Check both mailboxes and confirm each link.' }}</p>
        <p>Open both links in this same browser session. Do not request another change or password recovery while completing this request.</p>
    @elseif($step === 'verified')
        <p>Both mailboxes verified. Privately choose a new password. Access remains held until completion, then you must sign in afresh.</p>
        <p>Use 12–128 characters with uppercase and lowercase letters, a number and a symbol.</p>
        <form method="POST" action="{{ route('account-email-change.complete', $reference) }}">@csrf
            <label for="new-password">New password</label><input id="new-password" name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required>
            <label for="confirm-password">Confirm password</label><input id="confirm-password" name="password_confirmation" type="password" minlength="12" maxlength="128" autocomplete="new-password" required>
            <button type="submit">Set Password and Complete Change</button>
        </form>
    @elseif($step === 'completed')
        <p role="status">Email change completed. Sign in using your verified new email and new password. Previous sessions cannot resume.</p>
    @else
        <p role="alert">This request is unavailable, expired or requires Administrator reconciliation. Do not repeat email or password operations. An unresolved access hold remains enforced even if the feature is disabled.</p>
    @endif
    <p><a href="{{ route('login') }}">Return to Sign In</a></p>
</section></main></body>
</html>
