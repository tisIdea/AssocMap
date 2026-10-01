<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name)
                ? trim($this->name)
                : $this->name,

            'psgc_code' => filled($this->psgc_code)
                ? trim($this->psgc_code)
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'psgc_code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('municipalities', 'psgc_code'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Municipality name is required.',
            'name.max' => 'Municipality name must not exceed 150 characters.',
            'psgc_code.max' => 'PSGC code must not exceed 20 characters.',
            'psgc_code.unique' => 'This PSGC code is already assigned to another municipality.',
        ];
    }
}