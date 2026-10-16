-- Synthetic fixtures for a newly created, explicitly verified local scratch database only.
-- Use the verified local harness with psql -v ON_ERROR_STOP=1.
BEGIN;

DO $safety$
BEGIN
    IF inet_server_addr() IS DISTINCT FROM '127.0.0.1'::inet
       OR current_database() !~ '^assocmap_gis_test_[0-9]{8}_[0-9a-f]{8}$'
       OR current_setting('assocmap.expected_database', true) IS DISTINCT FROM current_database()
       OR current_setting('assocmap.synthetic_only', true) IS DISTINCT FROM 'yes' THEN
        RAISE EXCEPTION 'Verified disposable loopback database required.';
    END IF;
END;
$safety$;

CREATE SCHEMA IF NOT EXISTS assocmap_test;
REVOKE ALL ON SCHEMA assocmap_test FROM PUBLIC, anon, authenticated;
CREATE TABLE IF NOT EXISTS assocmap_test.identities(label TEXT PRIMARY KEY, id UUID NOT NULL);
CREATE TABLE IF NOT EXISTS assocmap_test.cases(label TEXT PRIMARY KEY, ids JSONB NOT NULL);
DO $empty$
BEGIN
    IF EXISTS (SELECT 1 FROM assocmap_test.identities) OR EXISTS (SELECT 1 FROM assocmap_test.cases)
       OR EXISTS (SELECT 1 FROM auth.users) THEN
        RAISE EXCEPTION 'Fixture setup requires empty synthetic fixture/Auth tables; nothing will be reset.';
    END IF;
END;
$empty$;

DO $actors$
DECLARE label TEXT; role_code TEXT; actor UUID;
BEGIN
    FOREACH label IN ARRAY ARRAY['admin','officer','other_officer','account','cleanup_actor'] LOOP
        role_code := CASE WHEN label IN ('admin','cleanup_actor') THEN 'system_admin'
                     WHEN label = 'account' THEN 'association_account' ELSE 'field_officer' END;
        actor := gen_random_uuid();
        INSERT INTO auth.users(id) VALUES (actor);
        INSERT INTO public.user_profiles(id, role_id, display_name, is_active)
        SELECT actor, id, 'Synthetic ' || label, true FROM public.roles WHERE roles.code = role_code;
        INSERT INTO assocmap_test.identities VALUES (label, actor);
    END LOOP;
END;
$actors$;

DO $parents$
DECLARE municipality UUID; barangay UUID; area UUID; association UUID; label TEXT; officer UUID;
BEGIN
    INSERT INTO public.municipalities(name, source) VALUES ('Synthetic Lifecycle Municipality', 'manual') RETURNING id INTO municipality;
    INSERT INTO public.barangays(municipality_id, name, source) VALUES (municipality, 'Synthetic Barangay', 'manual') RETURNING id INTO barangay;
    INSERT INTO public.areas(barangay_id, address) VALUES (barangay, 'Synthetic test area') RETURNING id INTO area;
    SELECT id INTO officer FROM assocmap_test.identities WHERE identities.label = 'officer';
    FOREACH label IN ARRAY ARRAY['association','other_association','inactive_association','archived_association'] LOOP
        INSERT INTO public.associations(name, area_id, saad_phase2_join_date, primary_field_officer_id, status, archived_at)
        VALUES ('Synthetic ' || label, area, '2023-01-01',
            CASE WHEN label = 'other_association' THEN (SELECT id FROM assocmap_test.identities WHERE identities.label = 'other_officer') ELSE officer END,
            CASE WHEN label = 'inactive_association' THEN 'inactive' ELSE 'active' END,
            CASE WHEN label = 'archived_association' THEN now() ELSE NULL END)
        RETURNING id INTO association;
        INSERT INTO assocmap_test.identities VALUES (label, association);
    END LOOP;
    INSERT INTO public.association_accounts(association_id, user_id)
    SELECT a.id, u.id FROM assocmap_test.identities a CROSS JOIN assocmap_test.identities u
    WHERE a.label = 'association' AND u.label = 'account';
END;
$parents$;

CREATE FUNCTION assocmap_test.make_case(case_label TEXT, initial_status TEXT DEFAULT 'ongoing', association_label TEXT DEFAULT 'association')
RETURNS JSONB LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $fixture$
DECLARE
    a UUID; actor UUID; officer UUID; p UUID; product UUID; product_extra UUID; product_insert UUID;
    material UUID; material_extra UUID; training UUID; training_extra UUID; participant UUID;
    member UUID; member_extra UUID; activity UUID; monitoring UUID; monitoring_extra UUID;
    production UUID; production_extra UUID; monthly UUID; income UUID; monitored_material UUID;
    visit UUID; location UUID; category BIGINT; commodity BIGINT; saad BIGINT; result JSONB;
BEGIN
    SELECT id INTO STRICT a FROM assocmap_test.identities WHERE label = association_label;
    SELECT id INTO STRICT actor FROM assocmap_test.identities WHERE label = 'admin';
    SELECT primary_field_officer_id INTO STRICT officer FROM public.associations WHERE id = a;
    SELECT id INTO STRICT category FROM public.fisheries_project_categories WHERE code = 'AQUACULTURE';
    SELECT id INTO commodity FROM public.commodities WHERE category_id = category ORDER BY id LIMIT 1;
    SELECT sa.id INTO saad FROM public.saad_activities sa
    JOIN public.saad_subcomponents sub ON sub.id = sa.subcomponent_id
    JOIN public.saad_components component ON component.id = sub.component_id
    WHERE component.code = 'FPL' ORDER BY sa.id LIMIT 1;
    INSERT INTO public.projects(association_id, saad_activity_id, fisheries_category_id, commodity_id,
        title, program_year, implementation_start_date, status, created_by, updated_by)
    VALUES (a, saad, category, commodity, 'Synthetic ' || case_label, 2026, '2026-01-01', initial_status, actor, actor) RETURNING id INTO p;
    INSERT INTO public.project_products(project_id, product_name, default_unit) VALUES (p, 'Main product', 'kg') RETURNING id INTO product;
    INSERT INTO public.project_products(project_id, product_name, default_unit) VALUES (p, 'Spare product', 'kg') RETURNING id INTO product_extra;
    INSERT INTO public.project_products(project_id, product_name, default_unit) VALUES (p, 'Insert target product', 'kg') RETURNING id INTO product_insert;
    INSERT INTO public.project_materials(project_id, item_name, quantity_planned, unit) VALUES (p, 'Main material', 10, 'pieces') RETURNING id INTO material;
    INSERT INTO public.project_materials(project_id, item_name, quantity_planned, unit) VALUES (p, 'Spare material', 10, 'pieces') RETURNING id INTO material_extra;
    INSERT INTO public.members(association_id, first_name, last_name, birth_date, sex_id, position_id)
    SELECT a, 'Synthetic', case_label, '1990-01-01', s.id, pos.id FROM public.sex_categories s CROSS JOIN public.association_positions pos
    WHERE s.code = 'M' AND pos.code = 'MEMBER' RETURNING id INTO member;
    INSERT INTO public.members(association_id, first_name, last_name, birth_date, sex_id, position_id)
    SELECT a, 'Synthetic spare', case_label, '1990-01-01', s.id, pos.id FROM public.sex_categories s CROSS JOIN public.association_positions pos
    WHERE s.code = 'M' AND pos.code = 'MEMBER' RETURNING id INTO member_extra;
    INSERT INTO public.trainings(association_id, related_project_id, saad_activity_id, title, program_year, start_date, created_by, updated_by)
    VALUES (a, p, saad, 'Synthetic training', 2026, '2026-01-10', actor, actor) RETURNING id INTO training;
    INSERT INTO public.trainings(association_id, related_project_id, saad_activity_id, title, program_year, start_date)
    VALUES (a, p, saad, 'Synthetic spare training', 2026, '2026-01-11') RETURNING id INTO training_extra;
    INSERT INTO public.training_participants(training_id, member_id) VALUES (training, member) RETURNING id INTO participant;
    INSERT INTO public.program_activity_records(saad_activity_id, association_id, related_project_id, title, program_year, activity_date, status, created_by, updated_by)
    VALUES (saad, a, p, 'Synthetic activity', 2026, '2026-01-12', 'planned', actor, actor) RETURNING id INTO activity;
    INSERT INTO public.monitoring_records(project_id, year, quarter_id, monitoring_date, project_status, created_by, updated_by)
    VALUES (p, 2026, 1, '2026-01-15', 'ongoing', actor, actor) RETURNING id INTO monitoring;
    INSERT INTO public.monitoring_records(project_id, year, quarter_id, monitoring_date, project_status)
    VALUES (p, 2028, 2, '2028-04-15', 'ongoing') RETURNING id INTO monitoring_extra;
    INSERT INTO public.monitoring_production(monitoring_record_id, project_product_id, target_output, actual_output, unit)
    VALUES (monitoring, product, 100, 30, 'kg') RETURNING id INTO production;
    INSERT INTO public.monitoring_production(monitoring_record_id, project_product_id, target_output, actual_output, unit)
    VALUES (monitoring, product_extra, 100, 0, 'kg') RETURNING id INTO production_extra;
    INSERT INTO public.monitoring_production_monthly(monitoring_production_id, month, activity_count, total_quantity)
    VALUES (production, 1, 1, 10) RETURNING id INTO monthly;
    INSERT INTO public.monitoring_production_monthly(monitoring_production_id, month, activity_count, total_quantity)
    VALUES (production, 2, 1, 10), (production, 3, 1, 10);
    INSERT INTO public.monitoring_income(monitoring_production_id, period_type, gross_income)
    VALUES (production, 'quarterly', 1200) RETURNING id INTO income;
    INSERT INTO public.monitoring_materials(monitoring_record_id, project_material_id, condition)
    VALUES (monitoring, material, 'good') RETURNING id INTO monitored_material;
    INSERT INTO public.monitoring_visit_reports(monitoring_record_id, monitoring_date, monitored_by, conducted_by_user_id, created_by, updated_by)
    VALUES (monitoring, '2026-01-15', officer, officer, actor, actor) RETURNING id INTO visit;
    INSERT INTO public.gis_locations(project_id, location_type, location_name, public_title, geom, created_by, updated_by)
    VALUES (p, 'project_point', 'Synthetic location', 'Synthetic approved title',
        extensions.ST_SetSRID(extensions.ST_MakePoint(123.8,10.3),4326), actor, actor) RETURNING id INTO location;
    result := jsonb_build_object('project',p,'association',a,'saad',saad,'admin',actor,
        'product',product,'product_extra',product_extra,'product_insert',product_insert,
        'material',material,'material_extra',material_extra,'training',training,'training_extra',training_extra,
        'participant',participant,'member',member,'member_extra',member_extra,'activity',activity,
        'monitoring',monitoring,'monitoring_extra',monitoring_extra,'production',production,
        'production_extra',production_extra,'monthly',monthly,'income',income,
        'monitoring_material',monitored_material,'visit',visit,'gis',location);
    INSERT INTO assocmap_test.cases VALUES (case_label, result);
    RETURN result;
END;
$fixture$;

SELECT assocmap_test.make_case(label, initial_status, association_label) ->> 'project' AS synthetic_project
FROM (VALUES
 ('legacy_completed','completed','association'), ('completed_candidate','ongoing','association'),
 ('terminated_candidate','ongoing','association'), ('ongoing','ongoing','association'),
 ('other_ongoing','ongoing','association'), ('planned','planned','association'),
 ('suspended','suspended','association'), ('cancelled','cancelled','association'),
 ('archived_ongoing','ongoing','association'), ('inactive_parent','ongoing','inactive_association'),
 ('archived_parent','ongoing','archived_association')
) cases(label,initial_status,association_label);
UPDATE public.projects SET archived_at = now()
WHERE id = (SELECT (ids ->> 'project')::uuid FROM assocmap_test.cases WHERE label = 'archived_ongoing');

CREATE FUNCTION assocmap_test.core_snapshot() RETURNS JSONB LANGUAGE plpgsql AS $snapshot$
DECLARE item RECORD; rows JSONB; result JSONB := '{}'::jsonb; projection TEXT;
BEGIN
    FOR item IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind = 'r' ORDER BY c.relname LOOP
        projection := CASE WHEN item.relname = 'projects'
            THEN 'to_jsonb(t) - ARRAY[''closed_at'',''closed_by'',''closure_remarks'']' ELSE 'to_jsonb(t)' END;
        EXECUTE format('SELECT COALESCE(jsonb_agg(row_data ORDER BY row_data::text), ''[]''::jsonb) FROM (SELECT %s AS row_data FROM public.%I t) data',
            projection, item.relname) INTO rows;
        result := result || jsonb_build_object(item.relname, rows);
    END LOOP;
    RETURN result;
END;
$snapshot$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA assocmap_test FROM PUBLIC, anon, authenticated;
SELECT 'synthetic fixtures ready' AS result, COUNT(*) AS cases FROM assocmap_test.cases;
COMMIT;
