<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['endorsement_letter', 'first_name', 'middle_name', 'last_name', 'suffix',
            'contact_number', 'fishr_registration_number', 'position_other_text'] as $field) {
            $value = $this->input($field);
            $normalized[$field] = is_string($value) ? trim($value) : $value;
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'endorsement_letter' => ['bail', 'required', 'string', 'max:10000'],

            'applicant_photo' => [
                'bail',
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                'mimetypes:image/jpeg,image/png',
                'extensions:jpg,jpeg,png',
                'max:2048',
                'dimensions:max_width=2048,max_height=2048',
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
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'endorsement_letter.required' => 'Enter a nonblank endorsement letter.',
            'endorsement_letter.string' => 'The endorsement letter must be plain text.',
            'endorsement_letter.max' => 'The endorsement letter must not exceed 10,000 characters.',
            'applicant_photo.uploaded' => 'The photo upload failed. Select the file again and retry.',
            'applicant_photo.image' => 'The applicant photo must be a valid JPEG or PNG image.',
            'applicant_photo.mimes' => 'Only JPEG and PNG photos are accepted.',
            'applicant_photo.mimetypes' => 'Only JPEG and PNG image content is accepted.',
            'applicant_photo.extensions' => 'Use a .jpg, .jpeg, or .png filename matching the image format.',
            'applicant_photo.max' => 'The applicant photo must not exceed 2 MiB.',
            'applicant_photo.dimensions' => 'Photo dimensions must not exceed 2048 by 2048 pixels.',
            'birth_date.before_or_equal' =>
                'Birth date cannot be in the future.',
        ];
    }
}
