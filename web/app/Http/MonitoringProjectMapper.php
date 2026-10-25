<?php

namespace App\Http;

use App\Models\Project;

final class MonitoringProjectMapper
{
    public function map(Project $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'association_id' => $project->association_id,
            'association_name' => $project->association?->name,
            'assigned_officer' => $project->association?->primaryFieldOfficer?->is_active
                && $project->association?->primaryFieldOfficer?->role?->is_active
                && $project->association?->primaryFieldOfficer?->role?->code === 'field_officer'
                ? ['id' => $project->association->primaryFieldOfficer->id, 'name' => $project->association->primaryFieldOfficer->display_name]
                : null,
            'program_year' => $project->program_year,
            'status' => $project->status,
            'products' => $project->products->map(fn ($product) => [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_type' => $product->product_type,
                'default_unit' => $product->default_unit,
                'is_active' => (bool) $product->is_active,
            ])->values(),
            'materials' => $project->materials->map(fn ($material) => [
                'id' => $material->id,
                'item_name' => $material->item_name,
                'unit' => $material->unit,
                'delivery_status' => $material->delivery_status,
            ])->values(),
        ];
    }
}
