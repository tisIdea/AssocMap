-- AssocMap Migration 013: distinct irreversible project finalization outcomes.
-- Prerequisite: corrected pending 011. 010 is a withdrawn, comment-only placeholder.
-- Reuses closure evidence; status distinguishes completion from termination.
BEGIN;
SET LOCAL lock_timeout = '10s';

ALTER TABLE public.projects
    DROP CONSTRAINT projects_status_check,
    ADD CONSTRAINT projects_status_check CHECK (
        status IN ('planned', 'ongoing', 'completed', 'suspended', 'cancelled', 'terminated')
    ),
    DROP CONSTRAINT ck_projects_closure_metadata,
    ADD CONSTRAINT ck_projects_closure_metadata CHECK (
        (status <> 'terminated' AND closed_at IS NULL AND closure_remarks IS NULL AND closed_by IS NULL)
        OR (
            status IN ('completed', 'terminated') AND closed_at IS NOT NULL
            AND closure_remarks IS NOT NULL AND char_length(closure_remarks) <= 2000
            -- POSIX control/space plus explicit Unicode format, filler and private-use ranges.
            AND regexp_replace(closure_remarks, U&'[[:space:][:cntrl:]\0080-\009F\00AD\034F\0600-\0605\061C\06DD\070F\0890\0891\08E2\115F\1160\1680\17B4\17B5\180B-\180F\2000-\200F\2028-\202F\205F-\206F\2800\3000\3164\E000-\F8FF\FE00-\FE0F\FEFF\FFA0\FFF9-\FFFB\+0110BD\+0110CD\+013430-\+013455\+01BCA0-\+01BCA3\+01D173-\+01D17A\+0E0000-\+0E0FFF\+0F0000-\+0FFFFD\+100000-\+10FFFD]', '', 'g') ~ '[[:graph:]]'
        )
    );

CREATE OR REPLACE FUNCTION public.protect_project_completion()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = pg_catalog, public, pg_temp
SET row_security = off
AS $function$
DECLARE
    actor_column TEXT;
    old_actor UUID;
    new_actor UUID;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status IN ('completed', 'terminated') THEN
            RAISE EXCEPTION 'Create an operational project before using formal finalization.' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF OLD.status IN ('completed', 'terminated') OR OLD.closed_at IS NOT NULL THEN
            RAISE EXCEPTION 'Finalized projects cannot be deleted.' USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status IN ('completed', 'terminated') OR OLD.closed_at IS NOT NULL THEN
        IF (to_jsonb(NEW) - ARRAY['created_by', 'updated_by', 'archived_by', 'closed_by', 'commodity_id', 'updated_at'])
            IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['created_by', 'updated_by', 'archived_by', 'closed_by', 'commodity_id', 'updated_at']) THEN
            RAISE EXCEPTION 'Finalized projects are permanently read-only.' USING ERRCODE = '23514';
        END IF;
        -- Retain existing actor FK cleanup; do not permit ordinary provenance edits.
        FOREACH actor_column IN ARRAY ARRAY['created_by', 'updated_by', 'archived_by', 'closed_by'] LOOP
            old_actor := (to_jsonb(OLD) ->> actor_column)::uuid;
            new_actor := (to_jsonb(NEW) ->> actor_column)::uuid;
            IF new_actor IS DISTINCT FROM old_actor AND (new_actor IS NOT NULL OR EXISTS (
                SELECT 1 FROM public.user_profiles WHERE id = old_actor
            )) THEN
                RAISE EXCEPTION 'Finalization provenance cannot be edited.' USING ERRCODE = '23514';
            END IF;
        END LOOP;
        IF NEW.commodity_id IS DISTINCT FROM OLD.commodity_id AND (
            NEW.commodity_id IS NOT NULL OR EXISTS (
                SELECT 1 FROM public.commodities WHERE id = OLD.commodity_id
            )
        ) THEN
            RAISE EXCEPTION 'Finalized project commodity cannot be changed.' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.status IN ('completed', 'terminated') THEN
        IF OLD.status <> 'ongoing' OR OLD.archived_at IS NOT NULL
            OR NEW.archived_at IS NOT NULL OR NEW.closed_by IS NULL THEN
            RAISE EXCEPTION 'Only an ongoing, unarchived project can be formally finalized by an actor.' USING ERRCODE = '23514';
        END IF;
        PERFORM 1 FROM public.associations
        WHERE id = OLD.association_id AND status = 'active' AND archived_at IS NULL
        FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Finalization requires an operational association.' USING ERRCODE = '23514';
        END IF;
        IF NOT (public.user_has_role(NEW.closed_by, 'system_admin') OR (
            public.user_has_role(NEW.closed_by, 'field_officer') AND EXISTS (
                SELECT 1 FROM public.associations
                WHERE id = OLD.association_id AND primary_field_officer_id = NEW.closed_by
            )
        )) THEN
            RAISE EXCEPTION 'Finalization requires an active authorized actor.' USING ERRCODE = '23514';
        END IF;
        IF (to_jsonb(NEW) - ARRAY['status', 'closed_at', 'closed_by', 'closure_remarks', 'updated_by', 'updated_at'])
            IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['status', 'closed_at', 'closed_by', 'closure_remarks', 'updated_by', 'updated_at']) THEN
            RAISE EXCEPTION 'Finalization cannot also change the project profile or association.' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$function$;

REVOKE ALL ON FUNCTION public.protect_project_completion() FROM PUBLIC, anon, authenticated;

-- The existing trg_protect_project_completion now calls this amended function.
-- No data updates, new columns, RLS changes, or grants are needed.
COMMIT;
