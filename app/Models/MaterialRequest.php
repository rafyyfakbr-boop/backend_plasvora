<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'material_category',
        'requested_weight_kg',
        'minimum_condition',
        'general_location',
        'intended_use',
        'status',
    ];

    protected $casts = [
        'requested_weight_kg' => 'decimal:2',
    ];

    /**
     * Nilai enum yang valid untuk material_category.
     */
    public const CATEGORIES = ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'];

    /**
     * Nilai enum yang valid untuk minimum_condition.
     */
    public const CONDITIONS = ['clean', 'slightly_dirty', 'dirty', 'mixed'];

    /**
     * Nilai enum yang valid untuk status.
     */
    public const STATUSES = ['active', 'fulfilled', 'cancelled'];

    /**
     * Pengguna yang membuat kebutuhan material ini.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
