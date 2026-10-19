# Monitoring UI corrections: QA record

Date: 2026-10-16, Asia/Manila. Scope: Income conflict presentation, duplicate Monitoring success notifications, and Visit Report / Add Area validation isolation.

## Verified corrections

| Issue | Responsible source | Correction |
| --- | --- | --- |
| Income concurrency response appeared inline | `editor-script.blade.php::saveIncome` | Typed `monitoring_income_conflict` responses retain HTTP 409 and open the dedicated Income dialog. Latest Income uses the server-generated URL, checked for same origin. Refresh requires the separate discard confirmation. Cancel and close retain the form. Other conflicts and validation errors remain inline. |
| Production success appeared twice for Field Officers | Field Officer layout plus `monitoring/workspace.blade.php` | Monitoring pages render their own success flash only for the Admin layout. Field Officer and Association layouts retain their existing success renderer. Dashboard and Visit Reports use the same ownership rule. |
| Visit errors opened Add Area | `admin/areas/modals.blade.php::$hasAreaErrors`, `forms.js::initForms` | Area restores only submissions carrying its own `area_form=editor` marker. Its old input is similarly scoped. Workspace Visit request and service validation use the `monitoringVisit` error bag, rendered in the Visit form. |

The Visit interface retains both conductor values until the user corrects the choice. Client guidance follows the existing service rule: new or changed attribution requires exactly one option; unchanged historical attribution may remain. Signed-in staff attribution, assignment checks, dates, schema and persistence rules are unchanged.

## Executed automated verification

| Verification | Final result |
| --- | --- |
| Monitoring UI, Income, period, Production and calculation tests | 119 tests, 863 assertions passed |
| Above plus Project Closure, Association Lifecycle and finalized-project GIS maintenance regressions | 227 tests, 2,064 assertions passed |
| Monitoring Income, conflict dialog, Visit, period and Area isolation JavaScript tests | 26 passed |
| PHP syntax checks on changed PHP classes/tests/bootstrap | Passed |
| `node --check resources/js/pages/admin/forms.js` | Passed |
| `git diff --check` | Passed |

PHP tests use the existing isolated SQLite `:memory:` guard, synthetic fixtures, mocked Supabase authentication and blocked outbound HTTP. No SQL migration files were executed. JavaScript tests use Node's built-in test runner and DOM doubles, including actual source helpers. They do not establish native browser layout, focus trapping or Escape behavior.

Covered cases include stale HTTP 409, current successful Income saves, draft/revision/removal preservation, explicit refresh confirmation, cancelling and closing the dialog, same-origin navigation checks, escaped server messages, non-JavaScript conflict recovery, one rendered Production success per writer role, no success on failure, duplicate-submit guards and repeated initialization, both conductor choices, invalid/both/neither choices, historical date/attribution retention, assignment/role/finalization denials, unchanged Area tables on rejected Visits, legitimate Area request/service validation and creation, and unique IDs on rendered Monitoring editors.

Initial runs exposed test setup gaps: missing synthetic encryption keys, a missing SQLite UUID default, lazy validator creation after swapping a database test double, and an older frontend fixture missing the submit state. Related fixtures were corrected. The broader regression run received a synthetic key only in its process environment; no application credential file was changed. There are no failing tests in the final executed checks.

Commands, from `web`:

```powershell
# Synthetic test-process key only; do not use an application credential.
$env:APP_KEY = 'base64:' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(('a' * 32)))
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --filter 'MonitoringUiTest|MonitoringIncomeTest|MonitoringPeriodTest|ProductionMonitoringTest|ProductionMonitoringCalculationTest|ProjectClosureTest|AssociationLifecycleTest|GisFinalizedProjectMaintenanceTest' --colors=never
node --test tests/Frontend/monitoring-income.test.mjs tests/Frontend/monitoring-conflict.test.mjs tests/Frontend/monitoring-visit.test.mjs tests/Frontend/monitoring-period.test.mjs tests/Frontend/area-modal-isolation.test.mjs
```

Local execution evidence is retained outside the repository at `C:\dev\.codex-tmp\monitoring-ui-20261016\regression-final.xml` and `regression-final.log`.

## Remaining manual browser QA

Not executed during this task. Use an approved environment and synthetic records only.

1. Open Income in two sessions. Save in A and submit B's stale form. Confirm one dialog, HTTP 409, unchanged draft/revision/removal selections, and no inline duplicate. Repeat as Admin and assigned Field Officer.
2. Open Latest Saved Income. Confirm a new tab and preserved original form. Cancel, close and Escape must retain the draft and return focus. Verify Tab / Shift+Tab remain within the native dialog.
3. Choose Refresh Page. Confirm no refresh occurs until Discard Draft and Refresh is explicitly activated. Keep Draft must return to the first action group. Check narrow screens and keyboard operation.
4. Save Production. Confirm one request and one success notice. Double-click Save; confirm only one pending submission. Exercise invalid values and a failed response, then confirm controls recover without false success.
5. Save a Visit with both conductor options and then neither. Client guidance must retain both fields; a direct invalid request must return Visit-scoped errors and preserve the remaining report. Add Area must stay closed. Check current officer, alternative name, historical officer/date and stale assignment cases.
6. Test Municipality, Barangay and Specific Area validation independently. Each must restore its own Area editor. Verify unrelated Visit text never populates it.
7. Disable JavaScript for an HTML stale Income submission. Confirm the HTTP 409 recovery page preserves escaped draft content and offers the safe new-tab link.

The repository's normal asset build is needed when releasing the shared JavaScript change. No deployment or browser acceptance is claimed here.

## Complete file list changed by this UI task

Paths are relative to `C:\dev\AssocMap`. This list excludes pre-existing changes that this task did not edit.

```text
docs/monitoring-ui-qa.md
web/app/Http/Controllers/MonitoringWorkspaceController.php
web/app/Http/Requests/StoreMonitoringVisitReportRequest.php
web/bootstrap/app.php
web/resources/js/pages/admin/forms.js
web/resources/views/admin/areas/modals.blade.php
web/resources/views/monitoring/dashboard.blade.php
web/resources/views/monitoring/editor.blade.php
web/resources/views/monitoring/income-conflict.blade.php
web/resources/views/monitoring/partials/editor-script.blade.php
web/resources/views/monitoring/partials/income-conflict-modal.blade.php
web/resources/views/monitoring/partials/visit-script.blade.php
web/resources/views/monitoring/visit.blade.php
web/resources/views/monitoring/visits.blade.php
web/resources/views/monitoring/workspace.blade.php
web/tests/Feature/MonitoringIncomeTest.php
web/tests/Feature/MonitoringPeriodTest.php
web/tests/Feature/MonitoringUiTest.php
web/tests/Feature/ProductionMonitoringTest.php
web/tests/Frontend/area-modal-isolation.test.mjs
web/tests/Frontend/monitoring-conflict.test.mjs
web/tests/Frontend/monitoring-income.test.mjs
web/tests/Frontend/monitoring-period.test.mjs
web/tests/Frontend/monitoring-visit.test.mjs
```

No migrations or dependencies were added or changed. Hash checks confirmed `MonitoringService.php`, `IncomeMonitoringService.php`, and `015_add_monitoring_income_version.sql` match their pre-task bytes. Existing unrelated uncommitted work was retained. No live Supabase, real-record mutation, credential change, staging, commit, push or deployment occurred.

The Area restoration condition previously accepted errors from other default-bag forms as well. Positive Area ownership now addresses that shared collision. No duplicate IDs were found on the tested Monitoring editors; this task is not an audit of every unrelated application modal.
