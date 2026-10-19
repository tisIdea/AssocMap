@include('monitoring.partials.details', ['details' => [
    'Issues Encountered (quarterly legacy)' => $record->issues_encountered,
    'Actions Taken (quarterly legacy)' => $record->actions_taken,
    'Overall Remarks (quarterly legacy)' => $record->overall_remarks,
]])
