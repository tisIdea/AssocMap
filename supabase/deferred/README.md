# Association geography cutover: deployment approval required

Only `migrations/017_expand_association_geography.sql` is an independently compatible expansion. It adds nullable Barangay/address columns, a direct Barangay foreign key, an address check and a Barangay index. It does not backfill, change Area nullability or activate the prepared application workflow.

`018_cutover_association_geography.sql` is deliberately outside the normal migration directory. Do not include it in a Batch 1 deployment or a recursive migration runner. Its number reserves the next step in this prepared sequence; recheck numbering against intervening migrations before an approved Batch 2 release.

The cutover is breaking for legacy writers. It requires explicit deployment authorization, verified compatibility across all dependent modules/clients, a verified expansion, a backup/restore plan and a maintenance window with legacy writers stopped. The session approval setting in the SQL is an execution safeguard, not deployment authorization. Both settings in `web/config/associations.php` remain false in source.

The script checks every Association, including inactive and archived records, rejects missing mappings and conflicting populated Barangays, and fills only NULL Barangays from the existing Area. It verifies row counts, identity and preserved attributes before enforcing the final foreign keys. It never copies Area addresses or changes children. The existing timestamp trigger may advance `updated_at` on backfilled rows; reload open forms after the eventual cutover.

The final `(area_id, barangay_id)` foreign key uses `MATCH SIMPLE` with `RESTRICT` update/delete actions. A NULL Area is accepted while the direct Barangay remains required. The unique Area pair is required as the referenced key and leaves the existing Area identity and address uniqueness intact. The composite Association index replaces the Area-only index, retaining its useful left prefix.

## Schema readiness and optional-Area activation

| State | Database | `geography_schema_ready` | `direct_geography` | Behavior |
| --- | --- | --- | --- | --- |
| 1 | Legacy schema | false | false | Existing Area-required reader/writer |
| 2 | Expansion 017 | false | false | Area remains required; new columns remain nullable |
| 3 | Final schema 018 | true | false | Direct Barangay readers; Area-required UI; writer derives required Barangay from the selected Area |
| 4 | Final schema 018 | true | true | Direct Barangay required; Area and independent address optional |

Schema readiness must switch with the cutover while writers are stopped. Optional-Area activation is a later decision. Setting only `direct_geography` true does not activate the feature. Leaving schema readiness false after 018 is unsafe for legacy writes and Area-less reads. Final-schema edits require the current `expected_updated_at` token even in State 3.

Prepared editor catalog queries have a 5,000-record limit per geographic catalog and fail with HTTP 503 on overflow; larger catalogs require a bounded search/paging workflow before activation. Real-session browser acceptance, shared Supabase/RLS verification and hosted performance remain release gates.

## Authorized deployment sequence

1. Review the actual shared migration history, catalog, constraints, indexes, RLS and client writers. Recheck 017/018 numbering against intervening migrations. Run `association_geography_preflight.sql` as a read-only review, using its post-017 section only after expansion. Resolve every discrepancy before continuing.
2. Obtain a verified backup and restore procedure, record row/child/geometry snapshots, and approve a maintenance window. Deploy the compatible application with both flags false; apply only 017 through the project's reviewed SQL process. Verify State 2 before cutover.
3. Stop web, queue, scheduled, mobile/API and other legacy writers. Recheck all active, inactive and archived mappings and populated Barangay conflicts. Reload open forms after cutover because backfill can advance timestamps.
4. In the explicitly approved session, run 018 once with `assocmap.geography_cutover_approved='batch2'`, stop-on-error enabled and automatic savepoint recovery disabled. Coordinate `geography_schema_ready=true`, `direct_geography=false`, approved configuration-cache refresh and worker restart before opening access. Never replay baseline migrations or run 018 recursively.
5. Verify validated direct/composite foreign keys, nullability, indexes, unchanged identities/children/geometries and zero invalid mappings. Smoke-test State 3 create/edit, scoped directories, GIS, dashboard, API and exports. Recheck authorization after assignment/account-link revocation.
6. Only after separate activation authorization and all acceptance gates pass, set `direct_geography=true`. Verify all four Area/address combinations, validation recovery, stale edits, archived/inactive protections and the role matrix.

## Failure and recovery

018 is transactional and fails on approval, schema, mapping, lock or constraint errors. A failed transaction retains the expanded schema; investigate before retrying. It intentionally rejects replay on the final schema.

After 018 commits, keep compatible direct-Barangay readers and writers. Turning optional-Area activation off returns to State 3; it does not restore the old schema. Do not run an old binary or set schema readiness false. Restoring Area NOT NULL after Area-less registrations requires approved data remediation or a coordinated backup restore that accounts for all subsequent writes. Never fabricate Areas or copy Area addresses to force rollback. Prefer a narrow forward repair with current data preserved.

See `../../docs/association-geography-batch-2-release.md` for local evidence, remaining release gates and the browser checklist. This runbook has not been executed against shared Supabase.

For local verification, use the fresh disposable target procedure in `../tests/local/README.md`: `association-geography.php` for focused constraints and a separate empty target for `geography-integration.php`. Do not use an application database or a shared Supabase endpoint.
