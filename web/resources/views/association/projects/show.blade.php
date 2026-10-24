@extends('layouts.association')

@section('content')
<section class="program-management" data-workspace-module="association-projects" data-workspace-title="Project Details" data-association-projects>
    <a class="back-link" href="{{ route('association.projects.index') }}"><x-icon name="back" />Back to My Projects</a>
    <header class="card program-heading"><div><p class="eyebrow">{{ $association->name }}</p><h1 tabindex="-1">Project Details</h1><p>{{ $project->title }}</p></div></header>
    @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <section class="card program-information" aria-labelledby="project-information-title">
        <h2 id="project-information-title">Project overview</h2>
        <p>This page provides read-only access to your association's saved project information.</p>
        @if($project->archived_at)<p class="notice">Project Archived — Read Only.</p>@endif
        @if($project->isFinalized())<p class="notice">Project finalized — Read Only. Saved Monitoring history remains available.</p>@endif
        <dl class="detail-grid">
            <div><dt>Current project status</dt><dd><x-status-badge :value="ucfirst($project->status)" /></dd></div>
            <div><dt>Program year</dt><dd>{{ $project->program_year }}</dd></div>
            <div><dt>Program classification</dt><dd>{{ $project->saadActivity?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Fisheries category</dt><dd>{{ $project->fisheriesCategory?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Commodity</dt><dd>{{ $project->commodity?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Implementation start</dt><dd>{{ $project->implementation_start_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Implementation end</dt><dd>{{ $project->implementation_end_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Association barangay</dt><dd>{{ $association->barangay_name ?? 'Not recorded' }}</dd></div>
            <div><dt>Municipality / city</dt><dd>{{ $association->municipality_name ?? 'Not recorded' }}</dd></div>
            <div><dt>Registered Area reference</dt><dd>{{ $association->area_id === null ? 'No Area assigned' : (filled($association->area_address) ? $association->area_address : 'Not recorded') }}</dd></div>
        </dl>
        <h3>Description</h3><p class="program-preserve-text">{{ filled($project->description) ? $project->description : 'Not recorded' }}</p>
        <p>Location labels describe the linked association's geography.</p>
    </section>
    <section class="card program-register" id="project-products" aria-labelledby="project-products-title">
        <div class="program-section-heading"><div><h2 id="project-products-title">Project products</h2><p>Registered products and their default units.</p></div></div>
        <div class="table-scroll" role="region" aria-label="Registered project products" tabindex="0"><table class="data-table program-register-table">
            <caption class="sr-only">Products linked to this project</caption><thead><tr><th scope="col">Product</th><th scope="col">Type</th><th scope="col">Default unit</th><th scope="col">Status</th></tr></thead><tbody>
                @forelse($products as $product)<tr><th scope="row">{{ $product->product_name }}<small>{{ filled($product->description) ? $product->description : 'Description not recorded' }}</small></th><td>{{ ucfirst($product->product_type) }}</td><td>{{ filled($product->default_unit) ? $product->default_unit : 'Not recorded' }}</td><td><x-status-badge :value="$product->is_active ? 'Active' : 'Inactive'" /></td></tr>
                @empty<tr><td colspan="4"><x-empty-state :title="$products->total() === 0 ? 'No products recorded for this project.' : 'No products on this page.'" message="Only registered project products are shown here." /></td></tr>@endforelse
            </tbody></table></div>
        @include('program-management.partials.pagination', ['paginator' => $products, 'label' => 'products'])
    </section>
    <section class="card program-register" id="project-assistance" aria-labelledby="project-assistance-title">
        <div class="program-section-heading"><div><h2 id="project-assistance-title">Registered assistance / materials</h2><p>Saved project inventory and delivery information. Quarterly condition and maintenance observations are available in Monitoring.</p></div></div>
        <div class="table-scroll" role="region" aria-label="Registered project assistance" tabindex="0"><table class="data-table program-register-table">
            <caption class="sr-only">Registered assistance quantities and delivery information</caption><thead><tr><th scope="col">Item</th><th scope="col">Planned quantity</th><th scope="col">Delivered quantity</th><th scope="col">Unit</th><th scope="col">Delivery status</th><th scope="col">Delivery date</th></tr></thead><tbody>
                @forelse($materials as $material)<tr><th scope="row">{{ $material->item_name }}</th><td>{{ $material->quantity_planned ?? 'Not recorded' }}</td><td>{{ $material->quantity_delivered ?? 'Not recorded' }}</td><td>{{ filled($material->unit) ? $material->unit : 'Not recorded' }}</td><td><x-status-badge :value="ucwords(str_replace('_', ' ', $material->delivery_status))" /></td><td>{{ $material->delivery_date?->format('M j, Y') ?? 'Not recorded' }}</td></tr>
                @empty<tr><td colspan="6"><x-empty-state :title="$materials->total() === 0 ? 'No assistance / materials recorded for this project.' : 'No assistance / materials on this page.'" message="Only registered project inventory is shown here." /></td></tr>@endforelse
            </tbody></table></div>
        @include('program-management.partials.pagination', ['paginator' => $materials, 'label' => 'assistance / materials'])
    </section>
    <section class="card program-register" id="project-monitoring" aria-labelledby="project-monitoring-title">
        <div class="program-section-heading"><div><h2 id="project-monitoring-title">Monitoring history</h2><p>Open the existing read-only Monitoring workspaces. Saved status during Monitoring may differ from the current project status.</p></div></div>
        @if($monitoring->total() === 0)
            <x-empty-state title="No Monitoring records recorded for this project." message="The project remains available in My Projects without a Monitoring record." />
        @else
            <div class="table-scroll" role="region" aria-label="Project Monitoring history" tabindex="0"><table class="data-table program-register-table">
                <caption class="sr-only">Existing quarterly Monitoring records and authorized read-only workspaces</caption><thead><tr><th scope="col">Reporting period</th><th scope="col">Monitoring date</th><th scope="col">Status during Monitoring</th><th scope="col">Site validated</th><th scope="col">View records</th></tr></thead><tbody>
                    @forelse($monitoring as $record)<tr><th scope="row">{{ $record->year }} · Q{{ $record->quarter?->quarter_number ?? '?' }}</th><td>{{ $record->monitoring_date?->format('M j, Y') ?? 'Not recorded' }}</td><td><x-status-badge :value="ucfirst($record->project_status)" /></td><td>{{ $record->site_validated ? 'Yes' : 'No' }}</td><td>
                        <a class="button" href="{{ route('association.monitoring.workspace', $record->id) }}" aria-label="View Overview for Q{{ $record->quarter?->quarter_number ?? '?' }} {{ $record->year }}">Overview</a>
                        @foreach(['production' => 'Production', 'income' => 'Income', 'materials' => 'Materials'] as $section => $label)<a class="button" href="{{ route('association.monitoring.workspace', [$record->id, $section]) }}" aria-label="View {{ $label }} for Q{{ $record->quarter?->quarter_number ?? '?' }} {{ $record->year }}">{{ $label }}</a>@endforeach
                        <a class="button" href="{{ route('association.monitoring.visits', $record->id) }}" aria-label="View Visit Reports for Q{{ $record->quarter?->quarter_number ?? '?' }} {{ $record->year }}">Visit Reports</a>
                    </td></tr>@empty<tr><td colspan="5">No Monitoring records on this page.</td></tr>@endforelse
                </tbody></table></div>
        @endif
        @include('program-management.partials.pagination', ['paginator' => $monitoring, 'label' => 'Monitoring records'])
    </section>
</section>
@endsection
