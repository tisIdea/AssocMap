<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainingRequest extends FormRequest
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
            'association_id' => [
                'required',
                'uuid',
                'exists:associations,id',
            ],

            'related_project_id' => [
                'nullable',
                'uuid',
                'exists:projects,id',
            ],

            'saad_activity_id' => [
                'required',
                'integer',
                'exists:saad_activities,id',
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

            'start_date' => [
                'required',
                'date',
            ],

            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'venue' => [
                'nullable',
                'string',
                'max:255',
            ],

            'conducted_by' => [
                'nullable',
                'string',
                'max:255',
            ],

            'training_cost' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'status' => [
                'required',
                Rule::in([
                    'scheduled',
                    'completed',
                    'cancelled',
                ]),
            ],

            'remarks' => [
                'nullable',
                'string',
            ],

            'participants' => [
                'nullable',
                'array',
            ],

            'participants.*.member_id' => [
                'required_with:participants',
                'uuid',
                'distinct',
                'exists:members,id',
            ],

            'participants.*.attendance_status' => [
                'required_with:participants',
                Rule::in([
                    'present',
                    'absent',
                ]),
            ],

            'participants.*.remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}