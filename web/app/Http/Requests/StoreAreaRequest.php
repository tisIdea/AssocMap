<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->attributes->get('current_user');

        return $user?->canAccessApplication() && $user->role?->code === 'system_admin';
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
            'municipality_id' => [
                'bail',
                'required',
                'uuid',
                Rule::exists('municipalities', 'id')->where('is_active', true),
            ],
            'barangay_id' => [
                'bail',
                'required',
                'uuid',
                Rule::exists('barangays', 'id')
                    ->where('is_active', true)
                    ->where('municipality_id', Str::isUuid($this->input('municipality_id')) ? $this->input('municipality_id') : null),
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
            'municipality_id.required' => 'Please select a municipality / city.',
            'municipality_id.exists' => 'The selected municipality / city is invalid or inactive.',
            'barangay_id.required' => 'Please select a barangay.',
            'barangay_id.exists' => 'Select an active barangay in the selected municipality / city.',
            'address.required' => 'Area address is required.',
        ];
    }
}
