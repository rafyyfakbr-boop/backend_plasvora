<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publish legacy active gallery works that were created under the old
     * pre-moderation default (`status = 'pending'`).
     *
     * Scope guarantees:
     * - Updates ONLY rows with `status = 'pending'` and `deleted_at IS NULL`.
     * - Leaves `rejected` works, soft-deleted works, and already-approved works untouched.
     * - Preserves `reviewed_at = NULL` so `down()` can safely identify and revert
     *   unreviewed published records if rolled back.
     */
    public function up(): void
    {
        if (! Schema::hasTable('gallery_works')) {
            return;
        }

        DB::table('gallery_works')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->update([
                'status'       => 'approved',
                'published_at' => DB::raw('COALESCE(published_at, created_at, CURRENT_TIMESTAMP)'),
                'updated_at'   => now(),
            ]);
    }

    /**
     * Revert only active approved works that have no explicit moderation
     * review timestamp (`reviewed_at IS NULL`).
     */
    public function down(): void
    {
        if (! Schema::hasTable('gallery_works')) {
            return;
        }

        DB::table('gallery_works')
            ->where('status', 'approved')
            ->whereNull('reviewed_at')
            ->whereNull('deleted_at')
            ->update([
                'status'       => 'pending',
                'published_at' => null,
                'updated_at'   => now(),
            ]);
    }
};
