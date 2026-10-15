@props(['module', 'rows' => []])
<div class="table-scroll" role="region" aria-label="{{ $module['title'] }} records" tabindex="0"><table class="data-table"><caption class="sr-only">{{ $module['title'] }} records</caption><thead><tr>@foreach($module['columns'] as $key => $label)<th scope="col" @if($module['key'] === 'areas') data-area-column="{{ $key }}" @if(in_array($key, ['municipality', 'barangay'])) hidden @endif @endif>{{ $label }}</th>@endforeach<th scope="col">{{ $module['key'] === 'audit-logs' ? 'View Details' : 'Actions' }}</th></tr></thead><tbody>
    @foreach($rows as $row)
        <tr @if($module['key'] === 'areas') data-area-record="{{ json_encode($row) }}" data-area-type="{{ $row['area_type'] ?? '' }}" data-name="{{ $row['name'] ?? '' }}" data-barangay="{{ $row['barangay'] ?? '' }}" data-barangay-id="{{ $row['barangay_id'] ?? '' }}" data-municipality="{{ $row['municipality'] ?? '' }}" data-source="{{ $row['source'] ?? '' }}" data-status="{{ $row['status'] ?? '' }}" data-created-at="{{ $row['created_at'] ?? '' }}" @endif>
            @foreach($module['columns'] as $key => $label)
                <td class="{{ $loop->first ? 'primary-cell' : '' }}" @if($module['key'] === 'areas') data-area-column="{{ $key }}" @if(in_array($key, ['municipality', 'barangay'])) hidden @endif @endif>
                    @if(is_array($row[$key] ?? null))<div class="tags">@foreach($row[$key] as $tag)<x-status-badge :value="$tag" />@endforeach</div>
                    @elseif(in_array($key, ['status', 'project_status', 'site_validated', 'action']) || ($module['key'] === 'areas' && in_array($key, ['area_type', 'source'])))<x-status-badge :value="$row[$key] ?? '—'" />
                        @if($module['key'] === 'associations' && $key === 'status' && !empty($row['archived_at']))<span class="badge badge-neutral">Archived</span>@endif
                        @if($module['key'] === 'members' && $key === 'status' && !empty($row['archived_at']))<span class="badge badge-neutral">Archived</span>@endif
                    @else{{ $row[$key] ?? '—' }}@endif
                </td>
            @endforeach
            <td><div class="row-actions">
                @if($module['key'] === 'areas')
                    <button class="text-button" type="button" data-area-view>View</button>
                    <button class="text-button" type="button" @disabled(empty($row['update_url'])) @if(empty($row['update_url'])) title="Editing is not available for this record type." @endif data-area-edit>Edit</button>
                    <button class="text-button text-danger" type="button" @disabled(($row['status'] ?? '') === 'Inactive' || empty($row['deactivate_url'])) @if(empty($row['deactivate_url'])) title="Deactivation is not available for this record type." @endif data-area-deactivate data-confirm="Deactivate {{ $row['name'] }}? Existing relationships will be preserved." data-confirm-title="Deactivate Area" data-confirm-label="Deactivate">Deactivate</button>
                @elseif($module['key'] === 'associations')
                    <button class="text-button" type="button" data-association-view data-association-record="{{ json_encode($row) }}" data-dialog="association-modal">View</button>
                    @if(!empty($row['archived_at']))
                    <button class="text-button" type="button" data-association-unarchive data-unarchive-url="{{ $row['unarchive_url'] ?? '' }}" @disabled(empty($row['unarchive_url'])) data-confirm="Unarchive {{ $row['name'] }}? Its existing Active/Inactive status will be preserved." data-confirm-title="Unarchive Association" data-confirm-label="Unarchive">Unarchive</button>
                    @else
                    <button class="text-button" type="button" data-association-edit data-dialog="association-editor" @disabled(empty($row['update_url']))>Edit</button>
                    <button class="text-button text-danger" type="button" data-association-archive data-archive-url="{{ $row['archive_url'] ?? '' }}" @disabled(empty($row['archive_url'])) data-confirm="Archive {{ $row['name'] }}? The record will be preserved for historical purposes." data-confirm-title="Archive Association" data-confirm-label="Archive">Archive</button>
                    @endif
                @elseif($module['key'] === 'members')
                    <button class="text-button" type="button" data-member-view data-member-record="{{ json_encode($row) }}" data-dialog="member-modal">View</button>
                @else<a class="text-button" href="#{{ $module['key'] }}/show">View</a>@endif
                @if(isset($module['action']) && !in_array($module['key'], ['users', 'associations', 'members']))<a class="text-button" href="#{{ $module['key'] }}/edit">Edit</a>@endif
                @if($module['key'] === 'users')
                    <details class="action-menu"><summary aria-label="More actions for {{ $row['name'] }}">•••</summary><div><button type="button" data-dialog="email-modal">Change Email</button><button type="button" data-confirm="{{ ($row['status'] ?? '') === 'Active' ? 'Disable' : 'Enable' }} the selected account?">{{ ($row['status'] ?? '') === 'Active' ? 'Disable Account' : 'Enable Account' }}</button></div></details>
                @endif
            </div></td>
        </tr>
    @endforeach
</tbody></table></div>
<div data-empty @if(count($rows)) hidden @endif>@if($module['key'] === 'areas')<x-empty-state title="No records found." message="" />@else<x-empty-state :title="$module['key'] === 'members' ? 'No members available.' : 'No '.strtolower($module['title']).' records found.'" />@endif</div>
