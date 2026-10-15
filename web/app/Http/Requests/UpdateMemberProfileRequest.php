<?php

namespace App\Http\Requests;

use App\Models\Association;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMemberProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');
        $member = $this->route('member');

        return $actor?->canAccessApplication() && $actor->role?->code === 'field_officer'
            && $member instanceof Member && Association::query()->useWritePdo()
                ->whereKey($member->association_id)->where('primary_field_officer_id', $actor->id)->exists();
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'contact_number', 'fishr_registration_number'] as $field) {
            $value = $this->input($field);
            $values[$field] = is_string($value) ? trim($value) : $value;
            if (in_array($field, ['middle_name', 'suffix', 'contact_number', 'fishr_registration_number'], true)
                && $values[$field] === '') $values[$field] = null;
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'member_record_id' => ['required', 'uuid', Rule::in([$this->route('member')?->id])],
            'member_action' => ['required', Rule::in(['profile'])],
            'expected_updated_at' => ['required', 'string', 'max:40'],
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'sex_id' => ['required', 'integer', 'exists:sex_categories,id'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'fishr_registration_number' => ['nullable', 'string', 'max:100',
                Rule::unique('members', 'fishr_registration_number')->ignore($this->route('member')?->id)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be changed through member profile editing.');
            }
        }];
    }

    public function messages(): array
    {
        return ['fishr_registration_number.unique' => 'This FishR registration number is already assigned to another member.',
            'birth_date.before_or_equal' => 'Birth date cannot be in the future.'];
    }
}
