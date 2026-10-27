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
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->enum('material_category', ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED']);
            $table->decimal('requested_weight_kg', 10, 2);
            $table->enum('minimum_condition', ['clean', 'slightly_dirty', 'dirty', 'mixed']);
            $table->string('general_location', 255);
            $table->string('intended_use', 500);
            $table->enum('status', ['active', 'fulfilled', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['status', 'material_category']);
            $table->index('requester_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
