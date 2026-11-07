<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['title', 'description', 'remarks', 'new_commodity_name'] as $field) {
            $value = $this->input($field);
            $normalized[$field] = is_string($value) ? trim($value) : $value;
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'association_id' => [
                'required',
                'uuid',
                'exists:associations,id',
            ],

            'fisheries_category_id' => [
                'required',
                'integer',
                'exists:fisheries_project_categories,id',
            ],

            'commodity_id' => [
                'nullable',
                'integer',
                'exists:commodities,id',
            ],

            'new_commodity_name' => [
                'nullable',
                'string',
                'max:255',
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

            'implementation_start_date' => [
                'required',
                'date',
            ],

            'implementation_end_date' => [
                'nullable',
                'date',
                'after_or_equal:implementation_start_date',
            ],

            'status' => [
                'required',
                Rule::in([
                    'planned',
                    'ongoing',
                    'suspended',
                    'cancelled',
                ]),
            ],

            'remarks' => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | PROJECT MATERIALS
            |--------------------------------------------------------------------------
            */

            'materials' => [
                'nullable',
                'array',
            ],

            'materials.*.id' => [
                'nullable',
                'uuid',
                'exists:project_materials,id',
            ],

            'materials.*.item_name' => [
                'required_with:materials',
                'string',
                'max:255',
            ],

            'materials.*.quantity_planned' => [
                'required_with:materials',
                'numeric',
                'min:0',
            ],

            'materials.*.quantity_delivered' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'materials.*.unit' => [
                'required_with:materials',
                'string',
                'max:100',
            ],

            'materials.*.unit_cost' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'materials.*.delivery_status' => [
                'required_with:materials',
                Rule::in([
                    'planned',
                    'not_delivered',
                    'partially_delivered',
                    'delivered',
                ]),
            ],

            'materials.*.delivery_date' => [
                'nullable',
                'date',
            ],

            'materials.*.remarks' => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | PROJECT PRODUCTS
            |--------------------------------------------------------------------------
            */

            'products' => [
                'nullable',
                'array',
            ],

            'products.*.id' => [
                'nullable',
                'uuid',
                'exists:project_products,id',
            ],

            'products.*.product_name' => [
                'required_with:products',
                'string',
                'max:255',
            ],

            'products.*.product_type' => [
                'required_with:products',
                Rule::in([
                    'raw',
                    'processed',
                    'other',
                ]),
            ],

            'products.*.default_unit' => [
                'nullable',
                'string',
                'max:100',
            ],

            'products.*.description' => [
                'nullable',
                'string',
            ],

            'products.*.is_active' => [
                'nullable',
                'boolean',
            ],
        ];
    }
}
