<?php

namespace App\Http\Requests;

use App\Models\MaterialListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialListingRequest extends FormRequest
{
    /**
     * Hanya pengguna yang sudah login yang boleh membuat listing.
     */
    public function authorize(): bool
    {
        return true; // Auth middleware sudah memastikan ini
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_free') && is_string($this->input('is_free'))) {
            $parsed = filter_var($this->input('is_free'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                $this->merge(['is_free' => $parsed]);
            }
        }
    }

    /**
     * Aturan validasi untuk membuat listing baru.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'min:3', 'max:255'],
            'category'              => ['required', 'string', Rule::in(MaterialListing::CATEGORIES)],
            'weight_kg'             => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'condition'             => ['required', 'string', Rule::in(MaterialListing::CONDITIONS)],
            'price'                 => ['nullable', 'numeric', 'min:0'],
            'is_free'               => ['required', 'boolean'],
            'general_location'      => ['required', 'string', 'min:3', 'max:255'],
            'origin_destination_id' => ['nullable', 'integer', 'min:1'],
            'origin_label'          => ['nullable', 'string', 'max:255'],
            'description'           => ['nullable', 'string', 'max:2000'],
            'image_url'             => ['nullable', 'url', 'max:500'],
            'image'                 => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
        ];
    }

    /**
     * Pesan error yang ramah pengguna.
     */
    public function messages(): array
    {
        return [
            'name.required'             => 'Nama material wajib diisi.',
            'name.min'                  => 'Nama material minimal 3 karakter.',
            'category.required'         => 'Kategori material wajib dipilih.',
            'category.in'               => 'Kategori tidak valid. Pilih dari: ' . implode(', ', MaterialListing::CATEGORIES),
            'weight_kg.required'        => 'Berat material wajib diisi.',
            'weight_kg.min'             => 'Berat material harus lebih dari 0 kg.',
            'condition.required'        => 'Kondisi material wajib dipilih.',
            'condition.in'              => 'Kondisi tidak valid. Pilih dari: ' . implode(', ', MaterialListing::CONDITIONS),
            'price.min'                 => 'Harga tidak boleh bernilai negatif.',
            'is_free.required'          => 'Status gratis wajib diisi.',
            'general_location.required' => 'Lokasi umum wajib diisi.',
            'origin_destination_id.integer' => 'ID lokasi asal tidak valid.',
            'origin_destination_id.min'     => 'ID lokasi asal tidak valid.',
            'origin_label.max'              => 'Label lokasi asal maksimal 255 karakter.',
            'description.max'           => 'Deskripsi maksimal 2000 karakter.',
            'image.image'               => 'File harus berupa gambar yang valid.',
            'image.mimes'               => 'Format gambar harus berupa jpeg, jpg, png, atau webp.',
            'image.max'                 => 'Ukuran gambar maksimal adalah 10MB.',
        ];
    }

    /**
     * Validasi tambahan setelah aturan dasar lolos.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $isFree = $this->boolean('is_free');
            $price  = $this->input('price', 0);

            // Jika gratis, harga harus 0
            if ($isFree && $price > 0) {
                $validator->errors()->add('price', 'Harga harus 0 jika material ditandai gratis.');
            }

            // Jika berbayar, harga harus diisi dan lebih dari 0
            if (! $isFree && $price <= 0) {
                $validator->errors()->add('price', 'Harga harus lebih dari 0 jika material tidak gratis.');
            }
        });
    }
}
