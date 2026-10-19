<?php

namespace App\Http\Requests;

use App\Models\Association;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MemberLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');
        $member = $this->route('member');

        return $actor?->canAccessApplication() && $actor->role?->code === 'field_officer'
            && $member instanceof Member && Association::query()->useWritePdo()
                ->whereKey($member->association_id)->where('primary_field_officer_id', $actor->id)->exists();
    }

    public function rules(): array
    {
        return [
            'member_record_id' => ['required', 'uuid', Rule::in([$this->route('member')?->id])],
            'member_action' => ['required', Rule::in([$this->routeIs('field-officer.members.archive') ? 'archive' : 'reactivate'])],
            'expected_updated_at' => ['required', 'string', 'max:40'],
            'confirm_change' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be changed through this member action.');
            }
        }];
    }
}
