@props(['module', 'rows' => []])
<div class="table-scroll" role="region" aria-label="{{ $module['title'] }} records" tabindex="0"><table class="data-table"><caption class="sr-only">{{ $module['title'] }} records</caption><thead><tr>@foreach($module['columns'] as $key => $label)<th scope="col" @if($module['key'] === 'areas' && $key === 'municipality') data-area-parent-column hidden @endif>{{ $label }}</th>@endforeach<th scope="col">{{ $module['key'] === 'audit-logs' ? 'View Details' : 'Actions' }}</th></tr></thead><tbody>
    @foreach($rows as $row)
        <tr @if($module['key'] === 'areas') data-area-record="{{ json_encode($row) }}" data-area-type="{{ $row['area_type'] ?? '' }}" data-name="{{ $row['name'] ?? '' }}" data-municipality="{{ $row['municipality'] ?? '' }}" data-source="{{ $row['source'] ?? '' }}" data-status="{{ $row['status'] ?? '' }}" data-created-at="{{ $row['created_at'] ?? '' }}" @endif>
            @foreach($module['columns'] as $key => $label)
                <td class="{{ $loop->first ? 'primary-cell' : '' }}" @if($module['key'] === 'areas' && $key === 'municipality') data-area-parent-column hidden @endif>
                    @if(is_array($row[$key] ?? null))<div class="tags">@foreach($row[$key] as $tag)<x-status-badge :value="$tag" />@endforeach</div>
                    @elseif(in_array($key, ['status', 'project_status', 'site_validated', 'action']) || ($module['key'] === 'areas' && in_array($key, ['area_type', 'source'])))<x-status-badge :value="$row[$key] ?? '—'" />
                    @else{{ $row[$key] ?? '—' }}@endif
                </td>
            @endforeach
            <td><div class="row-actions">
                @if($module['key'] === 'areas')
                    <button class="text-button" type="button" data-area-view>View</button>
                    <button class="text-button" type="button" data-area-edit>Edit</button>
                    <button class="text-button text-danger" type="button" @disabled(($row['status'] ?? '') === 'Inactive') data-area-deactivate data-confirm="Deactivate {{ $row['name'] }}? Existing relationships will be preserved." data-confirm-title="Deactivate Area" data-confirm-label="Deactivate">Deactivate</button>
                @else<a class="text-button" href="#{{ $module['key'] }}/show">View</a>@endif
                @if(isset($module['action']) && $module['key'] !== 'users')<a class="text-button" href="#{{ $module['key'] }}/edit">Edit</a>@endif
                @if($module['key'] === 'users')
                    <details class="action-menu"><summary aria-label="More actions for {{ $row['name'] }}">•••</summary><div><button type="button" data-dialog="email-modal">Change Email</button><button type="button" data-confirm="{{ ($row['status'] ?? '') === 'Active' ? 'Disable' : 'Enable' }} the selected account?">{{ ($row['status'] ?? '') === 'Active' ? 'Disable Account' : 'Enable Account' }}</button></div></details>
                @endif
            </div></td>
        </tr>
    @endforeach
</tbody></table></div>
<div data-empty @if(count($rows)) hidden @endif>@if($module['key'] === 'areas')<x-empty-state title="No records found." message="" />@else<x-empty-state :title="$module['key'] === 'members' ? 'No members available.' : 'No '.strtolower($module['title']).' records found.'" />@endif</div>
