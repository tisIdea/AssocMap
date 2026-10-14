
-- ORIGINAL ASSOCMAP
-- 004: Backfill historical Monitoring Overview reports.
-- Execute manually only after migration 003 is confirmed.

BEGIN;

-- Prevent RLS from silently filtering historical source records.
-- This requires a database role permitted to bypass RLS.
SET LOCAL row_security = off;

-- Verify required tables exist.
DO $$
BEGIN
    IF to_regclass('public.monitoring_records') IS NULL
       OR to_regclass('public.monitoring_visit_reports') IS NULL THEN
        RAISE EXCEPTION
            'Required Monitoring tables are missing. Apply migration 003 first.';
    END IF;
END;
$$;

-- Stabilize source records and serialize imports.
LOCK TABLE public.monitoring_records IN SHARE MODE;
LOCK TABLE public.monitoring_visit_reports IN SHARE ROW EXCLUSIVE MODE;

-- Verify required target constraints.
DO $$
DECLARE
    v_target oid := 'public.monitoring_visit_reports'::regclass;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = v_target
          AND contype = 'p'
    ) THEN
        RAISE EXCEPTION 'Missing Monitoring Visit Reports primary key.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = v_target
          AND contype = 'f'
          AND confrelid = 'public.monitoring_records'::regclass
          AND confdeltype = 'c'
          AND convalidated
    ) THEN
        RAISE EXCEPTION
            'Missing validated cascading quarterly Monitoring foreign key.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_index
        WHERE indexrelid = to_regclass(
            'public.uq_monitoring_visit_reports_legacy_record'
        )
          AND indisunique
          AND indisvalid
          AND indisready
          AND indpred IS NOT NULL
    ) THEN
        RAISE EXCEPTION
            'Missing valid partial unique index for historical imports.';
    END IF;
END;
$$;

-- Abort if any existing historical report conflicts
-- with the original quarterly Overview values.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.monitoring_records AS source
        JOIN public.monitoring_visit_reports AS report
          ON report.monitoring_record_id = source.id
         AND report.is_legacy_import = TRUE
        WHERE ROW(
            report.monitoring_date,
            report.monitored_by,
            report.site_validated,
            report.issues_encountered,
            report.actions_taken,
            report.overall_remarks,
            report.created_by,
            report.updated_by,
            report.created_at,
            report.updated_at
        ) IS DISTINCT FROM ROW(
            source.monitoring_date,
            source.monitored_by,
            source.site_validated,
            source.issues_encountered,
            source.actions_taken,
            source.overall_remarks,
            source.created_by,
            source.updated_by,
            source.created_at,
            source.updated_at
        )
    ) THEN
        RAISE EXCEPTION
            'Existing historical Monitoring reports differ from their source records. No changes applied.';
    END IF;
END;
$$;

-- Copy Overview information without modifying
-- existing quarterly Monitoring Records.
INSERT INTO public.monitoring_visit_reports (
    monitoring_record_id,
    monitoring_date,
    monitored_by,
    site_validated,
    issues_encountered,
    actions_taken,
    overall_remarks,
    created_by,
    updated_by,
    is_legacy_import,
    created_at,
    updated_at
)
SELECT
    source.id,
    source.monitoring_date,
    source.monitored_by,
    source.site_validated,
    source.issues_encountered,
    source.actions_taken,
    source.overall_remarks,
    source.created_by,
    source.updated_by,
    TRUE,
    source.created_at,
    source.updated_at
FROM public.monitoring_records AS source
ON CONFLICT (monitoring_record_id)
WHERE is_legacy_import = TRUE
DO NOTHING;

-- Verify every quarterly record has exactly one
-- correctly preserved historical report.
DO $$
DECLARE
    v_source_count bigint;
    v_imported_count bigint;
BEGIN
    SELECT COUNT(*)
    INTO v_source_count
    FROM public.monitoring_records;

    SELECT COUNT(*)
    INTO v_imported_count
    FROM public.monitoring_visit_reports
    WHERE is_legacy_import = TRUE;

    IF v_source_count <> v_imported_count THEN
        RAISE EXCEPTION
            'Historical import count mismatch: expected %, found %.',
            v_source_count,
            v_imported_count;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.monitoring_records AS source
        LEFT JOIN public.monitoring_visit_reports AS report
          ON report.monitoring_record_id = source.id
         AND report.is_legacy_import = TRUE
        WHERE report.id IS NULL
           OR ROW(
                report.monitoring_date,
                report.monitored_by,
                report.site_validated,
                report.issues_encountered,
                report.actions_taken,
                report.overall_remarks,
                report.created_by,
                report.updated_by,
                report.created_at,
                report.updated_at
           ) IS DISTINCT FROM ROW(
                source.monitoring_date,
                source.monitored_by,
                source.site_validated,
                source.issues_encountered,
                source.actions_taken,
                source.overall_remarks,
                source.created_by,
                source.updated_by,
                source.created_at,
                source.updated_at
           )
    ) THEN
        RAISE EXCEPTION
            'Historical Monitoring report verification failed.';
    END IF;

    RAISE NOTICE
        'Historical monitoring backfill verified: % records.',
        v_imported_count;
END;
$$;

COMMIT;
