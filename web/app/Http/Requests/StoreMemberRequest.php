<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => trim((string) $this->first_name),
            'middle_name' => $this->middle_name
                ? trim((string) $this->middle_name)
                : null,
            'last_name' => trim((string) $this->last_name),
            'suffix' => $this->suffix
                ? trim((string) $this->suffix)
                : null,
            'contact_number' => $this->contact_number
                ? trim((string) $this->contact_number)
                : null,
            'fishr_registration_number' =>
                $this->fishr_registration_number
                    ? trim((string) $this->fishr_registration_number)
                    : null,
            'position_other_text' =>
                $this->position_other_text
                    ? trim((string) $this->position_other_text)
                    : null,
        ]);
    }

    public function rules(): array
    {
        $member = $this->route('member');

        return [
            'association_id' => [
                'required',
                'uuid',
                'exists:associations,id',
            ],

            'first_name' => [
                'required',
                'string',
                'max:120',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:120',
            ],

            'last_name' => [
                'required',
                'string',
                'max:120',
            ],

            'suffix' => [
                'nullable',
                'string',
                'max:20',
            ],

            'birth_date' => [
                'required',
                'date',
                'before_or_equal:today',
            ],

            'sex_id' => [
                'required',
                'integer',
                'exists:sex_categories,id',
            ],

            'contact_number' => [
                'nullable',
                'string',
                'max:30',
            ],

            'fishr_registration_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique(
                    'members',
                    'fishr_registration_number'
                )->ignore($member?->id),
            ],

            'position_id' => [
                'required',
                'integer',
                'exists:association_positions,id',
            ],

            'position_other_text' => [
                'nullable',
                'string',
                'max:100',
            ],

            'date_registered' => [
                'required',
                'date',
            ],

            'member_status' => [
                'required',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' =>
                'Birth date cannot be in the future.',

            'fishr_registration_number.unique' =>
                'This FishR registration number is already assigned to another member.',
        ];
    }
}
