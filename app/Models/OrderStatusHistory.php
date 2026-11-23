<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    use HasFactory;

    // Tabel audit bersifat append-only (hanya created_at).
    public const UPDATED_AT = null;

    public const ACTOR_BUYER    = 'buyer';
    public const ACTOR_SELLER   = 'seller';
    public const ACTOR_SYSTEM   = 'system';
    public const ACTOR_MIDTRANS = 'midtrans';

    public const ACTOR_TYPES = [
        self::ACTOR_BUYER,
        self::ACTOR_SELLER,
        self::ACTOR_SYSTEM,
        self::ACTOR_MIDTRANS,
    ];

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'source',
        'reason',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
