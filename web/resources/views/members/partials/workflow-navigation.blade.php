@php
    $workflow = app(\App\View\WorkflowNavigation::class);
    $workflowPrefix = str_starts_with($indexRoute, 'admin.') ? 'admin' : 'field-officer';
    $parentAssociation = $associations->firstWhere('id', $filters['association']);
    $workflowParents = $parentAssociation ? [['name' => $workflowPrefix.'.associations.show', 'parameters' => ['association' => $parentAssociation->id], 'label' => $parentAssociation->name]] : [];
    $workflowBack = $workflow->back(request(), $indexRoute, [], $workflowParents);
    $members->appends($workflow->carry(request()));
@endphp
@if($workflowBack['contextual'])
    <x-workflow-navigation :back="$workflowBack" current="Member register" />
@endif
