# Manual Supabase handoff: project finalization and GIS

Prepared 2026-10-16. **For the project owner to review and execute later. Nothing in this guide has been executed against Supabase.** Local results are in [the test report](local-postgresql-migration-test-report.md). Current decision: **READY FOR FURTHER VERIFICATION**, not production readiness.

## 1. Before scheduling deployment

1. Confirm the exact development project and database, migration history, PostgreSQL/PostGIS versions, intended migration owner and actual Laravel database role. Do not publish credentials. SQL Editor results alone do not establish the backend role's permissions.
2. Retain a fresh, consistent schema-and-data backup and the corresponding migration ledger/application revision. Verify the backup can be restored into a separate approved environment. Include relevant Auth identity relationships in the recovery plan; the local tests used only an `auth.users(id)` FK stub. No existing backup was restored during testing.
3. Complete an approved rehearsal on PostgreSQL 17.6 with the intended locale, extension versions, roles, grants and RLS. The local run used Windows PostgreSQL 18.1, PostGIS 3.6.2 and builtin C.UTF-8. Recheck Unicode-only remarks and FK cleanup there. Resolve differences before deployment.
4. Review the matching Laravel/UI changes already in the working tree. Do not deploy an arbitrary dirty working tree wholesale. GIS deletion validates the exact 014 trigger metadata; mixed application/migration versions can cause denials or protection gaps.
5. Pause operational writes, queue workers and older application processes for the migration window. Retain backups and an agreed recovery plan before proceeding. Run the following read-only queries and save their results while writes remain paused.

## 2. Read-only preflight SQL

These queries change no data, schema, roles or policies. Run them as the intended migration owner and repeat the privilege/RLS inspection through an approved connection as the actual Laravel role. A missing prerequisite, unexpected previously deployed finalization object, different owner, or unexplained trigger is a **STOP for review**, not a reason to replay the baseline or grant broad privileges.

```sql
SELECT current_database(), current_user, session_user, version(),
       current_setting('server_encoding') AS encoding,
       current_setting('transaction_isolation') AS isolation,
       current_setting('row_security') AS row_security,
       current_setting('session_replication_role') AS replication_role;

SELECT datlocprovider, datcollate, datctype,
       COALESCE(to_jsonb(d)->>'datlocale', to_jsonb(d)->>'daticulocale') AS provider_locale,
       to_jsonb(d)->>'datcollversion' AS locale_version
FROM pg_database d WHERE datname = current_database();

SELECT e.extname, e.extversion, n.nspname AS extension_schema
FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace
WHERE e.extname IN ('postgis','pgcrypto');

SELECT name, default_version, installed_version
FROM pg_available_extensions WHERE name IN ('postgis','pgcrypto');

SELECT to_regclass('supabase_migrations.schema_migrations') AS possible_supabase_ledger,
       to_regclass('public.migrations') AS possible_laravel_ledger;

SELECT status, count(*) FROM public.projects GROUP BY status ORDER BY status;
SELECT status, count(*) FROM public.associations GROUP BY status ORDER BY status;

SELECT table_name, column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'public' AND (
 (table_name = 'projects' AND column_name IN ('closed_at','closed_by','closure_remarks'))
 OR (table_name = 'associations' AND column_name IN ('terminated_at','terminated_by','termination_remarks'))
) ORDER BY table_name, column_name;

SELECT to_regprocedure('public.protect_project_completion()') AS parent_guard,
       to_regprocedure('public.protect_finalized_project_history()') AS history_guard,
       to_regprocedure('public.project_history_project_ids(text,jsonb)') AS ancestry_helper;
```

Expected before a genuinely pending 011: no three project closure columns or new finalization functions. If they already exist, establish what was applied and compare the deployed definitions; **do not rerun these non-idempotent files**. Association lifecycle remains `active`/`inactive` plus archive metadata; a previously deployed Association Termination implementation requires separate reconciliation. Migration 010 is now comments only and does not reverse an earlier deployment. Inspect the confirmed ledger with a separately reviewed SELECT appropriate to its actual columns; do not assume the two ledgers above are interchangeable.

```sql
WITH required(name) AS (VALUES
 ('projects'),('associations'),('user_profiles'),('roles'),('commodities'),
 ('project_products'),('project_materials'),('trainings'),('training_participants'),
 ('program_activity_records'),('monitoring_records'),('monitoring_visit_reports'),
 ('monitoring_production'),('monitoring_production_monthly'),('monitoring_income'),
 ('monitoring_materials'),('gis_locations'),('audit_logs')
)
SELECT r.name, c.oid::regclass AS relation, pg_get_userbyid(c.relowner) AS owner,
       c.relrowsecurity, c.relforcerowsecurity,
       has_table_privilege(current_user, c.oid, 'SELECT') AS can_select,
       has_table_privilege(current_user, c.oid, 'INSERT') AS can_insert,
       has_table_privilege(current_user, c.oid, 'UPDATE') AS can_update,
       has_table_privilege(current_user, c.oid, 'DELETE') AS can_delete
FROM required r LEFT JOIN pg_class c ON c.oid = to_regclass('public.' || r.name)
ORDER BY r.name;

SELECT rolname, rolsuper, rolbypassrls, rolinherit, rolcanlogin
FROM pg_roles WHERE rolname IN (current_user, session_user, 'anon', 'authenticated')
 OR oid IN (SELECT relowner FROM pg_class WHERE oid IN
   (to_regclass('public.projects'), to_regclass('public.gis_locations')));

SELECT has_schema_privilege(current_user, 'public', 'USAGE') AS public_usage,
       has_schema_privilege(current_user, 'extensions', 'USAGE') AS extensions_usage,
       has_schema_privilege('anon', 'public', 'CREATE') AS anon_public_create,
       has_schema_privilege('authenticated', 'public', 'CREATE') AS authenticated_public_create;

SELECT schemaname, tablename, policyname, roles, cmd, qual, with_check
FROM pg_policies WHERE schemaname = 'public' AND tablename IN
 ('projects','associations','user_profiles','commodities','project_products','project_materials',
  'trainings','training_participants','program_activity_records','monitoring_records',
  'monitoring_visit_reports','monitoring_production','monitoring_production_monthly',
  'monitoring_income','monitoring_materials','gis_locations','audit_logs')
ORDER BY tablename, policyname;

SELECT c.conrelid::regclass AS relation, c.conname, c.convalidated,
       pg_get_constraintdef(c.oid) AS definition
FROM pg_constraint c WHERE c.conrelid IN
 (to_regclass('public.projects'), to_regclass('public.monitoring_production_monthly'),
  to_regclass('public.gis_locations')) ORDER BY c.conrelid::regclass::text, c.conname;

SELECT c.conrelid::regclass AS referencing_relation, c.conname,
       pg_get_constraintdef(c.oid) AS definition
FROM pg_constraint c WHERE c.contype = 'f'
 AND c.confrelid = to_regclass('public.gis_locations');

SELECT c.relname, t.tgname, t.tgtype, t.tgenabled, t.tgnargs, t.tgconstraint,
       t.tgattr::text AS columns, t.tgqual::text AS condition,
       t.tgoldtable, t.tgnewtable, t.tgfoid::regprocedure AS function,
       pg_get_triggerdef(t.oid) AS definition
FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
WHERE NOT t.tgisinternal AND t.tgrelid IN
 (to_regclass('public.projects'), to_regclass('public.gis_locations'))
ORDER BY c.relname, t.tgname;

SELECT n.nspname, p.proname, pg_get_function_identity_arguments(p.oid) AS arguments,
       pg_get_userbyid(p.proowner) AS owner, p.prosecdef, p.proconfig, p.proacl,
       has_function_privilege('anon', p.oid, 'EXECUTE') AS anon_execute,
       has_function_privilege('authenticated', p.oid, 'EXECUTE') AS authenticated_execute,
       pg_get_functiondef(p.oid) AS definition
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public' AND p.proname IN
 ('protect_project_completion','protect_finalized_project_history','project_history_project_ids',
  'user_has_role','validate_gis_location') ORDER BY p.proname;
```

014 explicitly requires its executor to own all 13 named project/history/GIS tables. Its two private SECURITY DEFINER functions must retain that trusted ownership; `public` must not be writable by untrusted roles. The parent guard is SECURITY INVOKER: the actual writer needs visibility/privileges on associations, roles/profile checks and commodity/profile FK cleanup dependencies. Native GIS functions also require `USAGE` on `extensions`. `row_security=off` fails closed when RLS would apply; it is not a bypass grant. FORCE RLS without a compatible trusted execution role may block valid writes. Resolve the role model separately; do not disable RLS, change owners, grant client DML/BYPASSRLS, or expose helper EXECUTE to force deployment through.

Save this read-only count baseline and repeat it after all four files, before any functional writes. Retain the backup for full record comparison; equal counts alone do not prove equal values.

```sql
SELECT 'projects' AS relation, count(*) AS rows FROM public.projects
UNION ALL SELECT 'associations', count(*) FROM public.associations
UNION ALL SELECT 'user_profiles', count(*) FROM public.user_profiles
UNION ALL SELECT 'commodities', count(*) FROM public.commodities
UNION ALL SELECT 'project_products', count(*) FROM public.project_products
UNION ALL SELECT 'project_materials', count(*) FROM public.project_materials
UNION ALL SELECT 'trainings', count(*) FROM public.trainings
UNION ALL SELECT 'training_participants', count(*) FROM public.training_participants
UNION ALL SELECT 'program_activity_records', count(*) FROM public.program_activity_records
UNION ALL SELECT 'monitoring_records', count(*) FROM public.monitoring_records
UNION ALL SELECT 'monitoring_visit_reports', count(*) FROM public.monitoring_visit_reports
UNION ALL SELECT 'monitoring_production', count(*) FROM public.monitoring_production
UNION ALL SELECT 'monitoring_production_monthly', count(*) FROM public.monitoring_production_monthly
UNION ALL SELECT 'monitoring_income', count(*) FROM public.monitoring_income
UNION ALL SELECT 'monitoring_materials', count(*) FROM public.monitoring_materials
UNION ALL SELECT 'gis_locations', count(*) FROM public.gis_locations
UNION ALL SELECT 'audit_logs', count(*) FROM public.audit_logs
ORDER BY relation;
```

## 3. Exact files and execution order

Only after the preflight and version/role rehearsal pass, use the project's reviewed migration mechanism to apply each **entire file**, stopping on the first error:

| Order | Source under `supabase/migrations/` | Tested SHA-256 |
| --- | --- | --- |
| 1 | `011_add_permanent_project_closure.sql` | `9a280a4e2081867a29b9aa6dbf7ac3db9a18959de4d20f2fa8915799da95035f` |
| 2 | `012_allow_zero_production_activities.sql` | `765364b07bb60c67891927cf2d780b0262365d2c6bc6709fdaaa8b0905d842ad` |
| 3 | `013_add_permanent_project_finalization.sql` | `a5d72ea6be8ad9a2756e0ccdb1d8a1e97d00457809c09307cbf627881a221ba4` |
| 4 | `014_protect_finalized_project_history.sql` | `290ca62de9f60f1790c6382d35dde7cfe1cf860de92112efdbca62e44bddfa5a` |

001–009 must already be accurately present; **never replay them over existing data**. 010 is a withdrawn, comment-only placeholder: preserve numbering, do not restore its old implementation. Do not deploy `supabase/tests/`, synthetic fixtures, local runners, scratch credentials or the regression SQL. Do not run the local harness against Supabase. Do not use `php artisan migrate` as a substitute for this domain migration review.

Every pending file has its own BEGIN/COMMIT and 10-second lock timeout. Expect no data backfill/deletion, new grants, or policy changes. 011 adds three nullable evidence columns/FK, CHECK and parent trigger. 012 permits zero activities. 013 adds the distinct irreversible `terminated` outcome and amends the parent guard. 014 adds two private functions and 12 child/GIS triggers. Deploy the reviewed matching Laravel/UI before reopening writes; queries select `closed_at` and need the schema ready.

## 4. Read-only post-migration SQL

Repeat the identity, owner/privilege/RLS, constraints, GIS incoming-FK/trigger and record-count queries above. Then inspect:

```sql
SELECT column_name, data_type, is_nullable
FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'projects'
 AND column_name IN ('closed_at','closed_by','closure_remarks') ORDER BY column_name;

SELECT conname, convalidated, pg_get_constraintdef(oid) AS definition
FROM pg_constraint WHERE conrelid = 'public.projects'::regclass
 AND (conname IN ('projects_status_check','ck_projects_closure_metadata')
      OR (contype = 'f' AND confrelid = 'public.user_profiles'::regclass))
ORDER BY conname;

SELECT conname, convalidated, pg_get_constraintdef(oid) AS definition
FROM pg_constraint WHERE conrelid = 'public.monitoring_production_monthly'::regclass
 AND conname = 'chk_monitoring_production_activity_count';

WITH expected(name) AS (VALUES ('projects'),('project_products'),('project_materials'),
 ('trainings'),('training_participants'),('program_activity_records'),('monitoring_records'),
 ('monitoring_visit_reports'),('monitoring_production'),('monitoring_production_monthly'),
 ('monitoring_income'),('monitoring_materials'),('gis_locations'))
SELECT e.name, t.tgname, t.tgtype, t.tgenabled, t.tgnargs, t.tgconstraint,
       t.tgattr::text AS columns, t.tgqual::text AS condition,
       t.tgoldtable, t.tgnewtable, t.tgfoid::regprocedure AS function,
       pg_get_triggerdef(t.oid) AS definition
FROM expected e LEFT JOIN pg_trigger t ON t.tgrelid = to_regclass('public.' || e.name)
 AND t.tgname = CASE WHEN e.name = 'projects' THEN 'trg_protect_project_completion'
                    ELSE 'trg_protect_finalized_project_history' END
ORDER BY e.name;

SELECT p.oid::regprocedure AS function, pg_get_userbyid(p.proowner) AS owner,
       p.prosecdef, p.proconfig, p.proacl,
       has_function_privilege('anon', p.oid, 'EXECUTE') AS anon_execute,
       has_function_privilege('authenticated', p.oid, 'EXECUTE') AS authenticated_execute,
       pg_get_functiondef(p.oid) AS definition
FROM pg_proc p WHERE p.oid IN ('public.protect_project_completion()'::regprocedure,
 'public.protect_finalized_project_history()'::regprocedure,
 'public.project_history_project_ids(text,jsonb)'::regprocedure) ORDER BY p.oid::regprocedure::text;

SELECT status, count(*) AS rows,
       count(*) FILTER (WHERE closed_at IS NULL AND closed_by IS NULL AND closure_remarks IS NULL) AS no_evidence,
       count(*) FILTER (WHERE closed_at IS NOT NULL) AS recorded_finalizations
FROM public.projects GROUP BY status ORDER BY status;

SELECT count(*) AS inconsistent_finalization_rows FROM public.projects
WHERE (status = 'terminated' AND closed_at IS NULL)
   OR (closed_at IS NOT NULL AND (status NOT IN ('completed','terminated') OR closure_remarks IS NULL))
   OR (closed_at IS NULL AND (closed_by IS NOT NULL OR closure_remarks IS NOT NULL));

SELECT count(*) AS negative_activity_counts FROM public.monitoring_production_monthly WHERE activity_count < 0;
```

Expected: `closed_at` timestamp with time zone, `closed_by` UUID, `closure_remarks` text, all nullable; `closed_by` FK ON DELETE SET NULL; six allowed statuses (`planned`,`ongoing`,`completed`,`suspended`,`cancelled`,`terminated`); evidence CHECK includes visible remarks <=2,000 characters; zero activities allowed, negative denied. All CHECKs validated. Thirteen expected trigger rows must be present/enabled `O`, type `29` (AFTER ROW INSERT/UPDATE/DELETE), no arguments/filters/column restrictions/transition tables. Verify the actual functions, not trigger names alone. Two history functions are SECURITY DEFINER; parent is INVOKER; all three pin `search_path=pg_catalog, public, pg_temp` and `row_security=off`, with no anon/authenticated EXECUTE. Legacy completed evidence stays null; no finalization metadata should be fabricated. Both inconsistency counts should be zero. Existing records/counts must match the paused-write baseline.

These queries inspect definitions and anomalies; they do not prove application authorization or every Unicode value by themselves. Complete the approved functional rehearsal with dedicated synthetic development records, then browser checks using Admin, assigned/unassigned Field Officer, Association Account and guest. Exercise both final outcomes, stale/duplicate submissions, ongoing Production zero activities and Income retention, authorized GIS edit/publish/unpublish/archive, finalized GIS deletion/reassignment denial, eligible ongoing GIS deletion, historical reads and error rollback. Do not experiment on historical business records. Exact cloud Auth/login/session, network, API exposure and browser behavior remain outside the local result.

## 5. Failure handling and recovery

Stop at the first SQL error; retain exact migration, SQLSTATE, statement and server detail without credentials or personal data. If the client is still in a failed transaction, issue ROLLBACK through the reviewed deployment session. Inspect the ledger/catalogs before retrying. Earlier successfully committed files remain applied; there is no automatic four-file rollback. Keep writes paused if schema/application compatibility is incomplete.

Lock timeout `55P03` means investigate conflicting writers/long transactions and reschedule. A `23514`, missing relation/column, duplicate object, owner preflight failure or `42501` is a prerequisite/data/definition/privilege investigation; do not remove constraints, disable triggers/RLS, make up evidence, downgrade finalized statuses, truncate history, or skip a migration. A statement timeout is also an error, not proof of success. Prepare a narrow reviewed forward fix, or follow the verified backup recovery plan in an approved environment. Do not blindly replay 001–009, 011 or 014.

Runtime project-linked history writes intentionally require READ COMMITTED; REPEATABLE READ is rejected with `25000`. Arbitrary direct writers can deadlock despite the tested service schedules. Use the trusted services, short transactions, consistent actor/association/project/descendant lock ordering, and bounded whole-transaction retries for `40P01`/`40001`. An uncertain commit needs state/idempotency reconciliation before retry. Administrative owners/superusers, TRUNCATE/trigger-alter privileges and direct actor-ID spoofing remain outside row-trigger authorization; restrict those credentials. The migrations do not replace Laravel identity, assignment checks or mandatory audits.

The pre/post query blocks were also syntax/execution checked against the retained local database. Their Supabase results and the PostgreSQL 17.6 rehearsal are still unverified.
