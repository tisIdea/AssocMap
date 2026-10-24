-- Forward-only. Apply before enabling Administrator-approved account reactivation.
ALTER TABLE public.user_profiles ADD COLUMN IF NOT EXISTS auth_sessions_valid_after TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS public.account_reactivations (
    id UUID PRIMARY KEY,
    user_id UUID NOT NULL UNIQUE REFERENCES public.user_profiles(id),
    requested_by UUID NOT NULL REFERENCES public.user_profiles(id),
    role_id BIGINT NOT NULL REFERENCES public.roles(id),
    email VARCHAR(254) NOT NULL,
    reference_hash CHAR(64) NOT NULL UNIQUE,
    review_hash CHAR(64) NOT NULL,
    revocation_count INTEGER NOT NULL CHECK (revocation_count > 0),
    state VARCHAR(32) NOT NULL CHECK (state IN ('issued', 'verifying', 'verified', 'password_started', 'completed')),
    expires_at TIMESTAMPTZ NOT NULL,
    cancelled_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

ALTER TABLE public.account_reactivations ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.account_reactivations FROM anon, authenticated;
