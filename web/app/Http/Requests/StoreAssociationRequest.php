<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssociationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->attributes->get('current_user');

        return $user?->canAccessApplication() && $user->role?->code === 'system_admin';
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name)
                ? trim($this->name)
                : $this->name,

            'acronym' => is_string($this->acronym)
                ? (filled($this->acronym) ? trim($this->acronym) : null)
                : $this->acronym,
        ]);
        if (is_array($this->input('registration'))) {
            $registration = $this->input('registration');
            if (is_string($registration['registration_number'] ?? null)) {
                $registration['registration_number'] = trim($registration['registration_number']);
            }
            $this->merge(['registration' => $registration]);
        }
    }

    public function rules(): array
    {
        $agencyId = $this->input('registration.agency_id');
        $agencyId = is_scalar($agencyId) ? filter_var($agencyId, FILTER_VALIDATE_INT) : false;

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
            'registration' => $this->isMethod('POST')
                ? ['sometimes', 'array:agency_id,registration_number,registration_date,expiration_date,is_primary,remarks']
                : ['prohibited'],
            'registration.agency_id' => ['bail', 'required_with:registration', 'integer',
                Rule::exists('registration_agencies', 'id')->where('is_active', true)],
            'registration.registration_number' => ['bail', 'required_with:registration', 'string', 'max:150',
                Rule::unique('association_registrations', 'registration_number')->where('agency_id',
                    $agencyId === false ? null : $agencyId)],
            'registration.registration_date' => ['required_with:registration', 'date_format:Y-m-d',
                Rule::when($this->boolean('registration.is_primary'), ['before_or_equal:saad_phase2_join_date'])],
            'registration.expiration_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:registration.registration_date'],
            'registration.is_primary' => ['nullable', 'boolean'],
            'registration.remarks' => ['nullable', 'string', 'max:4000'],
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
