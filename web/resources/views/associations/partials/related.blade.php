<div class="table-scroll" role="region" aria-label="Related {{ $section }}" tabindex="0">
    <table class="data-table association-table"><caption class="sr-only">{{ ucfirst($section) }} connected to {{ $association->name }}.</caption>
        <thead><tr>
        @if($section === 'members')<th scope="col">Member</th><th scope="col">Position</th><th scope="col">Status</th><th scope="col">Open</th>
        @elseif($section === 'registrations')<th scope="col">Agency</th><th scope="col">Registration number</th><th scope="col">Registration date</th><th scope="col">Expiration</th><th scope="col">Primary</th>
        @else<th scope="col">{{ $section === 'activities' ? 'Program Activity' : ucfirst(rtrim($section, 's')) }}</th><th scope="col">Status</th>@if($section !== 'projects')<th scope="col">Date</th>@endif<th scope="col">Open</th>@endif
        </tr></thead><tbody>
        @forelse($related as $item)
            <tr>
            @if($section === 'members')
                <th scope="row">@include('associations.partials.person-name', ['person' => $item])</th><td>{{ $item->position?->name ?? 'Position unavailable' }}</td><td><x-status-badge :value="ucfirst($item->member_status)" />@if($item->archived_at)<span class="badge badge-neutral">Archived</span>@endif</td>
                <td><a class="text-button" href="{{ route($prefix.'.members.index', ['association' => $prefix === 'admin' ? $association->name : $association->id, 'status' => $item->archived_at ? 'archived' : $item->member_status]).($prefix === 'admin' ? '#members' : '') }}">Open member register</a></td>
            @elseif($section === 'registrations')
                <th scope="row">{{ $item->agency?->name ?? 'Agency unavailable' }}<small>{{ $item->agency?->registration_kind }}</small>
                    @if($prefix === 'admin' && $item->agency?->registration_kind === 'accreditation' && $item->agency?->is_active && $association->status === 'active' && !$association->archived_at)
                        <a class="text-button" href="{{ route('admin.associations.index', ['editor' => $association->id, 'accreditation' => $item->id]) }}">Update Accreditation</a>
                    @endif
                </th><td>{{ $item->registration_number }}@if($prefix === 'admin' && filled($item->remarks))<small>{{ $item->remarks }}</small>@endif</td><td>{{ $item->registration_date?->format('M d, Y') ?? 'Not recorded' }}</td><td>{{ $item->expiration_date?->format('M d, Y') ?? 'Not recorded' }}</td><td>{{ $item->is_primary ? 'Yes' : 'No' }}</td>
            @else
                @php($recordRoute = $section === 'activities' ? 'program-activities' : $section)
                <th scope="row"><a href="{{ route($prefix.'.'.$recordRoute.'.show', $item->id) }}">{{ $item->title }}</a></th><td><x-status-badge :value="ucfirst($item->status)" />@if($item->archived_at)<span class="badge badge-neutral">Archived</span>@endif</td>
                @if($section !== 'projects')<td>{{ ($section === 'trainings' ? $item->start_date : $item->activity_date)?->format('M d, Y') ?? 'Not recorded' }}</td>@endif
                <td><a class="text-button" href="{{ route($prefix.'.'.$recordRoute.'.show', $item->id) }}"><x-icon name="eye" />View</a></td>
            @endif
            </tr>
        @empty<tr><td colspan="5"><x-empty-state title="No records on this page" message="No matching related records are available on this page. Historical records are retained according to existing access rules." /></td></tr>@endforelse
        </tbody>
    </table>
</div>
