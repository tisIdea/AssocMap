# Private media storage (STORAGE-1)

`Storage::disk('media_private')` is the dedicated backend S3-compatible disk for future Monitoring Visit, Monitoring Material and GIS photos. Nothing uses it automatically. The global default, generic `s3` disk, `member_application_photos` local disk, Supabase transport, existing photo authorization, routes and schema are unchanged.

## Architecture and failure contract

`config/filesystems.php` contains scalar environment-backed settings compatible with Laravel configuration caching. `PrivateMediaServiceProvider` registers the narrowly scoped `media_private_s3` driver. On first resolution, it validates settings and constructs Laravel's standard S3 driver using the official `league/flysystem-aws-s3-v3` adapter. No Cloudflare SDK is used. Missing configuration does not prevent unrelated application workflows from booting, but using this disk fails closed.

`PrivateMediaAdapter` wraps the Flysystem adapter to enforce private writes, directory markers, copies and moves. Public visibility and arbitrary ACL/grant/multipart hooks are rejected before requests. The provider removes the SDK's generated `ACL=private` parameter before serialization for every command, including multipart initiation and copies: no `x-amz-acl` header is sent. This is required by R2 and also works with standard S3 private object defaults. Privacy depends on a private bucket/policy and restricted credentials, rather than an explicit object ACL. `getVisibility()` and `setVisibility(..., 'private')` explicitly raise `operation_unsupported` without making ACL requests; public visibility changes are rejected. Allowed write options are `visibility=private`, `directory_visibility=private`, `retain_visibility` (always forced false), `ContentType`, `CacheControl`, `ContentDisposition`, `ContentEncoding` and `Metadata`. Treat metadata as private application data; do not put credentials or personal information in object names or headers. A small `PrivateMediaFilesystem` subclass also sanitizes Flysystem's deferred listing errors, which otherwise include the requested prefix.

The returned Laravel filesystem supports ordinary Storage operations. It intentionally provides no public URL, temporary download URL or temporary upload URL. No disk link, upload endpoint, browser credential access, automatic fallback, bucket creation or database migration is introduced. Bucket privacy must also be enforced by the provider; application ACLs cannot override an unsafe public bucket policy or public domain.

Failures raise `PrivateMediaStorageException`, implementing Flysystem's exception interface and Laravel's `ShouldntReport`. Its fixed `reason` is safe for application metrics/logging:

| Reason | Meaning |
| --- | --- |
| `configuration_invalid` | Missing/invalid settings or S3 client construction failure |
| `options_rejected` | Caller requested unsafe/unsupported options |
| `operation_unsupported` | ACL inspection or mutation is intentionally unavailable |
| `exists_failed`, `read_failed`, `metadata_failed`, `list_failed` | Operation could not be completed |
| `write_unconfirmed`, `delete_unconfirmed`, `copy_unconfirmed`, `move_unconfirmed` | Mutation completion cannot be assumed |

The original exception is neither chained nor automatically logged: SDK errors can contain credentials, signed requests, keys and response bodies. Future callers should catch this exception, log only a fixed operation/reason and an application correlation ID, and return a generic unavailable response. Do not log configuration, raw exceptions, request headers, streams or object keys. Keep production `APP_DEBUG=false` and PHP `zend.exception_ignore_args=On`; debug traces and caller logs are outside this disk's control.

`exists()` returns false for missing objects; Laravel may issue both a file HEAD and a directory listing on a miss. A missing read raises `read_failed`; do not translate every read error to “not found.” S3 deletion is idempotent: a successful response does not prove an object previously existed. Invalid credentials, a nonexistent bucket, network errors and provider rejections fail safely at operation time. Configuration validation cannot prove credentials or provider access are valid without contacting the provider.

## Provider setup

Provisioning is an operator task, not performed by this batch:

1. Create separate private buckets for each environment. Block anonymous/public access and public ACLs where supported; disable website hosting, public development URLs and public custom domains. Do not enable public access to make an upload work.
2. Create bucket-scoped backend credentials granting only required object read/write/delete operations and listing as needed. Deny bucket management, policy changes and public grants. Apply provider-side encryption, access auditing and retention controls appropriate to the data.
3. Install locked dependencies with `composer install` during the normal reviewed deployment. The adapter brings the standard AWS PHP SDK as a transitive dependency. No existing locked package was upgraded. The added Symfony filesystem package remains on major 7 to preserve the application's declared PHP 8.2 compatibility; execution was verified on PHP 8.4.
4. Supply these secrets/settings through the backend environment or secret manager. Never use `VITE_`, frontend bundles, mobile configuration or Git for credentials.

```dotenv
FILESYSTEM_DISK=local
MEDIA_PRIVATE_ACCESS_KEY_ID=<bucket-scoped-access-key>
MEDIA_PRIVATE_SECRET_ACCESS_KEY=<bucket-scoped-secret>
MEDIA_PRIVATE_REGION=us-east-1
MEDIA_PRIVATE_BUCKET=<private-environment-bucket>
MEDIA_PRIVATE_ENDPOINT=https://<s3-compatible-api-host>
MEDIA_PRIVATE_USE_PATH_STYLE_ENDPOINT=false
MEDIA_PRIVATE_CONNECT_TIMEOUT=5
MEDIA_PRIVATE_TIMEOUT=30
```

For AWS S3, explicitly set the HTTPS regional S3 endpoint and matching region. Other providers may require path-style addressing (`true`) or an endpoint path prefix. Endpoints must be valid HTTPS URLs without embedded credentials, queries or fragments. Bucket names must be 3–63 characters using lowercase DNS-compatible characters; IP addresses and malformed separators are rejected. Region names accept letters, digits and hyphens. Boolean path-style settings must be `true` or `false`, not arbitrary strings.

For optional Cloudflare R2, use `https://<account-id>.r2.cloudflarestorage.com`, the private bucket name and `MEDIA_PRIVATE_REGION=auto`. Keep all public bucket access disabled. R2 does not support object ACL headers or standalone ACL operations; this disk omits those headers for every provider and disables ACL inspection/mutation. Copy/move do not read source ACLs. Standard S3 deployments must also use private bucket policies/defaults; policies requiring an explicit ACL header are incompatible with this disk. Provider-specific compatibility and anonymous-access denial require the smoke check below before enabling a feature.

The disk explicitly supplies its own credentials; it does not fall back to generic `AWS_*` variables, SDK profiles, instance metadata or local/public disks. Connect timeout must be 1–10 seconds; request/read timeout 1–120 seconds and at least the connect timeout. Defaults are 5/30 seconds, TLS verification is enabled, redirects are disabled and SDK retries are disabled. These are per-request/stream-read bounds, not an end-to-end deadline for multipart operations or an entire streaming response. Do not increase limits to mask provider errors.

Timeout enforcement also depends on Guzzle's selected transport. Its PHP streaming handler uses the general `timeout` and `read_timeout` values and does not implement a separate `connect_timeout`; do not promise a strict five-second streaming connection deadline from configuration alone. SDK checksum defaults remain enabled. Verify checksum handling, timeout behavior and partial-stream failures against the selected provider/runtime during the opt-in smoke check; synthetic serialization is not a transport test.

After changing credentials or settings, rebuild Laravel's configuration cache in the intended deployment environment (`php artisan config:cache`) and restart long-running workers/processes so cached disk instances are replaced. Protect cache files, backups and diagnostic output as secrets: Laravel's cache contains resolved credentials. Do not run `config:show filesystems` in shared logs. Rotate by installing/reloading new credentials, verifying access, then revoking old credentials; do not leave old worker processes using revoked keys.

## Streaming and future integration

Prefer `writeStream()` with a readable resource and `readStream()` with bounded downstream copying; callers own and must close streams in `finally` blocks. Avoid `file_get_contents()`, `get()`, whole-file base64 payloads or `Storage::download()` when its metadata/response behavior has not been reviewed for the new endpoint. A returned read stream can still fail during consumption: future HTTP streaming code must handle partial reads/timeouts without exposing raw transport warnings or sending a success response for incomplete data.

Laravel's normal `put()`/`putFile()` helpers are supported and stream uploaded files through the same guarded adapter. `exists()` can issue an avoidable second directory-listing request on a missing file; use `fileExists()` when explicitly checking an object, and avoid a preflight existence request before every read. The SDK's automatic multipart threshold is 16 MiB with default concurrency 3; unknown-length streams may buffer up to 5 MiB to choose an upload mode. Streaming avoids an application-level whole-file copy, but does not promise zero buffering or a single end-to-end timeout. No memory benchmark or real network latency measurement is claimed.

```php
$stream = fopen($validatedLocalPath, 'rb');
try {
    Storage::disk('media_private')->writeStream($serverGeneratedKey, $stream, [
        'ContentType' => $validatedMimeType,
    ]);
} finally {
    if (is_resource($stream)) {
        fclose($stream);
    }
}
```

This example assumes prior validation and authorization. Before any feature uploads/reads/deletes, implement server-side role/association/resource authorization, opaque server-generated keys, explicit per-feature byte/dimension/count limits, MIME/content validation and image normalization. Align PHP/web-server/mobile limits and time budgets. Reauthorize before mutations and reads; the disk itself has no user or record context. Never accept a bucket, endpoint, object key or arbitrary storage options directly from the frontend.

Object storage and database transactions are not atomic. Design metadata ownership, audit records, replacement/deletion behavior and reconciliation before integration. A timed-out mutation may have succeeded; do not blindly retry with a new key or delete an uncertain upload. Track intended keys and completion state, and reconcile using a controlled job with an age/grace period and authorization-aware ownership checks. Define retention/backup/versioning policies and expired multipart-upload cleanup. No orphan-deletion job, lifecycle policy, upload-size policy or media metadata schema is implemented here.

## Isolated verification and optional provider smoke check

`tests/Unit/PrivateMediaStorageTest.php` constructs the real Laravel/Flysystem/AWS stack using synthetic settings, then replaces the SDK handler before any operation. Requests never reach a provider. It inspects serialized, signed command/request parameters for PUT/GET/DELETE, directory markers, copy/move and an automatic multipart upload, including absence of ACL headers and `auto` signing for a synthetic R2 endpoint. It also covers configuration/cache snapshots, missing/invalid settings, TLS/timeouts, explicit credentials, private string/stream writes, `putFile()`, reads, deletion, missing objects, provider/network errors, listing sanitization and public-option/URL rejection. Run through the project's `TestEnvironmentGuard` with uncached configuration, SQLite `:memory:`, temporary storage/cache, synthetic environment values and blocked stray Laravel HTTP. Do not load the project's `.env`. Existing photo/evidence/authorization tests remain separate regression checks. `Http::fake()` alone does not intercept the AWS SDK transport; preserve the synthetic SDK handler in these tests.

`Storage::fake('media_private', ['visibility' => 'private', 'directory_visibility' => 'private'])` works for isolated consumer tests. Laravel replaces the entire disk with a local fake; that fake does not enforce the custom production guard or prove R2 compatibility. Use the synthetic SDK tests for privacy and request assertions. Normal `getDriver()` and `getAdapter()` access still passes through the production guards; the returned disk does not expose the native S3 client. As with any server-side code, deliberate reflection, a different disk or replacing the filesystem binding is outside this boundary.

Real-provider testing is **manual and explicitly opt-in**, excluded from PHPUnit and normal CI. Before doing it:

1. Obtain explicit approval for that provider check. Use a separate non-production runtime and a newly dedicated private bucket such as `assocmap-media-smoke`, with credentials restricted to that bucket. Do not copy production/staging environment files or reuse their bucket. Verify the exact endpoint, account and bucket independently before operations.
2. Load only synthetic data. Generate a fresh `storage-1-smoke/<random-uuid>.txt` key; record it locally for cleanup. Stream-write a tiny synthetic payload, read it back and compare exact bytes, check a fresh nonexistent key and confirm failures are safely reported. Never list/delete another prefix or run recursive cleanup.
3. Check anonymous HTTPS access for that exact synthetic object is denied; also inspect provider public domains/bucket policy. Upload success alone is not a privacy check. Test invalid credentials separately without logging raw SDK errors.
4. Delete only the exact generated key in a `finally` cleanup, verify it is absent, and confirm no test object remains. If completion is uncertain, reconcile that single key with the operator; do not widen cleanup. Record provider/runtime/version, sanitized outcomes and privacy evidence, without secrets or sensitive keys.

No real-provider smoke test was executed for STORAGE-1. Local synthetic success does not prove deployed bucket policy, credential permissions, DNS/TLS, provider compatibility, lifecycle rules or feature-level authorization.

References: [Laravel 12 filesystem](https://laravel.com/framework/docs/12.x/filesystem), [AWS PHP client options](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_configuration.html), [S3 private object defaults](https://docs.aws.amazon.com/AmazonS3/latest/API/API_PutObject.html), [Cloudflare R2 S3 compatibility](https://developers.cloudflare.com/r2/api/s3/api/).

## Final architecture review

Retain the four focused custom components. The provider adds fail-closed validation and ACL-free SDK serialization; the adapter blocks unsafe options and sanitizes all adapter failures; the filesystem subclass removes prefixes added by Flysystem's lazy listing wrapper; the exception supplies a stable, non-reporting application failure contract. Streaming, credential signing, multipart handling and timeout enforcement remain framework/SDK responsibilities. Combining these components would mix distinct boundaries without removing their required behavior.

The review reproduced the original `ACL=private` / `x-amz-acl: private` defect with a failing serialized-request test before correcting it. Local signed-request verification establishes ACL-free request construction, not live R2 acceptance, provider policies or anonymous-access denial. No real-provider access was performed. Deployment remains gated on the dedicated opt-in smoke procedure above, including the exact locked SDK/runtime and private bucket configuration.
