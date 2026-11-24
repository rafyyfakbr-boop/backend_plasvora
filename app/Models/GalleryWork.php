<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GalleryWork extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const DIFFICULTY_MUDAH    = 'mudah';
    public const DIFFICULTY_MENENGAH = 'menengah';
    public const DIFFICULTY_SULIT    = 'sulit';

    public const DIFFICULTIES = [
        self::DIFFICULTY_MUDAH,
        self::DIFFICULTY_MENENGAH,
        self::DIFFICULTY_SULIT,
    ];

    /**
     * Hanya field konten karya yang boleh di-mass-assign.
     * user_id, status, likes_count, reviewed_at, published_at, dan rejection_reason
     * TIDAK boleh mass-assignable dari request klien.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'difficulty',
        'materials',
        'tools',
        'steps',
        'video_links',
        'ownership_confirmed_at',
    ];

    protected $casts = [
        'materials'              => 'array',
        'tools'                  => 'array',
        'steps'                  => 'array',
        'video_links'            => 'array',
        'likes_count'            => 'integer',
        'reviewed_at'            => 'datetime',
        'published_at'           => 'datetime',
        'ownership_confirmed_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryWorkImage::class, 'gallery_work_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(GalleryWorkLike::class, 'gallery_work_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeWithLikedBy(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query;
        }

        return $query->withExists([
            'likes as liked_by_me' => function (Builder $likeQuery) use ($user): void {
                $likeQuery->where('user_id', $user->id);
            },
        ]);
    }
}
