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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique('orders_order_number_unique');
            $table->string('idempotency_key', 64);

            // Parties & listing relationships
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('listing_id')->constrained('material_listings')->restrictOnDelete();
            $table->foreignId('buyer_address_id')->nullable()->constrained('user_addresses')->nullOnDelete();

            // Immutable listing snapshot at checkout time
            $table->string('listing_name_snapshot', 255);
            $table->string('listing_category_snapshot', 32);
            $table->decimal('listing_weight_kg_snapshot', 10, 2);
            $table->string('listing_image_url_snapshot', 500)->nullable();
            $table->unsignedBigInteger('origin_destination_id_snapshot');
            $table->string('origin_label_snapshot', 255);

            // Immutable shipping address snapshot at checkout time
            $table->string('recipient_name_snapshot', 120);
            $table->string('recipient_phone_snapshot', 20);
            $table->unsignedBigInteger('destination_id_snapshot');
            $table->string('destination_label_snapshot', 255);
            $table->text('address_detail_snapshot');
            $table->string('postal_code_snapshot', 10)->nullable();
            $table->string('delivery_note_snapshot', 255)->nullable();

            // Server-authoritative pricing in whole IDR Rupiah
            $table->unsignedBigInteger('item_price');
            $table->unsignedBigInteger('shipping_cost');
            $table->unsignedBigInteger('platform_fee')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->string('currency', 3)->default('IDR');

            // Shipping snapshot & manual fulfillment
            $table->string('courier_code', 30);
            $table->string('courier_name', 100);
            $table->string('courier_service', 50);
            $table->string('courier_description', 255)->nullable();
            $table->string('estimated_etd', 50)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('shipment_notes', 500)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Order & payment lifecycle (string columns, no DB ENUM)
            $table->string('status', 30)->default('pending_payment');
            $table->string('payment_status', 30)->default('unpaid');
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('buyer_note', 500)->nullable();

            // Midtrans transaction references
            $table->string('midtrans_order_id', 64)->unique('orders_midtrans_order_id_unique');
            $table->string('midtrans_snap_token', 255)->nullable();
            $table->string('midtrans_redirect_url', 500)->nullable();
            $table->string('midtrans_transaction_id', 100)->nullable();
            $table->string('midtrans_payment_type', 50)->nullable();
            $table->string('midtrans_transaction_status', 50)->nullable();
            $table->string('midtrans_fraud_status', 30)->nullable();

            $table->timestamps();

            $table->unique(['buyer_id', 'idempotency_key'], 'orders_buyer_idempotency_unique');
            $table->index(['buyer_id', 'status', 'created_at'], 'orders_buyer_status_created_idx');
            $table->index(['seller_id', 'status', 'created_at'], 'orders_seller_status_created_idx');
            $table->index(['listing_id', 'status'], 'orders_listing_status_idx');
            $table->index(['status', 'payment_expires_at'], 'orders_status_expires_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
