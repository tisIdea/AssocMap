-- Task 10. Forward-only; 018 remains reserved for the separate geography cutover.
-- Requires 001, 005, 012-016; trusted table owner; READ COMMITTED writers.
-- No aggregate records are converted and no daily entries are backfilled.
BEGIN;
SET LOCAL lock_timeout = '10s';

DO $preflight$
DECLARE table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['monitoring_records', 'monitoring_production',
        'monitoring_production_monthly', 'quarters', 'projects', 'project_products', 'associations', 'user_profiles', 'audit_logs'] LOOP
        IF current_user IN ('anon', 'authenticated') OR current_user IS DISTINCT FROM
            (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = ('public.' || table_name)::regclass) THEN
            RAISE EXCEPTION '019 requires the trusted owner of public.%', table_name;
        END IF;
    END LOOP;
    IF to_regprocedure('public.protect_finalized_project_history()') IS NULL
        OR NOT EXISTS (SELECT 1 FROM pg_constraint
            WHERE conrelid = 'public.monitoring_production_monthly'::regclass
            AND conname = 'chk_monitoring_production_activity_count'
            AND pg_get_constraintdef(oid) LIKE '%>= 0%') THEN
        RAISE EXCEPTION '019 requires the finalized-history and zero-activity migrations';
    END IF;
END;
$preflight$;

ALTER TABLE public.monitoring_production
    ADD COLUMN quantity_source TEXT NOT NULL DEFAULT 'aggregate'
        CHECK (quantity_source IN ('aggregate', 'daily_harvest')),
    ADD COLUMN harvest_version BIGINT NOT NULL DEFAULT 0 CHECK (harvest_version >= 0),
    ALTER COLUMN target_output DROP NOT NULL,
    ADD CONSTRAINT chk_aggregate_production_target
        CHECK (quantity_source = 'daily_harvest' OR target_output IS NOT NULL);

CREATE TABLE public.monitoring_harvest_entries (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    monitoring_production_id UUID NOT NULL REFERENCES public.monitoring_production(id) ON DELETE RESTRICT,
    harvest_date DATE NOT NULL,
    -- Unconstrained NUMERIC plus checks rejects excess precision instead of rounding at assignment.
    quantity NUMERIC NOT NULL CHECK (quantity > 0 AND quantity <= 999999999999.99 AND scale(quantity) <= 2),
    remarks TEXT CHECK (remarks IS NULL OR length(remarks) <= 2000),
    created_by UUID NOT NULL REFERENCES public.user_profiles(id) ON DELETE RESTRICT,
    updated_by UUID NOT NULL REFERENCES public.user_profiles(id) ON DELETE RESTRICT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    voided_at TIMESTAMPTZ,
    voided_by UUID REFERENCES public.user_profiles(id) ON DELETE RESTRICT,
    void_reason TEXT,
    CONSTRAINT chk_harvest_void_metadata CHECK (
        (voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR
        (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL
            AND length(btrim(void_reason)) BETWEEN 1 AND 2000))
);
CREATE UNIQUE INDEX uq_monitoring_harvest_active_date
    ON public.monitoring_harvest_entries(monitoring_production_id, harvest_date) WHERE voided_at IS NULL;
CREATE INDEX idx_monitoring_harvest_history
    ON public.monitoring_harvest_entries(monitoring_production_id, harvest_date, id);
CREATE TRIGGER trg_monitoring_harvest_updated_at BEFORE UPDATE ON public.monitoring_harvest_entries
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE FUNCTION public.guard_daily_production_identity()
RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $function$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.quantity_source = 'daily_harvest' THEN
            RAISE EXCEPTION 'Daily production history cannot be deleted; void individual harvests.' USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;
    IF NEW.quantity_source IS DISTINCT FROM OLD.quantity_source THEN
        RAISE EXCEPTION 'Production quantity source cannot be converted.' USING ERRCODE = '23514';
    END IF;
    IF OLD.quantity_source = 'daily_harvest' AND
        (NEW.id, NEW.monitoring_record_id, NEW.project_product_id, NEW.unit) IS DISTINCT FROM
        (OLD.id, OLD.monitoring_record_id, OLD.project_product_id, OLD.unit) THEN
        RAISE EXCEPTION 'Daily production identity and unit are immutable.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$function$;
CREATE TRIGGER trg_guard_daily_production_identity BEFORE UPDATE OR DELETE ON public.monitoring_production
    FOR EACH ROW EXECUTE FUNCTION public.guard_daily_production_identity();

CREATE FUNCTION public.guard_daily_product_project()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp SET row_security = off AS $function$
BEGIN
    IF NEW.project_id IS DISTINCT FROM OLD.project_id AND EXISTS (
        SELECT 1 FROM public.monitoring_production WHERE project_product_id = OLD.id AND quantity_source = 'daily_harvest'
    ) THEN
        RAISE EXCEPTION 'Products with daily production cannot move between projects.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$function$;
CREATE TRIGGER trg_guard_daily_product_project BEFORE UPDATE OF project_id ON public.project_products
    FOR EACH ROW EXECUTE FUNCTION public.guard_daily_product_project();

-- Keep all three projection identities, including before the first harvest.
CREATE FUNCTION public.guard_daily_projection_identity()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp SET row_security = off AS $function$
BEGIN
    IF EXISTS (SELECT 1 FROM public.monitoring_production WHERE id = OLD.monitoring_production_id
        AND quantity_source = 'daily_harvest') THEN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'Daily monthly projections cannot be deleted.' USING ERRCODE = '23514';
        END IF;
        IF (NEW.id, NEW.monitoring_production_id, NEW.month) IS DISTINCT FROM
            (OLD.id, OLD.monitoring_production_id, OLD.month) THEN
            RAISE EXCEPTION 'Daily monthly projection identity cannot change.' USING ERRCODE = '23514';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$function$;
CREATE TRIGGER trg_guard_daily_projection_identity BEFORE UPDATE OR DELETE ON public.monitoring_production_monthly
    FOR EACH ROW EXECUTE FUNCTION public.guard_daily_projection_identity();

CREATE FUNCTION public.guard_monitoring_harvest_entry()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp SET row_security = off AS $function$
DECLARE ancestry RECORD; parent RECORD; period RECORD;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Harvest history cannot be deleted; use voiding.' USING ERRCODE = '23514';
    END IF;
    IF current_setting('transaction_isolation') <> 'read committed' THEN
        RAISE EXCEPTION 'Harvest writes require READ COMMITTED.' USING ERRCODE = '25000';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.voided_at IS NOT NULL OR (NEW.id, NEW.monitoring_production_id, NEW.created_by, NEW.created_at)
            IS DISTINCT FROM (OLD.id, OLD.monitoring_production_id, OLD.created_by, OLD.created_at) THEN
            RAISE EXCEPTION 'Voided harvests and harvest creation identity are immutable.' USING ERRCODE = '23514';
        END IF;
        IF NEW.voided_at IS NOT NULL AND (NEW.harvest_date, NEW.quantity, NEW.remarks)
            IS DISTINCT FROM (OLD.harvest_date, OLD.quantity, OLD.remarks) THEN
            RAISE EXCEPTION 'Void and edit must be separate operations.' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.voided_at IS NOT NULL THEN
        RAISE EXCEPTION 'New harvests must be active.' USING ERRCODE = '23514';
    END IF;
    SELECT mr.id AS record_id, mr.project_id, p.association_id INTO ancestry
        FROM public.monitoring_production mp JOIN public.monitoring_records mr ON mr.id = mp.monitoring_record_id
        JOIN public.projects p ON p.id = mr.project_id WHERE mp.id = NEW.monitoring_production_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'Harvest parent unavailable.' USING ERRCODE = '23514'; END IF;
    PERFORM 1 FROM public.associations WHERE id = ancestry.association_id AND status = 'active'
        AND archived_at IS NULL FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Association is not operational.' USING ERRCODE = '23514'; END IF;
    PERFORM 1 FROM public.projects WHERE id = ancestry.project_id AND association_id = ancestry.association_id
        AND status NOT IN ('completed', 'terminated') AND closed_at IS NULL AND archived_at IS NULL FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Finalized or archived project history is read-only.' USING ERRCODE = '23514'; END IF;
    SELECT year, quarter_id INTO period FROM public.monitoring_records
        WHERE id = ancestry.record_id AND project_id = ancestry.project_id FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Harvest ancestry changed.' USING ERRCODE = '23514'; END IF;
    SELECT monitoring_record_id, quantity_source, project_product_id INTO parent FROM public.monitoring_production
        WHERE id = NEW.monitoring_production_id FOR SHARE;
    IF parent.monitoring_record_id IS DISTINCT FROM ancestry.record_id OR parent.quantity_source <> 'daily_harvest' THEN
        RAISE EXCEPTION 'Daily harvest requires a daily-mode parent.' USING ERRCODE = '23514';
    END IF;
    PERFORM 1 FROM public.project_products WHERE id = parent.project_product_id AND project_id = ancestry.project_id FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Harvest product belongs to another project.' USING ERRCODE = '23514'; END IF;
    PERFORM 1 FROM public.quarters q WHERE q.id = period.quarter_id
        AND q.quarter_number BETWEEN 1 AND 4
        AND EXTRACT(QUARTER FROM NEW.harvest_date) = q.quarter_number FOR SHARE;
    IF NOT FOUND OR EXTRACT(YEAR FROM NEW.harvest_date) <> period.year
        OR NEW.harvest_date > (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date THEN
        RAISE EXCEPTION 'Harvest date is future or outside the Monitoring period.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$function$;
CREATE TRIGGER trg_guard_monitoring_harvest_entry BEFORE INSERT OR UPDATE OR DELETE ON public.monitoring_harvest_entries
    FOR EACH ROW EXECUTE FUNCTION public.guard_monitoring_harvest_entry();

-- Validate final transaction state, allowing an entry and its projections to change together.
CREATE FUNCTION public.assert_daily_harvest_projection(p_id UUID)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp SET row_security = off AS $function$
DECLARE parent RECORD; first_month INTEGER;
BEGIN
    SELECT mp.*, mr.year, q.quarter_number INTO parent FROM public.monitoring_production mp
        JOIN public.monitoring_records mr ON mr.id = mp.monitoring_record_id
        JOIN public.quarters q ON q.id = mr.quarter_id WHERE mp.id = p_id;
    IF NOT FOUND OR parent.quantity_source <> 'daily_harvest' THEN RETURN; END IF;
    first_month := (parent.quarter_number - 1) * 3 + 1;
    IF parent.quarter_number NOT BETWEEN 1 AND 4 OR
        (SELECT count(*) FROM public.monitoring_production_monthly WHERE monitoring_production_id = p_id) <> 3 OR
        EXISTS (
            SELECT 1 FROM generate_series(first_month, first_month + 2) AS months(month)
            LEFT JOIN public.monitoring_production_monthly m ON m.monitoring_production_id = p_id AND m.month = months.month
            LEFT JOIN LATERAL (
                SELECT count(*) AS days, coalesce(sum(h.quantity), 0) AS quantity
                FROM public.monitoring_harvest_entries h WHERE h.monitoring_production_id = p_id
                    AND h.voided_at IS NULL AND EXTRACT(MONTH FROM h.harvest_date) = months.month
            ) actual ON true
            WHERE m.id IS NULL OR m.activity_count <> actual.days OR m.total_quantity <> actual.quantity
        ) OR parent.actual_output <> (SELECT coalesce(sum(quantity), 0) FROM public.monitoring_harvest_entries
            WHERE monitoring_production_id = p_id AND voided_at IS NULL) OR
        EXISTS (SELECT 1 FROM public.monitoring_harvest_entries WHERE monitoring_production_id = p_id
            AND (EXTRACT(YEAR FROM harvest_date) <> parent.year OR EXTRACT(QUARTER FROM harvest_date) <> parent.quarter_number)) THEN
        RAISE EXCEPTION 'Daily production projections must match authoritative harvest entries.' USING ERRCODE = '23514';
    END IF;
END;
$function$;
CREATE FUNCTION public.check_daily_harvest_projection()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp SET row_security = off AS $function$
BEGIN
    IF TG_TABLE_NAME = 'monitoring_production' THEN
        IF TG_OP <> 'DELETE' THEN PERFORM public.assert_daily_harvest_projection(NEW.id); END IF;
    ELSE
        IF TG_OP <> 'INSERT' THEN PERFORM public.assert_daily_harvest_projection(OLD.monitoring_production_id); END IF;
        IF TG_OP <> 'DELETE' THEN PERFORM public.assert_daily_harvest_projection(NEW.monitoring_production_id); END IF;
    END IF;
    RETURN NULL;
END;
$function$;
CREATE CONSTRAINT TRIGGER trg_check_daily_harvest_projection
    AFTER INSERT OR UPDATE OR DELETE ON public.monitoring_production
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_daily_harvest_projection();
CREATE CONSTRAINT TRIGGER trg_check_daily_harvest_projection
    AFTER INSERT OR UPDATE OR DELETE ON public.monitoring_production_monthly
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_daily_harvest_projection();
CREATE CONSTRAINT TRIGGER trg_check_daily_harvest_projection
    AFTER INSERT OR UPDATE OR DELETE ON public.monitoring_harvest_entries
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_daily_harvest_projection();

ALTER TABLE public.monitoring_harvest_entries ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.monitoring_harvest_entries FROM PUBLIC, anon, authenticated;
REVOKE ALL ON FUNCTION public.guard_daily_production_identity(), public.guard_daily_projection_identity(),
    public.guard_daily_product_project(),
    public.guard_monitoring_harvest_entry(), public.assert_daily_harvest_projection(UUID),
    public.check_daily_harvest_projection() FROM PUBLIC, anon, authenticated;
COMMIT;
