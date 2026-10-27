# Monitoring photos (MEDIA-1)

This batch connects Monitoring Visit Reports and Material observations to the existing STORAGE-1 disk and STORAGE-2 lifecycle. It adds no schema, provider SDK, Composer dependency, public storage URL or alternate storage path.

## Routes and authorization

Both staff namespaces expose the following routes under
/{admin|field-officer}/monitoring/{monitoringRecord}/photos/{kind}/{parentId},
where kind is visits or materials and all IDs are UUIDs:

| Method | Suffix | Responsibility |
| --- | --- | --- |
| GET | / | Ready metadata, backend thumbnail/display URLs, reserved-slot count and pending recovery state |
| POST | / | One JPEG/PNG and a stable request_id UUID; at most 5 MiB original |
| GET | /{photoId}/{thumbnail|display} | Authorize and deliver exactly one private variant |
| DELETE | /{photoId} | Withdraw, then attempt fenced cleanup |
| POST | /{photoId}/reconcile | Check/recover an expired operation using the existing lifecycle |

Names are admin.monitoring.photos.* and field-officer.monitoring.photos.* (index, store, image, destroy, reconcile). Upload/reconciliation requests are limited to 30 per minute through Laravel's existing limiter. Web authentication, current role middleware and CSRF protection apply. Association-account and public routes do not exist.

MonitoringPhotoController handles HTTP concerns and safe responses. MonitoringPhotoService binds the parent to the exact Monitoring record and supplies the lifecycle's transactional authorization callback. Writes reuse MonitoringService::lockRecordForMutation: current role/profile/session boundary, operational association, current project ownership/status and Monitoring record, followed by the exact Visit/Material parent. STORAGE-2 then locks metadata and reserves no more than five live slots per parent. Each reserve/claim/metadata/finalization/reconciliation stage reauthorizes, including after expensive processing.

Reads use the same role/profile eligibility and session-cutoff check and lock association, project, record and parent in that order. Field Officers must still be assigned to the active association and an unarchived project. Administrators can read retained history, and assigned officers can read finalized-project history; mutations remain blocked. The current actor and ready state are checked again after a slow object read. Cross-parent photo IDs fail even if the caller can access both parents.

Photo requests never increment Monitoring section versions, rewrite ordinary form fields or alter calculations. Lifecycle audit events use the existing audit service inside metadata transactions. An observation with any photo history, including deleted tombstones, cannot be hard-deleted: the existing STORAGE-2 RESTRICT relationship is preserved and a useful validation message directs users to edit condition/remarks instead. Observation deletion without photo history is unchanged. Photo order uses STORAGE-2's stable display_order/id order; no reorder UI or endpoint is added in this batch.

## Delivery, privacy and resource use

Only normalized 320-pixel thumbnails appear in galleries. Opening a photo requests the normalized display variant (up to 1920 pixels); originals are not retained. Galleries load on disclosure, and unchanged thumbnail DOM nodes are reused on refresh. A collapsed gallery performs no media API/storage calls. Listing reads indexed metadata only, without HEAD/object-existence or bucket-list requests.

PhotoLifecycleService::open stages exactly one requested variant into a temporary file with 64 KiB chunks, bounded by the stored length and the processor's 5 MiB output limit. It checks length and SHA-256 before returning, closes provider resources, and reauthorizes before delivery. Provider reads hold no database locks. Staging avoids whole-file PHP buffering and prevents a timeout/truncated/corrupt response from being emitted after HTTP headers. It costs one temporary-file write/read and an additional short authorization transaction per image.

Successful image responses include Content-Type, verified Content-Length, a fixed inline filename, nosniff, no-referrer and private/no-store/max-age=0. Images and gallery JSON expose neither storage keys nor bucket URLs, fingerprints, leases, credentials or actor IDs. No base64/image bytes are embedded in page HTML or JSON. Local selection previews use revocable browser blob URLs.

Missing or withdrawn metadata returns 404. A missing storage object, timeout, corrupted object or uncertain provider result returns a safe 503; no extra HEAD is made to distinguish provider absence from failure. Unsupported variants (including originals) have no route. Internal failure logs contain only fixed lifecycle reason codes or exception class names, without SQL, request bodies, object keys, provider messages or stack traces. Already delivered browser pixels cannot be revoked; every subsequent HTTP read is authorized anew.

STORAGE-1's HTTPS/TLS enforcement, private bucket requirement, explicit credentials, bounded connection/request/read timeouts and disabled SDK retries remain in force. This batch does not change S3/R2 request signing, visibility or ACL suppression.

## Failure and recovery

The browser retains the selected file and its original request_id after an unconfirmed request. A retry with that same file/UUID cannot create another reservation. A request already processing/uncertain remains pending instead of blindly re-uploading. Retrying an explicitly removed request asks the user to choose the file again for a new logical upload; it never revives a tombstone. All reserved/processing/uploading/uncertain/ready slots count toward five, independently for each parent. Invalid images release their reservation; existing ready photos remain intact.

Delete first withdraws visibility transactionally. Fenced physical cleanup occurs outside transactions and waits for any active upload lease. Failed deletes remain hidden and retryable, with their row retained. Pending rows show recovery controls after the lease expires (normally 15 minutes); Refresh reloads current state. "Check saved state" uses STORAGE-2 reconciliation, which verifies both variants before finalization or retries only intentional exact-prefix deletion. Removal of a pending upload uses an explicit confirmation and the same withdrawal flow.

A database/audit failure or reassignment after writes leaves durable uploading/uncertain metadata. It never declares the writes absent or removes another photo. An eligible Administrator/currently assigned officer can reconcile after expiry while the project remains operational.

Finalized/archived/inactive parents remain read-only, including recovery mutations. Pending work on such parents is retained for operator triage; this batch does not introduce a privileged cleanup bypass or background scheduler. Before deployment, define who handles this case, recovery scheduling, retention/tombstone rechecks, and any required project-finalization preflight. Do not purge metadata or apply bucket lifecycle rules that can remove ready evidence without a reviewed policy.

## Verification and deployment

Normal tests use TestEnvironmentGuard, SQLite :memory:, migration 023 and Storage::fake, with synthetic authentication and blocked external HTTP. The Monitoring fixture installs the canonical migration wrapper, not a second schema. These are HTTP-kernel checks, not hosted Supabase Auth/provider acceptance.

Run from the repository root:

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/storage/verify.ps1 -Filter 'Monitoring|DailyHarvest|PhotoLifecycleTest|PhotoImageProcessorTest|PrivateMediaStorageTest|TestEnvironmentIsolationTest'
    powershell -NoProfile -ExecutionPolicy Bypass -File web/tests/Postgres/RunMediaLifecycleVerification.ps1
    node --test web/tests/Frontend/monitoring-*.test.mjs

The native runner starts and verifies a disposable task-owned loopback PostgreSQL identity, tests actual shared lifecycle parent-lock contention/capacity, constraints and RLS, then removes its cluster. It does not claim multi-session coverage of every Monitoring callback/revocation path; those paths also have focused request/in-flight synthetic tests.

For browser checks, create an empty temporary directory named assocmap-media1-ui-<32 lowercase hex characters>, set ASSOCMAP_MEDIA1_UI_DIRECTORY to it and run the MonitoringPhotoTest::test_galleries_render test through verify.ps1. The guarded test exports synthetic rendered pages and a generated PNG. Build frontend assets in web, then run:

    node web/tests/Frontend/monitoring-photos.browser.mjs <fixture-directory> <path-to-playwright-package>

Playwright is a temporary verification tool, not an application dependency. The runner uses installed Edge, blocks external requests and mocks gallery/image APIs; it tests actual Blade output and gallery JavaScript at desktop/mobile sizes. Backend authorization, provider failures and lifecycle transitions are separately tested in Laravel. Do not point this harness at a deployed application.

Before staging/provider acceptance:

- Ensure the approved STORAGE-2 migration 023 and existing version migrations are deployed through the established migration procedure. This batch does not apply them to shared Supabase.
- Configure the existing media_private disk and a private dedicated S3/R2 bucket, with no public/custom-domain access. Rebuild config cache after credential rotation.
- Enable PHP GD with JPEG/PNG and EXIF; align upload_max_filesize (at least 5M), post_max_size/proxy body limit (e.g. 8M), worker memory, execution time, writable temporary disk and free-space monitoring. Each request accepts one image.
- Set web/PHP/proxy budgets above processing plus two bounded storage writes. Confirm timeout behavior with the chosen provider and monitor only sanitized failures. Keep SDK blind retries disabled.
- Rehearse real staff sessions, reassignment/revocation, project finalization during upload, CSRF, private headers and responsive interactions against staging.
- Run opt-in S3/R2 upload/thumbnail/display/delete/timeout recovery checks only in a dedicated nonproduction test bucket with synthetic images and exact-prefix cleanup. Local SDK serialization and fakes are not a live R2 compatibility proof.
- Assign recovery/orphan/tombstone retention ownership, including pending work on finalized parents. No scheduled cleanup or retention purge is silently enabled.

## Executed local evidence

- Relevant Monitoring/security/storage regression run: 857 tests, 6,440 assertions passed.
- Final focused photo/lifecycle run after additional security and usability checks: 49 tests, 412 assertions passed.
- Existing native lifecycle PostgreSQL runner: 37 checks passed, including actual parent-lock contention; its task-owned cluster was removed.
- Monitoring frontend tests: 64 passed.
- Edge synthetic rendered-page checks: 46 passed (Visit at 1280 x 900 and Materials at 390 x 844); no script errors or horizontal overflow.
- Production frontend build, scoped Pint, PHP/JavaScript syntax and diff whitespace checks passed.

No shared migration, production storage request, commit, push, merge or deployment was performed.
