@php
    $isEditing = $mode === 'edit';
    $values = $isEditing ? $module['record'] : [];
    $formPrefix = $module['key'].'-'.$mode;
@endphp
<a class="back-link" href="#{{ $module['key'] }}"><x-icon name="back" />Back to {{ $module['title'] }}</a>
@include('partials.page-header', ['title' => $isEditing ? 'Edit '.$module['singular'] : $module['action'], 'description' => 'Complete the information below. Fields marked * are required.', 'eyebrow' => $module['title']])
@if($module['key'] === 'users')<div class="notice"><x-icon name="info" /><span>Invitations are unavailable in this form. Use Invite User in User Management.</span></div>@endif
<form class="record-form" data-form onsubmit="return false;" data-module="{{ $module['key'] }}">
    <div class="form-main">
        @foreach($module['sections'] as $formSection)
            <x-form-section :title="$formSection['title']" :number="$loop->iteration">
                @if($formSection['title'] === 'SAAD classification')<p class="section-help">SAAD classification options are unavailable in this form.</p>@endif
                @if($formSection['repeater'])
                    <div data-repeater="{{ $formSection['repeater'] }}">
                        <div data-repeater-rows>
                            @foreach(($values[$formSection['repeater']] ?? [[]]) as $rowIndex => $rowValues)
                                @include('admin.shared.repeater-row', ['repeaterSection' => $formSection, 'formPrefix' => $formPrefix, 'rowIndex' => $rowIndex, 'rowValues' => $rowValues])
                            @endforeach
                        </div>
                        <template data-row-template>@include('admin.shared.repeater-row', ['repeaterSection' => $formSection, 'formPrefix' => $formPrefix, 'rowIndex' => '__INDEX__', 'rowValues' => []])</template>
                        <button type="button" class="button button-outline" data-add-row><x-icon name="plus" />{{ $formSection['button'] }}</button>
                    </div>
                @else
                    <div class="form-grid">@foreach($formSection['fields'] as $field)<x-field :field="$field" :prefix="$formPrefix" :value="$values[$field['name']] ?? ''" />@endforeach</div>
                @endif
            </x-form-section>
        @endforeach
        @if($module['key'] === 'trainings')@include('admin.trainings.participants', ['mode' => $mode, 'participants' => $values['participants'] ?? []])@endif
        <div class="form-actions"><a href="#{{ $module['key'] }}" class="button">Cancel</a><button type="button" disabled class="button button-primary"><x-icon name="save" />{{ $module['key'] === 'users' ? 'Invite User' : ($isEditing ? 'Save Changes' : 'Save') }}</button></div>
    </div>
    <aside class="form-aside"><div class="card"><span class="stat-icon"><x-icon :name="$module['icon']" /></span><h3>{{ $module['singular'] }} information</h3><p>{{ $module['description'] }}</p><hr><p class="muted">Saving is unavailable in this form. Return to the module register to manage records.</p></div></aside>
</form>
