<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CloseProjectRequest extends FormRequest
{
    protected const CONFIRMATION_FIELD = 'completion_confirmation';
    protected const CONFIRMATION_WORD = 'COMPLETED';

    protected $errorBag = 'projectClosure';

    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication()
            && in_array($actor->role?->code, ['system_admin', 'field_officer'], true);
    }

    protected function prepareForValidation(): void
    {
        $remarks = $this->input('closure_remarks');
        if (is_string($remarks)) $this->merge(['closure_remarks' => Str::trim($remarks)]);
    }

    public function rules(): array
    {
        return ['closure_remarks' => ['bail', 'required', 'string', 'max:2000', function ($attribute, $value, $fail) {
                if (! preg_match('/[^\p{Z}\p{C}\s\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}]/u', $value)) $fail('Final Remarks must contain visible text.');
            }],
            static::CONFIRMATION_FIELD => ['bail', 'required', 'string', 'in:'.static::CONFIRMATION_WORD],
            'expected_updated_at' => ['required', 'string', 'max:64']];
    }

    public function messages(): array
    {
        return ['closure_remarks.required' => 'Final Remarks must document the reason for finalization.',
            'closure_remarks.max' => 'Final Remarks must not exceed 2,000 characters.',
            static::CONFIRMATION_FIELD.'.required' => 'Type '.static::CONFIRMATION_WORD.' exactly to confirm.',
            static::CONFIRMATION_FIELD.'.in' => 'Type '.static::CONFIRMATION_WORD.' exactly, without surrounding spaces.',
            'expected_updated_at.required' => 'Reload the project before confirming finalization.'];
    }

    protected function getRedirectUrl(): string
    {
        $prefix = $this->attributes->get('current_user')->role->code === 'system_admin' ? 'admin' : 'field-officer';

        return route($prefix.'.projects.show', $this->route('project'));
    }
}
