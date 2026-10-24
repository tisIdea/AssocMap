# Association geography Batch 2 release review

Review date: 2026-10-20. Repository: `C:\dev\AssocMap`, branch `development`. **Production verdict: NOT READY.** Local geographic integration is verified on synthetic data; shared database, real-session acceptance and hosted deployment gates remain open. This document authorizes no deployment, migration or activation.

## A. Source scope and recovered state

Batch 1 expansion/editor work and Batch 2 integration were preserved. The application now distinguishes final-schema readiness from optional-Area activation. Final-schema readers use Association → Barangay → Municipality, retain optional Area separately, and keep the independent Association address private. State 3 writes derive the required Barangay while the UI still requires Area. State 4 validates the direct Barangay, optional matching Area and independent address.

Existing assignment/account-link authorization, archive/inactive controls, Monitoring/history safeguards, report formulas, geometry algorithms and API contract versions were retained. Dashboard production code was not changed. The shared geography helper consolidates repeated relation paths, selected columns and geography joins; it is not a new location framework.

Both configuration flags remain false. 017 and deferred 018 were executed only in newly generated disposable local databases. No shared Supabase connection was made, so shared migration history remains UNVERIFIED. No environment credential file was read or changed. No staging, commit, push, branch switch or deployment was performed. The pre-existing `.gitignore` modification was preserved (SHA256 `F5C6BDE1EF3EEEDFF34373D459D124D5998955C89EE2D7B4DB380F743CC1655B`). Synthetic credentials, database files, worker files, logs and exports remain outside the repository.

The exact changed-path inventory and machine-readable results are in [the verification record](testing/association-geography-batch-2-results.json). `.gitignore` is excluded from implementation ownership. This report and the JSON are local review artifacts ignored by the existing `/docs` rule (`.gitignore:58`); they have not been staged or added to version control. That rule was preserved.

## B. Continuation repairs

The public GIS publication fixture correctly uses the same Laravel connection/transaction as its reader. A separate PDO transaction cannot publish uncommitted changes to another connection.

The native report-options assertion now compares exact JSON values. Its previous strict PHP array equality compared distinct `stdClass` instances by identity, despite equal contract values. No values, keys or records are omitted from the comparison.

The mobile malformed-public-field fixture now copies nested properties into `Map<String, dynamic>` before inserting an integer. The inferred string-valued fixture map previously threw a Dart type error before reaching the parser. Every intended `throwsFormatException` assertion remains.

The cutover rejects unsupported/already-final schema metadata before writing. The negative orphan fixture retains an unvalidated legacy FK so it exercises the orphan guard rather than failing the schema-metadata precondition. The replay check verifies unchanged records after rejection. Deployment and local harness documentation now describe both flags and the recovery boundary.

## C. Module compatibility and security

| Module / responsibility | Changed source | Verification / remaining risk |
| --- | --- | --- |
| Association writes, display and directory | `AssociationGeographyService`, `AssociationService`, `AssociationDirectoryQueryService`, `StoreAssociationRequest`, Association/Barangay models, Association views, `admin/forms.js` | Four combinations, validation/clearing/omission, stale edits, escaping, pagination, scope and query-growth checks; actual browser interaction pending |
| Municipality / Barangay / Area | `AreaManagementQueryService`, connected-Associations partial | Native direct-Barangay reads and feature assertions preserve counts; actual Area counts remain Area-specific; no null pseudo-Area |
| Members and Applications | `MemberDirectoryQueryService`, Association Account Application controller, Member/Application views | Existing ownership/role suites plus Area-less location coverage; actual account sessions pending |
| Projects / Training / Activities | `ProgramDirectoryQueryService`, Admin program controllers, Project Closure controller, program views | Area-less display/choices and existing mutation/closure suites; real forms pending |
| Monitoring | Shared program/member geography readers | Production Monitoring implementation unchanged; historical/finalized safeguards included in focused regression |
| GIS | `GisQueryService`, `GisAddressResolver` | Native PostGIS read/write-return, exact geometry, public publication projection and private-address exclusion; shared RLS/provider configuration unverified |
| Dashboard / reporting | `Reporting/ReportScope` | Native dashboard contract, exact report dataset/options equality, eight Python report types, release authorization recheck; no formula/dashboard production edits |
| API / Android | Existing backend contracts; test-only Dart fixture repair | Backend bearer guards and mobile parsers tested; live Android sign-in is disabled in `ApiAuthRepository` |
| Audit / history | Existing lifecycle implementation retained | Geography batch adds no login/logout audit events; `StaffAuthController` has no such writes; required event coverage needs a separate scoped task |

Administrator write authorization remains in requests/controllers. Field Officer reads remain restricted to current assignments with the module's existing status/archive rules. Association Accounts remain restricted by active account links. Public GIS keeps exactly `location_type`, `title`, `address`, `description` as feature properties; it never reads the independent Association address. The model hides that address from incidental serialization. Backend authorization is rechecked after report rendering, including a native test that revokes an assignment between rendering and release.

Search OR conditions remain inside their grouped search predicates. Geographic SQL still uses parameter binding and fixed internal aliases. Eager relation paths select the required foreign keys; no per-row geographic query was introduced. Existing UI CSRF/method handling, backend validation and ownership checks remain authoritative.

## D. Native PostgreSQL/PostGIS evidence

Fresh targets, both on `127.0.0.1`, generated synthetic credentials, no application database:

| Runner | Database | Port | Result |
| --- | --- | --- | --- |
| `association-geography.php` | `assocmap_gis_test_20261020_bb4aca19` | 65456 | 11 checks, 0 failures |
| `geography-integration.php` | `assocmap_gis_test_20261020_53db1c48` | 52746 | 13 checks, 0 failures |
| Nested fixture safety | integration target | 52746 | 16 checks, 0 failures |
| Nested 011-014 migration verification | integration target | 52746 | 4 migrations passed catalog/preservation assertions |

Core checks cover 017 compatibility, address constraints, approval/conflict/orphan rollback, complete backfill including archived/inactive rows, direct/composite FKs, all four combinations, RESTRICT behavior, uniqueness/indexes and replay rejection. The full runner applies actual schema/framework prerequisites and 015/016 locally, preserves every Association identity, child snapshot and geometry, and calls actual Laravel services.

Two native races passed: an Area reparent waits for a concurrent referencing Association insert then fails; a queued stale Association writer obtains its lock after a newer address commits and receives validation status 422 without overwriting it. Transaction rollback checks preserve original data. Both fresh servers stopped cleanly in the orchestrator's `finally` block; recovered earlier geography clusters were also stopped. Evidence is retained rather than recursively deleted.

## E. Tests and Dashboard assertion review

The verification record contains exact command lines, results and evidence locations. Stage A/B/C results were inspected and followed by a fresh combined focused PHP run; they are overlapping suites and must not be added together as a unique total. The complete project suite was not run.

| Executed selection | Result |
| --- | --- |
| Final focused PHP, 22 classes | 700 tests, 9,659 assertions: 679 passed, 13 errors, 8 failures, 0 skipped |
| Geography/module Stage A (earlier in this task) | 89 tests, 1,347 assertions, 0 failures/errors |
| Geography/module Stage B (earlier in this task) | 133 tests, 2,754 assertions, 0 failures/errors |
| Geography/module Stage C (earlier in this task) | 175 tests, 2,386 assertions, 0 failures/errors |
| Selected frontend Node tests | 181 passed, 0 failed/skipped |
| Python reporting unit tests | 24 passed, 0 failed |
| Flutter dashboard/report/public-GIS/auth contracts | 35 passed, 0 failed |
| Native constraints / integration / fixture safety | 11 / 13 / 16 passed, 0 failed; four 011-014 migration checks passed |
| Export reopen verification | 16 passed, 0 failed |

All 21 final PHP problems occur in the unchanged `MemberOfficerLifecycleTest` (49 cases: 28 passed, 13 errors, eight failures). The other 21 selected classes pass all 651 cases. Source inspection confirms that the implicated test, `MemberService`, `MemberApplicationService`, position-integrity service and routes were not changed by this batch. Errors include legacy `archive`/`unarchive` argument signatures, an obsolete `MemberService::create` call, a missing Member route parameter, and data that now fails the existing position-integrity guard. Seven failures compare an incomplete newly created Eloquent snapshot with a full freshly read database row; another expects an audit exception before satisfying the existing update guards. These are confirmed test/contract mismatches in unchanged source; a clean HEAD runtime comparison was not performed. No assertion was removed or backend guard weakened. Align these fixtures and calls in a separate narrow test repair, then rerun them; do not treat them as a passing gate.

Exact final commands (run from `web`, repository root, or `mobile` as indicated):

```powershell
# web; process-only synthetic APP_KEY and the existing isolated test guard
php vendor/phpunit/phpunit/phpunit --filter 'AssociationManagementTest|AssociationLifecycleTest|AssociationOfficerTest|AreaManagementTest|MemberDirectoryUiTest|MemberOfficerLifecycleTest|MemberApplicationSubmissionTest|ProgramManagementUiTest|MonitoringManagementUiTest|MonitoringUiTest|MonitoringPeriodTest|MonitoringIncomeTest|MonitoringMaterialsTest|MonitoringDashboardGroupingTest|ProductionMonitoringTest|ProjectClosureTest|GisScopedReadsTest|GisManagementUiTest|GisFinalizedProjectMaintenanceTest|ReportAuthorizationTest|DashboardServiceTest|MobileAuthenticationTest' --no-progress --log-junit C:/dev/.codex-tmp/geography-batch2/final-php.xml
$geographyTests=Get-ChildItem tests/Frontend/*.test.mjs | Where-Object { $_.BaseName -notin @('user-management.test','invitation-activation.test') } | ForEach-Object { $_.FullName }
node --test $geographyTests
# repository root; existing dependencies only
& reporting/.venv/Scripts/python.exe -I -B -m unittest discover -s reporting/python/tests -p 'test_*.py'
php supabase/tests/local/association-geography.php C:/dev/.codex-tmp/assocmap-pg-20261020_bb4aca19/run.json
php supabase/tests/local/geography-integration.php C:/dev/.codex-tmp/assocmap-pg-20261020_53db1c48/run.json
& C:/Users/Dea/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe -I -B reporting/python/tests/verify_exports.py C:/dev/.codex-tmp/assocmap-pg-20261020_53db1c48/export-evidence
# mobile; existing SDK cache required access outside the workspace
flutter test --no-pub test/association_dashboard_test.dart test/analytics_report_test.dart test/public_gis_feature_test.dart test/auth_controller_test.dart
```

The native commands above record executed targets, now stopped. They are not reusable manifests for a fresh run: prepare new verified empty clusters through the guarded process. No dependency installation was performed. A stalled initial Flutter wrapper was stopped by its verified task PID only; the retry passed with SDK cache access. Unrelated IDE/background processes were preserved.

Four pre-existing Dashboard failures were observed in the pre-change baseline (329 tests, 5,363 assertions, four failures). Three expected every metric as an unlinked direct `article`; the existing `dashboard/content.blade.php` intentionally renders role-specific linked primary cards. The corrected tests independently specify those keys/labels and continue checking exact server values, notes, shortcuts and role visibility. The fourth unavailable-state fixture now nulls the metric actually shown for that role. Existing independent primary-card destination assertions remain. No production Dashboard logic was changed to satisfy tests.

PHP syntax checks cover all modified/new PHP files (Blade syntax itself is exercised by feature rendering), JavaScript syntax is checked, and `git diff --check` is required. No dependencies were installed and no production build or hosted smoke was claimed.

## F. Exports: generated files versus browser downloads

Actual PostgreSQL → Laravel → repository Python reporting generated analytics plus PDF and XLSX for all eight catalog types: `comprehensive`, `associations`, `members`, `projects`, `trainings`, `production`, `income`, `activities`. All 16 export files were reopened with the existing verification runtime. Checks cover exact XLSX rows/cells/types/summaries, formula safety, and PDF readability, Unicode, section headings and page bounds. Temporary private reporting workspaces were removed by the reporting engine, including denied exports.

This does **not** establish real-session browser downloads or hosted Python/font/process configuration. The previously reported browser export defect was not reproduced at the local service pipeline, and remains an acceptance gate until its HTTP/browser path is exercised. Do not classify it as fixed in production.

## G. Query behavior and limits

Equivalent native Field Officer service calls before/after cutover and Area removal:

| Service call | Before queries | After queries |
| --- | ---: | ---: |
| Barangay directory | 3 | 3 |
| Member listing | 9 | 9 |
| Project listing including summary | 8 | 8 |
| Monitoring lookup | 6 | 6 |
| Dashboard | 19 | 19 |
| Report options with authorization recheck | 22 | 22 |
| GIS features | 2 | 2 |
| GIS Association choices | 1 | 1 |

The evidence JSON retains measured local SQL/elapsed milliseconds. These are single synthetic cold/warm observations; they do not demonstrate latency improvement or hosted performance. Report filters still perform 22 service queries in this case; preserve authorization rechecks when profiling their real bottleneck.

Association feature tests grow records beyond one page and assert constant query counts (at most seven service queries). HTTP request evidence: legacy/prepared Admin directory 12/12, create editor 17/19, edit editor 18/21 queries. The prepared editor's additional two queries load Municipality/Barangay catalogs; edit adds its direct Barangay/timestamp context. Catalogs load only when editing. Legacy Field Officer directory is 10 queries and officer directories are six. These request counts include middleware/UI work and are not directly comparable to the native service table.

Optional-Area geographic catalogs are capped at 5,000 records each and fail explicitly with 503 beyond capacity. Existing disabled-mode Area catalogs, officer/agency catalogs and distinct directory filter choices still use whole scoped result sets; this is a pre-existing capacity risk, not a measured current failure. Reporting options have existing 1,000-row caps, and report datasets have per-dataset/total limits. Direct-Barangay eager reads retain optional Area separately; they replace the geographic parent path rather than adding per-row queries. Shared cardinalities and real provider/network timing remain unverified.

## H. Release blocker register

| Priority / classification | Evidence and impact | Smallest next action | Status |
| --- | --- | --- | --- |
| P0 / release blocker: shared cutover prerequisites | Shared history, catalog drift, data mapping, backup restore and RLS were deliberately not accessed | Authorized read-only preflight, verified restore plan, writer inventory and maintenance approval; then reviewed 017/018 sequence | OPEN |
| P0 / essential workflow blocker if authenticated Android is required | `mobile/lib/data/remote/api_auth_repository.dart` returns `canAttemptSignIn=false`; API mode selects it | Separate focused live-auth integration preserving existing backend/bearer checks; real-device session/expiry/logout acceptance | OPEN |
| P0 / acceptance gate: required report downloads | All local artifacts work; reported real browser path has not been exercised | Test eight PDF/XLSX downloads through real authorized sessions; repair the exact failing HTTP/runtime layer if reproduced | OPEN |
| P0 / release gate: hosted environment and sessions | No production build/deploy, provider/RLS check, real-session or device acceptance | Verify hosted runtime configuration without exposing secrets; approved staging smoke and role checklist | OPEN |
| P1 / reported defect, reproduction pending: report-filter responsiveness | 22 local service queries, no query growth; hosted/network latency unknown | Profile DB/provider/CPU separately on an approved target; reduce measured duplication without removing authorization rechecks | OPEN |
| P1 / deferred requirement: authentication audit events | Browser login/logout controller has no audit writes | Confirm required event set; add narrowly scoped sanitized events/tests in a separate task, retaining login availability policy | OPEN |
| P1 / regression verification debt | 21 Member lifecycle test errors/failures from unchanged fixture/service contract mismatches | Align calls, concurrency tokens and complete persisted snapshots with current contracts; preserve every authorization/rollback assertion and rerun | OPEN |
| P2 / capacity follow-up | Some legacy catalogs remain unbounded; activated catalogs stop at 5,000 | Confirm actual cardinalities; add scoped paging/search if needed before exceeding limits | DEFERRED |

The final focused run is not fully green because of the Member lifecycle verification debt above. Local geographic success does not close these production gates.

## I. Migration and recovery readiness

017 is a nullable expansion with Area still required. 018 is deliberately outside the migration directory, transactional, approval guarded, metadata checked and not replayable after cutover. It backfills only NULL Barangays, never copies Area addresses, enforces direct Barangay NOT NULL and composite Area/Barangay MATCH SIMPLE with RESTRICT, and replaces the Area-only index with the composite index. Existing identity/address uniqueness remains intact. PostgreSQL requires a unique referenced pair for that composite FK ([constraint documentation](https://www.postgresql.org/docs/current/ddl-constraints.html#DDL-CONSTRAINTS-FK)).

Follow the [controlled deployment runbook](../supabase/deferred/README.md), including its four-state flag table. Review actual shared history/metadata and restore capability first; deploy compatible code with both flags false, apply 017, verify State 2, stop all legacy writers, cut over 018 and schema readiness together under maintenance, verify State 3, then separately authorize optional-Area activation. Never replay the baseline schema.

Failure before transaction commit leaves expansion compatible. After 018 commits, keep direct readers/writers and use a forward repair; turning optional activation off returns only to State 3. An old binary or `geography_schema_ready=false` is not a valid rollback. Restoring Area NOT NULL after Area-less writes requires explicit remediation or coordinated restore accounting for subsequent records. Reload open forms because backfill can advance `updated_at`.

## J. Browser/device acceptance checklist

All items below are **NOT RUN** in real sessions:

- State 2: Area-required create/edit and existing workflows; unauthorized new fields rejected.
- State 3: Area-required create/edit writes Barangay, old forms rejected, existing Area-less records remain visible in every reader.
- State 4: each Area/address combination; Municipality → Barangay → Area filtering; switching/clearing choices; omitted versus cleared address; whitespace/Unicode/500-character validation; validation-error restoration; no-JavaScript behavior.
- Two simultaneous editors: later stale save cannot overwrite the committed address. Inactive parents, mismatched Area/Barangay, archived Associations and unauthorized roles fail safely.
- Admin, assigned/unassigned Field Officers and two linked Association Accounts: directory search/sort/filter/pagination/counts and direct URLs retain exact scope; private address remains escaped and absent from public GIS/API/report projections.
- Member/Application ownership, Project/Training/Activity location labels and Monitoring quarterly history/revision/delete/finalization rules remain correct with and without Area.
- GIS scoped map/table/detail and write-return retain geometry; public/private publication toggle and revoked links/assignments deny access correctly.
- Dashboard totals and empty/unavailable states; all report filters and eight PDF/XLSX downloads, filenames/content types/content disposition, empty/error/unauthorized states and revocation during generation. Record timings and response stages.
- Keyboard/focus, reduced-motion/no-JS behavior and phone/tablet/desktop layout. Android real live sign-in/session expiry/logout and backend authorization after its separate repair.
- Approved deployment smoke: configuration cache/worker versions, Python runtime/fonts/process permissions, private temporary cleanup, PostGIS/RLS, redacted logs and backup recovery evidence.

## K. Verdict

**NOT READY for production deployment or optional-Area activation.** The local geographic implementation has passing native integrity/concurrency/export evidence, but production prerequisites and required acceptance remain unverified, and live mobile authentication is a confirmed source limitation. Both flags stay false pending explicit authorization. No live migration or deployment was performed.

## L. Actions before October 23

1. October 20: review this patch and authorize only the next required gate; settle authenticated Android delivery scope immediately. If required, perform its narrow auth repair separately.
2. October 21: complete authorized read-only shared preflight and backup/restore review; exercise real-session report downloads and profile filters. Repair reproduced P0 defects at their exact layer.
3. October 22: complete role/device/browser acceptance and approved staging deployment smoke. Resolve every P0 before requesting a maintenance/cutover decision.
4. October 23: deliver only the verified approved release, preserve migration evidence and a rehearsed recovery procedure. Defer P2 work; do not mask required workflows or assume gate completion to meet the date.

Stop after review. Production cutover requires separate explicit authorization.
