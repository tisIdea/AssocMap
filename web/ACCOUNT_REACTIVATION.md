# Administrator-approved account reactivation (Task 11C)

## Release state

Disabled by default with `SUPABASE_ACCOUNT_REACTIVATION_ENABLED=false`. No environment, shared database, Auth template, or redirect configuration is changed by this implementation. Do not enable before the schema and dedicated-account acceptance checks below are complete.

## Workflow and eligibility

User Management exposes **Reactivate Account** for previously revoked, activated profiles when the feature is enabled. The review page lists the registered identity, unchanged role, and every retained Field Officer designation. Both account and relationship review confirmations are required. The server compares a hash of the current role, registered email, revocation count, and relationships with the reviewed snapshot.

Eligibility requires an inactive profile, an active supported role, an original accepted invitation for the same UUID, role and email, a recorded activation timestamp, recorded revocation, and no unresolved invitation or identity conflict. A timestamp alone is not sufficient. Bootstrap accounts without original invitation evidence require separate policy review; this workflow does not infer or fabricate activation evidence.

Association Accounts require exactly one unchanged active link to one active, unarchived association. Reactivation restores the same holder; representative handover and email changes are excluded. Field Officer assignments, original activation timestamps, role, and historical project/monitoring records are preserved. Inactive/archived designations do not gain operational access.

The Administrator requests one ten-minute email verification. A reference alone grants no authority. The holder submits a recovery `token_hash` by POST, with independent provider verification of the same confirmed identity. Opening a link does not consume it or sign out an existing browser session. Existing local sign-in requires explicit sign-out consent.

The profile remains inactive during verification and password setup. A durable `password_started` claim commits before the non-retryable provider write. After a confirmed password update, global Supabase logout must return 204. The final transaction rechecks approval, Administrator eligibility, identity and relationships, restores the profile with a session cutoff, and records the completion audit. No login session is created. The holder signs in with the new password.

Explicit revocation cancels any pending approval even while the target is inactive. Cancellation does not remove an uncertain-password hold. Concurrent/sequential duplicate requests cannot duplicate verification or password writes. Expired approval may be replaced only when no password write has started. A timeout, failed logout, or completion audit failure leaves the account inactive and requires manual reconciliation; automatic password retries are prohibited.

## Session exclusion

`user_profiles.auth_sessions_valid_after` is enforced permanently for reactivated accounts, including when the feature flag is later disabled. Web login, protected web requests, and mobile bearer middleware use it. Web/mobile profile reads use the primary database.

After the exact bearer is verified by Supabase, Laravel checks its subject, issuer, audience, role, expiry, session UUID, and fresh password authentication method. It then requires an `auth.sessions` row for the same UUID with an original `created_at` after the cutoff. A refreshed token's new `iat` cannot promote its old session. Missing claims, missing sessions, old password proof and unavailable session storage fail closed. A same-second sign-in may need to be retried a second later; no clock-skew grace restores old sessions.

Global logout revokes refresh sessions, but JWT expiry alone is not the application boundary. The database session check follows [Supabase's session guidance](https://supabase.com/docs/guides/auth/sessions); [sign-out documentation](https://supabase.com/docs/reference/javascript/auth-signout) describes refresh-token revocation and access-token lifetime.

This protects Laravel web/API access. It does not add policies to unrelated Supabase products. The current mobile application uses Laravel for domain authorization. Do not remove the cutoff column or deploy an older application build after reactivation has been used.

## Schema and provider prerequisites

- Forward-only SQL: `supabase/migrations/020_add_account_reactivation.sql`.
- Laravel wrapper: `database/migrations/2026_10_22_000000_add_account_reactivation.php` executes that SQL on PostgreSQL; its SQLite branch is for isolated tests only.
- Adds the nullable profile cutoff and one approval table with a unique user/reference, participant foreign keys, bounded state, expiry and cancellation. Approval RLS blocks `anon` and `authenticated` access.
- No shared migration has been run. Review the SQL and the existing deployment migration order before owner-approved application. Do not run the entire historical migration set blindly.
- Apply the SQL in one transaction, as the Laravel PostgreSQL migration does; do not apply the column and table changes independently.
- The Laravel server database role must be able to read `auth.users` and `auth.sessions(id,user_id,created_at)`. Do not grant these reads to public/mobile roles. Request initiation probes this server-side session-read capability before sending email; a missing table, privilege or configuration fails closed.
- Persistent encrypted recovery context storage and session blocking reuse the existing invitation/recovery setup.
- Recovery email must preserve `{{ .RedirectTo }}` and supply `#token_hash={{ .TokenHash }}`. Ordinary `/password-recovery`, Task 11A `/invitation/restart/{reference}`, and `/account-reactivation/{reference}` must all retain their respective destinations. Do not change a shared template without testing all three workflows.
- Add the owner-approved origin's `/account-reactivation/*` callback pattern to the Supabase redirect allowlist when staging acceptance is authorized. `redirect_to` is generated only from configured `APP_URL`; visitors cannot choose the origin. No provider configuration was changed here.

## Audit and confidentiality

Request, verification, password-write claim, and completion use the existing required audit writer. The actor is the initiating Administrator, whose authority is rechecked; the target UUID is recorded separately. The viewer permits only target UUID, approving Administrator UUID, and fixed outcome. Cancellation is included in the existing revocation event. No password, token hash, access/refresh token, recovery reference, callback URL or provider response is logged or shown to Administrators.

Audit failures roll back their database phase. External password/logout operations cannot be rolled back, so failure after those operations preserves the durable hold and inactive profile. Reconciliation must establish provider outcome and current authorization before any separately reviewed repair; do not clear the hold or toggle `is_active` as a shortcut.

## Dedicated staging acceptance

1. Review/apply only the required forward migration in an approved deployment window, confirm server-only Auth table visibility, persistent context storage, redirect allowlist and shared recovery email behavior. Enable the flag only through an explicitly approved configuration change.
2. Use dedicated activated Field Officer and Association Account identities. Revoke them and retain pre-revocation web cookies, access JWTs and refresh tokens privately for testing.
3. Confirm unauthorized/self/never-activated/pending/conflicting accounts are refused. Review every officer designation before sending. Verify stale review and changed relationships invalidate approval.
4. Complete email proof and new password setup. Confirm UUID, role, links, original activation time and history remain unchanged; only operational associations regain access. Confirm the holder must sign in afresh.
5. Exercise old cookies, old JWTs, retained refresh tokens and any newly refreshed old-session JWT against protected web and mobile endpoints. All must fail. Confirm a genuinely fresh password session succeeds on both boundaries.
6. In a disposable PostgreSQL database, race duplicate requests, verification and password submissions; test revocation/cancellation, assignment/lifecycle changes, stale original invitation completion, role disablement and required audit failures. Association Account reactivation locks its association before its profile, matching invitation activation; Field Officer assignment paths retain profile-before-association ordering. Confirm no duplicate provider write, unsafe restoration, deadlock-related partial state or lost audit.
7. Test expired/replayed links, wrong UUID/email proof, an existing browser account, browser fragment scrubbing, token-free URLs after POST, and clean success/error feedback. Repeat ordinary Forgot Password and Task 11A on the same shared template.
8. Keep the two separately reproduced Association Account dashboard test failures tracked independently; Task 11C does not change their project links or shortcut expectations.

Provider operations in automated tests are mocked. SQLite cannot establish native PostgreSQL concurrency, RLS, or hosted Auth behavior. Physical-device and browser acceptance remain required.
