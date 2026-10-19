# Local PostgreSQL migration test report

Run date: 2026-10-16. Workspace: `C:\dev\AssocMap`. Scope: the separately authorized autonomous local test of migrations 011–014, permanent project finalization, historical integrity, Production Monitoring and the GIS maintenance exception.

**Result: all completed local checks pass. Deployment decision: READY FOR FURTHER VERIFICATION.** PostgreSQL 17.6, the exact Supabase role/RLS configuration, real Auth behavior and browser workflows still require verification. No cloud connection or deployment was performed. The [manual Supabase handoff](supabase-finalization-deployment-guide.md) contains the pre/post SQL, file hashes, backup requirements and failure handling. [Machine-readable evidence](testing/local-postgresql-2026-10-16-results.json) records each final check, catalogs, versions and migration hashes.

## A. Testing environment and isolation

| Item | Verified value |
| --- | --- |
| Database | `assocmap_gis_test_20261016_68a9d0e7` |
| Server endpoint | `127.0.0.1:58662`, private standalone PostgreSQL instance |
| PostgreSQL | 18.1, Windows x86-64; installed binaries under `C:\Program Files\PostgreSQL\18\bin` |
| PostGIS | 3.6.2; actual native geometry calculations and catalog queries executed |
| Other extension | pgcrypto 1.4; extensions installed in `extensions` from actual baseline source |
| Locale / encoding | builtin C.UTF-8; UTF8; database datcollate/datctype are C |
| PHP / PHPUnit | PHP 8.2.12; PHPUnit 11.5.56; existing installed dependencies only |
| Test owner | `assocmap_test_owner`; private-cluster bootstrap superuser; additional non-superuser/RLS and client-role scenarios tested separately |
| Retained files | `C:\dev\.codex-tmp\assocmap-pg-20261016_68a9d0e7` |

Before the first SQL connection, a unique run/data directory and unused loopback port were selected and a new private cluster initialized. The new cluster contained only its system databases before the uniquely named test database was created. Server readiness/PID, data directory, explicit host/port/database/user and SQL-reported address/port/data directory were cross-checked before schema or fixture writes. Inherited PG connection settings were removed; every connection used explicit local parameters. The test scripts reject another manifest, server, data directory or database. The SQL regression also requires exact expected database/port and an explicit synthetic-data marker.

The existing `postgresql-x64-18` Windows service was already running; it was never connected to or altered. The private server was started directly with installed `postgres.exe` using PostgreSQL's [documented server launch](https://www.postgresql.org/docs/18/server-start.html), as a hidden unprivileged process, after `pg_ctl start` encountered Windows restricted-process errors. No administrative escalation, execution-policy change, service configuration or installation was used. Only newly generated scratch-cluster authentication files were used; their contents are excluded from reports. No project `.env`, existing database credentials, live Supabase, backup restoration, invitations, real business records, commits/pushes or deployment were involved. The retained database was not dropped or reset. The private server was cleanly stopped after evidence collection; its data directory remains available.

### Accurate prerequisites

The disposable database used actual AssocMap domain migrations 001–009, not a rewritten schema. 004 ran against empty Monitoring prerequisites before synthetic fixtures were loaded. Actual framework migration `up()` methods created users/session/cache/job/token tables and the Program Activity status adjustment; the real framework migration repository was also created. This was done with explicitly configured Capsule connections, without Artisan or loading project configuration. 007 then secured the existing framework infrastructure. A minimal synthetic `auth.users(id UUID PRIMARY KEY)` supplied only the Supabase FK dependency; it does not reproduce Supabase Auth.

Before 011, fixtures supplied active/inactive/archived associations, Admin/assigned and unassigned Field Officers/Association Account/inactive actors, all project lifecycle cases, a legacy completed project with no closure columns yet, native GIS geometry, and every protected child register. New concurrency fixtures were added only inside the same disposable database. Tests did not import real records.

## B. Actual migration results

| Migration | Result | Execution and assertions | SQL correction / retest |
| --- | --- | --- | --- |
| 011 `add_permanent_project_closure` | PASS | Actual source committed; nullable TIMESTAMPTZ/TEXT/UUID evidence columns; actor FK SET NULL; metadata CHECK; registered parent trigger; valid completion accepted in a rolled-back stage test; legacy completed rows retained all-null evidence | No migration error or SQL change |
| 012 `allow_zero_production_activities` | PASS | Actual source committed; named CHECK permits zero; a real zero-activity update passed and rolled back | Source unchanged; no migration error |
| 013 `add_permanent_project_finalization` | PASS | Actual source committed; six statuses including distinct terminated; amended evidence CHECK and parent guard; valid termination accepted in a rolled-back stage test | No migration error or SQL change |
| 014 `protect_finalized_project_history` | PASS | Actual source committed; two private SECURITY DEFINER functions; owner preflight; 12 enabled AFTER ROW INSERT/UPDATE/DELETE triggers; private ACLs and fixed function configuration | No migration error or SQL change |

All public-table record snapshots matched before/after **each** pending migration, excluding only the three newly added null project evidence fields. Common comparison SHA-256: `0c5f36a972e7509526305a5ba74c0f7df4662220d3920bcc652bf9efb54caf24`. The legacy completed record stayed completed with null metadata. Migration source hashes were checked again against executed results at collection time. 010 remains withdrawn/comments only; it was not executed. No pending migration was rewritten or replayed to force success.

### Failures diagnosed during setup and testing

These were environment/test-harness issues, not defects in 011–014:

- Windows rejected libc locale `C.UTF-8`; initialization succeeded using builtin C.UTF-8 with libc C metadata. Windows restricted-process startup errors (87/3) were avoided using the documented direct server launch. PowerShell blocked a script file under its existing execution policy; inline commands were used without changing policy.
- First 007 setup attempt lacked `public.sessions` (`42P01`); actual framework prerequisites were created on the new database, then 007 succeeded. This did not touch or repair an existing application database.
- The first focused PHPUnit run had one error: `getBag()` on null in `ProjectClosureTest::test_termination_validation_uses_only_its_own_modal_error_bag`. Its GET consumed flash-session errors before the assertion. Moving error-bag assertions before that GET fixed only the test; all 168 focused tests then passed.
- Initial matrix expectations omitted PostgreSQL RESTRICT SQLSTATE `23001`, and a reassignment fixture collided with an unrelated unique product-name constraint. Valid ongoing controls and corrected fixtures/expected rejection codes now isolate the intended protections; separate bare-parent deletion tests independently prove the lifecycle trigger returns `23514`.
- Native connection harnesses needed an explicitly quoted empty libpq `options` and an explicit async passfile. The deadlock harness needed separate SET statements (`42601` on a prepared multi-command statement). Corrected harnesses passed; application connection code was unchanged.
- The non-superuser RLS fixture first needed the genuine extension USAGE and consistent test-table ownership to reach the intended RLS assertion. Grants/ownership were confined to rollback-only synthetic scenarios. FORCE RLS still failed closed with `42501`; no protection was relaxed.
- Evidence collection used pg_database locale metadata after PostgreSQL 18 rejected `current_setting('lc_ctype')` (`42704`). The handoff uses the portable catalog form and all 26 read-only queries passed locally.

Initial failure logs remain in the scratch directory; the repository evidence contains final results. No genuine pending-migration defect was found in the exercised cases.

## C. Functional results

| Area | Actual result |
| --- | --- |
| Completion / Termination | Actual ProjectClosureService completed an ongoing project as Admin and terminated another as its assigned Field Officer; outcome, remarks, actor/time and exactly one required audit were retained |
| Invalid transitions / actors | Planned/suspended/cancelled, archived project, inactive/archived association, unassigned/account/inactive actors, repeated/opposite finalization and profile-changing finalization were rejected |
| Remarks | Both final CHECK outcomes rejected 73 invalid samples each: null/empty/whitespace, 2,001 characters, ASCII/C1 controls, zero-width/direction/BOM/tag characters, fillers/variation/private-use-only strings. Visible text, 2,000 characters, non-ASCII Ñ and visible multiline remarks passed |
| Irreversibility | Final status cannot reopen; profile, evidence and project/association links remain read-only; deletion rejected even on a finalized parent with no dependent child rows |
| Legacy completed | No fabricated actor/date/remarks; operational mutation/deletion protection and authorized GIS exception still apply |
| Operational registers | Native INSERT/UPDATE/DELETE/reassignment matrix across all 11 operational tables for ongoing, completed, terminated and legacy completed cases; nullable training/activity detachment tested. Ongoing controls succeeded; any old/new finalized ancestry was denied |
| Association-wide activity | Unlinked Program Activity CRUD remained operational; ongoing link/detach allowed; old/new finalized links denied |
| GIS maintenance | Actual services with native PostGIS allowed Admin and assigned Field Officer creation/idempotent retry, name/coordinate correction, publish/unpublish and archive after either outcome. Project/evidence/operational snapshots remained unchanged |
| GIS restrictions | Finalized deletion, parent reassignment/detachment, unassigned/account/stale-version operations and ineligible association denied; eligible ongoing GIS deletion succeeded with actual schema preflight and audit |
| Production Monitoring | Focused Laravel calculation/workflow tests passed. Native service saved zero-activity months, calculated `50.50` from monthly quantities, ignored the submitted total, retained monthly IDs, Income and omitted legacy Production, rejected stale revision with 409 and denied final-project saves |

The 11 operational tables are project_products, project_materials, trainings, training_participants, program_activity_records, monitoring_records, monitoring_production, monitoring_production_monthly, monitoring_income, monitoring_materials and monitoring_visit_reports. GIS is the twelfth history-trigger table with its approved exception. These tests do not authorize general operational writes on inactive/archived associations or expand historical read permissions.

### Executed automated totals

| Suite | Final result |
| --- | --- |
| Native functional checks | 61 passed, 0 failed |
| Native concurrency schedules | 17 passed, 0 failed |
| Native security/FK/audit checks | 14 passed, 0 failed |
| Existing SQL regression source | PASS; READ COMMITTED and REPEATABLE READ sections; full public-record snapshots unchanged |
| Focused Laravel regressions | 168 tests, 1,216 assertions; 0 errors/failures/skips |
| Laravel environment isolation class | 10 tests, 49 assertions; 0 errors/failures/skips |
| PHP syntax checks | 15 files passed |
| Manual handoff query validation | 26 queries passed locally inside a read-only transaction |

The focused PHPUnit filter was `GisFinalizedProjectMaintenanceTest|GisFeatureSerializerTest|GisGeometryTest|GisScopedReadsTest|ProjectClosureTest|ProductionMonitoringTest|ProductionMonitoringCalculationTest`; the separate isolation class was TestEnvironmentIsolationTest. PHPUnit used guarded SQLite :memory: and blocked external HTTP; native SQL/PostGIS/locking tests used a separate manifest-guarded PostgreSQL harness. The existing SQLite database guard was never disabled or made to allow PostgreSQL. PHPUnit's project environment loading was tightened before execution.

## D. Database integrity, concurrency and security

All 13 parent/child trigger registrations had the expected enabled `O`, type `29` metadata and function identities. FK compatibility and genuine profile/commodity ON DELETE SET NULL cleanup passed without changing final evidence/business fields. Deleting a publisher of still-published GIS correctly failed the existing publication CHECK and rolled back the entire delete; this existing constraint remains an operational consideration, not a bypass to add.

Separate real connections/processes produced server-observed row/advisory lock waits using pg_stat_activity/pg_blocking_pids. All four completion/termination winner/loser combinations had exactly one winner/audit, with the loser returning 409. GIS/finalization in both orders passed for both outcomes; concurrent same-version GIS editors conflicted safely; the existing per-ID advisory lock actually blocked a writer. Monitoring/finalization commit and rollback schedules preserved the correct winner; direct descendant Income trigger writes also serialized correctly in both orders. A real PostgreSQL `40P01` deadlock made Laravel's DB::transaction retry, succeeding on attempt 2. A required audit INSERT failure rolled back actual completion, termination, GIS publication and GIS deletion.

READ COMMITTED allowed eligible linked work and enforced final-state checks. Under REPEATABLE READ, unrelated unlinked activity CRUD succeeded; project-linked operations failed closed with `25000`, including indirect ancestry and GIS. This is the implemented compatibility boundary, not support for arbitrary higher-isolation writers.

Private helper EXECUTE was unavailable to PUBLIC-derived anon/authenticated access; client roles gained no DML. Qualified table references resisted temporary shadow tables. Normal trusted-owner ancestry checks enforced protection; a non-BYPASS owner under FORCE RLS failed closed with `42501`. The invoker parent guard did not mistake an RLS-hidden profile or commodity for a deleted FK parent. Native GIS schema preflight rejected an unknown DELETE trigger, a spoofed expected trigger name/function and an incoming FK. Invalid coordinates/self-intersection were rejected with actual PostGIS validation.

### Remaining boundaries and risks

- **Deployment prerequisite:** local 18.1/Windows/builtin locale/PostGIS 3.6.2 does not establish PostgreSQL 17.6/Supabase equivalence. Actual owner/grant/policy/FORCE RLS behavior must be rehearsed under the intended backend role, including extension USAGE and audit INSERT. SQL Editor owner success is insufficient.
- **Administrative trust:** row triggers do not protect TRUNCATE, disabled triggers or malicious owners/superusers. A privileged direct SQL writer can supply an existing actor ID and bypass Laravel's identity/audit workflow. Restrict those credentials and use authorized services.
- **Concurrency availability:** tested service schedules preserve integrity, but arbitrary direct writer lock orders can still deadlock. Keep short transactions, a consistent actor/association/project/descendant order and bounded whole-transaction retries. The real framework retry test proves retry behavior, not universal deadlock elimination or safe replay after uncertain commit.
- **RLS availability:** `row_security=off` fails closed when RLS applies; it does not bypass it. A misconfigured intended role can deny valid business writes. Do not solve that by exposing private helpers or weakening RLS.
- **Unverified:** cloud Auth/JWT/session/RLS/API exposure, existing migration history/data drift, exact hosted extension/locale, production-size performance, browser visual behavior, actual login and deployment recovery. No full application-wide suite or frontend build was needed for this database test scope.

## E. Exact source changes in this testing task

No production application service/controller/view, pending migration, `.env`, existing credential or dependency file was changed in this task. Preexisting unrelated working-tree edits were preserved.

| File | Change / reason | Verification |
| --- | --- | --- |
| `web/tests/Support/TestEnvironmentGuard.php` | Redirect testing environment loading to isolated Support/environment/.env.testing; never load project credentials | 10 isolation tests plus focused suite passed |
| `web/tests/Unit/TestEnvironmentIsolationTest.php` | Assert the isolated environment path/file contract | 10 tests passed |
| `web/tests/Feature/ProjectClosureTest.php` | Assert flash error bags before the following GET consumes them | Focused suite passed |
| `supabase/tests/finalization_gis_regression.sql` | Unique database/loopback/exact expected database+port checks; accurate local-only header | Actual SQL rerun passed in both isolation sections |
| `supabase/tests/local/ScratchDatabase.php` | Explicit local manifest/server/connection guard; no project configuration | Used by every native runner |
| `supabase/tests/local/bootstrap-framework.php` | Actual framework prerequisites via guarded Capsule | Prerequisites/007 passed |
| `supabase/tests/local/bootstrap-application.php` | Minimal isolated container for real service calls without normal environment/bootstrap | Native service/security/concurrency checks passed |
| `supabase/tests/local/fixtures.sql` | Synthetic complete prerequisite graph and record snapshot helpers | Loaded before 011; native checks passed |
| `supabase/tests/local/LocalTestRun.php` | Named results, precise rejection/savepoint/rollback helpers | Used by all 92 named native checks |
| `supabase/tests/local/migrate-pending.php` | Actual ordered migration execution, catalog/history assertions; refuses replay | Four actual files passed unchanged |
| `supabase/tests/local/functional.php` | Native lifecycle/child/GIS/Production service matrix | 61 checks passed |
| `supabase/tests/local/run-sql-regression.php` | Guarded execution of existing SQL source; full-record snapshot check | PASS |
| `supabase/tests/local/worker.php` | Separate guarded native/service connections and transaction barriers | Used by concurrency suite |
| `supabase/tests/local/concurrency.php` | Actual waits, competing services, deadlock/retry/rollback schedules | 17 checks passed |
| `supabase/tests/local/security.php` | Privilege/RLS/FK/audit/schema-preflight edge cases | 14 checks passed |
| `supabase/tests/local/collect-evidence.php` | Nonsecret server/catalog/count/hash evidence | Collected; migration source hashes still match |
| `supabase/tests/local/verify-handoff.php` | Execute prepared SELECT queries only in local read-only transaction | 26 queries passed |
| `docs/gis-finalization-maintenance.md` | Link subsequent actual results; update formerly unexecuted test/isolation descriptions | Static review |
| `docs/local-postgresql-migration-test-report.md` | This complete result/scope/limitations inventory | Evidence comparison/static review |
| `docs/supabase-finalization-deployment-guide.md` | Manual backup/preflight/order/postflight/failure checklist | Read-only SQL validated locally |
| `docs/testing/local-postgresql-2026-10-16-results.json` | Sanitized final execution evidence; no credentials | Counts/hashes compared with scratch reports |

Native runners are deliberately Windows/run-manifest specific and refuse normal application configuration. They are local testing tools, not deployment commands or a replacement for the guarded PHPUnit suite. Setup commands/logs and generated local authentication files remain only in the scratch directory; never deploy or commit that directory wholesale.

## F. Deployment readiness

**READY FOR FURTHER VERIFICATION.** The tested SQL set needs no correction from this local run. Complete the version/locale/intended-role rehearsal and read-only Supabase preflight before deciding on controlled development deployment. There is no claim of production readiness, actual cloud authorization correctness or successful browser preview.

## G. Owner's handoff and manual checks

Use [the manual deployment guide](supabase-finalization-deployment-guide.md). Apply only the exact reviewed source in order **011 → 012 → 013 → 014**, with required earlier schema already present, backups checked and writes paused. Do not replay 001–009, restore withdrawn 010 logic, deploy test sources, or reopen writes with mismatched Laravel/schema versions. On failure, stop, roll back the failed file's transaction if needed, inspect exact state and review a forward fix/recovery plan; never delete history or weaken guards.

After that separate approval/deployment process, verify in the browser with dedicated development records: finalize each outcome as Admin/assigned officer; reject invalid remarks, stale/duplicate requests and unassigned/account roles; retain historical reads; save ongoing Production with zero activities and unchanged Income; allow final GIS create/edit/publish/unpublish/archive; deny final GIS reassignment/deletion; allow eligible ongoing deletion; confirm validation/audit failures leave no partial writes. Browser checks have not been executed in this run.

For the oral defense: Laravel establishes who may act and writes the required audit within a transaction; PostgreSQL independently protects project state and linked historical data; GIS is a narrow validated maintenance exception. Separate-connection tests demonstrate how locking serializes competing writes. Local tests establish those behaviors in this isolated environment, while the actual hosted role/security/deployment contract still needs its own verification.
