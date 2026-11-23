<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'destination_id',
        'destination_label',
        'address_detail',
        'postal_code',
        'delivery_note',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'destination_id' => 'integer',
            'is_default'     => 'boolean',
        ];
    }

    /**
     * Pemilik alamat pengiriman.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pesanan yang pernah merujuk pada alamat tersimpan ini.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_address_id');
    }
}
