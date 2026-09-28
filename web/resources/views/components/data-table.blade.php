@props(['module', 'rows' => []])
<div class="table-scroll" role="region" aria-label="{{ $module['title'] }} records" tabindex="0"><table class="data-table"><caption class="sr-only">{{ $module['title'] }} — fictional preview records</caption><thead><tr>@foreach($module['columns'] as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">{{ $module['key'] === 'audit-logs' ? 'View Details' : 'Actions' }}</th></tr></thead><tbody>
    @foreach($rows as $row)
        <tr data-record="{{ json_encode($row) }}">
            @foreach($module['columns'] as $key => $label)
                <td class="{{ $loop->first ? 'primary-cell' : '' }}">
                    @if(is_array($row[$key] ?? null))<div class="tags">@foreach($row[$key] as $tag)<x-status-badge :value="$tag" />@endforeach</div>
                    @elseif(in_array($key, ['status', 'project_status', 'site_validated', 'action']) || ($module['key'] === 'areas' && in_array($key, ['area_type', 'source'])))<x-status-badge :value="$row[$key] ?? '—'" />
                    @else{{ $row[$key] ?? '—' }}@endif
                    @if($loop->first)<small>Fictional sample</small>@endif
                </td>
            @endforeach
            <td><div class="row-actions">
                @if($module['key'] === 'areas')
                    <button class="text-button" type="button" data-area-view>View</button>
                    <button class="text-button" type="button" data-area-edit>Edit</button>
                    <button class="text-button text-danger" type="button" data-confirm="Are you sure you want to deactivate {{ $row['name'] }}? Existing or future records linked to this area should remain preserved." data-confirm-title="Deactivate Area" data-confirm-label="Deactivate">Deactivate</button>
                @else<a class="text-button" href="#{{ $module['key'] }}/show">View</a>@endif
                @if(isset($module['action']) && $module['key'] !== 'users')<a class="text-button" href="#{{ $module['key'] }}/edit">Edit</a>@endif
                @if($module['key'] === 'users')
                    <details class="action-menu"><summary aria-label="More actions for {{ $row['name'] }}">•••</summary><div><button type="button" data-dialog="email-modal">Change Email</button><button type="button" data-confirm="{{ ($row['status'] ?? '') === 'Active' ? 'Disable' : 'Enable' }} the demo account?">{{ ($row['status'] ?? '') === 'Active' ? 'Disable Account' : 'Enable Account' }}</button></div></details>
                @endif
            </div></td>
        </tr>
    @endforeach
</tbody></table></div>
<div data-empty @if(count($rows)) hidden @endif><x-empty-state :title="$module['key'] === 'members' ? 'No members match the current filters.' : 'No '.strtolower($module['title']).' records found.'" /></div>
