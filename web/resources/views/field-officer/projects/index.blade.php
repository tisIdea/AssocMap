@php
    $projectLayout = 'layouts.field-officer';
    $projectRoutePrefix = 'field-officer.projects';
    $catalog = ['options' => [
        'association_records' => $associations->map(fn ($association) => ['value' => $association->id, 'label' => $association->name])->values()->all(),
        'fisheries_category_records' => $categories->map(fn ($category) => ['value' => $category->id, 'label' => $category->name])->values()->all(),
        'commodity_records' => $commodities->map(fn ($commodity) => ['value' => $commodity->id, 'label' => $commodity->name, 'category_id' => $commodity->category_id, 'source' => $commodity->source])->values()->all(),
    ]];
    $module = ['status_filter' => $statusFilter, 'rows' => $projects->map(fn ($project) => [
        'id' => $project->id,
        'association_id' => $project->association_id,
        'association' => $project->association?->name,
        'municipality' => $project->association?->area?->barangay?->municipality?->name,
        'component' => $project->saadActivity?->name ?? 'Livelihood Project Delivery',
        'title' => $project->title,
        'description' => $project->description,
        'fisheries_category_id' => $project->fisheries_category_id,
        'fisheries_category' => $project->fisheriesCategory?->name,
        'commodity_id' => $project->commodity_id,
        'commodity' => $project->commodity?->name,
        'program_year' => $project->program_year,
        'implementation_start_date' => $project->implementation_start_date?->format('Y-m-d'),
        'implementation_end_date' => $project->implementation_end_date?->format('Y-m-d'),
        'status' => ucfirst($project->status),
        'status_value' => $project->status,
        'remarks' => $project->remarks,
        'archived_at' => $project->archived_at?->toIso8601String(),
        'materials' => $project->materials->map(fn ($material) => [
            'id' => $material->id,
            'item_name' => $material->item_name,
            'quantity_planned' => $material->quantity_planned,
            'quantity_delivered' => $material->quantity_delivered,
            'unit' => $material->unit,
            'unit_cost' => $material->unit_cost,
            'delivery_status' => $material->delivery_status,
            'delivery_date' => $material->delivery_date?->format('Y-m-d'),
            'remarks' => $material->remarks,
        ])->values()->all(),
        'products' => $project->products->map(fn ($product) => [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_type' => $product->product_type,
            'default_unit' => $product->default_unit,
            'description' => $product->description,
            'is_active' => $product->is_active,
        ])->values()->all(),
        'update_url' => route('field-officer.projects.update', $project),
        'archive_url' => route('field-officer.projects.archive', $project),
        'unarchive_url' => route('field-officer.projects.unarchive', $project),
    ])->values()->all()];
@endphp
@include('admin.projects.index')
