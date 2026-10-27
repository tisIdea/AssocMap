<?php

namespace App\Services\Media;

use App\Exceptions\MediaLifecycleException;
use App\Models\GisLocation;
use App\Models\MonitoringMaterial;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

final class PhotoRules
{
    public const MAX_BYTES = 5242880;

    public const MAX_DIMENSION = 8192;

    public const MAX_PIXELS = 12000000;

    public const DISPLAY_EDGE = 1920;

    public const THUMBNAIL_EDGE = 320;

    public const LEASE_MINUTES = 15;

    public const LIVE_STATES = ['reserved', 'processing', 'uploading', 'uncertain', 'ready'];

    public const PARENTS = [
        Project::class => ['project_id', 1],
        GisLocation::class => ['gis_location_id', 5],
        MonitoringVisitReport::class => ['monitoring_visit_report_id', 5],
        MonitoringMaterial::class => ['monitoring_material_id', 5],
    ];

    public static function parent(Model $parent): array
    {
        return self::PARENTS[$parent::class] ?? throw new MediaLifecycleException('invalid_parent');
    }
}
