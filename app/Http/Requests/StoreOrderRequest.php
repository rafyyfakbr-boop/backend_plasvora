<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    /**
     * Field otoritatif server yang dilarang dikirim/dimanipulasi oleh klien.
     */
    private const FORBIDDEN_CLIENT_FIELDS = [
        'item_price',
        'shipping_cost',
        'platform_fee',
        'total_amount',
        'weight_kg',
        'buyer_id',
        'seller_id',
        'owner_id',
        'status',
        'payment_status',
        'midtrans_order_id',
        'midtrans_server_key',
        'midtrans_signature_secret_key',
    ];

    public function authorize(): bool
    {
        return true; // auth:sanctum memastikan user terautentikasi
    }

    protected function prepareForValidation(): void
    {
        $merges = [];

        if (is_string($this->input('courier_code'))) {
            $merges['courier_code'] = strtolower(trim($this->input('courier_code')));
        }

        if (is_string($this->input('courier_service'))) {
            $merges['courier_service'] = trim($this->input('courier_service'));
        }

        if (is_string($this->input('idempotency_key'))) {
            $merges['idempotency_key'] = trim($this->input('idempotency_key'));
        }

        if ($this->exists('buyer_note') && is_string($this->input('buyer_note'))) {
            $trimmed = trim($this->input('buyer_note'));
            $merges['buyer_note'] = $trimmed !== '' ? $trimmed : null;
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
            'listing_id'      => ['required', 'integer', 'min:1'],
            'address_id'      => ['required', 'integer', 'min:1'],
            'courier_code'    => ['required', 'string', 'min:2', 'max:30'],
            'courier_service' => ['required', 'string', 'min:1', 'max:50'],
            'buyer_note'      => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:4', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'listing_id.required'      => 'Listing material wajib dipilih.',
            'listing_id.integer'       => 'ID listing material tidak valid.',
            'address_id.required'      => 'Alamat pengiriman wajib dipilih.',
            'address_id.integer'       => 'ID alamat pengiriman tidak valid.',
            'courier_code.required'    => 'Kode kurir wajib dipilih.',
            'courier_service.required' => 'Layanan kurir wajib dipilih.',
            'buyer_note.max'           => 'Catatan pembeli maksimal 500 karakter.',
            'idempotency_key.required' => 'Idempotency key wajib disertakan.',
            'idempotency_key.min'      => 'Idempotency key minimal 4 karakter.',
            'idempotency_key.max'      => 'Idempotency key maksimal 64 karakter.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach (self::FORBIDDEN_CLIENT_FIELDS as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add(
                        $field,
                        "Field '{$field}' ditentukan oleh server dan tidak diperbolehkan dalam permintaan checkout."
                    );
                }
            }
        });
    }
}
