<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('handover_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('material_listings')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->decimal('requested_weight_kg', 10, 2);
            $table->decimal('agreed_weight_kg', 10, 2)->nullable();
            $table->decimal('actual_weight_kg', 10, 2)->nullable();
            $table->text('note')->nullable();
            $table->enum('status', [
                'pending',
                'approved',
                'handed_over',
                'utilization_confirmed',
                'rejected',
                'cancelled',
            ])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('utilization_confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'status']);
            $table->index('owner_id');
            $table->index('requester_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('handover_requests');
    }
};
