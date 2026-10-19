<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBarangayRequest extends FormRequest
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

            'psgc_code' => is_string($this->psgc_code)
                ? (trim($this->psgc_code) === '' ? null : trim($this->psgc_code))
                : $this->psgc_code,
        ]);
    }

    public function rules(): array
    {
        return [
            'municipality_id' => [
                'required',
                'uuid',
                Rule::exists('municipalities', 'id')
                    ->where(fn ($query) => $query->where(function ($query) {
                        $query->where('is_active', true);
                        if ($barangay = $this->route('barangay')) {
                            $query->orWhere('id', $barangay->municipality_id);
                        }
                    })),
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'psgc_code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('barangays', 'psgc_code')->ignore($this->route('barangay')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'municipality_id.required' => 'Please select a municipality or city.',
            'municipality_id.exists' => 'The selected municipality or city is invalid or inactive.',
            'name.required' => 'Barangay name is required.',
            'name.max' => 'Barangay name must not exceed 150 characters.',
            'psgc_code.max' => 'PSGC code must not exceed 20 characters.',
            'psgc_code.unique' => 'This PSGC code is already assigned to another barangay.',
        ];
    }
}
