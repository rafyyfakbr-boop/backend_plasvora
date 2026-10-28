<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoverRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'owner_id',
        'requester_id',
        'requested_weight_kg',
        'agreed_weight_kg',
        'actual_weight_kg',
        'note',
        'status',
        'approved_at',
        'handed_over_at',
        'utilization_confirmed_at',
    ];

    protected $casts = [
        'requested_weight_kg' => 'decimal:2',
        'agreed_weight_kg' => 'decimal:2',
        'actual_weight_kg' => 'decimal:2',
        'approved_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'utilization_confirmed_at' => 'datetime',
    ];

    /**
     * Nilai enum yang valid untuk status.
     */
    public const STATUSES = [
        'pending',
        'approved',
        'handed_over',
        'utilization_confirmed',
        'rejected',
        'cancelled',
    ];

    /**
     * Status terminal: tidak bisa dipindahkan ke status lain.
     */
    public const TERMINAL_STATUSES = [
        'utilization_confirmed',
        'rejected',
        'cancelled',
    ];

    /**
     * Status yang valid sebagai TARGET transisi (nilai tujuan pada PATCH status).
     * Diturunkan dari union semua nilai dalam ALLOWED_TRANSITIONS.
     * 'pending' tidak termasuk karena tidak ada transisi yang menuju ke sana.
     */
    public const TARGET_STATUSES = ['approved', 'rejected', 'cancelled', 'handed_over', 'utilization_confirmed'];

    /**
     * Aturan transisi status yang diperbolehkan.
     * Format: 'dari_status' => ['status_yang_diperbolehkan', ...]
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['handed_over', 'cancelled'],
        'handed_over' => ['utilization_confirmed'],
    ];

    /**
     * Cek apakah transisi ke status baru diperbolehkan.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed);
    }

    /**
     * Listing material yang terkait.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(MaterialListing::class, 'listing_id');
    }

    /**
     * Pemilik listing (owner).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Pengguna yang membuat permintaan handover.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
