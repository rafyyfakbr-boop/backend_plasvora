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

    protected function prepareForValidation(): void
    {
        $merges = [];

        if ($this->has('is_free') && is_string($this->input('is_free'))) {
            $parsed = filter_var($this->input('is_free'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                $merges['is_free'] = $parsed;
            }
        }

        if ($this->exists('origin_label') && is_string($this->input('origin_label'))) {
            $trimmed = trim($this->input('origin_label'));
            $merges['origin_label'] = $trimmed !== '' ? $trimmed : null;
        }

        if (! empty($merges)) {
            $this->merge($merges);
        }
    }

    /**
     * Semua field bersifat optional (PATCH), tapi jika dikirim harus valid.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'                  => ['sometimes', 'string', 'min:3', 'max:255'],
            'category'              => ['sometimes', 'string', Rule::in(MaterialListing::CATEGORIES)],
            'weight_kg'             => ['sometimes', 'numeric', 'min:0.01', 'max:99999'],
            'condition'             => ['sometimes', 'string', Rule::in(MaterialListing::CONDITIONS)],
            'price'                 => ['sometimes', 'numeric', 'min:0'],
            'is_free'               => ['sometimes', 'boolean'],
            'general_location'      => ['sometimes', 'string', 'min:3', 'max:255'],
            'origin_destination_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'origin_label'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'description'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image_url'             => ['sometimes', 'nullable', 'url', 'max:500'],
            'image'                 => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            // Pemilik hanya boleh mengaktifkan/menonaktifkan listing secara manual; reserved/completed dikelola sistem transaksi
            'status'                => ['sometimes', 'string', Rule::in(['available', 'inactive'])],
        ];
    }

    /**
     * Pesan error yang ramah pengguna.
     */
    public function messages(): array
    {
        return [
            'origin_destination_id.integer' => 'ID lokasi asal tidak valid.',
            'origin_destination_id.min'     => 'ID lokasi asal tidak valid.',
            'origin_label.max'              => 'Label lokasi asal maksimal 255 karakter.',
            'image.image'                   => 'File harus berupa gambar yang valid.',
            'image.mimes'                   => 'Format gambar harus berupa jpeg, jpg, png, atau webp.',
            'image.max'                     => 'Ukuran gambar maksimal adalah 10MB.',
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

            // Konsistensi pasangan origin pada partial PATCH:
            // Jika keduanya tidak dikirim, biarkan nilai lama utuh.
            // Jika salah satu dikirim, keduanya wajib dikirim sebagai pasangan konsisten
            // (sama-sama terisi atau sama-sama null untuk menghapus).
            $idProvided    = $this->exists('origin_destination_id');
            $labelProvided = $this->exists('origin_label');

            if ($idProvided || $labelProvided) {
                if ($idProvided && ! $labelProvided) {
                    $validator->errors()->add(
                        'origin_label',
                        'ID dan label lokasi asal harus dikirim bersama sebagai pasangan yang konsisten.'
                    );
                } elseif ($labelProvided && ! $idProvided) {
                    $validator->errors()->add(
                        'origin_destination_id',
                        'ID dan label lokasi asal harus dikirim bersama sebagai pasangan yang konsisten.'
                    );
                } else {
                    $originId    = $this->input('origin_destination_id');
                    $originLabel = $this->input('origin_label');
                    $hasId       = $originId !== null && $originId !== '';
                    $hasLabel    = is_string($originLabel) && trim($originLabel) !== '';

                    if ($hasId && ! $hasLabel) {
                        $validator->errors()->add(
                            'origin_label',
                            'Label lokasi asal wajib diisi jika ID lokasi asal disertakan.'
                        );
                    }

                    if (! $hasId && $hasLabel) {
                        $validator->errors()->add(
                            'origin_destination_id',
                            'ID lokasi asal wajib diisi jika label lokasi asal disertakan.'
                        );
                    }
                }
            }
        });
    }
}
