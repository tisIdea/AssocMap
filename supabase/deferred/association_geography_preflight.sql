-- Read-only evidence for developer review. Run only in an explicitly authorized environment.
SELECT table_name, column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema='public' AND (
    (table_name='associations' AND column_name IN ('id','area_id','barangay_id','specific_address'))
    OR (table_name='areas' AND column_name IN ('id','barangay_id','address'))
) ORDER BY table_name,column_name;

SELECT conrelid::regclass AS relation, conname, convalidated, pg_get_constraintdef(oid) AS definition
FROM pg_constraint WHERE conrelid IN ('public.associations'::regclass,'public.areas'::regclass)
ORDER BY relation,conname;

SELECT tablename,indexname,indexdef FROM pg_indexes
WHERE schemaname='public' AND tablename IN ('associations','areas') ORDER BY tablename,indexname;

SELECT count(*) AS associations, count(*) FILTER (WHERE archived_at IS NOT NULL) AS archived,
    count(*) FILTER (WHERE status='inactive') AS inactive FROM public.associations;

-- Execute the following only after confirming 017 columns exist.
SELECT count(*) FILTER (WHERE ar.id IS NULL) AS unresolved_areas,
    count(*) FILTER (WHERE b.id IS NULL) AS unresolved_area_barangays,
    count(*) FILTER (WHERE a.barangay_id IS NOT NULL AND a.barangay_id IS DISTINCT FROM ar.barangay_id) AS conflicts,
    count(*) FILTER (WHERE a.barangay_id IS NULL) AS pending_backfill
FROM public.associations a LEFT JOIN public.areas ar ON ar.id=a.area_id
LEFT JOIN public.barangays b ON b.id=ar.barangay_id;
