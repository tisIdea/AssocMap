# Reports and Analytics — implementation record

Date: 2026-10-15 (Asia/Manila). Repository: `C:\dev\AssocMap` only.

**This is the original implementation record. The 2026-10-16 [audit, repairs and isolated execution report](reporting-analytics-audit-and-repair.md) supersedes its formerly unexecuted local-test status. Hosted deployment and real-session browser verification remain pending. Live Flutter integration is still blocked by the disabled API authentication implementation.**

## A. Files created and modified

Created:

```text
web/config/reporting.php
web/app/Exceptions/ReportingUnavailableException.php
web/app/Http/Requests/ReportRequest.php
web/app/Http/Middleware/ReportingRequestGuard.php
web/app/Http/Controllers/ReportController.php
web/app/Services/Reporting/ReportCatalog.php
web/app/Services/Reporting/ReportScope.php
web/app/Services/Reporting/ReportDataset.php
web/app/Services/Reporting/ReportService.php
web/app/Services/Reporting/PythonReportEngine.php
web/app/Console/Commands/CleanupReportWorkspaces.php
web/resources/views/reports/page.blade.php
web/resources/views/reports/error.blade.php
web/tests/Feature/ReportAuthorizationTest.php
reporting/.gitignore
reporting/requirements.txt
reporting/README.md
reporting/python/__init__.py
reporting/python/main.py
reporting/python/contracts.py
reporting/python/analytics.py
reporting/python/exporters.py
reporting/python/tests/test_analytics.py
mobile/lib/data/models/analytics_report.dart
mobile/lib/data/repositories/analytics_repository.dart
mobile/lib/data/remote/api_analytics_repository.dart
mobile/lib/features/reports/association_analytics_screen.dart
mobile/test/analytics_report_test.dart
docs/reports-and-analytics-implementation.md
docs/reports-and-analytics-manual-qa.md
```

Modified:

```text
web/routes/web.php
web/routes/api.php
web/bootstrap/app.php
web/resources/views/admin/reports/index.blade.php
web/resources/js/pages/admin/reports.js
web/resources/css/pages/admin-reports.css
web/resources/views/dashboard/index.blade.php
web/resources/views/partials/admin-sidebar.blade.php
web/resources/js/layouts/admin-navigation.js
web/resources/views/layouts/field-officer.blade.php
web/resources/views/layouts/association.blade.php
mobile/lib/app/app.dart
mobile/lib/core/authentication/association_guard.dart
mobile/lib/features/account/association_home_screen.dart
```

No operational service/model/schema, dependency lock, environment file, secret or deployment infrastructure was modified. Existing global layouts received only report heading/navigation integration. The old dashboard hash section now links to the single functional Admin report workspace.

## B. Report status

Here, **Implemented** means source code exists, not runtime verification.

| Report | Source status | Scope and limitations |
| --- | --- | --- |
| Comprehensive | Implemented | Reuses the seven report definitions; current snapshot sections retain their own basis alongside period-filtered observations. |
| Association Distribution | Implemented | Distinct current associations, municipality/barangay groups keyed by IDs, current status and archive state. No historical association reconstruction. |
| Member Summary | Implemented | Distinct official retained members, active/inactive unarchived vs archived, classification counts and association totals. No applicant evidence or identifying member details. |
| Project Portfolio | Implemented | Project/association, program year, category, component, current statuses and archive state. Status/component distributions; program-year and project filters. |
| Training Participation | Implemented | Activities, completed activity count, participant rows, distinct members recorded, present occurrences and distinct members marked present. Official trained-member eligibility is **blocked pending policy approval**, so no such KPI is presented. |
| Production Performance | Implemented | Recorded quarterly target/actual by association/project/product/unit, real monthly details, zero counts and observation coverage. Annual production totals and achievement percentages are **not implemented** because cumulative semantics/formulas are unapproved. |
| Gross Income | Implemented | Exact sums of recorded entries, period/frequency details, zero/missing coverage. PHP currency label approved by owner on 2026-10-15. Month selection excludes quarterly entries without allocation. No profit or invented sales/expenses. |
| Activities and Monitoring | Implemented | Program activities, components, quarterly monitoring, historically recorded statuses and visit-report rows, identifying legacy imports. Physical visits and completion percentages are **not inferred**. |

## C. Permissions

| Surface | Admin | Field Officer | Association Account |
| --- | --- | --- | --- |
| Web reports/options/analytics | All associations | Currently assigned active unarchived associations | Exactly one current active linked, active unarchived association |
| PDF/XLSX POST | Allowed within scope | Allowed within scope | Denied before extraction/process execution |
| Mobile reports/options API | Denied by association guard | Denied by association guard | Bearer identity plus current eligible link; no export route |

Every page/data/options/export handler uses `ReportRequest` and the shared scope service. UUID syntax, period bounds, report-specific relevance, unknown filters and quarter/month combinations are validated. Bound query predicates enforce scope. Options expose only scoped association/project/product/period/component information. Requests cannot supply an assignment list. Current eligibility is reread and checked before data release.

FO archived-association access remains excluded, following the existing monitoring workspace's association policy. Approval is needed to broaden that behavior. Archived project/training/member history remains within currently authorized association scope. Association accounts cannot use this report module to access inactive/archived associations.

## D. Database source mapping

All reports use association → `areas` → `barangays` → `municipalities` for geography. Components follow activity → `saad_subcomponents` → `saad_components`. No new table or live query was created/executed during implementation.

| Report | Source tables and relationships | Filters | Calculation and missing-data handling |
| --- | --- | --- | --- |
| Comprehensive | Union of the independent datasets below, not a join of all child registers | Municipality, association, program year, reporting year/quarter, project, component | Same definitions below; no unrelated extra KPI. Association/member snapshots remain independent of project/event filters. |
| Association | `associations.area_id`, `areas.barangay_id`, `barangays.municipality_id` | Municipality, association | Distinct association IDs; current status and archive state. Zero associations is an empty scope, not a historical estimate. |
| Members | `members.association_id`; separate `member_classification_assignments` → `member_classifications` | Municipality, association | One official member row per ID; archived retained. Classification membership uses distinct member sets and is not summed into the member total. |
| Projects | `projects.association_id`, `saad_activity_id`, `fisheries_category_id`; activity/subcomponent/component; `fisheries_project_categories` | Municipality, association, program year, project, component | Distinct portfolio rows and current status/component counts. No creation-year reconstruction. |
| Trainings | `trainings.association_id`, optional `related_project_id`; separate `training_participants.training_id/member_id` and scoped `members` | Municipality, association, program year, start-date year/quarter, project, component | Distinct training/member IDs; present vs absent occurrences separate. Archived members remain attributable. No participant rows means zero recorded participants, not proof no people attended. |
| Production | `projects` → `monitoring_records` → `monitoring_production` → `project_products`; `quarters`; separate `monitoring_production_monthly` | Municipality, association, project/product, project program year/component, monitoring year/quarter, recorded month | Quarterly observations are displayed with units and never added across quarters/units. Monthly details are separate, not added to parents. Missing observation and stored numeric zero differ. |
| Income | Production chain → `monitoring_income.monitoring_production_id` | Same production scope; monthly selection applies to income month | Decimal sum of recorded rows, grouped by year/quarter/frequency. Mixed frequencies on one production parent fail contract validation. No rows yields null/unobserved amount; stored zero stays `0.00`. Quarterly entries are excluded under month selection and counted in a notice. |
| Activities/Monitoring | `program_activity_records` and its component path; projects → monitoring; separate `monitoring_visit_reports` | Municipality, association, program year/component/project; activity-date year/quarter and monitoring parent year/quarter | Separate activity, monitoring and visit-report counts. Visit reports include legacy imports and all dates under selected parents; no physical-visit claim. Unlinked program-wide activities are Admin-only without municipality/association filtering. |

Year defaults to the current Manila year for event/monitoring reports; period options contain scoped recorded years and the current year. Year is not applied to association/member snapshots or the project portfolio. Quarter uses the actual `quarters.quarter_number`, not an assumed reference ID. Month filters select their containing monitoring quarter and real monthly detail; they never prorate quarterly output/income. Training uses start date rather than inventing session-day counts or an overlap rule.

The dataset uses a PostgreSQL read-only repeatable-read snapshot, separate bounded queries and explicit selected columns. No personal member details, remarks/evidence or raw models are returned. Cross-project products and inconsistent training/member association links are excluded by query predicates; database constraint/trigger deployment remains unverified. The 1,000-option and 5,000/20,000-row bounds refuse oversized reports; totals are never based on a silently truncated subset.

## E–F. Engine and web UI

See `reporting/README.md` for exact contracts, files, limits, prerequisites and transport. Laravel owns authentication, filters, authorized PostgreSQL reads and final authorization. Python owns counts, distinct sets, grouping, exact recorded-income sums and the canonical report result. Both exporters consume that result. Browser and Dart code perform presentation scaling only.

The existing Admin Reports structure/styling is reused with a filter card, result preview, KPI cards, native charts and accessible paginated tables. Existing layouts serve Admin, FO and association web users. All have loading, empty and error feedback, generation/scope/period context and quality notes. Admin/FO have POST/CSRF PDF and XLSX actions tied to the applied filters; association accounts have none. Changed filters invalidate the displayed preview/export controls until results load. Server rendering supplies a first-100-row fallback; JavaScript exposes all bounded table pages. The old dashboard hash destination links to the single report workspace.

## G. Flutter

Source components: Reports navigation tab under the existing association guard, typed shared-result model, API repository adapter, recorded period/type filters, summary cards, bars, paginated detail tables, pull-to-refresh and failure handling. No totals are independently calculated in Dart. Report state is cleared on leaving the foreground, on replacement requests and on widget disposal; no offline snapshot cache is written.

**Partially implemented / blocked:** `ApiAuthRepository.canAttemptSignIn` remains false. No verified live Flutter session reader exists. The default reporting repository therefore returns integration unavailable and makes no request. The adapter requires a future approved authenticated reader and is not wired to demo identity or web cookies. SDK installation, secure storage, refresh coordination, `/me` eligibility and authenticated HTTP-reader wiring require a separate approval task. No operational mobile reporting claim is made.

## H–I. Exports and security review

PDF: ReportLab, TTF glyph checking, escaped/wrapped text, repeated table headings, wide-table orientation, pagination/page numbers, metadata, scope, units and quality notes. XLSX: openpyxl workbook with metadata, typed summary/details, data-quality sheets, frozen headings and filters. Text cells are explicitly literal strings, including strings beginning with formula characters. Decimal precision is preserved in the result; XLSX rejects values exceeding its safe numeric precision instead of rounding silently.

The executable is configured server-side by absolute path, entrypoint is fixed, arguments are passed as an array and data travels through bounded stdin JSON. Credentials and raw SQL never go to Python. Process time/byte limits, one-user/two-global-slot atomic locks, UUID private workspaces and minimal inherited environment constrain generation. Laravel accepts only the expected fixed artifact filename, resolved workspace path, matching size and file signature. It loads the bounded file, cleans the workspace and rechecks current authorization before returning bytes. No persistent artifact/download URL exists. Crash-orphan cleanup is provided as an unscheduled reviewed-source command. OS permissions and load behavior require deployment verification.

All report responses use private/no-store and nosniff headers; downloads have the proper content type and attachment name. Failure responses/logs omit traceback/stderr, SQL, credentials and datasets. Existing Supabase auth, grants/RLS, audit writers and operational business services remain unchanged. No new export audit event policy was invented.

## J–K. Deployment and QA

Exact runtime/configuration, process permissions, filesystem/ACLs, font handling, cleanup, configuration-cache, concurrency, resource and hosting requirements: `reporting/README.md`.

Specific role, filtering, calculation, PDF/XLSX, missing/zero, historical, failure, cleanup and mobile consistency tests: `docs/reports-and-analytics-manual-qa.md`.

Unexecuted regression sources: `web/tests/Feature/ReportAuthorizationTest.php`, `reporting/python/tests/test_analytics.py`, and `mobile/test/analytics_report_test.dart`. They specify scope/reassignment denial, export denial, distinct/decimal/month/quarter behavior, injection protection and shared mobile result parsing. They are not evidence of passing tests.

## L–M. Original evidence and status at the source-only implementation stage

- Source implementation: complete for the safe supported web/API/Python/export scope; Flutter screen/adapter source exists, live Flutter auth wiring remains blocked.
- Static review: completed through source/route/schema inspection and read-only Git whitespace/diff review. This is not syntax, compilation, execution or security-certification evidence.
- Runtime/PDF visual/XLSX reopening verification: pending.
- Manual QA: pending owner execution in an isolated approved environment.
- Dependency installation, runtime executable/font configuration, hosting/resource/ACL/cache/scheduler decisions: pending owner action and approval where required.
- Production readiness: pending all preceding verification.
- Organizational decisions withheld: official trained-member eligibility, production target achievement/annual cumulative semantics, physical-visit KPI and any broader archived-association visibility.
- Capstone documentation conflict: older audit inventories call reports a placeholder and are historical snapshots; this implementation record supersedes that report status only. The inspected traceability file states that approved Capstone 1 documents are unavailable, so external document conformity cannot be certified from this repository.

During that original implementation stage, no tests, report scripts, PHP/Laravel commands, builds, migrations, SQL sessions, live Supabase operations, dependency installation, deployment, commit or push were executed. The subsequent linked audit records actual local execution and narrowly scoped repairs. Defense explanations must distinguish those local results from unverified hosted behavior.
