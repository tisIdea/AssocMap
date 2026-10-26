# Public GIS API integration — 2026-10-14

Desktop 2 implementation of the approved public GIS plan. **Source implemented;
formatting, analysis, all new/changed tests, backend connectivity, browser/device
behavior and UAT are unverified.** No Flutter/Dart/Git/PHP/database command, build,
live-service request, migration or credential access was performed for this task.
The earlier 63-test pass predates this work and does not verify the current source.

## Endpoint and source authority

The client uses only **GET /map/data**, relative to the configured Laravel application
base URL. Initial loading and submitted searches request mode=overview, followed by
mode=map with bbox[0] through bbox[3] in west/south/east/north order. “Search this
view” skips overview and sends the visible extent. Optional public filters are trimmed
q (at most 100 Unicode code points) and location_type (project_point or
project_boundary). No private association/project/locality IDs, publication or
lifecycle filters are sent. No request is triggered for every pan/zoom frame.

/map/associations, list pagination and /api/v1/me are **not used by this adapter**.
The existing authentication repository and session/guard behavior are unchanged.

Read-only source references:

- [Public route declarations](../../web/routes/web.php): public guard and gis-read throttling.
- [Public controller](../../web/app/Http/Controllers/PublicGisController.php):
  calls features(null, ...), explicitly choosing anonymous publication scope.
- [Request rules](../../web/app/Http/Requests/GisQueryRequest.php): mode, bbox, search/filter rules.
- [GIS query and serialization](../../web/app/Services/GIS/GisQueryService.php):
  public projection, overview extent, map limits and readable published geometry.
- [Feature serializer](../../web/app/Services/GIS/GisFeatureSerializer.php):
  exact anonymous public fields.

These describe the available reference copy, not proof of Desktop 1 deployment.
Desktop 1 must confirm the current deployment and isolated test environment.

## Exact consumed response contract

Overview (synthetic example):

    {
      "type": "FeatureCollection",
      "features": [],
      "meta": {
        "bounds": [124.9, 11.0, 125.2, 11.1],
        "empty_reason": null
      }
    }

Null bounds with a nonempty reason is a valid empty overview and ends loading without
a map request. Equal axis endpoints for a single point are expanded by 0.001 degrees
within WGS84 limits for the map bbox query.

Map (synthetic example):

    {
      "type": "FeatureCollection",
      "features": [{
        "type": "Feature",
        "id": "11111111-1111-4111-8111-111111111111",
        "geometry": {"type": "Point", "coordinates": [124.95, 11.05]},
        "properties": {
          "location_type": "project_point",
          "title": "Synthetic public point",
          "address": null,
          "description": null
        }
      }],
      "meta": {"limit": 100, "truncated": false}
    }

Feature id is a public GIS location UUID. Title is a nonblank published public title,
not an inferred association/project name. Address and description are nullable strings
and map to optional public address and summary presentation. No association name or
livelihood category is manufactured when absent. Location-type chips are UI labels
for the two actual public codes.

| Public location_type | GeoJSON type | Coordinates | Presentation |
| --- | --- | --- | --- |
| project_point | Point | [longitude, latitude] | Actual point marker |
| project_boundary | Polygon | [exteriorRing, ...interiorRings] | One polygon retaining holes |
| project_boundary | MultiPolygon | [[exteriorRing, ...interiorRings], ...parts] | All polygon parts retaining their holes |

Live parsing requires finite, two-dimensional WGS84 positions, the correct type/geometry
pair, closed rings with at least three distinct vertices, at most 2,000 positions per
feature, unique UUIDs and at most 100 features. Invalid input rejects the whole result;
invalid features are not silently dropped. Structural checks do not replace PostGIS
topology validation. A boundary has no fabricated centroid or companion marker.
The viewport is never substituted for actual feature geometry.

Map metadata has limit=100, boolean truncated and optional empty_reason when empty.
Source limits include a 2 MiB feature-payload budget; the client permits at most 3 MiB
for the complete JSON response. Truncated results remain visibly partial. A map count
describes returned locations, not all database records. Empty reasons include
no_records, filtered, geometry_unavailable, outside_view and display_limit.

## Configuration and offline behavior

ASSOCMAP_GIS_MODE=mock|live selects public GIS independently of authentication.
Default without any defines is mock. For compatibility, an omitted/empty GIS define
inherits the old data setting: ASSOCMAP_DATA_MODE=mock → mock GIS, api → live GIS,
invalid → configuration error. An explicit GIS setting overrides only GIS selection.
Unknown GIS values fail closed.

ASSOCMAP_BACKEND_BASE_URL is an approved reachable HTTPS Laravel application base
URL, optionally with its application prefix. Do not append /map/data or /api/v1.
User-info, query strings, fragments, HTTP and missing hosts are rejected. This setting
is non-secret configuration. No temporary hostname is embedded in source.

Switching modes requires an explicit define and application restart. A live request
failure never selects the bundled mock repository. Mock mode remains fully local,
with the existing fixtures, synthetic labels and offline coordinate grid.

## Flow, feedback and security

User opens/submits/searches view → PublicMapScreen → repository interface →
ApiPublicProjectsRepository public filters → PublicGisClient HTTPS GET →
Laravel public controller/query → published PostgreSQL/PostGIS geometry →
public GeoJSON → typed validation → actual markers/polygons/list/details.
Selection opens fetched public information without a private detail request.
Flutter uses setState/FutureBuilder; there is no new state-management package.

During loading no successful map is shown. Empty overview gives an empty panel;
empty viewport retains its requested extent so the user can pan and search again.
Live controls remain available in error/empty states and scroll on smaller screens.
Search is explicitly submitted; mock searches still filter locally. Retry remains in
the same mode. Show all locations requests an overview with the current filters;
Clear filters removes search/type and requests a new overview. Older future completions
do not replace a newer FutureBuilder request.

Configuration, malformed JSON/fields, HTML proxy pages, transport failure, timeout,
HTTP errors and throttling have fixed safe messages. Raw server bodies, exceptions
and configured URLs are not displayed. Each request owns/closes its client; the
15-second timeout covers headers and body. Redirects are disabled; native non-200
responses are errors. Browser redirect/CORS failures may surface as safe transport
failures. No automatic retries, offline caches or mock fallback are implemented.

The only application header is Accept: application/json; no bearer, Supabase key,
token, password or application cookie is added. Browser-managed ambient cookies are
platform behavior; anonymous server projection must remain enforced independently.
There are no database/storage writes, private endpoints, location permissions or
third-party basemap requests. Publication/topology/privacy authority remains Laravel.
The live map has a neutral background and says no street basemap is configured.

## Exact authored files in this task

Modified:

- pubspec.yaml: declare already resolved http 1.6.0 directly.
- lib/app/configuration/app_config.dart: independent GIS mode.
- lib/data/models/public_project.dart: optional live presentation fields/point.
- lib/data/repositories/public_projects_repository.dart: viewport-capable interface.
- lib/data/repositories/repository_factory.dart: GIS-only selection.
- lib/data/remote/api_public_projects_repository.dart: public overview/map adapter.
- lib/features/welcome/welcome_screen.dart: GIS source labels.
- lib/features/public_map/public_map_screen.dart: live queries and result states.
- lib/features/public_map/public_map_view.dart: optional points, polygons, boundary
  hit selection and live extent.
- lib/features/public_map/public_project_details.dart: supplied fields only.
- test/app_config_test.dart, test/repository_test.dart, test/widget_test.dart.
- README.md.
- docs/backend-contract-requirements.md, docs/development-status.md,
  docs/friday-prototype-readiness.md, docs/integration-checklist.md,
  docs/manual-verification.md, docs/mobile-architecture.md.

Added:

- lib/core/errors/public_gis_failure.dart.
- lib/core/networking/public_gis_client.dart.
- lib/core/widgets/public_gis_mode_banner.dart.
- lib/data/models/public_gis_feature.dart.
- test/support/public_gis_fixtures.dart.
- test/public_gis_feature_test.dart.
- test/public_gis_api_test.dart.
- test/public_gis_widget_test.dart.
- docs/public-gis-integration.md.

No lockfile regeneration, platform, authentication, Laravel or migration edits in
this task by Codex. The current lockfile already marks http as direct; a future
pub get should verify consistency. This is an authored inventory, not a Git diff
or synchronization claim.

## Tests authored — all unexecuted

Fake HTTP/model/widget cases cover public success, null descriptions, empty overview,
empty viewport, malformed overview/JSON/fields/metadata, network and HTTP failures,
rate limits, redirect policy, HTTPS configuration, response-body limit, body/header
timeout and client cleanup. Geometry cases cover coordinate order, unsupported/type
mismatch, WGS84/dimension limits, closed rings, holes, multipart preservation, duplicate
IDs and display limits. Widget cases cover loading, safe error/retry, no fallback,
actual boundaries without artificial markers, truncation, explicit submitted search,
viewport loading/recovery and phone/landscape/tablet layouts. Existing mock GIS tests
remain and configuration tests cover independence from authentication.

These fixtures are synthetic and transports are injected; no test requires a live
Laravel/Supabase service. Their presence does not mean they pass.

## Safe manual verification — provided, not executed

From C:\dev\AssocMap\mobile, when you authorize/run local checks:

    flutter pub get
    dart format lib test
    flutter analyze
    flutter test

Offline demo, separately authorized device launch:

    flutter run --dart-define=ASSOCMAP_DATA_MODE=mock --dart-define=ASSOCMAP_GIS_MODE=mock

Only after Desktop 1 supplies an approved isolated HTTPS test backend and live-service
access is separately authorized, replace <approved-https-base-url> locally:

    flutter run --dart-define=ASSOCMAP_DATA_MODE=mock --dart-define=ASSOCMAP_GIS_MODE=live "--dart-define=ASSOCMAP_BACKEND_BASE_URL=<approved-https-base-url>"
    flutter run -d chrome --dart-define=ASSOCMAP_DATA_MODE=mock --dart-define=ASSOCMAP_GIS_MODE=live "--dart-define=ASSOCMAP_BACKEND_BASE_URL=<approved-https-base-url>"

1. Resolve/format/analyze/test first; correct reported issues before rehearsal.
2. Rehearse mock mode with connectivity disabled, including existing demo
   navigation/authentication and GIS filters, markers, holes, multipolygons and summaries.
3. On the approved test service, open live GIS without login. Inspect overview/map
   GETs and bbox order; compare titles/geometries to sanitized approved test records.
4. Check hole/multipart rendering, polygon/list/point selection, boundaries toggle,
   search/type filters, pan/zoom then Search this view, and Show all/Clear filters.
5. Check empty overview/view, malformed/HTML response, offline/timeout/throttle and
   partial results. Confirm no synthetic records or success map appear after failure.
6. On Chrome inspect CORS, redirects/proxy challenges and actual public response.
   Check Android debug networking with trusted HTTPS; do not bypass certificate errors.
7. Check phone portrait/landscape, tablet, keyboard, enlarged text, scrolling and back
   navigation. Restart explicitly in mock mode for the independent offline fallback.
8. Record platform, mode, backend/reference revision, synthetic scenario and observed
   results. Backend publication/private-field tests require Desktop 1's test environment.

## Remaining blockers and defense questions

Current source has not been formatted/analyzed/executed. Dependency consistency
needs verification. Desktop 1 must confirm route deployment, approved HTTPS URL, public
publication fixtures, CORS and operational availability; backend integration/device
verification/UAT are pending. Android main manifest lacks INTERNET permission while
debug/profile manifests have it, so release networking needs separate platform work.
No street basemap/provider or production packaging has been approved. Real
Association Account login, protected workflows and private storage remain separate.

For defense: why is a location count not the database total? Why must a polygon retain
its holes and not become a centroid marker? Why does public publication scope belong
to Laravel even when no user logs in? Why do fake HTTP tests not prove deployed database
or device behavior? How can an explicit mock build demonstrate offline functionality
without disguising a live outage?
