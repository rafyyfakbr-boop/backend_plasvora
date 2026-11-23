<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum middleware memastikan pengguna terautentikasi
    }

    protected function prepareForValidation(): void
    {
        $merges = [];

        if ($this->has('label') && is_string($this->input('label'))) {
            $trimmed = trim($this->input('label'));
            $merges['label'] = $trimmed !== '' ? $trimmed : null;
        }

        foreach (['recipient_name', 'destination_label', 'address_detail', 'postal_code', 'delivery_note'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $trimmed = trim($this->input($field));
                $merges[$field] = $trimmed !== '' ? $trimmed : null;
            }
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
            'label'             => ['nullable', 'string', 'max:60'],
            'recipient_name'    => ['required', 'string', 'max:120'],
            'recipient_phone'   => [
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || User::normalizeIndonesianPhone($value) === null) {
                        $fail('Format nomor telepon penerima tidak valid. Gunakan nomor seluler Indonesia (08..., 62..., atau +62...).');
                    }
                },
            ],
            'destination_id'    => ['required', 'integer', 'min:1'],
            'destination_label' => ['required', 'string', 'max:255'],
            'address_detail'    => ['required', 'string', 'max:1000'],
            'postal_code'       => ['nullable', 'string', 'max:10'],
            'delivery_note'     => ['nullable', 'string', 'max:255'],
            'is_default'        => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.max'                  => 'Label alamat maksimal 60 karakter.',
            'recipient_name.required'    => 'Nama penerima wajib diisi.',
            'recipient_name.max'         => 'Nama penerima maksimal 120 karakter.',
            'recipient_phone.required'   => 'Nomor telepon penerima wajib diisi.',
            'recipient_phone.max'        => 'Nomor telepon penerima maksimal 20 karakter.',
            'destination_id.required'    => 'Wilayah tujuan wajib dipilih.',
            'destination_id.integer'     => 'ID wilayah tujuan tidak valid.',
            'destination_id.min'         => 'ID wilayah tujuan tidak valid.',
            'destination_label.required' => 'Label wilayah tujuan wajib diisi.',
            'destination_label.max'      => 'Label wilayah tujuan maksimal 255 karakter.',
            'address_detail.required'    => 'Detail alamat lengkap wajib diisi.',
            'address_detail.max'         => 'Detail alamat maksimal 1000 karakter.',
            'postal_code.max'            => 'Kode pos maksimal 10 karakter.',
            'delivery_note.max'          => 'Catatan pengiriman maksimal 255 karakter.',
            'is_default.boolean'         => 'Status alamat utama harus bernilai boolean.',
        ];
    }
}
