-- AssocMap Migration 011: irreversible project completion with closure evidence.
-- Forward-only source. Legacy completed projects retain null closure metadata.

BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE public.projects
    ADD COLUMN closed_at TIMESTAMPTZ,
    ADD COLUMN closure_remarks TEXT,
    ADD COLUMN closed_by UUID REFERENCES public.user_profiles(id) ON DELETE SET NULL,
    ADD CONSTRAINT ck_projects_closure_metadata CHECK (
        (closed_at IS NULL AND closure_remarks IS NULL AND closed_by IS NULL)
        OR (
            status = 'completed' AND closed_at IS NOT NULL
            AND closure_remarks IS NOT NULL AND char_length(closure_remarks) <= 2000
            -- POSIX control/space plus explicit Unicode format, filler and private-use ranges.
            AND regexp_replace(closure_remarks, U&'[[:space:][:cntrl:]\0080-\009F\00AD\034F\0600-\0605\061C\06DD\070F\0890\0891\08E2\115F\1160\1680\17B4\17B5\180B-\180F\2000-\200F\2028-\202F\205F-\206F\2800\3000\3164\E000-\F8FF\FE00-\FE0F\FEFF\FFA0\FFF9-\FFFB\+0110BD\+0110CD\+013430-\+013455\+01BCA0-\+01BCA3\+01D173-\+01D17A\+0E0000-\+0E0FFF\+0F0000-\+0FFFFD\+100000-\+10FFFD]', '', 'g') ~ '[[:graph:]]'
        )
    );

CREATE FUNCTION public.protect_project_completion()
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
        IF NEW.status = 'completed' THEN
            RAISE EXCEPTION 'Create an operational project before using formal closure.' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF OLD.status = 'completed' OR OLD.closed_at IS NOT NULL THEN
            RAISE EXCEPTION 'Completed projects cannot be deleted.' USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'completed' OR OLD.closed_at IS NOT NULL THEN
        IF (to_jsonb(NEW) - ARRAY['created_by', 'updated_by', 'archived_by', 'closed_by', 'commodity_id', 'updated_at'])
            IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['created_by', 'updated_by', 'archived_by', 'closed_by', 'commodity_id', 'updated_at']) THEN
            RAISE EXCEPTION 'Completed projects are permanently read-only.' USING ERRCODE = '23514';
        END IF;
        -- Retain existing actor FK cleanup; do not permit ordinary provenance edits.
        FOREACH actor_column IN ARRAY ARRAY['created_by', 'updated_by', 'archived_by', 'closed_by'] LOOP
            old_actor := (to_jsonb(OLD) ->> actor_column)::uuid;
            new_actor := (to_jsonb(NEW) ->> actor_column)::uuid;
            IF new_actor IS DISTINCT FROM old_actor AND (new_actor IS NOT NULL OR EXISTS (
                SELECT 1 FROM public.user_profiles WHERE id = old_actor
            )) THEN
                RAISE EXCEPTION 'Closure provenance cannot be edited.' USING ERRCODE = '23514';
            END IF;
        END LOOP;
        IF NEW.commodity_id IS DISTINCT FROM OLD.commodity_id AND (
            NEW.commodity_id IS NOT NULL OR EXISTS (
                SELECT 1 FROM public.commodities WHERE id = OLD.commodity_id
            )
        ) THEN
            RAISE EXCEPTION 'Completed project commodity cannot be changed.' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.status = 'completed' THEN
        IF OLD.status <> 'ongoing' OR OLD.archived_at IS NOT NULL
            OR NEW.archived_at IS NOT NULL OR NEW.closed_by IS NULL THEN
            RAISE EXCEPTION 'Only an ongoing, unarchived project can be formally closed by an actor.' USING ERRCODE = '23514';
        END IF;
        IF (to_jsonb(NEW) - ARRAY['status', 'closed_at', 'closed_by', 'closure_remarks', 'updated_by', 'updated_at'])
            IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['status', 'closed_at', 'closed_by', 'closure_remarks', 'updated_by', 'updated_at']) THEN
            RAISE EXCEPTION 'Closure cannot also change the project profile or association.' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$function$;

REVOKE ALL ON FUNCTION public.protect_project_completion() FROM PUBLIC, anon, authenticated;

CREATE TRIGGER trg_protect_project_completion
AFTER INSERT OR UPDATE OR DELETE ON public.projects
FOR EACH ROW EXECUTE FUNCTION public.protect_project_completion();

COMMIT;
