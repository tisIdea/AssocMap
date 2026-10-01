@php($editing = $mode === 'edit')
@php($values = $editing ? $module['record'] : [])
<a class="back-link" href="#{{ $module['key'] }}"><x-icon name="back" />Back to {{ $module['title'] }}</a>
@include('partials.page-header', ['title' => $editing ? 'Edit '.$module['singular'] : $module['action'], 'description' => 'Complete the information below. Fields marked * are required.', 'eyebrow' => $module['title']])
@if($module['key'] === 'users')<div class="notice"><x-icon name="info" /><span>Account invitations are not connected yet.</span></div>@endif
<form class="record-form" data-form onsubmit="return false;" data-module="{{ $module['key'] }}">
    <div class="form-main">
        @foreach($module['sections'] as $section)
            <x-form-section :title="$section['title']" :number="$loop->iteration">
                @if($section['title'] === 'SAAD classification')<p class="section-help">Choose a component, sub-component, and activity. Options will appear once connected.</p>@endif
                @if($section['repeater'])
                    <div data-repeater="{{ $section['repeater'] }}">
                        <div data-repeater-rows>
                            @foreach(($values[$section['repeater']] ?? [[]]) as $rowIndex => $rowValues)
                                @include('admin.shared.repeater-row', ['rowIndex' => $rowIndex, 'rowValues' => $rowValues])
                            @endforeach
                        </div>
                        <template data-row-template>@include('admin.shared.repeater-row', ['rowIndex' => '__INDEX__', 'rowValues' => []])</template>
                        <button type="button" class="button button-outline" data-add-row><x-icon name="plus" />{{ $section['button'] }}</button>
                    </div>
                @else
                    <div class="form-grid">@foreach($section['fields'] as $field)<x-field :field="$field" :prefix="$module['key'].'-'.$mode" :value="$values[$field['name']] ?? ''" />@endforeach</div>
                @endif
            </x-form-section>
        @endforeach
        @if($module['key'] === 'trainings')@include('admin.trainings.participants', ['mode' => $mode, 'participants' => $values['participants'] ?? []])@endif
        <div class="form-actions"><a href="#{{ $module['key'] }}" class="button">Cancel</a><button type="button" disabled class="button button-primary"><x-icon name="check" />{{ $module['key'] === 'users' ? 'Invite User' : ($editing ? 'Save Changes' : 'Save') }}</button></div>
    </div>
    <aside class="form-aside"><div class="card"><span class="stat-icon"><x-icon :name="$module['icon']" /></span><h3>{{ $module['singular'] }} information</h3><p>{{ $module['description'] }}</p><hr><p class="muted">Saving is not connected yet.</p></div></aside>
</form>
