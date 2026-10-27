# GIS photo galleries — implementation and verification

Verified 2026-10-28 in `C:\dev\AssocMap-GISMEDIA`, branch `feat/gis-photo-galleries`, base `5d3f80fca04e1ed182ffec2c54b221e773e55d14` (merged MEDIA-2). The starting working tree was clean. No commit, push, merge, deployment, shared database change, or live provider mutation was performed.

## Exact changed files

Application:

- `web/app/Services/GIS/GisAccessService.php` — shared transactional GIS actor/parent/location locks.
- `web/app/Services/GIS/GisLocationService.php` — apply current actor eligibility and the common lock order to all existing GIS writes.
- `web/app/Services/GIS/GisPhotoService.php` — staff scope, public eligibility, and bounded gallery snapshots.
- `web/app/Http/Controllers/GisPhotoController.php` — gallery, upload, approval, ordering, removal, recovery, and verified delivery endpoints.
- `web/app/Http/Middleware/GisRequestGuard.php` — allow a 6 MiB multipart envelope only on the new upload routes; retain the 192 KiB geometry request limit.
- `web/routes/shared/gis.php` — Administrator and Field Officer photo routes.
- `web/routes/public/gis.php` — approved gallery and image routes.
- `web/resources/views/components/gis-photos.blade.php` — gallery, preview, viewer, confirmation, and recovery markup.
- `web/resources/views/partials/gis-workspace.blade.php` — include the gallery in location details.
- `web/resources/js/pages/gis-photos.js` — gallery behavior and selection/request isolation.
- `web/resources/js/pages/gis-workspace.js` — load/clear the gallery with location selection.
- `web/resources/css/pages/gis-workspace.css` — responsive gallery and image viewer styles.

Verification and documentation:

- `web/tests/Feature/GisPhotoTest.php` — 33 isolated endpoint, authorization, lifecycle, delivery, storage-failure, and CSRF cases.
- `web/tests/Feature/GisFinalizedProjectMaintenanceTest.php` — supply the new GIS access dependency; existing assertions retained.
- `web/tests/Frontend/gis-correction.test.mjs` — supply the gallery variable in the extracted-function fixture; existing assertions retained.
- `web/tests/Frontend/gis-photos.browser.mjs` — real Blade/JavaScript component browser verification with synthetic HTTP responses.
- `web/tests/Postgres/GisMediaVerification.php` — real-service concurrency and full public PostGIS policy verification.
- `web/tests/Postgres/RunGisMediaVerification.ps1` — guarded disposable local cluster runner and cleanup.
- `docs/gis-photo-galleries.md` — this handoff.

No migration, dependency manifest, lockfile, storage adapter, shared media lifecycle, or GIS serializer/query policy was changed.

## GIS authorization correction

Previously GIS writes acquired the location advisory lock, locked the profile for update, and then locked the role. They did not compare `auth_sessions_valid_after` with the authenticated request snapshot. This differed from the existing program mutation boundary.

GIS now calls `ProgramActorService::lock()` inside every mutation transaction. That reloads the current role and profile, verifies application eligibility, and rejects a changed role or session cutoff. Field Officer assignment is checked against the locked current association. Photo callbacks retain the original request actor snapshot throughout upload/read phases, so refreshing a profile cannot silently adopt a newly issued cutoff.

Lock order: **role → profile → existing per-location advisory fence → associations sorted by ID → projects sorted by ID → location → photo**. Ownership edits lock source and destination parents together in sorted order. Project ownership is checked again after locking, as is the location's parent identity. Storage reads/writes occur outside these transactions.

The same advisory key/hash and delete tombstone remain in use. Existing project finalization, GIS publication/archival, historical Administrator geometry correction, version-conflict, and schema-guarded deletion rules remain intact. No unrelated authorization service was modified.

Photo mutations require an operational association, a nonarchived location/project, and a nonfinalized project. Finalized project photos remain readable to authorized staff and retain the existing public GIS eligibility, while new photo mutations are rejected. Administrators can read eligible archived parent history; officers cannot read archived project/association history.

## Routes and contracts

`{staff}` is `admin` or `field-officer`. Every location/photo parameter is UUID-constrained. Staff routes retain web authentication, role middleware, GIS read throttling, and CSRF; mutations additionally use GIS write throttling. Association Accounts receive no private photo routes.

| Method | Path | Request / response |
| --- | --- | --- |
| GET | `/{staff}/gis/{location}/photos` | `photos`, `pending`, `used_slots`, `limit: 5`, `can_manage`; ready photos include approval state and delivery URLs. |
| POST | same | Multipart `photo`, UUID `request_id`; returns `{photo}`. The same request ID safely retries the same upload. |
| PATCH | same + `/order` | `ids`: the exact, distinct ready-photo UUID set; returns a saved-order message. |
| PATCH | same + `/{photo}/approval` | Boolean `approved`; returns `{approved}`. |
| DELETE | same + `/{photo}` | Immediately withdraws access, attempts cleanup, returns `{status}`. |
| POST | same + `/{photo}/reconcile` | Checks uncertain or retired state after its lease; returns `{status}`. |
| GET | same + `/{photo}/{variant}` | Verified image bytes; variant is `thumbnail` or `display`. |
| GET | `/map/{location}/photos` | Public-safe `{photos}` only. |
| GET | `/map/{location}/photos/{photo}/{variant}` | Verified bytes, subject to current public eligibility. |

Names: `{staff}.gis.photos.{index,store,order,approval,destroy,reconcile,image}` and `public.gis.photos.{index,image}`. The web component uses Laravel's `_method=PATCH` multipart override where needed.

Validation failures return 422; capacity, pending operations, and ordering conflicts return 409; unavailable images return 404; unconfirmed storage operations return 503. Unauthorized actors are rejected, and foreign ownership is concealed with 404. Responses do not serialize provider exceptions, object keys, credentials, or recovery tokens.

## Approval and delivery

The existing `PhotoLifecycleService` enforces five live/reserved slots per GIS location and validates/reencodes JPEG/PNG input of at most 5 MiB. Upload does not approve a photo. Approval/withdrawal uses its existing `approveGis()` and audit events. Removal clears approval before storage cleanup; uncertain operations retain durable recovery state.

Public delivery requires a ready photo with both approval fields set and a parent admitted by the unchanged `GisQueryService::scoped(null)` policy. That includes current publication metadata, nonblank public title, valid readable geometry, active/nonarchived association, and nonarchived project/location. Eligibility and approval are checked before and after private storage retrieval. A change committed during retrieval denies delivery before any image bytes or success headers are sent.

Both variants use the existing bounded, hash-verified stream staging and `PrivatePhotoResponse`. There are no public object-storage URLs. Public metadata omits uploader/approver identities, object references, bucket configuration, timestamps, pending states, and recovery data. Metadata and images use `private, no-store, max-age=0` and `nosniff`; conditional client requests do not bypass authorization. Previously delivered bytes cannot be recalled from a user's device.

## UI and performance

Selecting a location loads gallery metadata lazily. Initial map data, viewport limits, geometry editing, map selection, search, filters, and the existing public GeoJSON contract remain unchanged; no marker query or media join was added.

The component provides thumbnail previews, explicit display-image opening, five-slot counts, local upload previews, approval/withdrawal, confirmed removal, ordering, pending-upload explanations, recovery checks, and refresh controls. Controls follow server-reported manageability. Public and Association Account viewers use only the public approved gallery. Empty, read-only, validation, missing-image, and storage-error states are handled. Native dialogs support keyboard dismissal and focus restoration.

Repeated selection of the same location/version causes no gallery request. Metadata refresh reuses loaded thumbnail elements. Display images load only when opened. Changing selection aborts stale metadata requests, clears previews/dialogs, and prevents an old mutation response from updating the new location's gallery. Metadata queries perform no storage reads. Each image request intentionally verifies current access and one stored variant; no cross-request image cache was introduced.

## Executed verification

| Check | Result and boundary |
| --- | --- |
| Focused Laravel regression | **301 tests / 2,496 assertions passed**, including 33 new GIS photo cases, existing GIS geometry/lifecycle/UI cases, Monitoring photos, Project covers, shared photo lifecycle, image processing, and private storage tests. SQLite in memory, synthetic identities/storage, HTTP blocked by the existing isolation guard. |
| Native PostgreSQL/PostGIS | **99 checks passed** in a task-owned local cluster. Real GIS, revocation, reassignment, and media services; both write-first and access-change-first interleavings; stale role/cutoff; concurrent fifth/sixth reservations; concurrent publication/approval; changes committed during storage reads; full public geometry policy. Cluster and credentials removed by the runner. These synthetic tables do not establish deployed trigger/RLS/grant acceptance. |
| Frontend regression | **266 passed, 3 skipped, 0 failed**. Skips are existing Association native-form cases requiring their separate exported fixture. All 19 existing GIS frontend checks passed. |
| Browser component | **82 checks passed** in headless Edge at 1280 px and 390 px. Real rendered gallery Blade, real gallery JS/CSS, synthetic intercepted HTTP/storage. Approval, withdrawal, upload/retry UUID, capacity, recovery, removal/cancel, ordering, lazy images, thumbnail reuse, read-only/public controls, keyboard/focus, and no horizontal overflow. Screenshots visually inspected. This is not an authenticated deployed-app browser run. |
| Route compatibility | **29 existing GIS routes unchanged** against HEAD, comparing URI, methods, names, controller actions, middleware, and parameter constraints. New routes are additive. |
| Build/static checks | Vite production build, PHP syntax checks for affected PHP files, JS syntax checks, and `git diff --check` passed. |

The initial broad test-name filter also selected unrelated registration tests and encountered missing application-key errors. The final run selected explicit relevant files and supplied a synthetic process-only `APP_KEY`; no application `.env` was created or copied. Initial native/browser fixture failures were corrected and rerun without weakening assertions. PostgreSQL initialization required execution outside the Windows restricted-token sandbox. The full Laravel suite and hosted/provider/device acceptance were not run.

## Remaining staging requirements and commit decision

**GO for a focused commit of this batch.** No commit was created. This is not deployment approval or staging acceptance.

Before release, separately verify the already-required media schema (migration 023), deployed grants/RLS/triggers, private bucket settings, runtime storage configuration, GD/image support, and authenticated Administrator/assigned-officer sessions. Check that proxy/PHP limits admit a 5 MiB file inside the 6 MiB request envelope. Exercise real-provider uncertain upload/cleanup and real browser delivery after approval withdrawal, unpublication, archival, revocation, and reassignment. Confirm the public map and staff GIS details within the complete deployed layout.

Existing restriction retained: permanent GIS deletion still performs its conservative incoming-foreign-key/schema review. The existing media foreign key can therefore block location deletion; this batch does not relax that safeguard or delete dependent photo history.

No shared Supabase access or live provider mutation is needed to reproduce the local checks. Use `vendor/phpunit/phpunit/phpunit` with explicit relevant test files and the existing test guard, `node --test tests/Frontend/*.test.mjs`, and `tests/Postgres/RunGisMediaVerification.ps1`. The browser script accepts a guarded temporary fixture directory exported by `test_gallery_markup_and_optional_browser_fixture` through `ASSOCMAP_GISMEDIA_UI_DIRECTORY`, plus a local Playwright package path. New test utilities contain no provider credentials.
