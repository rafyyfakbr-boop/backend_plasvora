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
        'password',
    ];

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
}
