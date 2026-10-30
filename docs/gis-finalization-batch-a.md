# GIS Finalization Batch A

Date: 2026-10-30. Checkout: `C:\dev\AssocMap-STAGING`, branch `feature/member-application-r2`, starting HEAD `439392bce7cbd190071879afe838994a8e80d7ae`.

Implemented backend geometry preservation, authorized audit visibility, and focused web-map improvements. Only Project Point / Project Boundary remain operational feature types. Every operational feature still requires an existing project; legacy association points are retained and excluded from operational reads/creation. Site classification remains optional and independent of geometry and fisheries category. Boundaries describe the actual project-site area, not administrative/association coverage.

## Root causes and before/after behavior

| Area | Before | After |
| --- | --- | --- |
| Classification maintenance | Editor always submitted geometry; every update reconstructed and assigned `geom` from GeoJSON. | Regular editor PATCH omits unchanged geometry. The service leaves `geom` out of SQL when omitted or equivalent to the locked record's GeoJSON projection, including older full-payload clients. Assigning, changing, or clearing classification preserves stored geometry. |
| Geometry validation | All updates required geometry. | Creation and explicit geometry edits retain structural validation; changed geometry retains PostGIS validation. Changing the geometry discriminator without replacement geometry is rejected inside the authorized transaction. Historical geometry correction remains geometry-only. |
| Audit visibility | Snapshots stored `site_type_id`, but the display allowlist hid it. | Administrator audit details expose the stored previous/new ID, including NULL when cleared. Existing snapshot data and role restrictions are unchanged. No historical label is inferred from today's catalog. |
| Geometry versus site classification | Mixed labels/legend entries made their different meanings less clear. | Explicit Geometry type / Site classification labels, separate legend group labels, actual project-site boundary guidance, and registered-geography explanations. |
| Selection and marker recognition | No persistent selected-feature emphasis; public representative markers resembled actual points. | Selected green outline, stronger selected boundary stroke/fill, `aria-pressed`, 44px marker targets, and a square badge identifying public boundary markers. A keyboard-accessible View selected details button focuses the selected heading. |
| Public entry links | Hero/preview map buttons were disabled and said integration was unavailable. | Both link to the existing public-map route. The adjacent hero sign-in link reuses the existing login route. The decorative preview remains explicitly illustrative. |

Actor/session freshness, association assignment and parent locks, identity fence, expected version, finalized ownership/deletion guards, publication rules, and audit rollback remain in the existing mutation transaction. Metadata-only requests still require the existing parent/project/name/type/version fields; this is not a new unrestricted partial-update API.

## Exact files in this batch

| Responsibility | Files |
| --- | --- |
| Request, mutation, audit display | `web/app/Http/Requests/SaveGisLocationRequest.php`; `web/app/Services/GIS/GisLocationService.php`; `web/app/Services/Audit/AuditLogService.php` |
| Map/editor behavior | `web/resources/js/pages/gis-editor.js`; `web/resources/js/pages/gis-map.js`; `web/resources/js/pages/gis-workspace.js` |
| Map/editor presentation | `web/resources/css/pages/gis-workspace.css`; `web/resources/views/gis-editor.blade.php`; `web/resources/views/partials/gis-workspace.blade.php` |
| Public entry points | `web/resources/views/public/sections/hero.blade.php`; `web/resources/views/public/sections/gis-preview.blade.php` |
| Laravel regression coverage | `web/tests/Feature/AuditLogAccessTest.php`; `web/tests/Feature/GisFinalizedProjectMaintenanceTest.php`; `web/tests/Feature/GisManagementUiTest.php` |
| Frontend regression coverage | `web/tests/Frontend/gis-correction.test.mjs`; `web/tests/Frontend/gis-feedback.test.mjs`; `web/tests/Frontend/gis-markers.test.mjs`; `web/tests/Frontend/gis-workspace.test.mjs` |
| Report | `docs/gis-finalization-batch-a.md` |

The four pre-existing untracked F08/catalog files remain outside this batch. Migration 024, DatabaseSeeder and the existing F08 audit were hash-checked unchanged. No controller, route, model, serializer, GIS read query, SQL migration, mobile source, environment, or media-storage implementation was changed.

The existing symbol mapper already supports the seven proposed catalog codes: cage, pen, seaweed, and post-harvest have dedicated artwork; culture pond, hatchery/nursery, and payao use the neutral fallback with their catalog names. Unknown/legacy codes remain safe. The fixed artwork cache is bounded to six symbols times two marker presentations. These are proposed AssocMap classifications pending BFAR Region VII validation, not an officially approved BFAR GIS taxonomy. No catalog was loaded by this batch.

All existing record controls were traced before deciding what to retain. Automatic search/filter changes, Apply filters, Reset, Refresh Records, parent-option search, pagination, preview/fit/clear geometry, and lifecycle controls have distinct working paths. They were retained. Visible help now states that Refresh Records reloads and fits matching locations; parent-option search remains separate from feature search. No new GIS data/lookup requests are introduced by selection or the details button. Existing viewport/payload/pagination limits and delayed reads remain intact.

## Verification

All Laravel runs used `TestEnvironmentGuard` / `IsolatedDatabaseManager`, uncached process-only synthetic configuration, SQLite `:memory:`, temporary storage/compiled views/cache, and blocked/faked Laravel HTTP. No application `.env`, shared database, provider Auth/storage operation, migration, or seeder was used. The service suite's former direct migration-024 invocation was replaced with equivalent synthetic catalog/FK fixture declarations; migration verification itself was not executed.

| Check | Result |
| --- | --- |
| Existing Laravel baseline before production changes | 109 tests / 1,078 assertions passed: geometry, serializer, scoped reads, management UI, photos, audit access |
| Existing frontend baseline | 25 tests passed across the four GIS `.test.mjs` files listed above |
| New defect reproduction before correction | Eight cases failed as expected: omitted geometry raised four errors; three old-client cases rewrote `geom`; audit details hid classification |
| Post-change Laravel regression | **181 tests / 2,135 assertions passed**: the baseline files plus finalized-project GIS maintenance |
| Post-change frontend regression | **29 tests passed**, including omission on classification change/clear, seven codes, representative markers, selection/clear emphasis, keyboard details, requests and correction contracts |
| Syntax | Six changed PHP files and three GIS JavaScript files passed |
| Static assets | `npm.cmd run build` passed; generated ignored assets/manifest remain local build output |
| Whitespace | `git diff --check` passed |
| Scoped Pint | Did not pass whole-file checks: SaveGisLocationRequest, GisLocationService, GisFinalizedProjectMaintenanceTest and GisManagementUiTest already have whole-file formatting findings in temporary HEAD copies. Broad formatting was not applied. AuditLogService and AuditLogAccessTest passed. |

The final Laravel run covers Point, Polygon with a hole, and MultiPolygon preservation with omitted/old-client geometry; classification assign/change/clear; unchanged publication and opaque fixture geometry; history/photo references; unauthorized actors, stale version, type changes, audit failure rollback, and hidden/unchanged legacy association points. Existing correction, finalization, CSRF/photo access, public projection and lifecycle tests also passed. The initial and final service tests use a SQLite SQL adapter and mocked spatial validation: they establish application decisions and statement/fixture preservation, not native EWKB, PostGIS validity, row-lock or concurrency acceptance.

Evidence: baseline JUnit at `%TEMP%\assocmap-gis-batch-a-bf0e0b8e437c41e888d7cf4def1619e0\baseline.xml`; post-change JUnit at `%TEMP%\assocmap-gis-batch-a-0e8d5dfcb45040fd895e66074f223ae0\after.xml`. Native PostgreSQL/PostGIS, real browser/layout, screen-reader, physical device, deployed/provider, production-volume latency, and the full application suite were not executed.

## Deployment compatibility and prerequisites

| Change group | Database/existing records/API | Deployment, security and rollback implications |
| --- | --- | --- |
| Optional unchanged geometry on PATCH | No schema/data migration or backfill. Existing geometry column is untouched for classification-only maintenance. Older full payloads remain accepted; route paths, creation contract and response fields remain unchanged. | Existing PostgreSQL/PostGIS functions, catalog schema and finalized-history safeguards are required. Actual target schema, update timestamps, trusted runtime grants/row-lock behavior and launch-environment TLS must be verified separately. Deploy the backend before allowing the new editor to submit geometry-free updates. |
| Audit display | Read-only allowlist addition; stored audits and catalog IDs are retained. | Existing administrator authorization remains required. No extra catalog query/grant or public field is introduced. Reverting this display change hides the field again but does not remove saved audit data. |
| Web map/editor/CSS | No GIS record, coordinate, association/project, publication, API projection or media mutation. Only existing feature types and classifications are rendered. | Deploy PHP/Blade and rebuilt JS/CSS/manifest as a coherent release. Old cached templates and new JS are not an acceptance configuration. Use the existing approved deployment/cache procedure; no deployment/cache-clearing command was executed here. Keep matching old assets available for cached pages and rollback. |
| Public landing and publishing explanations | Existing map/login routes are reused; the illustration contains no real coordinates. Public eligibility and the approved-photo policy are unchanged. | The publishing preview/editor now explicitly explain complete geometry disclosure. This explanation does not constitute stakeholder approval of that disclosure. |

No new environment variables, packages, Cloudflare settings, R2 buckets/objects/paths, storage permissions, or Supabase Auth changes are required. Photo controllers, private streaming and PhotoLifecycleService remain unchanged; the existing 33 GIS-photo tests passed. The existing catalog seeder remains an explicit future F08 action and was not invoked, including its dedicated seeder tests, during this batch.

Rollback is an application/asset rollback only: restore matching previous PHP, Blade, JS/CSS and manifest together. No database rollback or migration-024 reversal is needed. Do not roll back only the backend while clients still submit the new geometry-free PATCH body. Any legitimate classifications/geometry edits made after a future release and their audit history must be retained; this batch provides no destructive data undo.

## Public-data decision requiring stakeholder confirmation

**The current public API transmits the complete eligible published Polygon/MultiPolygon geometry even though the public web map displays one representative PointOnSurface marker.**

The unchanged [GIS query projection](../web/app/Services/GIS/GisQueryService.php#L286) serializes `g.geom`; the unchanged [serializer](../web/app/Services/GIS/GisFeatureSerializer.php#L55) returns it as Feature geometry. Public `display_point` is additive. [Web rendering](../web/resources/js/pages/gis-map.js) substitutes that point only for drawing. The public serializer and marker tests retain the original geometry and prove presentation does not replace it. Hiding the polygon visually does not hide its coordinates.

Stakeholders must confirm whether full project-site boundary coordinates are appropriate for anonymous publication. This batch preserves that contract. A future decision to withhold/generalize geometry requires a separately reviewed API/privacy and Flutter compatibility change. No publication status or coordinates were silently changed here. Existing historical sites still require business verification that saved boundaries actually describe project sites; this batch does not survey or reclassify records.

## Remaining Flutter and acceptance work

- Parse site-type ID/code/name and additive `display_point` while preserving original geometry separately. Live Flutter currently ignores these fields, renders public polygons, and uses the server's representative-point viewport contract; parity remains incomplete.
- Add complete server-backed site-type choices/filtering, shared classification meaning/symbols and legend, Project Point/Project Boundary labels, representative-marker disclosure and accessible selection/details. Do not infer a full catalog from the bounded map result.
- Confirm the boundary-disclosure decision before changing Flutter's geometry use. Reuse existing approved public gallery endpoints if photos are later integrated; no private storage URL exposure is authorized.
- Separately verify native PostGIS/EWKB preservation and concurrent authorization/version behavior, then actual desktop/narrow-screen keyboard/screen-reader and device workflows with appropriate provider/deployed evidence.
- Obtain final BFAR Region VII validation of the seven proposed project classifications before treating them as an approved reference catalog. No new taxonomy, administrative boundary type, classification inference or automatic assignment is introduced.

No migrations, seeders, shared/staging access, environment/cloud modifications, commit, push, merge, or deployment were performed. Batch A stops at implementation and isolated verification.
