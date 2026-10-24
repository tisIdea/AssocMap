-- Executed by the Laravel wrapper in one transaction; do not apply twice.
-- Bound lock acquisition and each statement; SET LOCAL resets at transaction end.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE public.email_change_requests
    ADD COLUMN IF NOT EXISTS phase VARCHAR(32) NOT NULL DEFAULT 'legacy',
    ADD COLUMN IF NOT EXISTS role_id BIGINT REFERENCES public.roles(id) ON DELETE RESTRICT,
    ADD COLUMN IF NOT EXISTS reference_hash CHAR(64),
    ADD COLUMN IF NOT EXISTS scope_hash CHAR(64),
    ADD COLUMN IF NOT EXISTS browser_hash CHAR(64),
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS first_confirmation_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS password_confirmed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS signout_confirmed_at TIMESTAMPTZ;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'public.email_change_requests'::regclass AND conname = 'ck_email_change_phase') THEN
        ALTER TABLE public.email_change_requests ADD CONSTRAINT ck_email_change_phase CHECK (phase IN
            ('legacy','requested','old_verifying','old_verified','email_started','awaiting_confirmations',
             'confirming','verified','password_started','password_confirmed','signout_started','completed','reconciliation'));
        ALTER TABLE public.email_change_requests ADD CONSTRAINT ck_email_change_managed CHECK (phase = 'legacy' OR
            (requested_by IS NOT NULL AND role_id IS NOT NULL AND reference_hash IS NOT NULL AND reference_hash ~ '^[a-f0-9]{64}$'
             AND scope_hash IS NOT NULL AND scope_hash ~ '^[a-f0-9]{64}$' AND expires_at IS NOT NULL
             AND old_email = lower(trim(old_email)) AND new_email = lower(trim(new_email))));
        ALTER TABLE public.email_change_requests ADD CONSTRAINT ck_email_change_browser CHECK (browser_hash IS NULL OR browser_hash ~ '^[a-f0-9]{64}$');
        ALTER TABLE public.email_change_requests ADD CONSTRAINT ck_email_change_completed CHECK (phase <> 'completed' OR
            (status = 'confirmed' AND confirmed_at IS NOT NULL AND password_confirmed_at IS NOT NULL AND signout_confirmed_at IS NOT NULL));
        ALTER TABLE public.email_change_requests ADD CONSTRAINT uq_email_change_holder UNIQUE (user_id, id);
    END IF;
END $$;
CREATE UNIQUE INDEX IF NOT EXISTS uq_email_change_reference ON public.email_change_requests(reference_hash);
CREATE UNIQUE INDEX IF NOT EXISTS uq_email_change_pending_destination ON public.email_change_requests(lower(new_email)) WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS ix_email_change_status_expiry ON public.email_change_requests(status, expires_at);

CREATE TABLE IF NOT EXISTS public.email_change_proofs (
    proof_hash CHAR(64) PRIMARY KEY CHECK (proof_hash ~ '^[a-f0-9]{64}$'),
    request_id UUID NOT NULL REFERENCES public.email_change_requests(id) ON DELETE RESTRICT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_email_change_proofs_request ON public.email_change_proofs(request_id);
ALTER TABLE public.email_change_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.email_change_proofs ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.email_change_requests, public.email_change_proofs FROM anon, authenticated;

ALTER TABLE public.user_profiles ADD COLUMN IF NOT EXISTS approved_email VARCHAR(255),
    ADD COLUMN IF NOT EXISTS email_change_hold_id UUID;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'public.user_profiles'::regclass AND conname = 'fk_profile_email_change_hold') THEN
        ALTER TABLE public.user_profiles ADD CONSTRAINT fk_profile_email_change_hold
            FOREIGN KEY (id, email_change_hold_id) REFERENCES public.email_change_requests(user_id, id) ON DELETE RESTRICT;
        ALTER TABLE public.user_profiles ADD CONSTRAINT ck_profile_approved_email CHECK
            (approved_email IS NULL OR (approved_email = lower(trim(approved_email)) AND approved_email <> ''));
    END IF;
END $$;
