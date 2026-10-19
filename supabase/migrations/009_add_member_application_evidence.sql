-- AssocMap Migration 009: private member application evidence.
-- Forward-only. Apply manually after isolated testing; no historical backfill.
-- Requires the existing public.member_applications table from migration 001.
-- Independent of migration 008's executive-position enforcement.

BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE public.member_applications
    ADD COLUMN endorsement_letter TEXT,
    ADD COLUMN applicant_photo_path VARCHAR(255);

COMMIT;
