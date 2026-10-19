@extends($monitoringLayout)
@section('content')
<div class="monitoring-management" data-workspace-module="monitoring" data-workspace-title="Monitoring">
    @include('monitoring.partials.context')
    <section class="card monitoring-section" aria-labelledby="monitoring-section-title">
        <div class="monitoring-section-heading">
            <div><h2 id="monitoring-section-title">{{ $section === 'overview' ? 'Quarterly Notes' : ucfirst($section).' Register' }}</h2>
                <p>{{ ['overview' => 'Original quarterly notes retained with this record.', 'production' => 'Recorded output by project product and unit of measurement.', 'income' => 'Saved monthly or quarterly gross income for each production product.', 'materials' => 'Resource condition and maintenance observations for this quarter.'][$section] }}</p>
            </div>
            @if($canManage && $section !== 'overview')<a class="button button-primary" href="{{ route($monitoringPrefix.'.section.edit', [$record, $section]) }}"><x-icon name="pencil" />Manage {{ ucfirst($section) }}</a>@endif
        </div>
        @include('monitoring.partials.project-shortcuts')
        <div class="monitoring-section-body">
            @include('monitoring.partials.'.$section)
        </div>
    </section>
</div>
@endsection
