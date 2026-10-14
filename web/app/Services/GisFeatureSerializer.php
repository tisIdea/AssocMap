<?php

namespace App\Services;

use App\Models\GisLocation;

class GisFeatureSerializer
{
    public function make(GisLocation $location, bool $public, bool $manager = false): array
    {
        $geometry = is_string($location->geometry_json) ? json_decode($location->geometry_json, true) : null;
        $properties = [
            'location_type' => $location->location_type,
            'title' => $public ? $location->public_title : $location->location_name,
            'address' => $public ? $location->public_address : $location->address,
            'description' => $location->public_description,
        ];
        if (! $public) {
            $properties += [
                'association' => $location->association_name, 'project' => $location->project_title,
                'municipality' => $location->municipality_name, 'barangay' => $location->barangay_name,
                'area' => $location->area_address, 'program_year' => $location->program_year,
            ];
        }
        if (! $public && $manager) {
            $properties += [
                'association_id' => $location->association_id, 'project_id' => $location->project_id,
                'parent_association_id' => $location->parent_association_id,
                'public_title' => $location->public_title, 'public_description' => $location->public_description,
                'public_address' => $location->public_address, 'is_published' => $location->is_published,
                'published_by' => $location->published_by, 'published_at' => $location->published_at?->toIso8601String(),
                'created_by' => $location->created_by, 'updated_by' => $location->updated_by,
                'created_by_name' => $location->created_by_name, 'updated_by_name' => $location->updated_by_name,
                'published_by_name' => $location->published_by_name, 'archived_by_name' => $location->archived_by_name,
                'created_at' => $location->created_at?->toIso8601String(), 'updated_at' => $location->updated_at?->toIso8601String(),
                'archived_by' => $location->archived_by, 'archived_at' => $location->archived_at?->toIso8601String(),
                'version' => $location->version,
            ];
        }

        return ['type' => 'Feature', 'id' => $location->id, 'geometry' => $geometry, 'properties' => $properties];
    }
}
