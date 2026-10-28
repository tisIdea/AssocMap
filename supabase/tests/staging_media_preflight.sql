-- Review only. Requires separately authorized access to the intended development project.
-- psql variables: expected_database, backend_role, migration_role (role names, not passwords).
-- No migrations, grants, role changes, fixtures, or provider operations are performed.
\set ON_ERROR_STOP on
\set ON_ERROR_ROLLBACK off
\pset pager off

BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

-- 1. Verify project identity out of band as well: many Supabase projects use database postgres.
SELECT current_database() AS database_name, current_user AS inspecting_role,
    session_user AS login_role, current_setting('server_version') AS postgres_version,
    current_database() = :'expected_database' AS expected_database,
    current_setting('transaction_read_only') AS read_only;
SELECT ssl, version, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid();
SELECT extname, extversion, extnamespace::regnamespace AS schema_name
FROM pg_extension WHERE extname IN ('postgis', 'pgcrypto') ORDER BY extname;

-- Missing role rows are blockers. Do not print passwords or connection settings.
SELECT rolname, rolsuper, rolbypassrls, rolcanlogin, rolcreatedb, rolcreaterole, rolinherit
FROM pg_roles WHERE rolname IN (current_user, :'backend_role', :'migration_role', 'anon', 'authenticated')
ORDER BY rolname;
SELECT rolname, has_database_privilege(oid, current_database(), 'CONNECT') AS can_connect
FROM pg_roles WHERE rolname IN (:'backend_role', :'migration_role');
SELECT member.rolname AS member, parent.rolname AS granted_role, m.admin_option
FROM pg_auth_members m JOIN pg_roles member ON member.oid = m.member
JOIN pg_roles parent ON parent.oid = m.roleid
WHERE member.rolname IN (current_user, :'backend_role', :'migration_role', 'anon', 'authenticated')
ORDER BY member.rolname, parent.rolname;
SELECT n.nspname, pg_get_userbyid(n.nspowner) AS owner, n.nspacl
FROM pg_namespace n WHERE n.nspname IN ('public', 'extensions');
SELECT r.rolname, n.nspname, has_schema_privilege(r.oid, n.oid, 'USAGE') AS can_use,
    has_schema_privilege(r.oid, n.oid, 'CREATE') AS can_create
FROM pg_roles r CROSS JOIN pg_namespace n
WHERE r.rolname IN (:'backend_role', :'migration_role') AND n.nspname IN ('public', 'extensions');

-- 2. Inspect ledgers without assuming manual SQL 017/018 are recorded by Laravel.
SELECT to_regclass('public.migrations') IS NOT NULL AS has_laravel_ledger,
    to_regclass('supabase_migrations.schema_migrations') IS NOT NULL AS has_supabase_ledger,
    to_regclass('public.media_photos') IS NOT NULL AS has_media_table,
    to_regclass('public.gis_site_types') IS NOT NULL AS has_site_type_table,
    EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('public.associations')
        AND attname = 'barangay_id' AND NOT attisdropped)
    AND EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('public.associations')
        AND attname = 'specific_address' AND NOT attisdropped) AS has_017_columns
\gset
\if :has_laravel_ledger
SELECT id, migration, batch FROM public.migrations ORDER BY id;
SELECT migration, count(*) AS entries FROM public.migrations
WHERE migration IN ('2026_10_27_000000_add_media_photos', '2026_10_28_000000_add_gis_site_types')
GROUP BY migration ORDER BY migration;
\else
\echo 'BLOCKER: Laravel migration ledger is absent; reconcile framework prerequisites.'
\endif

\if :has_media_table
SELECT state, count(*) AS photo_rows,
    count(*) FILTER (WHERE gis_location_id IS NOT NULL) AS gis_photo_rows
FROM public.media_photos GROUP BY state ORDER BY state;
\endif
\if :has_site_type_table
SELECT count(*) AS existing_catalog_rows FROM public.gis_site_types;
\endif
\if :has_supabase_ledger
SELECT version FROM supabase_migrations.schema_migrations ORDER BY version;
\else
\echo 'No Supabase CLI ledger: obtain operator evidence for manually applied SQL.'
\endif

-- 3. Fingerprint schema before deciding whether any migration is pending.
SELECT c.relname, pg_get_userbyid(c.relowner) AS owner, c.relrowsecurity, c.relforcerowsecurity,
    (NOT c.relrowsecurity OR r.rolsuper OR r.rolbypassrls
        OR (c.relowner = r.oid AND NOT c.relforcerowsecurity)) AS inspector_has_full_row_visibility
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
CROSS JOIN pg_roles r
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND r.rolname = current_user
    AND c.relname IN ('associations', 'areas', 'barangays', 'municipalities', 'projects', 'gis_locations',
        'user_profiles', 'monitoring_records', 'monitoring_visit_reports', 'monitoring_materials',
        'media_photos', 'gis_site_types', 'audit_logs') ORDER BY c.relname;
-- Do not interpret zero data counts as complete when the visibility check is false.
SELECT c.relname, a.attname, format_type(a.atttypid, a.atttypmod) AS data_type,
    a.attnotnull, a.attidentity, pg_get_expr(d.adbin, d.adrelid) AS default_expression
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
WHERE n.nspname = 'public' AND (c.relname IN ('media_photos', 'gis_site_types')
    OR (c.relname = 'associations' AND a.attname IN ('id', 'area_id', 'barangay_id', 'specific_address'))
    OR (c.relname = 'areas' AND a.attname IN ('id', 'barangay_id', 'address'))
    OR (c.relname = 'gis_locations' AND a.attname IN ('id', 'project_id', 'association_id', 'geom', 'site_type_id'))
    OR (c.relname = 'user_profiles' AND a.attname IN ('auth_sessions_valid_after', 'email_change_hold_id', 'approved_email')))
ORDER BY c.relname, a.attnum;
SELECT c.conrelid::regclass AS relation, c.conname, c.convalidated, c.condeferrable, c.condeferred,
    pg_get_constraintdef(c.oid, true) AS definition
FROM pg_constraint c WHERE c.conrelid IN (to_regclass('public.associations'), to_regclass('public.areas'),
    to_regclass('public.media_photos'), to_regclass('public.gis_site_types'), to_regclass('public.gis_locations'))
ORDER BY c.conrelid::regclass::text, c.conname;
SELECT c.relname AS table_name, ic.relname AS index_name, i.indisvalid, i.indisready,
    pg_get_indexdef(i.indexrelid) AS definition
FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid JOIN pg_class ic ON ic.oid = i.indexrelid
WHERE c.oid IN (to_regclass('public.associations'), to_regclass('public.areas'),
    to_regclass('public.media_photos'), to_regclass('public.gis_site_types'), to_regclass('public.gis_locations'))
ORDER BY c.relname, ic.relname;

-- 4. All statuses are included. Pre-018 requires valid Area mappings for every Association.
\if :has_017_columns
SELECT count(*) AS associations,
    count(*) FILTER (WHERE a.area_id IS NULL) AS area_less_rows,
    count(*) FILTER (WHERE ar.id IS NULL OR ab.id IS NULL) AS pre018_unresolved_area_mappings,
    count(*) FILTER (WHERE a.barangay_id IS NULL) AS pending_backfill,
    count(*) FILTER (WHERE a.area_id IS NOT NULL AND ar.id IS NULL) AS orphan_areas,
    count(*) FILTER (WHERE a.barangay_id IS NOT NULL AND b.id IS NULL) AS orphan_direct_barangays,
    count(*) FILTER (WHERE a.barangay_id IS NOT NULL AND (b.id IS NULL OR m.id IS NULL)) AS unreadable_direct_geography,
    count(*) FILTER (WHERE a.area_id IS NOT NULL AND a.barangay_id IS NOT NULL
        AND a.barangay_id IS DISTINCT FROM ar.barangay_id) AS conflicting_barangays
FROM public.associations a LEFT JOIN public.areas ar ON ar.id = a.area_id
LEFT JOIN public.barangays ab ON ab.id = ar.barangay_id
LEFT JOIN public.barangays b ON b.id = a.barangay_id
LEFT JOIN public.municipalities m ON m.id = b.municipality_id;
\else
\echo 'BLOCKER: 017 columns absent; do not execute 018 or use the current true/true configuration.'
\endif

-- 5. 014/024 safeguards: compare definitions, owner and ACL with the reviewed source.
SELECT p.oid::regprocedure AS function_name, pg_get_userbyid(p.proowner) AS owner,
    p.prosecdef, p.proconfig, p.proacl, pg_get_functiondef(p.oid) AS definition
FROM pg_proc p WHERE p.oid IN (to_regprocedure('public.project_history_project_ids(text,jsonb)'),
    to_regprocedure('public.protect_finalized_project_history()'),
    to_regprocedure('public.protect_project_completion()')) ORDER BY p.oid::regprocedure::text;
SELECT t.tgrelid::regclass AS relation, t.tgname, t.tgenabled,
    pg_get_triggerdef(t.oid, true) AS definition
FROM pg_trigger t WHERE NOT t.tgisinternal AND t.tgrelid IN (to_regclass('public.projects'),
    to_regclass('public.gis_locations'), to_regclass('public.monitoring_records'),
    to_regclass('public.monitoring_visit_reports'), to_regclass('public.monitoring_materials'))
ORDER BY t.tgrelid::regclass::text, t.tgname;
SELECT c.relname, pg_get_userbyid(c.relowner) AS owner,
    pg_get_userbyid(c.relowner) = :'migration_role' AS exact_024_migration_owner
FROM pg_class c WHERE c.oid IN (to_regclass('public.projects'), to_regclass('public.gis_locations'));
SELECT c.relname, pg_get_userbyid(c.relowner) AS owner,
    pg_get_userbyid(c.relowner) = :'migration_role' AS migration_is_actual_owner
FROM pg_class c WHERE c.oid IN (to_regclass('public.associations'), to_regclass('public.areas'));
SELECT c.relname, has_table_privilege(r.oid, c.oid, 'REFERENCES') AS migration_can_reference
FROM pg_class c CROSS JOIN pg_roles r WHERE r.rolname = :'migration_role'
    AND c.oid IN (to_regclass('public.projects'), to_regclass('public.gis_locations'),
        to_regclass('public.monitoring_visit_reports'), to_regclass('public.monitoring_materials'),
        to_regclass('public.user_profiles')) ORDER BY c.relname;
SELECT pg_get_userbyid(p.proowner) = :'migration_role' AS exact_024_function_owner
FROM pg_proc p WHERE p.oid = to_regprocedure('public.protect_finalized_project_history()');

-- 6. Effective runtime/client grants, RLS policies, and default privileges.
WITH requirements(table_name, privilege) AS (VALUES
    ('media_photos','SELECT'), ('media_photos','INSERT'), ('media_photos','UPDATE'),
    ('gis_site_types','SELECT'), ('gis_locations','SELECT'), ('gis_locations','INSERT'),
    ('gis_locations','UPDATE'), ('gis_locations','DELETE'), ('audit_logs','INSERT'),
    ('roles','SELECT'), ('user_profiles','SELECT'), ('user_invitations','SELECT'),
    ('associations','SELECT'), ('projects','SELECT'), ('monitoring_records','SELECT'),
    ('monitoring_visit_reports','SELECT'), ('monitoring_materials','SELECT'),
    ('areas','SELECT'), ('barangays','SELECT'), ('municipalities','SELECT'))
SELECT q.table_name, q.privilege, CASE WHEN c.oid IS NOT NULL AND r.oid IS NOT NULL
    THEN has_table_privilege(r.oid, c.oid, q.privilege) END AS runtime_has_privilege
FROM requirements q LEFT JOIN pg_class c ON c.oid = to_regclass('public.' || q.table_name)
LEFT JOIN pg_roles r ON r.rolname = :'backend_role' ORDER BY q.table_name, q.privilege;
SELECT a.attname, has_column_privilege(r.oid, c.oid, a.attnum, 'UPDATE') AS runtime_catalog_column_update,
    has_table_privilege(r.oid, c.oid, 'INSERT') AS runtime_catalog_insert,
    has_table_privilege(r.oid, c.oid, 'DELETE') AS runtime_catalog_delete
FROM pg_class c JOIN pg_attribute a ON a.attrelid = c.oid CROSS JOIN pg_roles r
WHERE c.oid = to_regclass('public.gis_site_types') AND r.rolname = :'backend_role'
    AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum;
-- Column-level UPDATE(id) is sufficient for these row locks; existing workflows may need more.
SELECT c.relname, has_any_column_privilege(r.oid, c.oid, 'UPDATE') AS runtime_can_lock_rows
FROM pg_class c CROSS JOIN pg_roles r WHERE r.rolname = :'backend_role'
AND c.oid IN (to_regclass('public.roles'), to_regclass('public.user_profiles'),
    to_regclass('public.associations'), to_regclass('public.projects'), to_regclass('public.monitoring_records'),
    to_regclass('public.monitoring_visit_reports'), to_regclass('public.monitoring_materials'),
    to_regclass('public.media_photos'), to_regclass('public.gis_site_types')) ORDER BY c.relname;
SELECT r.rolname, c.relname, privilege,
    has_table_privilege(r.oid, c.oid, privilege) AS client_has_privilege
FROM pg_roles r CROSS JOIN pg_class c
CROSS JOIN unnest(ARRAY['SELECT','INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER']) AS privilege
WHERE r.rolname IN ('anon','authenticated') AND c.oid IN (to_regclass('public.media_photos'),
    to_regclass('public.gis_site_types')) ORDER BY r.rolname, c.relname, privilege;
SELECT r.rolname, c.relname, a.attname, privilege,
    has_column_privilege(r.oid, c.oid, a.attnum, privilege) AS client_has_column_privilege
FROM pg_roles r CROSS JOIN pg_class c JOIN pg_attribute a ON a.attrelid = c.oid
CROSS JOIN unnest(ARRAY['SELECT','INSERT','UPDATE','REFERENCES']) AS privilege
WHERE r.rolname IN ('anon','authenticated') AND a.attnum > 0 AND NOT a.attisdropped
    AND c.oid IN (to_regclass('public.media_photos'), to_regclass('public.gis_site_types'))
    AND has_column_privilege(r.oid, c.oid, a.attnum, privilege)
ORDER BY r.rolname, c.relname, a.attname, privilege;
SELECT schemaname, tablename, policyname, roles, cmd, qual, with_check FROM pg_policies
WHERE schemaname = 'public' AND tablename IN ('media_photos','gis_site_types') ORDER BY tablename, policyname;
SELECT pg_get_userbyid(defaclrole) AS creating_role, defaclnamespace::regnamespace AS schema_name,
    defaclobjtype, defaclacl FROM pg_default_acl ORDER BY creating_role, defaclnamespace::regnamespace::text, defaclobjtype;
SELECT r.rolname, p.oid::regprocedure AS function_name, has_function_privilege(r.oid, p.oid, 'EXECUTE') AS can_execute
FROM pg_roles r CROSS JOIN pg_proc p WHERE r.rolname IN (:'backend_role','anon','authenticated')
    AND p.oid = to_regprocedure('public.user_has_role(uuid,text)');

ROLLBACK;
