@if(request()->routeIs('admin.members.index') && !isset($requestedModule))
    @include('dashboard.index')
@else
    @include('admin.shared.index')
    @if(request()->routeIs('admin.members.index') && $errors->any())
        <div class="notice notice-error" role="alert" data-member-validation><x-icon name="info" /><div>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></div>
    @endif
    <x-modal id="member-modal" title="Member Details">
        <dl class="detail-grid">
            @foreach($module['details'] as $key => $label)
                <div @if(in_array($key, ['position_other_text', 'archived_at'])) data-member-optional="{{ $key }}" hidden @endif>
                    <dt>{{ $label }}</dt><dd data-member-detail="{{ $key }}">—</dd>
                </div>
            @endforeach
        </dl>
    </x-modal>
@endif
