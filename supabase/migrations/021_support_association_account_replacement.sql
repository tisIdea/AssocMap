-- Execute through the reviewed Laravel wrapper in one transaction.
-- No existing account or relationship records are changed.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE public.association_accounts, public.user_invitations IN SHARE ROW EXCLUSIVE MODE;

ALTER TABLE public.association_accounts ADD COLUMN IF NOT EXISTS ended_at TIMESTAMPTZ;
ALTER TABLE public.association_accounts ADD COLUMN IF NOT EXISTS ended_by UUID;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'public.association_accounts'::regclass AND conname = 'association_accounts_ended_by_fkey') THEN
        ALTER TABLE public.association_accounts ADD CONSTRAINT association_accounts_ended_by_fkey
            FOREIGN KEY (ended_by) REFERENCES public.user_profiles(id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'public.association_accounts'::regclass AND conname = 'chk_association_account_end') THEN
        ALTER TABLE public.association_accounts ADD CONSTRAINT chk_association_account_end CHECK (
            (NOT is_active OR (ended_at IS NULL AND ended_by IS NULL))
            AND (ended_by IS NULL OR ended_at IS NOT NULL)
            AND (ended_at IS NULL OR ended_at >= linked_at)
        );
    END IF;
END;
$$;

-- Build replacement protection before dropping the original unique constraints.
CREATE UNIQUE INDEX IF NOT EXISTS uq_association_accounts_active_association
    ON public.association_accounts(association_id) WHERE is_active;
CREATE UNIQUE INDEX IF NOT EXISTS uq_association_accounts_active_user
    ON public.association_accounts(user_id) WHERE is_active;
CREATE UNIQUE INDEX IF NOT EXISTS uq_user_invitations_unresolved_association
    ON public.user_invitations(association_id)
    WHERE association_id IS NOT NULL AND status IN ('pending', 'processing', 'sent', 'failed');

ALTER TABLE public.association_accounts DROP CONSTRAINT IF EXISTS association_accounts_association_id_key;
ALTER TABLE public.association_accounts DROP CONSTRAINT IF EXISTS association_accounts_user_id_key;
CREATE INDEX IF NOT EXISTS idx_association_accounts_association_history ON public.association_accounts(association_id, linked_at DESC, id);
CREATE INDEX IF NOT EXISTS idx_association_accounts_user_history ON public.association_accounts(user_id, linked_at DESC, id);

CREATE OR REPLACE FUNCTION public.validate_association_account_role()
RETURNS TRIGGER LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.user_id <> OLD.user_id OR NEW.association_id <> OLD.association_id THEN
            RAISE EXCEPTION 'Association account history cannot be reassigned';
        END IF;
        IF NOT OLD.is_active AND NEW.is_active THEN
            RAISE EXCEPTION 'An ended association appointment cannot be restored';
        END IF;
        IF OLD.is_active AND NOT NEW.is_active AND (NEW.ended_at IS NULL OR NEW.ended_by IS NULL) THEN
            RAISE EXCEPTION 'Ending an appointment requires its time and authorizing actor';
        END IF;
        IF OLD.ended_at IS NOT NULL AND NEW.ended_at IS DISTINCT FROM OLD.ended_at THEN
            RAISE EXCEPTION 'An appointment end time cannot be rewritten';
        END IF;
    END IF;
    IF NEW.is_active AND NOT EXISTS (
        SELECT 1 FROM public.user_profiles p JOIN public.roles r ON r.id = p.role_id
        WHERE p.id = NEW.user_id AND p.is_active AND p.activated_at IS NOT NULL
            AND r.code = 'association_account' AND r.is_active
    ) THEN
        RAISE EXCEPTION 'An active appointment requires an eligible activated Association Account';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS trg_validate_association_account_role ON public.association_accounts;
CREATE TRIGGER trg_validate_association_account_role
    BEFORE INSERT OR UPDATE OF user_id, association_id, is_active, ended_at, ended_by
    ON public.association_accounts FOR EACH ROW EXECUTE FUNCTION public.validate_association_account_role();
REVOKE EXECUTE ON FUNCTION public.validate_association_account_role() FROM PUBLIC, anon, authenticated;
-- Existing RLS, policies and table grants are deliberately preserved.
