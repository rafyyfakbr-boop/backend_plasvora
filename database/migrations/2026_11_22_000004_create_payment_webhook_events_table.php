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
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('provider', 30)->default('midtrans');
            $table->string('midtrans_order_id', 64)->nullable();
            $table->string('midtrans_transaction_id', 100)->nullable();
            $table->string('transaction_status', 50)->nullable();
            $table->string('fraud_status', 30)->nullable();
            $table->string('status_code', 10)->nullable();
            $table->string('gross_amount', 30)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('payload_hash', 64)->unique('webhook_events_payload_hash_unique');
            $table->string('processing_result', 50)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['midtrans_order_id', 'transaction_status'], 'webhook_events_order_status_idx');
            $table->index('midtrans_transaction_id', 'webhook_events_tx_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
