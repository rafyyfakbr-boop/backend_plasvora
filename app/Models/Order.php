<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID            = 'paid';
    public const STATUS_SHIPPED         = 'shipped';
    public const STATUS_COMPLETED       = 'completed';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_EXPIRED         = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_PAID,
        self::STATUS_SHIPPED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    public const PAYMENT_UNPAID    = 'unpaid';
    public const PAYMENT_PENDING   = 'pending';
    public const PAYMENT_PAID      = 'paid';
    public const PAYMENT_FAILED    = 'failed';
    public const PAYMENT_EXPIRED   = 'expired';
    public const PAYMENT_CANCELLED = 'cancelled';
    public const PAYMENT_REFUNDED  = 'refunded';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_PENDING,
        self::PAYMENT_PAID,
        self::PAYMENT_FAILED,
        self::PAYMENT_EXPIRED,
        self::PAYMENT_CANCELLED,
        self::PAYMENT_REFUNDED,
    ];

    public const CANCELLED_BY_BUYER  = 'buyer';
    public const CANCELLED_BY_SELLER = 'seller';
    public const CANCELLED_BY_SYSTEM = 'system';

    public const CANCELLED_BY_ACTORS = [
        self::CANCELLED_BY_BUYER,
        self::CANCELLED_BY_SELLER,
        self::CANCELLED_BY_SYSTEM,
    ];

    /**
     * Status order yang masih mengunci ketersediaan listing material.
     */
    public const ACTIVE_RESERVATION_STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_PAID,
        self::STATUS_SHIPPED,
    ];

    protected $fillable = [
        'order_number',
        'idempotency_key',
        'buyer_id',
        'seller_id',
        'listing_id',
        'buyer_address_id',
        'listing_name_snapshot',
        'listing_category_snapshot',
        'listing_weight_kg_snapshot',
        'listing_image_url_snapshot',
        'origin_destination_id_snapshot',
        'origin_label_snapshot',
        'recipient_name_snapshot',
        'recipient_phone_snapshot',
        'destination_id_snapshot',
        'destination_label_snapshot',
        'address_detail_snapshot',
        'postal_code_snapshot',
        'delivery_note_snapshot',
        'item_price',
        'shipping_cost',
        'platform_fee',
        'total_amount',
        'currency',
        'courier_code',
        'courier_name',
        'courier_service',
        'courier_description',
        'estimated_etd',
        'tracking_number',
        'shipment_notes',
        'shipped_at',
        'delivered_at',
        'completed_at',
        'status',
        'payment_status',
        'payment_expires_at',
        'paid_at',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
        'buyer_note',
        'midtrans_order_id',
        'midtrans_snap_token',
        'midtrans_redirect_url',
        'midtrans_transaction_id',
        'midtrans_payment_type',
        'midtrans_transaction_status',
        'midtrans_fraud_status',
    ];

    protected function casts(): array
    {
        return [
            'listing_weight_kg_snapshot'     => 'decimal:2',
            'origin_destination_id_snapshot' => 'integer',
            'destination_id_snapshot'        => 'integer',
            'item_price'                     => 'integer',
            'shipping_cost'                  => 'integer',
            'platform_fee'                   => 'integer',
            'total_amount'                   => 'integer',
            'shipped_at'                     => 'datetime',
            'delivered_at'                   => 'datetime',
            'completed_at'                   => 'datetime',
            'payment_expires_at'             => 'datetime',
            'paid_at'                        => 'datetime',
            'cancelled_at'                   => 'datetime',
        ];
    }

    /**
     * Daftar status order yang menahan reservasi listing.
     *
     * @return list<string>
     */
    public static function activeReservationStatuses(): array
    {
        return self::ACTIVE_RESERVATION_STATUSES;
    }

    /**
     * Apakah order ini sedang menahan reservasi listing material.
     */
    public function holdsActiveListingReservation(): bool
    {
        return in_array($this->status, self::ACTIVE_RESERVATION_STATUSES, true);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MaterialListing::class, 'listing_id');
    }

    public function buyerAddress(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'buyer_address_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class, 'order_id');
    }
}
