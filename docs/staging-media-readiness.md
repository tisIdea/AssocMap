# STAGING-FIX-1: GIS media correction and development-database migration review

Reviewed on 2026-10-28 in `C:\dev\AssocMap-STAGING`, branch `fix/gis-media-staging-readiness`, base `399652ee8676730ddd1b2beaa3396d34d29d9065`. The starting worktree was clean.

The user's correction supersedes the original deletion-guard implementation request. Archive and Delete Permanently remain implemented and unchanged. No deletion service, foreign key, route, UI, configuration flag, migration 023/024, or authentication/storage implementation is changed. The intended integration target is the **existing development Supabase project**, not a newly required hosted database. No connection to that project is authorized or performed by this batch.

**Decision: NO-GO for applying 018, 023, or 024 to development until target evidence and the operational gates below have been reviewed.** Local success is not evidence of the current hosted schema, migration history, privileges, data mappings, or restore capability.

## Narrow response-header correction

`GisRequestGuard` previously overwrote `PrivatePhotoResponse::HEADERS['Referrer-Policy']` after the controller returned. The regression reproduced `strict-origin-when-cross-origin` instead of `no-referrer`.

The middleware now supplies its existing default only when the response has no Referrer-Policy. Administrator, assigned Field Officer, and approved public thumbnail/display delivery retain `no-referrer`; gallery JSON retains the same endpoint-supplied policy. Ordinary public, Administrator, Field Officer, and Association Account GIS pages retain `strict-origin-when-cross-origin`. Existing cache/privacy headers remain in place.

This changes response handling only. Actor/session checks, parent scoping, approval, withdrawal, verified image staging, database transactions and lock ordering are unchanged. No new concurrency mechanism is needed for this correction.

## Known migration 023 / permanent-delete compatibility limitation

Migration 023 adds `public.media_photos.gis_location_id -> public.gis_locations.id ON DELETE RESTRICT`. `GisLocationService::assertDeletionSchema()` rejects every incoming foreign key before deletion. Therefore, once 023 is installed, the existing permanent-delete operation is blocked by schema review even for a location with no photo rows. Archive remains available under its existing rules. The Delete Permanently route and control remain present; this batch does not remove or hide them.

Any photo metadata, including deleted/withdrawn tombstones, also retains its parent relationship. This batch introduces no cascade, metadata purge, object deletion, FK exception, deletion race handling, or bypass. Record this limitation in the rollout decision. A later compatibility change requires separate authorization if permanent deletion must succeed with 023 installed. Do not work around it by dropping constraints or deleting history.

## Required geography compatibility

`web/config/associations.php` currently hardcodes `geography_schema_ready=true` and `direct_geography=true`. Existing older release documents describing false flags are historical, not the current source configuration. Flags were not changed in this batch.

Current readers join GIS geography through `associations.barangay_id`. Current association writers require a valid direct Barangay, permit a NULL Area, verify any selected Area belongs to that Barangay, and use the optimistic edit token. Thus the supported target is the final 018 schema.

| Target state | Required evidence | Compatibility with current true/true source |
| --- | --- | --- |
| Before 017 | No direct Barangay/address columns | Incompatible; readers/writers reference absent columns |
| 017 expanded, before 018 | Nullable direct Barangay; Area still NOT NULL; original Area FK | Incompatible as a rollout state: NULL direct geography can hide records and Area-less writes fail |
| Successfully completed 018 | Direct Barangay NOT NULL; Area nullable; validated direct/composite FKs, unique referenced Area pair, final indexes and valid mappings | Structurally compatible; real Auth, scoped workflows and provider acceptance remain separate gates |
| Partial, drifted or incompatible schema | Missing/unvalidated/wrong constraints, mismatches, orphan geography, conflicting ledger, wrong ownership or missing prerequisites | NO-GO; inspect and prepare an approved forward repair or recovery; do not replay 018 blindly |

The final fingerprint requires:

- `associations.barangay_id UUID NOT NULL`; `area_id UUID NULL`; `specific_address TEXT NULL` with the 017 address check.
- Validated `fk_associations_barangay`: direct Barangay FK with RESTRICT update/delete.
- `areas.address` remains NOT NULL; `uq_areas_id_barangay UNIQUE(id, barangay_id)` exists.
- Validated `fk_associations_area_barangay`: `(area_id,barangay_id) -> areas(id,barangay_id)`, MATCH SIMPLE, RESTRICT update/delete, not deferred. The old `associations_area_id_fkey` is removed.
- Valid/ready `idx_associations_area_barangay` and `idx_associations_barangay_id`; the old Area-only association index is removed.
- No missing direct Barangays or municipalities; no orphan or mismatched non-NULL Areas. A NULL Area is valid only in the final state.

Before 018, every Association (including archived/inactive rows) needs a valid Area-to-Barangay mapping and no conflicting populated direct Barangay. NULL direct Barangays are pending backfill at that stage. 018 fills only those NULLs, preserves identities/other attributes, never copies Area addresses, and can advance timestamps. Reload open forms after cutover. The canonical file is transaction guarded and rejects replay on an already-final schema.

## Exact read-only target checks, prepared but not run on Supabase

Use `supabase/tests/staging_media_preflight.sql`. It is a **psql** script: every SQL inspection runs in a repeatable-read, read-only transaction with bounded lock/statement timeouts and a final ROLLBACK. It contains no migrations, grants, fixtures or role changes. Output contains schema/role metadata and aggregate counts, not credentials, image keys or personal records. A successful script exit means the evidence was collected; it is not automatic migration approval.

Only after separate authorization for read-only access, configure an operator-owned libpq service and protected password mechanism for the confirmed development project. Do not put secrets in command arguments or logs. From the repository root, the review command is:

```powershell
# REVIEW ONLY: not executed against the hosted project in this batch.
$env:PGSERVICE = 'assocmap-development-review'
psql -X -v expected_database=postgres `
    -v backend_role=REPLACE_WITH_RUNTIME_ROLE `
    -v migration_role=REPLACE_WITH_EFFECTIVE_MIGRATION_ROLE `
    -f supabase/tests/staging_media_preflight.sql
```

Replace the database/role names with verified names. Confirm the project identity independently: database name `postgres` alone cannot distinguish Supabase projects. Use TLS and review the script's own-session SSL result. Keep the output operator-only. Do not point disposable verification harnesses at the hosted project.

Review these outputs before authorizing writes:

1. Project/database identity, inspecting/login role, PostgreSQL/PostGIS versions and extension schema, TLS, and read-only status.
2. Laravel ledger entries and batches, plus the Supabase CLI ledger if present. Manual 017/018 SQL may have no Laravel/CLI entry; require operator execution evidence and schema fingerprints. Never manufacture a ledger entry to conceal drift.
3. Table/column types, nullability, identity/defaults, complete constraints, index definitions/readiness and owners. Compare 023/024 against their canonical SQL, not merely table existence.
4. Geography counts across all statuses. Require sufficient unfiltered row visibility: zero counts under RLS are not proof that bad rows do not exist. The visibility result is a conservative sufficiency check; false requires owner review, not automatic disabling of RLS.
5. Existing media counts by state, catalog count, exact history-function definitions, owners/ACLs, and attached trigger definitions/enabled state. Preserve 014 safeguards and verify 024 only adds `site_type_id` to the GIS whitelist.
6. Effective role memberships, schema/database privileges, runtime grants and row-lock permissions, catalog column grants, client table/column privileges, policies and default ACLs. Recheck under the actual migration/runtime identities before execution.

Missing objects, missing roles, NULL privilege results, false required privileges, wrong ownership, unexpected policies or inconsistent history are blockers. The script does not automatically change roles or switch to the migration identity.

## Database privileges and RLS review

| Identity/operation | Requirement |
| --- | --- |
| 018 migration | Trusted ownership/ALTER authority for Associations and Areas, required locks/read access to geography, full visibility of all Association rows, and maintenance approval. Inspect named prerequisite constraints, not just equivalent-looking columns |
| 023 migration | CONNECT; public schema CREATE/USAGE; REFERENCES on the four parent tables and user_profiles; normal owner authority on the new table; prerequisite `anon`/`authenticated` roles; writable existing Laravel ledger |
| 024 migration | The effective `current_user` must exactly own `projects`, `gis_locations`, and `protect_finalized_project_history()`; schema CREATE for the catalog; both 014 helpers present; writable ledger. Inherited privileges alone do not satisfy its owner check |
| Media runtime | SELECT/INSERT/UPDATE on media_photos; current lifecycle uses tombstones and does not require SQL DELETE on metadata |
| Catalog runtime | SELECT plus UPDATE(id) for `FOR SHARE`; no catalog INSERT/DELETE or code/name UPDATE is needed |
| Existing media/GIS authorization | Preserve SELECT and row-lock permissions on roles, profiles, associations, projects, Monitoring records/parents; invitation/geography reads; audit INSERT; existing GIS mutation rights and applicable function EXECUTE |
| Framework runtime | Preserve session/cache/lock/queue privileges for the stores actually configured; these are not replaced by media grants |

`FOR SHARE`/`FOR UPDATE` needs UPDATE privilege on at least one column of each locked table. The native catalog test proves SELECT alone fails and SELECT + UPDATE(id) permits the lock. SQL grants alone do not bypass RLS: the approved trusted backend must have verified owner/bypass access or separately reviewed backend-only policies. The exercised native model is a non-owner NOSUPERUSER BYPASSRLS role with explicit grants, not a certified hosted role. Its broad base grants do not prove a whole-application least-privilege minimum.

Keep RLS enabled on media_photos and gis_site_types, with no direct PUBLIC/anon/authenticated grants or policies. Inspect inherited and column-level access too. Public GIS requests still access PostgreSQL through Laravel's trusted connection; they do not require anonymous direct-table access. A Supabase Auth API key is not a PostgreSQL login privilege.

## Reviewed migration procedure (future operator execution only)

No command below is approval to connect, apply migrations, or deploy. Do not run a global migration command or Composer setup against development.

1. Review the read-only evidence, accept/document the 023 deletion limitation, identify all applications/devices/workers writing to this shared development database, and obtain a verified backup/restore plan. Record schema/function/ACL/row snapshots without exposing private data.
2. Stop all incompatible writers, including other worktrees' servers, workers, scheduled jobs, mobile/API clients and ad hoc scripts. Use a maintenance window; this database is not disposable.
3. Classify geography first. If final 018 is already verified, skip 018. If only the expected 017 expansion is present, resolve all mappings and complete separately approved cutover prerequisites. If 017 is absent or metadata has drifted, stop and review that prerequisite separately. Do not recursively execute deferred SQL.
4. For an approved 018 execution only, use one owner session with `psql -X -v ON_ERROR_STOP=1 -v ON_ERROR_ROLLBACK=off`; set `statement_timeout='60s'` and `assocmap.geography_cutover_approved='batch2'` in that same session, then execute `supabase/deferred/018_cutover_association_geography.sql` once. Its own BEGIN/COMMIT and 10-second lock timeout remain intact. Do not use automatic savepoint recovery. The approval setting is an execution safeguard, not user authorization.

```powershell
# FUTURE REVIEWED EXECUTION ONLY, from the repository root.
# PGSERVICE/password mechanism must now select the verified effective owner identity.
psql -X -v ON_ERROR_STOP=1 -v ON_ERROR_ROLLBACK=off `
    -c "SET statement_timeout='60s'; SET assocmap.geography_cutover_approved='batch2';" `
    -f supabase/deferred/018_cutover_association_geography.sql
```

5. Verify the final 018 fingerprint, row/child/geometry preservation and geography reads/writes before reopening access. Current true/true source requires the final schema; do not turn flags off to conceal a failed cutover.
6. Reconcile the existing ledger with 023/024 objects. If already applied and matching, skip them. If absent and genuinely pending, package both `web/` and sibling `supabase/migrations/`, use the reviewed migration identity, and execute the two Laravel wrappers separately in this order, from `web/`:

```powershell
# FUTURE REVIEWED EXECUTION ONLY; never run the canonical SQL a second time.
php artisan migrate --path=database/migrations/2026_10_27_000000_add_media_photos.php --force --no-interaction
php artisan migrate --path=database/migrations/2026_10_28_000000_add_gis_site_types.php --force --no-interaction
```

7. Check each exit status before continuing. Verify constraints/indexes/RLS, exactly one ledger entry per wrapper, expected 023-before-024 ordering, and unchanged existing rows. 023/024 SQL is not independently replayable; Laravel's ledger supplies skip behavior. If schema exists without its wrapper entry, stop for reconciliation rather than rerunning or manually marking success.
8. Provision only reviewed explicit backend grants using a separately reviewed operator statement; migrations do not guess the runtime role. Recheck effective privileges and unchanged client denial. Rebuild configuration cache/restart relevant processes only during the later authorized deployment.
9. Use synthetic fixture IDs in the existing development database and a dedicated private nonproduction R2 bucket for separately authorized integration tests. Exercise actual Auth/CSRF/session scope, all four photo parents, approval withdrawal, replacement, deletion, uncertainty/recovery and anonymous bucket denial. Never run scratch-cluster fixture loaders against development.

018 is a coordinated geography prerequisite for this application's configuration, not a SQL dependency of 023 itself. 023 must precede 024 in the reviewed media deployment sequence. No taxonomy is seeded by 024; Unclassified remains supported pending business approval.

## Rollback and recovery

- A failed 018 transaction leaves the prior schema/data intact. Capture the failure, verify transaction rollback and investigate before retrying. A lost COMMIT response requires inspection before any replay.
- Once 018 commits, retain compatible direct-Barangay readers/writers. Restoring Area NOT NULL may be impossible after valid Area-less writes; flags or an old binary do not reverse the schema. Prefer an approved forward repair, or a coordinated restore accounting for subsequent writes.
- Both 023/024 wrappers explicitly refuse `down()`. Do not use rollback/reset/fresh or drop their objects as a recovery shortcut. Each PostgreSQL migration is transactional, but the pair is not one atomic deployment: 023 remains applied if 024 fails.
- Keep additive schema and the corrected history function for a compatible application rollback. Unexpected partial objects/ledger entries require investigation, not direct ledger editing.
- After uploads start, database restoration must account for corresponding R2 objects, audit/history and later writes. A DB restore and an object-store restore are not atomic. Retain exact-prefix recovery records and avoid broad deletion.
- Assign recovery ownership, scheduling/grace periods, tombstone retention and delayed-write rechecks. Normal recovery/mutations remain blocked on finalized/archived/inactive parents; no privileged bypass is added. Decide how operators handle that state before integration writes begin.

## Verification performed and remaining gates

- Red/green: before the middleware correction, the new photo-header assertion failed with the exact overwritten policy; ordinary page assertions passed.
- Isolated Laravel regression with EXIF enabled: **312 tests, 2,718 assertions passed**. Includes media/GIS classification, galleries, historical/finalized behavior, deletion trigger/audit regressions, Monitoring and Project photos. SQLite memory, synthetic Auth/storage, blocked Laravel HTTP, temporary caches/storage; no source flag changes.
- Canonical disposable PostgreSQL 17.11/PostGIS 3.6.2: **242 checks passed**, including Laravel migration ordering/idempotence, constraints, RLS/grants, finalized-history preservation, four photo parent workflows, classification and boundary presentation. The established harness explicitly exercises legacy geography mode, not the hosted target's current schema.
- Separate native shared-media runner: **37 checks passed**, including actual parent-lock contention, cover replacement, foreign-key/CHECK constraints and RLS. This does not cover the new GIS deletion behavior from the superseded request.
- New preflight SQL executed successfully in a read-only transaction on that synthetic schema before and after applying canonical 018 locally; final geography nullability and validated composite RESTRICT/MATCH SIMPLE FK were also checked. The temporary validation runner and logs are outside Git. This establishes SQL executability and local schema behavior, not hosted 018 readiness or full true/true application acceptance.
- GIS frontend: **25 passed**. Production Vite build passed. PHP syntax and `git diff --check` passed.
- Scoped Pint: middleware passes; both edited existing test files retain exactly the same pre-existing fixer categories as HEAD. No broad formatting cleanup was performed. Locked Composer installation retains existing PSR-4 fixture warnings; no lockfile/dependency version changes.
- The older `RunGisMediaVerification.ps1` **failed/incomplete**: its synthetic fixture lacks `gis_site_types`, and the merged GIS detail query fails with SQLSTATE 42P01 during the authorized-write-first concurrency case. The runner/service sources are unchanged; middleware is not executed by this service-level runner. This is a pre-existing fixture prerequisite gap, not evidence of a new header regression. Its remaining synchronized concurrency assertions are not claimed as passed. Repair/rerun that fixture in a separate focused verification batch.

All task-owned native clusters and generated database credentials were removed by their runners. No shared Supabase/Auth/R2 call, cloud resource change, production credential use, commit, push, merge or deployment was performed. New deletion behavior/concurrency tests from the superseded request were not implemented; deletion remains unchanged as corrected by the user.

**GO for review/commit of this scoped header correction and preflight documentation after acknowledging the existing harness gap; no commit was made. NO-GO for development database migration execution.** Outstanding gates are actual target schema/ledger/owners/RLS evidence, verified restore capability and writer maintenance, acceptance of the known deletion limitation, recovery/retention responsibility, target PHP/private-R2 readiness and real hosted Auth/provider acceptance. The full Laravel suite, deployed browser/device acceptance and hosted data/service behavior were not tested.
