-- HOLD FOR BATCH 2. Intentionally outside migrations/ so sequential runners omit it.
-- Requires 017, verified module compatibility, stopped legacy writers and explicit approval.
-- In that approved session: SET assocmap.geography_cutover_approved = 'batch2';
BEGIN;
SET LOCAL lock_timeout = '10s';

DO $$
BEGIN
    IF current_setting('assocmap.geography_cutover_approved', true) IS DISTINCT FROM 'batch2' THEN
        RAISE EXCEPTION 'Association geography cutover requires Batch 2 approval.';
    END IF;
END;
$$;

LOCK TABLE public.associations IN ACCESS EXCLUSIVE MODE;
LOCK TABLE public.areas, public.barangays IN SHARE MODE;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid='public.associations'::regclass AND attname='area_id' AND attnotnull AND NOT attisdropped)
       OR NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid='public.associations'::regclass AND attname='barangay_id' AND NOT attnotnull AND NOT attisdropped)
       OR NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid='public.areas'::regclass AND attname='address' AND attnotnull AND NOT attisdropped)
       OR NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='public.associations'::regclass AND conname='fk_associations_barangay' AND convalidated)
       OR NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='public.associations'::regclass AND conname='associations_area_id_fkey' AND contype='f') THEN
        RAISE EXCEPTION 'Unsupported or already-cut-over geography schema; inspect metadata before migration.';
    END IF;
END;
$$;

CREATE TEMP TABLE association_geography_before ON COMMIT DROP AS
SELECT id, to_jsonb(a) - 'barangay_id' - 'updated_at' AS preserved
FROM public.associations a;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.associations a
        LEFT JOIN public.areas ar ON ar.id = a.area_id
        LEFT JOIN public.barangays b ON b.id = ar.barangay_id
        WHERE ar.id IS NULL OR b.id IS NULL
    ) THEN
        RAISE EXCEPTION 'Unresolved Association Area/Barangay mapping; no backfill performed.';
    END IF;
    IF EXISTS (
        SELECT 1 FROM public.associations a JOIN public.areas ar ON ar.id = a.area_id
        WHERE a.barangay_id IS NOT NULL AND a.barangay_id <> ar.barangay_id
    ) THEN
        RAISE EXCEPTION 'Conflicting populated Association Barangay; resolve explicitly before cutover.';
    END IF;
END;
$$;

-- Include active, inactive and archived records. Never copy areas.address.
UPDATE public.associations a SET barangay_id = ar.barangay_id
FROM public.areas ar WHERE ar.id = a.area_id AND a.barangay_id IS NULL;

DO $$
BEGIN
    IF (SELECT count(*) FROM association_geography_before) <> (SELECT count(*) FROM public.associations)
        OR EXISTS (
            SELECT id, preserved FROM association_geography_before
            EXCEPT SELECT id, to_jsonb(a) - 'barangay_id' - 'updated_at' FROM public.associations a
        ) OR EXISTS (
            SELECT 1 FROM public.associations a LEFT JOIN public.areas ar ON ar.id = a.area_id
            LEFT JOIN public.barangays b ON b.id = a.barangay_id
            WHERE b.id IS NULL OR ar.id IS NULL OR a.barangay_id IS DISTINCT FROM ar.barangay_id
        ) THEN
        RAISE EXCEPTION 'Association geography verification failed; transaction rolled back.';
    END IF;
END;
$$;

-- PostgreSQL requires a unique referenced pair even though id alone is already unique.
ALTER TABLE public.areas ADD CONSTRAINT uq_areas_id_barangay UNIQUE (id, barangay_id);
ALTER TABLE public.associations
    ALTER COLUMN barangay_id SET NOT NULL,
    ADD CONSTRAINT fk_associations_area_barangay FOREIGN KEY (area_id, barangay_id)
        REFERENCES public.areas(id, barangay_id) MATCH SIMPLE
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ALTER COLUMN area_id DROP NOT NULL,
    DROP CONSTRAINT associations_area_id_fkey;

-- Replaces the Area-only index; its left prefix still supports Area lookups.
CREATE INDEX idx_associations_area_barangay ON public.associations(area_id, barangay_id);
DROP INDEX public.idx_associations_area_id;

COMMIT;
