# Global feedback implementation and acceptance report

Reviewed and continued on 2026-10-17 in `C:\dev\AssocMap`. This report covers the Laravel web interface and the actual workflows listed below. It is not a deployment approval or a claim that every AssocMap client uses web dialogs.

## Result

The interrupted web implementation has been continued and the common result component is integrated into the System Administrator, Field Officer, and Association Account layouts. Focused isolated checks pass. Real-browser acceptance remains pending, and a broader test selection still has 41 pre-existing failing/error cases reproduced from the pre-notification source snapshot. The entire repository test suite is not green.

No live Supabase connection, migration execution, real-record mutation, credential change, dependency installation, staging, commit, push, or deployment was performed. Existing Monitoring database and business-logic changes were preserved.

## What was already implemented

The interrupted work already contained the `GlobalFeedback` PHP component, its Blade dialog, CSS, JavaScript queue/controller, layout integration, initial flash-banner removal, deferred form restoration, an Association submission marker, initial GIS mutation integration, and the report download adapter. These files were reviewed and continued rather than replaced.

The worktree also contained earlier Monitoring Income concurrency and Monitoring UI changes. Those are separate from this presentation feature. Migration 015 remains exactly as it was; its presence in the repository does not establish that it has been applied to any database.

## Corrections and completion in this continuation

- Preserved original HTTP statuses for HTML or malformed report/GIS responses. Successful responses with invalid content are failures, not successful downloads or saves.
- Reused and revealed existing field-error elements, linked details through `aria-describedby`, supported nested/array field names, and retained input values.
- Gated Association old-input values, registration drafts, and restoration on `association_form=editor`. An Area submission with the same field names cannot populate the Association editor.
- Fixed acknowledgement/close-event ordering so form restoration cannot race with focus restoration. Prevented drawer keyboard handlers from handling the feedback dialog's Tab/Escape events.
- Completed explicit GIS save and lifecycle feedback, protected pending requests from duplicate clicks, checked returned record identities, and kept successful mutations truthful when a later register refresh fails.
- Added modal errors for explicit GIS Refresh Records/Retry failures. Passive loads, map movements, filter lookups, and geometry previews retain local status behavior.
- Completed report preview and PDF/XLSX feedback, exact MIME checks, safe server filenames, malformed-error handling, stale-export button state, and object URL cleanup even when download initiation throws.
- Added named/scoped field handling to officer-position, member lifecycle, and rejection forms. Kept their existing confirmation rules.
- Covered the actual `/dashboard` URL in the generic safe HTML error renderer. Kept specialized exception guards ahead of it and retained HTTP status/headers, including throttling recovery headers. Generic error pages use private/no-store and nosniff headers.
- Added server, controller, GIS, report, and specialized Income conflict regression tests, then verified an isolated production asset build.

## Architecture and data flow

Ordinary Laravel forms still submit to their existing controllers with their existing CSRF token and HTTP method. Controllers/services validate, authorize, and save exactly as before. After a redirect, the shared PHP component reads `success`, `error`, `invitation_result`, and all nonempty named validation bags and emits escaped JSON plus a visible HTML fallback. Errors take priority over stale success messages. The normal Laravel flash lifecycle expires messages; the component does not invent persistent session notifications.

The shared JavaScript controller reads that payload once and displays one native `<dialog>`. Each operation can carry an ID: repeated IDs are ignored, while two legitimate operations with identical messages still receive separate feedback. GIS, report, and Income handlers call the same controller explicitly; no global fetch interceptor was installed.

Validation form scripts mark only their own dialog with `data-feedback-restore`. Feedback is acknowledged first; then the marked form opens with its draft and field details. If another specialized dialog is already open, the result waits for it to close. Existing destructive confirmations and project finalization logic retain their ownership.

This keeps backend authorization and persistence separate from presentation. A disabled button or a hidden dialog does not grant or deny database access. No notification table, schema change, package, or parallel per-role notification service was added.

## Role and workflow coverage

| Workflow | Primary operation feedback | Preserved behavior |
| --- | --- | --- |
| System Administrator layout | Shared flash/errors component | Existing administrative permissions |
| Field Officer layout | Same component; former layout/page duplicates removed | Current assignment restrictions |
| Association Account layout | Same component | Association-facing account scope; no invented individual member account |
| Areas and Associations | Shared redirect result; only marked editor restored | Existing validation, selections, registration controls |
| User management and assignments | Shared result/errors; safe unavailable notice | Server role/assignment enforcement |
| Administrator invitations | `invitation_result` owned by shared component | Provider acceptance is not account activation; operation reference/status remain visible |
| Member applications and Field Officer member actions | Shared redirect result and scoped field errors | Photo/backend validation, review/lifecycle confirmations |
| Association officer positions | Shared result plus `officerPosition` details | Occupancy, identity/version and confirmation checks |
| Projects, Trainings, Program Activities | Shared redirect result; originating editor restored | Existing linked resources, participant/history and archive restrictions |
| Project completion/termination | Shared outcome, then correct named form if invalid | Specialized confirmations, exact confirmation words, immutability safeguards |
| Monitoring Production, Materials, Overview | Shared redirect outcome | Production calculations/revision checks and existing data protection |
| Monitoring Visit Reports | Shared result plus `monitoringVisit` details | Conductor/date validation; Add Area remains isolated |
| Monitoring Income save | Destination flash on success; generic result for ordinary failures | Typed HTTP 409 exclusively uses the specialized Income conflict dialog |
| GIS save and lifecycle mutations | Shared result after confirmed server outcome | Same routes/methods, geometry validation, ownership and finalization rules |
| GIS explicit Refresh Records/Retry failure | Shared error, local fallback | Passive map/status updates remain quiet |
| Reports Apply Filters | One result for current explicit request | Stale/aborted responses ignored; passive options do not open modals |
| Reports PDF/XLSX | One result after response validation and download request | Existing POST, CSRF, session, format and export permissions |
| Audit Logs and report/user unavailable pages | Shared safe error; detailed filter messages retained | Read-only behavior and existing response status |
| Staff sign-in validation/errors | Shared component and field details | Existing sign-in and session recovery controller behavior |

## Validation and flash ownership

All nonempty bags are included without merging their fields together. Forms declare their own `data-feedback-error-bag`; modal restoration also requires the existing record/action marker. Important bags include `monitoringVisit`, `officerPosition`, `projectClosure`, `projectTermination`, and the retained `memberLifecycle` summary. Default-bag editors use their explicit Area/Association/Project/Training/Program Activity/Monitoring markers.

Field-specific summaries remain as useful form details. They do not independently create result dialogs. Typed drafts are not reset by acknowledgement. Input values and server field messages are rendered as text. Existing finalization behavior may intentionally require renewed confirmation rather than replaying a destructive confirmation.

## GIS behavior

Save waits for a valid Feature response and acknowledgement before navigating to the register. Errors retain the draft. Lifecycle requests preserve their version token and CSRF header. Their confirmation closes before result feedback opens. A rejected or unconfirmed publication never produces success. Once a mutation is confirmed, a refresh failure produces a truthful success message with a refresh instruction, rather than implying the mutation failed and encouraging an unsafe repeat.

Background reads do not invoke the controller. Public GIS has no new mutation capability or global modal requirement. The dedicated former publication notification was removed in favor of the shared owner.

## Reports and downloads

The adapter intercepts the existing export form only after JavaScript initialization. It sends one same-origin POST with `FormData`, including the existing CSRF token and hidden filters. Authentication/authorization remain server enforced; `Accept: application/json` allows failures to be classified without navigating away. Redirects are rejected rather than downloaded as login HTML.

Only a successful, nonempty response with the exact PDF or XLSX MIME type can become an artifact. Server-generated `assocmap-...pdf/xlsx` filenames are retained; invalid names fall back safely. A temporary anchor requests the download, is removed, and its object URL is revoked. There is one Blob per response, with no additional ArrayBuffer/base64 copy or retained artifact collection. The existing reporting engine already validates and limits artifact sizes; this UI does not expand that server limit.

Feedback says the report was generated and the download was requested. Browser APIs cannot establish that the file was saved to disk. A full Blob is still needed for this fetch-based browser download, so it uses memory proportional to one bounded report. Without JavaScript, the existing native form submission/download remains available; modal acknowledgement is unavailable.

HTTP 403, 422, 429, 500 and 503 retain their response status internally. Relevant user messages distinguish permission, invalid input, throttling, session expiry and temporary server unavailability. Raw Python/database errors and response bodies are not used as primary modal messages. Network failures are described as an unconfirmed result and are not automatically retried.

## Specialized dialogs, accessibility and fallback

The typed `monitoring_income_conflict` HTTP 409 branch returns before generic error handling. Latest Saved Income remains a validated same-origin link in a new tab. Refresh still requires a second explicit discard confirmation. Keep Editing retains values, removal selections, and the old revision. The protected conflict partial and Income/Monitoring services have unchanged SHA-256 hashes against the saved pre-notification baseline.

The shared dialog has `role=alertdialog`, labelled title/description, native modal background inertness, centered responsive sizing, dim/blur backdrop, scroll protection, a single focused acknowledgement control, keyboard focus containment, and focus restoration. Backdrop clicks do not dismiss it. Escape is deliberately blocked: OK/Close acknowledges the result. No result timeout closes the dialog. Opening/acknowledgement animations respect reduced motion.

If JavaScript or native dialog support is unavailable, the server-rendered result remains visible. The existing JavaScript-dependent workspace dialogs still require JavaScript; the fallback does not claim to make the complete workspace functional without it. Native browser behavior, screen-reader announcements, visual blur, and actual device downloads require browser acceptance below.

## Verification actually executed

All PHP tests used the existing `TestEnvironmentGuard`, synthetic process-only APP_KEY, SQLite `:memory:` fixtures, array sessions/cache and blocked outbound HTTP. No application credential file or live database was used. Fixture schemas were created directly in isolated SQLite; migrations were not executed.

| Check | Actual result |
| --- | --- |
| Focused Laravel/Unit selection | 334 tests, 2,988 assertions; passed |
| Additional authentication, GIS scope and dashboard selection | 60 tests, 408 assertions; passed |
| Frontend suite | 71 tests; passed (Node VM/DOM doubles, not browser acceptance) |
| PHP syntax | 54 changed/untracked PHP/Blade files; passed |
| JavaScript syntax | 18 changed/untracked JS/MJS files; passed |
| Compiled Blade syntax | 19 targeted templates parsed with `TOKEN_PARSE`; passed within GlobalFeedbackTest |
| Asset build | Existing Vite configuration/dependencies, empty environment directory, scratch output; passed |
| Diff whitespace | `git diff --check`; passed |
| Protected hashes | MonitoringService, IncomeMonitoringService, SQL 015, Income conflict partial unchanged |

The focused selection includes GlobalFeedback, MonitoringUi, MonitoringIncome, MonitoringPeriod, ProductionMonitoring, ProductionMonitoringCalculation, ProjectClosure, AssociationLifecycle, GisFinalizedProjectMaintenance, AssociationOfficer, UserManagement and ReportAuthorization tests. Additional tests cover SupabaseInvitationAuth, GisScopedReads and DashboardService.

The broader run before the last focused additions executed **425 tests / 3,642 assertions**, with **14 errors and 27 failures**. An isolated snapshot reconstructed from HEAD plus the saved pre-notification patch reproduced the exact same 41 failing/error test case identities. That snapshot ran the three affected suites: **92 tests / 705 assertions**, with the same **14 errors / 27 failures**. Line endings were normalized when comparing source; dependencies were reused, and a prepended autoloader selected snapshot App/Tests code.

| Existing suite issue | Broader result | Evidence/limitation |
| --- | --- | --- |
| MemberApplicationSubmissionTest | 8 failures | Existing submission fixtures/response expectations fail before this UI work |
| MemberOfficerLifecycleTest | 14 errors, 17 failures | Includes old two-argument archive/unarchive calls, nonexistent `create()` and `admin.members.unarchive` references |
| UserInvitationWorkflowTest | 2 failures | Expects old member-applications destination; current login goes to role dashboard |

These tests were not weakened or the underlying business rules changed to make this feature appear green. Logs/XML and the isolated build are under `C:\dev\.codex-tmp\global-feedback-20261016`. The full repository test suite was not executed.

Reproduction commands from `web` (Windows PHP runtime as installed; synthetic APP_KEY only):

```powershell
$env:APP_KEY = 'base64:' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(('a' * 32)))
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --filter 'GlobalFeedbackTest|MonitoringUiTest|MonitoringIncomeTest|MonitoringPeriodTest|ProductionMonitoringTest|ProductionMonitoringCalculationTest|ProjectClosureTest|AssociationLifecycleTest|GisFinalizedProjectMaintenanceTest|AssociationOfficerTest|UserManagementTest|ReportAuthorizationTest' --colors=never
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --filter 'SupabaseInvitationAuthTest|GisScopedReadsTest|DashboardServiceTest' --colors=never
node --test tests/Frontend/*.test.mjs
```

## Required manual browser QA

Use a separately authorized test environment with synthetic data and matching application/schema versions. These checks were **not executed in a real browser** during this continuation.

1. For each of the three roles, complete one permitted action and trigger one rejection. Verify one centered result, correct icon/title/message, dim/blur, disabled background interaction, and no timer dismissal. Acknowledge, refresh, then repeat an independent action with the same message: no replay, but the new action must show feedback.
2. At desktop/mobile widths and 200% zoom, test Tab/Shift+Tab, Enter/Space, Escape, backdrop click, long text, screen-reader title/description, focus restoration and reduced motion. Verify only OK/Close dismisses the result.
3. Fail Visit conductor/date validation. Retain all draft values and show the Visit details after acknowledgement; Add Area must remain closed. Separately fail Area and Association validation. Verify only the marked editor restores and neither copies the other's `name`, area selections, or registration draft.
4. Fail Project, Training, Program Activity, member profile/rejection, officer-position and finalization forms. Verify the correct record/form, named bag, field text and draft. Destructive confirmations must retain their existing required confirmation behavior.
5. Use two Income editors: save the first, then submit the stale second. Verify only the specialized conflict modal, unchanged draft/removal/version, Latest in a new tab, Keep Editing, and two-step refresh/discard. Test ordinary 422/network failures separately and confirm one global result.
6. Save and publish/unpublish/archive/delete permitted GIS records. Verify one result after confirmation closes, pending-click prevention and truthful refresh-failure messages. Attempt out-of-scope/finalized-project violations. Pan/zoom/type searches and simulate passive read failures: no acknowledgement modal.
7. Apply report filters, including empty results. Change filters during a pending response: stale results must not produce feedback or exports. Simulate 403/422/429/500/503 and offline responses. Field errors stay on filters and internal server details remain hidden.
8. Export PDF and XLSX once, then double-click while pending. Verify one POST and one file, correct name/type/content, controls restored, and accurate requested-download wording. Test login HTML, invalid/empty binary, malformed 422 JSON, expired session, browser download restrictions, and changing filters during export. Inspect object URL cleanup.
9. Verify unauthorized routes still fail, Association Accounts cannot export or gain mutation controls, and assignment/ownership restrictions still apply. Confirm invitation activation/recovery/password setup retains its protected standalone flow.
10. Disable JavaScript and verify the result fallback and native export submission. Check existing noscript guidance for JavaScript-dependent workspace controls. Reenable JavaScript and verify no duplicate handler registration.

## Remaining inline/standalone behavior and limits

- Invitation activation, password setup, reconciliation and recovery retain their existing nonce/CSP-protected standalone result pages. They are deliberately outside this shared workspace modal; their security envelope was not weakened for UI uniformity.
- Mobile/API clients retain JSON responses. No Flutter UI was modified.
- Public GIS, passive map/lookup statuses, dashboard unavailable/empty states, project location read-status, readiness warnings and geometry previews stay inline. These describe persistent state or background activity.
- Form-specific validation summaries/details and browser constraint-validation bubbles remain. They are not competing flash/toast owners.
- Existing confirmation, navigation/discard and Income conflict dialogs retain their specialized behavior.
- The disabled, unconnected Change Email preview remains disabled. No unsupported account workflow was implemented.
- Real browser/screen-reader/download acceptance is pending. No real Python-generated artifact or live database workflow was exercised by these UI tests. Existing report authorization tests and synthetic binary frontend tests do not establish hosting or production readiness.
- Existing stale Member workflow and login-destination test expectations remain a separate repair task. The broader suite must not be reported as passing.

## File inventory

The following inventory separates feedback changes (including interrupted work retained in this continuation) from earlier dirty-tree work. It is not a list of staged changes. No files were staged.

### Feedback implementation and tests

- `web/app/View/Components/GlobalFeedback.php`
- `web/bootstrap/app.php`
- `web/resources/css/app.css`
- `web/resources/css/components/feedback.css`
- `web/resources/css/pages/gis-workspace.css`
- `web/resources/js/app.js`
- `web/resources/js/core/dialogs.js`
- `web/resources/js/core/feedback.js`
- `web/resources/js/core/gis-api.js`
- `web/resources/js/pages/admin/forms.js`
- `web/resources/js/pages/admin/reports.js`
- `web/resources/js/pages/gis-editor.js`
- `web/resources/js/pages/gis-workspace.js`
- `web/resources/js/pages/project-closure.js`
- `web/resources/js/pages/report-download.js`
- `web/resources/views/admin/areas/modals.blade.php`
- `web/resources/views/admin/associations/index.blade.php`
- `web/resources/views/admin/audit-logs/error.blade.php`
- `web/resources/views/admin/audit-logs/index.blade.php`
- `web/resources/views/admin/program-activities/index.blade.php`
- `web/resources/views/admin/projects/index.blade.php`
- `web/resources/views/admin/reports/index.blade.php`
- `web/resources/views/admin/trainings/index.blade.php`
- `web/resources/views/admin/user-invitations/create.blade.php`
- `web/resources/views/admin/users/assignments.blade.php`
- `web/resources/views/admin/users/register.blade.php`
- `web/resources/views/association/member-applications/index.blade.php`
- `web/resources/views/associations/officers.blade.php`
- `web/resources/views/auth/login.blade.php`
- `web/resources/views/components/global-feedback.blade.php`
- `web/resources/views/dashboard/index.blade.php`
- `web/resources/views/errors/feedback.blade.php`
- `web/resources/views/field-officer/member-applications/index.blade.php`
- `web/resources/views/field-officer/members/index.blade.php`
- `web/resources/views/layouts/admin.blade.php`
- `web/resources/views/layouts/association.blade.php`
- `web/resources/views/layouts/field-officer.blade.php`
- `web/resources/views/monitoring/dashboard.blade.php`
- `web/resources/views/monitoring/edit-overview.blade.php`
- `web/resources/views/monitoring/editor.blade.php`
- `web/resources/views/monitoring/partials/editor-script.blade.php`
- `web/resources/views/monitoring/visit.blade.php`
- `web/resources/views/monitoring/visits.blade.php`
- `web/resources/views/monitoring/workspace.blade.php`
- `web/resources/views/partials/gis-workspace.blade.php`
- `web/resources/views/projects/partials/finalization-modal.blade.php`
- `web/resources/views/projects/show.blade.php`
- `web/resources/views/reports/error.blade.php`
- `web/resources/views/reports/page.blade.php`
- `web/tests/Feature/GlobalFeedbackTest.php`
- `web/tests/Feature/MonitoringUiTest.php` (existing untracked test extended)
- `web/tests/Fixtures/global-feedback.blade.php`
- `web/tests/Frontend/area-modal-isolation.test.mjs` (existing untracked test extended)
- `web/tests/Frontend/gis-feedback.test.mjs`
- `web/tests/Frontend/global-feedback.test.mjs`
- `web/tests/Frontend/monitoring-income.test.mjs` (existing untracked test extended)
- `web/tests/Frontend/report-feedback.test.mjs`

### Earlier Monitoring work preserved

- `docs/monitoring-income-concurrency.md`
- `docs/monitoring-ui-qa.md`
- `supabase/migrations/015_add_monitoring_income_version.sql`
- `supabase/tests/local/monitoring-income.php`
- `web/app/Exceptions/MonitoringIncomeConflictException.php`
- `web/app/Http/Controllers/MonitoringWorkspaceController.php`
- `web/app/Http/Requests/StoreMonitoringRequest.php`
- `web/app/Http/Requests/StoreMonitoringVisitReportRequest.php`
- `web/app/Http/Requests/UpdateMonitoringSectionRequest.php`
- `web/app/Models/MonitoringRecord.php`
- `web/app/Services/IncomeMonitoringService.php`
- `web/app/Services/MonitoringService.php`
- `web/resources/views/monitoring/income-conflict.blade.php`
- `web/resources/views/monitoring/partials/income-conflict-modal.blade.php`
- `web/resources/views/monitoring/partials/visit-script.blade.php`
- `web/tests/Feature/MonitoringIncomeTest.php`
- `web/tests/Feature/MonitoringPeriodTest.php`
- `web/tests/Feature/ProductionMonitoringTest.php`
- `web/tests/Frontend/monitoring-conflict.test.mjs`
- `web/tests/Frontend/monitoring-period.test.mjs`
- `web/tests/Frontend/monitoring-visit.test.mjs`
- `web/tests/Support/MonitoringIncomeFixture.php`

### This report

- `docs/global-feedback-implementation.md`

