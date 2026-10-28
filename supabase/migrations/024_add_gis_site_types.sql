SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

CREATE TABLE public.gis_site_types (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$'),
    name VARCHAR(100) NOT NULL CHECK (TRIM(name) <> '')
);

-- Catalog labels require business approval; no candidate taxonomy is seeded here.
ALTER TABLE public.gis_locations
    ADD COLUMN site_type_id BIGINT REFERENCES public.gis_site_types(id) ON DELETE RESTRICT;

CREATE INDEX idx_gis_locations_site_type ON public.gis_locations(site_type_id);

-- POSTGRES SECURITY
ALTER TABLE public.gis_site_types ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.gis_site_types FROM PUBLIC, anon, authenticated;
-- Catalog reads and location assignments go through the existing Laravel boundary.


-- Extend only the GIS maintenance whitelist from migration 014.
DO $preflight$
BEGIN
    IF to_regprocedure('public.protect_finalized_project_history()') IS NULL
        OR to_regprocedure('public.project_history_project_ids(text,jsonb)') IS NULL THEN
        RAISE EXCEPTION '024 requires the finalized-project history safeguards from migration 014.';
    END IF;
    IF current_user IS DISTINCT FROM (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='public.gis_locations'::regclass)
        OR current_user IS DISTINCT FROM (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='public.projects'::regclass)
        OR current_user IS DISTINCT FROM (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid='public.protect_finalized_project_history()'::regprocedure) THEN
        RAISE EXCEPTION '024 requires the trusted GIS/project/history function owner.';
    END IF;
END;
$preflight$;

CREATE OR REPLACE FUNCTION public.protect_finalized_project_history()
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
            IF (new_row - ARRAY['location_type', 'site_type_id', 'location_name', 'address', 'geom',
                'public_title', 'public_description', 'public_address', 'is_published',
                'published_by', 'published_at', 'updated_by', 'updated_at', 'archived_at', 'archived_by', 'created_by'])
                IS DISTINCT FROM
               (old_row - ARRAY['location_type', 'site_type_id', 'location_name', 'address', 'geom',
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
