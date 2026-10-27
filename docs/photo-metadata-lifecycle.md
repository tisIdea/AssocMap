# STORAGE-2: photo metadata and lifecycle

This is backend infrastructure on the merged STORAGE-1 baseline. No module upload/delivery endpoint, route, UI, Flutter feature, existing-member-photo migration, provider provisioning or shared database change is included.

## Schema and ownership

The canonical migration is supabase/migrations/023_add_media_photos.sql. The Laravel migration executes that file transactionally on PostgreSQL; isolated SQLite tests translate the same definition, without maintaining a second schema.

media_photos has a UUID identity and exactly one explicit foreign key to projects, gis_locations, monitoring_visit_reports or monitoring_materials. Uploader and GIS approver reference user_profiles. A nullable self-reference records which cover a replacement supersedes. RESTRICT foreign keys preserve ownership/history and recovery records. Existing parent deletes without photos are unchanged; future module integration must define an explicit history-retention policy before deleting a parent with photo rows. Do not cascade-delete metadata while objects might remain.

A unique request UUID identifies a logical upload and binds it to its uploader, parent, caption, input SHA-256 and optional previous cover. Reusing it with a different payload fails. Object prefixes are independent server UUIDs, unique per row, confined to media/<opaque-uuid>. The only objects are display.jpg/png and thumbnail.jpg/png. Originals are not retained. Neither prefix nor checksums, leases, request IDs or storage configuration appear in generic model/DTO serialization.

Parent/state/order indexes support galleries and eager loading. The four parent models expose ready-only photos() relations; use with('photos') for a parent collection to avoid N+1 queries. Partial unique indexes protect one ready Project cover and one pending Project operation. Other parent capacities are enforced by serialized parent-row locking in the service. Direct backend SQL must not bypass that service. RLS is enabled and PUBLIC/anon/authenticated receive no table privileges or policies. A deployment must explicitly review backend-role grants and RLS bypass/ownership; this batch does not guess a production role.

## Limits and processing

PhotoRules centralizes application limits: GIS, Visit Report and Material observation each allow five live reservations/photos per parent; Projects allow one cover. Reserved, processing, uploading and uncertain rows consume capacity. A replacement may coexist with the one existing ready Project cover, with one pending replacement at a time.

Input is at most 5,242,880 bytes (inclusive), JPEG or PNG with actual format/MIME and matching extension. GD decodes once after header validation. Maximum edge is 8192 and maximum input is 12 million pixels. A conservative 128 MiB processing allocation budget and available PHP memory are checked before decoding; a file within the nominal pixel ceiling may still exceed the memory budget. Keep PHP/GD/libjpeg/libpng patched and enforce worker/request execution limits; this is not an OS sandbox for native codecs.

The display fits a 1920-pixel longest edge; the thumbnail fits 320. Both preserve aspect ratio and avoid upscaling. JPEG quality is 85/80; PNG compression is 6 and transparency is preserved. Newly encoded pixels discard original EXIF/GPS/other metadata and trailing bytes. EXIF orientation is normalized when PHP EXIF is enabled; without it, metadata is still stripped but orientation cannot be interpreted. Enable EXIF on deployment for camera-photo orientation support.

Outputs are temporary file streams, bounded to 5 MiB each, with expected SHA-256/size recorded before writes. Streams close in finally blocks. GD still holds decoded pixels; streaming does not mean zero image memory. Temporary files can hold private normalized data, so use protected local temporary storage with sufficient space. Existing member applicant-photo code and its 2 MiB limit are untouched.

## Lifecycle

The owning module supplies an authorization closure to every parent-bound operation. That closure runs inside the short transaction and must:
1. Reload and lock the current identity, session freshness and ownership relationships in the module's established order.
2. Reload/lock the requested parent and enforce role, association, finalized/archived and action-specific rules.
3. Return the authorized parent model of the same exact type/ID. Update the request current_user attribute with the verified actor when recording an audit.

The shared service then locks that parent before counting or changing metadata. It intentionally does not invent a common role policy. A no-op authorization closure is not a valid integration. Reuse the owning module's current transactional actor/lifecycle checks, including ProgramActorService where applicable; do not replace them with UI visibility checks. All four modules remain integration work.

The service operations are:
- reserve(parent, file, requestUuid, request, authorize, replacesId?, caption?): short transaction, input fingerprint, idempotency check and capacity reservation.
- upload(photoId, file, request, authorize): claim a 15-minute fenced lease; normalize outside transactions; persist expected metadata; stream two private objects through Storage::disk('media_private'); reauthorize and finalize in a short transaction.
- listing(parent, authorize), reorder(parent, completeReadyIdList, request, authorize): bounded parent galleries; reorder rejects duplicates, missing IDs and other-parent IDs.
- withdraw(photoId, request, authorize): immediately removes it from ready listings/public approval and records deletion intent. It does not delete objects inline.
- approveGis(photoId, approved, request, authorize): explicit per-photo approval for ready GIS photos only.
- reconcile(photoId, request, authorize) and reconcileAbandoned(request, authorize, limit=25): bounded, authorized recovery without a queue/workflow framework.

reserve/upload/reconcile reject an enclosing database transaction so external storage cannot inherit caller-held row locks. The authorization closure must not perform external calls. Required reservation/finalization/approval/withdrawal/reorder events reuse AuditLogService inside the same transaction, using only the photo ID and fixed labels, without object keys or provider details.

The old Project cover remains ready until both replacement objects have been acknowledged and the metadata/audit transaction commits. That transaction retires the old row and activates the new row atomically. Cleanup of the old cover is deferred. A second simultaneous replacement is rejected while one is pending. Withdrawing an old cover concurrently is an explicit user action; a replacement cannot silently finalize against a changed predecessor.

## Recovery and cleanup

PostgreSQL and S3 are not atomic. Every object prefix is persisted before any write; no unrecorded random retry keys are generated after a timeout. Tokens prevent withdrawn/expired/stale workers from finalizing. A known ready retry returns existing metadata without uploading again; an in-progress/uncertain retry must use reconciliation rather than overwrite the objects.

Failure after writes (including database/audit or permission failure) leaves an uploading/uncertain row and preserves any previous ready cover. No blind cleanup occurs. Once the lease expires, reconciliation reads bounded streams and verifies BOTH expected hashes and sizes before reauthorizing finalization. Missing, partial, mismatched or unavailable objects remain uncertain with a fixed error code and a retry lease. This is deliberate: uncertain rows retain capacity until recovered or explicitly withdrawn. Do not automatically declare a provider timeout to mean absence.

Expired reservations/processing with no started writes become deletion-intended records. Explicitly withdrawn/replaced rows wait for an in-flight lease before cleanup. Cleanup uses idempotent deletes only for that row's exclusively owned, validated prefix, never a bucket listing, another prefix or a still-ready row. Failed/unknown deletes remain deletion-intended and retryable. Successful deletes retain tombstones and recovery references rather than removing the row. Extremely delayed provider writes may require rechecking that exact tombstone via reconcile(); no finite local timeout can prove a remote server will never commit later. Do not purge tombstones or recovery records without a reviewed retention/reconciliation policy.

Automatic abandoned reconciliation scans at most 100 eligible records per call (default 25). It excludes ready and deleted rows. Schedule it only after a reviewed module-authorized worker identity/dispatch integration; no scheduler or ambient privileged actor is installed in this batch. Incomplete rows retain their prefix even if the database is unavailable while recording a failure. Operational logs should use fixed exception reason/correlation IDs only.

## Privacy and publication

All objects stay on the existing private disk. STORAGE-1's ACL-free S3 serialization, unsafe-option rejection, TLS settings and sanitized errors are unchanged. MediaLifecycleException exposes only fixed codes and suppresses automatic raw reporting. Keep production APP_DEBUG=false and zend.exception_ignore_args=On; callers must not log raw files, database bindings, storage keys or provider exceptions.

GIS publication alone never approves a photo. publicGisMetadata() requires ready media plus its explicit approval AND reuses GisQueryService::scoped(null) for the current published/eligible parent policy. It returns safe metadata only, with no URL or bytes. A future delivery endpoint must recheck both policies at request time; metadata eligibility is not an enduring access grant. Monitoring photos and Project covers cannot receive GIS approval.

## Verification and deployment

Run isolated Laravel regressions from the repository root:

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/storage/verify.ps1

Run the image tests with EXIF enabled for the PHP test process:

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/storage/verify.ps1 -WithExif -Filter PhotoImageProcessorTest

Run native PostgreSQL concurrency/constraints in a newly initialized task-owned loopback cluster:

    powershell -NoProfile -ExecutionPolicy Bypass -File web/tests/Postgres/RunMediaLifecycleVerification.ps1

The native harness checks the cluster's actual data directory, user/address/port, uses random local credentials, observes real row-lock waits, applies the canonical migration to synthetic parent fixtures and removes the cluster/password. It does not load .env, use shared Supabase or contact object storage. It proves local PostgreSQL constraints/contention, not production migration or provider acceptance.

Before deployment, review/apply migration 023 once through the Laravel wrapper, with backup and the intended backend database-role permissions, and rehearse against the full deployment schema. No migration has been applied to a shared environment by this batch. Configure PHP GD/JPEG/PNG and EXIF, memory/time/upload/temp-disk bounds; align web server and future mobile limits. Retain STORAGE-1's private-bucket/provider smoke gate. Decide lifecycle scheduling, uncertain-operation triage, tombstone retention, parent-deletion behavior and per-module authorization integration before exposing uploads. No new Composer package is needed.

References: PHP's official [imagejpeg](https://www.php.net/manual/en/function.imagejpeg.php) and [exif_read_data](https://www.php.net/manual/en/function.exif-read-data.php) manuals describe GD encoding and EXIF parsing; runtime tests verify the concrete outputs here. Prior STORAGE-1 isolated/provider evidence remains separate from deployed R2 acceptance.

## Recorded local verification

- Relevant regression batch: 803 tests / 5,771 assertions, including STORAGE-1, member-photo, Project, Monitoring, GIS and audit tests.
- After the final validation/recovery correction: 34 directly affected tests / 154 assertions passed.
- Image tests with process-only EXIF enabled: 11 tests / 39 assertions passed.
- Native PostgreSQL 17.11: 37 checks passed with observed lock contention, concurrent cover replacements and idempotency, canonical CHECK/FK constraints and anonymous-access denial. The task-owned cluster/password were removed.
- Changed-file PHP syntax, Pint and whitespace checks passed. Composer requirements/lockfile, STORAGE-1 components and member-photo implementation are unchanged.
- These are synthetic/local checks. Shared/deployed migrations, live S3/R2 behavior, browser/device integration and module-level feature acceptance were not executed.
