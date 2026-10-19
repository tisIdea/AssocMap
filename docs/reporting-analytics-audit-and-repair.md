# Reporting and Analytics: audit, repairs and local verification

Date: 2026-10-16. Workspace: `C:\dev\AssocMap`. This report describes the current source and actual isolated local execution, not a live Supabase inspection or deployment. The repository already contained substantial modified/untracked work; it was preserved. Approved Capstone 1 documents are not present in the inspected traceability package, so external document conformity cannot be certified.

**Outcome:** five confirmed reporting defects were repaired in six production-source files. All completed local checks pass. Existing web reporting is locally verified; deployment configuration and real-session acceptance remain conditional. Live Flutter reporting remains unavailable because secure API sign-in/session wiring is not implemented. No schema changes, environment/credential edits, dependency installation, commits/pushes or deployment were made.

[Machine-readable final evidence](testing/reporting-analytics-2026-10-16-results.json) records actual counts, source hashes, native scenario results, export checks, build status and retained test-cluster identity.

## A. Reporting inventory

All eight report types share `ReportController::{index,data,options,export}`, `ReportRequest`, `ReportCatalog`, `ReportScope`, `ReportDataset`, `ReportService`, `PythonReportEngine`, Python contracts/calculations/exporters, the existing Reports Blade workspace and `reports.js`.

Web routes: `/{admin|field-officer|association}/reports`, `/data`, `/options`, and POST `/export/{pdf|xlsx}`. Role-specific route names and middleware remain intact. Association Account export endpoints deny access server-side; no new financial or export permission was introduced. Protected mobile routes are GET `/api/v1/association/reports`, `/options`, and `/api/v1/association/dashboard`; no mobile export route exists.

| Report | Actual sections/charts and source | Existing filters | Reviewed status |
| --- | --- | --- | --- |
| Comprehensive | All 21 sections below, including 11 chart definitions; independent register extraction | Municipality, association, program year, reporting year/quarter, project, component | Fixed ongoing Activity rejection and monthly row/column mismatch; real preview/PDF/XLSX passed |
| Association Distribution | Current association rows, municipality/barangay distribution and status counts; associations → areas → barangays → municipalities | Municipality, association | Native extraction/exports passed, including Admin archive visibility |
| Member Summary | Official member counts/current lifecycle and distinct classification membership; members + separate classification assignments | Municipality, association | Archived members retained; no applicants or personal identity fields in output; passed |
| Project Portfolio | Current project rows, separate six-status distribution and component chart; projects + SAAD path + fisheries category | Municipality, association, program year, project, component | Completed and Terminated remain separate; archived/legacy history retained; passed |
| Training Participation | Training rows, participant/present/absent counts, distinct people and component chart; trainings + separate participants/member consistency predicates | Municipality, association, program year, start-date year/quarter, project, component | All dates/statuses/archives retained within scope; boundary/filter tests passed |
| Production Performance | Saved quarterly target/actual observations, separate monthly detail, period/unit observation-count chart; monitoring → production → project products + separate monthly rows | Municipality, association, program year/component, reporting year/quarter/month, project/product | Fixed missing Quarter column; zero month/activities, legacy/missing values, unlike units and no double counting passed |
| Gross Income | Recorded entries and period/frequency sums; separate monitoring_income extraction | Production scope; month applies only to recorded monthly Income | Exact PHP Decimal sums, monthly exclusion of unallocated quarterly entries, null versus zero and XLSX precision checks passed |
| Activities and Monitoring | Program activities/component chart, quarterly monitoring/status chart, visit-report observations and legacy source counts | Municipality, association, program year/component, event/monitoring year/quarter, project | Valid ongoing activities accepted; visits remain report rows, not a claimed physical-visit KPI; passed |

Dashboards are separate Laravel reads through `DashboardController`/`DashboardService`; they **do not invoke Python**. Admin sees organization-wide association lifecycle/geography, member/project counts, officer attention and scoped operational summaries. Field Officer sees current eligible assignments, application counts, project/component charts and training/Monitoring summaries. Association Account sees its own eligible association's equivalent permitted summaries. Optional query failures display unavailable rather than fabricated zero; core failures return unavailable. Dashboard quick actions and shared Blade contracts were reviewed and relevant routes/rendering tested.

The web workspace renders canonical sections/measures/charts/tables, applies validated filters and submits current applied filters to authorized PDF/XLSX POSTs. Browser fixture tests exercised the actual JavaScript and compiled CSS. Flutter has generic typed report/dashboard models, adapters and screens, but its default unavailable repositories and `ApiAuthRepository.canAttemptSignIn == false` remain authoritative: no live mobile data/login claim is made.

No CSV exporter, arbitrary date-range filter, project-status selector, commodity selector, dedicated materials/GIS export, annual quantity total, achievement percentage, profit or official trained-member KPI is implemented in this catalog. Fisheries category, project product and commodity are different concepts. Existing association geography uses recorded locality relationships, not invented GIS counts. Product/material/Monitoring/GIS relationships were inspected; unsupported reports were not added merely because a table exists.

## B. Confirmed root causes and severity

| Finding | Exact source/function | Evidence before repair | Minimal correction |
| --- | --- | --- | --- |
| High: dashboard can release data after assignment revocation or include a transient new assignment | `web/app/Services/DashboardService.php::load`, initial scope near line 22 and final eligibility check near line 160 | The initial scope remained a live subquery, and only the Account association received a final scope check. Two regression tests demonstrated a revoked FO still receiving assembled statistics and a transient assignment inflating project count from 2 to 3 | Freeze initial association IDs for all dashboard queries and compare the full freshly authorized set before release; fail closed with 403 |
| Medium: valid ongoing Activity makes Activity/Comprehensive generation fail | `reporting/python/contracts.py::validate`, status allowlist near line 158 | Laravel StoreProgramActivityRequest and the actual framework migration allow planned/ongoing/completed/cancelled; Python omitted ongoing. Both new report-type subcases raised ContractError before the fix | Add only ongoing to the Activity enum; unsupported Activity statuses still fail |
| Medium: monthly Production causes Comprehensive/Production HTTP 503 | `reporting/python/analytics.py::calculate`, monthly section near lines 148–151; rejected by `PythonReportEngine::validateResult` | Monthly rows inherited quarter from context, but their declared columns omitted quarter. Actual PostgreSQL/Laravel/Python execution failed with invalid_row. A new shared-result regression found the extra field | Declare the existing Quarter value as an integer column; preserve strict row validation and all monthly data |
| Medium: preview and export throttles interfere | `web/routes/web.php` near lines 45/51; matching API report group in `web/routes/api.php` near line 14 | Unnamed 30/minute and 6/minute middleware counters used the same signature. Seven previews could block an export; an export hit the shared counter twice. Two HTTP regressions failed with 429 | Distinct reporting-read/reporting-export prefixes; preserve both limits and existing identity/IP granularity |
| Low: XLSX periods display grouped digits | `reporting/python/exporters.py::xlsx` near line 128 | Rendered exports showed year 2026 as 2,026; the new format assertion failed | Use plain integer format for year/program_year/quarter/month; retain grouped count and decimal amount formats |

These are confirmed source/local-runtime findings. They explain reproducible failure paths, but no live request/log/dataset was inspected; a separate hosted configuration/data issue can still cause 503. Validation, authorization, row/type bounds and irreversible project protections were not suppressed.

## C. Exact files changed by this task

| File | Reason |
| --- | --- |
| `reporting/python/contracts.py` | Approved ongoing Activity status |
| `reporting/python/analytics.py` | Monthly Production columns match actual rows |
| `reporting/python/exporters.py` | Correct XLSX period formatting |
| `web/app/Services/DashboardService.php` | Fixed association scope and final release check |
| `web/routes/web.php` | Separate web reporting/export rate counters |
| `web/routes/api.php` | Separate mobile reporting read counter from unrelated unnamed throttles |
| `reporting/python/tests/test_analytics.py` | Ongoing Activity, exact row shape, empty/missing/multiple datasets and period formatting regressions |
| `reporting/python/tests/test_transport.py` (new) | Actual CLI success, malformed/duplicate/oversized input, strict status diagnostics, empty exports, font/glyph and precision failures |
| `reporting/python/tests/verify_exports.py` (new) | Reopen every pipeline XLSX/PDF; compare canonical cells/summary/types, formula safety, Unicode, headings and page bounds |
| `web/tests/Feature/DashboardServiceTest.php` | Revoked/transient assignments during dashboard assembly |
| `web/tests/Feature/ReportAuthorizationTest.php` | Real route middleware/role/filter/download/throttle/API denial and safe error/rendering coverage |
| `supabase/tests/local/reporting.php` (new) | Manifest-guarded real PostgreSQL/Laravel/Python pipeline, all reports/exports, known-value accuracy, roles, boundaries, release races, cleanup and GIS neutrality |
| `mobile/tool/reporting_contract_check.dart` (new) | Direct installed-Dart checks against actual synthetic server results without Flutter launcher/network/auth shortcuts |
| `reporting/README.md` | Current local verification status and unchanged hosted prerequisites |
| `docs/reports-and-analytics-implementation.md` | Mark original source-only evidence as historical and link this run |
| `docs/reports-and-analytics-manual-qa.md` | Distinguish automated local coverage from still-unperformed real-session QA |
| `docs/reporting-analytics-audit-and-repair.md` (new) | Complete inventory, findings, changes, verification and handoff |
| `docs/testing/reporting-analytics-2026-10-16-results.json` (new) | Nonsecret final execution evidence and hashes |

All other existing changes were preserved. No report controller/request/ReportDataset/Monitoring service, authentication/session implementation, Flutter runtime source, UI design, migration, schema, dependency manifest or environment file was changed. Scratch setup/build/browser/render scripts and generated sample exports are under the private test directories, not deployable project source.

## D. Accuracy and consistency review

Laravel independently reads one-to-many registers instead of joining Production, months, Income, attendance and classifications into a multiplied result. Python validates unique IDs/compound keys and parent references before calculating. Native fixtures with multiple Income/monthly sibling rows proved that three monthly rows remained three and recorded Income remained exactly 1200.30; selecting month 1 returned 0.10 and excluded the unallocated quarterly 1200.00 entry.

Saved Quarterly Actual Output is displayed once per product/monitoring period and unit. New Monitoring saves derive it from three decimal monthly quantities; reports read the saved value and keep legacy quarterly observations. Monthly detail is separate context, never an additional quarterly contribution. Zero activity and 0.00 quantity remain recorded values; absent records/null fields remain missing. Unlike units and separate quarterly observations are not summed into an unapproved annual production KPI. Coverage charts count observations, not production quantities.

Income uses Python Decimal on validated nonnegative decimal strings, preserves monthly/quarterly frequency, and sums only recorded entries. No entries yields unobserved/null; recorded zero is 0.00. XLSX precision overflow fails safely rather than silently rounding an unsafe numeric value. Text cells remain literal strings, including formula-like labels. Reporting adds no sales, expense or profit assumptions.

Project status is the current portfolio status; monitoring project_status is its historical snapshot. Completed and Terminated remain distinct in rows/distributions and do not fabricate closure or implementation dates. Current association/member snapshots are explicitly labeled. Training participant rows, present occurrences and distinct people are separate measures; multi-classified members are not counted multiple times as people. Visit-report rows, quarter containers and verified physical visits are not equated.

Year/quarter uses training start dates and Activity dates; Monitoring uses its recorded year plus actual quarters.quarter_number, not a guessed quarter ID. March 31/April 1 boundaries passed natively. Project portfolio uses program year, not a fabricated creation/reporting year. Month affects only real monthly detail/Income. Invalid scalar/array shapes, dates/dimensions and irrelevant filters receive controlled validation. Unsupported filters are rejected, not silently applied to another column.

Shared unfiltered association/member/project metrics matched dashboards in native tests. Legitimate differences remain documented: dashboards show current unarchived project charts and all-year Monitoring of unarchived projects; reports can retain archived project history and apply reporting periods. Dashboard training history covers all dates; filtered reports need not match that total. Pagination changes displayed rows only; browser tests showed 50/50/20 rows from a 120-row synthetic fixture while retaining its supplied summary. GIS maintenance after both final outcomes changed neither report sections/totals nor closure evidence.

## E. Security and data integrity

Web middleware verifies Supabase identity and role; ReportRequest validates input. ReportScope rereads the active profile/role, resolves association permissions, validates explicit scope and rechecks current eligibility before results/download release. Admin retains all associations including archives. FO remains limited to currently assigned active/unarchived associations. Account requires exactly one active link to one active/unarchived association and cannot export. Archived child history stays reportable within an eligible authorized association. Existing denial of archived associations to FO/Account was not broadened.

Mobile routes require bearer verification plus the current association-access guard, independently of web cookies or client claims. Tests used synthetic verified-provider responses; no real Auth calls were made. Forged association/municipality/project scope, unsupported roles, absent authentication, revoked links/roles and Account downloads were denied before extraction/engine access where applicable. Actual generation followed by FO reassignment denied analytics/PDF/XLSX release and left no workspace. The dashboard repair freezes its initial scope and rechecks it; reports retain their existing repeatable-read read-only dataset snapshot plus final scope check.

Reads use bound predicates and selected fields; minimal internal IDs support distinct/parent validation without exposing member identities in the canonical report. No report-data cache or reusable artifact URL exists. Responses are private/no-store/nosniff. Atomic per-user/global process locks, a fixed executable/entrypoint argument array, restricted inherited environment, bounded input/output/artifact/time limits and private UUID workspaces remain intact. Failures return sanitized codes; raw SQL, stderr, tracebacks, tokens and datasets are not logged or returned. A native missing-font failure cleaned files and released locks; a following generation succeeded. Cleanup command source was reviewed, not executed against application storage.

The local PostgreSQL owner is a private-cluster superuser; this does not reproduce exact Supabase grants/RLS. Hosted backend-role permissions and Auth behavior remain a separate verification requirement. Report snapshots describe a consistent extraction instant, not real-time values throughout Python generation. Downloaded files cannot be revoked after delivery. HTTP limits retain the existing signature/IP behavior; shared-NAT capacity was not redesigned. Owners/superusers, raw privileged SQL and deployment administration remain trusted boundaries.

## F. Actual test results and environment

| Verification | Executed final result |
| --- | --- |
| Focused Laravel + related regression suite | **255 tests, 2259 assertions, 0 failures/errors/skips** |
| Included reporting/dashboard/isolation HTTP subset | 44 tests, 272 assertions; included in 255, not additional |
| Python unittest suite | **24 tests passed; 0 failures/errors/skips** (17 analytics + 7 actual CLI tests) |
| Native PostgreSQL/Laravel/Python scenarios | **17 named scenarios passed; 0 failures**; includes all eight analytics types and all 16 PDF/XLSX exports |
| Saved-export verification | **16 checks passed**: 8 workbooks and 8 PDFs reopened; canonical values/types/summary/formula safety and Unicode/page bounds checked |
| Direct Dart server-contract checks | **16 checks passed** against all eight actual report results plus actual Account dashboard/adapter/denial contracts |
| Headless browser fixture | **6 checks passed** using actual reports.js and compiled CSS; all network requests intercepted, no application/Supabase connection |
| Web build | Vite production build passed with an empty environment directory and output in scratch, not web/public/build |
| Syntax/static review | Six changed PHP files linted; diff/whitespace/source-boundary review passed |
| Visual export review | 40 PDF pages rendered, all eight contact sheets reviewed, Monthly Production inspected at higher resolution; representative Production/Income/Project XLSX sheets rendered, year formatting corrected/rechecked |
| Flutter test launcher | Did not progress through SDK startup/cache locking in the restricted environment; interrupted before test execution. No SDK modification/download or lock bypass. No Flutter widget/device success claimed |

Laravel filter: ReportAuthorizationTest, DashboardServiceTest, TestEnvironmentIsolationTest, GisFinalizedProjectMaintenanceTest, GisFeatureSerializerTest, GisGeometryTest, GisScopedReadsTest, ProjectClosureTest, ProductionMonitoringTest, ProductionMonitoringCalculationTest, MobileAuthenticationTest. Existing SQLite :memory:/configuration/HTTP guards stayed enabled. Normal application .env was never loaded for these tests. Native service execution used the separate already prepared manifest guard, not a relaxed PHPUnit connection guard.

Native test environment: PostgreSQL **18.1**, PostGIS **3.6.2**, builtin C.UTF-8/UTF8; PHP **8.2.12**; CPython **3.12.10** with existing ReportLab **4.4.10**/openpyxl **3.1.5**; Dart **3.12.2**. A new private cluster/database `assocmap_gis_test_20261016_d745f049` listened only on **127.0.0.1:64776**. Exact PID, server-reported host/port/database/user/data directory were verified before writes. Only its newly initialized maintenance database was used to verify system-only contents and create the uniquely named database. Inherited PG settings were removed; test credentials were newly generated scratch-only files and are excluded from evidence.

Actual migrations 001–009, framework prerequisites and unchanged 011→012→013→014 established the new synthetic schema. 004 ran before business fixtures on empty Monitoring prerequisites. auth.users was a UUID FK stub only. The prior retained local test cluster, existing Windows PostgreSQL service and every real/local application/Supabase database were untouched. The new database/data files are retained; only this private server was cleanly stopped after collection.

Before fixes, the new regressions reproduced the Activity contract error, monthly result mismatch, both dashboard scope failures, throttle collisions and grouped year format. Final results above supersede those initial failures. One exploratory null project-start-date fixture was correctly rejected by NOT NULL and rolled back; no schema or reporting-date rule was changed to accommodate it.

## G. Remaining issues and limits

- Live Flutter authentication/session/secure reader remains absent; default repositories deliberately return integration unavailable. This task did not add an SDK, store tokens, use demo identity as authorization or invent a session architecture. Genuine mobile login/dashboard/report end-to-end acceptance remains incomplete.
- No live Supabase SQL, data, policy, credentials, logs or HTTP Auth call was accessed. The user's reported deployed 011–014 state is not independently recertified here. PostgreSQL 17.6/hosted locale/extensions/roles/RLS differ from the local 18.1 environment.
- Real authenticated browser navigation/download handling, full layouts with existing deployment assets, device/accessibility acceptance, Microsoft Excel behavior and hosting ACLs/resource capacity remain owner QA. The browser fixture is frontend-contract verification, not a real-session application test; its pagination rows were synthetic.
- The configured hosted Python executable, child-process permissions, shared lock driver, production-size reports/timeouts, orphan cleanup scheduling and complete Unicode/font coverage remain deployment checks. Source defaults still fail safely if the executable is not configured. Missing glyphs and unsafe XLSX numeric precision intentionally fail rather than silently corrupt output.
- No unsupported CSV/material/GIS/commodity report or new status/date-range filter was added. Annual cumulative production/achievement formulas, official trained-member/physical-visit KPIs, profit, wider archived-association visibility and any new Account financial/export permission require an approved business definition. Existing gross-income analytics access was preserved, not expanded.
- Historical records with inconsistent ancestry, duplicate periods or mixed Income frequencies may still fail the strict contract. No live data was inspected or silently repaired. Diagnose safe stage/dataset/field codes and review real inconsistencies separately rather than bypassing validation.

## H. Deployment requirements

1. Deploy the reviewed six production-source changes with their tests/documentation. Preserve unrelated working-tree work; do not deploy all dirty files blindly. No migration/schema/data change or new environment variable is required. Do not replay 011–014 for this reporting repair.
2. Refresh/rebuild cached routes through the approved deployment process because route middleware parameters changed. Refresh cached configuration only if existing reporting runtime settings are changed; verify normal PHP worker/opcache deployment behavior. No cache/deployment command was run on the user's environment.
3. Verify existing REPORTING_PYTHON_EXECUTABLE points to the approved Python 3.12 environment and that both existing packages and required fonts are available to PHP. Local tests explicitly configured their own runtime; the real application's environment was not read or changed. Optional existing font variables remain optional. There is no Python server to restart: the CLI is launched per report.
4. No JS/CSS production source changed, so this repair itself needs no new frontend bundle. The isolated build verified current imports/assets; deploy/rebuild earlier frontend changes as required by the normal release. Scratch build output was not copied into application public assets.
5. Review existing private storage ACLs/shared atomic cache locks/request budgets/orphan cleanup and complete manual QA below before declaring hosted readiness. Dependencies were not installed or upgraded here; package-advisory/hosting certification was not performed. See [runtime requirements](../reporting/README.md).

## I. Manual real-session QA for the owner

1. In an approved development/acceptance environment, sign in as Admin; open Dashboard and Reports. Compare registered associations/members/projects with known authorized records. Confirm dashboard current/unarchived/all-date notes and report period/archive notes explain legitimate differences.
2. Open Comprehensive, Activities and Production with a real valid ongoing Activity and recorded monthly Production. Apply recorded year/quarter, association, project and product where supported. Confirm no 503, month/quarter columns align, zero months remain zero and unrelated records disappear. Check March 31/April 1 boundaries using dedicated synthetic acceptance records.
3. Export all relevant PDF/XLSX types. Check title, scope, period, units, current/historical statuses, totals, readable pagination, Ñ/Philippine names and Excel period display. Verify formula-like text stays literal, months are not added again to quarterly output and quarterly Income is not prorated into a selected month.
4. Sign in as an FO assigned only to association A. Confirm B is absent from reports/options; forge B's association/project/product/municipality filters and try the Admin paths. Confirm denial. Reassign A away during generation through the approved workflow; ensure no pending preview/download is released and Dashboard requires refresh/denies the stale result.
5. Sign in as an Association Account with one eligible link. Confirm only its association; no export controls. Direct POSTs to Account/Admin/FO export routes must be denied. Test revoked/inactive/archive eligibility using dedicated acceptance setup and verify no broadened historical access.
6. Sign out and request protected pages/data/options/downloads; confirm redirect or 401/403 without data. On mobile API, validate a real authorized bearer identity only after secure mobile integration is separately implemented; do not copy a web token or demo identity into the app.
7. Change filters while generation is pending; confirm old data/export controls remain invalidated. Exercise pagination, empty periods, 390px/tablet/desktop layouts, keyboard navigation, loading, expired sessions, 429 and controlled runtime/font failures. Verify no stale response or partial file remains. Retain redacted evidence, not credentials or personal records.
8. Confirm both Completed and Terminated history remains reportable. Perform permitted GIS maintenance through the approved role workflow and compare operational reports before/after; totals and closure evidence must remain unchanged. Do not mutate protected historical operational data for QA.

## J. Capstone defense explanation

Reporting lets authorized staff turn retained operational records into understandable summaries without changing those records. Laravel verifies identity/role, selects the current association scope, validates filters and reads a consistent PostgreSQL snapshot. It sends only selected facts to Python through bounded JSON stdin. Python checks the contract and calculates distinct counts/groupings and exact Income sums; one canonical result drives screen, PDF, XLSX and the prepared Dart display. Laravel validates that result, rechecks current scope and releases it, then removes temporary workspaces.

Independent register extraction prevents join multiplication. Decimal strings/Decimal preserve exact amounts. Stored zero, missing data, units, periods, current project outcomes and historical Monitoring status remain different concepts. Completed and Terminated never become the same successful-completion category. Server-side role/scope checks and final release checks protect access even when someone forges filters or visibility changes during processing. Hidden buttons alone do not authorize anything.

Correctness was tested with synthetic known-value calculations, failing-then-passing regressions, actual PostgreSQL/Laravel/Python generation, real exported files, bearer/role route tests, direct Dart parsing and isolated frontend interaction checks. These prove the exercised local behavior; they do not certify the live Supabase role model, missing mobile authentication, production capacity or unperformed human acceptance. A PHP-only replacement renderer was unnecessary: the existing ReportLab/openpyxl architecture worked once its specific contracts and integration defects were corrected.
