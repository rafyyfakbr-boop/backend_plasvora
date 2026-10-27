<?php

namespace App\Http\Requests;

use App\Models\MaterialListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialListingRequest extends FormRequest
{
    /**
     * Authorization ditangani di controller/policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Semua field bersifat optional (PATCH), tapi jika dikirim harus valid.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'             => ['sometimes', 'string', 'min:3', 'max:255'],
            'category'         => ['sometimes', 'string', Rule::in(MaterialListing::CATEGORIES)],
            'weight_kg'        => ['sometimes', 'numeric', 'min:0.01', 'max:99999'],
            'condition'        => ['sometimes', 'string', Rule::in(MaterialListing::CONDITIONS)],
            'price'            => ['sometimes', 'numeric', 'min:0'],
            'is_free'          => ['sometimes', 'boolean'],
            'general_location' => ['sometimes', 'string', 'min:3', 'max:255'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image_url'        => ['sometimes', 'nullable', 'url', 'max:500'],
            'status'           => ['sometimes', 'string', Rule::in(MaterialListing::STATUSES)],
        ];
    }

    /**
     * Validasi konsistensi is_free dan price setelah aturan dasar lolos.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Hanya validasi jika kedua field dikirim
            if ($this->has('is_free') && $this->has('price')) {
                $isFree = $this->boolean('is_free');
                $price  = $this->input('price', 0);

                if ($isFree && $price > 0) {
                    $validator->errors()->add('price', 'Harga harus 0 jika material ditandai gratis.');
                }

                if (! $isFree && $price <= 0) {
                    $validator->errors()->add('price', 'Harga harus lebih dari 0 jika material tidak gratis.');
                }
            }
        });
    }
}
