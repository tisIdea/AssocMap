# Friday mobile prototype readiness

**Subsequent approved task:** the public GIS plan (Task A) below has now been
implemented in Flutter source. See [public GIS integration](public-gis-integration.md)
for exact files, actual response contract, independent mock/live configuration and
manual verification. All new checks are unexecuted; no backend/device integration is
verified. Authentication Task B remains unapproved/unimplemented.

The remainder is the original readiness-audit snapshot; statements about missing
public HTTP adapters and proposed Task A describe that earlier state. Use
[development status](development-status.md) and [integration checklist](integration-checklist.md)
for the current source inventory.

Audit: 2026-10-14, Asia/Manila. Deadline: Friday, 2026-10-16, inferred from the current
date and supplied request. Workspace: `C:\dev\AssocMap\mobile`.

This is a source audit with small Flutter-only preparation changes. No Flutter, Dart,
PHP, Artisan, Composer, Git, test, build, browser, or device command was executed.
Read-only file searches/reads were used. No .env/credentials, live service, invitation,
email, database record, migration, Cloudflare setting, or photo-storage work was touched.
The available `web/` copy is a reference, not proof it matches Desktop 1 or deployment.

## Readiness decision

The existing demonstration is a functional **local synthetic prototype**. Its core
screens and interactions exist, and the prior Phase 3 run passed 63 local tests.
That is historical evidence, not a fresh pass for the current source. Actual device
readiness and every live backend integration remain unverified.

The principal integration gap is now Flutter's missing HTTP/provider/session adapters.
Laravel source now provides association eligibility in `/api/v1/me`; the previous
Phase 3 claim that it omitted eligibility is obsolete. Deployment, HTTPS access,
approved synthetic test accounts, token lifecycle, and operational backend availability
still need confirmation. Do not describe this source preparation as live authentication.

## Implementation inventory

| Capability | Source implemented | Synthetic/mock status | Test evidence | Backend integrated | Device verified |
| --- | --- | --- | --- | --- | --- |
| Welcome, named navigation, public entry | Yes | No data operations | Prior widget run passed; not rerun | No API required | No |
| Public GIS pan/zoom/fit, markers, summary sheet | Yes | Local JSON only; no street basemap | Prior widget run passed; not rerun | No | No |
| Polygon/MultiPolygon/hole parsing and display | Yes | Synthetic WGS84 geometries | Prior unit/widget run passed; not rerun | No | No |
| Map search/category filters and boundary toggle | Yes | Filters local fixtures | Prior widget run passed; not rerun | No | No |
| Draft/archive exclusion | Yes in mock loader | Mock scenarios; not server verification | Prior fixture tests passed; not rerun | No | No |
| Login form, password toggle, safe errors/loading | Yes | Demo repository only | Prior unit/widget run passed; not rerun | No | No |
| Role/link denial, protected Workspace/Account shell | Yes | Synthetic eligibility | Prior guard tests passed; not rerun | No | No |
| Logout, expiry timer/resume, late-result protection | Yes | In-memory 15-minute demo sessions | Prior tests passed; not rerun | No provider lifecycle | No |
| Central mock/API selection and no fallback | Yes | Explicit mock mode | Prior isolation tests passed; not rerun | API implementations remain blockers | No |
| Current `/me` mobile context parsing | Prepared in this task | Synthetic response fixtures | New/changed tests unexecuted | No HTTP call | No |
| Non-secret HTTPS backend URL configuration | Prepared in this task | Unset default; unused by current blockers | New tests unexecuted | No requests | No |
| Supabase mobile login, token storage/refresh | No | No real provider/client integration | Not verified | No | No |
| Association dashboard statistics/roster/applications | No | Pending-module shell only | Not implemented | No verified business APIs | No |
| Photo uploads and endorsement workflows | No | Outside this audit | Not implemented | Not inspected/integrated | No |

Evidence: `lib/app/app.dart`, `app_routes.dart`, `repository_factory.dart`,
`public_map_screen.dart`, `public_map_view.dart`, `mock_public_projects_repository.dart`,
`association_access_screen.dart`, `association_home_screen.dart`, `auth_controller.dart`,
`association_guard.dart`, `demo_auth_repository.dart`, remote repositories, `pubspec.yaml`,
all seven current Dart test files, and mobile reference documents. Source declarations
are not runtime results. The new configuration is not a connection switch by itself.

## Current identity API contract — source verified

`GET /api/v1/me`: no request body. Send `Authorization: Bearer <Supabase user access JWT>`
and `Accept: application/json`. Do not use publishable keys, service-role keys, refresh
tokens, browser session cookies, query tokens, or user-editable metadata as this bearer.

Sources:

- [API route](../../web/routes/api.php): `ApiRequestGuard`, `throttle:api-auth`, Supabase bearer middleware.
- [Identity middleware](../../web/app/Http/Middleware/EnsureSupabaseApiAuthenticated.php): clears request context, verifies identity, resolves mobile eligibility.
- [Provider verifier](../../web/app/Services/SupabaseAuthService.php): `verifyApiIdentity` requires a bounded JWT-shaped token, validates through Supabase `/auth/v1/user`, uses TLS, disables redirects/cookies, and bounds timeouts/response format/size.
- [Eligibility resolver](../../web/app/Services/MobileAssociationAccessService.php): derives profile/role and association from the verified actor, using current write-connection reads.
- [Response controller](../../web/app/Http/Controllers/Api/V1/MeController.php): emits profile fields and `data.mobile`.
- [Error guard](../../web/app/Http/Middleware/ApiRequestGuard.php): safe JSON errors, no-store headers, numeric Retry-After for throttling.

Successful eligible response, with placeholders rather than real records:

```json
{
  "data": {
    "id": "<user-profile UUID>",
    "display_name": "<authorized display name>",
    "role": {"code": "association_account", "name": "Association Account"},
    "is_active": true,
    "mobile": {
      "eligible": true,
      "reason": null,
      "association": {"id": "<association UUID>", "name": "<association name>"}
    }
  }
}
```

HTTP 200 can instead contain `mobile.eligible: false`, `association: null`, and:

- `role_not_supported`: an otherwise authorized Administrator/Field Officer/other role.
- `association_link_unavailable`: missing, inactive, invalid, duplicate, or inconsistent linkage.
- `association_unavailable`: unavailable/invalid association, inactive status, archived association,
  missing name, or unresolved link target.

The resolver requires active profile/role and existing invitation eligibility before
these contexts are returned. Disabled profile/role, missing profile, banned provider
identity, or blocked invitation/account access receives 403 instead of an eligible context.
Do not impose a blanket `activated_at` requirement: the source preserves eligible legacy
accounts through the existing invitation rules.

| HTTP status | `error.code` in inspected source | Future client behavior |
| --- | --- | --- |
| 401 | `unauthenticated` | Missing/invalid/expired identity; use approved refresh/reauth policy, clear rejected access |
| 403 | `account_unavailable` | Deny application access; no synthetic fallback |
| 403 | `mobile_access_denied` | Protected mobile middleware denial; not a `/me` success response |
| 429 | `rate_limited` | Respect numeric `Retry-After`; avoid rapid/automatic credential retries |
| 503 | `authentication_unavailable` | Provider/configuration/transport verification unavailable; offer bounded retry |
| 503 | `service_unavailable` | Backend/database failure; show unavailable state and deny protected access |

Errors have `{ "message": "<safe message>", "error": { "code": "<code>" } }`.
Other guard statuses include 404 `not_found`, 405 `method_not_allowed`, and 422
`validation_failed`. No raw exception details belong in the mobile UI.

`mobile.association` middleware and the resolver's `scope()` helper revalidate eligibility
and derive association scope server-side. No production association business API route
using this middleware was found in `routes/api.php`. `/api/v1/_mobile-test/...` routes
exist only in the isolated PHP test setup and must never be coded as real endpoints.
Backend tests were read for intended cases; no PHP tests or databases were executed.

An expired token is classified as 401 when provider verification identifies invalid
identity. Outages are classified separately as 503. `/me` does not return refresh
credentials or an expiry timestamp and is not a login/refresh/logout endpoint. Web
logout still establishes only browser-session invalidation in the inspected controller.

## Public GIS contract — independent candidate for first live integration

Declared public routes use GET and require no bearer authentication:

- `/map/data`: `PublicGisController::data` calls `GisQueryService::features(null, ...)`.
- `/map/associations`: requires `kind=association`; supports `q` and `selected` and returns
  `{options: [{value, label}], truncated}`. The value is a public GIS location ID (`g.id`)
  and the label is its public title; it is not an internal association ID/name response.
  Options come from the same public-scoped query and normally have a 50-option limit.

`/map/data` returns GeoJSON `FeatureCollection` with `features` and `meta`. Each feature
has `id`, `geometry`, and public properties `location_type`, `title`, `address`,
`description`. It does **not** provide the mock model's required association display name,
category, or area label. Do not populate these using synthetic defaults or private queries.

Map mode requires `mode=map` and `bbox=[west,south,east,north]` without dateline wrapping;
search `q` is at most 100 characters; `location_type` is supported. Map results have a
100-feature limit, truncation flag, and a 2 MiB feature-payload budget in source. List
mode is paginated at 20 records and returns page/last_page/total metadata. Public callers
cannot use internal association/project/locality IDs or publication/lifecycle filters.

Public serialization omits association/project actor metadata. Query rules require
published, unarchived GIS records with approved public titles and publication attribution,
active/unarchived associations, and unarchived projects. A live adapter should render
the provided point or polygon geometry faithfully; it cannot assume every boundary
has a separately returned marker. These are source rules, not verified publication results.

## Shortest Friday demonstration paths

| Priority / workflow | Frontend | Backend/API and database dependencies | Current status / missing work | Security / verification |
| --- | --- | --- | --- | --- |
| First real integration: public GIS exploration | Existing map/details; adapt to true public feature fields and location-type filters | GET `/map/data`; published GIS/project/association/locality data and PostGIS | No live adapter; needs HTTP transport, public DTO/geometry mapping, viewport limits and empty/error handling | No token or private projection; isolated published synthetic test records; verify offline/error/truncation/no fallback |
| Next real integration: Association Account sign-in and identity/link summary | Existing login/denial/shell; show server-authorized identity and association only | Approved Supabase user login plus GET `/api/v1/me`; auth.users, profiles, roles, invitation state, current account link and association | Eligibility source exists; missing provider client, HTTPS identity client, secure session handling, approved configuration/policy | Check HTTP status AND eligible true/role/profile/context; test both staff roles, disabled and unlinked accounts; verify no UI access on denial |
| Complete the identity workflow: logout and expiry | Existing guard/expiry/logout UI | Supabase session lifecycle; `/me` revalidation, provider logout/refresh policy | Demo only; no real lifecycle adapter | Clear local private state promptly, choose logout scope explicitly, revalidate on restore/refresh; no claim web logout revokes bearer |
| Rehearsed fallback: existing local demo | Welcome → map → search/selection/boundaries → demo login → account shell → expiry/logout | None; bundled synthetic fixtures | Implemented, prior tests passed; needs manual current checks and actual-device rehearsal | Visible DEMO labels, use debug build; present as local simulation, not live database/authentication |

Prioritize public read-only integration because it avoids unfinished invitations/activation
and private authentication, then add the bounded login→`/me`→association-summary flow.
If the required approvals/environment are unavailable, rehearse the existing demo and
present its integration limitations explicitly. Do not add a new mock business module
or claim the shell performs organizational monitoring, applications, or database writes.

## Small changes made in this audit

Modified:

- `lib/data/models/laravel_me_profile.dart`: strict `mobile`/association DTOs and
  `canAccessMobile`; missing/legacy/null context denies access, unknown denial reasons
  stay denied, contradictory or malformed context is rejected. This is response parsing,
  not authentication or a live repository adapter.
- `lib/app/configuration/app_config.dart`: optional non-secret compile-time
  `ASSOCMAP_BACKEND_BASE_URL`, HTTPS-only URI validation, and prefix-preserving `/api/v1/me`
  URI construction. Blank remains unconfigured; user-info, query, and fragment are rejected.
  Use the Laravel application base URL, not a URL already ending in `/api/v1`. This is
  not consumed by the blocked API repositories and cannot initiate requests.
- `lib/core/authentication/auth_failure.dart`: removed the obsolete claim that the
  backend profile API lacks association context; real sign-in stays disabled.
- `test/laravel_me_profile_test.dart`: added eligible/denied/legacy/role/invalid-payload cases.
- `README.md`, existing mobile integration/status documents: corrected current versus
  historical findings and verification labels.

Added `test/app_config_test.dart` for unset/HTTPS/prefix/credential-bearing URL cases,
and this report. Eight focused new tests are authored but **not executed**. These use
synthetic decoded JSON/configuration fixtures, not HTTP calls or organizational records.
No new dependencies, login enablement, route/session changes, platform changes, or backend
modifications. Side effect: malformed future API context now raises `FormatException`;
the future HTTP adapter must catch it and show a safe unavailable/error state.

## Proposed file-level next task — approval required

Task A: connect the public GIS repository against an approved isolated test backend.

1. `pubspec.yaml`: declare `http` 1.6.0 directly, subject to approval. It is already
   resolved as a transitive dependency in the inspected lockfile; declaring it directly
   supports legitimate client imports without adding a second HTTP stack. No dependency
   changes or resolution command were performed in this audit.
2. Add `lib/data/models/public_gis_feature.dart` and tests using actual public response
   fixtures; allow only the server's public fields and Point/Polygon/MultiPolygon geometry.
3. Replace `lib/data/remote/api_public_projects_repository.dart` blocker with a typed
   bounded client; adapt its interface where necessary for viewport/truncation metadata.
4. Update `public_map_screen.dart`, `public_map_view.dart`, and `public_project_details.dart`
   only as needed to render real fields/geometries and honest empty/truncated states.
5. Keep factory selection explicit, preserve mock mode, and add fake-transport tests.
6. Separately approve runtime commands/device launch and test-service access. No production calls.

Task B: real Supabase login and Laravel eligibility, after Task A or as a separately
approved priority. Proposed edits/additions: `pubspec.yaml` (provider/session storage
packages after compatibility approval), `app_config.dart` (public provider configuration),
`lib/core/authentication/secure_session_store.dart`,
`lib/core/networking/laravel_identity_client.dart`,
`lib/data/remote/supabase_auth_repository.dart`, `repository_factory.dart`,
`auth_controller.dart` lifecycle mapping, necessary normalized-session/login updates,
and focused adapter/guard tests. No Laravel edits or invented endpoints. Agree how storage,
restore, refresh, denial cleanup, cancellation, and logout scope work before enablement.
Do not use Supabase's client-side database role metadata as association authorization.

These plans do not authorize dependencies, real authentication, or live-service access.
Desktop 1 must confirm the reference contract/deployment and approved fixtures; Desktop 2
must implement the clients. Adding further eligibility fields/routes is no longer the
first backend request. Invitations/activation/HTTPS tests remain Desktop 1's work.

## Manual verification commands — provided, not executed

Run from `C:\dev\AssocMap\mobile` when you choose to verify locally:

```powershell
dart format lib test
flutter analyze
flutter test
flutter devices
flutter run --dart-define=ASSOCMAP_DATA_MODE=mock
```

For an Android debug APK demonstration, if you choose to build it:

```powershell
flutter build apk --debug --dart-define=ASSOCMAP_DATA_MODE=mock
```

Manual expected checks: synthetic markers/boundaries work without login; search/selection
and navigation work; demo allowed/denied roles behave correctly; expiry/logout remove
the protected shell; API mode remains honestly blocked. Rehearse on the actual presentation
device with connectivity disabled. Record actual results rather than marking these passed
from the earlier test run. Do not put genuine credentials or tokens in command arguments.

## Concrete remaining risks

- Demo auth deliberately does not work in release mode. Debug/profile builds permit
  the demo; an actual build/device has not been verified here. Do not remove this gate.
- Android main manifest lacks INTERNET permission; debug/profile manifests have it.
  Future release networking needs an explicit mobile manifest change and device verification.
- Android application ID/name and debug signing are scaffold values; production packaging
  remains separate. Avoid last-minute platform changes to the working demo without verification.
- A device's localhost refers to that device. Use an approved reachable HTTPS backend URL
  for later integration; never disable certificate validation to accommodate proxy failures.
- No street basemap/provider/cache/attribution deployment has been selected. The current
  coordinate-grid demonstration should be described accurately.
- The existing fixture-driven public model cannot directly consume `/map/data`; privacy
  fields, geometry selection, and truncation handling need deliberate adaptation.
- Latest parsing/configuration edits and all fresh tests are unverified. The historical
  63-test pass does not certify them. Actual API availability, CORS for browser previews,
  provider session policies, live database integrity, and UAT are unverified.

## Capstone defense notes

Authentication establishes identity; Laravel establishes application/mobile eligibility
and association scope. An HTTP 200 is transport success and can still carry a denial.
The server's `mobile.eligible` and authorized association projection must guide the UI.
Flutter guards prevent accidental navigation; backend middleware/query scoping must
protect the data. Repository interfaces allow local demonstrations without inventing
working network integration. GeoJSON uses longitude/latitude order; each polygon part
and hole is preserved. Published public fields differ from internal management records.
Local fake-data tests prove client behavior only; they do not prove provider, deployed
database, device, or organizational acceptance. Show that distinction in the presentation.
