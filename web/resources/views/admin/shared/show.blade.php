<a class="back-link" href="#{{ $module['key'] }}"><x-icon name="back" />Back to {{ $module['title'] }}</a>
@include('partials.page-header', ['title' => $module['singular'].' Details', 'description' => 'Record details will appear here once connected.', 'eyebrow' => $module['title']])
<div class="detail-banner card"><span class="detail-avatar"><x-icon :name="$module['icon']" /></span><div><h2>{{ $module['record']['name'] ?? $module['record']['title'] ?? $module['record']['project'] ?? $module['record']['entity'] ?? 'No record selected' }}</h2><p>{{ $module['description'] }}</p></div>@if(isset($module['action']) && $module['key'] !== 'users')<a class="button button-primary" href="#{{ $module['key'] }}/edit">Edit {{ $module['singular'] }}</a>@else<span class="badge badge-neutral">{{ ($module['readonly'] ?? false) ? 'Read-only' : 'No account selected' }}</span>@endif</div>
@if($module['key'] === 'users')<div class="detail-actions"><button class="button" type="button" data-dialog="email-modal">Change Email</button><button class="button button-danger-outline" type="button" data-confirm="Disable the selected account?">Disable Account</button></div>@endif
@if(isset($module['sections']))
    @foreach($module['sections'] as $section)
        <x-form-section :title="$section['title']">
            @php($detailRows = $section['repeater'] ? ($module['record'][$section['repeater']] ?? []) : [$module['record']])
            @forelse($detailRows as $detailRow)
                <dl class="detail-grid">@foreach($section['fields'] as $field)<div><dt>{{ $field['label'] }}</dt><dd>{{ is_bool($detailRow[$field['name']] ?? null) ? ($detailRow[$field['name']] ? 'Yes' : 'No') : ($detailRow[$field['name']] ?? '—') }}</dd></div>@endforeach</dl>
            @empty<p class="muted">No records available.</p>@endforelse
        </x-form-section>
    @endforeach
    @if($module['key'] === 'trainings')<x-form-section title="Participants"><div class="table-scroll"><table class="data-table"><thead><tr><th scope="col">Member</th><th scope="col">Attendance</th></tr></thead><tbody>@foreach($module['record']['participants'] ?? [] as $participant)<tr><td>{{ $participant['name'] }}</td><td><x-status-badge :value="$participant['attendance']" /></td></tr>@endforeach</tbody></table></div></x-form-section>@endif
@else
    <x-form-section title="{{ $module['key'] === 'members' ? 'Official member profile' : 'Event details' }}"><dl class="detail-grid">@foreach(($module['details'] ?? $module['columns']) as $key => $label)<div><dt>{{ $label }}</dt><dd>@if(is_array($module['record'][$key] ?? null))<div class="tags">@foreach($module['record'][$key] as $tag)<x-status-badge :value="$tag" />@endforeach</div>@else{{ $module['record'][$key] ?? '—' }}@endif</dd></div>@endforeach</dl></x-form-section>
@endif
