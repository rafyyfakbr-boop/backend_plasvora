<?php

namespace App\Http\Requests;

use App\Models\MaterialRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'material_category'   => ['sometimes', 'string', Rule::in(MaterialRequest::CATEGORIES)],
            'requested_weight_kg' => ['sometimes', 'numeric', 'min:0.01', 'max:99999'],
            'minimum_condition'   => ['sometimes', 'string', Rule::in(MaterialRequest::CONDITIONS)],
            'general_location'    => ['sometimes', 'string', 'min:3', 'max:255'],
            'intended_use'        => ['sometimes', 'string', 'min:5', 'max:500'],
            'status'              => ['sometimes', 'string', Rule::in(['active', 'cancelled'])],
        ];
    }
}
