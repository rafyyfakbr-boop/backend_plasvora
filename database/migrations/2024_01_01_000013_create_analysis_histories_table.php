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
        Schema::create('analysis_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('declared_category', ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'])->nullable();
            $table->enum('estimated_category', ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'])->nullable();
            $table->decimal('weight_kg', 10, 2)->nullable();
            $table->enum('condition', ['clean', 'slightly_dirty', 'dirty', 'mixed'])->nullable();
            $table->string('input_image_path', 500)->nullable();
            $table->json('recommendations')->nullable();
            $table->boolean('is_demo_result')->default(true);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_histories');
    }
};
