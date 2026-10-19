<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GisParentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['association', 'project', 'municipality', 'barangay', 'area'])],
            'q' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'uuid'],
            'association_id' => ['nullable', 'uuid'], 'municipality_id' => ['nullable', 'uuid'],
            'barangay_id' => ['nullable', 'uuid'], 'area_id' => ['nullable', 'uuid'],
        ];
    }
}
