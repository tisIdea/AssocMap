# Role-based dashboards

Source implementation date: 2026-10-15. Static source review only. No application commands, automated tests, builds, database queries, browser automation, migrations or deployment were executed. Runtime behavior, query plans, visual appearance and deployed schema remain unverified.

## Existing inventory and decisions

- `/dashboard` (`admin.dashboard`) supplied hardcoded zero values to the catalog workspace. `admin/dashboard.blade.php` displayed dashes, empty charts and placeholder recent records.
- There were no dedicated Field Officer or Association web dashboard routes/views. Their successful web logins opened Member Applications.
- Flutter's association home showed a module-pending card. Its Reports and Account tabs and public map navigation already existed.
- Laravel's `EnsureSupabaseApiAuthenticated` and `EnsureMobileAssociationAccess` implement bearer verification and current database eligibility checks. Flutter's `ApiAuthRepository.canAttemptSignIn` is still false. `RepositoryFactory` selects that blocked repository in API mode. No authenticated mobile JSON reader is currently supplied.
- `docs/production-readiness/09_REQUIREMENTS_TRACEABILITY.md` identifies documentation as technical working evidence, not an approved Capstone requirement numbering scheme. The dashboard request and current source/schema support the measures below; this document does not invent approved manuscript requirements.

The existing web layouts, Supabase authentication, current_user identity and module workflows remain authoritative. Only successful login destinations change for Field Officer/Association accounts. This is a dashboard routing integration, not a new authentication flow.

## Routes and data flow

| Client / role | Route | Gates |
|---|---|---|
| System Admin web | GET `/dashboard`, `admin.dashboard` | Existing `supabase.auth`, `role:system_admin` |
| Field Officer web | GET `/field-officer/dashboard`, `field-officer.dashboard` | Existing `supabase.auth`, `role:field_officer` |
| Association web | GET `/association/dashboard`, `association.dashboard` | Existing `supabase.auth`, `role:association_account` |
| Association API | GET `/api/v1/association/dashboard`, `api.association.dashboard` | `ApiRequestGuard`, authentication throttle, `supabase.api.auth`, `mobile.association`, dashboard throttle |

Web: Supabase session verification -> current_user -> role middleware -> DashboardController -> DashboardService -> scoped read-only Laravel queries -> shared Blade dashboard under the correct role layout.

Mobile source contract: existing bearer guard -> current account/link checks -> the same DashboardService -> `{data: ...}` JSON -> ApiDashboardRepository -> validated AssociationDashboard model -> native dashboard widgets. The reader/session integration remains unavailable; the app defaults to UnavailableDashboardRepository.

DashboardService reuses `Reporting/ReportScope::actor()` and `associations()` for authorization, without changing those classes. It does not call ReportService, ReportDataset, PythonReportEngine, reporting filters or subprocesses. A broken Comprehensive Report therefore has no execution path from dashboard rendering. Identity/link or database failures can still correctly deny a dashboard.

No association ID, municipality selector, reporting period or client-supplied scope is accepted by this dashboard service. An added `association_id` query parameter cannot widen scope.

## Authorization and ownership

- Admin: organization-wide associations, including archived/inactive associations. Child counters use that same parent scope unless their label explicitly adds an archive predicate.
- Field Officer: `associations.primary_field_officer_id = current_user.id`, `status = active`, `archived_at IS NULL`, before any counter or list is read.
- Association: exactly one active `association_accounts` link for the current profile, with exactly one link for that association; the linked association must be active/unarchived. Missing, inactive or inconsistent links deny access.
- Actor/profile/role eligibility is freshly read using the existing authorization service. Reads use the write connection. Account/link eligibility is rechecked before releasing the payload; own-association identity must still match. No permission-sensitive cache is used.
- Assignment predicates are in every applicable SQL subquery. Subsequent requests use current assignments. Multiple dashboard queries are not one atomic database snapshot; concurrent edits can produce small timing differences between widgets. Every module destination independently authorizes its own request.
- Association web/mobile have no member editing, application approval or export controls. Projects link to the existing Association Monitoring workspace because no separate Association Project/Profile management route exists.
- Responses use private/no-store, nosniff and no-referrer headers. API failures use the existing JSON error guard; unauthenticated/denied/unavailable remain distinct HTTP states. Core web query failure returns 503 with no counters; a dashboard authorization failure returns 403 with no records.
- Payloads contain counts, association display details and bounded operational titles/statuses/dates. They omit applicant/member names, birth dates, phone numbers, evidence/photos, credentials, audit payloads and monitoring issue/remark narratives. Blade escapes values and Flutter renders plain text.

## Metric and chart mapping

All measures below apply the server-resolved association scope first. Counts use one row per source record; independent registers are not joined together to calculate totals.

| Measure / widget | Tables and fields | Predicates and aggregation | Semantics |
|---|---|---|---|
| Registered associations | associations | COUNT(*) | Admin only; retained includes archives |
| Active associations / assigned active associations | associations.status, archived_at, primary_field_officer_id | COUNT CASE active and unarchived; Field Officer assignment predicate | Current lifecycle |
| Inactive associations | associations.status, archived_at | COUNT CASE inactive and unarchived | Admin only; disjoint from archived |
| Archived associations | associations.archived_at | COUNT CASE archive timestamp present | Admin only; takes precedence over status |
| Official members | members.association_id | COUNT(*) | Retained official records; archived members remain counted |
| Active official members | members.member_status, archived_at | COUNT CASE active and unarchived | Current member lifecycle, not application counts |
| Registered projects | projects.association_id | COUNT(*) | Includes retained archived projects |
| Unarchived projects | projects.archived_at | COUNT CASE archive timestamp absent | Includes planned/ongoing/completed/suspended/cancelled; no invented active-project status |
| Active Field Officer profiles | user_profiles, roles | COUNT with role code field_officer and both is_active flags true | Admin only; not a guarantee of invitation completion or current sign-in eligibility |
| Training activities / completed activities | trainings.association_id, status | COUNT all / COUNT CASE completed | All dates/statuses, archived training retained |
| Distinct present members | training_participants -> trainings and members | COUNT DISTINCT member_id, attendance_status=present, member/ training association match | All-time historical attendees; archives retained; not attendance occurrences |
| Quarterly monitoring records | monitoring_records -> projects | COUNT records; unarchived project IDs | All recorded years; no completion rate |
| Monitoring periods | monitoring_records.year, quarter_id | COUNT of DISTINCT year/quarter subquery | A shared quarter across projects counts once |
| Visit reports / legacy imports | monitoring_visit_reports -> monitoring_records -> projects | COUNT rows / COUNT CASE is_legacy_import | Separate from quarterly records and periods; historical conversion identified |
| Pending / approved / rejected applications | member_applications.association_id, status | Three conditional counts | Field Officer/Association only; approved applications are not added to membership totals |
| Association lifecycle chart | Same association aggregates | Active/unarchived, inactive/unarchived, archived | Disjoint categories sum to retained total |
| Municipality distribution | associations -> areas -> barangays -> municipalities | GROUP BY municipality ID/name, COUNT | Admin only; retained associations, top 12 groups clearly labeled |
| Project status chart | projects.status | GROUP BY status, COUNT; unarchived | Current portfolio status, not monitoring_records.project_status |
| Program component chart | projects -> saad_activities -> saad_subcomponents -> saad_components | GROUP BY component ID/name, COUNT; unarchived | One component per project; up to 12 components stated |
| Association overview | associations/geography plus separate member/project scalar subqueries | Up to 10 associations, alphabetical; active/unarchived member count and unarchived project count | Admin/Field Officer only; no fan-out between children |
| Assignment attention | associations -> NOT EXISTS eligible user_profiles/roles | Active/unarchived associations whose assigned profile/role is inactive, missing or wrong role | Admin review through existing accounts/assignments workflow; no application approval privilege |
| Recent project records | projects.created_at, status, program_year | Up to 6 unarchived projects ordered by created_at then ID | Registration timestamps, not inferred user actions |
| Dated program activities | program_activity_records.activity_date, status | Up to 6 unarchived records by activity_date; program-wide NULL association rows only for Admin | Includes planned dates; no fabricated audit feed |
| Recent monitoring records | monitoring_records.monitoring_date, year, project_status; quarters | Up to 6 for unarchived projects | Saved historical status; no issue narratives |

Schema sources: Supabase migrations 001 (domain tables and constraints), 003/004 (visit reports and historical conversions). No migrations or indexes were changed. The original associations schema requires a non-null primary Field Officer: a generic “unassigned associations” KPI would be misleading, so the attention widget checks actual assigned profile/role flags instead. Full invitation activation is not inferred from those flags.

## Differences from detailed Reports

- Member Summary and the dashboard retained-member measure use the same members table and lifecycle predicates when role/association scope matches.
- Registered projects are retained records; portfolio charts/recent lists and dashboard monitoring additionally exclude archived projects. Detailed Reports preserve archived project history. Use matching populations when comparing.
- Training dashboard counters are all-time and include archives. Detailed training reports use selected start-date periods. Distinct present membership is not summed across trainings/components.
- Program-wide activities appear only on Admin dashboards, consistent with Admin-only unfiltered program-wide reporting scope.
- Income/production, profit, monitoring completion percentages, approval deadlines and year-over-year trends were omitted. No current-period definition was invented, unlike units were not added, and monthly production was not added to quarterly output. Use the independent authorized Reports workflow for detailed income/production interpretation.

## Failure states and performance

Core reads must succeed before a dashboard payload is returned. A core outage never becomes an apparently valid zero dashboard. Optional widget QueryExceptions yield null, a safe diagnostic code and “Unavailable”; successful COUNT(*)=0 remains numeric zero. A missing visit-report table affects that widget rather than all monitoring or the whole dashboard.

Queries use COUNT, conditional COUNT, COUNT DISTINCT, GROUP BY, EXISTS and scoped subqueries. Member/project/application registers are aggregated separately. The overview's correlated counts run inside one SQL statement over at most ten displayed associations, without per-row Laravel requests. No whole operational tables, unbounded association ID arrays or private model graphs are loaded into PHP.

Lists are limited to 6/10/12 rows; status groups are bounded by schema status constraints. Existing schema indexes cover association assignment and child association/project foreign keys, and migration 003 indexes visit-report parent/date. No new indexes or caching were introduced. Approximately 20–30 database statements including fresh authorization/link checks can be needed depending on role and available widgets; exact counts, PostgreSQL plans and latency need measurement before deployment. No performance claim is based on a runtime run.

Web uses normal server-rendered navigation/refresh and the browser's request-loading behavior. Mobile has a progress state, pull-to-refresh, retry, stale-response protection and plain denied/expired/unavailable feedback. Previous data is cleared before reload and is never shown as a failed refresh's current data. Responsive metric cards, wrap-capable actions, keyboard-focusable table scrolling and labeled bar charts reuse the existing blue/white layouts with white rounded cards and the recent Reports spacing/border language. No chart package was installed. Mobile displays server-provided chart breakdown counts without independently calculating official totals.

## Mobile authentication blocker and adapter contract

Live integration is blocked by `mobile/lib/data/remote/api_auth_repository.dart` (`canAttemptSignIn=false`) and absence of an approved authenticated JSON/session reader. The existing demo repository is not a production sign-in implementation. No IDs, credentials, fake metrics or tokens were embedded to work around this boundary.

The new ApiDashboardRepository accepts an injected AuthenticatedDashboardRead callback and the existing AppConfig HTTPS backend origin. It constructs only GET `/api/v1/association/dashboard`, with no query selector. A separately approved session coordinator must own public-client Supabase sign-in, token refresh, secure session storage, logout and current `/me` eligibility validation. It must attach bearer authorization, validate HTTP/content type/JSON, refuse redirects/credential forwarding, bound responses and translate 401/403/429/503/network failures into the corresponding DashboardFailure. A 401 must trigger coordinated session recovery or expiration; it must not silently retry indefinitely. A 403 must clear dashboard data without treating it as an empty association.

The adapter remains unconnected by default. Demo sessions explicitly refuse live dashboard reads. AssociationGuard's authorization behavior is unchanged; dashboard repository injection and an identity-based widget key prevent presentation state being reused across identities. The dashboard model validates version, Association role, one-association scope, status, nullable counts and bounded lists. A response for a different association from the guarded session is rejected. The screen uses the existing controller's expiration callback for an expired failure.

Public GIS and the existing Reports/Account tabs remain available. No mobile Member Application, Project editing or Training-management route was invented; records are read-only summaries.

## Files created and modified

| Path | Purpose |
|---|---|
| web/app/Services/DashboardService.php | New scoped, read-only operational aggregates and bounded lists |
| web/app/Http/Controllers/DashboardController.php | New shared role layout/controller and guarded Association JSON action |
| web/routes/web.php | Replace existing Admin placeholder route action; add the two missing role dashboard routes |
| web/routes/api.php | Add independently guarded Association dashboard GET endpoint; retain Reports API |
| web/app/Http/Controllers/Auth/StaffAuthController.php | Change only successful Field Officer/Association redirect destinations |
| web/resources/views/dashboard/page.blade.php | New shared role-layout dashboard page |
| web/resources/views/dashboard/content.blade.php | New role-specific metrics, actions, status/error states, summaries and lists |
| web/resources/views/dashboard/chart.blade.php | New accessible labeled count bars |
| web/resources/views/admin/dashboard.blade.php | Replace old hidden catalog placeholder with a link to the live-query dashboard route |
| web/resources/views/layouts/field-officer.blade.php | Add Dashboard navigation/title and point brand to dashboard |
| web/resources/views/layouts/association.blade.php | Add Dashboard navigation/title and point brand to dashboard |
| web/resources/views/partials/admin-sidebar.blade.php | Use real dashboard/association/member routes so navigation works on the dashboard without catalog hash panels |
| web/resources/css/pages/admin-dashboard.css | Scoped responsive operational-dashboard styles |
| mobile/lib/data/models/association_dashboard.dart | New validated dashboard data contract |
| mobile/lib/data/repositories/dashboard_repository.dart | New read interface, safe default and typed failures |
| mobile/lib/data/remote/api_dashboard_repository.dart | New adapter for a future approved authenticated JSON reader |
| mobile/lib/features/account/association_dashboard_screen.dart | New native dashboard loading/content/error/retry presentation |
| mobile/lib/features/account/association_home_screen.dart | Replace pending-module home card with Dashboard tab; retain Reports/Account/map/sign-out |
| mobile/lib/core/authentication/association_guard.dart | Pass dashboard dependency and reset widget identity; no authorization change |
| mobile/lib/app/app.dart | Optional dashboard dependency with unavailable default |
| web/tests/Feature/DashboardServiceTest.php | Isolated test source: scoping, reassignment, archives, attendee deduplication, widget/core failures and missing bearer |
| mobile/test/association_dashboard_test.dart | Test source: zero/null separation, role/scope validation and safe unavailable default |
| docs/role-based-dashboards.md | This implementation, mapping, QA and defense record |

Existing Reporting controllers/services/scripts/exports/filters/diagnostics and report UI were not modified. Shared web layouts/navigation receive dashboard-only integration changes; no existing workflow entry was removed. Existing uncommitted work was retained.

## Manual QA plan — pending, not executed

1. In an approved development environment, sign in as each web role. Confirm the three dashboard routes and destinations, refresh, browser back/forward and sidebar active states. Open Associations/Members from Admin dashboard and from legacy catalog pages; these must load the real authorized module routes.
2. Admin: reconcile retained/active/inactive/archived association categories to records. Archive takes precedence; category sum must equal retained associations. Reconcile retained/active members and retained/unarchived projects independently.
3. Field Officer: use two officers with different assignments. Confirm each receives only their current active/unarchived associations, counts, projects, training, activities, applications and monitoring. Attempt other role dashboard URLs and expect denial.
4. Reassign an association through the existing Admin workflow. Refresh both officers' dashboards. The old officer must lose it and the new eligible officer must gain it. Deactivate/archive the association and repeat. With no eligible assignments, show an explicit empty scope and genuine zeros.
5. Association: compare two different accounts. An added browser/API `association_id` parameter must not change the server scope. Check missing/inactive links, inactive/archived association, inconsistent links and disabled profiles/roles. Deny without leaking records.
6. Applications: create/review through existing authorized workflows, then refresh. Pending, approved and rejected counts must match status rows; approval must not double-count an application as an extra official member. Association accounts must have no approval/edit/export controls.
7. Members: compare active, inactive and archived rows. Archival must preserve the retained total while reducing the active total. Check no member personal/evidence data appears in HTML or API payloads.
8. Projects: compare current portfolio status to saved monitoring status for a project that changed status. They must remain separate. Archive a project and confirm retained-project total remains while unarchived/project-chart/monitoring populations shrink.
9. Training: mark the same member present in multiple trainings; distinct present count must stay one. Verify absent attendance and cross-association mismatches are excluded. Verify archived training/member history remains included and explain all-time versus selected-report dates.
10. Monitoring: create records for two projects in one year/quarter. Quarterly count must be two; distinct period count one. Add multiple visit reports and confirm visit totals change independently. Check legacy imports are identified, not added again as extra quarterly records.
11. Charts: check each group against direct authorized records; municipality chart is top 12, component chart is bounded, and no group suggests an unsupported completion/financial measure. Confirm labels and numeric values remain accessible without interpreting bar colors.
12. Recent lists: verify limits/order/dates. A changed record timestamp must not be described as a user action. Future planned activity dates are labeled as dated records, not completed events. Ensure no unrestricted audit narrative appears.
13. In an isolated non-production failure setup, make an optional widget source unavailable: it must show Unavailable while other readable data remains. Simulate core database failure: web returns 503 with no zero counters; API returns a safe JSON 503. Restore access and retry.
14. API: no bearer -> 401; unsupported role or invalid association link -> 403; correct Association bearer -> own scope only. Check private/no-store headers, rate limits and absence of SQL/credentials/private fields in error bodies. Verify assignment/link changes with fresh requests.
15. Flutter now: verify the demo session and blocked API sign-in show explicit integration-unavailable UI without fake totals or live business requests. Verify Reports/Account tabs, public map and sign-out are preserved.
16. After separately approved secure mobile authentication/reader integration: test own data rendering, matching association identity, pull-to-refresh, retry, offline, malformed payload, session expiration, denied link, rapid reload and changed identity while a request is pending. No stale response may replace a newer one. No business values may survive sign-out.
17. Desktop/laptop/tablet/320px browser, keyboard focus, table scroll, 200% zoom and larger mobile text: no page overflow or clipped actions. Check Dashboard/Reports visual consistency and mobile summary wrapping.
18. Compare Dashboard/Reports only with matching association scope, archive population and time definitions. A Comprehensive Report 503 must not prevent a fresh dashboard from loading while database/auth dependencies are healthy.
19. Regression-check authentication/invitations, Member Applications/evidence, member archival/reactivation, executive-position uniqueness, project/training history, monitoring detail edits, GIS, all report categories and PDF/XLSX permissions. Run new/existing source tests only after the owner approves an isolated, uncached environment; never bypass TestEnvironmentGuard.

The new test files have not been executed. SQLite test fixtures cannot prove PostgreSQL plans, constraints, live Supabase identity behavior, deployed migrations, browser layout or mobile session integration.

## Capstone defense preparation

- **Why dashboards?** Each role needs a fast operational overview and a path into the work they are authorized to perform.
- **Dashboard versus Reports?** Dashboards use focused Laravel counts/current lists for regular navigation. Reports provide selected-period interpretation, detailed analytics and permitted document exports. Dashboard loading does not launch Python.
- **How does authorization work?** Middleware verifies the existing Supabase identity/profile and role. The service resolves current assignments or the account link before queries. UI visibility is additional presentation, not the access control.
- **How are KPIs calculated?** Conditional COUNTs distinguish lifecycle states; separate registers prevent row multiplication; COUNT DISTINCT prevents repeat attendance being mistaken for more people.
- **How does Supabase data reach the screen?** Laravel reads PostgreSQL through the existing configured database connection. Blade renders server data. The mobile API uses the same service; the future authenticated reader will supply that JSON to typed Flutter models.
- **Why distinguish current/history?** Archival retains evidence. A retained total and an active/unarchived count answer different questions, so their labels/predicates are explicit. Current project status and dated monitoring status have different meanings.
- **Why no large reporting job?** Small aggregate queries and bounded lists avoid extracting entire datasets or starting subprocesses on frequently visited dashboard pages.
- **How do web/mobile stay consistent?** Official values come from one Laravel service; Dart validates/renders them without recalculating totals. Live mobile sign-in/transport is explicitly unfinished.
- **How was this verified?** Only static source inspection, baseline comparison and whitespace/diff review were performed. Test source and a manual plan were added. Runtime, security integration and deployment still require owner verification.

## Status

- Completed in source: three web role dashboards, lightweight aggregates, scoped Association API, navigation/landing integration, responsive UI, unavailable/error states and focused test source.
- Partially implemented: mobile dashboard presentation, typed contract and injectable API adapter.
- Blocked dependency: production mobile sign-in/session coordinator and authenticated JSON reader, requiring separate authorization and integration verification. No auth architecture was changed to bypass this.
- Static review completed; syntax/application execution, automated tests, browser/mobile QA, live data reconciliation, performance and deployment verification pending.
- Existing Comprehensive HTTP 503 remains unresolved and out of scope.
