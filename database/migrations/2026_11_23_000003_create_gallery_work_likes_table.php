<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_work_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_work_id')
                ->constrained('gallery_works')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['gallery_work_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_work_likes');
    }
};
