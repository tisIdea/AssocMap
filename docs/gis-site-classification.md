# GIS-CLASS implementation and verification

The [published boundary marker amendment](gis-boundary-marker-presentation.md) records the final public display-point behavior and regression results. The combined migration verification below remains preserved.

Workspace: `C:\dev\AssocMap-GISCLASS`, branch `feat/gis-site-classification`.
Baseline: `e5e299e58d0e12b242c1fc1e95d545c5806b0b47`, equal to the local `origin/development` reference and including GIS-MEDIA. No remote fetch was performed.

## Final data model and migration

No existing physical site classification was found. `gis_locations.location_type` remains the geometry discriminator (`project_point` or `project_boundary`); `projects.fisheries_category_id` remains the project category.

Migration **024** adds `gis_site_types` with an identity BIGINT primary key, a unique lowercase stable `code` (50 characters), and a nonblank `name` (100 characters). It adds nullable `gis_locations.site_type_id`, an `ON DELETE RESTRICT` foreign key, and a B-tree index supporting classified and unclassified filters. Legacy locations retain NULL and their existing geometry, ownership and publication metadata. There is no backfill or automatic classification inference.

The catalog starts **empty**, as requested, pending BFAR stakeholder validation. No proposed labels are seeded. The SQL enables catalog RLS and revokes direct access from PUBLIC, anon and authenticated. Laravel remains the read/write authorization boundary. A non-owner backend role needs catalog SELECT and row-lock permission; the native test uses the narrow `UPDATE(id)` grant, while denying name/code updates and catalog inserts. Hosted runtime grants remain unverified. The Laravel wrapper follows the existing SQL-backed migration convention, translates the schema for isolated SQLite tests, and refuses automatic destructive rollback.

The initial verification excluded migration 023. The subsequent authorized combined run applies **023 then 024 through Laravel's migration command and ledger** in a fresh disposable PostgreSQL/PostGIS cluster. Neither canonical SQL file is executed separately from its wrapper. Migration 023 remains unchanged. See [the combined verification report](gis-media-classification-verification.md). No migration, catalog insert, Auth operation or other mutation was performed against shared Supabase.

Combined verification reproduced a real finalized-project defect: migration 014's GIS maintenance whitelist omitted the new column. Migration 024 now amends that function to permit `site_type_id` maintenance. It requires the existing 014 safeguards and trusted owner; the function's other logic, owner and ACL remain unchanged. Native checks confirm finalized ownership/provenance/deletion and Monitoring protections remain active. Applied migration 014 is not edited.

Deploying this code later requires applying reviewed migration 024 before serving the changed GIS pages or endpoints. This batch does not authorize that deployment.

## Assignment, authorization and public data

The existing editor offers an optional site classification. With an empty catalog it offers only Unclassified and explains that classifications await approval. Both points and boundaries can carry classification; their geometry rules remain separate.

Save requests validate a positive catalog ID or NULL. The service rechecks and share-locks the catalog row within the existing authorized mutation transaction. Creation defaults to NULL; an update that omits the new field preserves the current value for older clients, while explicit NULL clears it. Idempotency comparisons and ordinary GIS audit snapshots include `site_type_id`.

Existing actor/session freshness, current officer assignment, source/destination parent locks, version checks, finalized ownership protections and archived-parent rules remain in the original services. Classification maintenance follows the existing finalized-project GIS maintenance rule. Historical geometry correction remains geometry-only. Publication confirmation now includes classification because it is shown publicly when the location is published.

GeoJSON adds three scalar catalog properties: `site_type_id`, `site_type_code`, and `site_type_name`. Legacy values are NULL. The presentation amendment additionally exposes public-only `display_point` for marker rendering, preserving original GeoJSON geometry and `location_type`. Publication, parent eligibility and readable-geometry policies remain intact. Source inspection confirms the existing Flutter public parser reads named properties and tolerates these additions; no Flutter files or tests were changed or run.

## Markers, legend and filters

Point markers use small local inline SVG pins with white pictograms, green-led AssocMap colors and restrained blue/brown accents. The five proposed examples have different artwork, so recognition does not depend on color. Legacy and unknown types use a neutral slate point. Public Polygon/MultiPolygon locations use one classified representative marker from PointOnSurface; staff views retain full purple boundary outlines/fills and their own legend entry.

Map and legend use the same fixed SVG function. Catalog text is escaped or assigned with `textContent`, never interpolated into SVG. Click, Enter and Space selection remain available, with accessible names and a visible focus indicator. There are no marker image downloads or new animations.

Staff, Association Account and public viewers receive the site filter, including Unclassified. The existing form plumbing sends it to extent, viewport and paginated list requests; reset clears it. The selection panel, popup and list show the same classification. Existing galleries still load on selection.

## Query and performance impact

- One catalog SELECT per workspace/editor page; no per-marker catalog request.
- One left join on the catalog primary key for feature/detail projection; no Eloquent relation queries per feature.
- Filtering uses the indexed `g.site_type_id` column, including `IS NULL` for Unclassified.
- Overview and pagination retain their existing behavior. The 100-feature / 101-row probe, 2 MiB map response budget and 20-row list pagination remain intact.
- Native synthetic checks observed one public map query for one location and for a truncated 100-location response. This establishes constant query count, not deployed latency or production query-plan acceptance.
- At most six cached Leaflet icon objects are created for the five proposed symbols plus fallback. Gallery/storage queries are not added to feature loading.

## Executed verification

| Check | Result and evidence boundary |
| --- | --- |
| Focused Laravel regression | **99 tests / 1,153 assertions passed**: geometry, serializer, role-scoped reads, management UI, finalized/archived maintenance, classification create/update/clear/omission, idempotency, invalid assignments and HTTP payload validation. SQLite `:memory:`, synthetic identities, existing isolation guard and blocked Laravel HTTP. Final UI refresh also passed 12 tests / 222 assertions with fixture export enabled. |
| Combined Laravel regression | **311 tests / 2,671 assertions passed**, including the focused GIS tests and existing Project, Monitoring, GIS photo, photo lifecycle, image processor and private storage tests. Synthetic SQLite/fake storage, blocked Laravel HTTP. |
| Canonical combined PostgreSQL/PostGIS | **189 checks passed, 0 failed**, PostgreSQL 17.11 / PostGIS 3.6.2. Canonical prerequisite schema/triggers, Laravel 023 then 024 ledger, row preservation, all 26 media/catalog constraints and eight new foreign keys, indexes, default-deny RLS, non-owner backend permissions, real classification and four photo workflows with local fake storage, and finalized-history protections. Cluster/password removed. |
| Independent PostgreSQL/PostGIS | **49 checks passed** using the actual migration 024 and real GIS services in a task-owned loopback cluster. Legacy row preservation, empty catalog, FK/index/RLS/grant metadata, assignment/retry/update/clear/audit, stale versions and actors, public filtering/eligibility, Point/Polygon/MultiPolygon preservation, approved gallery metadata and constant query count/100-feature limit. Rerun after the trigger amendment with the canonical 014 function prerequisites. Migration 023 was not applied in this separate runner. Synthetic base tables do not establish deployed acceptance; no concurrent race was executed. |
| Frontend regression | **270 passed, 3 skipped, 0 failed**. Skips are the existing Association native-form cases requiring their separate exported fixture. New checks exercise real marker code, fixed SVG safety, icon reuse, legacy fallback, keyboard selection and filter propagation. |
| Classification browser | **86 checks passed** in headless Edge at 1280 px and 390 px using real rendered Blade and production Vite bundles/Leaflet, with synthetic intercepted HTTP and blocked external requests. Covers distinct symbols, polygon rendering, Enter/Space selection, publication preview, map/list filters/reset, version/CSRF/classification save payload, empty catalogs and no horizontal overflow. Screenshots inspected. This is not an authenticated deployed-app run. |
| Existing gallery browser | **82 checks passed** using the unchanged gallery browser suite and component. Synthetic HTTP/images; upload/retry, approval/withdrawal, ordering/removal, recovery, lazy image loading, keyboard/focus and responsive controls. Fixture markup was exported without invoking migration 023. No real storage/provider operation was run. |
| Build and static checks | Vite production build passed. PHP syntax passed for all 15 affected PHP files; JS syntax passed for all six affected JS/MJS files. Pint passes on the four new PHP files. The 11 modified PHP files have exactly the same Pint fixer categories as their HEAD baselines (six files already have formatting warnings); unrelated formatting was preserved. `git diff --check` passed. |

The pre-change GIS frontend baseline passed all 19 checks. The fresh worktree initially lacked dependencies and assets; locked Composer/npm dependencies were restored without install scripts or changes to manifests/lockfiles. Composer retained two existing PSR-4 fixture warnings. Initial new-test failures (SQLite BTRIM portability, a changed list-card child position, and loopback/feedback selectors in the browser harness) were corrected and the relevant assertions rerun without weakening them.

The later authorization permits 023 in isolated tests: the relevant PHP media suites are now included in the passing 311-test run, and the new combined native suite verifies both migrations. Focused frontend tests (23) and the classification browser suite (86) were also rerun successfully. The full Laravel suite, existing native concurrency media runner, hosted Supabase, real storage, deployed browser sessions, touch/screen-reader acceptance, production query plans and physical devices remain unverified in this follow-up.

Reproduction from `web/`:

```powershell
# Existing TestEnvironmentGuard prevents application environment loading and non-memory database use.
$env:APP_KEY='base64:' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(('a' * 32)))
php -d memory_limit=512M vendor/phpunit/phpunit/phpunit tests/Unit/GisGeometryTest.php tests/Unit/GisFeatureSerializerTest.php tests/Feature/GisScopedReadsTest.php tests/Feature/GisManagementUiTest.php tests/Feature/GisFinalizedProjectMaintenanceTest.php
node --test tests/Frontend/*.test.mjs
npm.cmd run build
powershell -NoProfile -ExecutionPolicy Bypass -File tests/Postgres/RunGisClassificationVerification.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/Postgres/RunGisClassificationVerification.ps1 -IncludeMedia
```

For browser reproduction, create a temporary directory named `assocmap-gisclass-ui-<32 hex characters>`, set `ASSOCMAP_GISCLASS_UI_DIRECTORY` to it, and run `GisManagementUiTest`. Pass that directory and an available Playwright package directory to `node tests/Frontend/gis-classification.browser.mjs`. To run the existing gallery script without 023, copy the exported `staff-gallery.html`, `public-gallery.html` and `photo.png` into a guarded `assocmap-gismedia-ui-<32 hex characters>` temporary directory as `staff.html`, `public.html` and `photo.png`, then run `gis-photos.browser.mjs` with that directory and Playwright. Neither browser fixture starts Laravel or contacts a shared service.

## BFAR decisions and future catalog process

Fish cage, fish pen, seaweed cultivation site, fishing gear site and post-harvest facility are **proposed examples only**. Their codes and SVG artwork are tested assets, not an approved taxonomy. BFAR must validate the labels, definitions, whether distinctions overlap and which sites should receive each label. No existing location is classified automatically.

Existing project lookup catalogs are maintained through reviewed schema/data migrations; this batch adds no catalog administration routes or CRUD interface. After BFAR approval:

1. Record the approved names, definitions and stable codes in project requirements.
2. Prepare a separate reviewed forward data migration using the next available migration number. Include only approved entries; never edit applied migration history or run application startup seeding.
3. Match approved codes to distinct symbols. The proposed asset codes are `fish_cage`, `fish_pen`, `seaweed_cultivation_site`, `fishing_gear_site`, and `post_harvest_facility`; new codes need reviewed artwork if the generic fallback would make categories indistinguishable.
4. Verify that migration and catalog permissions in an isolated environment, then obtain separate authorization for any shared deployment.
5. Reload GIS pages to obtain the new options/legend. Authorized staff can then assign types through the existing editor. Any historical backfill needs separate evidence and authorization.

## Exact changed files (31)

```text
docs/gis-site-classification.md
docs/gis-boundary-marker-presentation.md
docs/gis-media-classification-verification.md
supabase/migrations/024_add_gis_site_types.sql
web/app/Http/Controllers/GisWorkspaceController.php
web/app/Http/Controllers/PublicGisController.php
web/app/Http/Requests/GisQueryRequest.php
web/app/Http/Requests/SaveGisLocationRequest.php
web/app/Models/GisLocation.php
web/app/Models/GisSiteType.php
web/app/Services/GIS/GisFeatureSerializer.php
web/app/Services/GIS/GisLocationService.php
web/app/Services/GIS/GisQueryService.php
web/database/migrations/2026_10_28_000000_add_gis_site_types.php
web/resources/css/pages/gis-workspace.css
web/resources/js/pages/gis-map.js
web/resources/js/pages/gis-site-symbols.js
web/resources/js/pages/gis-workspace.js
web/resources/views/gis-editor.blade.php
web/resources/views/partials/gis-workspace.blade.php
web/resources/views/public/gis.blade.php
web/tests/Feature/GisFinalizedProjectMaintenanceTest.php
web/tests/Feature/GisManagementUiTest.php
web/tests/Frontend/gis-classification.browser.mjs
web/tests/Frontend/gis-markers.test.mjs
web/tests/Frontend/gis-workspace.test.mjs
web/tests/Postgres/GisClassificationVerification.php
web/tests/Postgres/GisBoundaryPresentationVerification.php
web/tests/Postgres/GisMediaClassificationVerification.php
web/tests/Postgres/RunGisClassificationVerification.ps1
web/tests/Unit/GisFeatureSerializerTest.php
```

## Commit decision

**GO for a focused GIS-CLASS commit**, with the intentionally empty catalog. This is not BFAR taxonomy approval or deployment acceptance. Shared rollout requires separate migration/runtime-role review and authorization. No commit, push, merge or deployment was performed.
