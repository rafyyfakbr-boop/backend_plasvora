<?php

namespace Tests\Unit\Models;

use App\Models\MaterialListing;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderModelTest extends TestCase
{
    use RefreshDatabase;

    private function createOrderGraph(array $orderOverrides = []): array
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'name'                  => 'Botol PET Bening 10kg',
            'category'              => 'PET',
            'weight_kg'             => 10.00,
            'price'                 => 50000.00,
            'is_free'               => false,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, SUMUR BANDUNG, KOTA BANDUNG, JAWA BARAT, 40111',
            'image_url'             => 'materials/sample-pet.jpg',
            'status'                => 'reserved',
        ]);

        $address = UserAddress::create([
            'user_id'           => $buyer->id,
            'label'             => 'Rumah',
            'recipient_name'    => 'Budi Pembeli',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => 6157,
            'destination_label' => 'BABAKANKARET, CIANJUR, KAB. CIANJUR, JAWA BARAT, 43211',
            'address_detail'    => 'Jl. Raya Cianjur No. 12',
            'postal_code'       => '43211',
            'delivery_note'     => 'Dekat masjid',
            'is_default'        => true,
        ]);

        $order = Order::create(array_merge([
            'order_number'                   => 'PLV-20261122-0001',
            'idempotency_key'                => 'idem-key-0001',
            'buyer_id'                       => $buyer->id,
            'seller_id'                      => $seller->id,
            'listing_id'                     => $listing->id,
            'buyer_address_id'               => $address->id,
            'listing_name_snapshot'          => $listing->name,
            'listing_category_snapshot'      => $listing->category,
            'listing_weight_kg_snapshot'     => $listing->weight_kg,
            'listing_image_url_snapshot'     => $listing->getRawOriginal('image_url'),
            'origin_destination_id_snapshot' => $listing->origin_destination_id,
            'origin_label_snapshot'          => $listing->origin_label,
            'recipient_name_snapshot'        => $address->recipient_name,
            'recipient_phone_snapshot'       => $address->recipient_phone,
            'destination_id_snapshot'        => $address->destination_id,
            'destination_label_snapshot'     => $address->destination_label,
            'address_detail_snapshot'        => $address->address_detail,
            'postal_code_snapshot'           => $address->postal_code,
            'delivery_note_snapshot'         => $address->delivery_note,
            'item_price'                     => 50000,
            'shipping_cost'                  => 18000,
            'platform_fee'                   => 0,
            'total_amount'                   => 68000,
            'currency'                       => 'IDR',
            'courier_code'                   => 'jne',
            'courier_name'                   => 'Jalur Nugraha Ekakurir (JNE)',
            'courier_service'                => 'REG',
            'courier_description'            => 'Layanan Reguler',
            'estimated_etd'                  => '2-3 day',
            'status'                         => Order::STATUS_PENDING_PAYMENT,
            'payment_status'                 => Order::PAYMENT_UNPAID,
            'midtrans_order_id'              => 'PLV-MID-20261122-0001',
        ], $orderOverrides));

        return compact('seller', 'buyer', 'listing', 'address', 'order');
    }

    public function test_required_tables_columns_and_indexes_exist_without_duplicates(): void
    {
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_status_histories'));
        $this->assertTrue(Schema::hasTable('payment_webhook_events'));

        $this->assertTrue(Schema::hasColumns('orders', [
            'id',
            'order_number',
            'idempotency_key',
            'buyer_id',
            'seller_id',
            'listing_id',
            'buyer_address_id',
            'listing_name_snapshot',
            'listing_category_snapshot',
            'listing_weight_kg_snapshot',
            'listing_image_url_snapshot',
            'origin_destination_id_snapshot',
            'origin_label_snapshot',
            'recipient_name_snapshot',
            'recipient_phone_snapshot',
            'destination_id_snapshot',
            'destination_label_snapshot',
            'address_detail_snapshot',
            'postal_code_snapshot',
            'delivery_note_snapshot',
            'item_price',
            'shipping_cost',
            'platform_fee',
            'total_amount',
            'currency',
            'courier_code',
            'courier_name',
            'courier_service',
            'courier_description',
            'estimated_etd',
            'tracking_number',
            'shipment_notes',
            'shipped_at',
            'delivered_at',
            'completed_at',
            'status',
            'payment_status',
            'payment_expires_at',
            'paid_at',
            'cancelled_at',
            'cancellation_reason',
            'cancelled_by',
            'buyer_note',
            'midtrans_order_id',
            'midtrans_snap_token',
            'midtrans_redirect_url',
            'midtrans_transaction_id',
            'midtrans_payment_type',
            'midtrans_transaction_status',
            'midtrans_fraud_status',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('order_status_histories', [
            'id',
            'order_id',
            'from_status',
            'to_status',
            'actor_type',
            'actor_id',
            'source',
            'reason',
            'metadata',
            'created_at',
        ]));

        $this->assertTrue(Schema::hasColumns('payment_webhook_events', [
            'id',
            'order_id',
            'provider',
            'midtrans_order_id',
            'midtrans_transaction_id',
            'transaction_status',
            'fraud_status',
            'status_code',
            'gross_amount',
            'signature_valid',
            'payload_hash',
            'processing_result',
            'processed_at',
            'created_at',
            'updated_at',
        ]));

        // Pastikan kolom raw payload tidak pernah dibuat pada payment_webhook_events
        $this->assertFalse(Schema::hasColumn('payment_webhook_events', 'payload'));

        $orderIndexes   = collect(Schema::getIndexes('orders'))->pluck('name')->all();
        $historyIndexes = collect(Schema::getIndexes('order_status_histories'))->pluck('name')->all();
        $webhookIndexes = collect(Schema::getIndexes('payment_webhook_events'))->pluck('name')->all();

        foreach ([
            'orders_order_number_unique',
            'orders_midtrans_order_id_unique',
            'orders_buyer_idempotency_unique',
            'orders_buyer_status_created_idx',
            'orders_seller_status_created_idx',
            'orders_listing_status_idx',
            'orders_status_expires_idx',
        ] as $expectedIndex) {
            $this->assertContains($expectedIndex, $orderIndexes);
        }

        $this->assertContains('order_histories_order_created_idx', $historyIndexes);
        $this->assertContains('webhook_events_payload_hash_unique', $webhookIndexes);
        $this->assertContains('webhook_events_order_status_idx', $webhookIndexes);
        $this->assertContains('webhook_events_tx_id_idx', $webhookIndexes);
    }

    public function test_eloquent_relationships_and_casts_resolve_correctly(): void
    {
        $graph = $this->createOrderGraph();
        /** @var Order $order */
        $order = $graph['order'];
        /** @var User $buyer */
        $buyer = $graph['buyer'];
        /** @var User $seller */
        $seller = $graph['seller'];
        /** @var MaterialListing $listing */
        $listing = $graph['listing'];
        /** @var UserAddress $address */
        $address = $graph['address'];

        $history = OrderStatusHistory::create([
            'order_id'   => $order->id,
            'from_status'=> null,
            'to_status'  => Order::STATUS_PENDING_PAYMENT,
            'actor_type' => OrderStatusHistory::ACTOR_BUYER,
            'actor_id'   => $buyer->id,
            'source'     => 'checkout',
            'reason'     => 'Order dibuat',
            'metadata'   => ['courier_code' => 'jne'],
        ]);

        $webhook = PaymentWebhookEvent::create([
            'order_id'                => $order->id,
            'provider'                => 'midtrans',
            'midtrans_order_id'       => $order->midtrans_order_id,
            'midtrans_transaction_id' => 'tx-12345',
            'transaction_status'      => 'settlement',
            'fraud_status'            => 'accept',
            'status_code'             => '200',
            'gross_amount'            => '68000.00',
            'signature_valid'         => true,
            'payload_hash'            => hash('sha256', 'sample-event-1'),
            'processing_result'       => 'processed',
            'processed_at'            => now(),
        ]);

        $order->refresh();

        $this->assertSame($buyer->id, $order->buyer->id);
        $this->assertSame($seller->id, $order->seller->id);
        $this->assertSame($listing->id, $order->listing->id);
        $this->assertSame($address->id, $order->buyerAddress->id);
        $this->assertCount(1, $order->statusHistories);
        $this->assertCount(1, $order->webhookEvents);

        $this->assertCount(1, $buyer->buyerOrders);
        $this->assertCount(1, $seller->sellerOrders);
        $this->assertCount(1, $buyer->orderStatusHistories);
        $this->assertCount(1, $listing->orders);
        $this->assertCount(1, $address->orders);

        $this->assertSame($order->id, $history->order->id);
        $this->assertSame($buyer->id, $history->actor->id);
        $this->assertSame(['courier_code' => 'jne'], $history->metadata);

        $this->assertSame($order->id, $webhook->order->id);
        $this->assertTrue($webhook->signature_valid);

        $this->assertIsInt($order->item_price);
        $this->assertIsInt($order->shipping_cost);
        $this->assertIsInt($order->platform_fee);
        $this->assertIsInt($order->total_amount);
        $this->assertSame(68000, $order->total_amount);
    }

    public function test_active_listing_reservation_helper_identifies_locking_statuses(): void
    {
        $this->assertSame([
            Order::STATUS_PENDING_PAYMENT,
            Order::STATUS_PAID,
            Order::STATUS_SHIPPED,
        ], Order::activeReservationStatuses());

        $activeOrder = new Order(['status' => Order::STATUS_PAID]);
        $finalOrder  = new Order(['status' => Order::STATUS_COMPLETED]);
        $expired     = new Order(['status' => Order::STATUS_EXPIRED]);

        $this->assertTrue($activeOrder->holdsActiveListingReservation());
        $this->assertFalse($finalOrder->holdsActiveListingReservation());
        $this->assertFalse($expired->holdsActiveListingReservation());
    }

    public function test_deleting_buyer_address_sets_null_while_preserving_snapshots_and_restricts_listing_deletion(): void
    {
        $graph = $this->createOrderGraph();
        /** @var Order $order */
        $order   = $graph['order'];
        $address = $graph['address'];
        $listing = $graph['listing'];

        // Hapus alamat tersimpan: FK menjadi null tetapi snapshot tetap utuh
        $address->delete();
        $order->refresh();

        $this->assertNull($order->buyer_address_id);
        $this->assertSame('Budi Pembeli', $order->recipient_name_snapshot);
        $this->assertSame('Jl. Raya Cianjur No. 12', $order->address_detail_snapshot);

        // Hapus listing yang sudah punya order harus ditolak oleh restrictOnDelete
        $this->expectException(QueryException::class);
        $listing->delete();
    }

    public function test_unique_constraints_enforce_order_idempotency_and_webhook_deduplication(): void
    {
        $graph = $this->createOrderGraph();
        /** @var Order $order */
        $order = $graph['order'];

        // Duplikasi (buyer_id, idempotency_key) harus ditolak
        try {
            Order::create(array_merge($order->only([
                'buyer_id',
                'seller_id',
                'listing_id',
                'buyer_address_id',
                'listing_name_snapshot',
                'listing_category_snapshot',
                'listing_weight_kg_snapshot',
                'origin_destination_id_snapshot',
                'origin_label_snapshot',
                'recipient_name_snapshot',
                'recipient_phone_snapshot',
                'destination_id_snapshot',
                'destination_label_snapshot',
                'address_detail_snapshot',
                'item_price',
                'shipping_cost',
                'platform_fee',
                'total_amount',
                'currency',
                'courier_code',
                'courier_name',
                'courier_service',
                'status',
                'payment_status',
            ]), [
                'order_number'      => 'PLV-20261122-0002',
                'idempotency_key'   => $order->idempotency_key,
                'midtrans_order_id' => 'PLV-MID-20261122-0002',
            ]));
            $this->fail('Expected QueryException on duplicate buyer_id + idempotency_key.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Duplikasi payload_hash webhook harus ditolak
        $hash = hash('sha256', 'duplicate-webhook-event');
        PaymentWebhookEvent::create([
            'order_id'        => $order->id,
            'provider'        => 'midtrans',
            'signature_valid' => true,
            'payload_hash'    => $hash,
        ]);

        $this->expectException(QueryException::class);
        PaymentWebhookEvent::create([
            'order_id'        => $order->id,
            'provider'        => 'midtrans',
            'signature_valid' => true,
            'payload_hash'    => $hash,
        ]);
    }
}
