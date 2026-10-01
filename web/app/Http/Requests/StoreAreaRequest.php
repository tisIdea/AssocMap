<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'address' => is_string($this->address)
                ? trim($this->address)
                : $this->address,
        ]);
    }

    public function rules(): array
    {
        return [
            'barangay_id' => [
                'required',
                'uuid',
                Rule::exists('barangays', 'id')
                    ->where('is_active', true),
            ],

            'address' => [
                'required',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'barangay_id.required' => 'Please select a barangay.',
            'barangay_id.exists' => 'The selected barangay is invalid or inactive.',
            'address.required' => 'Area address is required.',
        ];
    }
}