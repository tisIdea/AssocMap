-- Batch 1 expansion only. Compatible with legacy Area-required writers.
-- Do not apply the deferred Batch 2 cutover with this migration.
BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE public.associations
    ADD COLUMN barangay_id UUID NULL,
    ADD COLUMN specific_address TEXT NULL,
    ADD CONSTRAINT fk_associations_barangay FOREIGN KEY (barangay_id)
        REFERENCES public.barangays(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_associations_specific_address CHECK (
        specific_address IS NULL OR (
            char_length(specific_address) <= 500
            AND specific_address !~ '^[[:space:]]*$'
        )
    );

CREATE INDEX idx_associations_barangay_id ON public.associations(barangay_id);

-- area_id remains NOT NULL. Neither addresses nor geography are backfilled here.
COMMIT;
