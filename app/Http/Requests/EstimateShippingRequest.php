<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EstimateShippingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum middleware memastikan pengguna terautentikasi
    }

    /**
     * Klien hanya mengirim listing_id dan address_id.
     * Origin, destination, weight, courier, dan price mode selalu di-resolve di server.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'integer', 'exists:material_listings,id'],
            'address_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'listing_id.required' => 'Listing material wajib dipilih.',
            'listing_id.integer'  => 'ID listing material tidak valid.',
            'listing_id.exists'   => 'Listing material tidak ditemukan.',
            'address_id.required' => 'Alamat tujuan wajib dipilih.',
            'address_id.integer'  => 'ID alamat tujuan tidak valid.',
            'address_id.min'      => 'ID alamat tujuan tidak valid.',
        ];
    }
}
