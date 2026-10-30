# Proposed GIS site-type reference catalog

These are proposed AssocMap project classifications pending final BFAR Region VII validation. They are not an officially approved BFAR GIS taxonomy. Preparing this seeder does not approve loading the catalog into staging, a shared database, or production.

## Schema and presentation compatibility

`Database\Seeders\GisSiteTypeSeeder` uses the existing migration 024 structure: generated BIGINT identity `id`, unique lowercase `code` matching `^[a-z][a-z0-9_]{0,49}$`, and a nonblank `name` of at most 100 characters. All seven codes and labels fit those limits. The catalog has no timestamps, approval/source fields, or `is_active` column. The seeder supplies only `code` and `name`; the database allocates IDs. The guarded `GisSiteType` model remains unchanged.

| Code | Proposed label | Existing web symbol |
| --- | --- | --- |
| `fish_cage` | Fish Cage | Dedicated cage symbol |
| `fish_pen` | Fish Pen | Dedicated pen symbol |
| `culture_pond` | Fishpond / Culture Pond | Neutral fallback |
| `seaweed_cultivation_site` | Seaweed Cultivation Site | Dedicated seaweed symbol |
| `hatchery_nursery` | Hatchery / Nursery | Neutral fallback |
| `payao_installation` | Payao Installation | Neutral fallback |
| `post_harvest_facility` | Post-Harvest Facility | Dedicated facility symbol |

The existing fixed SVG renderer safely handles all seven codes. The three fallback entries remain distinguishable by their catalog labels in web filters, legends, popups, and details. No new icon artwork or Flutter changes are included. Existing `fishing_gear_site` rows or other additional classifications are retained; this seeder does not create that code.

Geometry remains independent: `location_type` still distinguishes project points from boundaries; Polygon and MultiPolygon remain boundary representations. Project fisheries categories and commodities are unchanged.

## Insertion and conflict policy

- Insert missing codes only. Never supply IDs, update labels, delete catalog rows, or touch `gis_locations` and its assignments/geometries/publication fields.
- An existing code with the exact proposed label retains its original ID and row. A complete matching catalog causes no insert/update/delete statements on repeat execution.
- A requested code with a different label fails with the code identified for review. The transaction rolls back every new catalog row from that attempt; it never renames or remaps the existing classification.
- Additional codes are preserved. Names are not unique in migration 024, so an existing name under another code is not automatically merged or reassigned.
- Insert conflicts from concurrent execution are tolerated only when the final code/label matches. The final rows are read with shared locks and checked before commit. Missing rows or mismatched labels fail; database errors propagate. Database identity sequences may have gaps after failed or concurrent attempts; never depend on contiguous or cross-environment IDs.
- The approved provisioning role needs catalog reads/inserts, identity-sequence access, and permission to acquire shared row locks. The seeder changes no grants, policies, or configuration. Do not grant direct catalog access to public Supabase clients for this operation.

Migration 024, its wrapper, `DatabaseSeeder`, and Composer/bootstrap hooks are unchanged. The dedicated seeder is not automatically invoked.

## Future F08 provisioning integration

This is a proposed addition to [F08 G4: reference data initialization](database-migration-reproducibility-audit.md#g4-reference-data-first-administrator-and-synthetic-uat-scenarios), after G3 stages 8 and 9 have installed and verified migration 024, its Laravel ledger entry, and the approved provisioning/runtime permissions. The migration's empty-catalog checkpoint remains valid before reference initialization. This dedicated insertion stage replaces the earlier proposed need for a new catalog data migration only when explicitly adopted into the approved F08 manifest. The existing F08 audit is preserved.

1. Obtain final BFAR Region VII classification validation and target-specific provisioning authorization. For a separately approved isolated UAT exercise using these proposed labels, record their provisional status in the UAT evidence; it does not approve production use.
2. Verify the exact new isolated target and migration/permission readiness. Capture the current catalog by code/name/ID and confirm the expected existing rows. Stop on conflicting labels; resolve them through a separate reviewed decision, without renaming or deleting records just to make the seeder pass.
3. Run only the named seeder from `web/` during that future authorized procedure:

   ```powershell
   php artisan db:seed --class="Database\Seeders\GisSiteTypeSeeder"
   ```

   This command is documentation for future use; it was not run against staging or a shared database. Do not run plain `db:seed`, `DatabaseSeeder`, `migrate --seed`, or a migration command as part of this catalog step.

4. Verify the seven exact code/label pairs and unique generated IDs. A fresh empty catalog should contain seven rows; an existing catalog may contain additional rows. Confirm pre-existing IDs, labels, location assignments, geometries, publication fields, audit history, and photo metadata remain unchanged. Record source revision, seeder checksum, target identity, result, and catalog snapshot in F08 evidence. Seeding does not add a Laravel migration-ledger entry.
5. Continue with authorized F08 business/UAT workflows. Catalog insertion does not assign a site type to any existing or future location automatically. Final taxonomy edits and any location classification remain separate reviewed operations. Preserve Unclassified until a user explicitly assigns a type through the authorized GIS workflow.

## Verification boundary

The dedicated feature tests use `Tests\TestCase` and `TestEnvironmentGuard`, SQLite `:memory:`, synthetic catalog constraints and opaque location fixtures, and blocked Laravel HTTP. They do not execute migrations, load the application `.env`, or connect to Supabase/PostgreSQL. They cover initial insertion, generated IDs/catalog projection, repeat execution without catalog writes, existing/custom records, assignment preservation, all seven code conflicts, database failure rollback, and detection/rollback of an incomplete ignored insert.

Existing frontend marker tests cover fixed symbols, safe unknown-code fallback, keyboard selection, and public representative-point behavior. Native PostgreSQL identity/RLS/permission/concurrency behavior, deployed map behavior, and BFAR stakeholder acceptance require separate future verification. SQLite fixture preservation is not a native PostGIS geometry test.

Executed on 2026-10-30 with PHP 8.4.27 / PHPUnit 11.5.56, synthetic process-only configuration and temporary storage/cache paths:

| Check | Result |
| --- | --- |
| Dedicated seeder tests | 13 tests / 96 assertions passed |
| Existing GIS management UI, scoped reads, and serializer regressions | 24 tests / 337 assertions passed |
| Existing frontend marker tests | 5 tests passed |
| All seven catalog codes against the existing SVG renderer | Passed: four dedicated symbols, three neutral fallbacks |
| New PHP files: syntax and scoped Pint | Passed |
| Protected source hashes | Migration 024, DatabaseSeeder, and the existing F08 audit unchanged |

No migration, shared/staging seeder execution, environment/cloud change, commit, push, or deployment was performed.
