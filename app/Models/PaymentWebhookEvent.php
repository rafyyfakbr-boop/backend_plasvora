<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'provider',
        'midtrans_order_id',
        'midtrans_transaction_id',
        'transaction_status',
        'fraud_status',
        'status_code',
        'gross_amount',
        'signature_valid',
        'payload_hash',
        'processing_result',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'processed_at'    => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
