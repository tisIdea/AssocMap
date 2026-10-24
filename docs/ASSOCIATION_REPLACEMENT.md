# Association Account replacement

## Scope and invariants

- Reuse Administrator User Management revocation, ordinary invitations and activation.
- Revocation and ending the active appointment commit together with required audit events.
- Retain the old profile, Auth UUID, invitation history and relationship row. The replacement receives a distinct identity.
- One active holder per association and one active association appointment per account are enforced by partial unique indexes.
- Pending, processing, sent and failed invitations reserve the association. Uncertain outcomes require reconciliation, not automatic retry.
- An ended appointment cannot be re-enabled or reassigned. Task 11C cannot restore it; a separate appointment remains outside this release.
- Association authorization uses current active links. Existing Field Officer cross-role history restrictions are unchanged. Session-cutoff checks remain independent of lifecycle feature flags.

## Migration ownership and compatibility

The only deployment migration for this change is:

`database/migrations/2026_10_22_010000_support_association_account_replacement.php`

Its PostgreSQL branch executes `../supabase/migrations/021_support_association_account_replacement.sql` inside Laravel's migration transaction. Do not execute both mechanisms separately. Previously applied migrations are unchanged.

SQL 021 adds nullable `ended_at` and `ended_by`, replaces unconditional link uniqueness with active-only indexes, adds unresolved-association invitation uniqueness and protects appointment history. It changes no existing data, table grants, RLS settings or policies. Legacy inactive rows can retain unknown end metadata.

SQL 021 and SQL 022 both set `lock_timeout = '5s'` and `statement_timeout = '60s'` with `SET LOCAL` before their schema operations. Laravel wraps each reviewed PostgreSQL migration in its own transaction, so these limits apply on the connection executing that migration and reset after commit or rollback. Do not rely on `PGOPTIONS` through the Supabase pooler or require session defaults outside the migration transaction to equal these limits.

`tests/Postgres/MigrationTimeoutVerification.php` rehearses the two wrappers in order against a verified disposable PostgreSQL 17.11 instance. It observes the actual DDL backend and transaction, exercises real five-second lock and sixty-second statement failures, checks atomic rollback and retries only in the disposable database. It never loads `.env` or accepts a remote database.

Ordinary readers remain compatible before migration. Revoking an account that still has an active association link fails closed before migration, with no partial profile update. Do not conduct replacement acceptance before coordinated deployment.

## Explicit reconciliation of the two previously identified revoked holders

No shared records have been reconciled automatically.

1. Obtain separate authorization to reconcile each named account in the Administrator UI. Reconfirm its Auth UUID, revoked profile, existing revocation audit, current association and active link through approved read-only inspection.
2. Confirm that ending this appointment is intended, the association has no competing active holder and unresolved invitation/recovery operations have been reviewed. If evidence conflicts, stop.
3. After SQL 021 is deployed and verified, open Manage Access and explicitly confirm **End Retained Appointment**. This reuses the existing revocation endpoint, ends the still-active link even if the profile was already revoked, and records new reconciliation-time audit evidence. It does not require enabling reactivation.
4. Preserve the earlier revocation event. `ended_at` records when this appointment is actually ended; do not backdate it to imply an earlier unlink.
5. Verify the same historical link ID remains, it is inactive, its ending actor/time are recorded, the profile remains revoked and there is no active holder.
6. Only then invite a separately authorized replacement through the existing invitation form. Do not edit historical rows or reuse the former holder's Auth identity.

## Controlled deployment

1. Review the complete coordinated source/migration diff. Keep Task 11A and Task 11C disabled unless separately approved.
2. In a controlled window, pause lifecycle operations by an operator-approved mechanism. Do not restart or change services automatically.
3. Take a fresh public-schema backup and verify archive decoding and restoration in a disposable database. Include migrations, links, invitations, profiles, associations and audits. Public-only backups do not provide full Supabase Auth/Storage recovery.
4. In a read-only preflight, check database identity/version, pending ledger entry, expected schema/trigger definitions, permissions, duplicate active links, duplicate unresolved association invitations, and conflicting transactions. Resolve conflicts separately; the migration will fail rather than alter them.
5. Execute only the wrapper from `C:\dev\AssocMap\web`, after explicit deployment approval:

```powershell
& 'C:\php8427\php.exe' artisan migrate --path=database/migrations/2026_10_22_010000_support_association_account_replacement.php --force
```

6. Verify one ledger entry, nullable ending columns, validated constraints, valid partial indexes, unchanged RLS/grants, unchanged original row counts and unchanged business relationships. No existing link should have ended merely from migration.
7. Perform separately authorized dedicated-account acceptance: revoke, verify old web/mobile denial, invite, verify the mailbox, set a password, activate, verify scoped access and confirm ended-link reactivation denial.
8. Reconcile the two historical cases only under the separate approval above.

## Failure and forward correction

- Lock acquisition is bounded; migration failure must leave schema and ledger unchanged. Inspect the cause before retrying.
- Audit failure rolls back profile, link and invitation activation changes together.
- Once replacement history exists, do not revert to code that assumes one total row per association/account, restore unconditional uniqueness, re-enable ended links, clear password-write uncertainty markers or remove session cutoffs.
- Keep affected operations unavailable and apply a reviewed forward correction. A PostgreSQL transaction cannot undo a provider-created identity or delivered email; reconcile those outcomes without blind resend or deletion.

## Isolated verification

Use PHP 8.4.27, the repository's guarded SQLite `:memory:` configuration, a process-only synthetic APP_KEY and provider fakes. Never run write-capable tests using `.env` database settings.

`tests/Postgres/AssociationReplacementVerification.php` is a standalone synthetic harness. It refuses cached configuration, never loads `.env`, and requires the dedicated local PostgreSQL 17.11 cluster at `127.0.0.1:55439` owned by the synthetic `assocmap_verify` role. It creates uniquely named databases and removes them in `finally`. Its worker processes exercise real production writers concurrently; fake activation receipts do not prove real provider password or email acceptance.

```powershell
& 'C:\php8427\php.exe' tests/Postgres/AssociationReplacementVerification.php
```

An operator must provision and verify that disposable cluster first. The harness never starts, stops or modifies the installed PostgreSQL services or shared Supabase.

## Verification recorded on 22 October 2026

- PHP 8.4.27 core regression: 466 tests, 5,334 assertions, passed.
- Additional Association Management, Audit Log and invitation-auth regression: 233 tests, 2,755 assertions, passed. This includes three offline native-browser submission checks. The sandbox blocked the first browser launch; the unchanged offline test passed outside it.
- PostgreSQL 17.11: 47 checks, zero failures; six synchronized concurrent-writer scenarios passed. Laravel migration failure left no ledger entry and rolled back schema changes.
- Required audit rollback, replacement integration, preserved legacy rows, RLS/grants, active uniqueness and restricted session-query behavior passed on synthetic PostgreSQL data.
- Disposable databases and the dedicated test cluster were removed. Installed PostgreSQL 17/18 services remained running.
- These results do not establish real SMTP, Supabase password/session, browser or Android acceptance against the shared project.

Exact PHPUnit invocation groups, from `web`, with a synthetic process-only key:

```powershell
$previousTestAppKey = [Environment]::GetEnvironmentVariable('APP_KEY', 'Process')
try {
    $env:APP_KEY = 'base64:' + [Convert]::ToBase64String([byte[]](1..32))
    $coreTests = @(
        'tests/Feature/UserManagementTest.php',
        'tests/Feature/UserInvitationWorkflowTest.php',
        'tests/Feature/UserInvitationResendTest.php',
        'tests/Feature/InvitationActivationServiceTest.php',
        'tests/Feature/InvitationRestartTest.php',
        'tests/Feature/AccountReactivationTest.php',
        'tests/Feature/MobileAuthenticationTest.php',
        'tests/Feature/ReportAuthorizationTest.php',
        'tests/Feature/StaffAuthenticationCharacterizationTest.php',
        'tests/Feature/PasswordRecoveryTest.php',
        'tests/Unit/SupabaseSessionBoundaryTest.php'
    )
    & 'C:\php8427\php.exe' vendor/phpunit/phpunit/phpunit --do-not-cache-result --stop-on-failure --stop-on-error @coreTests
    if ($LASTEXITCODE -ne 0) { throw 'Core regression failed.' }
    $additionalTests = @(
        'tests/Feature/AssociationManagementTest.php',
        'tests/Feature/AuditLogAccessTest.php',
        'tests/Feature/SupabaseInvitationAuthTest.php'
    )
    & 'C:\php8427\php.exe' vendor/phpunit/phpunit/phpunit --do-not-cache-result --stop-on-failure --stop-on-error @additionalTests
    if ($LASTEXITCODE -ne 0) { throw 'Additional regression failed.' }
} finally {
    [Environment]::SetEnvironmentVariable('APP_KEY', $previousTestAppKey, 'Process')
}
```

## Coordinated review scope

New files:

- `supabase/migrations/021_support_association_account_replacement.sql`
- `web/database/migrations/2026_10_22_010000_support_association_account_replacement.php`
- `web/ASSOCIATION_REPLACEMENT.md`
- `web/tests/Support/CreatesLifecycleAuditTable.php`
- `web/tests/Postgres/AssociationReplacementVerification.php`
- `web/tests/Postgres/association-replacement-base.sql`

Existing files updated in this step:

- `web/app/Models/Association.php`
- `web/app/Models/AssociationAccount.php`
- `web/app/Models/UserProfile.php`
- `web/app/Services/AccountReactivationService.php`
- `web/app/Services/AuditLogService.php`
- `web/app/Services/InvitationActivationService.php`
- `web/app/Services/MobileAssociationAccessService.php`
- `web/app/Services/Reporting/ReportScope.php`
- `web/app/Services/UserAccessRevocationService.php`
- `web/app/Services/UserInvitationResendService.php`
- `web/app/Services/UserInvitationService.php`
- `web/app/Services/UserManagementQueryService.php`
- `web/resources/views/admin/users/details.blade.php`
- `web/tests/Feature/AccountReactivationTest.php`
- `web/tests/Feature/InvitationActivationServiceTest.php`
- `web/tests/Feature/InvitationRestartTest.php`
- `web/tests/Feature/MobileAuthenticationTest.php`
- `web/tests/Feature/ReportAuthorizationTest.php`
- `web/tests/Feature/UserInvitationResendTest.php`
- `web/tests/Feature/UserInvitationWorkflowTest.php`
- `web/tests/Feature/UserManagementTest.php`

The earlier `web/resources/views/admin/users/register.blade.php` refinement remains unchanged in the working tree and belongs to the coordinated review. The Field Officer assignment service retains its original safeguards. Exclude the unrelated Daily Harvest deletion, Android build report, credentials and generated artifacts.
