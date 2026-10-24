CREATE SCHEMA auth;
CREATE TABLE auth.users (id UUID PRIMARY KEY, email TEXT NOT NULL);
CREATE TABLE auth.sessions (id UUID PRIMARY KEY, user_id UUID REFERENCES auth.users(id), created_at TIMESTAMPTZ NOT NULL, provider_private_value TEXT);
CREATE TABLE roles (id BIGINT PRIMARY KEY, code TEXT UNIQUE NOT NULL, name TEXT, is_active BOOLEAN NOT NULL);
INSERT INTO roles VALUES (1,'system_admin','Administrator',true),(2,'field_officer','Field Officer',true),(3,'association_account','Association Account',true);
CREATE TABLE user_profiles (id UUID PRIMARY KEY REFERENCES auth.users(id), role_id BIGINT REFERENCES roles(id), display_name TEXT,
    is_active BOOLEAN NOT NULL, activated_at TIMESTAMPTZ, invited_by UUID, invited_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT now(), updated_at TIMESTAMPTZ DEFAULT now());
CREATE TABLE associations (id UUID PRIMARY KEY, name TEXT, status TEXT, archived_at TIMESTAMPTZ,
    primary_field_officer_id UUID REFERENCES user_profiles(id), created_at TIMESTAMPTZ DEFAULT now(), updated_at TIMESTAMPTZ DEFAULT now());
CREATE TABLE association_accounts (id UUID PRIMARY KEY, association_id UUID NOT NULL UNIQUE REFERENCES associations(id) ON DELETE CASCADE,
    user_id UUID NOT NULL UNIQUE REFERENCES user_profiles(id) ON DELETE CASCADE, is_active BOOLEAN NOT NULL DEFAULT true,
    linked_by UUID REFERENCES user_profiles(id) ON DELETE SET NULL, linked_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ DEFAULT now(), updated_at TIMESTAMPTZ DEFAULT now());
CREATE TABLE user_invitations (id UUID PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL,
    role_id BIGINT REFERENCES roles(id), association_id UUID REFERENCES associations(id), invited_by UUID REFERENCES user_profiles(id),
    auth_user_id UUID REFERENCES auth.users(id), status TEXT NOT NULL, attempt_count INTEGER NOT NULL DEFAULT 0,
    last_attempt_at TIMESTAMPTZ, sent_at TIMESTAMPTZ, accepted_at TIMESTAMPTZ, last_error_code TEXT,
    created_at TIMESTAMPTZ DEFAULT now(), updated_at TIMESTAMPTZ DEFAULT now());
CREATE UNIQUE INDEX uq_user_invitations_unresolved_email ON user_invitations(email) WHERE status IN ('pending','processing','sent','failed');
CREATE UNIQUE INDEX uq_user_invitations_unresolved_auth_user ON user_invitations(auth_user_id) WHERE auth_user_id IS NOT NULL AND status IN ('pending','processing','sent','failed');
CREATE TABLE audit_logs (id UUID PRIMARY KEY, actor_user_id UUID REFERENCES user_profiles(id), action_type TEXT NOT NULL, module TEXT NOT NULL,
    entity_type TEXT, entity_id TEXT, old_values JSONB, new_values JSONB, performed_at TIMESTAMPTZ NOT NULL);
CREATE FUNCTION reject_synthetic_audit() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action_type = current_setting('assocmap.reject_audit',true) THEN RAISE EXCEPTION 'Synthetic audit rejection'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER reject_synthetic_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_synthetic_audit();
CREATE FUNCTION validate_association_account_role() RETURNS TRIGGER LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END; $$;
CREATE TRIGGER trg_validate_association_account_role BEFORE INSERT OR UPDATE OF user_id ON association_accounts
    FOR EACH ROW EXECUTE FUNCTION validate_association_account_role();
ALTER TABLE association_accounts ENABLE ROW LEVEL SECURITY;
