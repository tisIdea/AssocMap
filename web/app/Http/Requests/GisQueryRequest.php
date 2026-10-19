<?php

namespace App\Http\Requests;

use App\Models\GisLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GisQueryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in(['map', 'list', 'overview'])], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'q' => ['nullable', 'string', 'max:100'], 'location_type' => ['nullable', Rule::in(GisLocation::TYPES)],
            'publication' => ['nullable', Rule::in(['published', 'unpublished'])],
            'lifecycle' => ['nullable', Rule::in(['active', 'archived'])],
            'association_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'],
            'municipality_id' => ['nullable', 'uuid'], 'barangay_id' => ['nullable', 'uuid'], 'area_id' => ['nullable', 'uuid'],
            'association_location_id' => ['nullable', 'uuid'],
            'bbox' => ['required_if:mode,map', 'array', 'size:4'],
            'bbox.0' => ['required_with:bbox', 'numeric', 'between:-180,180'], 'bbox.1' => ['required_with:bbox', 'numeric', 'between:-90,90'],
            'bbox.2' => ['required_with:bbox', 'numeric', 'between:-180,180'], 'bbox.3' => ['required_with:bbox', 'numeric', 'between:-90,90'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) return;
            $bbox = $this->input('bbox');
            if (is_array($bbox) && (! array_is_list($bbox) || $bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3])) {
                $validator->errors()->add('bbox', 'Use west, south, east, north bounds without wrapping across the date line.');
            }
            $user = $this->attributes->get('current_user');
            if ($user === null) {
                foreach (['association_id', 'project_id', 'municipality_id', 'barangay_id', 'area_id', 'publication', 'lifecycle'] as $field) {
                    if ($this->filled($field)) $validator->errors()->add($field, 'This filter is unavailable in the public viewer.');
                }
            } elseif ($user->role->code === 'association_account' && $this->input('lifecycle') === 'archived') {
                $validator->errors()->add('lifecycle', 'Archived GIS records are unavailable in this viewer.');
            }
        }];
    }
}
