<?php

namespace App\Http\Requests;

use App\Services\GisGeometry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CorrectGisGeometryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('geometry')) && strlen($this->input('geometry')) <= GisGeometry::MAX_BYTES) {
            $this->merge(['geometry' => json_decode($this->input('geometry'), true)]);
        }
    }

    public function rules(): array
    {
        return ['geometry' => ['required', 'array'], 'version' => ['required', 'string', 'max:64']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['geometry', 'version', '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'Only geometry and the expected version may be submitted for correction.');
            }
        }];
    }
}
