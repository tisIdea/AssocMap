<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberApplicationRequest extends FormRequest
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
        return [
            'first_name' => [
                'required',
                'string',
                'max:150',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'last_name' => [
                'required',
                'string',
                'max:150',
            ],

            'suffix' => [
                'nullable',
                'string',
                'max:50',
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
                'max:50',
            ],

            'classification_ids' => [
                'nullable',
                'array',
            ],

            'classification_ids.*' => [
                'integer',
                'distinct',
                'exists:member_classifications,id',
            ],

            'fishr_registration_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'position_id' => [
                'required',
                'integer',
                'exists:association_positions,id',
            ],

            'position_other_text' => [
                'nullable',
                'string',
                'max:150',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' =>
                'Birth date cannot be in the future.',
        ];
    }
}