<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryWorkLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'gallery_work_id',
        'user_id',
    ];

    protected $casts = [
        'gallery_work_id' => 'integer',
        'user_id'         => 'integer',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(GalleryWork::class, 'gallery_work_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
