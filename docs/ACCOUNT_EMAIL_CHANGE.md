# Administrator-managed verified email change (Task 12)

Status: implemented for isolated verification; disabled by default. No shared migration or provider acceptance is implied.

## Workflow and trust boundaries

Administrator → User Management → account details → Change Email / Review Request. The Administrator enters the normalized destination, confirms the same holder, reviews the role and relationships, then approves. This is not representative replacement. The service rechecks the actor and target from the database in every sensitive transaction.

The existing `email_change_requests` record reserves the identity and destination. `user_profiles.email_change_hold_id` denies application access without changing `is_active`, assignments or association links. This hold remains enforced when the feature is disabled.

1. A recovery email proves ownership of the current mailbox. Its callback is `/account-email-change/{reference}/old`. GET never verifies a token. The browser scrubs the fragment; an explicit CSRF-protected POST submits it.
2. A restricted, encrypted cache context is bound to that browser session and the approved request. No normal login or refresh token is retained. Its lifetime is at most one hour and the provider session lifetime minus 30 seconds.
3. The recipient explicitly starts authenticated `PUT /auth/v1/user` with the approved email. This is **not** an Admin API email update. Under Secure Email Change, the current email must remain unchanged at this point.
4. Both native mailbox confirmation links return to `/account-email-change/{reference}/confirm` in that same browser. POST `/auth/v1/verify` uses `type=email_change`. Supabase's first confirmation returns a message without a session; only the second can yield the expected UUID and verified destination. A single-confirmation configuration fails closed.
5. The holder privately submits a confirmed password: 12–128 characters, mixed case, number and symbol. The shared password-write lock and durable database phase prevent duplicate writes across processes. Provider identity is checked independently.
6. A confirmed password receipt precedes global provider signout. Required completion auditing, `approved_email`, removal of the hold, and the permanent session cutoff commit together. A fresh password login is required. Original invitation emails and all organizational identities remain unchanged.

Nullable `approved_email` preserves existing pre-feature eligibility. Once set by completion, provider-verified identities must match it. Task 11C accepts this approved current binding while retaining the accepted invitation's UUID and role checks. No Flutter change is required: Laravel's shared eligibility check and existing session boundary protect web and mobile, and the mobile client already denies 401/403 responses.

## Durable phases and failure handling

`requested → old_verifying → old_verified → email_started → awaiting_confirmations → confirming → awaiting_confirmations/verified → password_started → password_confirmed → signout_started → completed`.

Every provider operation is preceded by a committed phase claim and required audit. Proofs are irreversibly reserved by a SHA-256 digest in `email_change_proofs`; bearer tokens and passwords never enter these tables or audit snapshots. Only encrypted, expiring cache storage retains a restricted bearer token.

The request expires after one hour. Expiry stops all completion attempts; it does **not** imply that an external operation was undone. Unresolved requests keep `status=pending`, reserving the destination and retaining their hold. The UI reports expiry and the last durable phase. Intermediate started phases are uncertainty markers; do not reset them to permit replay.

Only `requested` can be cancelled from the Administrator UI, with a required audit, because no proof or email/password write has begun. Later interruption, lost browser state, provider timeout, partial verification, failed required audit, role/link drift, or revocation requires separately authorized reconciliation. Do not repeat password operations, assume a DB rollback undoes Auth, clear the hold, restore an old cutoff, or delete the request. Revocation still denies access and may end an appointment under its existing policy; email change never restores one. Reactivation is blocked by the unresolved hold.

Reconciliation must inspect safe request/audit metadata and the provider's current UUID/email, establish the last *confirmed* operation and current holder/appointment authorization, then use a separately reviewed forward correction. Missing proof or uncertain password/signout is not evidence of completion. Keep access denied until an approved resolution exists. This implementation intentionally offers no force-complete, retry-password, privileged-email-override or old-mailbox bypass button.

## Provider prerequisites (manual, before any enabling)

Operator-confirmed settings: Email enabled; Secure Email Change enabled; Secure Password Change disabled; require-current-password disabled; OTP expiry 3,600 seconds. Preserve Secure Email Change. The app enforces its stronger password policy independently of the provider's six-character minimum.

- Keep Reset Password template `{{ .RedirectTo }}#token_hash={{ .TokenHash }}` unchanged. It supports the initial current-mailbox recovery callback as well as existing Forgot Password, Task 11A and Task 11C.
- Configure **Change Email Address**, a different Supabase template, to link to `{{ .RedirectTo }}#token_hash={{ .TokenHash }}` for each mailbox. Do not use the provider's GET-consuming `ConfirmationURL` for this application flow. Confirm the hosted provider emits both messages with the correct per-mailbox hash.
- Add `https://staging.assocmap.app/account-email-change/**` to the redirect allowlist; this covers two path components after the prefix. For narrower patterns, allow `/account-email-change/*/old` and `/account-email-change/*/confirm`. Preserve existing invitation, password recovery, restart and reactivation entries.
- `APP_URL` must be the intended HTTPS origin, with no credentials, path, query or fragment. The app generates callbacks; visitors cannot supply them.
- Verify Resend sender/domain authorization, SMTP delivery to both dedicated mailboxes, provider mail/rate limits and secure-session storage before enabling. No current delivery claim is made.
- If the old mailbox is inaccessible, stop. A separate approved account-recovery policy is required; an immediate Admin API email update does not prove ownership.

Provider contracts were checked against official Supabase docs and upstream Auth source; deployed provider/version and templates still need acceptance:
- https://supabase.com/docs/reference/javascript/auth-updateuser
- https://supabase.com/docs/reference/javascript/auth-admin-updateuserbyid
- https://supabase.com/docs/guides/auth/auth-email-templates
- https://github.com/supabase/auth/blob/master/internal/api/verify.go
- https://github.com/supabase/auth/blob/master/internal/api/mail.go

## Shared deployment prerequisites (not executed)

1. Review the combined uncommitted lifecycle changes. Preserve the unrelated Daily Harvest deletion and Android generated report. Obtain a fresh local backup and use the already verified restore procedure; public-only backups do not fully recover Supabase Auth or Storage.
2. Migration 020 must be present. Review and deploy the separately approved association replacement migration 021 before relying on its appointment safeguards. This task does not change 021.
3. Read-only preflight: verify database/project identity, UTC connection timezone, original counts, backend DDL/audit privileges, and `auth.users` SELECT permissions on `id`, `email`, **`email_change`**. Session enforcement continues to require SELECT on `auth.sessions(id,user_id,created_at)` and schema USAGE, without private session fields.
4. Count duplicate normalized pending destinations, unsupported legacy requests, schema drift, and unexpected grants/policies before 022. Do not repair existing rows automatically. The unique index deliberately rejects conflicting legacy reservations.
5. In a controlled window, execute **only the Laravel wrapper** for this change:

   `C:\php8427\php.exe artisan migrate --path=database/migrations/2026_10_22_020000_support_verified_email_change.php --force`

   It executes `../supabase/migrations/022_support_verified_email_change.sql` transactionally. Do not apply the SQL separately. The SQL sets transaction-local `lock_timeout` to 5 seconds and `statement_timeout` to 60 seconds per statement; both reset automatically when the migration transaction ends. A timeout aborts the transaction, rolling back the migration's schema changes. Laravel writes its ledger after the schema transaction; if ledger insertion alone fails, inspect schema and ledger before a reviewed rerun. The SQL is idempotent, as verified locally. ALTER TABLE and unique-index creation take locks; avoid concurrent deployment activity.
6. Read-only postchecks: one ledger entry; new columns nullable for existing profiles; existing counts/links unchanged; RLS and no client grants/policies on both email tables; valid indexes/FKs/CHECKs; no holds or approvals unexpectedly issued. Confirm ordinary login, recovery and Task 11A/11C remain compatible while disabled.
7. Only with separate approval, configure `SUPABASE_ACCOUNT_EMAIL_CHANGE_ENABLED=true`, using the deployment's established configuration reload procedure. No such enabling or reload occurred here. Task 11A/11C flags are independent and should retain their intended values.
8. Disabling the flag blocks new and existing email-change operations. It does not clear holds, approved bindings or session cutoffs. Do not roll these security values back to restore old-token access. Use reviewed forward corrections.

## Acceptance still required (shared writes; not run)

Use dedicated test identities/mailboxes only, with explicitly approved scope and cleanup:

- Same-holder Field Officer, Association Account and second-Administrator changes, preserving UUID/role/history/links.
- Original-mailbox recovery → explicit start → both email confirmations, in both possible orders. A partial confirmation grants no application access.
- Resend delivery, link prefetch, same-browser requirement, expiry, duplicate click, wrong UUID/email and copied reference/context.
- Mandatory private strong password, real old-password rejection, new-email/new-password login, old-email behavior, global refresh revocation.
- Old web/mobile bearer and refreshed tokens denied during the hold and after cutoff; fresh password sessions work; existing association remains unchanged.
- Task 11C revoke/reactivate after a completed email change recognizes the new approved email without rewriting invitation history. A holder with no authorized current appointment remains blocked.
- Expiry, delivery uncertainty and interrupted browser leave access held; only an unstarted request can be cancelled. Reconciliation procedures need explicit operator review before any real correction.
- Existing ordinary Forgot Password, invitation activation and Task 11A callbacks remain compatible with the shared Reset Password template.

Do not claim real password rejection, mailbox delivery, hosted provider semantics or device acceptance from HTTP fakes.

## Isolated verification

SQLite testing uses `Tests\Support\TestEnvironmentGuard` / `IsolatedDatabaseManager`, SQLite `:memory:`, array mail/cache/session, a synthetic process-only APP_KEY and blocked/faked HTTP. It never loads `.env`.

Native runner: `C:\php8427\php.exe tests/Postgres/EmailChangeVerification.php`. It only accepts PostgreSQL 17.11 at `127.0.0.1:55439`, role `assocmap_verify`, and unique `assocmap_emailchange_<12 hex>` databases. It refuses cached config and does not load `.env`. Its separate temporary cluster must use UTC connections. No installed service is restarted. Worker requests use synthetic verified contexts to exercise production phase claims; mailbox verification itself is covered by the HTTP-faked feature tests and still requires live acceptance.

The native suite verifies wrapper atomicity/idempotency, constraints/FKs/indexes, RLS/client denial, restricted session columns, request/final audit rollback, successful completion and six synchronized concurrency scenarios. Disposable databases are removed in `finally`; stop/remove the separately created temporary cluster after testing.
