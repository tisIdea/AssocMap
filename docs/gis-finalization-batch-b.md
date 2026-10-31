# GIS Finalization Batch B

Date: 2026-10-30. Checkout: `C:\dev\AssocMap-STAGING`; branch `feature/member-application-r2`; starting HEAD `ae4302c519c0468ae2d82148ad43ba3f4f5690c7`. The starting worktree was clean. Reviewed [Batch A](gis-finalization-batch-a.md) and the [proposed reference catalog](gis-site-type-reference-catalog.md) before implementation.

Implemented live Flutter public-map classification and representative-marker parity, plus one additive Laravel catalog endpoint. No database structure, catalog contents, locations, geometry, publication, authorization, audit history or photographs were changed. The seven prepared classifications remain **proposed AssocMap project classifications pending final BFAR Region VII validation**, not an officially approved BFAR GIS taxonomy. No migration or seeder was executed, including in the Laravel tests selected for this batch.

## Before and after

| Area | Before | After |
| --- | --- | --- |
| Feature parsing | Flutter ignored the optional site-type fields and `display_point`. | Parses optional ID/code/name and a validated WGS84 representative Point. Retains original Point, Polygon/MultiPolygon coordinates, all parts/holes, and the exact original geometry discriminator separately. |
| Public boundaries | Live Flutter drew full boundary outlines; only actual Points had markers. | Actual Points use their original point; boundaries use only the server's representative point. Circular blue Point markers and purple square Boundary markers share site-symbol families; selection has a green, thicker outline and selected semantics. No centroid/coordinate estimation occurs. |
| Older responses | No distinction between a missing representative point and ordinary boundary rendering. | Missing/NULL additive fields remain readable. An older boundary without `display_point` stays in the list/details with an explicit missing-marker explanation; no outline or invented marker is substituted. |
| Classification | No live site labels or full site catalog. | Server names appear in lists/details, a separate site filter and the legend. NULL/absent classification is Unclassified; an assignment without a supplied label is Unknown classification. No category/name/geometry inference occurs. |
| Filters | Live geometry chips were labelled Locations / Areas, with a separate boundary visibility toggle. | Project Point / Project Boundary remain independent of site classification and search. Site choices use catalog IDs, plus All and Unclassified. Clear filters resets both dimensions. The redundant live boundary toggle is removed; mock polygon controls remain. |
| Catalog availability | Web embedded the complete `siteTypes()` catalog; no mobile catalog endpoint. | A complete read-only catalog request runs independently of map loading. Empty, unavailable, malformed and duplicate catalogs have explicit feedback, safe All/Unclassified choices and a catalog-only retry. No choices are inferred from the bounded feature response. |
| Details and accessibility | Generic project wording and category symbols; boundary meaning was unclear. | Live location wording, explicit geometry/site labels, representative-marker explanation, accessible 52px marker buttons, selected marker/list state, expandable legend and existing scrollable details/navigation. Established mock wording remains compatible. |

The symbol families match the web: cage, pen, seaweed, legacy fishing gear and post-harvest facility have dedicated Material icon equivalents. Culture pond, hatchery/nursery, payao, NULL and unknown codes use a neutral location symbol, as on the web. Catalog labels distinguish sites sharing a symbol. This does not introduce new official codes or require an SVG/image dependency.

## Exact changed files

| Responsibility | Files |
| --- | --- |
| Additive public catalog API | `web/routes/public/gis.php`; `web/app/Http/Controllers/PublicGisController.php` |
| Catalog API regression | `web/tests/Feature/PublicGisSiteTypesTest.php` (new) |
| Mobile transport and repositories | `mobile/lib/core/networking/public_gis_client.dart`; `mobile/lib/data/remote/api_public_projects_repository.dart`; `mobile/lib/data/repositories/public_projects_repository.dart` |
| Mobile models | `mobile/lib/data/models/gis_site_type.dart` (new); `mobile/lib/data/models/public_gis_feature.dart`; `mobile/lib/data/models/public_project.dart` |
| Mobile presentation | `mobile/lib/features/public_map/gis_site_symbols.dart` (new); `mobile/lib/features/public_map/public_map_screen.dart`; `mobile/lib/features/public_map/public_map_view.dart`; `mobile/lib/features/public_map/public_project_details.dart` |
| Mobile regression fixtures/tests | `mobile/test/public_gis_classification_test.dart` (new); `mobile/test/public_gis_widget_test.dart`; `mobile/test/support/public_gis_fixtures.dart`; `mobile/test/proposed_ui_test.dart` (test repository's additive method argument) |
| Report | `docs/gis-finalization-batch-b.md` (new) |

No dependency/lockfile, Android settings, authenticated API, environment, cloud/storage, migration, seeder, web JavaScript/CSS or Blade changes are included.

## Data flow and integrity

1. Laravel retains the existing public `GET /map/data` overview/map contract and publication scope. `GisQueryService::scoped(null)` requires active, nonarchived parents, an operational project-owned feature, publication provenance/title and readable geometry. Client filters cannot enable unpublished records or choose private role/scope fields. This batch changes neither that query nor the serializer.
2. The serializer still sends the original GeoJSON Feature geometry and optional `site_type_id`, `site_type_code`, `site_type_name`, `display_point`. For public boundaries the existing PostGIS query derives `display_point` using `ST_PointOnSurface`.
3. `PublicGisClient` uses the same HTTPS application origin/prefix for `/map/data` and new `/map/site-types`. Requests have no bearer token/cookie, disable redirects, require JSON, retain the existing 15-second total deadline/3 MiB limit and close their owned transport. No automatic retries or demo fallback are added.
4. `PublicGisFeature` validates the original geometry before accepting a feature, and validates supplied optional fields. `PublicProject` retains original geometry, original geometry type and representative point independently. Live rendering and camera fitting use `publicMarker`/`publicDisplayPoints`; original `location`/`boundary`/`geometryPoints` remain available. Mock rendering still consumes its fixture geometry.
5. `GET /map/site-types` returns `{"data":[{"id":...,"code":"...","name":"..."}]}` using the existing ordered `GisQueryService::siteTypes()`. It shares `GisRequestGuard` and `throttle:gis-read`, has no write route, performs one SELECT and returns no extra fields. The same catalog was already exposed to public web viewers. Runtime database permissions, RLS and sequence grants are unchanged; mobile receives it through Laravel, not direct Supabase SQL/REST.
6. The complete validated catalog lives only in screen memory. It is fetched once per live-map screen initialization, with an explicit retry on failure; map reload/search/selection does not refetch it. Site IDs or `unclassified` are sent to both overview and viewport map requests. No persistent GIS cache or classification assignment is created. Duplicate IDs/codes or malformed catalogs fail as a whole; unknown valid codes retain server labels and neutral symbols.

Geometry type, fisheries category, commodity and physical site classification remain independent. The API supplies no fisheries category/commodity for these public features, so Flutter does not fabricate them. Public result counts count GIS locations, not distinct projects. Legacy association-point rows are neither created nor rewritten and remain excluded by the existing operational server scope.

## Verification

All Laravel execution used `TestEnvironmentGuard` / `IsolatedDatabaseManager`, uncached process-only synthetic configuration, SQLite `:memory:`, temporary storage/cache/compiled views and blocked Laravel HTTP. Tables and rows were synthetic test fixtures, not migrations/seeders. Flutter used injected HTTP responses, synthetic coordinates/identities and fake tile providers. No staging/shared database, live Auth or R2 request was part of these tests.

| Check | Result |
| --- | --- |
| Pre-change Laravel baseline | **60 tests / 714 assertions passed**: feature serializer, scoped reads, GIS management UI and GIS photo access. |
| Pre-change Flutter baseline | **40 tests passed**: public GIS feature/API/widget, geometry and configuration tests. |
| Final Laravel regression | **82 tests / 761 assertions passed**: baseline suites plus geometry validation and the four new public catalog/filter tests. |
| Final Flutter regression | **197 tests passed**, the full local Flutter suite, including live GIS and unchanged mock/authentication/report workflows. |
| Static analysis | `flutter analyze --no-pub` passed with **no issues**. |
| PHP syntax | Controller, public routes and new endpoint test passed `php -l`. |
| Dart formatting / whitespace | All 14 changed/new Dart files passed the formatter check; `git diff --check` passed. |
| Web frontend checks | Not applicable to this batch: no web JS/CSS/Blade changes. |

New checks cover Point, Polygon with holes, multipart and single-part MultiPolygon, separate representative coordinates, exact geometry type preservation, missing optional fields, NULL and unknown classifications, fixed symbol/fallback families, complete/empty/duplicate/malformed catalogs, unavailable endpoint and retry, incomplete JSON, geometry/site filter independence, clear/reset, selection semantics, list-to-details interaction, viewport search and 200% text/keyboard/legend/details at 360px width. Existing malformed geometry, response-size/timeouts, authentication and mock workflows are included in the regression boundary. An initial broad run exposed three mock-label compatibility failures; mode-specific labels were corrected without weakening those tests.

Laravel JUnit evidence: `%TEMP%\assocmap-gis-batch-b-dd6dffbf57bb46b495bbc7ef17d9dd52\baseline.xml` and `%TEMP%\assocmap-gis-batch-b-5878a5df5e784c1187dc8adb60445543\after.xml`. Runtime: PHP 8.4.27 / PHPUnit 11.5.56; Flutter 3.44.6 / Dart 3.12.2. The SDK wrapper initially stalled; verification subsequently used the installed Dart executable and Flutter tools snapshot, with process-only analytics suppression and reviewed SDK lock/cache access. No global SDK settings or dependencies were changed.

Reproduction from `mobile/`: `flutter test --no-pub --concurrency=1 --reporter expanded` and `flutter analyze --no-pub`. Use the existing guarded synthetic Laravel harness for the six suites above; do not bootstrap tests through the application credential file or run database setup commands.

## Compatibility, deployment and rollback

| Combination | Expected behavior |
| --- | --- |
| Existing web/mobile + new backend | Existing endpoints/properties are unchanged. Older clients ignore the new catalog route and keep their prior rendering. |
| New Flutter + Batch A backend with catalog route | Full classification/filter/representative-marker behavior. |
| New Flutter + Batch A backend without catalog route | 404 is contained in the catalog panel. Map data and server-provided site labels/representative markers still work; All/Unclassified, geometry filtering and search remain available. |
| New Flutter + older response without site/display fields | Actual points render normally; boundaries remain readable through list/details with a missing-marker explanation. No classification or representative coordinate is guessed. |

Deploy the backend route/controller first under the existing approved web release/cache procedure, verify the public catalog and current map contract on the target, then build/distribute mobile. No schema deployment, migration, catalog seed, backfill, data reassignment or new database grants are required for this change. Loading proposed catalog rows remains a separately authorized future F08 action subject to the existing reference-catalog procedure and BFAR Region VII validation.

The existing `mobile/config/staging.json` selects API/live mode and `https://staging.assocmap.app`; it was inspected without changing it or exposing the publishable key. A future hosted backend needs its separately approved public build configuration. `ASSOCMAP_BACKEND_BASE_URL` must be an HTTPS application origin (optional supported application prefix), without `/api`, endpoint suffix, credentials, query or fragment. It is compiled into the app; changing hosting to another origin normally requires a new build. Do not ship a future production build accidentally targeting staging or include privileged provider keys in public defines.

Current Android source requires INTERNET, disables cleartext traffic and backup, uses Java 17 / the installed Flutter Android toolchain, and retains `com.example.mobile`, version `1.0.0+1` and debug signing for release builds. Those existing identifiers/signing settings require a separately approved distribution decision; existing installations need a compatible application ID/signing identity and a higher version code for an update. No Android release configuration was modified. A future authorized build can use `flutter build apk --release --no-pub --dart-define-from-file=<approved-target-config.json>` with existing dependencies; **no APK/AAB was built or distributed in this batch**.

During backend downtime, map requests display a safe retry state and never substitute fixtures; catalog failure alone does not hide map data. Imagery has its existing independent public HTTPS tile path, attribution, 15-second/1 MiB budget and outage message. Neither tile requests nor public GIS requests use authenticated Laravel tokens. Supabase login, eligibility checks, secure session storage and association scope remain in their existing separate paths.

Rollback is application-only. Removing the catalog endpoint leaves the new mobile client in the documented limited-catalog mode. Retain the existing `/map/data` contract, publication rules and site-filter support while installed clients remain in use; rollback to arbitrary older APIs that silently ignore filters is not verified. Rolling mobile back restores its previous polygon presentation. Keep coherent Batch A PHP/Blade/assets on any web rollback. Do not roll back migration 024, delete catalog rows, renumber IDs, remap assignments or undo legitimate later geometry/audit/media changes as part of this rollback.

## Privacy decision and remaining acceptance

**Complete eligible published Polygon/MultiPolygon coordinates are still transmitted by the public API and retained by Flutter, even though public web and new Flutter display representative markers. Anonymous full-boundary disclosure remains pending stakeholder privacy approval. This implementation is not that approval.** Any future withholding/generalization policy needs a separately reviewed server/API and installed-client compatibility change.

Not executed: native PostgreSQL/PostGIS geometry/row-lock/RLS tests, hosted database/API smoke checks, browser/screen-reader testing, real satellite-provider acceptance/terms/quota review, performance under production volumes or adverse real networks, Android release build/signature verification, emulator/physical-device installation and UAT. SQLite and widget tests do not establish those results. The full Laravel application suite was not rerun; verification was scoped to the affected API/GIS/photo boundary.

Remaining mobile stabilization: verify target endpoint deployment and real-device portrait/landscape/large-text/TalkBack/touch behavior, foreground/background and outage recovery, representative markers in the approved project area, network/TLS and provider imagery behavior; obtain final classification and public-boundary privacy decisions; complete approved application-ID/signing/versioning and build/distribution checks. Photos still use the existing unavailable placeholder on mobile; any future public-gallery integration must reuse authorized gallery/proxy endpoints and preserve private photographs. No registration/authentication redesign is included.

No migrations, seeders, shared-service writes, environment/cloud configuration changes, commit, push, merge or deployment were performed. Stop point: implementation and isolated verification only.
