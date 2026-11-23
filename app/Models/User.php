<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp_public',
        'password',
    ];

    /**
     * Normalisasi nomor telepon seluler Indonesia (08..., 62..., +62...) ke format 628... untuk WhatsApp.
     * Mengembalikan null jika kosong atau tidak valid.
     */
    public static function normalizeIndonesianPhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/[\s\-\(\)\.]+/', '', trim($raw));
        if ($cleaned === null || $cleaned === '') {
            return null;
        }

        if (! preg_match('/^(?:\+?62|0)8[1-9][0-9]{7,11}$/', $cleaned)) {
            return null;
        }

        if (str_starts_with($cleaned, '+62')) {
            return substr($cleaned, 1);
        }

        if (str_starts_with($cleaned, '0')) {
            return '62' . substr($cleaned, 1);
        }

        return $cleaned;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'whatsapp_public'   => 'boolean',
        ];
    }

    /**
     * Listing material yang dimiliki pengguna.
     */
    public function materialListings(): HasMany
    {
        return $this->hasMany(MaterialListing::class, 'owner_id');
    }

    /**
     * Kebutuhan material yang dibuat pengguna.
     */
    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class, 'requester_id');
    }

    /**
     * Riwayat analisis milik pengguna.
     */
    public function analysisHistories(): HasMany
    {
        return $this->hasMany(AnalysisHistory::class);
    }

    /**
     * Handover requests di mana pengguna ini adalah pemilik listing.
     */
    public function ownedHandoverRequests(): HasMany
    {
        return $this->hasMany(HandoverRequest::class, 'owner_id');
    }

    /**
     * Handover requests di mana pengguna ini adalah peminta.
     */
    public function sentHandoverRequests(): HasMany
    {
        return $this->hasMany(HandoverRequest::class, 'requester_id');
    }

    /**
     * Alamat pengiriman tersimpan milik pengguna.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Pesanan di mana pengguna ini bertindak sebagai pembeli.
     */
    public function buyerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    /**
     * Pesanan di mana pengguna ini bertindak sebagai penjual.
     */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    /**
     * Riwayat perubahan status pesanan yang dipicu oleh pengguna ini.
     */
    public function orderStatusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'actor_id');
    }
}
