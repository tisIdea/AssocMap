<?php

namespace App\Http\Requests;

use App\Models\Association;
use App\Models\AssociationRegistration;
use App\Services\Associations\AssociationGeographyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
        foreach (['primary_field_officer_id', 'expected_primary_field_officer_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtolower(trim($this->input($field)))]);
            }
        }
        if ($this->exists('specific_address') && is_string($this->input('specific_address'))) {
            $address = trim($this->input('specific_address'));
            $this->merge(['specific_address' => $address === '' ? null : $address]);
        }
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

            ...app(AssociationGeographyService::class)->rules($this->all(), ! $this->isMethod('POST')),

            'saad_phase2_join_date' => [
                'required',
                'date',
            ],

            'primary_field_officer_id' => [
                'required',
                'uuid',
                Rule::exists('user_profiles', 'id'),
            ],
            'expected_primary_field_officer_id' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'uuid'],

            'status' => [
                'required',
                Rule::in(['active', 'inactive']),
            ],
            ...$this->registrationRules($agencyId),
        ];
    }

    private function registrationRules(int|false $agencyId): array
    {
        if (! $this->isMethod('POST')) {
            $association = $this->route('association');
            $id = $this->input('registration.id');
            $record = $association instanceof Association && Str::isUuid($id)
                ? AssociationRegistration::query()->whereKey($id)->where('association_id', $association->id)
                    ->whereHas('agency', fn ($query) => $query->where('registration_kind', 'accreditation')->where('is_active', true))->first()
                : null;

            return [
                'registration' => ['sometimes', 'array:id,expected_updated_at,registration_number,registration_date,expiration_date,remarks',
                    'required_array_keys:id,expected_updated_at,registration_number,registration_date'],
                'registration.id' => ['bail', 'required_with:registration', 'uuid', function ($attribute, $value, $fail) use ($record) {
                    if (! $record) {
                        $fail('Select an existing accreditation record for this association with an active issuing agency.');
                    }
                }],
                'registration.expected_updated_at' => ['required_with:registration', 'string', 'max:50'],
                'registration.agency_id' => ['prohibited'],
                'registration.is_primary' => ['prohibited'],
                'registration.registration_number' => ['bail', 'required_with:registration', 'string', 'max:150',
                    Rule::unique('association_registrations', 'registration_number')->where('agency_id', $record?->agency_id)->ignore($record?->id)],
                'registration.registration_date' => ['bail', 'required_with:registration', 'string', 'date_format:Y-m-d',
                    Rule::when($record?->is_primary === true && is_string($this->input('saad_phase2_join_date')), ['before_or_equal:saad_phase2_join_date'])],
                'registration.expiration_date' => ['bail', 'sometimes', 'nullable', 'string', 'date_format:Y-m-d',
                    Rule::when(is_string($this->input('registration.registration_date')), ['after_or_equal:registration.registration_date'])],
                'registration.remarks' => ['sometimes', 'nullable', 'string', 'max:4000'],
            ];
        }

        return [
            'registration' => ['sometimes', 'array:agency_id,registration_number,registration_date,expiration_date,is_primary,remarks'],
            'registration.agency_id' => ['bail', 'required_with:registration', 'integer',
                Rule::exists('registration_agencies', 'id')->where('is_active', true)],
            'registration.registration_number' => ['bail', 'required_with:registration', 'string', 'max:150',
                Rule::unique('association_registrations', 'registration_number')->where('agency_id',
                    $agencyId === false ? null : $agencyId)],
            'registration.registration_date' => ['bail', 'required_with:registration', 'string', 'date_format:Y-m-d',
                Rule::when($this->boolean('registration.is_primary') && is_string($this->input('saad_phase2_join_date')), ['before_or_equal:saad_phase2_join_date'])],
            'registration.expiration_date' => ['bail', 'nullable', 'string', 'date_format:Y-m-d',
                Rule::when(is_string($this->input('registration.registration_date')), ['after_or_equal:registration.registration_date'])],
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
