-- Forward-only Materials concurrency counter. Apply before deploying the matching application.
BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE public.monitoring_records
    ADD COLUMN materials_version BIGINT NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_monitoring_materials_version CHECK (materials_version >= 0);

COMMIT;
