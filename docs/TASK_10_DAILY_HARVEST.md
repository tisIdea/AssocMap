# Task 10: Daily harvest implementation handoff

Status: source implementation prepared on `feature/daily-harvest`. NOT EXECUTED OR DEPLOYED.

No PHP/Python/Flutter/Dart execution, SQL, database connection, HTTP request, application server, automated test, build, formatter, dependency installation, environment change or Git mutation was performed. Review consisted of source reads, Git status/diff inspection and `git diff --check`. The worktree started clean. Runtime behavior remains unverified.

## Files

Added:

- `supabase/migrations/019_add_daily_harvest_production.sql`
- `supabase/tests/local/daily-harvest.php`
- `web/app/Models/MonitoringHarvestEntry.php`
- `web/app/Services/Monitoring/DailyHarvestMonitoringService.php`
- `web/resources/views/monitoring/harvest.blade.php`
- `web/tests/Support/DailyHarvestFixture.php`
- `web/tests/Feature/DailyHarvestMonitoringTest.php`
- This handoff.

Modified:

- `web/app/Models/MonitoringProduction.php`: source metadata, version cast and harvest relationship.
- `web/app/Services/Monitoring/ProductionMonitoringService.php`: shared exact decimal sum, daily-mode editor exclusion, revision metadata and exact goal percentage.
- `web/app/Services/Monitoring/MonitoringService.php`: reuse its existing transaction-bound authorization/parent locking; protect daily rows in the common legacy production writer.
- `web/app/Services/Audit/AuditLogService.php`: permitted harvest, initialization and goal snapshots for the existing audit viewer.
- `web/app/Services/Reporting/ReportDataset.php`: preserve missing daily observations and avoid fabricated monthly zero observations.
- `web/app/Http/Controllers/MonitoringWorkspaceController.php`: scoped workspace forms, history and mutation handlers.
- `web/app/Http/Controllers/Admin/MonitoringController.php`: source metadata in the shared editor payload.
- `web/routes/web.php`: role-specific Monitoring routes.
- `web/resources/views/monitoring/editor.blade.php`: instructions for creating a period before initializing daily harvest.
- `web/resources/views/monitoring/partials/editor-script.blade.php`: correct daily-mode read-only notice in the aggregate editor.
- `web/resources/views/monitoring/partials/production.blade.php`: initialization and daily register links.
- `web/tests/Unit/ProductionMonitoringCalculationTest.php`: decimal/percentage precision-limit coverage.
- `supabase/tests/local/README.md`: disposable runner scope and prerequisites.

## Schema and migration dependencies

The latest normal migration is 017. Number 018 is reserved in `supabase/deferred` for the independently approved geography cutover. Task 10 therefore uses 019 and does not require or activate 018.

019 requires the current Monitoring schema, especially 001/005, the zero-activity change 012, finalized-project semantics 013 and ancestry protection 014. Keep the existing 015/016 Income/Materials version migrations in the application's normal history. The migration checks table ownership, the zero-count constraint and the finalized-history helper. It must run as the trusted owner, with `anon` and `authenticated` roles available. Existing Laravel private-table access must remain available to the trusted server connection.

Changes:

- `monitoring_production.quantity_source`: `aggregate` by default, or `daily_harvest`. Source cannot change after insertion.
- `monitoring_production.harvest_version`: nonnegative monotonic application revision counter for harvest and goal mutations.
- `target_output` becomes nullable for daily mode only. An aggregate-specific check retains its previous required-target rule. Existing target and actual values are preserved.
- `monitoring_harvest_entries`: UUID parent/date/quantity/remarks, creator/updater timestamps and users, and void timestamp/user/reason. Foreign keys restrict deletion of the parent and referenced users.
- Quantities use exact PostgreSQL `NUMERIC` with explicit positive/range/scale checks compatible with `NUMERIC(14,2)`. The unconstrained typmod is intentional: PostgreSQL must reject extra fractional digits before any numeric assignment rounding can hide them.
- A partial unique index permits one active date per production parent. A separate parent/date/id index supports chronological history, including voids.
- Harvest guards enforce actual year/quarter number, Manila future-date validation, operational association/project ancestry, immutable parent/provenance and immutable void history. Daily production/product/monthly identities cannot be reparented or deleted.
- Deferred constraint triggers require all three monthly projections and the parent's actual output to match the active entries at transaction completion. They reject inconsistent direct monthly/quarterly writes without removing existing 005/014 constraints or triggers.
- RLS is enabled and all direct table/function access is revoked from PUBLIC, anon and authenticated. This follows the existing private monthly-table pattern; browser users operate through authenticated Laravel routes.

The migration is transactional with a 10-second lock timeout and intentionally fails on replay. It does not delete, convert, backfill daily records, or change historical aggregate quantities.

## Routes and authorization

All paths below have `/{area}/monitoring` prefixes, with `area` equal to `admin`, `field-officer` or `association`. `{record}` is a Monitoring UUID and `{production}` is scoped through that record. Route names retain the existing `{area}.monitoring` prefix.

| Method | Suffix | Name suffix | Access |
| --- | --- | --- | --- |
| GET | `/{record}/production/{production}/harvests` | `harvest.index` | Admin, assigned officer, own association account |
| POST | `/{record}/daily-production` | `harvest.initialize` | Admin, assigned officer |
| POST | `/{record}/production/{production}/harvests` | `harvest.store` | Admin, assigned officer |
| GET | `/{record}/production/{production}/harvests/{entry}/edit` | `harvest.edit` | Admin, assigned officer |
| PATCH | `/{record}/production/{production}/harvests/{entry}` | `harvest.update` | Admin, assigned officer |
| PATCH | `/{record}/production/{production}/harvests/{entry}/void` | `harvest.void` | Admin, assigned officer |
| PATCH | `/{record}/production/{production}/goal` | `harvest.goal` | Admin, assigned officer |

Association accounts have only the GET register route. Guests have no private route. Routes retain Supabase/session authentication and role middleware. Every write rechecks active actor/role, current assignment and operational ancestry under the existing locks. Submitted association IDs are not used for authorization. Normal Blade forms include CSRF and PATCH method spoofing.

## Data flow and calculations

The user opens an existing project/year/quarter workspace and initializes an unused active project product. The unit comes from its catalog and becomes the production row's fixed unit. To create a new period, use the existing Add Monitoring form without aggregate Production entries, then open Production and Start Daily Harvest. A separate conversion workflow is deliberately absent.

Blade form -> shared Monitoring controller -> daily harvest service -> existing Monitoring authorization/locking -> entry/goal write -> projections -> audit -> commit. Failed validation, authorization, database constraints or audit writes roll back the transaction. Parent locks serialize submissions; the production fingerprint rejects a stale/repeated form with 409. Equal-quantity edits still increment `harvest_version`. The register reads entries, summaries and revision under a shared record lock to avoid pairing old displayed values with a newer token.

For each product and month:

- Harvesting days = count of active dated entries.
- Monthly quantity = exact sum of their quantities.
- Quarterly actual = exact sum of the three monthly quantities, saved to `actual_output`.
- Percentage = quarterly actual / `target_output` * 100, rounded to two decimals with the project's existing `brick/math` dependency. It is not capped at 100%.

Three monthly projection rows start with internal count/quantity zero. They do not assert that harvesting was confirmed absent. UI uses **No harvest records** when count is zero and **Recorded to date** for an unfinished period. Percentage is absent if there are no active entries or the goal is missing/zero. No daily entries are synthesized.

Every goal set/revision requires a reason and writes previous/new target, reason, actor and timestamp through `AuditLogService` in the same transaction. Admin Audit Logs can display these allowlisted snapshots. Harvest initialization/create/edit/void also produce audit events. A void preserves the original quantity/date and required reason; it cannot be edited or restored. A corrected active entry can subsequently use that date.

## Historical, editor and reporting compatibility

Aggregate rows retain their current writer and reconciliation rules. Daily initialization rejects any existing row for the product-quarter, including populated aggregates. Omitted rows are preserved.

Both section and full-record legacy writers reach `syncProduction`. Daily rows accept only identity and independently authorized Income edits there; supplied monthly quantities/counts, goals, units or remarks are rejected. The shared editor disables these daily fields and identifies the dedicated workflow. New daily/goal operations require the same production revision used by old clients.

Reports already extract production, months and income separately; no harvest-to-income join was introduced. For daily rows without active entries, quarterly actual is sent as NULL through the existing nullable report contract. Daily empty-month projections are omitted; aggregate monthly zero rows remain. Existing Python rendering already supports missing observations, so no Python, chart or Flutter changes were needed. These totals are never summed across different units or added to their own monthly detail again.

## Tests added: NOT EXECUTED

`DailyHarvestMonitoringTest` uses the repository's isolated SQLite-memory/blocked-network convention. It covers admin and assigned-officer operations, assignment removal for every write, account/guest restrictions, scoped identities, duplicate and distinct-product dates, nonconsecutive dates and multiple months, exact totals/overflow, edit/date moves, void/replacement history, goal audit/missing/zero/over-100 handling, invalid/future/wrong-quarter/year input, stale/repeated submissions, equal-quantity revision changes, finalization/archive/inactive restrictions, legacy writer protections, aggregate preservation, reporting extraction and audit rollback for every operation.

The added unit test covers exact small sums, maximum-precision percentage, rounding and absent attainment.

The separate `daily-harvest.php` runner installs actual 019 on a verified fresh disposable PostgreSQL target. It uses exact relevant baseline/005 tables and 005/014 functions over synthetic ancillary fixtures, applies actual 012, then tests native constraints, deferred consistency, void/replacement, privilege metadata, audit-trigger failure rollback and finalization. Five real two-connection cases observe lock waiting and reject stale create/edit/void/goal/initialize submissions. This is focused PostgreSQL evidence when run, not full Supabase/full-schema integration or production verification.

## Commands for the reviewer to run after review

These commands were not executed. The worktree currently has no `web/vendor`; provide the already reviewed locked PHP dependencies in your verification environment first. Do not use the application's database for the test runner.

From `C:\dev\AssocMap-Harvest\web`, in an uncached isolated test workspace:

```powershell
php .\vendor\bin\phpunit --filter 'DailyHarvestMonitoringTest|ProductionMonitoringCalculationTest|ProductionMonitoringTest|MonitoringIncomeTest|MonitoringMaterialsTest|MonitoringManagementUiTest|ReportAuthorizationTest'
```

This suite is protected by `Tests\Support\TestEnvironmentGuard`: SQLite `:memory:`, array session/cache, blocked external requests, no project environment loading, and refusal of cached configuration. Do not bypass a guard rejection.

From the repository root, with a separately reviewed **new empty disposable local PostgreSQL manifest**, following `supabase/tests/local/README.md`:

```powershell
php .\supabase\tests\local\daily-harvest.php '<verified fresh disposable run.json>'
```

The runner needs existing locked PHP dependencies, PDO PostgreSQL and a trusted local owner able to create synthetic NOLOGIN roles. It never reads the project's environment credentials. Use a fresh target independent of all existing harness runs. Do not substitute a Supabase endpoint or an application database.

Optional PHP syntax review from the repository root:

```powershell
php -l .\web\app\Services\Monitoring\DailyHarvestMonitoringService.php
php -l .\web\app\Http\Controllers\MonitoringWorkspaceController.php
php -l .\supabase\tests\local\daily-harvest.php
```

After a separately authorized deployment of reviewed migration 019 and matching application source, inspect route registration with `php artisan route:list --name=harvest`. Application bootstrap may load its environment, so do this only in your approved verification environment.

Browser acceptance should cover desktop/mobile/keyboard forms, validation retention, escaped remarks, success/error messages, chronological pagination, one-product/date rejection, cross-month corrections, required void/goal reasons, no-record text, over-100% attainment, stale tabs, reassignment, account read-only/cross-association denial and finalized-project reads/write rejection. Verify current production, monthly, income and exports together with different units and more than one income month.

## Migration/recovery risks and remaining gates

- Review the actual database migration history, owners, RLS/privileges and existing constraints before applying 019 once. A reviewed backup and restore process, compatible server privileges and a coordinated application/schema release are prerequisites. Do not replay 001 or run deferred 018 for this task.
- Pending migrations, SQL syntax, PHP syntax, generated Blade, native constraints, locking races and audit behavior remain unexecuted. No runtime success is claimed.
- The application source and reports now require the 019 schema. Keep writers closed while coordinating deployment. New daily records are inaccessible to an old application and cannot be safely represented as manually entered aggregate rows.
- There is no destructive down migration. Before commit, a failed 019 transaction rolls back. After commit, preserve new harvest/audit data and prefer a forward repair. Dropping the table/metadata or restoring target NOT NULL can lose history or fail on unset daily goals. A database backup restore must be coordinated with application rollback and all subsequent writes.
- User-profile deletion can be blocked by the new provenance FKs. This preserves the responsible user for harvest history; do not null or remove that evidence to force a deletion.
- Deferred constraints require transactions that keep entries and their projections together. Direct custom SQL writers must follow the same lock order, projection/revision/audit contract and handle lock/deadlock failures. Public/authenticated Supabase clients have no direct harvest table access.
- Aggregate conversion, monthly zero confirmation, void restoration, new analytics charts and mobile mutation APIs are outside this batch. Goals for existing aggregate rows continue through their existing workflow; the new audited daily goal workflow applies to new daily-mode rows.
- The fixture runner is intentionally partial: current shared Supabase schema/RLS, real authentication, production timing, browser/device behavior and full-schema integration still require the user's own verification.

TASK 10 IMPLEMENTATION COMPLETE — CHANGES NOT EXECUTED OR DEPLOYED
