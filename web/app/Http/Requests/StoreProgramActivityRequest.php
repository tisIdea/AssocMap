<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title)
                ? trim($this->title)
                : $this->title,

            'description' => is_string($this->description)
                ? trim($this->description)
                : $this->description,

            'venue' => is_string($this->venue)
                ? trim($this->venue)
                : $this->venue,

            'conducted_by' => is_string($this->conducted_by)
                ? trim($this->conducted_by)
                : $this->conducted_by,

            'remarks' => is_string($this->remarks)
                ? trim($this->remarks)
                : $this->remarks,
        ]);
    }

    public function rules(): array
    {
        return [
            'saad_activity_id' => [
                'required',
                'integer',
                'exists:saad_activities,id',
            ],

            'association_id' => [
                'nullable',
                'uuid',
                'exists:associations,id',
            ],

            'related_project_id' => [
                'nullable',
                'uuid',
                'exists:projects,id',
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'program_year' => [
                'required',
                'integer',
                'between:2000,2100',
            ],

            'activity_date' => [
                'required',
                'date',
            ],

            'venue' => [
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'required',
                Rule::in([
                    'planned',
                    'ongoing',
                    'completed',
                    'cancelled',
                ]),
            ],

            'conducted_by' => [
                'nullable',
                'string',
                'max:255',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}