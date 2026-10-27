# MEDIA-2: private Project cover photos

Baseline: development dc6919c (merged MEDIA-1). The implementation uses the existing media_photos table, Project relationship/rules, image processor, media_private disk and replacement lifecycle. It adds no migration, storage adapter, image processor, SDK, framework or application dependency. Ordinary Project create/update forms and service contracts are unchanged. Cover photos are optional and separate from GIS galleries. Association-account/public access is not introduced.

## Routes and authorization

The following named routes exist under both /admin/projects/{project}/cover and /field-officer/projects/{project}/cover:

| Method | Suffix | Route name suffix |
| --- | --- | --- |
| GET | / | projects.cover.index |
| POST | / | projects.cover.store |
| GET | /{photoId}/{thumbnail or display} | projects.cover.image |
| DELETE | /{photoId} | projects.cover.destroy |
| POST | /{photoId}/reconcile | projects.cover.reconcile |

Project/photo route IDs must be UUIDs. Route names start with admin or field-officer. Web authentication and the matching role middleware apply. Upload/reconciliation requests use the existing 30/minute limiter. POST accepts one photo, a stable request_id UUID and an optional replaces UUID identifying the current ready cover. No client object key, bucket URL, parent type or actor ID is accepted.

ProjectCoverService supplies PhotoLifecycleService's authorization callback. Every lifecycle transaction locks/revalidates role, current profile and session cutoff through ProgramActorService, then association and Project in the existing order. Writes use ProjectLifecycleService::lockNotFinalized, association operational checks, current assignment authorization and an explicit Project archive check. Authorization runs before expensive processing and again at reserve, claim, metadata write and finalization/recovery.

Read scope matches existing staff Project history: Administrators can read retained history; Field Officers must still be assigned and the association must not be archived. Archived/finalized Project evidence can be read within that scope, but no mutations or recovery bypasses are allowed. The shared private stream reader reauthorizes after storage I/O. Every photo ID is constrained to its Project before delivery, withdrawal or recovery; a GIS or another Project's photo ID cannot be used.

## Replacement and recovery

STORAGE-2 enforces one ready cover and at most one reserved/processing/uploading/uncertain replacement. A replacement must name the current ready cover belonging to this Project. The parent lock, live-slot checks and existing PostgreSQL partial unique indexes protect competing requests. A stale target returns 409; the user must refresh and deliberately choose the file again.

The original cover remains ready while both new normalized variants are processed/written. Only successful, reauthorized database/audit finalization atomically makes the new cover ready and retires the original. The controller then attempts exact-prefix cleanup outside transactions. Cleanup failure does not turn a confirmed replacement into a failed upload; the retired row remains tracked and hidden, and its recovery control is listed.

Storage timeout, lost response, partial/missing objects, assignment changes or database finalization failure preserve durable uncertain metadata and the original ready cover. A retry uses the same file, UUID and original replacement target, preventing a duplicate reservation or silent overwrite of a newer cover. Uncertain operations retain capacity. After the lease expires, reconciliation verifies both expected hashes/lengths before finalization; it never treats a timeout as proof of absence.

Withdrawal immediately hides the selected photo, retains its recovery row and respects any in-flight lease before physical deletion. Failed deletion stays retryable. Pending uploads can be explicitly cancelled. The UI disables removal of the current cover while a replacement is pending, directing users to cancel/check the pending operation first. No automatic tombstone purge or bucket-wide cleanup is introduced.

Finalized, archived or inactive parents remain read-only even for recovery. If they change while storage work is running, the operation remains recorded for operator triage; this batch adds no privileged bypass. Resolve the recovery/retention policy for these cases before deployment rather than reopening permanently finalized Projects or deleting metadata.

## UI and delivery

Project register rows show a lazy 80 x 60 thumbnail or "No cover photo". The ready cover ID is an indexed scalar subquery in the existing Project list query. No per-row metadata requests, eager-loaded full photo history or additional database round trip is required. Existing 8-query list budgets remain unchanged from 1 to 30 records. Only cover IDs are selected; no storage keys are included.

Project detail and edit pages include the same focused cover component, outside the ordinary Project form. It loads authorized metadata and the optimized display variant, shows a local blob preview, enforces the 5 MiB JPEG/PNG selection limit, displays busy/error/recovery states and confirms withdrawal. Replacement retains the current display image until the new cover is confirmed. Unchanged successful image URLs are reused on metadata refresh. Layout/navigation and ordinary form data remain intact.

The existing processor remains authoritative: actual content/MIME/extension checks, 5 MiB original/output bounds, edge/pixel/memory limits, orientation handling, metadata removal and fresh thumbnail/display encoding. Browser checks supplement server validation.

MEDIA-1's PhotoLifecycleService::open is reused unchanged. It stages one private variant into a temporary file with bounded 64 KiB chunks, verifies length/SHA-256 before sending headers, then reauthorizes. Storage requests hold no database locks. PrivatePhotoResponse shares only the small streaming/header implementation between modules and preserves Monitoring's fixed filename. Responses are private/no-store/max-age=0, nosniff and no-referrer with verified Content-Length and JPEG/PNG Content-Type. No originals, raw keys, private bucket URLs or base64 image data appear in HTML/JSON. Missing/withdrawn metadata is 404; missing/unavailable/corrupt provider bytes produce a safe 503.

Shared photoInputError contains only file-selection validation. Project behavior is not coupled to Monitoring controllers. Inspection found that staff layouts do not supply the CSRF meta tag assumed by the original Monitoring client. Both media components now provide their session CSRF token directly, and both clients send it in X-CSRF-TOKEN. Tests explicitly enable CSRF enforcement instead of relying on Laravel's normal testing bypass. Provider/SQL errors are not returned or logged verbatim; diagnostics contain fixed reason codes or exception class names only.

## Verification and evidence boundary

Executed locally:

- Relevant Laravel regression: 317 tests / 3,795 assertions passed (Project, Project UI, Monitoring photos, lifecycle, processing, private storage and isolation).
- Final affected Laravel run after CSRF/page-integration checks: 59 tests / 627 assertions passed.
- Native PostgreSQL: 37 checks passed, including actual competing cover-replacement row-lock contention, original-ready-cover retention, capacity, FK/CHECK constraints and RLS. The task-owned loopback cluster and password were removed.
- Frontend: 79 tests passed across Monitoring and relevant Program/report-link helpers.
- Project component browser checks: 100 passed at 1280 x 900 and 390 x 900.
- Monitoring gallery browser regressions: 52 passed at desktop/mobile sizes.
- Production frontend build, scoped Pint, PHP/JavaScript syntax and whitespace checks passed.

The native test uses synthetic authorization to isolate the existing lifecycle lock; it is not a claim that every Project authorization race was exercised in multiple database sessions. Project callback eligibility/reassignment/session-cutoff and in-flight failure behavior are separately tested through isolated Laravel requests. Browser tests execute actual Blade components/JavaScript but mock APIs/storage and block external requests. Existing Project page tests verify rendered integration and unchanged navigation/query budgets. Neither browser fixtures nor SDK request serialization prove live R2/provider or hosted Auth acceptance.

Reproduce from the repository root:

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/storage/verify.ps1 -Filter 'ProjectCoverTest|MonitoringPhotoTest|ProgramManagementUiTest|ProjectClosureTest|AssociationProjectsTest|PhotoLifecycleTest|PhotoImageProcessorTest|PrivateMediaStorageTest|TestEnvironmentIsolationTest'
    powershell -NoProfile -ExecutionPolicy Bypass -File web/tests/Postgres/RunMediaLifecycleVerification.ps1

In web, run npm run build and the relevant node --test tests/Frontend/*.test.mjs selection.

For Project browser fixtures, create an empty temporary directory named assocmap-projectmedia-ui-<32 lowercase hex characters>. Set ASSOCMAP_PROJECTMEDIA_UI_DIRECTORY to it and run ProjectCoverTest::test_component_and_synthetic_browser_fixture through verify.ps1. Then:

    node web/tests/Frontend/project-cover.browser.mjs <fixture-directory> <path-to-playwright-package>

MEDIA-1's existing analogous fixture exporter/runner remains available. Playwright is only a temporary verification tool; it is not added to application dependencies.

## Remaining deployment/provider gates

- Confirm the already approved STORAGE-2 migration 023, backend database permissions and existing Project lifecycle migrations in the deployment environment. This batch applies no shared migrations.
- Use the existing private media_private configuration and a dedicated nonproduction test bucket for opt-in provider checks. Do not enable public bucket/custom-domain access. SDK signing, R2 ACL suppression, strict credentials/TLS/timeouts and disabled blind retries are unchanged.
- Configure PHP GD/JPEG/PNG and EXIF, at least 5M upload_max_filesize, a larger post_max_size/proxy body limit (e.g. 8M), worker execution/memory budgets and writable monitored temporary disk. Config-cache rebuilds are required after credential rotation.
- Rehearse real staff sessions/CSRF, reassignment/revocation, stale tabs, Project finalization during upload, private image headers, missing objects and timeout recovery in staging. Verify upload/read/replacement/delete against the actual S3/R2 provider using synthetic images and exact owned prefixes only.
- Assign recovery scheduling, orphan/tombstone retention and operator responsibility for pending work on finalized/archived parents. No scheduled recovery, privileged cleanup bypass or retention purge is silently enabled.
- Web integration only; no Flutter cover-upload contract or public GIS publication is added.

GO for commit after local verification; staging/provider acceptance remains required before deployment. No commit, push, merge, deploy, cloud resource creation, live storage access or shared Supabase modification was performed.

## Exact changed files (25)

| Area | File |
| --- | --- |
| Project HTTP | web/app/Http/Controllers/ProjectCoverController.php |
| Shared HTTP | web/app/Http/PrivatePhotoResponse.php |
| Monitoring HTTP reuse | web/app/Http/Controllers/MonitoringPhotoController.php |
| Project policy | web/app/Services/Projects/ProjectCoverService.php |
| Project list query | web/app/Services/Programs/ProgramDirectoryQueryService.php |
| Routes | web/routes/shared/project-cover.php |
| Route registration | web/routes/web.php |
| Cover component | web/resources/views/projects/partials/cover.blade.php |
| List thumbnail | web/resources/views/projects/partials/cover-thumbnail.blade.php |
| Project register | web/resources/views/projects/register.blade.php |
| Project detail | web/resources/views/projects/show.blade.php |
| Project edit | web/resources/views/projects/edit.blade.php |
| Cover client | web/resources/js/pages/project-cover.js |
| Shared validation | web/resources/js/components/photo-input.js |
| Client registration | web/resources/js/app.js |
| Monitoring validation/CSRF | web/resources/js/pages/monitoring-photos.js |
| Monitoring CSRF | web/resources/views/monitoring/partials/photos.blade.php |
| Cover styles | web/resources/css/pages/project-cover.css |
| Style registration | web/resources/css/app.css |
| Project cover tests | web/tests/Feature/ProjectCoverTest.php |
| Existing Project UI tests | web/tests/Feature/ProgramManagementUiTest.php |
| Cover browser tests | web/tests/Frontend/project-cover.browser.mjs |
| Monitoring browser regression | web/tests/Frontend/monitoring-photos.browser.mjs |
| Guarded test selection | scripts/storage/verify.ps1 |
| Documentation | docs/project-cover-photos.md |
