
-- Original AssocMap
-- Migration 005: Monthly Production and Monitoring Conductors
-- Prerequisites: Supabase migrations 001-004.
-- Execute once, after backup and review.
-- Monthly integrity functions require READ COMMITTED isolation.

BEGIN;

-- ============================================================
-- 1. Verify prerequisites and table ownership.
-- ============================================================

DO $$
DECLARE
    v_table_name TEXT;
    v_owner TEXT;
BEGIN
    FOREACH v_table_name IN ARRAY ARRAY[
        'monitoring_records',
        'monitoring_production',
        'monitoring_visit_reports',
        'quarters',
        'user_profiles'
    ]
    LOOP
        IF to_regclass('public.' || v_table_name) IS NULL THEN
            RAISE EXCEPTION
                'Migration 005 prerequisite missing: public.%',
                v_table_name;
        END IF;
    END LOOP;

    IF to_regprocedure('public.set_updated_at()') IS NULL THEN
        RAISE EXCEPTION
            'Migration 005 prerequisite missing: public.set_updated_at()';
    END IF;

    FOREACH v_table_name IN ARRAY ARRAY[
        'monitoring_records',
        'monitoring_production',
        'monitoring_visit_reports',
        'quarters'
    ]
    LOOP
        SELECT pg_get_userbyid(c.relowner)
        INTO v_owner
        FROM pg_class c
        WHERE c.oid = to_regclass(
            'public.' || v_table_name
        );

        IF current_user <> v_owner THEN
            RAISE EXCEPTION
                'Migration 005 requires ownership of public.% (owner: %)',
                v_table_name, v_owner;
        END IF;
    END LOOP;

    IF current_setting('transaction_isolation')
        <> 'read committed' THEN
        RAISE EXCEPTION
            'Migration 005 requires READ COMMITTED isolation';
    END IF;

    IF to_regclass(
        'public.monitoring_production_monthly'
    ) IS NOT NULL THEN
        RAISE EXCEPTION
            'Migration 005: monthly Production table already exists';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'monitoring_visit_reports'
          AND column_name IN (
              'conducted_by_user_id',
              'conducted_by'
          )
    ) THEN
        RAISE EXCEPTION
            'Migration 005: conductor columns already exist';
    END IF;
END;
$$;


-- ============================================================
-- 2. Create monthly Production details.
-- ============================================================

CREATE TABLE public.monitoring_production_monthly (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    monitoring_production_id UUID NOT NULL,

    month SMALLINT NOT NULL,

    activity_count INTEGER NOT NULL,

    total_quantity NUMERIC(14,2) NOT NULL,

    remarks TEXT,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT fk_monitoring_production_monthly_parent
        FOREIGN KEY (monitoring_production_id)
        REFERENCES public.monitoring_production(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_monitoring_production_month
        CHECK (month BETWEEN 1 AND 12),

    CONSTRAINT chk_monitoring_production_activity_count
        CHECK (activity_count > 0),

    CONSTRAINT chk_monitoring_production_total_quantity
        CHECK (total_quantity >= 0),

    CONSTRAINT uq_monitoring_production_monthly_parent_month
        UNIQUE (monitoring_production_id, month)
);


-- ============================================================
-- 3. Maintain updated_at.
-- ============================================================

CREATE TRIGGER trg_monitoring_production_monthly_updated_at
BEFORE UPDATE
ON public.monitoring_production_monthly
FOR EACH ROW
EXECUTE FUNCTION public.set_updated_at();


-- ============================================================
-- 4. Validate the Production month.
-- Lock order: Monitoring Record -> Production -> Quarter.
-- ============================================================

CREATE FUNCTION public.validate_monitoring_production_monthly_period()
RETURNS TRIGGER
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
DECLARE
    v_record_id UUID;
    v_confirmed_record_id UUID;
    v_quarter_id SMALLINT;
    v_quarter_number SMALLINT;
    v_first_month INTEGER;
    v_last_month INTEGER;
BEGIN
    IF current_setting('transaction_isolation')
        <> 'read committed' THEN
        RAISE EXCEPTION
            'Monthly Production validation requires READ COMMITTED isolation';
    END IF;

    -- Discover the quarterly parent before locking.
    SELECT mp.monitoring_record_id
    INTO v_record_id
    FROM public.monitoring_production mp
    WHERE mp.id = NEW.monitoring_production_id;

    IF v_record_id IS NULL THEN
        RAISE EXCEPTION
            'Monthly Production parent does not exist';
    END IF;

    -- Lock the quarterly Monitoring Record first.
    SELECT mr.quarter_id
    INTO v_quarter_id
    FROM public.monitoring_records mr
    WHERE mr.id = v_record_id
    FOR SHARE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Quarterly Monitoring Record does not exist';
    END IF;

    -- Lock Production second and verify its parent.
    SELECT mp.monitoring_record_id
    INTO v_confirmed_record_id
    FROM public.monitoring_production mp
    WHERE mp.id = NEW.monitoring_production_id
    FOR SHARE;

    IF NOT FOUND
       OR v_confirmed_record_id IS DISTINCT FROM v_record_id
    THEN
        RAISE EXCEPTION
            'Production parent changed during monthly validation; retry the operation';
    END IF;

    -- Lock the quarter reference third.
    SELECT q.quarter_number
    INTO v_quarter_number
    FROM public.quarters q
    WHERE q.id = v_quarter_id
    FOR SHARE;

    IF v_quarter_number IS NULL
       OR v_quarter_number NOT BETWEEN 1 AND 4 THEN
        RAISE EXCEPTION
            'Invalid quarterly reporting period';
    END IF;

    v_first_month := ((v_quarter_number - 1) * 3) + 1;
    v_last_month := v_quarter_number * 3;

    IF NEW.month NOT BETWEEN v_first_month AND v_last_month THEN
        RAISE EXCEPTION
            'Production month % is outside quarter % (months % to %)',
            NEW.month,
            v_quarter_number,
            v_first_month,
            v_last_month;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_validate_monitoring_production_monthly_period
BEFORE INSERT OR UPDATE OF monitoring_production_id, month
ON public.monitoring_production_monthly
FOR EACH ROW
EXECUTE FUNCTION public.validate_monitoring_production_monthly_period();


-- ============================================================
-- 5. Protect Production product, unit and quarterly parent.
-- ============================================================

CREATE FUNCTION public.guard_monitoring_production_monthly_identity()
RETURNS TRIGGER
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
BEGIN
    IF current_setting('transaction_isolation')
        <> 'read committed' THEN
        RAISE EXCEPTION
            'Production identity validation requires READ COMMITTED isolation';
    END IF;

    IF (
        NEW.project_product_id
            IS DISTINCT FROM OLD.project_product_id

        OR NEW.unit
            IS DISTINCT FROM OLD.unit

        OR NEW.monitoring_record_id
            IS DISTINCT FROM OLD.monitoring_record_id
    )
    AND EXISTS (
        SELECT 1
        FROM public.monitoring_production_monthly m
        WHERE m.monitoring_production_id = OLD.id
    ) THEN
        RAISE EXCEPTION
            'Cannot change Production product, unit or parent while monthly details exist';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_guard_monitoring_production_monthly_identity
BEFORE UPDATE OF
    project_product_id,
    unit,
    monitoring_record_id
ON public.monitoring_production
FOR EACH ROW
EXECUTE FUNCTION public.guard_monitoring_production_monthly_identity();


-- ============================================================
-- 6. Protect quarterly Monitoring identity.
-- ============================================================

CREATE FUNCTION public.guard_monitoring_record_monthly_identity()
RETURNS TRIGGER
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
BEGIN
    IF current_setting('transaction_isolation')
        <> 'read committed' THEN
        RAISE EXCEPTION
            'Monitoring identity validation requires READ COMMITTED isolation';
    END IF;

    IF (
        NEW.project_id IS DISTINCT FROM OLD.project_id

        OR NEW.year IS DISTINCT FROM OLD.year

        OR NEW.quarter_id IS DISTINCT FROM OLD.quarter_id
    )
    AND EXISTS (
        SELECT 1
        FROM public.monitoring_production mp
        JOIN public.monitoring_production_monthly m
            ON m.monitoring_production_id = mp.id
        WHERE mp.monitoring_record_id = OLD.id
    ) THEN
        RAISE EXCEPTION
            'Cannot change project, year or quarter while monthly Production exists';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_guard_monitoring_record_monthly_identity
BEFORE UPDATE OF project_id, year, quarter_id
ON public.monitoring_records
FOR EACH ROW
EXECUTE FUNCTION public.guard_monitoring_record_monthly_identity();


-- ============================================================
-- 7. Protect quarter numbering.
-- ============================================================

CREATE FUNCTION public.guard_quarter_monthly_identity()
RETURNS TRIGGER
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
BEGIN
    IF current_setting('transaction_isolation')
        <> 'read committed' THEN
        RAISE EXCEPTION
            'Quarter identity validation requires READ COMMITTED isolation';
    END IF;

    IF NEW.quarter_number IS DISTINCT FROM OLD.quarter_number
       AND EXISTS (
           SELECT 1
           FROM public.monitoring_records mr
           JOIN public.monitoring_production mp
               ON mp.monitoring_record_id = mr.id
           JOIN public.monitoring_production_monthly m
               ON m.monitoring_production_id = mp.id
           WHERE mr.quarter_id = OLD.id
       ) THEN
        RAISE EXCEPTION
            'Cannot change quarter numbering while monthly Production exists';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_guard_quarter_monthly_identity
BEFORE UPDATE OF quarter_number
ON public.quarters
FOR EACH ROW
EXECUTE FUNCTION public.guard_quarter_monthly_identity();


-- ============================================================
-- 8. Add Visit Report conductor fields.
-- ============================================================

ALTER TABLE public.monitoring_visit_reports
    ADD COLUMN conducted_by_user_id UUID,

    ADD COLUMN conducted_by VARCHAR(255),

    ADD CONSTRAINT fk_monitoring_visit_report_conductor_user
        FOREIGN KEY (conducted_by_user_id)
        REFERENCES public.user_profiles(id)
        ON DELETE RESTRICT,

    ADD CONSTRAINT chk_monitoring_visit_report_conductor
        CHECK (
            (
                conducted_by_user_id IS NULL
                OR conducted_by IS NULL
            )
            AND (
                conducted_by IS NULL
                OR length(btrim(conducted_by)) > 0
            )
        );

CREATE INDEX idx_monitoring_visit_reports_conductor_user
ON public.monitoring_visit_reports (conducted_by_user_id)
WHERE conducted_by_user_id IS NOT NULL;


-- ============================================================
-- 9. Restrict direct database access.
-- ============================================================

ALTER TABLE public.monitoring_production_monthly
ENABLE ROW LEVEL SECURITY;

REVOKE ALL PRIVILEGES
ON TABLE public.monitoring_production_monthly
FROM PUBLIC, anon, authenticated;

REVOKE EXECUTE ON FUNCTION
    public.validate_monitoring_production_monthly_period(),
    public.guard_monitoring_production_monthly_identity(),
    public.guard_monitoring_record_monthly_identity(),
    public.guard_quarter_monthly_identity()
FROM PUBLIC, anon, authenticated;


-- ============================================================
-- 10. Finish migration.
-- ============================================================

COMMIT;
