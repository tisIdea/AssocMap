# Monitoring Income concurrency fix

Prepared 2026-10-16 (Asia/Manila). Scope: F-01 Monitoring Income only. This change has not been deployed and no migration file was executed.

## Original defect and data flow

The Income editor loaded saved rows under each Monitoring Production record. Its Remove dialog removed a DOM row, but did not submit deletion intent. `MonitoringService::syncIncome()` deleted every persisted Income ID omitted from the request. An older form could therefore erase a newer addition even though writes were serialized by database locks.

The editor still uses the existing Laravel forms, CSRF token, section endpoint, Income identifiers and amount/period fields. Income saves now use a JSON response so a conflict can leave the form and its draft in place.

## Version and deletion contract

- The proposed `monitoring_records.income_version` is a nonnegative BIGINT, initially zero. It is server-maintained, excluded from mass-assignment fields, and increments once in the same transaction as every successful Income section save or full update containing Income edits. Rollback restores both data and version.
- `IncomeMonitoringService::revision()` hashes that version, Monitoring identity/period, saved Production identities and ordered Income facts. It uses the already loaded display snapshot rather than independently reading a newer revision after the displayed rows were loaded.
- A separate counter is needed because the existing Production revision is not an Income save counter, and timestamps/content alone can repeat when edits restore previous values within the same timestamp resolution. A frozen-clock regression covers this case.
- Submitted `income_revision` must match a freshly loaded revision after existing actor, association, project and Monitoring locks/eligibility checks. A mismatch throws `MonitoringIncomeConflictException` before writes and returns HTTP 409.
- Confirmed removals submit `production[index][deleted_income_ids][]`. Unsaved rows need no deletion identifier. Every removal ID must be unique and belong to the submitted saved Production record. The same row cannot be edited and removed together. Omission alone preserves saved Income.
- Retained and submitted entries are validated together against existing frequency, quarter/month, uniqueness and amount rules. Removing an entry requires explicit intent even if it is absent from the edit list.
- The legacy full-update endpoint uses the same Income revision and deletion rules. Production-only saves strip Income edits/removals.
- Income identifier existence/ownership is checked inside the transaction after revision validation, so a row deleted by another writer produces a stale conflict rather than failing an earlier existence check.

Row locks remain necessary: they serialize the current writes and protect authorization/state checks. The revision answers a different question: whether the form was based on the current saved Income state.

## Conflict and failure handling

The Income frontend submits FormData with the existing CSRF token and PATCH method spoofing. Success returns the authorized workspace destination. HTTP 409, validation errors, provider/session failures and network uncertainty leave the entered values and removal markers in the form. No automatic reload, merge, revision refresh or retry is performed. The user can open current Income in a separate tab for comparison.

A non-JSON conflict also returns HTTP 409, with escaped submitted values and a copyable recovery draft. Conflict responses are private/no-store. Database failures produce generic responses and sanitized logs, without SQL bindings or raw database diagnostics.

## Changed components

- `web/app/Services/IncomeMonitoringService.php`: display-snapshot revision and detection of full-update Income edits.
- `web/app/Services/MonitoringService.php`: transaction checks, version advancement, explicit scoped deletion, merged-state validation and safe database-failure handling.
- `web/app/Models/MonitoringRecord.php`: Income version integer cast; no client-assignable version field.
- `web/app/Http/Requests/StoreMonitoringRequest.php` and `UpdateMonitoringSectionRequest.php`: revision/removal shape validation; retain transaction-level identifier checks.
- `web/app/Http/Controllers/MonitoringWorkspaceController.php`: issue the displayed Income revision and JSON success destination.
- `web/app/Exceptions/MonitoringIncomeConflictException.php`, `web/bootstrap/app.php` and `web/resources/views/monitoring/income-conflict.blade.php`: dedicated JSON/HTML 409 handling and draft recovery.
- `web/resources/views/monitoring/editor.blade.php` and `partials/editor-script.blade.php`: revision input, explicit removal markers and draft-preserving saves.
- Focused tests/fixture and the disposable PostgreSQL runner listed below. One existing Production test fixture/call was updated to supply the required Income contract; its assertions were retained.

## Verification performed

PHPUnit was run directly with a process-only synthetic APP_KEY and the existing isolation guard. The guard prevents loading the project environment, permits only SQLite `:memory:`, rejects configuration caches/unsafe connections and blocks unfaked HTTP. No application credentials were used.

```powershell
# Supply a process-only synthetic APP_KEY; do not use project credentials.
php vendor/phpunit/phpunit/phpunit --filter 'MonitoringIncomeTest|ProductionMonitoringTest|ProductionMonitoringCalculationTest|TestEnvironmentIsolationTest|ReportAuthorizationTest|ProjectClosureTest|AssociationLifecycleTest' --do-not-cache-result --colors=never --no-progress
node --test tests/Frontend/monitoring-income.test.mjs
```

Final related PHP result: **185 tests, 1,535 assertions, all passed**. This includes **27 Income cases**: creation/update/deletion and identifier/precision preservation; two editors and stale additions/removals; current retries; omission preservation; frozen-clock restoration of earlier values; invalid/foreign/duplicate removal IDs; foreign update IDs; missing/forged/cross-record revisions; changed assignment and actor/parent state; cross-association/account denial; completed/terminated restrictions; rollback; generic database errors; full-update protection and valid full updates; display-snapshot consistency; escaped HTML draft recovery; retained-period validation.

Frontend result: **5 tests passed**, exercising actual helper source for conflict draft preservation, validation text, uncertain network outcome, success navigation and explicit removal markers. They are not real-browser acceptance.

Python command (repository root): `reporting/.venv/Scripts/python.exe -I -B -m unittest discover -s reporting/python/tests -p 'test_*.py'`. **24 tests passed**. Python contracts/calculations/exporters were not changed.

Native PostgreSQL: `supabase/tests/local/monitoring-income.php` ran on a newly initialized private PostgreSQL 18.1 cluster at loopback port 52885, database `assocmap_gis_test_20261016_67c56455`. Manifest, data directory, PID, connection identity and empty database were verified before synthetic fixture creation. The runner refuses an existing public-table schema and never resets a database. It used the shared synthetic fixture, not migration scripts.

- Addition race: second connection observed waiting on `Lock/transactionid`; winner committed; stale writer returned 409; one saved row and gross total `100.10` remained; version 1.
- Removal race: second connection observed waiting on `Lock/transactionid`; stale removal returned 409; both saved rows and gross total `300.30` remained; version 2.
- Fresh explicit deletion succeeded; retained gross total `200.20`; version 3.
- A late native trigger failure rolled back Income deletion/insertion and version advancement.

Evidence is outside the repository at `C:\dev\.codex-tmp\assocmap-pg-20261016_67c56455\monitoring-income-results.json`. Only this task's private server was stopped afterward. No live Supabase/application database was contacted.

SQLite does not prove PostgreSQL row-lock waiting. The native fixture proves the tested lock races and numeric/transaction behavior; it does not reproduce the complete deployed PostGIS/RLS/finalization trigger stack. Existing lifecycle checks remain in place, and related lifecycle tests passed. Browser and actual deployment acceptance remain unexecuted. Unrelated previously failing repository tests were not repaired.

## Migration and deployment requirements

Review SQL is in `docs/proposed-migrations/015_add_monitoring_income_version.sql`. It adds only the counter/default/check on the existing Monitoring table. No Income amounts, identifiers, periods, reporting facts, RLS policies or finalization triggers are changed by the proposal.

The Supabase CLI was unavailable in this environment, so SQL is left as a proposal; no migration was registered or executed. Register the reviewed forward-only SQL through the approved migration workflow, reconcile its sequence with the actual ledger, and apply it before enabling the matching application code. Do not replay prior migrations. Pause affected writes and replace/restart all old PHP workers together; old application code still has the original omission-delete behavior. Include the new PHP classes/views, refresh compiled views through normal deployment, and have users preserve drafts before reopening pre-update forms. Old forms without the new revision are rejected rather than silently accepted.

Supported Laravel Income writers advance the counter. Any additional direct writer introduced later must follow this contract. No claim is made that the counter replaces authorization or database lifecycle triggers. No audit vocabulary expansion was included; existing attribution and broader audit limitations remain.

## Manual browser QA after approved migration/deployment

1. Use a synthetic ongoing project under an active/unarchived association, as Admin and its assigned Field Officer. Open the same Income section in two independent sessions.
2. In A, add an eligible monthly entry and save. In B, save its older draft. Confirm a 409 in Network, a clear conflict notice, unchanged draft values/removal markers, and A's intact saved amount.
3. Open latest saved Income using the new-tab link. Confirm the original draft stays open. Re-enter reviewed changes against the new revision; save successfully.
4. Remove a saved entry, cancel the dialog and confirm nothing is staged. Confirm removal, inspect the explicit deletion ID, save and verify only that entry disappears. Repeat while the other session edits/adds; stale removal must conflict.
5. Try foreign/nonexistent IDs through direct requests. Confirm no cross-record change. Change the officer assignment, deactivate/archive the parent, and finalize separate synthetic projects as completed/terminated; old forms must not write.
6. Exercise invalid months, mixed periods, duplicate months, zero/max permitted amounts and remarks. Confirm validation preserves the draft. Simulate a dropped response and verify there is no automatic retry or reload; inspect current saved state first.
7. Compare Gross Income preview/PDF/XLSX totals before/after a rejected save. Valid saves must reflect exactly the intended saved records. Repeat direct full-update requests with current/stale revisions.

This completes the source fix and isolated verification, not live deployment or browser acceptance. Materials and other unrelated F-01 manifestations remain outside this task.
