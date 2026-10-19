BEGIN;

-- Execute as the existing quarterly Monitoring table owner.
DO $$
DECLARE
    parent_owner NAME;
BEGIN
    IF to_regclass('public.monitoring_records') IS NULL
       OR to_regclass('public.user_profiles') IS NULL
       OR to_regprocedure('public.set_updated_at()') IS NULL THEN
        RAISE EXCEPTION '003 requires monitoring_records, user_profiles and set_updated_at() from the existing AssocMap schema.';
    END IF;

    SELECT pg_get_userbyid(relowner) INTO parent_owner
    FROM pg_class
    WHERE oid = 'public.monitoring_records'::regclass;

    IF parent_owner IN ('anon', 'authenticated') OR current_user <> parent_owner THEN
        RAISE EXCEPTION '003 must be executed as the trusted owner of public.monitoring_records; no alternative server role is granted automatically.';
    END IF;

    IF to_regclass('public.monitoring_visit_reports') IS NOT NULL THEN
        RAISE EXCEPTION '003 cannot run: public.monitoring_visit_reports already exists. Review its schema and migration history instead of replaying 003.';
    END IF;
END;
$$;

CREATE TABLE public.monitoring_visit_reports (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    monitoring_record_id    UUID NOT NULL
                            REFERENCES public.monitoring_records(id) ON DELETE CASCADE,
    monitoring_date         DATE NOT NULL,
    monitored_by            UUID
                            REFERENCES public.user_profiles(id) ON DELETE SET NULL,
    site_validated          BOOLEAN NOT NULL DEFAULT FALSE,
    issues_encountered      TEXT,
    actions_taken           TEXT,
    overall_remarks         TEXT,
    created_by              UUID
                            REFERENCES public.user_profiles(id) ON DELETE SET NULL,
    updated_by              UUID
                            REFERENCES public.user_profiles(id) ON DELETE SET NULL,
    is_legacy_import        BOOLEAN NOT NULL DEFAULT FALSE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_monitoring_visit_reports_record_date
ON public.monitoring_visit_reports
    (monitoring_record_id, monitoring_date DESC, created_at DESC, id DESC);

CREATE UNIQUE INDEX uq_monitoring_visit_reports_legacy_record
ON public.monitoring_visit_reports (monitoring_record_id)
WHERE is_legacy_import = TRUE;

CREATE TRIGGER trg_monitoring_visit_reports_updated_at
BEFORE UPDATE ON public.monitoring_visit_reports
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

ALTER TABLE public.monitoring_visit_reports ENABLE ROW LEVEL SECURITY;

-- Remove client/default PUBLIC grants; the existing table owner retains access.
REVOKE ALL PRIVILEGES ON TABLE public.monitoring_visit_reports
FROM PUBLIC, anon, authenticated;

COMMIT;
