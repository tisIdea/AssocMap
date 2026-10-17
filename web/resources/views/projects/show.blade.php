@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')

@section('content')
<section data-workspace-module="projects" data-workspace-title="Project Details">
    <a class="back-link" href="{{ route($prefix.'.projects.index') }}"><x-icon name="back" />Back to Projects</a>
    <header class="page-header" style="flex-wrap: wrap; align-items: flex-start;">
        <div style="flex: 1 1 240px; min-width: 0; overflow-wrap: anywhere;">
            <div class="eyebrow">Project Management</div>
            <h1 tabindex="-1">Project Details</h1>
            <p>{{ $project->title }}</p>
        </div>
        @if($canEdit)<a class="button button-primary" style="flex-shrink: 0; margin-left: auto;" href="{{ route($prefix.'.projects.edit', $project) }}"><x-icon name="pencil" />Edit Project</a>@endif
    </header>
    @php($closureErrors = $errors->getBag('projectClosure'))
    @php($terminationErrors = $errors->getBag('projectTermination'))
    @if(!$canClose && $closureErrors->any())<div class="notice notice-error" role="alert"><ul>@foreach($closureErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(!$canClose && $terminationErrors->any())<div class="notice notice-error" role="alert"><ul>@foreach($terminationErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="card form-section">
        <h2>{{ $project->title }}</h2>
        <dl class="detail-grid">
            <div><dt>Status</dt><dd><x-status-badge :value="$project->isFinalized() ? strtoupper($project->status) : ucfirst($project->status)" /></dd></div>
            <div><dt>Association</dt><dd>{{ $project->association->name }}</dd></div>
            <div><dt>Classification</dt><dd>{{ $project->saadActivity?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Fisheries category</dt><dd>{{ $project->fisheriesCategory?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Commodity</dt><dd>{{ $project->commodity?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Program year</dt><dd>{{ $project->program_year }}</dd></div>
            <div><dt>Implementation start</dt><dd>{{ $project->implementation_start_date?->format('Y-m-d') ?? 'Not recorded' }}</dd></div>
            <div><dt>Implementation end</dt><dd>{{ $project->implementation_end_date?->format('Y-m-d') ?? 'Not recorded' }}</dd></div>
            <div><dt>Description</dt><dd>{{ $project->description ?? 'Not recorded' }}</dd></div>
            <div><dt>Project remarks</dt><dd>{{ $project->remarks ?? 'Not recorded' }}</dd></div>
            <div><dt>Archived at</dt><dd>{{ $project->archived_at?->toIso8601String() ?? 'Not archived' }}</dd></div>
        </dl>
        @include('projects.partials.location', ['row' => $locationRow])
        @if($project->isFinalized())
            <p class="notice">This project is permanently read-only. Reopening, editing, archival, and restoration are unavailable.</p>
            <p>{{ $project->status === 'terminated' ? 'Implementation was permanently discontinued.' : 'Implementation was successfully completed.' }}</p>
            @if($project->closed_at)
                <p>{{ $project->status === 'terminated' ? 'Terminated' : 'Completed' }} <time datetime="{{ $project->closed_at->toIso8601String() }}">{{ $project->closed_at->timezone('Asia/Manila')->format('M j, Y, g:i a T') }}</time></p>
                <p>Finalized by: {{ $project->closedBy?->display_name ?? 'Deleted or unavailable actor' }}</p>
                <h3>Final Remarks</h3><p style="white-space: pre-wrap; overflow-wrap: anywhere">{{ $project->closure_remarks }}</p>
            @else
                <p>Legacy completed project. No formal closure date, actor, or Closure Remarks were recorded.</p>
            @endif
            @if($closureAudit)<p><a class="text-button" href="{{ route('admin.audit-logs.show', $closureAudit->id) }}">{{ $project->status === 'terminated' ? 'View termination audit event' : 'View closure audit event' }}</a></p>@endif
        @endif
        @if($canClose)
            <button type="button" class="button button-primary" data-dialog="project-closure-modal" data-project-finalization-open="project-closure-modal">Complete Project</button>
            <button type="button" class="button button-danger-outline" data-dialog="project-termination-modal" data-project-finalization-open="project-termination-modal">Terminate Project</button>
        @endif
    </section>
    <div class="card form-section"><h2>Project resources</h2>
        <h3>Products</h3>
        @forelse($project->products as $product)<div class="repeat-row"><dl class="detail-grid">
            @foreach(['product_name' => 'Product Name', 'product_type' => 'Product Type', 'default_unit' => 'Default Unit', 'description' => 'Description'] as $field => $label)
                <div><dt>{{ $label }}</dt><dd>{{ filled($product->$field) ? $product->$field : 'Not recorded' }}</dd></div>
            @endforeach
            <div><dt>Active Status</dt><dd>{{ $product->is_active ? 'Active' : 'Inactive' }}</dd></div>
        </dl></div>@empty<p>No products recorded.</p>@endforelse
        <h3>Materials</h3>
        @forelse($project->materials as $material)<div class="repeat-row"><dl class="detail-grid">
            @foreach(['item_name' => 'Item Name', 'quantity_planned' => 'Planned Quantity', 'quantity_delivered' => 'Delivered Quantity', 'unit' => 'Unit', 'unit_cost' => 'Unit Cost', 'remarks' => 'Remarks'] as $field => $label)
                <div><dt>{{ $label }}</dt><dd>{{ filled($material->$field) ? $material->$field : 'Not recorded' }}</dd></div>
            @endforeach
            <div><dt>Delivery Status</dt><dd>{{ filled($material->delivery_status) ? ucwords(str_replace('_', ' ', $material->delivery_status)) : 'Not recorded' }}</dd></div>
            <div><dt>Delivery Date</dt><dd>{{ $material->delivery_date?->format('Y-m-d') ?? 'Not recorded' }}</dd></div>
        </dl></div>@empty<p>No materials recorded.</p>@endforelse
    </div>
    @foreach(['Monitoring history' => $monitoring, 'Training history' => $trainings, 'Program activity history' => $activities] as $heading => $history)
        <section class="card form-section"><h2>{{ $heading }}</h2>
            @if($history === null)<p>This history is unavailable under your existing access scope.</p>
            @else
                <ul>@forelse($history as $entry)
                    <li>@if($heading === 'Monitoring history')<a href="{{ route($prefix.'.monitoring.workspace', $entry) }}">{{ $entry->monitoring_date?->format('Y-m-d') }} — {{ $entry->year }} Q{{ $entry->quarter?->quarter_number }}</a> ({{ ucfirst(str_replace('_', ' ', $entry->project_status)) }})
                    @else<a href="{{ route($prefix.($heading === 'Training history' ? '.trainings.show' : '.program-activities.show'), $entry) }}">{{ $entry->title }}</a> — {{ ($heading === 'Training history' ? $entry->start_date : $entry->activity_date)?->format('Y-m-d') }} ({{ ucfirst($entry->status) }})@endif</li>
                @empty<li>No linked records recorded.</li>@endforelse</ul>
                <nav aria-label="{{ $heading }} pages">@if($history->previousPageUrl())<a class="button" href="{{ $history->previousPageUrl() }}">Previous</a>@endif @if($history->nextPageUrl())<a class="button" href="{{ $history->nextPageUrl() }}">Next</a>@endif</nav>
            @endif
        </section>
    @endforeach
    @if($canClose)
        @include('projects.partials.finalization-modal', ['outcome' => 'completed'])
        @include('projects.partials.finalization-modal', ['outcome' => 'terminated'])
    @endif
</section>
@endsection
