# Monitoring Materials concurrency protection

Implemented and locally verified 2026-10-17 (Asia/Manila). Supabase was not contacted. Migration 016 has not been applied to development or live Supabase. The user confirmed that migration 015 was already applied and structurally verified; this task did not execute or modify 015.

## Root causes and resulting behavior

Previously, Materials saved a replacement list without checking its age. Parent locks serialized writes but did not detect stale forms. Omitted saved observations were deleted. Request normalization also introduced `materials: []` into full-record requests that did not submit Materials. A pre-transaction existence rule could report 422 for a concurrently deleted observation before detecting the stale revision.

The Materials section and both legacy Admin/Field Officer full-record PATCH endpoints now use the same contract:

1. Preserve the distinction between absent input, submitted observations and requested removals.
2. Revalidate the actor/role, current assignment, operational association, unarchived project and permanent finalization restrictions through the existing services and locks.
3. Lock/reload saved Materials and compare the supplied revision before any mutations.
4. Validate submitted updates/removals and the final retained-plus-submitted material references.
5. Apply only submitted updates/additions and explicit removals, then advance `materials_version` exactly once in the same transaction.

Full updates check every applicable section revision before updating the parent or children. A later failure rolls back the entire update, including other section writes and counters. Monitoring creation retains its existing transaction and resource-registration workflow; new records receive version zero from the database default.

## Revision and request contract

`MaterialsMonitoringService::revision()` hashes the Monitoring UUID, project UUID, year, quarter, Materials version, and observations sorted by UUID. Each observation contributes its UUID, project-material UUID, condition, dates normalized to `Y-m-d`, and remarks. It uses the already loaded display snapshot. It excludes Income data/version, inventory quantities/delivery data and unrelated parent timestamps.

`MonitoringRecord` casts `materials_version` to integer and excludes it from mass assignment. A missing database column fails closed. The counter prevents an older revision from becoming valid again when values return to their original state, including under a frozen clock. It advances once for each accepted Materials save, including an intentionally empty/no-change save; rejected or rolled-back operations do not advance it.

| Submitted input on a full update | Meaning |
| --- | --- |
| Neither `materials` nor `deleted_material_ids` | No Materials operation; no Materials revision required or counter increment. |
| Only `materials_revision` | No Materials operation. |
| `materials: []`, `null`, or the HTML empty-string sentinel | Explicit empty Materials save; revision required; retain all observations. |
| Submitted observation rows | Update identified rows and create unidentified rows; preserve omitted rows. |
| `deleted_material_ids[]` | Remove only those saved observations; works without a `materials` field; revision required. |

A Materials section save always requires a revision. Missing/malformed revisions return validation errors; a well-formed obsolete/foreign/forged revision returns typed HTTP 409. UUID syntax and duplicate IDs are checked in requests and service validation. Saved observation membership is checked under the lock, after revision comparison, so a valid stale form containing a concurrently deleted observation receives 409 rather than a premature membership error.

Deletions must be unique, current-record observation IDs. An observation cannot be both edited and removed. Final material-reference uniqueness includes omitted retained observations. Project-material ownership is rechecked in the transaction. Existing condition/date/remarks validation and PostgreSQL constraints remain authoritative. There are no new date-period rules or inventory mutations.

## Feedback and recovery

The current section editor submits Materials through JSON with CSRF and one pending request. Success redirects to its normal workspace and flashes one global success result. Ordinary validation/access/server/network errors use the existing `AssocMapFeedback` controller once, with field details and the draft retained.

A typed `monitoring_materials_conflict` 409 has a dedicated Materials recovery dialog and never invokes the Income dialog or a simultaneous generic result. It retains values, original revision and removal markers. The authorized server-generated latest-editor URL opens in another tab after same-origin checking. Refresh requires a second explicit confirmation; Cancel/close restores focus. Backdrop clicks cannot dismiss this recovery dialog. Initialization is idempotent.

Ordinary HTML conflict submissions receive a private/no-store, escaped draft-recovery page with the global feedback component. Its whitelisted draft includes other supported full-update section fields so those edits remain available for copying too. No automatic retry, token replacement, merge or reload occurs. If a response is lost after a commit, the user must inspect saved state before retrying; this is not an automatic recovery/merge feature.

## Files and responsibilities

- `supabase/migrations/016_add_monitoring_materials_version.sql`: additive BIGINT/default/nonnegative check; no historical migration changes.
- `web/app/Models/MonitoringRecord.php`: counter cast.
- `web/app/Services/MaterialsMonitoringService.php`: snapshot revision, locked comparison and section-presence detection.
- `web/app/Services/MonitoringService.php`: both endpoint contracts, retained-state validation, explicit synchronization, transactional increment and confidential failure response.
- `web/app/Http/Requests/StoreMonitoringRequest.php`: preserve absence; revision/removal/identifier rules.
- `web/app/Http/Requests/UpdateMonitoringSectionRequest.php`: retain Materials contract fields in section validation.
- `web/app/Http/Controllers/MonitoringWorkspaceController.php`: displayed snapshot token and JSON success response.
- `web/app/Exceptions/MonitoringMaterialsConflictException.php`, `web/bootstrap/app.php`: typed JSON/HTML 409 handling.
- `web/resources/views/monitoring/editor.blade.php`, `partials/editor-script.blade.php`, `partials/materials-conflict-modal.blade.php`, `materials-conflict.blade.php`: form token, removal markers, feedback, accessible recovery and escaped fallback.
- `web/tests/Support/MonitoringMaterialsFixture.php`, `web/tests/Feature/MonitoringMaterialsTest.php`, `web/tests/Frontend/monitoring-materials*.test.mjs`, `supabase/tests/local/monitoring-materials.php`: synthetic verification.

## Executed local verification

PHPUnit used the existing isolation guard, SQLite `:memory:`, a process-only synthetic APP_KEY, uncached configuration and blocked external HTTP. It did not load application credentials. Command from `web`:

```powershell
# Supply a process-only synthetic APP_KEY, never application credentials.
php vendor/phpunit/phpunit/phpunit --filter 'MonitoringMaterialsTest|MonitoringIncomeTest|ProductionMonitoringTest|ProductionMonitoringCalculationTest|MonitoringPeriodTest|MonitoringUiTest|GlobalFeedbackTest|ProjectClosureTest|AssociationLifecycleTest|TestEnvironmentIsolationTest' --do-not-cache-result --colors=never --no-progress
```

Result: **256 tests, 2,354 assertions passed**, including 39 Materials cases. Coverage includes both legacy endpoints, absent/empty/deletion-only requests, additions/edits/removals and deleted-row conflicts, frozen-clock revision restoration, foreign/duplicate IDs, retained uniqueness, validation, assignment/role/actor/parent changes, account read/write boundaries, creation with visits, inventory preservation, rollback of mixed Income/Materials updates, confidential errors, display snapshot stability, escaped recovery, and one-time success feedback. Final output: `C:\dev\.codex-tmp\materials-php-regression.txt`.

Frontend command from `web`:

```powershell
node --test tests/Frontend/monitoring-materials.test.mjs tests/Frontend/monitoring-materials-conflict.test.mjs tests/Frontend/monitoring-income.test.mjs tests/Frontend/monitoring-conflict.test.mjs tests/Frontend/monitoring-period.test.mjs tests/Frontend/monitoring-visit.test.mjs tests/Frontend/global-feedback.test.mjs
```

Result: **55 tests passed**, including 19 Materials cases. These execute actual extracted editor helpers and shared feedback source in synthetic DOM harnesses; they are not real-browser acceptance.

Native command from repository root:

```powershell
php supabase/tests/local/monitoring-materials.php C:\dev\.codex-tmp\assocmap-pg-20261017_7747b3db\run.json
```

The server was a freshly initialized PostgreSQL 18.1 private cluster on loopback port 51842, database `assocmap_gis_test_20261017_7747b3db`, using READ COMMITTED isolation. The harness verified the explicit manifest, matching server data directory/port/database/owner, and empty public-table schema before creating synthetic fixtures. It refuses existing schemas. The fixture supplies the prior Income column shape without executing 015. It installs the exact Materials table/project-validation SQL from 001 and the actual history guard functions from 014, attached to Materials, Monitoring and project-material tables. It executes the actual proposed 016 file only in this disposable database.

| Native check | Observed result |
| --- | --- |
| Migration 016 | BIGINT, NOT NULL, DEFAULT 0; existing observations and Income counter preserved. |
| Version constraints | Negative rejected with 23514; NULL rejected with 23502. |
| Material uniqueness and referenced inventory deletion | Duplicate rejected with 23505; RESTRICT deletion rejected with 23001 on this PostgreSQL version. |
| Addition race | Second connection waited on `Lock/transactionid`; stale 409; winner retained two observations, version 1. |
| Edit race | Same observed wait and stale 409; winner retained two observations, version 2. |
| Removal race | Same observed wait and stale 409; winner retained one observation, version 3. |
| Late trigger failure | Insert/deletion/version all rolled back; version remained 3. |
| Completed and terminated history | Direct observation insert/update/delete and counter updates rejected by the actual 014 guard. |
| Current explicit deletion | Succeeded; zero observations, version 4. |

Evidence: `C:\dev\.codex-tmp\assocmap-pg-20261017_7747b3db\monitoring-materials-results.json`. Native SQL and service integration passed. The initial harness run expected generic foreign-key SQLSTATE 23503 for RESTRICT; PostgreSQL returned the more specific 23001. The harness was corrected and rerun on a fresh cluster. Early setup/test-harness errors were corrected; no final relevant test failures remain. Both task-created PostgreSQL servers were stopped after checking their canonical paths, ports and process identities. No repository-wide suite or browser acceptance was claimed.

This scoped native fixture does not reproduce the complete deployed PostGIS/RLS/grant/migration stack or project-finalization transition workflow. It verifies the relevant real Materials constraints and 014 history guard behavior, while the Laravel lifecycle suites cover application restrictions. The existing Production revision includes the parent timestamp, so a Materials save can still invalidate an older Production form; Production concurrency was intentionally preserved.

## Manual browser acceptance and deployment

1. After approved migration/deployment, use synthetic ongoing projects under active associations as Admin and the assigned Field Officer. Open Materials in two independent sessions.
2. Add/edit/remove in A; save stale B. Confirm Network 409, exactly one Materials dialog, A's intact data, and B's unchanged fields/revision/removal markers.
3. Open latest saved Materials in another tab. Verify the draft stays open. Cancel refresh, then explicitly confirm refresh on a separate copied draft.
4. Cancel and confirm row removal; inspect the saved observation ID marker. Remove an unsaved row and verify no marker is staged. Omit saved rows through direct requests and verify retention.
5. Verify one success modal at the normal destination; 422 field details, expired sessions and dropped responses preserve draft state and produce one appropriate result.
6. Test keyboard focus/Tab/Escape, close controls, blocked backdrop dismissal and mobile/tablet layouts. Repeat section navigation and initialization to check for duplicate handlers.
7. Exercise both full-record PATCH routes, foreign IDs, account write attempts, officer reassignment and archived/inactive/finalized parents. Recheck Income, Production, Overview, visits and Project Management resource references.

Deployment requires separate approval. Preserve open drafts and pause affected writes. Confirm the actual migration ledger contains the user-verified 015 and reserve 016. Apply 016 once through the approved workflow; verify its shape and history integrity. Deploy matching PHP/Blade/JS assets together, restart/replace all old workers, and refresh compiled views through the normal deployment process before reopening writes. Do not replay old migrations. Old Materials forms without a revision must be rejected and reopened after draft preservation. Reverting to omission-deleting application code is unsafe; use a reviewed forward fix.

Next recommended task: complete the manual two-session browser acceptance and approved deployment verification. Then review other replacement-list workflows separately; do not expand this fix into those modules.
