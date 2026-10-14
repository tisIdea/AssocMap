-- AssocMap Migration 008: at most one active President and Vice President per association.
-- Forward-only. Apply manually only after schema review and isolated PostgreSQL testing.
-- Vacancies are valid. No member data is changed.
-- Executive lookup IDs/codes must remain stable after installation; changing them requires a new migration.

-- Read-only preflight: expected lookup codes, IDs and officer flags.
-- SELECT e.code AS expected_code, p.id, p.code AS actual_code, p.name, p.is_officer, p.is_active
-- FROM (VALUES ('PRESIDENT'), ('VICE_PRESIDENT')) AS e(code)
-- LEFT JOIN public.association_positions p ON UPPER(BTRIM(p.code)) = e.code
-- ORDER BY e.code, p.id;

-- Read-only preflight: duplicates that would prevent index installation.
-- SELECT m.association_id, p.code, m.position_id, COUNT(*) AS active_holders, ARRAY_AGG(m.id ORDER BY m.id) AS member_ids
-- FROM public.members m JOIN public.association_positions p ON p.id = m.position_id
-- WHERE p.code IN ('PRESIDENT', 'VICE_PRESIDENT') AND m.member_status = 'active' AND m.archived_at IS NULL
-- GROUP BY m.association_id, p.code, m.position_id HAVING COUNT(*) > 1
-- ORDER BY m.association_id, p.code;

BEGIN;

SET LOCAL lock_timeout = '10s';

-- Keep lookup resolution and duplicate inspection stable until the index and trigger changes commit.
LOCK TABLE public.association_positions IN SHARE MODE;
LOCK TABLE public.members IN SHARE ROW EXCLUSIVE MODE;

DO $migration$
DECLARE
    president_id BIGINT;
    vice_president_id BIGINT;
BEGIN
    IF (SELECT COUNT(*) FROM public.association_positions
        WHERE UPPER(BTRIM(code)) IN ('PRESIDENT', 'VICE_PRESIDENT')) <> 2
       OR (SELECT COUNT(*) FROM public.association_positions WHERE code = 'PRESIDENT') <> 1
       OR (SELECT COUNT(*) FROM public.association_positions WHERE code = 'VICE_PRESIDENT') <> 1
       OR EXISTS (SELECT 1 FROM public.association_positions
                  WHERE code IN ('PRESIDENT', 'VICE_PRESIDENT') AND is_officer IS DISTINCT FROM TRUE) THEN
        RAISE EXCEPTION 'Executive lookup codes are missing or inconsistent. Review association_positions before applying migration 008.';
    END IF;

    SELECT id INTO STRICT president_id FROM public.association_positions WHERE code = 'PRESIDENT';
    SELECT id INTO STRICT vice_president_id FROM public.association_positions WHERE code = 'VICE_PRESIDENT';

    IF president_id = vice_president_id THEN
        RAISE EXCEPTION 'President and Vice President must reference different position IDs.';
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.members
        WHERE member_status = 'active' AND archived_at IS NULL
          AND position_id IN (president_id, vice_president_id)
        GROUP BY association_id, position_id HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Conflicting active executive members exist. Organizational review is required; no members were changed.';
    END IF;

    -- Do not silently accept an existing index with an unknown definition.
    EXECUTE format(
        'CREATE UNIQUE INDEX uq_members_active_executive_position ON public.members (association_id, position_id)
         WHERE member_status = %L AND archived_at IS NULL AND position_id IN (%L::bigint, %L::bigint)',
        'active', president_id, vice_president_id
    );

    -- The unique index is installed before retiring the check-only President trigger.
    DROP TRIGGER IF EXISTS trg_validate_one_active_president ON public.members;
    DROP FUNCTION IF EXISTS public.validate_one_active_president();
END;
$migration$;

COMMIT;
