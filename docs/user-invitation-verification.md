# User invitation verification procedure

## Status

Source changes are prepared; no commands or tests were executed by Codex. The previously reported isolation result (10 tests / 47 assertions) predates these changes. The previous combined result (127 tests / 1040 assertions, one error and one failure) was not a passing result. All changed and new tests must be rerun by the operator.

Supabase settings, applied database schema, SMTP delivery, tunnel availability, cookies, assets and end-to-end behavior remain unverified. Applicant-photo and Storage work remains paused.

## Local test prerequisites

Use an uncached, sanitized copy of the current `web` source and installed dependencies for the safest local verification. Do not reuse the earlier minimal Storage-only QA workspace. Do not copy `.env`, `.env.testing`, cached configuration, private uploads, production credentials or organizational data. The copy needs the current application, views, configuration templates, routes, tests, `phpunit.xml`, Composer autoload files and installed `vendor` dependencies. Supply only synthetic test configuration locally if needed, including a throwaway Laravel application key. Do not place actual Supabase or SMTP keys in it.

The existing `Tests\TestCase` installs isolation before kernel bootstrapping. PHPUnit forces testing, SQLite `:memory:`, array session/cache/mail and synchronous queues. `TestEnvironmentGuard` refuses cached configuration and unsafe connection definitions. `IsolatedDatabaseManager` rejects remote/non-memory connections, including named connections. Do not disable these guards if they stop execution. Focused Auth tests use synthetic HTTP fakes and block unfaked requests.

PHP needs the project's PHP 8.2-compatible dependencies and PDO SQLite. The workflow fixture is SQLite, not a PostgreSQL schema replica. It does not prove PostgreSQL triggers, partial indexes, row locking or real concurrency.

From the selected testing copy's `web` directory, execute these commands personally:

```powershell
php -l app/Exceptions/InvitationActivationUnavailableException.php
php -l app/Http/Controllers/Admin/UserInvitationController.php
php -l app/Http/Controllers/Auth/InvitationActivationController.php
php -l app/Services/SupabaseAuthService.php
php -l app/Services/UserInvitationService.php
php -l routes/web.php
php -l tests/Feature/SupabaseInvitationAuthTest.php
php -l tests/Feature/UserInvitationWorkflowTest.php
php -l tests/Feature/MobileAuthenticationTest.php
php vendor/phpunit/phpunit/phpunit --filter TestEnvironmentIsolationTest
php vendor/phpunit/phpunit/phpunit --filter 'SupabaseInvitationAuthTest|InvitationActivationServiceTest|UserInvitationWorkflowTest|MobileAuthenticationTest'
```

After those pass, rerun the original combined authentication suite with its original selection to catch cross-module regressions. New cases increase the expected count; do not compare against 127 as a required total. Blade rendering is exercised by the workflow tests; PHP lint alone does not validate Blade or JavaScript.

## Dedicated nonproduction integration environment

Real integration must use a separately approved Auth/database environment, synthetic associations and identities, and approved test mailboxes. PHPUnit remains fully mocked and isolated; do not enable network or PostgreSQL access inside its existing guard.

Before creating any invitation, personally confirm the target project/database identity, administrator profile, active roles, baseline organizational schema and invitation schema. Migration `006_create_user_invitations.sql` requires 001–005. It supplies normalized-email validation, allowed statuses, unresolved-email/Auth-identity unique indexes and invitation scope validation. Do not apply migrations automatically. Inspect actual applied schema and permissions separately. Migrations 008/009 are unchanged; this task does not authorize applying them.

For database-backed session/cache, verify the Laravel `sessions`, `cache` and `cache_locks` tables and backend permissions. Migration 007 protects infrastructure tables and expects them to exist already; it does not create them. The test backend must retain protection from direct public/anon/authenticated access to profiles, invitations and infrastructure. Verify current RLS/grants rather than assuming source migrations are deployed.

## Configuration names and HTTPS

Manage configuration personally; never share values for secrets or activation links. No environment files were inspected or changed.

| Setting | Required purpose |
| --- | --- |
| `SUPABASE_URL` | Approved test project's HTTPS origin, without `/rest/v1`, query or fragment. |
| `SUPABASE_PUBLISHABLE_KEY` | That project's public publishable key, or supported legacy anon key. |
| `SUPABASE_SERVICE_ROLE_KEY` | Existing invitation client expects a server-only legacy service-role JWT. Modern `sb_secret_` keys are not supported by this invitation client. Keep separate from Storage configuration. |
| `SUPABASE_INVITATION_REDIRECT_URL` | Exact current HTTPS origin plus `/invitation`, without a query or fragment. |
| `SUPABASE_ACTIVATION_CACHE_STORE` | Persistent shared lock-capable database, Redis or file store. Database is the source default. Array storage is for isolated fixtures only. |
| `APP_URL` | Current HTTPS application origin for the integration environment. |
| `APP_DEBUG` | False when exposing an environment through a public tunnel. |
| `SESSION_DRIVER` | Persistent supported session storage, not array or cookie-only activation storage. |
| `SESSION_DOMAIN` | Host-only cookies are suitable for a temporary hostname; do not set a shared `trycloudflare.com` cookie domain. |
| `SESSION_SECURE_COOKIE` | True for the HTTPS integration environment. |
| `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE` | Preserve HTTP-only cookies and the current `lax` same-site convention. |

`bootstrap/app.php` trusts only loopback proxies (`127.0.0.1`, `::1`) for forwarded scheme/host/port/address. Keep that restriction. No temporary hostname is hardcoded. There is no explicit Laravel trusted-host allowlist in the current bootstrap; verify the tunnel/origin host boundary and plan an exact stable host allowlist before production. Setting `APP_URL` alone is not a host-validation rule.

For a dedicated integration environment only, the operator can start its own tunnel after approval:

```powershell
cloudflared tunnel --url http://127.0.0.1:8000
```

This command is an instruction, not an executed action. Bind the origin to loopback and use only approved synthetic data. A Quick Tunnel exposes the local service, changes hostname on restart, stops with its process and has no uptime guarantee. It is for development/testing. Confirm HTTPS form actions/redirects, secure host-only session cookies, CSRF continuity and no mixed content. Do not broaden proxy trust or disable TLS verification to solve a mismatch.

Reconcile changed configuration/caches locally through your normal approved process. Never clear configuration in a running unrelated environment as part of these test instructions.

## Supabase Auth and Brevo requirements

Laravel calls `POST /auth/v1/invite` after a bounded existing-identity lookup. Supabase Auth owns email generation and sending through its configured SMTP provider or enabled email hook. This source does not send invitation mail through Laravel Mail or call the Brevo API. Provider acceptance is not proof of mailbox delivery.

In the approved test project, personally verify:

1. Site URL uses the current HTTPS application origin, and Redirect URLs includes the exact current `/invitation` URL. Update these before sending when a tunnel hostname changes. Avoid a broad wildcard covering all `trycloudflare.com` hosts.
2. **Invite user** email template constructs the project's fragment-based link:

   ```html
   <a href="{{ .RedirectTo }}#token_hash={{ .TokenHash }}">Activate account</a>
   ```

   `SUPABASE_INVITATION_REDIRECT_URL` must already end in `/invitation`. The recipient page expects Supabase's 56-character hexadecimal invitation token hash (SHA-224) in the fragment. A default `ConfirmationURL` link verifies at Supabase first and returns session fragments; that is a different contract and will not supply the expected token hash. Query-string tokens are deliberately discarded by Laravel. Verify the actual generated format; do not paste the token or email link into chat/logs.
3. Disable email-link tracking/rewriting for these messages where supported. Ensure scanners do not consume the single-use OTP before the explicit verification POST, and links retain their fragment.
4. Confirm Auth invitation expiry, email rate limits, relevant Auth hooks, password settings and test-recipient eligibility. Check that enabled email hooks do not replace the intended SMTP flow. Do not disable email confirmation or weaken password rules to make a test pass.
5. Confirm custom SMTP is actually enabled in Supabase Auth. Privately verify Brevo's supplied host, port, SMTP login and **SMTP key**, not a Brevo API key, plus sender address/name. Verify authenticated sender/domain and transactional-send enablement, quotas and recipient suppression/bounce state. Laravel `MAIL_*` settings do not configure Supabase SMTP.
6. For one approved mailbox, privately inspect sanitized delivery state: accepted, delivered, bounced or suppressed, plus timing. Do not export full email bodies, keys, tokens or private recipient logs. Acceptance and delivery are separate checkpoints.

Current configuration and actual delivery are unverified. No real recipient is authorized by this document automatically.

## Manual end-to-end acceptance checklist

1. Complete local syntax/focused tests first. In the dedicated integration environment, build the current frontend yourself when ready (`npm run build`), then serve its built assets. Verify no Vite development `hot` reference sends a remote recipient to their own localhost or insecure assets. No build was executed by Codex.
2. Confirm the database/project identity and current tunnel URL. Sign in as the approved synthetic active System Administrator. Open User Management → Invite User → Open invitation form, or `/admin/user-invitations/create`.
3. Verify required name/email, numeric active role options, Association Account association requirement, Field Officer association clearing, validation feedback, mobile layout, keyboard access and pending-submit feedback. Invalid non-string input must produce validation errors without breaking form rendering.
4. Invite an approved synthetic Field Officer once. Record only the operation UUID/status. Expect provider acceptance, a `sent` invitation and inactive profile. Do not call this delivery or activation success. Repeat the same request: no second email request. Changed role/details for the same unresolved email must fail without overwrite.
5. Confirm actual delivery privately. Open the HTTPS link in a signed-out browser. Its fragment disappears from the address bar. GET must not consume the OTP; click **Verify invitation** to POST it. Confirm no third-party requests or tokens in HTML/network URLs/referrers.
6. Submit a weak password and mismatched confirmation: controlled validation errors. Submit a valid matching password: one recipient-authorized Supabase password update, then atomic Laravel activation. Expect success and a separate normal login; activation must not establish an ordinary browser login session.
7. Verify profile activation and invitation acceptance timestamps. Field Officer activation creates no association link or assignment. Login must redirect to `/field-officer/member-applications` and deny Administrator routes. An officer without assignments may legitimately see an empty workspace.
8. Repeat with a fresh approved synthetic Association Account mailbox and eligible association. Before activation there is no link. After activation the active profile and exactly matching unique association-account link are committed together; login redirects to `/association/member-applications`. Unrelated association access must remain denied.
9. In the isolated environment, cover inactive/archived associations, existing links (including inactive ones), inactive actor/role, forbidden Administrator role invitation, existing Auth identity and another unresolved association invitation. No wrong role, overwritten profile or wrong association may result.
10. Test invalid, expired, reused and cancelled/revoked links using approved synthetic operations. Expect generic denial, no elevation, no inappropriate profile/link writes and no replay. No automatic resend/cancel/expiry-management interface was added.
11. Use the authored mocked tests for failure injection. Independently validate PostgreSQL rollback/concurrency in a separately approved isolated database: same normalized email and competing association invitations cannot result in duplicate accounts/links. SQLite does not verify these properties.
12. Confirm database-only recovery after a confirmed password write. The same browser retains its original expiry; temporary identity-check timeout/503 can be retried, but recovery never repeats the password write. Revoked/mismatched identity still denies. A missing/expired context, uncertain password response or provider-accepted/database-sync failure requires administrative reconciliation, not blind resend or direct SQL activation.
13. Confirm normal logout clears the Laravel session. Provider-wide logout/revocation and automatic browser refresh are not implemented by this task; expired access tokens require normal reauthentication. Record acceptance evidence without secrets before declaring the complete workflow functional.

## Remaining limitations

The existing user register/detail/edit UI remains a placeholder; this task connects invitation creation only. No general invitation management/resend/cancel/reconciliation console was added. Durable invitation state and safe operational logs are preserved; this task does not add transactional Audit Logging Stage B.

Supabase and PostgreSQL cannot share a Laravel transaction. Auth provisioning/password writes may succeed before a local failure. Unknown writes are not retried automatically. Reconciliation must compare the operation and exact synthetic identity; never silently activate, overwrite an account or resend with unverified provider state.

The existing browser `signIn()`/`getUser()` methods retain their prior response/error handling; provider connection failures there can still surface as request errors. Browser automatic refresh/provider-wide logout, production host allowlisting, cache/session deployment and external delivery require separate verification/hardening. No production readiness claim is made.

## Primary references

- [Supabase email templates](https://supabase.com/docs/guides/auth/auth-email-templates): TokenHash/RedirectTo variables, custom verification links and tracking/scanner considerations.
- [Supabase redirect URLs](https://supabase.com/docs/guides/auth/redirect-urls): allowlists and Site URL.
- [Supabase custom SMTP](https://supabase.com/docs/guides/auth/auth-smtp): provider configuration and delivery constraints.
- [Brevo SMTP configuration](https://help.brevo.com/hc/en-us/articles/7924908994450-Send-transactional-emails-using-Brevo-SMTP): SMTP login/key, sender/domain and account settings.
- [Cloudflare Quick Tunnels](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/): temporary-hostname and development limitations.
