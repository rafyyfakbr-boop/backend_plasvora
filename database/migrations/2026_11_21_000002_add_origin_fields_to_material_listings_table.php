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
        Schema::table('material_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('origin_destination_id')->nullable()->after('general_location');
            $table->string('origin_label', 255)->nullable()->after('origin_destination_id');

            $table->index('origin_destination_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_listings', function (Blueprint $table) {
            $table->dropIndex(['origin_destination_id']);
            $table->dropColumn(['origin_destination_id', 'origin_label']);
        });
    }
};
