@php($editing = $mode === 'edit')
@php($values = $editing ? $module['record'] : [])
<a class="back-link" href="#{{ $module['key'] }}"><x-icon name="back" />Back to {{ $module['title'] }}</a>
@include('partials.page-header', ['title' => $editing ? 'Edit '.$module['singular'] : $module['action'], 'description' => 'Complete the information below. Fields marked * are required in this preview.', 'eyebrow' => $module['title']])
@if($module['key'] === 'users')<div class="notice"><x-icon name="info" /><span>Invitation preview. Password setup will be handled by the future authentication service.</span></div>@endif
<form class="record-form" data-preview-form data-module="{{ $module['key'] }}">
    <div class="form-main">
        @foreach($module['sections'] as $section)
            <x-form-section :title="$section['title']" :number="$loop->iteration">
                @if($section['title'] === 'SAAD classification')<p class="section-help">Choose a component, sub-component, and activity. The options are for interface demonstration.</p>@endif
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
        <div class="form-actions"><a href="#{{ $module['key'] }}" class="button">Cancel</a><button type="submit" class="button button-primary"><x-icon name="check" />{{ $module['key'] === 'users' ? 'Preview Invitation' : 'Preview '.($editing ? 'Changes' : 'Save') }}</button></div>
    </div>
    <aside class="form-aside"><div class="card"><span class="stat-icon"><x-icon :name="$module['icon']" /></span><h3>{{ $module['singular'] }} information</h3><p>{{ $module['description'] }}</p><hr><p class="muted">This is an interface preview. Submitting checks the form locally; it does not save or send information.</p></div></aside>
</form>
