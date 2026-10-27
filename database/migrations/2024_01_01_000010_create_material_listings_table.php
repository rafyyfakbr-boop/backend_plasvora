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
        Schema::create('material_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 255);
            $table->enum('category', ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED']);
            $table->decimal('weight_kg', 10, 2);
            $table->enum('condition', ['clean', 'slightly_dirty', 'dirty', 'mixed']);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->string('general_location', 255);
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->enum('status', ['available', 'reserved', 'completed', 'inactive'])->default('available');
            $table->timestamps();

            $table->index(['status', 'category']);
            $table->index('owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_listings');
    }
};
