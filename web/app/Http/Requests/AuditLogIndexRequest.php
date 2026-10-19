<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use App\Services\AuditLogFilterOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AuditLogIndexRequest extends FormRequest
{
    public const FILTERS = ['action_type', 'module', 'entity_type', 'actor_user_id', 'date_from', 'date_to', 'per_page', 'page'];

    private ?array $resolvedFilterOptions = null;

    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor instanceof UserProfile && $actor->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        $options = $this->filterOptions();

        return [
            'action_type' => ['bail', 'nullable', 'string', 'max:100', 'regex:/\A[^\x00-\x1F\x7F]*\z/u', Rule::in($options['choices']['action_type'])],
            'module' => ['bail', 'nullable', 'string', 'max:100', 'regex:/\A[^\x00-\x1F\x7F]*\z/u', Rule::in($options['choices']['module'])],
            'entity_type' => ['bail', 'nullable', 'string', 'max:100', 'regex:/\A[^\x00-\x1F\x7F]*\z/u', Rule::in($options['choices']['entity_type'])],
            'actor_user_id' => ['bail', 'nullable', 'string', 'max:36', function ($attribute, $value, $fail) {
                if ($value !== 'unavailable' && ! \Illuminate\Support\Str::isUuid($value)) $fail('Choose an actor identifier or unavailable actor.');
            }, Rule::in(['unavailable', ...$options['actors']->pluck('id')->all()])],
            'date_from' => ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
            'date_to' => ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', Rule::when(is_string($this->query('date_from')) && $this->query('date_from') !== '', 'after_or_equal:date_from')],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function filterOptions(): array
    {
        return $this->resolvedFilterOptions ??= app(AuditLogFilterOptions::class)->get();
    }

    public function messages(): array
    {
        return [
            'action_type.in' => 'Choose a recorded action or reset the filters.',
            'module.in' => 'Choose a recorded module or reset the filters.',
            'entity_type.in' => 'Choose a recorded entity type or reset the filters.',
            'actor_user_id.in' => 'Choose a recorded actor or reset the filters.',
        ];
    }

    public function safeFilters(): array
    {
        return array_filter(array_intersect_key($this->query->all(), array_flip(self::FILTERS)),
            fn ($value) => is_string($value) || is_int($value));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->view('admin.audit-logs.index', [
            'filters' => $this->safeFilters(), 'filterErrors' => $validator->errors()->all(), 'invalidFilters' => true,
        ] + $this->filterOptions(), 422));
    }
}
