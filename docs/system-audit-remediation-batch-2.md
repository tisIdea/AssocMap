# System audit remediation: Batch 2

Date: 2026-10-30 (Asia/Manila). Worktree: `C:\dev\AssocMap-STAGING`, branch `feature/member-application-r2`, baseline `a504ebacee746b63dcb05b28d6bd7c2b678be7b4`. The starting worktree was clean. Changes remain uncommitted.

## Confirmed findings and corrections

| Path | Confirmed problem | Correction |
| --- | --- | --- |
| Project finalization — F02/F04 | Finalization loaded/locked the profile before the role, opposite to the shared staff guard and Administrator revocation. It rechecked eligibility and role but accepted a changed session cutoff. | Reuse `ProgramActorService::lock()` inside the existing finalization transaction. It checks current eligibility, role identity and the authenticated cutoff snapshot, with role before profile. Association/project checks, exact confirmation, expected version, permanent finalization, audit atomicity and the existing three-attempt transaction behavior remain unchanged. |
| Officer assignment / association administration — F02 | `FieldOfficerAssignmentService::lockParticipants()` reloaded participant eligibility but did not compare the acting Administrator's cutoff with the original authenticated profile. | Require the original Administrator role and compare the cutoff after the existing locks. Retain sorted role/profile participant locks, target eligibility, assignment snapshots, association locks, idempotency and audits. Association operations that use this guard inherit the correction. Do not prepend the single-actor guard here: that could lock one profile before the remaining participant roles/profiles. |
| Access revocation — F02 | The acting Administrator was reloaded, but a changed cutoff did not invalidate the in-flight revocation request. | Check the original Administrator role and cutoff against the locked current actor. Retain existing exclusive role/profile locking, last-Administrator/self-revocation safeguards, target/link handling, audit transaction and retries. This additional gap was reproduced before being fixed. |
| Reporting — F02 | `ReportScope::actor()` checked fresh eligibility and role but not the authenticated cutoff. Its otherwise-correct scope recheck could therefore release data to a request invalidated during calculation. | Add the same raw cutoff comparison when resolving the actor. Existing entry and pre-release rechecks both use it. Preserve assignment/link/lifecycle scope and Association Account export denial. No locks are held across Python calculation and no reporting contract changes. |
| Geography writes — F02 | Municipality/barangay create, update and deactivate relied on initial HTTP authorization. Specific-area creation locked/rechecked parents but did not recheck the actor. | Use the staff guard plus the existing Administrator-only role rule inside each write transaction, before business persistence and existing parent locks. Pass the trusted authenticated profile to municipality/barangay update/deactivate services through their controllers. Preserve validation, geographic links, duplicate checks, parent eligibility and controller error handling. |

The original authenticated snapshot comes from server-side middleware: `EnsureSupabaseAuthenticated` verifies the provider identity, loads the profile/role using the write connection, checks eligibility and `SupabaseSessionBoundary`, then stores that model in request attributes. Eloquent retains the raw original `auth_sessions_valid_after` value. Mutation guards compare that value with the locked profile; reporting compares it with a fresh write-connection read. Submitted fields, headers or claimed timestamps are not accepted as freshness evidence. The report service leaves its original request actor in place through calculation and final recheck. Provider authentication, password recovery and encryption are unchanged.

## Exact files changed

Production code:

- `web/app/Services/Projects/ProjectClosureService.php`
- `web/app/Services/Associations/FieldOfficerAssignmentService.php`
- `web/app/Services/Auth/UserAccessRevocationService.php`
- `web/app/Services/Reporting/ReportScope.php`
- `web/app/Services/Areas/MunicipalityService.php`
- `web/app/Services/Areas/BarangayService.php`
- `web/app/Services/Areas/AreaService.php`
- `web/app/Http/Controllers/Admin/MunicipalityController.php`
- `web/app/Http/Controllers/Admin/BarangayController.php`

Tests and report:

- `web/tests/Feature/ProjectClosureTest.php`
- `web/tests/Feature/UserManagementTest.php`
- `web/tests/Feature/ReportAuthorizationTest.php`
- `web/tests/Feature/AreaManagementTest.php`
- `docs/system-audit-remediation-batch-2.md`

## Executed verification

PHP 8.4.27 / PHPUnit 11.5.56. Direct PHPUnit runs used the verified `TestEnvironmentGuard` / `IsolatedDatabaseManager`, SQLite `:memory:`, synthetic process-only APP_KEY, temporary storage/views/caches, array services and blocked/faked HTTP. Application `.env` was not loaded. Native test scripts were not executed.

| Run | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| Existing pre-change baseline | 881 | 6,701 | 0 | 0 | 0 |
| New regressions before production fixes | 112 | 561 | 66 | 0 | 0 |
| New regressions after fixes | 112 | 660 | 0 | 0 | 0 |
| Focused existing + new regressions | 993 | 7,361 | 0 | 0 | 0 |
| Broader regression suite | 1,407 | 11,681 | 0 | 0 | 0 |
| Reporting/navigation frontend tests | 62 | — | 0 | 0 | 0 |

Runs overlap. The 66 reproduced failures were 43 geography denials, eight finalization cutoff denials, four finalization query-order cases, seven reporting cutoff/release cases and four administrative cutoff denials. The other new cases established already-correct restrictions and valid unchanged-cutoff behavior.

The focused suite included isolation, project closure, user management, association lifecycle, area management, report authorization, Monitoring transactional authorization and profile/role retrieval. The broader run additionally covered session boundaries, member mutation authorization, Association Account project/program reads, member application/evidence, sidebar labels, setup safety, workspace headers, contextual/workflow/record navigation, contextual reporting and geography reads. Frontend files were `workspace-navigation.test.mjs`, `report-feedback.test.mjs`, `report-analytics.test.mjs` and `reports-workspace.test.mjs`.

New tests cover revoked actors, changed/advanced cutoffs, changed/inactive roles, email holds, unchanged non-null cutoffs, both finalization outcomes and both staff roles. They assert unchanged project/history/audit snapshots on denial, unchanged assignment/revocation business and audit rows, unchanged geography relationships, and rejection of request-supplied cutoff claims. A geography HTTP test changes the cutoff after middleware authentication but before service execution. Report filter-options calculation changes the cutoff before the real pre-release recheck; direct recheck tests cover all three report roles. Existing tests retain assignment-change, target eligibility, self/last-Administrator, scope and audit rollback coverage.

PHP syntax checks passed for all 13 changed PHP files; `git diff --check` passed. Scoped Pint passed for the three geography services and assignment service. Other modified files retain existing full-file Pint findings, with no added fixer categories against HEAD after formatting the newly changed blocks. PHPStan/Psalm is not configured and was not installed or run.

## Concurrency boundary and remaining risks

These are deterministic stale-request/interleaving tests within one process, not concurrent PostgreSQL verification. The finalization tests verify role-query-before-profile-query order; SQLite does not implement PostgreSQL row locking. No database deadlock was executed or claimed resolved under concurrent load.

Finalization now follows role → profile → association → project, preserving row locks and audit commit/rollback. Assignment retains sorted participant roles/profiles before association locks. Revocation retains its existing ordered Administrator locks and target-specific association/invitation/link sequence. This removes the identified finalization inversion, but does not prove that every writer in the system follows a globally deadlock-free order.

Before native acceptance, separately authorize and verify a disposable PostgreSQL target. Exercise finalization versus revocation, cutoff changes and reassignment in both commit orders, observe actual blocking, verify retries/rollback, and assert no business/audit writes on authorization-first denial. Repeat for assignment and geography writes. No disposable database was provisioned or assumed reusable in this task.

Full PostgreSQL report extraction/Python generation and pre-release changes were not executed. Options release and the shared recheck were exercised locally; the unchanged generation path uses the same final recheck after its read-only transaction and Python calculation. Reporting retains a final authorization check rather than locks across computation/network delivery; an authorization change after that check cannot retract a response already being released.

Live Supabase authentication/session behavior, hosted schema/grants/cutoff writers, deployed browser/device behavior and sustained contention remain unverified. F03, F08 and F09 remain outside this batch. This is a correction of the identified F02/F04 paths, not a claim that all authentication flows have been audited.

## Side effects and safety

An in-flight request whose actor cutoff/eligibility changed now receives a denial; a still-authorized request with the same non-null cutoff continues to succeed. Geography writes add actor eligibility reads and transaction-scoped role/profile locks and can wait behind access changes. Their internal update/deactivate methods now require the authenticated profile; all repository call sites were updated. Routes and public API contracts are unchanged. Existing geography services have no audit writer; this patch does not introduce a new geography audit policy.

No new migration, dependency or runtime configuration is required by this code change. Shared deployment prerequisites were not inspected. The Administrator label remains **Registered Members**. Member Application photo storage, R2, Supabase configuration, migrations, GIS classification, mobile code, password recovery and session encryption were untouched. Only synthetic in-memory data and task-owned temporary test artifacts were mutated. No staging migrations, resets, seeders, native provisioning, commits, pushes, merges or deployments were performed. Batch 2 stops here.
