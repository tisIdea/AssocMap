
-- AssocMap Migration 007
-- Secure Laravel-only infrastructure tables
-- PostgreSQL / Supabase
--
-- Prerequisite:
-- Tables exist in public and are owned by postgres.
--
-- Does not modify table data.

BEGIN;

-- Block direct client access to Laravel infrastructure.
REVOKE ALL PRIVILEGES ON TABLE
    public.sessions,
    public.cache,
    public.cache_locks,
    public.jobs,
    public.job_batches,
    public.failed_jobs,
    public.migrations,
    public.users,
    public.password_reset_tokens,
    public.personal_access_tokens
FROM PUBLIC, anon, authenticated;

-- Add defense-in-depth row security.
-- No client-facing policies are created.
ALTER TABLE public.sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.cache ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.cache_locks ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.job_batches ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.failed_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.migrations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.password_reset_tokens ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.personal_access_tokens ENABLE ROW LEVEL SECURITY;

COMMIT;
