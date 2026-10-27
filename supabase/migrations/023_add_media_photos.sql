-- Canonical definition, executed transactionally by the Laravel wrapper.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

CREATE TABLE public.media_photos (
    id UUID PRIMARY KEY,
    project_id UUID REFERENCES public.projects(id) ON DELETE RESTRICT,
    gis_location_id UUID REFERENCES public.gis_locations(id) ON DELETE RESTRICT,
    monitoring_visit_report_id UUID REFERENCES public.monitoring_visit_reports(id) ON DELETE RESTRICT,
    monitoring_material_id UUID REFERENCES public.monitoring_materials(id) ON DELETE RESTRICT,
    uploaded_by UUID NOT NULL REFERENCES public.user_profiles(id) ON DELETE RESTRICT,
    request_id UUID NOT NULL UNIQUE,
    disk VARCHAR(32) NOT NULL DEFAULT 'media_private' CHECK (disk = 'media_private'),
    object_prefix VARCHAR(42) NOT NULL UNIQUE CHECK (length(object_prefix) = 42 AND object_prefix LIKE 'media/%'),
    input_sha256 VARCHAR(64) NOT NULL CHECK (length(input_sha256) = 64),
    state VARCHAR(16) NOT NULL DEFAULT 'reserved'
        CHECK (state IN ('reserved', 'processing', 'uploading', 'uncertain', 'ready', 'deleting', 'deleted')),
    replaces_photo_id UUID REFERENCES public.media_photos(id) ON DELETE RESTRICT,
    image_format VARCHAR(3) CHECK (image_format IN ('jpg', 'png')),
    display_width INTEGER,
    display_height INTEGER,
    display_bytes INTEGER,
    display_sha256 VARCHAR(64),
    thumbnail_width INTEGER,
    thumbnail_height INTEGER,
    thumbnail_bytes INTEGER,
    thumbnail_sha256 VARCHAR(64),
    caption VARCHAR(500),
    display_order INTEGER NOT NULL DEFAULT 0 CHECK (display_order BETWEEN 0 AND 4),
    approved_by UUID REFERENCES public.user_profiles(id) ON DELETE RESTRICT,
    approved_at TIMESTAMPTZ,
    operation_token UUID,
    lease_until TIMESTAMPTZ,
    last_error_code VARCHAR(40),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT media_exactly_one_parent CHECK (
        (CASE WHEN project_id IS NULL THEN 0 ELSE 1 END
        + CASE WHEN gis_location_id IS NULL THEN 0 ELSE 1 END
        + CASE WHEN monitoring_visit_report_id IS NULL THEN 0 ELSE 1 END
        + CASE WHEN monitoring_material_id IS NULL THEN 0 ELSE 1 END) = 1),
    CONSTRAINT media_replacement CHECK (replaces_photo_id IS NULL OR (project_id IS NOT NULL AND replaces_photo_id <> id)),
    CONSTRAINT media_project_order CHECK (project_id IS NULL OR display_order = 0),
    CONSTRAINT media_approval CHECK (
        (approved_by IS NULL AND approved_at IS NULL)
        OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND gis_location_id IS NOT NULL AND state = 'ready')),
    CONSTRAINT media_dimensions CHECK (
        (display_width IS NULL OR display_width BETWEEN 1 AND 1920)
        AND (display_height IS NULL OR display_height BETWEEN 1 AND 1920)
        AND (display_bytes IS NULL OR display_bytes BETWEEN 1 AND 5242880)
        AND (thumbnail_width IS NULL OR thumbnail_width BETWEEN 1 AND 320)
        AND (thumbnail_height IS NULL OR thumbnail_height BETWEEN 1 AND 320)
        AND (thumbnail_bytes IS NULL OR thumbnail_bytes BETWEEN 1 AND 5242880)),
    CONSTRAINT media_finalizable CHECK (state NOT IN ('uploading', 'uncertain', 'ready') OR (
        image_format IS NOT NULL AND display_width IS NOT NULL AND display_height IS NOT NULL
        AND display_bytes IS NOT NULL AND display_sha256 IS NOT NULL AND length(display_sha256) = 64
        AND thumbnail_width IS NOT NULL AND thumbnail_height IS NOT NULL AND thumbnail_bytes IS NOT NULL
        AND thumbnail_sha256 IS NOT NULL AND length(thumbnail_sha256) = 64))
);

CREATE INDEX media_project_parent ON public.media_photos(project_id, state, display_order, id);
CREATE INDEX media_gis_parent ON public.media_photos(gis_location_id, state, display_order, id);
CREATE INDEX media_visit_parent ON public.media_photos(monitoring_visit_report_id, state, display_order, id);
CREATE INDEX media_material_parent ON public.media_photos(monitoring_material_id, state, display_order, id);
CREATE INDEX media_recovery ON public.media_photos(state, lease_until, id);
CREATE UNIQUE INDEX media_one_ready_cover ON public.media_photos(project_id) WHERE project_id IS NOT NULL AND state = 'ready';
CREATE UNIQUE INDEX media_one_pending_cover ON public.media_photos(project_id)
    WHERE project_id IS NOT NULL AND state IN ('reserved', 'processing', 'uploading', 'uncertain');

-- POSTGRES SECURITY
ALTER TABLE public.media_photos ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.media_photos FROM PUBLIC, anon, authenticated;
-- No direct Supabase client policies. The reviewed Laravel backend database role is the boundary.
