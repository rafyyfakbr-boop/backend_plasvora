<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // OrderPolicy memeriksa kepemilikan pembeli
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('reason') && is_string($this->input('reason'))) {
            $trimmed = trim($this->input('reason'));
            $this->merge(['reason' => $trimmed !== '' ? $trimmed : null]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => 'Alasan pembatalan maksimal 255 karakter.',
        ];
    }
}
