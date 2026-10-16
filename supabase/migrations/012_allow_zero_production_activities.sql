-- AssocMap Migration 012: explicit zero-production activity counts.
-- Forward-only source; no historical rows or lifecycle triggers are changed.
BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE public.monitoring_production_monthly
    DROP CONSTRAINT chk_monitoring_production_activity_count,
    ADD CONSTRAINT chk_monitoring_production_activity_count CHECK (activity_count >= 0);

COMMIT;
