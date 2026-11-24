<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryWorkImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'gallery_work_id',
        'path',
        'sort_order',
    ];

    protected $casts = [
        'gallery_work_id' => 'integer',
        'sort_order'      => 'integer',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): ?string
    {
        $raw = $this->attributes['path'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            return $raw;
        }

        return asset('storage/' . ltrim($raw, '/'));
    }

    /**
     * Pastikan path gambar benar-benar file hasil upload yang dikelola di disk public/gallery.
     */
    public static function isManagedStorageImagePath(?string $rawPath): bool
    {
        if (! is_string($rawPath) || trim($rawPath) === '') {
            return false;
        }

        if (filter_var($rawPath, FILTER_VALIDATE_URL)) {
            return false;
        }

        $normalized = ltrim($rawPath, '/');

        return str_starts_with($normalized, 'gallery/')
            && ! str_contains($normalized, '..');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(GalleryWork::class, 'gallery_work_id');
    }
}
