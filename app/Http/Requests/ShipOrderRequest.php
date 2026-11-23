<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShipOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // OrderPolicy memeriksa kepemilikan penjual
    }

    protected function prepareForValidation(): void
    {
        $merges = [];

        if (is_string($this->input('tracking_number'))) {
            $merges['tracking_number'] = trim($this->input('tracking_number'));
        }

        if ($this->exists('shipment_notes') && is_string($this->input('shipment_notes'))) {
            $trimmed = trim($this->input('shipment_notes'));
            $merges['shipment_notes'] = $trimmed !== '' ? $trimmed : null;
        }

        if (! empty($merges)) {
            $this->merge($merges);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tracking_number' => ['required', 'string', 'min:5', 'max:100'],
            'shipment_notes'  => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'tracking_number.required' => 'Nomor resi pengiriman wajib diisi.',
            'tracking_number.min'      => 'Nomor resi pengiriman minimal 5 karakter.',
            'tracking_number.max'      => 'Nomor resi pengiriman maksimal 100 karakter.',
            'shipment_notes.max'       => 'Catatan pengiriman maksimal 500 karakter.',
        ];
    }
}
