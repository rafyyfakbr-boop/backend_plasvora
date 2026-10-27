<?php

namespace App\Http\Requests;

use App\Models\MaterialRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequestRequest extends FormRequest
{
    /**
     * Authorization ditangani oleh middleware auth.
     */
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
            'material_category'   => ['required', 'string', Rule::in(MaterialRequest::CATEGORIES)],
            'requested_weight_kg' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'minimum_condition'   => ['required', 'string', Rule::in(MaterialRequest::CONDITIONS)],
            'general_location'    => ['required', 'string', 'min:3', 'max:255'],
            'intended_use'        => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * Pesan error yang ramah pengguna.
     */
    public function messages(): array
    {
        return [
            'material_category.required'   => 'Kategori material yang dibutuhkan wajib dipilih.',
            'material_category.in'         => 'Kategori tidak valid. Pilih dari: ' . implode(', ', MaterialRequest::CATEGORIES),
            'requested_weight_kg.required' => 'Jumlah berat yang dibutuhkan wajib diisi.',
            'requested_weight_kg.min'      => 'Berat yang dibutuhkan harus lebih dari 0 kg.',
            'minimum_condition.required'   => 'Kondisi minimum material wajib dipilih.',
            'minimum_condition.in'         => 'Kondisi tidak valid. Pilih dari: ' . implode(', ', MaterialRequest::CONDITIONS),
            'general_location.required'    => 'Lokasi umum wajib diisi.',
            'intended_use.required'        => 'Tujuan penggunaan material wajib diisi.',
            'intended_use.min'             => 'Tujuan penggunaan minimal 5 karakter.',
        ];
    }
}
