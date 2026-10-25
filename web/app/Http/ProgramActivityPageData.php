<?php

namespace App\Http;

use App\Http\Requests\ProgramDirectoryRequest;
use App\Models\ProgramActivityRecord;
use App\Services\AssociationLifecycleService;
use App\Services\ProgramDirectoryQueryService;
use App\Services\ProgramEditorQueryService;

final class ProgramActivityPageData
{
    public function indexData(
        ProgramDirectoryRequest $request,
        ProgramDirectoryQueryService $directory,
        ProgramEditorQueryService $editor,
        string $prefix
    ): array {
        $actor = $request->attributes->get('current_user');
        $filters = $request->filters();
        $summary = $directory->summary($actor, 'program-activities');
        $records = $directory->records($actor, 'program-activities', $filters, $summary);
        $rows = $records->getCollection()->map(fn ($record) => $this->row($record, $prefix, $actor->id));
        $showEditor = $request->validated('editor') === 'create'
            || ($request->old('program_activity_form') === 'editor'
                && ! $request->old('program_activity_record_id'));
        $editorData = $showEditor ? $editor->activityData($request) : [];

        return compact('prefix', 'filters', 'summary', 'records', 'rows', 'showEditor') + $editorData;
    }

    public function row(ProgramActivityRecord $record, string $prefix, string $actorId): array
    {
        $association = $record->association;
        $project = $record->relatedProject;
        $lifecycle = app(AssociationLifecycleService::class);
        $parentsOperational = (! $record->association_id || ($association && $lifecycle->isOperational($association)))
            && (! $project || ($project->association && $lifecycle->isOperational($project->association)));
        $finalized = $project?->isFinalized() ?? false;

        return [
            'id' => $record->id,
            'project_finalized' => $finalized,
            'association_archived' => $association?->archived_at !== null,
            'parents_operational' => $parentsOperational,
            'can_manage' => $parentsOperational && ! $finalized,
            'can_view_project' => $project?->association && ($prefix === 'admin'
                || ($project->association->archived_at === null && $project->association->primary_field_officer_id === $actorId)),
            'saad_activity_id' => $record->saad_activity_id,
            'activity' => $record->saadActivity?->name ?? 'Not recorded',
            'activity_code' => $record->saadActivity?->code,
            'association_id' => $record->association_id,
            'association' => $record->association_id ? ($association?->name ?? 'Association unavailable') : 'Program-wide',
            'municipality' => $association?->locationBarangay()?->municipality?->name ?? 'Not recorded',
            'barangay' => $association?->locationBarangay()?->name ?? 'Not recorded',
            'related_project_id' => $record->related_project_id,
            'related_project' => $project?->title ?? 'No linked project',
            'title' => $record->title,
            'description' => $record->description,
            'program_year' => $record->program_year,
            'activity_date' => $record->activity_date?->format('Y-m-d'),
            'venue' => $record->venue,
            'status' => ucfirst($record->status),
            'status_value' => $record->status,
            'conducted_by' => $record->conducted_by,
            'remarks' => $record->remarks,
            'archived' => $record->archived_at !== null,
            'update_url' => route($prefix.'.program-activities.update', $record),
            'archive_url' => route($prefix.'.program-activities.archive', $record),
            'unarchive_url' => route($prefix.'.program-activities.unarchive', $record),
        ];
    }
}
