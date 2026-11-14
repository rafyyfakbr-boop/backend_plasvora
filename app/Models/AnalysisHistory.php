<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'declared_category',
        'estimated_category',
        'weight_kg',
        'condition',
        'notes',
        'input_image_path',
        'recommendations',
        'is_demo_result',
    ];

    protected $casts = [
        'weight_kg'       => 'decimal:2',
        'recommendations' => 'array',
        'is_demo_result'  => 'boolean',
    ];

    protected $appends = ['image_url'];

    /**
     * URL publik untuk gambar input analisis.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->input_image_path)) {
            return null;
        }

        if (filter_var($this->input_image_path, FILTER_VALIDATE_URL)) {
            return $this->input_image_path;
        }

        return asset('storage/' . $this->input_image_path);
    }

    /**
     * Pengguna pemilik analisis ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
