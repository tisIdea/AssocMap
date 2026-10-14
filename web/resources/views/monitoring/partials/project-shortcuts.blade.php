@if($record && $canManage && in_array($section, ['production', 'materials'], true)
    && !$record->project->archived_at && $record->project->association
    && !$record->project->association->archived_at && $record->project->association->status === 'active')
    <div class="monitoring-actions">
        <a class="button" href="{{ route($monitoringPrefix === 'admin.monitoring' ? 'admin.projects.index' : 'field-officer.projects.index', [
            'from_monitoring' => 1, 'project_id' => $record->project_id,
            'resource_section' => $section === 'production' ? 'products' : 'materials',
            'monitoring_record' => $record->id,
        ]) }}">{{ $section === 'production' ? 'Add Project Product' : 'Add Project Material' }}</a>
    </div>
    @if(request()->routeIs($monitoringPrefix.'.section.edit'))<p class="field-hint">Save your Monitoring changes before opening Project Management.</p>@endif
@endif
