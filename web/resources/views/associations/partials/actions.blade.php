<details class="association-actions" data-user-actions>
    <summary aria-label="Actions for {{ $association->name }}"><x-icon name="ellipsis" /></summary>
    <div class="association-actions-panel" data-user-actions-panel>
        <a href="{{ route($prefix.'.associations.show', $recordParameters) }}"><x-icon name="eye" />View Association Details</a>
        <a href="{{ route($prefix.'.association-officers.show', $association->id) }}"><x-icon name="users" />{{ $association->archived_at || $association->status !== 'active' ? 'View officers' : 'Manage officers' }}</a>
        @if($prefix === 'admin' && ($allowRecordChanges ?? false))
            @if($association->archived_at)
                <button type="button" data-association-unarchive data-unarchive-url="{{ route('admin.associations.unarchive', $association->id) }}" data-confirm="Unarchive {{ $association->name }}? Its existing Active/Inactive status will be preserved." data-confirm-title="Unarchive Association" data-confirm-label="Unarchive"><x-icon name="archive-restore" />Unarchive</button>
            @else
                <a href="{{ route('admin.associations.index', $filters + ['page' => $associations->currentPage(), 'editor' => $association->id]) }}"><x-icon name="pencil" />Edit Association</a>
                <button type="button" data-association-archive data-archive-url="{{ route('admin.associations.archive', $association->id) }}" data-confirm="Archive {{ $association->name }}? The record and existing relationships will be preserved." data-confirm-title="Archive Association" data-confirm-label="Archive"><x-icon name="archive" />Archive Association</button>
            @endif
        @endif
    </div>
</details>
