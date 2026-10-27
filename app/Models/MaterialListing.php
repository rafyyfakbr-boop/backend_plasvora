<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'category',
        'weight_kg',
        'condition',
        'price',
        'is_free',
        'general_location',
        'description',
        'image_url',
        'status',
    ];

    protected $casts = [
        'weight_kg'  => 'decimal:2',
        'price'      => 'decimal:2',
        'is_free'    => 'boolean',
    ];

    /**
     * Nilai enum yang valid untuk category.
     */
    public const CATEGORIES = ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'];

    /**
     * Nilai enum yang valid untuk condition.
     */
    public const CONDITIONS = ['clean', 'slightly_dirty', 'dirty', 'mixed'];

    /**
     * Nilai enum yang valid untuk status.
     */
    public const STATUSES = ['available', 'reserved', 'completed', 'inactive'];

    /**
     * Pemilik listing.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Permintaan handover yang berkaitan dengan listing ini.
     */
    public function handoverRequests(): HasMany
    {
        return $this->hasMany(HandoverRequest::class, 'listing_id');
    }

    /**
     * Scope: hanya listing yang berstatus available.
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }
}
