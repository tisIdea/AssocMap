<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssociationRequest extends FormRequest
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

            'acronym' => filled($this->acronym)
                ? trim($this->acronym)
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'acronym' => [
                'nullable',
                'string',
                'max:50',
            ],

            'area_id' => [
                'required',
                'uuid',
                Rule::exists('areas', 'id')
                    ->where('is_active', true),
            ],

            'saad_phase2_join_date' => [
                'required',
                'date',
            ],

            'primary_field_officer_id' => [
                'required',
                'uuid',
                Rule::exists('user_profiles', 'id')
                    ->where('is_active', true),
            ],

            'status' => [
                'required',
                Rule::in(['active', 'inactive']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Association name is required.',
            'name.max' => 'Association name must not exceed 255 characters.',
            'acronym.max' => 'Acronym must not exceed 50 characters.',

            'area_id.required' => 'Please select a specific area.',
            'area_id.exists' => 'The selected area is invalid or inactive.',

            'saad_phase2_join_date.required' => 'SAAD Phase II join date is required.',
            'saad_phase2_join_date.date' => 'Please provide a valid joining date.',

            'primary_field_officer_id.required' => 'Please select a Field Officer.',
            'primary_field_officer_id.exists' => 'The selected Field Officer is invalid or inactive.',

            'status.required' => 'Status is required.',
            'status.in' => 'Status must be Active or Inactive.',
        ];
    }
}