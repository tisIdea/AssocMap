# GIS-MEDIA and GIS-CLASS combined verification

Branch: `feat/gis-site-classification`, baseline `e5e299e58d0e12b242c1fc1e95d545c5806b0b47`.

**Final result: 189 native checks passed, 0 failed; 311 Laravel tests / 2,671 assertions passed.** The migration leaves the initial catalog empty pending BFAR validation. No shared/production credentials, databases, Auth providers or object storage were used. No commit, push, merge or deployment was performed.

## Environment and canonical migration process

The guarded runner initializes a unique task-owned PostgreSQL 17.11 cluster in the system temporary directory, binds it only to `127.0.0.1` on a dynamically chosen port, and generates its own credentials. PHP verifies the role, database, address, port, data-directory identity and absence of existing application tables before creating fixtures. It uses PostGIS 3.6.2, an absent test-only environment-file location, uncached configuration, temporary Laravel storage and blocked Laravel HTTP. The runner stops the server and removes its cluster and credentials on success or failure.

Prerequisites use the repository's canonical schema and triggers:

1. Synthetic `auth.users(id,email)` and anon/authenticated role stubs provide local Supabase-owned dependencies. These do not establish hosted Auth integration.
2. SQL-only prerequisites 001-006 run once. Laravel creates its framework/infrastructure tables and migration ledger through the existing migrations.
3. SQL-only prerequisites 007-019 run once in order. Withdrawn 010 is a comment-only no-op; deferred 018 is absent and is not activated. Existing geographic flags remain false.
4. Laravel's `Artisan::call('migrate', ...)` runs the existing wrappers for 020-022. Their SQL is never executed independently.
5. Synthetic Project, Monitoring, material and published/unclassified GIS records are inserted under the real constraints. Every pre-existing public table is snapshotted.
6. Laravel migrates `2026_10_27_000000_add_media_photos`. All original table rows must remain identical. Four ready-photo fixtures are then inserted, one for each supported parent.
7. Laravel migrates `2026_10_28_000000_add_gis_site_types`. Original rows and all four photo records must remain identical; the existing GIS location gains only NULL classification.
8. The Laravel ledger must contain exactly one entry for each migration, in 023-before-024 order. Rerunning Laravel with both paths must leave data and ledger unchanged.

Neither 023 nor 024 canonical SQL is called directly by this harness. The five proposed categories are inserted only after proving migration 024 leaves the catalog empty; they exist solely in disposable test data.

## Defect found and correction

The combined native test reproduced SQLSTATE 23514 when maintaining classification for a finalized project's GIS location. Existing migration 014 compares old/new GIS rows against a fixed maintenance whitelist; it omitted the new `site_type_id` column. Earlier SQLite service checks did not execute this native trigger.

The correction belongs to new migration **024**, not applied migration history. It recreates the same `protect_finalized_project_history()` function with `site_type_id` added to its old/new GIS maintenance lists. Migration 024 requires the 014 safeguards and trusted owner. Native comparison proves those two whitelist entries are the only function-definition changes, with owner and ACL preserved.

Native regressions now prove classification maintenance works for finalized GIS while ownership transfer, creation-provenance changes, GIS deletion and Monitoring material edits remain rejected. New Project cover photo writes are also rejected after finalization. Migrations 014 and 023 and the existing 023 wrapper were not modified.

## Schema, RLS and runtime permissions

The test verifies all **22 media constraints and four catalog constraints** are validated, including all seven media foreign keys and the new GIS site-type foreign key. All eight foreign keys use DELETE RESTRICT. It checks all primary/unique/supporting indexes are valid and ready, including the eight explicitly named parent/recovery/partial-unique/filter indexes. Identity, code/name length bounds and classification nullability are checked against database metadata.

Behavioral SQL checks reject missing/extra media parents, broken parent/uploader/approver/replacement references, invalid dimensions, incomplete ready metadata, inappropriate replacement/public approval, duplicate ready covers and duplicate pending covers. Parent deletion remains restricted. Catalog code uniqueness/format, nonblank names and invalid classification references are enforced.

Both new tables have RLS enabled and no direct client policies. anon/authenticated have no effective SELECT/INSERT/UPDATE/DELETE/TRUNCATE/REFERENCES/TRIGGER privileges. Temporary SELECT grants inside rolled-back transactions still reveal no photo rows or the five seeded types, confirming default-deny RLS independently of missing grants.

A separate synthetic Laravel connection authenticates as a non-owner, non-superuser backend role with BYPASSRLS and explicit local grants. It models a trusted backend; it does not inspect or certify any hosted role. Actual service operations verify:

- Photo metadata CRUD and the four photo workflows work with the supplied backend grants.
- Catalog SELECT alone cannot perform the service's `FOR SHARE` validation: PostgreSQL returns 42501.
- SELECT plus narrow column permission `UPDATE(id)` permits that row lock. Catalog inserts and name updates remain denied.
- Existing base-table privileges and `EXECUTE ON public.user_has_role(uuid,text)` support existing finalization workflows under this non-owner setup.

These permissions are provisioned only in the disposable fixture. The migrations do not silently provision a backend role. Future shared rollout needs a separate review of the actual trusted backend role, its RLS access model, schema/table/function privileges and catalog row-lock permission. No client-facing catalog policies or administration endpoint were added.

## Application and preservation checks

Native checks cover category assignment/retry/publication/update/clear/omission, invalid/unknown IDs, public eligibility and viewport filters, legacy NULL records, Point/Polygon/MultiPolygon round-trips, reassignment and stale-session rejection. Classification remains separate from geometry and project fisheries category.

Real Eloquent relationships retain Project cover, GIS location, Monitoring visit and Monitoring material photo metadata. Their services read those records under the non-owner Laravel role. The real lifecycle reserves/uploads a cover replacement, visit photo, material photo and GIS photo, producing eight image variants on a local fake private disk. The real audit service records the events. GIS approval and full public-gallery eligibility remain functional.

## Results and limits

| Check | Final result |
| --- | --- |
| Combined canonical PostgreSQL/PostGIS | **189 checks passed / 0 failed**; cluster and credentials removed. |
| Independent classification PostgreSQL/PostGIS | **49 checks passed**, rerun with migration 024's history prerequisites; separate cluster and credentials removed. |
| Laravel GIS and media regression | **311 tests / 2,671 assertions passed**, SQLite in memory, blocked HTTP and fake storage. |
| Focused GIS frontend | **23 passed**, including SVG safety/cache, unknown-code fallback, keyboard selection, filters and correction/feedback paths. |
| Classification browser | **86 checks passed**, Edge at 1280 px and 390 px, real Blade/production bundles/Leaflet with synthetic intercepted HTTP. |
| Static checks | PHP syntax passes for all 15 affected PHP files; Pint passes on both updated native PHP scripts; PowerShell parser and diff checks pass. |

Initial runs failed on a malformed synthetic APP_KEY and prerequisite/role-fixture setup, including attempting the withdrawn comment-only migration 010 and an invalid Administrator-as-officer reassignment. Fixtures were corrected without changing canonical prerequisites or weakening assertions. The real finalized-GIS trigger defect was reproduced and fixed separately as described above. Deliberately asserted permission/integrity failures are expected passes, not remaining failures.

Untested: the full Laravel suite, older native media concurrency runner, new synchronized races, real Supabase Auth/Storage, hosted runtime grants/RLS, deployed browser sessions, device/touch/screen-reader acceptance, production-scale plans/latency and deployment acceptance. Browser data is intercepted; it is not end-to-end against the disposable PostgreSQL database. Actual production catalog contents were neither read nor modified; migration source is verified to seed no types.

## Reproduction

From `web/`, with the local PostgreSQL 17/PostGIS installation available:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/Postgres/RunGisClassificationVerification.ps1 -IncludeMedia

$env:APP_KEY='base64:' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(('a' * 32)))
php -d memory_limit=512M vendor/phpunit/phpunit/phpunit tests/Unit/GisGeometryTest.php tests/Unit/GisFeatureSerializerTest.php tests/Feature/GisScopedReadsTest.php tests/Feature/GisManagementUiTest.php tests/Feature/GisFinalizedProjectMaintenanceTest.php tests/Feature/GisPhotoTest.php tests/Feature/ProjectCoverTest.php tests/Feature/MonitoringPhotoTest.php tests/Feature/PhotoLifecycleTest.php tests/Unit/PrivateMediaStorageTest.php tests/Unit/PhotoImageProcessorTest.php

node --test tests/Frontend/gis-markers.test.mjs tests/Frontend/gis-workspace.test.mjs tests/Frontend/gis-correction.test.mjs tests/Frontend/gis-feedback.test.mjs
```

This follow-up changes migration 024 and the two existing classification verification scripts, adds `web/tests/Postgres/GisMediaClassificationVerification.php`, and updates/adds the two GIS-CLASS reports. Application controllers/services/UI and migration 023 remain as previously reviewed. **GO for a focused commit of the verified GIS-CLASS batch; no deployment or BFAR classification approval is implied.**
