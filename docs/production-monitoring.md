> Lifecycle update (2026-10-16): Association termination is withdrawn; 010 is a comment-only no-op placeholder. Completed/terminated project operational history stays read-only, with authorized GIS maintenance as the approved exception. Pending 011/013/014 have source corrections; 012 is unchanged and none were executed. Earlier migration-order/database-gap statements below describe the Production refactor at that time and are superseded by the [current policy](project-closure.md) and [GIS migration/verification guide](gis-finalization-maintenance.md).

# Production Monitoring monthly workflow — source implementation review

Status: source implementation complete for the approved monthly workflow. No PHP, Laravel, Python, Flutter, test, build, SQL, migration, live Supabase, install, deployment, commit or push commands were executed. Runtime behavior remains unverified.

## Existing architecture and confirmed behavior

- Domain tables belong to `supabase/migrations`. Migration 005 creates `monitoring_production_monthly`, its identity/quarter triggers, unique parent/month constraint and the old `activity_count > 0` check.
- `MonitoringWorkspaceController` supplies shared role-specific workspaces and editor pages. Existing Admin/Field Officer controllers receive creation and whole-record updates; section PATCH requests use the workspace controller.
- `StoreMonitoringRequest` validates creation/full updates; `UpdateMonitoringSectionRequest` selects section-specific rules. `MonitoringService` owns writes and transactions. Existing model relationships map quarterly Production, monthly details and Income.
- Previously, submitted `actual_output` was persisted directly. Monthly details were optional supplementary observations, with positive activity counts; their subtotal did not recalculate the quarterly column. Omission during production synchronization could delete quarterly Production and cascade its Income.
- The inspected active form used the label **Initial Production**, rather than the request's quoted Initial Input. It now displays **Quarterly Actual Output** as a calculated read-only output.
- No monthly-versus-quarterly **production frequency** dropdown exists in the inspected source. The existing frequency selector is explicitly **Income Reporting Frequency**. It remains intact. The optional production month picker and add/remove-month controls were replaced with three fixed month sections. Year and quarter selection remain.
- `projects.status` already supplies current project dashboards and portfolio distributions. `monitoring_records.project_status` was saved separately; MonitoringService does not write `projects.status`.

## Implemented data flow

1. Existing authentication and role middleware establish the verified actor; FormRequests validate the request. Association Accounts retain viewing permissions only.
2. Inside the existing retrying database transaction, MonitoringService locks and rechecks the actor's active profile/role, then the association and project. Current association assignment and existing role scoping are rechecked.
3. The existing lifecycle services reject inactive/archived associations, archived projects and completed projects, including legacy completed projects without closure metadata.
4. Creation preserves the unique project/year/quarter register and existing product-registration workflow. Editing locks the Monitoring row and verifies a Production revision fingerprint before writing.
5. Each selected product must belong to the monitored project and occur once. An existing Production row must belong to that Monitoring record and retain its product and measurement unit.
6. `ProductionMonitoringService::calculateActualOutput()` validates exactly three distinct applicable month rows, explicit quantities and activity counts, and the selected year/quarter.
7. MonitoringService saves the product's quarterly row, updates/inserts its monthly rows, reads the persisted three-month set again, and calculates/saves the quarterly Actual Output inside the same transaction. A failure rolls everything back, including an initial visit or inline resource created earlier in that transaction.
8. Existing controller redirects and success notices remain. The form adds submission feedback and disables duplicate submission while processing. Stale revisions return a controlled conflict; database write failures on production paths return a generic error without SQL diagnostics.

The browser previews the total. The server decides what is stored. Changing a browser output or submitting an arbitrary `actual_output` cannot change the calculated result.

## Validation, periods and decimal arithmetic

| Quarter | Required month numbers | Display |
|---|---|---|
| Q1 | 1, 2, 3 | January–March |
| Q2 | 4, 5, 6 | April–June |
| Q3 | 7, 8, 9 | July–September |
| Q4 | 10, 11, 12 | October–December |

The calculator is the shared source of the month mapping; Blade passes it to JavaScript. Quarter IDs need not equal quarter numbers. Years remain 2000–2100.

- Each monthly quantity accepts nonnegative ordinary decimal notation, up to 12 whole digits and 2 fractional digits, matching NUMERIC(14,2). The quarterly sum must also fit NUMERIC(14,2).
- Arithmetic adds decimal strings as whole hundredths one digit at a time. It uses neither binary floating-point addition nor platform-sized quantity integers, BCMath, or a new package. The result is a two-decimal string.
- Activity counts accept integers from 0 through 2,147,483,647. Blank counts/quantities fail validation; they never become zero. All-zero quarters are valid when three explicit zero rows are supplied.
- Duplicates, out-of-quarter months, incomplete sets, excess decimal precision, negative values, overflow and foreign/stale monthly IDs are rejected. Existing months keep their saved IDs and month numbers.
- A selected product's total is calculated independently. Different products or units are never combined. Target Output keeps its existing meaning; it is not recalculated.
- No extra `activity_count = 0 implies total_quantity = 0` constraint was added. The schema and labels do not establish that both columns always describe the exact same events. That stronger rule requires a confirmed definition.

## Migration source and fields reused

Created `supabase/migrations/012_allow_zero_production_activities.sql`, after confirming 001–011 occupied the existing migration directory. It replaces only `chk_monitoring_production_activity_count` with `CHECK (activity_count >= 0)`, inside a transaction with a bounded lock timeout.

No historical migration is edited. Migrations **010 and 011 are unchanged**. Migration 012 contains no data updates, backfill, extra lifecycle triggers, foreign-key changes, new columns or changes to RLS/grants. A drifted/missing named constraint causes migration application to fail rather than guessing the live schema.

Reused fields: `monitoring_records.project_id/year/quarter_id/project_status/updated_by`, `monitoring_production.project_product_id/target_output/actual_output/unit/remarks`, and `monitoring_production_monthly.monitoring_production_id/month/activity_count/total_quantity/remarks`, with their existing IDs and timestamps. The revision fingerprint is a request value, not a database field.

## Transaction and concurrency guarantees

- Existing `DB::transaction(..., 3)` conventions remain. Profile/role shared locks precede association/project mutation locks. Editing then locks the Monitoring row and relevant Production rows; quarter reference locking precedes the monthly writes. Existing migration 005 also validates the Monitoring → Production → Quarter relationship.
- A project/association lock serializes ordinary application writers sharing that parent, including closure and termination. Eligibility is checked after obtaining those locks. A closure/termination that wins the race prevents later production writes; a production transaction that wins commits before closure/termination can proceed.
- The revision fingerprint covers Monitoring identity/status/update time and saved Production/monthly identities, values, remarks and timestamps. It prevents an old form from overwriting changed Production or a changed monitoring snapshot. It is not an authorization token.
- Existing Production identity and quarterly identity protections are retained. Saved monthly rows cannot be dropped, remapped or replaced with a different month's ID. Saved Production removal is disabled; omitting a product preserves it and its monthly/income history.
- The final persisted monthly set is validated again and used for the stored total. No standalone background or post-transaction recalculation exists.
- Real PostgreSQL lock scheduling, trigger interactions, retries and isolation behavior require separate verification. SQLite test source cannot prove these guarantees. Migration 005 explicitly requires READ COMMITTED for its relevant operations.

## Historical preservation and legacy editing

Historical reads show stored Actual Output without recalculation or inference. A quarterly row with no monthly records is labeled **Legacy quarterly-only**. Incomplete, invalid or sum-mismatched detail sets are labeled legacy/unreconciled and preserved read-only in the Production editor.

For an eligible project, an existing row is editable through the monthly workflow only if its saved set already has exactly the three valid quarter months and their sum equals its saved Actual Output. This is a consistency check, not proof of when the record was created; no provenance marker is invented. Editing that reconciled set is an explicit user action.

Legacy rows can be retained by submitting their saved identity only; attempts to change their quantities, unit, target or production remarks through this workflow are rejected. Users may add a different eligible product without converting a legacy row. Existing authorized Income recording against that saved Production remains available until lifecycle rules prohibit it.

There is no conversion or backfill endpoint. Safely converting a quarterly-only or unreconciled row needs a separately approved policy for retaining the previous quarterly evidence and reconciling an independently documented three-month set. No old total is divided, overwritten automatically or used to invent activity counts.

## Status and permanent lifecycle compatibility

- `projects.status` remains the authoritative current status for permissions, project management, closure and current portfolio/dashboard distributions.
- Monitoring views now label `monitoring_records.project_status` **Status During Monitoring**. The overview also displays **Current Project Status** from the project. The recent-monitoring dashboard badge is explicitly historical.
- Existing monitoring snapshot selection/correction remains explicit. Saving production does not change the project's current status. Closure does not rewrite monitoring snapshots.
- Existing ProjectClosureService, lifecycle services, confirmation requirements, authorization, audits, historical scoping, resources/training/activity guards and permanent-state rules were not rebuilt. No role gains historical access beyond its existing policy.
- Completed projects, including legacy completed rows, cannot add production, edit months, recalculate historical output or change Income through ordinary workflows. A inactive/archived association also blocks those writes, even when its project says ongoing.

### Implementation end date dependency — requires separate approval

`implementation_end_date` is currently recorded through ordinary Project Management, with its existing date validation. `closed_at` is generated by ProjectClosureService using server time. The closure request collects remarks, acknowledgement and a revision; it does not collect an actual end date or infer one.

Migration 011 explicitly rejects changing profile fields, including the implementation end date, in the completion transition. Adding an atomic end-date input to closure therefore conflicts with that unexecuted migration's approved trigger. This task expressly forbids editing 011, and extensive trigger changes require approval. That specific change is stopped. A separate decision is needed on whether the end date is mandatory and a forward-only trigger amendment is authorized. Meanwhile, an authorized user can record a known actual end date through existing Project Management before closing an eligible ongoing project. Existing completed projects and established dates remain untouched.

## Reporting and Income impact

`ReportDataset` already extracts quarterly `mp.actual_output` and monthly `total_quantity` independently, with product-to-project ownership validation. Python contracts already accept zero integer activity counts and decimal strings. The new saved total fits the existing contract without a schema/version change.

Python analytics already presents quarterly and monthly tables separately; no quantities are added across them or across units/products. The explanatory production note was updated to describe the shared achievement and retained legacy values. A month filter keeps the quarterly table as context and filters monthly details; no monthly share is inferred from quarterly-only history.

Income's monthly/quarterly selector, period validation, saved gross income, financial definitions and aggregation are unchanged. Production-only edits remove Income payloads from the write path. Preserving omitted Production avoids the previous cascading Income deletion. The existing explicit Income editing workflow remains; this task does not redesign it. Comprehensive Report failure handling, PDF/XLSX rendering and unrelated analytics were not changed.

The existing `MonitoringService::syncIncome()` can still remove omitted Income rows when an authorized user explicitly saves the Income workflow for an eligible project. This refactor does not add or invoke that deletion through Production-section edits. If immutable retention is also intended for ordinary Income edits on ongoing projects, that is a separate policy change; completed-project and finalized-project guards already prohibit those edits.

## Foreign keys and remaining integrity risks

**Fixed in this scope:** client-controlled Actual Output, optional/missing month values in new product submissions, positive-only activity validation, stale Production edits, saved monthly deletions, product/unit substitution, and omission-based Production/Income cascade deletion.

**Application-enforced, database gap remains:** equality of quarterly Actual Output to three months and finalized-project child-table immutability. Parent trigger 011 do not protect every child write. Direct SQL, service-role Data API writes, a future service or a role bypassing RLS could change/delete monthly/production/income rows or make totals inconsistent. No extensive child triggers were added. Reviewing deployed grants/RLS and approving child guards/total constraints would be separate work; live state was not inspected.

**Unresolved F02:** `ProjectService::update()` blocks association transfers when Monitoring exists, including its transaction recheck, but does not equivalently block or reconcile linked training/program-activity records for projects without Monitoring. Such a transfer may retain an older association on those children. This task does not transfer projects or redesign reassignment. Product and Production ownership is independently checked here.

All existing CASCADE/RESTRICT/SET NULL actions remain unchanged. ProjectService's existing resource-history guards continue to reject removing products/materials with monitoring references.

## Regression source added

- `web/tests/Unit/ProductionMonitoringCalculationTest.php`: all four quarter mappings, correct independent sums, explicit zero months/all-zero quarters, decimal boundary/precision, negative/missing/fractional/overflow counts and quantities, duplicate/out-of-quarter/missing/extra months, invalid year/quarter and total overflow.
- `web/tests/Feature/ProductionMonitoringTest.php`: assigned Field Officer/Admin creation and edits, per-product units/totals, malicious totals ignored, full/section updates sharing the calculator, recalculation, stale revision conflicts, quarterly-only/unreconciled preservation, omitted Production and Income retention, invalid product/month ownership, missing IDs/months, forbidden deletions, transaction rollback, deactivated actor, inactive/archived associations, archived/completed/terminated/legacy completed projects, unassigned officer, direct HTTP/account mutation denial, historical viewing, and request validation.
- `reporting/python/tests/test_analytics.py`: three-month computed output and a zero-activity month remain separate quarterly/monthly observations; month filtering retains quarter context; current project status remains independent of a historical snapshot. Existing coverage retains unlike-unit and Income-period checks.

These tests are source specifications only. They have not been executed. Feature fixtures use the repository's guarded in-memory SQLite setup and fake authentication/HTTP, not Supabase. SQLite fixtures do not emulate PostgreSQL precision, triggers or row locks.

## Manual migration and QA instructions

Do not apply any migration as part of this source-only change. For a separately authorized deployment:

1. Back up and inspect an isolated PostgreSQL copy; verify migration history, exact existing constraint name, NUMERIC(14,2) fields, quarter numbering, owners, grants/RLS and READ COMMITTED isolation. Do not replay baseline migrations.
2. Review/apply the approved unexecuted 010 and 011 in order, then 012 once, following the project's migration process. Confirm existing records and null legacy metadata are unchanged. Do not deploy zero-count submissions against the old positive-only constraint.
3. Inspect zero and negative count behavior, unique parent/month and project/year/quarter enforcement, quarter-bound and identity triggers, and decimal overflow/precision on real PostgreSQL. Confirm no extra zero-count/positive-quantity assumption was introduced.
4. In each role's browser workspace, create Q1–Q4 records. Verify the correct three fixed month names, editable Target Output/unit where applicable, readable field errors, and no editable Actual Output or production frequency selector. Income frequency and year/quarter controls must remain.
5. Submit 0, 125.10 and 175.20 with explicit counts; verify immediate 300.30 preview and persisted/reloaded total. Repeat with all zeros. Try blank, negative, extra-decimal, huge and tampered values; verify rejection or ignored client total as appropriate.
6. Create two products using different units; edit one month and verify only its product's total changes. Preserve the other product, visits, materials, Income and attribution. Reject monthly IDs from another product/Monitoring record and attempts to unlink/delete saved rows.
7. Open two production forms; save a changed quantity in the first, then save the second. Expect a conflict, preserved first result and a reload requirement. Repeat creation concurrently for the same project/year/quarter and verify one register only.
8. With separate real PostgreSQL connections, race production edits against closure, termination, reassignment and role deactivation. Confirm lock ordering/retries and no post-closure/termination child write. Inject a failure after a monthly save and verify parent, months and total all roll back.
9. View quarterly-only, partial and mismatched legacy sets; confirm original quantities remain unchanged and no monthly rows are fabricated. Attempt conversion directly over HTTP and confirm rejection. Verify completed history stays readable only to roles already authorized for that association/project.
10. Try edits as Admin, assigned/unassigned officers and Association Accounts, plus inactive/archived associations and formal/legacy completed and terminated projects. Include direct HTTP requests and forms opened before the lifecycle change.
11. Compare quarterly/monthly Production reports and PDF/XLSX exports: quarterly total appears once, month tables use recorded months, unlike units stay separate, absent observations remain missing. Check monthly/quarterly Income values and current-versus-historical status labels. Do not treat existing Comprehensive Report failures as verification of this change.
12. Verify keyboard navigation, labels, validation focus, mobile/tablet layout, cancelling a quarter change, duplicate-click feedback and browser back/forward restoration. Cancelled or failed submissions must not save partial data.

## Exact task file inventory

Created:

1. `web/app/Services/ProductionMonitoringService.php`
2. `supabase/migrations/012_allow_zero_production_activities.sql`
3. `web/tests/Unit/ProductionMonitoringCalculationTest.php`
4. `web/tests/Feature/ProductionMonitoringTest.php`
5. `docs/production-monitoring.md`

Modified:

1. `web/app/Services/MonitoringService.php`
2. `web/app/Http/Requests/StoreMonitoringRequest.php`
3. `web/app/Http/Requests/UpdateMonitoringSectionRequest.php`
4. `web/app/Http/Controllers/Admin/MonitoringController.php`
5. `web/app/Http/Controllers/MonitoringWorkspaceController.php`
6. `web/resources/views/monitoring/editor.blade.php`
7. `web/resources/views/monitoring/partials/editor-script.blade.php`
8. `web/resources/views/monitoring/partials/editor-templates.blade.php`
9. `web/resources/views/monitoring/partials/context.blade.php`
10. `web/resources/views/monitoring/workspace.blade.php`
11. `web/resources/views/dashboard/content.blade.php`
12. `reporting/python/analytics.py` (explanatory note only)
13. `reporting/python/tests/test_analytics.py`

Other pre-existing workspace changes were retained. Static review is separate from executable validation; no runtime success is claimed.

Static checks completed: `git diff --check` reported no whitespace errors; all 18 task paths exist; focused scans found no trailing whitespace/conflict markers or obsolete production month-add/remove controls. SHA-256 comparisons confirm migration 011 match their inspected pre-task contents. PHP/Blade/JavaScript/Python syntax and browser behavior remain unverified because executable checks were prohibited.
