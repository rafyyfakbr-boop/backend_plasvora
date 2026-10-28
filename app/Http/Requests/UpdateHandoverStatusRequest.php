<?php

namespace App\Http\Requests;

use App\Models\HandoverRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHandoverStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(HandoverRequest::TARGET_STATUSES)],
            'agreed_weight_kg' => ['sometimes', 'numeric', 'min:0.01'],
            'actual_weight_kg' => ['sometimes', 'numeric', 'min:0.01'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Pesan error yang ramah pengguna.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status baru wajib diisi.',
            'status.in' => 'Status tidak valid. Status yang diperbolehkan: '.implode(', ', HandoverRequest::TARGET_STATUSES),
        ];
    }
}
