-- REVIEW ONLY: forward-only Supabase migration proposal. Not executed by this task.
-- Register through the deployment migration workflow before deploying the matching application.
BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE public.monitoring_records
    ADD COLUMN income_version BIGINT NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_monitoring_income_version CHECK (income_version >= 0);

COMMIT;
