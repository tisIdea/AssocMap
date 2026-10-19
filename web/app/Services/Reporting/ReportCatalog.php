<?php

namespace App\Services\Reporting;

class ReportCatalog
{
    public const VERSION = 'assocmap.reports.v1';

    public const TYPES = [
        'comprehensive' => 'Comprehensive Report',
        'associations' => 'Association Distribution',
        'members' => 'Member Summary',
        'projects' => 'Project Portfolio',
        'trainings' => 'Training Participation',
        'production' => 'Production Performance',
        'income' => 'Gross Income',
        'activities' => 'Activities and Monitoring',
    ];

    public static function filters(string $type): array
    {
        $common = ['municipality_id', 'association_id'];

        return array_merge($common, match ($type) {
            'associations', 'members' => [],
            'projects' => ['program_year', 'component_id', 'project_id'],
            'trainings' => ['program_year', 'year', 'quarter', 'component_id', 'project_id'],
            'production', 'income' => ['program_year', 'year', 'quarter', 'month', 'component_id', 'project_id', 'product_id'],
            default => ['program_year', 'year', 'quarter', 'component_id', 'project_id'],
        });
    }
}
