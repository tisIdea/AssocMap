
-- ============================================================
-- ASSOCMAP MIGRATION 006
-- Secure User Invitations - Version 2
--
-- Creates durable invitation records for:
--   1. Field Officer
--   2. Association Account
--
-- Supabase Auth remains the authentication authority.
--
-- PREREQUISITES:
--   Migrations 001-005
--
-- IMPORTANT:
--   Run only after source review and explicit approval.
-- ============================================================

BEGIN;

-- ============================================================
-- 1. PREREQUISITE VALIDATION
-- ============================================================

DO $$
DECLARE
    v_role_count INTEGER;
BEGIN
    IF to_regclass('public.roles') IS NULL
       OR to_regclass('public.user_profiles') IS NULL
       OR to_regclass('public.associations') IS NULL
       OR to_regclass('auth.users') IS NULL
    THEN
        RAISE EXCEPTION
            'Migration 006: required tables are missing';
    END IF;

    IF to_regprocedure('public.set_updated_at()') IS NULL
    THEN
        RAISE EXCEPTION
            'Migration 006: updated_at function is missing';
    END IF;

    IF to_regclass('public.user_invitations') IS NOT NULL
    THEN
        RAISE EXCEPTION
            'Migration 006: user_invitations already exists';
    END IF;

    SELECT COUNT(*)
    INTO v_role_count
    FROM public.roles
    WHERE code IN (
        'field_officer',
        'association_account'
    )
    AND is_active = TRUE;

    IF v_role_count <> 2
    THEN
        RAISE EXCEPTION
            'Migration 006: invitation roles are not both active';
    END IF;
END;
$$;

-- ============================================================
-- 2. INVITATION TABLE
-- ============================================================

CREATE TABLE public.user_invitations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    email VARCHAR(255) NOT NULL,

    display_name VARCHAR(255) NOT NULL,

    role_id BIGINT NOT NULL
        REFERENCES public.roles(id)
        ON DELETE RESTRICT,

    association_id UUID
        REFERENCES public.associations(id)
        ON DELETE RESTRICT,

    invited_by UUID
        REFERENCES public.user_profiles(id)
        ON DELETE SET NULL,

    auth_user_id UUID
        REFERENCES auth.users(id)
        ON DELETE SET NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'pending',

    attempt_count INTEGER NOT NULL DEFAULT 0,

    last_attempt_at TIMESTAMPTZ,

    sent_at TIMESTAMPTZ,

    accepted_at TIMESTAMPTZ,

    last_error_code VARCHAR(100),

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT chk_user_invitation_email
        CHECK (
            email = LOWER(BTRIM(email))
            AND LENGTH(email) > 3
            AND POSITION('@' IN email) > 1
            AND POSITION(' ' IN email) = 0
        ),

    CONSTRAINT chk_user_invitation_display_name
        CHECK (
            LENGTH(BTRIM(display_name)) > 0
        ),

    CONSTRAINT chk_user_invitation_status
        CHECK (
            status IN (
                'pending',
                'processing',
                'sent',
                'accepted',
                'failed',
                'cancelled',
                'expired'
            )
        ),

    CONSTRAINT chk_user_invitation_attempt_count
        CHECK (attempt_count >= 0),

    -- Historical acceptance remains recorded even if
    -- the Supabase Auth identity is later deleted.
    CONSTRAINT chk_user_invitation_accepted
        CHECK (
            status <> 'accepted'
            OR accepted_at IS NOT NULL
        )
);

-- ============================================================
-- 3. DUPLICATE PREVENTION
-- ============================================================

-- One unresolved invitation per normalized email.
--
-- Failed invitations remain reserved so a retry
-- must reconcile the existing operation first.

CREATE UNIQUE INDEX
    uq_user_invitations_unresolved_email
ON public.user_invitations (email)
WHERE status IN (
    'pending',
    'processing',
    'sent',
    'failed'
);

-- An Auth identity cannot be claimed by two
-- unresolved invitation operations at once.
--
-- Historical accepted, cancelled, or expired
-- records do not permanently reserve that UUID.

CREATE UNIQUE INDEX
    uq_user_invitations_unresolved_auth_user
ON public.user_invitations (auth_user_id)
WHERE auth_user_id IS NOT NULL
AND status IN (
    'pending',
    'processing',
    'sent',
    'failed'
);

-- ============================================================
-- 4. SUPPORTING INDEXES
-- ============================================================

CREATE INDEX
    idx_user_invitations_status_created
ON public.user_invitations (
    status,
    created_at DESC
);

CREATE INDEX
    idx_user_invitations_role
ON public.user_invitations (role_id);

CREATE INDEX
    idx_user_invitations_association
ON public.user_invitations (association_id)
WHERE association_id IS NOT NULL;

-- ============================================================
-- 5. INVITATION ROLE AND ASSOCIATION VALIDATION
-- ============================================================

CREATE FUNCTION public.validate_user_invitation_scope()
RETURNS TRIGGER
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
DECLARE
    v_role_code VARCHAR(50);
    v_role_active BOOLEAN;
BEGIN
    SELECT r.code, r.is_active
    INTO v_role_code, v_role_active
    FROM public.roles r
    WHERE r.id = NEW.role_id;

    IF v_role_code IS NULL
       OR v_role_code NOT IN (
           'field_officer',
           'association_account'
       )
       OR v_role_active IS DISTINCT FROM TRUE
    THEN
        RAISE EXCEPTION
            'Invitation requires an active permitted role';
    END IF;

    IF v_role_code = 'association_account'
       AND NEW.association_id IS NULL
    THEN
        RAISE EXCEPTION
            'Association Account invitation requires an association';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_validate_user_invitation_scope
BEFORE INSERT OR UPDATE OF role_id, association_id
ON public.user_invitations
FOR EACH ROW
EXECUTE FUNCTION
    public.validate_user_invitation_scope();

-- ============================================================
-- 6. UPDATED_AT MAINTENANCE
-- ============================================================

CREATE TRIGGER trg_user_invitations_updated_at
BEFORE UPDATE
ON public.user_invitations
FOR EACH ROW
EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 7. ROW LEVEL SECURITY
-- ============================================================

ALTER TABLE public.user_invitations
ENABLE ROW LEVEL SECURITY;

-- No direct access for browser/mobile clients.
-- Invitation writes must pass through an
-- authorized, trusted Laravel backend.

REVOKE ALL PRIVILEGES
ON TABLE public.user_invitations
FROM PUBLIC, anon, authenticated;

REVOKE EXECUTE
ON FUNCTION public.validate_user_invitation_scope()
FROM PUBLIC, anon, authenticated;

COMMIT;
