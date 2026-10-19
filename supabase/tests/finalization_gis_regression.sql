-- Local-only regression source, NOT a migration and NOT for Supabase development data.
-- Requires an independently prepared, uniquely named assocmap_gis_test_YYYYMMDD_hex8 database,
-- migrations 001-009 and corrected 011-014, and the trusted table-owner test role.
-- Set session assocmap.expected_database/expected_port to the verified target,
-- assocmap.synthetic_only = 'yes' and assocmap.gis_fixture_ids to JSON:
-- {"completed":"uuid","terminated":"uuid","ongoing":"uuid","admin":"uuid"}.
-- Final projects must have retained rows in every operational child table below.
-- The ongoing project needs an existing linked Program Activity. All changes roll back.
BEGIN ISOLATION LEVEL READ COMMITTED;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '15s';

DO $preflight$
BEGIN
    IF current_database() !~ '^assocmap_gis_test_[0-9]{8}_[0-9a-f]{8}$'
        OR inet_server_addr() IS DISTINCT FROM '127.0.0.1'::inet
        OR current_setting('assocmap.expected_database', true) IS DISTINCT FROM current_database()
        OR current_setting('assocmap.expected_port', true) IS DISTINCT FROM inet_server_port()::text
        OR current_setting('assocmap.synthetic_only', true) IS DISTINCT FROM 'yes' THEN
        RAISE EXCEPTION 'Refusing anything except the explicit synthetic scratch database.';
    END IF;
    IF current_setting('assocmap.gis_fixture_ids', true) IS NULL THEN
        RAISE EXCEPTION 'Prepare synthetic fixture IDs before reviewing/running this source.';
    END IF;
END;
$preflight$;

CREATE TEMP TABLE gis_fixture_ids ON COMMIT PRESERVE ROWS AS
SELECT (j ->> 'completed')::uuid AS completed, (j ->> 'terminated')::uuid AS terminated,
    (j ->> 'ongoing')::uuid AS ongoing, (j ->> 'admin')::uuid AS admin
FROM (SELECT current_setting('assocmap.gis_fixture_ids')::jsonb AS j) input;

CREATE FUNCTION pg_temp.expect_sqlstate(statement TEXT, expected TEXT)
RETURNS void LANGUAGE plpgsql AS $function$
DECLARE actual TEXT;
BEGIN
    BEGIN
        EXECUTE statement;
    EXCEPTION WHEN OTHERS THEN
        GET STACKED DIAGNOSTICS actual = RETURNED_SQLSTATE;
        IF actual = expected THEN RETURN; END IF;
        RAISE EXCEPTION 'Expected %, got % for %', expected, actual, statement;
    END;
    RAISE EXCEPTION 'Expected % but mutation succeeded: %', expected, statement;
END;
$function$;

-- Use the deployed constraint itself, rather than duplicating its regex in a test.
CREATE TEMP TABLE remarks_probe (
    status TEXT NOT NULL, closed_at TIMESTAMPTZ, closure_remarks TEXT, closed_by UUID
);
DO $remarks$
DECLARE definition TEXT; outcome TEXT; remarks TEXT; code INTEGER;
BEGIN
    SELECT pg_get_constraintdef(oid) INTO STRICT definition FROM pg_constraint
    WHERE conrelid = 'public.projects'::regclass AND conname = 'ck_projects_closure_metadata';
    EXECUTE 'ALTER TABLE pg_temp.remarks_probe ADD CONSTRAINT remarks_check ' || definition;
    INSERT INTO remarks_probe(status) VALUES ('completed'), ('ongoing');
    PERFORM pg_temp.expect_sqlstate(
        'INSERT INTO pg_temp.remarks_probe(status) VALUES (''terminated'')', '23514');
    FOREACH outcome IN ARRAY ARRAY['completed', 'terminated'] LOOP
        FOREACH remarks IN ARRAY ARRAY['Reason recorded.', repeat('x', 2000), U&'Natapos na. \00D1', U&'\00D1'] LOOP
            INSERT INTO remarks_probe VALUES (outcome, now(), remarks, NULL);
        END LOOP;
        FOREACH remarks IN ARRAY ARRAY[NULL::text, '', E' \n\t\r', repeat('x', 2001),
            U&'\00A0\1680\2007\202F\3000', U&'\00AD\061C\200B\202E\2060\FEFF',
            U&'\034F\115F\17B4\2800\3164\FE0F\+0E0100',
            U&'\E000\+0F0000', U&'\+0E0001\+0E0020'] LOOP
            PERFORM pg_temp.expect_sqlstate(format(
                'INSERT INTO pg_temp.remarks_probe VALUES (%L, now(), %L, NULL)', outcome, remarks), '23514');
        END LOOP;
        FOR code IN 1..31 LOOP
            PERFORM pg_temp.expect_sqlstate(format(
                'INSERT INTO pg_temp.remarks_probe VALUES (%L, now(), %L, NULL)', outcome, chr(code)), '23514');
        END LOOP;
        FOR code IN 127..159 LOOP
            PERFORM pg_temp.expect_sqlstate(format(
                'INSERT INTO pg_temp.remarks_probe VALUES (%L, now(), %L, NULL)', outcome, chr(code)), '23514');
        END LOOP;
    END LOOP;
END;
$remarks$;

CREATE TEMP TABLE history_before(relation_name TEXT PRIMARY KEY, rows JSONB);
DO $gis$
DECLARE
    f RECORD; p RECORD; location UUID; table_name TEXT; rows_before JSONB; rows_after JSONB;
    history_id UUID; activity UUID;
    tables TEXT[] := ARRAY['projects', 'project_products', 'project_materials', 'trainings', 'training_participants',
        'program_activity_records', 'monitoring_records', 'monitoring_visit_reports', 'monitoring_production',
        'monitoring_production_monthly', 'monitoring_income', 'monitoring_materials'];
BEGIN
    SELECT * INTO STRICT f FROM gis_fixture_ids;
    IF f.completed IS NULL OR f.terminated IS NULL OR f.ongoing IS NULL OR f.admin IS NULL
        OR f.completed = f.terminated OR f.completed = f.ongoing OR f.terminated = f.ongoing THEN
        RAISE EXCEPTION 'Three distinct synthetic projects and an Admin are required.';
    END IF;
    IF NOT public.user_has_role(f.admin, 'system_admin') THEN RAISE EXCEPTION 'Synthetic Admin required.'; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.projects WHERE id = f.completed AND status = 'completed')
        OR NOT EXISTS (SELECT 1 FROM public.projects WHERE id = f.terminated AND status = 'terminated')
        OR NOT EXISTS (SELECT 1 FROM public.projects WHERE id = f.ongoing AND status = 'ongoing' AND closed_at IS NULL) THEN
        RAISE EXCEPTION 'Fixture project outcomes do not match.';
    END IF;
    FOREACH table_name IN ARRAY tables LOOP
        EXECUTE format('SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM public.%I t', table_name) INTO rows_before;
        INSERT INTO history_before VALUES (table_name, rows_before);
    END LOOP;
    FOR p IN SELECT id, status FROM public.projects WHERE id IN (f.completed, f.terminated, f.ongoing) LOOP
        location := gen_random_uuid();
        INSERT INTO public.gis_locations(id, project_id, location_type, location_name, geom, public_title, created_by, updated_by)
        VALUES (location, p.id, 'project_point', 'Synthetic GIS',
            extensions.ST_SetSRID(extensions.ST_MakePoint(123.8, 10.3), 4326), 'Approved synthetic title', f.admin, f.admin);
        UPDATE public.gis_locations SET location_name = 'Corrected synthetic GIS', address = 'Verified synthetic site',
            geom = extensions.ST_SetSRID(extensions.ST_MakePoint(124.1, 10.6), 4326),
            public_description = 'Corrected geographic reference', updated_by = f.admin WHERE id = location;
        UPDATE public.gis_locations SET is_published = true, published_by = f.admin, published_at = now() WHERE id = location;
        UPDATE public.gis_locations SET is_published = false WHERE id = location;
        IF NOT EXISTS (SELECT 1 FROM public.gis_locations WHERE id = location AND project_id = p.id
            AND NOT is_published AND extensions.ST_X(geom) = 124.1 AND extensions.ST_Y(geom) = 10.6) THEN
            RAISE EXCEPTION 'GIS maintenance did not persist the expected coordinates/publication.';
        END IF;
        IF p.status IN ('completed', 'terminated') THEN
            PERFORM pg_temp.expect_sqlstate(format('DELETE FROM public.gis_locations WHERE id = %L', location), '23514');
            PERFORM pg_temp.expect_sqlstate(format('UPDATE public.gis_locations SET project_id = %L WHERE id = %L', f.ongoing, location), '23514');
            PERFORM pg_temp.expect_sqlstate(format('UPDATE public.gis_locations SET association_id = (SELECT association_id FROM public.projects WHERE id = %L), project_id = NULL, location_type = ''association_point'' WHERE id = %L', p.id, location), '23514');
            PERFORM pg_temp.expect_sqlstate(format('UPDATE public.gis_locations SET id = gen_random_uuid() WHERE id = %L', location), '23514');
            PERFORM pg_temp.expect_sqlstate(format('UPDATE public.gis_locations SET created_at = created_at - interval ''1 day'' WHERE id = %L', location), '23514');
            PERFORM pg_temp.expect_sqlstate(format('UPDATE public.gis_locations SET created_by = NULL WHERE id = %L', location), '23514');
            FOREACH table_name IN ARRAY tables[2:array_length(tables, 1)] LOOP
                EXECUTE format('SELECT t.id FROM public.%I t WHERE %L::uuid = ANY(public.project_history_project_ids(%L, to_jsonb(t))) LIMIT 1',
                    table_name, p.id, table_name) INTO history_id;
                IF history_id IS NULL THEN RAISE EXCEPTION 'Missing retained % fixture for %', table_name, p.status; END IF;
                PERFORM pg_temp.expect_sqlstate(format('UPDATE public.%I SET id = id WHERE id = %L', table_name, history_id), '23514');
                IF table_name = 'program_activity_records' THEN
                    PERFORM pg_temp.expect_sqlstate(format('UPDATE public.program_activity_records SET related_project_id = NULL WHERE id = %L', history_id), '23514');
                    PERFORM pg_temp.expect_sqlstate(format('UPDATE public.program_activity_records SET related_project_id = %L, association_id = (SELECT association_id FROM public.projects WHERE id = %L) WHERE id = %L', f.ongoing, f.ongoing, history_id), '23514');
                    PERFORM pg_temp.expect_sqlstate(format('DELETE FROM public.program_activity_records WHERE id = %L', history_id), '23514');
                END IF;
            END LOOP;
        ELSE
            PERFORM pg_temp.expect_sqlstate(format('UPDATE public.gis_locations SET project_id = %L WHERE id = %L', f.completed, location), '23514');
            DELETE FROM public.gis_locations WHERE id = location;
            IF EXISTS (SELECT 1 FROM public.gis_locations WHERE id = location) THEN RAISE EXCEPTION 'Eligible GIS deletion failed.'; END IF;
        END IF;
    END LOOP;
    FOREACH table_name IN ARRAY tables LOOP
        SELECT rows INTO rows_before FROM history_before WHERE relation_name = table_name;
        EXECUTE format('SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM public.%I t', table_name) INTO rows_after;
        IF rows_before IS DISTINCT FROM rows_after THEN RAISE EXCEPTION 'GIS changed operational history in %', table_name; END IF;
    END LOOP;
    -- READ COMMITTED: unlinked activity CRUD and linking/detaching an ongoing project.
    SELECT * INTO STRICT p FROM public.projects WHERE id = f.ongoing;
    activity := gen_random_uuid();
    INSERT INTO public.program_activity_records(id, saad_activity_id, association_id, title, program_year, activity_date, status)
    VALUES (activity, p.saad_activity_id, p.association_id, 'Synthetic unlinked activity', p.program_year, CURRENT_DATE, 'planned');
    UPDATE public.program_activity_records SET title = 'Synthetic unlinked correction' WHERE id = activity;
    UPDATE public.program_activity_records SET related_project_id = p.id WHERE id = activity;
    UPDATE public.program_activity_records SET related_project_id = NULL WHERE id = activity;
    PERFORM pg_temp.expect_sqlstate(format('UPDATE public.program_activity_records SET related_project_id = %L, association_id = (SELECT association_id FROM public.projects WHERE id = %L) WHERE id = %L', f.completed, f.completed, activity), '23514');
    DELETE FROM public.program_activity_records WHERE id = activity;
END;
$gis$;
ROLLBACK;

-- A separate transaction tests stronger isolation without reusing the prior snapshot.
BEGIN ISOLATION LEVEL REPEATABLE READ;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '15s';
DO $repeatable_read$
DECLARE f JSONB; p RECORD; activity UUID; linked UUID; target UUID; destination UUID;
BEGIN
    IF current_database() !~ '^assocmap_gis_test_[0-9]{8}_[0-9a-f]{8}$'
        OR inet_server_addr() IS DISTINCT FROM '127.0.0.1'::inet
        OR current_setting('assocmap.expected_database', true) IS DISTINCT FROM current_database()
        OR current_setting('assocmap.expected_port', true) IS DISTINCT FROM inet_server_port()::text
        OR current_setting('assocmap.synthetic_only', true) IS DISTINCT FROM 'yes' THEN
        RAISE EXCEPTION 'Synthetic scratch database only.';
    END IF;
    f := current_setting('assocmap.gis_fixture_ids')::jsonb;
    SELECT * INTO STRICT p FROM public.projects WHERE id = (f ->> 'ongoing')::uuid;
    activity := gen_random_uuid();
    INSERT INTO public.program_activity_records(id, saad_activity_id, association_id, title, program_year, activity_date, status)
    VALUES (activity, p.saad_activity_id, p.association_id, 'Synthetic RR unlinked activity', p.program_year, CURRENT_DATE, 'planned');
    UPDATE public.program_activity_records SET title = 'Synthetic RR correction' WHERE id = activity;
    FOREACH target IN ARRAY ARRAY[p.id, (f ->> 'completed')::uuid, (f ->> 'terminated')::uuid] LOOP
        BEGIN
            INSERT INTO public.program_activity_records(saad_activity_id, association_id, related_project_id, title, program_year, activity_date, status)
            VALUES (p.saad_activity_id, (SELECT association_id FROM public.projects WHERE id = target), target,
                'Synthetic RR linked activity', p.program_year, CURRENT_DATE, 'planned');
            RAISE EXCEPTION 'Project-linked insertion under RR unexpectedly succeeded.';
        EXCEPTION WHEN SQLSTATE '25000' THEN NULL;
        END;
        BEGIN
            UPDATE public.program_activity_records SET related_project_id = target,
                association_id = (SELECT association_id FROM public.projects WHERE id = target) WHERE id = activity;
            RAISE EXCEPTION 'Project linking under RR unexpectedly succeeded.';
        EXCEPTION WHEN SQLSTATE '25000' THEN NULL;
        END;
        SELECT id INTO linked FROM public.program_activity_records WHERE related_project_id = target LIMIT 1;
        IF linked IS NULL THEN RAISE EXCEPTION 'A linked activity fixture is required for %', target; END IF;
        destination := CASE WHEN target = p.id THEN (f ->> 'completed')::uuid ELSE p.id END;
        BEGIN
            UPDATE public.program_activity_records SET related_project_id = destination,
                association_id = (SELECT association_id FROM public.projects WHERE id = destination) WHERE id = linked;
            RAISE EXCEPTION 'Project reassignment under RR unexpectedly succeeded.';
        EXCEPTION WHEN SQLSTATE '25000' THEN NULL;
        END;
        BEGIN
            UPDATE public.program_activity_records SET related_project_id = NULL WHERE id = linked;
            RAISE EXCEPTION 'Project detachment under RR unexpectedly succeeded.';
        EXCEPTION WHEN SQLSTATE '25000' THEN NULL;
        END;
        BEGIN
            DELETE FROM public.program_activity_records WHERE id = linked;
            RAISE EXCEPTION 'Linked activity deletion under RR unexpectedly succeeded.';
        EXCEPTION WHEN SQLSTATE '25000' THEN NULL;
        END;
    END LOOP;
    DELETE FROM public.program_activity_records WHERE id = activity;
END;
$repeatable_read$;
ROLLBACK;
