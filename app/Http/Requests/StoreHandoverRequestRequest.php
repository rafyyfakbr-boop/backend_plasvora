<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHandoverRequestRequest extends FormRequest
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
            'listing_id'          => ['required', 'integer', 'exists:material_listings,id'],
            'requested_weight_kg' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'note'                => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Pesan error yang ramah pengguna.
     */
    public function messages(): array
    {
        return [
            'listing_id.required'          => 'ID listing material wajib diisi.',
            'listing_id.exists'            => 'Listing material tidak ditemukan.',
            'requested_weight_kg.required' => 'Jumlah berat yang diminta wajib diisi.',
            'requested_weight_kg.min'      => 'Berat yang diminta harus lebih dari 0 kg.',
            'note.max'                     => 'Catatan maksimal 1000 karakter.',
        ];
    }
}
