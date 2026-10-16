-- AssocMap Migration 014: preserve finalized project history on linked writes.
-- Prerequisites: 003, 005, 011, 013. No backfills or privilege expansion.
BEGIN;
SET LOCAL lock_timeout = '10s';

DO $preflight$
DECLARE
    table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'projects', 'project_products', 'project_materials', 'trainings', 'training_participants',
        'program_activity_records', 'monitoring_records', 'monitoring_visit_reports',
        'monitoring_production', 'monitoring_production_monthly', 'monitoring_income',
        'monitoring_materials', 'gis_locations'
    ] LOOP
        IF current_user <> (SELECT pg_get_userbyid(relowner) FROM pg_class
            WHERE oid = ('public.' || table_name)::regclass)
            OR current_user IN ('anon', 'authenticated') THEN
            RAISE EXCEPTION '014 requires the trusted owner of public.%', table_name;
        END IF;
    END LOOP;
END;
$preflight$;

-- Definer reads only: RLS must not hide ancestry from an integrity guard.
-- Helpers are private, use qualified tables, and perform no business writes.
CREATE FUNCTION public.project_history_project_ids(p_table TEXT, p_row JSONB)
RETURNS UUID[]
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp
SET row_security = off
AS $function$
DECLARE
    result UUID[] := ARRAY[]::UUID[];
    parent_row JSONB;
    project_id UUID;
BEGIN
    IF p_row IS NULL THEN RETURN result; END IF;
    CASE p_table
        WHEN 'project_products', 'project_materials', 'monitoring_records', 'gis_locations' THEN
            result := array_append(result, (p_row ->> 'project_id')::uuid);
        WHEN 'trainings', 'program_activity_records' THEN
            result := array_append(result, (p_row ->> 'related_project_id')::uuid);
        WHEN 'training_participants' THEN
            SELECT to_jsonb(t) INTO parent_row FROM public.trainings t
            WHERE t.id = (p_row ->> 'training_id')::uuid FOR SHARE;
            result := public.project_history_project_ids('trainings', parent_row);
        WHEN 'monitoring_production', 'monitoring_materials', 'monitoring_visit_reports' THEN
            SELECT to_jsonb(m) INTO parent_row FROM public.monitoring_records m
            WHERE m.id = (p_row ->> 'monitoring_record_id')::uuid FOR SHARE;
            result := public.project_history_project_ids('monitoring_records', parent_row);
            IF p_table = 'monitoring_production' THEN
                SELECT p.project_id INTO project_id FROM public.project_products p
                WHERE p.id = (p_row ->> 'project_product_id')::uuid FOR SHARE;
                result := array_append(result, project_id);
            ELSIF p_table = 'monitoring_materials' THEN
                SELECT p.project_id INTO project_id FROM public.project_materials p
                WHERE p.id = (p_row ->> 'project_material_id')::uuid FOR SHARE;
                result := array_append(result, project_id);
            END IF;
        WHEN 'monitoring_production_monthly', 'monitoring_income' THEN
            SELECT to_jsonb(p) INTO parent_row FROM public.monitoring_production p
            WHERE p.id = (p_row ->> 'monitoring_production_id')::uuid FOR SHARE;
            result := public.project_history_project_ids('monitoring_production', parent_row);
        ELSE
            RAISE EXCEPTION 'Unsupported project-history table.' USING ERRCODE = '23514';
    END CASE;
    RETURN array_remove(result, NULL);
END;
$function$;

CREATE FUNCTION public.protect_finalized_project_history()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp
SET row_security = off
AS $function$
DECLARE
    old_row JSONB;
    new_row JSONB;
    project_ids UUID[] := ARRAY[]::UUID[];
    project_row RECORD;
    finalized BOOLEAN := FALSE;
    actor_column TEXT;
    old_actor UUID;
    new_actor UUID;
    cleanup BOOLEAN := FALSE;
    actor_columns TEXT[] := ARRAY['created_by', 'updated_by', 'archived_by',
        'monitored_by', 'conducted_by_user_id', 'published_by'];
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_row := to_jsonb(OLD);
        project_ids := project_ids || public.project_history_project_ids(TG_TABLE_NAME, old_row);
    END IF;
    IF TG_OP <> 'DELETE' THEN
        new_row := to_jsonb(NEW);
        project_ids := project_ids || public.project_history_project_ids(TG_TABLE_NAME, new_row);
    END IF;
    -- Unlinked association-wide activity/training writes have no project invariant.
    IF cardinality(project_ids) = 0 THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;
    IF current_setting('transaction_isolation') <> 'read committed' THEN
        RAISE EXCEPTION 'Project-linked history writes require READ COMMITTED isolation.' USING ERRCODE = '25000';
    END IF;
    FOR project_row IN
        SELECT id, status, closed_at FROM public.projects
        WHERE id = ANY(project_ids) ORDER BY id FOR SHARE
    LOOP
        finalized := finalized OR project_row.status IN ('completed', 'terminated') OR project_row.closed_at IS NOT NULL;
    END LOOP;
    IF finalized THEN
        -- GIS is geographic maintenance, not a new operational transaction.
        IF TG_TABLE_NAME = 'gis_locations' THEN
            IF TG_OP = 'INSERT' THEN RETURN NEW; END IF;
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'A finalized project GIS location cannot be deleted.' USING ERRCODE = '23514';
            END IF;
            -- Actual GIS maintenance columns; all other identity/provenance stays fixed.
            IF (new_row - ARRAY['location_type', 'location_name', 'address', 'geom',
                'public_title', 'public_description', 'public_address', 'is_published',
                'published_by', 'published_at', 'updated_by', 'updated_at', 'archived_at', 'archived_by', 'created_by'])
                IS DISTINCT FROM
               (old_row - ARRAY['location_type', 'location_name', 'address', 'geom',
                'public_title', 'public_description', 'public_address', 'is_published',
                'published_by', 'published_at', 'updated_by', 'updated_at', 'archived_at', 'archived_by', 'created_by']) THEN
                RAISE EXCEPTION 'Finalized GIS identity and project ownership cannot change.' USING ERRCODE = '23514';
            END IF;
            IF new_row ->> 'created_by' IS DISTINCT FROM old_row ->> 'created_by' AND (
                new_row ->> 'created_by' IS NOT NULL OR EXISTS (
                    SELECT 1 FROM public.user_profiles WHERE id = (old_row ->> 'created_by')::uuid
                )
            ) THEN
                RAISE EXCEPTION 'Finalized GIS creation provenance cannot change.' USING ERRCODE = '23514';
            END IF;
            RETURN NEW;
        END IF;
        -- Permit only existing user-profile ON DELETE SET NULL provenance cleanup.
        IF TG_OP = 'UPDATE' AND
            (new_row - actor_columns - 'updated_at') IS NOT DISTINCT FROM
            (old_row - actor_columns - 'updated_at') THEN
            FOREACH actor_column IN ARRAY actor_columns LOOP
                old_actor := (old_row ->> actor_column)::uuid;
                new_actor := (new_row ->> actor_column)::uuid;
                IF new_actor IS DISTINCT FROM old_actor THEN
                    IF new_actor IS NOT NULL OR EXISTS (SELECT 1 FROM public.user_profiles WHERE id = old_actor) THEN
                        RAISE EXCEPTION 'Finalized project provenance is read-only.' USING ERRCODE = '23514';
                    END IF;
                    cleanup := TRUE;
                END IF;
            END LOOP;
            IF cleanup THEN RETURN NEW; END IF;
        END IF;
        RAISE EXCEPTION 'Completed or terminated project history is permanently read-only.' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$function$;

REVOKE ALL ON FUNCTION public.project_history_project_ids(TEXT, JSONB) FROM PUBLIC, anon, authenticated;
REVOKE ALL ON FUNCTION public.protect_finalized_project_history() FROM PUBLIC, anon, authenticated;

DO $triggers$
DECLARE
    table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'project_products', 'project_materials', 'trainings', 'training_participants',
        'program_activity_records', 'monitoring_records', 'monitoring_visit_reports',
        'monitoring_production', 'monitoring_production_monthly', 'monitoring_income',
        'monitoring_materials', 'gis_locations'
    ] LOOP
        EXECUTE format('CREATE TRIGGER trg_protect_finalized_project_history
            AFTER INSERT OR UPDATE OR DELETE ON public.%I
            FOR EACH ROW EXECUTE FUNCTION public.protect_finalized_project_history()', table_name);
    END LOOP;
END;
$triggers$;

COMMIT;
