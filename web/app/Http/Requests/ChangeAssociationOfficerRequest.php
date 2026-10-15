<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAssociationOfficerRequest extends FormRequest
{
    protected $errorBag = 'officerPosition';

    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor && $actor->canAccessApplication()
            && in_array($actor->role?->code, ['system_admin', 'field_officer'], true);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['assign', 'replace', 'remove'])],
            'member_id' => ['required', 'uuid'],
            'expected_position_id' => ['required', 'integer'],
            'expected_updated_at' => ['required', 'string', 'max:40'],
            'position_id' => ['required_if:action,assign', 'nullable', 'integer'],
            'former_member_id' => ['required_if:action,replace', 'nullable', 'uuid', 'different:member_id'],
            'expected_former_position_id' => ['required_if:action,replace', 'nullable', 'integer'],
            'expected_former_updated_at' => ['required_if:action,replace', 'nullable', 'string', 'max:40'],
            'confirm_change' => ['required', 'accepted'],
        ];
    }
}
