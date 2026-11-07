# System audit remediation — Batch 3

Repository: `C:\dev\AssocMap-STAGING`; branch: `feature/member-application-r2`; starting HEAD: `87665c83400be7c7409a7051b9089fe3f350f3d6`. The worktree was clean before this batch. No commit was created.

## Findings and scope

| Finding | Confirmed application evidence | Result |
| --- | --- | --- |
| F03 | Ordinary recovery changed the provider password and regenerated only its own Laravel session. It did not advance `user_profiles.auth_sessions_valid_after`. If the provider continued accepting an old bearer, the application had no new cutoff to reject it. This is an application-level gap; acceptance of real old provider tokens was not demonstrated. | A confirmed, identity-matched password receipt now precedes a transaction that advances the existing cutoff. Existing web/mobile boundary checks remain unchanged. Provider invalidation and partial remote/local outcomes remain separate acceptance gaps. |
| F09 | Source defaults to database sessions, but the local staging environment file specifies `SESSION_DRIVER=file`, lifetime 120 minutes, and `SESSION_ENCRYPT=false`. No cached config file was present. Browser login retained a Supabase refresh token with no application reader or browser refresh workflow. | New successful logins retain only the required access credential and remove any legacy refresh value from that session. Laravel encryption is tested and documented, but neither its source default nor active configuration was enabled or changed. F09 remains open for staging activation and legacy storage handling. |

Configuration inspection read only an allowlist of non-secret session settings and checked APP_KEY presence/32-byte AES-256 shape without printing the key. This does not establish key entropy, backup quality, actual running-process overrides, deployed configuration, or consistency between workers. `config/app.php` uses AES-256-CBC, APP_KEY, and APP_PREVIOUS_KEYS. Recovery already depends on this encrypter.

## Authentication and credential trace

1. The public recovery request has generic feedback, rate limits and a bounded resend target. Opening the email page does not consume its token. POST verification checks storage before signing out an existing browser account with consent, verifies the single-use recovery hash with Supabase, checks provider UUID/email and local eligibility, and creates an encrypted cache context.
2. `PasswordRecoveryContext` keeps only an opaque capability in the browser session. Its encrypted cache record contains the necessary recovery access token, UUID/email, expiry, phase and session-ID fingerprint. It expires within ten minutes or earlier with provider expiry. It retains no refresh token and creates no regular login. Weak-password validation preserves the verified context.
3. Password submission uses the existing per-identity cache lock, revalidates identity/eligibility, and durably marks the context consumed before one non-retried provider password write. After a matching successful receipt, recovery now locks the current role before the profile, rechecks eligibility/email bindings/holds, and advances the cutoff in a local transaction. A later existing cutoff is retained. The provider request is outside the database transaction.
4. Success clears the consumed recovery capability and rotates the local session/CSRF state. It requires a normal password sign-in. The change does not activate/revoke accounts, change roles or association links, or alter history/audit records. Only the profile cutoff and normal `updated_at` timestamp can change.
5. Browser login retains the access token because protected requests call Supabase identity verification. The stored profile ID/role are session context, not authorization authority: middleware reloads current profile/role. There is no web token-refresh consumer. The removed refresh token therefore served no browser behavior. Pre-existing sessions can retain it until successful login, logout or expiry; old files/backups are not retroactively sanitized.
6. Flutter uses `GoTrueClient` to manage/refresh its session and persists it through `SecureAuthStorage`/`flutter_secure_storage`. Laravel APIs receive only its bearer access token, verify it, resolve local eligibility/association scope, and apply the same cutoff. Mobile refresh credentials are necessary and were not removed or changed.

Account reactivation and verified email handover already combine a confirmed password update, explicit global provider signout, durable workflow phases, and a cutoff before restoring/releasing restricted access. Ordinary recovery does not start with such a restriction/approval. This batch reuses the cutoff boundary without calling account revocation, releasing holds, or adding an unverified global-logout dependency. Existing revocation inactivates a profile and preserves its established lifecycle/audit safeguards; it is not a password-recovery substitute.

`SupabaseSessionBoundary` still requires provider verification of the exact token, a matching subject/issuer/audience/role, expiry, valid session UUID, password AMR proof strictly after the cutoff, and an originating `auth.sessions` row created strictly after the cutoff. Refreshing an old JWT cannot by itself satisfy these requirements. Recovery AMR does not substitute for password sign-in. Same-second logins can require waiting and signing in again, as already indicated by the login error. Native grants and synchronized clocks are deployment prerequisites.

## Failure handling and unresolved conditions

| Outcome | Verified application behavior / limit |
| --- | --- |
| Confirmed matching password receipt and local commit | Cutoff advances without moving backwards; old web/API credentials are rejected by the application even when the fake provider accepts them. Fresh password proof plus a new provider session succeeds. All three web roles were exercised with encryption off/on. |
| Provider denial, redirect, malformed success, wrong UUID, timeout or unavailable response | No cutoff advance, account revocation or success feedback. The consumed capability cannot replay the provider write. A timeout may have changed the remote password; the existing generic feedback recommends trying sign-in once or requesting another link. No remote rollback/retry is attempted. |
| Revocation, disabled role, approved-email change or email hold during the remote write | Completion rechecks and preserves that restriction; it does not restore/release access or report completion. |
| Provider confirms, then the local cutoff transaction fails | No false success, partial local profile change or provider replay. The remote password may already have changed while the cutoff remains unchanged. This distributed-operation gap is explicitly unresolved; provider invalidation cannot be inferred. A new verified recovery attempt can complete the local boundary after service restoration. Do not clear cutoffs or reactivate accounts as a workaround. |
| Invalid/expired/session-mismatched context, weak password, missing account-switch consent or storage failure before verification | Existing checks remain; no new password write. Tests preserve the valid context on validation failure and preserve the current account/link when storage preflight fails. |

No new logs include passwords, tokens, provider bodies or exception contents. Existing fixed recovery diagnostics and generic public errors remain.

Supabase's [signout documentation](https://supabase.com/docs/guides/auth/signout) distinguishes refresh-session revocation from access JWT expiry. Its [session documentation](https://supabase.com/docs/guides/auth/sessions) describes `session_id`/`auth.sessions`. Current upstream [password-update source](https://github.com/supabase/auth/blob/master/internal/models/user.go) logs out other sessions when a current session is available, and all sessions otherwise. Upstream source is not proof of this project's hosted version/configuration. This batch deliberately adds no provider logout call and makes no claim of global provider invalidation.

Separate authorization and disposable identities are required to verify old JWT `/user` acceptance, old refresh rejection, the current recovery session after password change, direct provider/RLS access, password AMR timestamps, fresh session creation, hosted `auth.sessions` read privileges, real failure/timeout outcomes, concurrent recovery/lifecycle writes, and actual browser/device behavior. SQLite does not prove PostgreSQL row-lock or distributed-cache concurrency behavior. A crash between remote success and local commit is not made atomic by this change.

## Actual files changed

Production code:

- `web/app/Http/Controllers/Auth/PasswordRecoveryController.php` — confirmed-reset cutoff transaction and current eligibility recheck.
- `web/app/Http/Controllers/Auth/StaffAuthController.php` — stop retaining unused browser refresh credentials; clear legacy values on successful login.

Tests:

- `web/tests/Feature/PasswordRecoveryTest.php` — canonical cutoff/binding fixture columns, confirmed-reset boundaries for all roles in both encryption modes, fresh-login/old-session cases, provider/local failures, lifecycle changes and encrypted recovery/account-switch cases.
- `web/tests/Feature/StaffAuthenticationCharacterizationTest.php` — assert refresh-token absence and removal of a legacy value while preserving role destinations/session behavior.
- `web/tests/Feature/EncryptedBrowserSessionTest.php` — rerun the 60 staff characterization cases with encryption enabled, plus eight persistence, file-driver, transition, key, tampering and account-switch cases.
- `web/tests/Support/CreatesAccountLifecycleSchema.php` — isolated SQLite fixture equivalents of existing lifecycle schemas 020/021/022, guarded to SQLite `:memory:`.
- `web/tests/Support/CreatesEmailChangeFixture.php`
- `web/tests/Feature/AccountReactivationTest.php`
- `web/tests/Feature/InvitationRestartTest.php`
- `web/tests/Feature/UserInvitationWorkflowTest.php`

The last four files replace their migration-class calls with the test helper so the required lifecycle suite runs without executing migrations. Definitions preserve the previous fixture columns, foreign keys and uniqueness/partial indexes; production SQL and Laravel migrations are untouched. This report is `docs/system-audit-remediation-batch-3.md`.

## Executed verification

PHP 8.4.27 / PHPUnit 11.5.56. Tests used the verified guard, SQLite memory only, synthetic process-only APP_KEY, array services, blocked/faked Laravel HTTP, and task-owned temporary storage/views/caches. Tests did not load application `.env`. The file-session test explicitly selects a separate temporary directory without changing the guarded default driver or application storage.

| Run | Tests | Assertions | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| Initial existing authentication/recovery/mobile baseline | 378 | 3,078 | 0 | 0 | 0 |
| Existing lifecycle baseline after fixture-only migration-call replacement | 176 | 2,906 | 0 | 0 | 0 |
| Regression expectations before production changes | 39 | 459 | 10 | 0 | 0 |
| Focused final recovery/plain and encrypted browser suite | 178 | 2,595 | 0 | 0 | 0 |
| Broader final authentication/account lifecycle/API/isolation/user management suite | 803 | 8,580 | 0 | 0 | 0 |
| Frontend recovery/invitation/user management | 37 | — | 0 | 0 | 0 |
| Flutter authentication/controller/widget contract tests | 64 | — | 0 | 0 | 0 |

Runs overlap and must not be added as unique cases. The ten intentional pre-fix failures comprised seven recovery-cutoff expectations and three retained-refresh expectations. The broader suite includes all 20 authentication/account lifecycle/provider/session/isolation/user-management test classes selected for this batch. Final PHPUnit counts were checked against JUnit, including zero errors/skips. The frontend files were `password-recovery.test.mjs`, `invitation-activation.test.mjs`, and `user-management.test.mjs`.

PHP syntax checks passed for all ten changed PHP files. `git diff --check` passed. The two new PHP files pass scoped Pint; existing files were not reformatted wholesale. No full repository, native PostgreSQL, deployed browser or physical-device acceptance is claimed.

Flutter ran `mobile_m1_auth_test.dart`, `auth_controller_test.dart`, and `auth_widget_test.dart` with existing cached SDK artifacts, mocked provider/API clients, `--no-pub` and suppressed telemetry. The standard launcher could not write its protected SDK cache and stopped before tests; a corrected task-owned writable cache overlay then completed successfully. JSON events confirm 64 visible passed tests, no failed/skipped tests, and successful run completion; hidden loading/setup/teardown events are excluded. No SDK update or dependency installation was performed. This is local mocked contract/widget evidence, not secure-storage acceptance on a physical device or live Supabase acceptance.

## Proposed staging activation — not executed

1. First obtain authorized acceptance with disposable identities for F03 and confirm the existing cutoff column, primary database access to `auth.sessions`, fresh password/session proof, HTTPS cookies, shared cache locks and consistent UTC clocks. This batch makes no schema/grant/provider changes. Verify the actual process configuration, including overrides and config-cache state, without displaying credentials.
2. Schedule a coordinated session transition. Drain in-flight requests and let short-lived recovery/invitation/reactivation/email-change operations finish or expire. Notify users that old browser sessions will need fresh login and interrupted recovery may require a new link. Do not revoke accounts or clear valid lifecycle records to force the transition.
3. Preserve the existing APP_KEY and required previous keys securely and consistently across instances. Do not generate or rotate a key for encryption activation. Independently approve restricted handling/removal of legacy plaintext session files and backups; setting the flag does not encrypt or erase untouched records. Keep lifecycle cache records separate from session-file cleanup.
4. In a separately authorized deployment, set `SESSION_ENCRYPT=true` consistently and use a new `SESSION_COOKIE` name/version to avoid reuse of pre-transition browser IDs. Retain the existing driver, lifetime, secure/httpOnly/sameSite settings and application key. Rebuild the deployment's configuration cache and restart its persistent processes using the normal release procedure. Avoid mixed encrypted/plain workers. None of these actions was performed here.
5. Check newly written session payloads without logging/decrypting credential contents, and exercise login, logout, expiry, CSRF rejection, account switching, recovery and fresh web/mobile access. Verify ciphertext persists across requests/processes and that old plaintext/recovery sessions do not authenticate. Confirm legacy-file/backup handling before declaring F09 complete. Application session lifetime does not extend provider access-token lifetime; browser refresh behavior remains unchanged.

## Rollback considerations

Prefer rolling back application code while keeping encryption enabled, the same key and the transitioned cookie namespace. Laravel's existing framework support can read these encrypted sessions. Do not revert the profile cutoffs or lifecycle security history, and never restore an earlier cutoff to regain old-session access.

If encryption itself must be disabled, use another fresh cookie namespace and an explicitly approved fresh/cleared session store; do not restore an old cookie name, plaintext files or session backups. Tests demonstrate that unread plaintext state can revive when encryption is switched off, whereas encrypted payloads become unreadable in the plain store. Expect fresh login and loss of in-progress browser recovery state. Key loss/change also invalidates encrypted recovery/lifecycle data; it is not a safe rollback technique. Restoring old application code can resume storing refresh credentials, so retain the small credential-minimization change where practical.

Shared Supabase/PostgreSQL records, Auth accounts/passwords, R2 objects, staging session files/cache, active `.env`, APP_KEY and deployed configuration were untouched. No migrations, resets, seeders, cloud mutations, deployment, commit, push, merge or branch change were executed. Routes, role/association permissions, navigation, mobile source, GIS classification and the Administrator label **Registered Members** are unchanged. Only synthetic fixtures and local temporary verification artifacts were written. Batch 3 stops after verification; provider acceptance and encryption activation remain outstanding.
