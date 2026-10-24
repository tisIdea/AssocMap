# Task 11A: clean interrupted invitation recovery

Scope: `C:\dev\AssocMap`, branch `acc-mng`. This implementation only resumes an email-confirmed, inactive, never-activated invitation with a clean `sent` operation. It does not reactivate revoked accounts, change email, hand over representatives, clear uncertainty markers, or create Auth identities. No migration is required.

## Manual provider prerequisite — not performed

The application setting `SUPABASE_INVITATION_RESTART_ENABLED` defaults to false. Keep it false until the owner reviews the shared configuration and performs separately authorized acceptance. No `.env`, Supabase setting, server, tunnel, or account was changed during implementation.

Inspect the **Reset Password** email template. Its action must preserve the server-selected destination and submit the token hash through the application's explicit verification form. A compatible action link is:

```html
<a href="{{ .RedirectTo }}#token_hash={{ .TokenHash }}">Verify and continue</a>
```

This same link supports ordinary Forgot Password, whose destination remains `/password-recovery`. Retain existing template branding and use wording suitable for both password reset and completing an invitation. Do not replace the separate Invite User template. A hard-coded password-recovery destination or the default provider-consuming confirmation link does not satisfy the new callback contract.

Review Auth URL Configuration and retain existing approved entries. For staging, the required destinations are:

- `https://staging.assocmap.app/password-recovery` (ordinary recovery; unchanged)
- `https://staging.assocmap.app/invitation/restart/*` (one generated 64-character reference segment)

Use the narrow path pattern, not a wildcard host or unrestricted application redirect. The server constructs every destination; callers cannot supply one. Verify that email scanning/tracking does not consume or rewrite proof. Confirm SMTP, applicable Auth hooks/CAPTCHA and rate limits with an authorized test identity. Do not enable the application switch merely because a provider request returned success.

Provider references checked during implementation:

- [Password recovery](https://supabase.com/docs/reference/javascript/auth-resetpasswordforemail)
- [Email templates](https://supabase.com/docs/guides/auth/auth-email-templates)
- [Redirect allowlists](https://supabase.com/docs/guides/auth/redirect-urls)
- [Upstream recovery handler](https://github.com/supabase/auth/blob/master/internal/api/recover.go)
- [Upstream verification handler](https://github.com/supabase/auth/blob/master/internal/api/verify.go)

Public documentation/upstream code supports recovery of an existing confirmed identity; it does not verify the deployed project's configuration or mail delivery.

## Flow and security

`GET/POST /invitation/restart` requests fresh email proof with generic public feedback. A strict administrative lookup checks the original provider UUID/email and confirmed invitation identity. An encrypted, ten-minute cache record holds the original invitation, UUID, email, role and association snapshot. The record reference is not authentication proof and is never a user/association selector.

`GET /invitation/restart/{reference}` only renders a form. The existing inline initializer scrubs the token fragment. `POST /invitation/restart/{reference}/verify` requires CSRF, sign-out consent when applicable, an unconsumed record, provider `type=recovery` verification, independent confirmed identity retrieval and exact local binding/eligibility rechecks. Consumption is persisted under a cache lock before verification; a timeout cannot replay it. Opening email on another browser is supported; the resulting activation context is bound to the verifying browser session.

Successful proof returns to the existing password form, atomic password claim and transactional activation. A surviving password-confirmed context can retry only the local transaction. Uncertain password writes remain blocked for Administrator reconciliation. Revocation, disabled roles, unavailable associations and conflicting links deny continuation. Ordinary Forgot Password stays active-account-only. Restart and Forgot Password share email/IP send limits.

Recovery audit outcomes distinguish request, provider acceptance/uncertainty, verification, denial/hold and activation completion. The dedicated fixed-event writer accepts no request payload or arbitrary snapshot fields. It does not weaken `AuditLogService`'s active-actor rule. Activation completion audit commits in the existing activation transaction; failure rolls back profile/link/acceptance together.

## Controlled staging acceptance — pending separate authorization

1. Review the template, narrow redirect entry, persistent cache/session locks and application switch. No service restart or provider modification is authorized by this document.
2. Use dedicated eligible Field Officer and Association Account fixtures: original invitation verified, profile inactive and never activated, clean `sent` operation, no revocation, active role and eligible association without a competing link. Do not alter real organizational accounts for testing.
3. Request recovery through the UI; compare generic feedback with an unknown email. Open the email in a different browser. Confirm GET performs no activation and explicit verification reaches password setup without a normal login.
4. Complete activation. Check unchanged UUID, invitation, role and association, exactly one expected initial account link, correct audit outcomes and normal sign-in afterward. Verify mobile eligibility only after activation.
5. Repeat the callback and password submissions; no duplicate password write, link or completion event is permitted. Check expiry and signed-in-browser consent.
6. Exercise rejection/uncertainty/rollback cases in an approved isolated environment. Do not stop shared services to simulate failures. Never clear a password marker to make the test pass.
7. Recheck ordinary Forgot Password, invitation/resend, web/API access, genuine email rendering and mobile login. Record results without credentials, bearer values or private records.

Automated tests use synthetic SQLite/provider/cache fixtures. Native PostgreSQL multiprocess locking, real mail, browser behavior and physical-device acceptance require separate verification. Local build output must stay outside `web/public/build` so served assets remain untouched.

## Local verification completed

- Laravel regression selection: **667 tests, 5,311 assertions, no failures**. Coverage includes restart, existing invitation activation/provider/resend/workflow, ordinary password recovery, staff/mobile identity and authentication, user management, report authorization, and environment isolation.
- Frontend selection: **37 tests passed**, covering invitation activation/restart, ordinary password recovery and user management.
- PHP syntax: all 11 affected production PHP files passed. `git diff --check` passed.
- Vite production build: **passed**, 33 modules transformed. An empty environment directory and separate temporary output were used; the application's `.env` was not loaded and served assets were not rebuilt. The SHA-256 of `web/public/build/manifest.json` remained `B097FB3BAC3C63C78EA75D216C921BEE22A12FD0B6F6E581493126EC2C927E5D`.

The regression run exposed existing test-fixture defects: a catch-all HTTP fake masked the intended failed recovery response, and a malformed synthetic bearer token could not reach the revoked-account authorization check. Only those fixtures were corrected; production recovery and authorization checks were retained.

The new tests exercise cache-lock contention with the isolated array store and competing contexts against SQLite. They do not establish native PostgreSQL multiprocess concurrency or deployed cache behavior. No shared Supabase database, live Auth operation, email, migration, deployment or server restart was used.

This local runbook is excluded by the repository's existing `/docs` ignore rule. It is not part of the Git-visible change set unless separately included during owner review.
