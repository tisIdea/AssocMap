# Published boundary marker presentation amendment

Workspace: `C:\dev\AssocMap-GISCLASS`, branch `feat/gis-site-classification`, HEAD `e5e299e58d0e12b242c1fc1e95d545c5806b0b47`.

**GO for a focused GIS-CLASS commit.** No commit, push, merge, deployment or shared Supabase operation was performed. Migrations 023 and 024 and both Laravel wrappers are byte-for-byte unchanged from the verified pre-amendment working tree, including 024's finalized-history trigger correction. No migration or persisted coordinates were added. The catalog remains unseeded by migration source pending BFAR validation.

## Final behavior

The public map renders published Point, Polygon and MultiPolygon locations as classified point markers. Each boundary has exactly one calculated representative marker, using the same local SVG symbols as classified Point locations. Unclassified/unknown types use a neutral slate fallback. Public legend, popup and selection details explain that boundary markers represent calculated positions, not independently surveyed site coordinates.

The GeoJSON feature retains its full original `geometry` and `location_type = project_boundary`. Public properties gain `display_point`, a GeoJSON Point containing longitude/latitude. Point locations also expose their existing point as `display_point`. Staff and Association Account feature properties do not gain this public presentation field; their existing full-boundary display remains intact. Authorized staff editors continue receiving, displaying and saving the original Polygon/MultiPolygon coordinates with their existing geometry tools.

The public renderer uses a shallow feature copy solely for Leaflet drawing and retains the original feature in selection callbacks. Popups and galleries therefore use the original location identity and geometry. List selection fits the rendered representative point, not the polygon envelope. The public renderer never falls back to polygon outlines or an invented centroid if the additive point is unavailable or invalid; location metadata remains accessible through the list.

## Query, viewport and performance

`GisQueryService` derives the point with `extensions.ST_PointOnSurface(g.geom)` for readable boundaries. It uses a lateral projection with `OFFSET 0` to prevent the point expression being inlined and repeated across filtering and output. Original geometry serialization remains unchanged. The point is neither saved nor used for mutation payloads.

Public viewport filtering retains the indexable original `g.geom && envelope` prefilter, then intersects the representative point with the viewport. A marker within the viewport is included. A polygon edge intersecting the viewport while its marker is outside is intentionally excluded from the public marker view. Point positions are calculated from the entire original geometry and do not move as the viewport changes.

Public overview bounds use the same representative points. Staff viewport filtering and overview bounds still use full original geometries. The 100-feature/101-row probe, 2 MiB response budget and list pagination remain unchanged. Three and 100 representative markers each used one public feature query; no per-marker lookup or metadata request was introduced. Native checks also confirm the SQL contains one representative-point expression reused by viewport and projection.

## Executed verification

| Check | Result |
| --- | --- |
| Combined native PostgreSQL/PostGIS | **242 passed, 0 failed** on task-owned PostgreSQL 17.11 / PostGIS 3.6.2. Includes all 189 combined migration/security/media checks and 53 boundary-presentation checks. Cluster and generated credentials removed. |
| Independent classification PostGIS regression | **49 passed** in a separate disposable local cluster, also removed. Public property contract updated additively. |
| Laravel GIS and media regression | **312 tests / 2,678 assertions passed**, using the existing SQLite-memory isolation guard, synthetic identities, blocked Laravel HTTP and fake storage. After extending browser fixture exports, the complete UI test class also passed **12 tests / 236 assertions**. |
| Complete frontend suite | **272 passed, 3 existing skips, 0 failed**. Includes public Polygon/MultiPolygon rendering, original selection geometry, missing/invalid display points, classification symbols, neutral fallback and existing GIS request/selection/filter/correction behavior. |
| Browser | **126 passed**, headless Edge at 1280 px and 390 px. Real rendered Blade, built production bundles, Leaflet and Geoman; synthetic intercepted HTTP/images with external requests blocked. |
| Build/static | Vite production build passed. Affected PHP/JS syntax and diff checks passed. The new native test file passes Pint; modified PHP files retain exactly their pre-amendment Pint fixer categories. Protected migration hashes are unchanged. |

Native presentation fixtures include a concave polygon, a polygon with a hole and disjoint MultiPolygon parts. All three have centroids outside their surfaces. Checks prove the additive point equals PostGIS PointOnSurface and lies within the actual polygon; original API geometry, stored EWKB, version, publication metadata and type remain unchanged after presentation queries. They cover marker-inside/edge-only viewports, stable coordinates across viewports, individual/global map fitting, full staff boundary filtering/edit detail, approved boundary galleries, NULL classification, the 100-feature cap and removal after unpublication/archival. New fixtures run in rolled-back transactions.

Browser checks verify one public marker per boundary and no public polygon drawing, distinct classified symbols, neutral multipart fallback, matching legends, popup wording, Enter/Space selection and approved photo opening. They also verify list selection fits a single marker without drawing an outline. Staff workspace MultiPolygons retain both polygon parts. Polygon/MultiPolygon editors retain their type, all parts, Geoman controls and exact saved geometry, and submit no `display_point` field. Desktop/mobile screenshots were visually inspected; no horizontal overflow was observed.

Initial browser fixture assertions needed correction because Leaflet draws multiple polygon parts inside one SVG path, adding the unclassified boundary produces two matching list cards, and closing popups briefly remain during Leaflet's existing fade. The exporter also needed to flush Laravel's cached controller between synthetic geometry variants. The assertions were corrected to verify all parts and exact records without weakening the required behavior. No application defect was suppressed.

## Exact amendment files (15)

The preceding GIS-CLASS changes remain in the working tree. This amendment changes only:

```text
docs/gis-site-classification.md
docs/gis-boundary-marker-presentation.md
web/app/Services/GIS/GisFeatureSerializer.php
web/app/Services/GIS/GisQueryService.php
web/resources/js/pages/gis-map.js
web/resources/js/pages/gis-site-symbols.js
web/resources/js/pages/gis-workspace.js
web/resources/views/partials/gis-workspace.blade.php
web/tests/Feature/GisManagementUiTest.php
web/tests/Frontend/gis-classification.browser.mjs
web/tests/Frontend/gis-markers.test.mjs
web/tests/Postgres/GisBoundaryPresentationVerification.php
web/tests/Postgres/GisClassificationVerification.php
web/tests/Postgres/GisMediaClassificationVerification.php
web/tests/Unit/GisFeatureSerializerTest.php
```

The new native file is included by the existing guarded `RunGisClassificationVerification.ps1 -IncludeMedia` flow, after canonical Laravel migration 023/024 and their existing regressions. No direct SQL/wrapper double execution was introduced.

## Limits and reproduction

These results do not establish hosted Supabase grants/RLS/provider behavior, real object-storage delivery, authenticated deployed browser acceptance, device/touch/screen-reader acceptance, synchronized concurrency races or production-scale plans/latency. The full Laravel suite was not run. Browser responses are synthetic and not connected end-to-end to the disposable PostgreSQL database. Original boundary GeoJSON deliberately remains available to existing public API consumers; only this web viewer's rendering changes.

From `web/`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/Postgres/RunGisClassificationVerification.ps1 -IncludeMedia
powershell -NoProfile -ExecutionPolicy Bypass -File tests/Postgres/RunGisClassificationVerification.ps1
node --test tests/Frontend/*.test.mjs
npm.cmd run build
```

The 312-test Laravel command is the GIS/media command in `docs/gis-media-classification-verification.md`. For browser reproduction, export the real Blade fixtures with `GisManagementUiTest` and `ASSOCMAP_GISCLASS_UI_DIRECTORY` set to a guarded temporary `assocmap-gisclass-ui-<32 hex characters>` directory. Then pass that directory and a local Playwright package directory to `node tests/Frontend/gis-classification.browser.mjs`.
