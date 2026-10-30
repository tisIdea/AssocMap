<?php

namespace App\Http\Requests;

use App\Models\GisLocation;
use App\Services\GIS\GisGeometry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SaveGisLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->attributes->get('current_user');

        return $user?->canAccessApplication() && in_array($user->role->code, ['system_admin', 'field_officer'], true);
    }

    protected function prepareForValidation(): void
    {
        foreach (['parent_association_id', 'project_id', 'idempotency_key'] as $field) {
            if (is_string($this->input($field))) $this->merge([$field => strtolower(trim($this->input($field)))]);
        }
        if (is_string($this->input('geometry')) && strlen($this->input('geometry')) <= GisGeometry::MAX_BYTES) {
            $this->merge(['geometry' => json_decode($this->input('geometry'), true)]);
        }
    }

    public function rules(): array
    {
        return [
            'location_type' => ['required', Rule::in(GisLocation::TYPES)], 'location_name' => ['required', 'string', 'max:255'],
            'site_type_id' => ['nullable', 'integer', 'min:1', Rule::exists('gis_site_types', 'id')],
            'association_id' => ['prohibited'], 'parent_association_id' => ['required', 'uuid'], 'project_id' => ['required', 'uuid'],
            'address' => ['nullable', 'string', 'max:2000'], 'public_title' => ['nullable', 'string', 'max:255'],
            'address_mode' => ['prohibited'],
            'public_description' => ['nullable', 'string', 'max:4000'], 'public_address' => ['nullable', 'string', 'max:2000'],
            'geometry' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'array'],
            'idempotency_key' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'version' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'string', 'max:64'],
            'geom' => ['prohibited'], 'is_published' => ['prohibited'], 'published_by' => ['prohibited'], 'published_at' => ['prohibited'],
            'created_by' => ['prohibited'], 'updated_by' => ['prohibited'], 'archived_at' => ['prohibited'], 'archived_by' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->has('geometry')) {
                return;
            }
            try {
                app(GisGeometry::class)->normalize($this->input('geometry'), $this->input('location_type'));
            } catch (ValidationException $exception) {
                foreach ($exception->errors()['geometry'] as $message) $validator->errors()->add('geometry', $message);
            }
        }];
    }
}
