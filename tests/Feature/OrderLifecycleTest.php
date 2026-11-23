<?php

namespace Tests\Feature;

use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function createOrderFixture(array $orderOverrides = [], string $listingStatus = 'reserved', float $weightKg = 10.0): array
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'name'                  => 'Botol PET Bening 10kg',
            'category'              => 'PET',
            'weight_kg'             => $weightKg,
            'price'                 => 50000.00,
            'is_free'               => false,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, BANDUNG',
            'status'                => $listingStatus,
        ]);

        $address = UserAddress::create([
            'user_id'           => $buyer->id,
            'label'             => 'Rumah Rahasia',
            'recipient_name'    => 'Budi Rahasia',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => 6157,
            'destination_label' => 'BABAKANKARET, CIANJUR',
            'address_detail'    => 'Jl. Sangat Rahasia No. 99',
            'postal_code'       => '43211',
            'delivery_note'     => 'Titip satpam',
            'is_default'        => true,
        ]);

        $order = Order::create(array_merge([
            'order_number'                   => 'PLV-20261122-' . uniqid(),
            'idempotency_key'                => 'idem-' . uniqid(),
            'buyer_id'                       => $buyer->id,
            'seller_id'                      => $seller->id,
            'listing_id'                     => $listing->id,
            'buyer_address_id'               => $address->id,
            'listing_name_snapshot'          => $listing->name,
            'listing_category_snapshot'      => $listing->category,
            'listing_weight_kg_snapshot'     => $weightKg,
            'origin_destination_id_snapshot' => 4949,
            'origin_label_snapshot'          => 'BRAGA, BANDUNG',
            'recipient_name_snapshot'        => $address->recipient_name,
            'recipient_phone_snapshot'       => $address->recipient_phone,
            'destination_id_snapshot'        => 6157,
            'destination_label_snapshot'     => 'BABAKANKARET, CIANJUR',
            'address_detail_snapshot'        => $address->address_detail,
            'postal_code_snapshot'           => $address->postal_code,
            'delivery_note_snapshot'         => $address->delivery_note,
            'item_price'                     => 50000,
            'shipping_cost'                  => 18000,
            'platform_fee'                   => 0,
            'total_amount'                   => 68000,
            'currency'                       => 'IDR',
            'courier_code'                   => 'jne',
            'courier_name'                   => 'JNE',
            'courier_service'                => 'REG',
            'status'                         => Order::STATUS_PENDING_PAYMENT,
            'payment_status'                 => Order::PAYMENT_UNPAID,
            'payment_expires_at'             => now()->addHours(24),
            'midtrans_order_id'              => 'PLV-MID-' . uniqid(),
            'midtrans_snap_token'            => 'snap-secret-tok',
            'midtrans_redirect_url'          => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-secret-tok',
        ], $orderOverrides));

        return compact('seller', 'buyer', 'listing', 'address', 'order');
    }

    public function test_authorization_and_buyer_pii_privacy_before_and_after_payment(): void
    {
        ['seller' => $seller, 'buyer' => $buyer, 'order' => $order] = $this->createOrderFixture();
        $outsider = User::factory()->create();

        // 1. Outsider tidak bisa mengakses detail atau aksi pesanan (404 anti-enumerasi)
        $this->actingAs($outsider, 'sanctum')->getJson("/api/orders/{$order->id}")->assertStatus(404);
        $this->actingAs($outsider, 'sanctum')->postJson("/api/orders/{$order->id}/ship", ['tracking_number' => 'JNE123456'])->assertStatus(404);
        $this->actingAs($outsider, 'sanctum')->postJson("/api/orders/{$order->id}/confirm-delivery")->assertStatus(404);
        $this->actingAs($outsider, 'sanctum')->postJson("/api/orders/{$order->id}/retry-payment")->assertStatus(404);

        // 2. Role peserta salah mendapat 403
        $this->actingAs($buyer, 'sanctum')->postJson("/api/orders/{$order->id}/ship", ['tracking_number' => 'JNE123456'])->assertStatus(403);
        $this->actingAs($seller, 'sanctum')->postJson("/api/orders/{$order->id}/cancel")->assertStatus(403);
        $this->actingAs($seller, 'sanctum')->postJson("/api/orders/{$order->id}/confirm-delivery")->assertStatus(403);
        $this->actingAs($seller, 'sanctum')->postJson("/api/orders/{$order->id}/retry-payment")->assertStatus(403);
        $this->actingAs($seller, 'sanctum')->postJson("/api/orders/{$order->id}/refresh-payment")->assertStatus(403);

        // 3. Pembeli selalu melihat alamat lengkap miliknya sendiri
        $buyerDetail = $this->actingAs($buyer, 'sanctum')->getJson("/api/orders/{$order->id}")->assertStatus(200);
        $buyerDetail->assertJsonPath('data.shipping_address.recipient_phone', '6281234567890')
            ->assertJsonPath('data.shipping_address.address_detail', 'Jl. Sangat Rahasia No. 99')
            ->assertJsonPath('data.payment.snap_token', 'snap-secret-tok');

        // 4. Penjual SEBELUM pembayaran tidak menerima telepon, alamat lengkap, kode pos, catatan, ataupun snap_token
        $sellerUnpaid = $this->actingAs($seller, 'sanctum')->getJson("/api/orders/{$order->id}")->assertStatus(200);
        $sellerAddrUnpaid = $sellerUnpaid->json('data.shipping_address');
        $this->assertArrayHasKey('destination_label', $sellerAddrUnpaid);
        $this->assertArrayNotHasKey('recipient_phone', $sellerAddrUnpaid);
        $this->assertArrayNotHasKey('address_detail', $sellerAddrUnpaid);
        $this->assertArrayNotHasKey('postal_code', $sellerAddrUnpaid);
        $this->assertArrayNotHasKey('delivery_note', $sellerAddrUnpaid);
        $this->assertArrayNotHasKey('snap_token', $sellerUnpaid->json('data.payment'));

        // 5. Setelah pembayaran terverifikasi (paid), penjual menerima snapshot alamat pengiriman lengkap
        $order->update([
            'status'         => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at'        => now(),
        ]);

        $sellerPaid = $this->actingAs($seller, 'sanctum')->getJson("/api/orders/{$order->id}")->assertStatus(200);
        $sellerPaid->assertJsonPath('data.shipping_address.recipient_name', 'Budi Rahasia')
            ->assertJsonPath('data.shipping_address.recipient_phone', '6281234567890')
            ->assertJsonPath('data.shipping_address.address_detail', 'Jl. Sangat Rahasia No. 99')
            ->assertJsonPath('data.shipping_address.postal_code', '43211');

        // 6. Order list ter-scope per role dan tidak membocorkan PII pembeli
        $buyerList = $this->actingAs($buyer, 'sanctum')->getJson('/api/orders?role=buyer')->assertStatus(200);
        $buyerList->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('shipping_address', $buyerList->json('data.0'));

        $this->actingAs($buyer, 'sanctum')->getJson('/api/orders?role=seller')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->actingAs($seller, 'sanctum')->getJson('/api/orders?role=seller')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->actingAs($outsider, 'sanctum')->getJson('/api/orders?role=buyer')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_full_fulfillment_flow_paid_to_shipped_to_completed_with_guards_and_safe_duplicate_confirm(): void
    {
        ['seller' => $seller, 'buyer' => $buyer, 'listing' => $listing, 'order' => $order] = $this->createOrderFixture();

        // Penjual tidak bisa kirim order yang masih pending_payment (409)
        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/orders/{$order->id}/ship", ['tracking_number' => 'JNE9999999'])
            ->assertStatus(409);

        // Pembeli tidak bisa konfirmasi penerimaan sebelum status shipped (409)
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-delivery")
            ->assertStatus(409);

        // Tandai paid
        $order->update([
            'status'         => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at'        => now(),
        ]);

        // Penjual kirim dengan nomor resi
        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/orders/{$order->id}/ship", [
                'tracking_number' => '  JNE0011223344  ',
                'shipment_notes'  => 'Dikemas pakai karung rapat',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_SHIPPED)
            ->assertJsonPath('data.tracking_number', 'JNE0011223344')
            ->assertJsonPath('data.shipment_notes', 'Dikemas pakai karung rapat');

        // Pengiriman ulang / duplikat ditolak (409)
        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/orders/{$order->id}/ship", ['tracking_number' => 'JNE99999999'])
            ->assertStatus(409);

        // Pembeli konfirmasi penerimaan -> order & listing menjadi completed
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-delivery")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_COMPLETED);

        $order->refresh();
        $this->assertNotNull($order->delivered_at);
        $this->assertNotNull($order->completed_at);
        $this->assertSame('completed', $listing->fresh()->status);

        // Konfirmasi penerimaan ganda aman dan tidak membuat duplikat history
        $historyCountBefore = OrderStatusHistory::where('order_id', $order->id)->count();
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-delivery")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_COMPLETED);

        $this->assertSame($historyCountBefore, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_listing_mutation_and_handover_contention_guards_protect_active_orders(): void
    {
        ['seller' => $seller, 'listing' => $listing] = $this->createOrderFixture([
            'status'         => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at'        => now(),
        ]);
        $otherUser = User::factory()->create();

        // 1. Pemilik tidak boleh edit atau nonaktifkan listing yang terikat order aktif (409)
        $this->actingAs($seller, 'sanctum')
            ->patchJson("/api/materials/{$listing->id}", ['price' => 99999])
            ->assertStatus(409);

        $this->actingAs($seller, 'sanctum')
            ->deleteJson("/api/materials/{$listing->id}")
            ->assertStatus(409);

        // 2. Pemilik tidak boleh men-set status 'reserved'/'completed' secara manual pada listing available (422)
        $freeListing = MaterialListing::factory()->create(['owner_id' => $seller->id, 'status' => 'available']);
        $this->actingAs($seller, 'sanctum')
            ->patchJson("/api/materials/{$freeListing->id}", ['status' => 'reserved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 3. Handover tidak boleh dibuat atau disetujui jika listing memiliki active Order
        // Simulasi edge case jika listing status masih available tetapi sudah ada active order
        $listing->update(['status' => 'available']);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id'          => $listing->id,
                'requested_weight_kg' => 5,
            ])
            ->assertStatus(409);

        $pendingHandover = HandoverRequest::factory()->create([
            'listing_id'   => $listing->id,
            'owner_id'     => $seller->id,
            'requester_id' => $otherUser->id,
            'status'       => 'pending',
        ]);

        $this->actingAs($seller, 'sanctum')
            ->patchJson("/api/handover-requests/{$pendingHandover->id}/status", ['status' => 'approved'])
            ->assertStatus(409);
    }

    public function test_activity_and_impact_summary_include_orders_without_pii_or_double_counting(): void
    {
        // 1. Completed order (12 kg) -> dihitung di impact summary
        ['seller' => $seller, 'buyer' => $buyer, 'order' => $completedOrder] = $this->createOrderFixture([
            'status'         => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at'        => now()->subDays(2),
            'shipped_at'     => now()->subDay(),
            'delivered_at'   => now(),
            'completed_at'   => now(),
        ], listingStatus: 'completed', weightKg: 12.0);

        OrderStatusHistory::create([
            'order_id'    => $completedOrder->id,
            'from_status' => Order::STATUS_SHIPPED,
            'to_status'   => Order::STATUS_COMPLETED,
            'actor_type'  => OrderStatusHistory::ACTOR_BUYER,
            'actor_id'    => $buyer->id,
            'source'      => 'buyer_confirm_delivery',
        ]);

        // 2. Shipped (belum completed) & pending_payment order -> TIDAK boleh dihitung di impact summary
        $this->createOrderFixture([
            'status'         => Order::STATUS_SHIPPED,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at'        => now()->subHour(),
            'shipped_at'     => now(),
        ], listingStatus: 'reserved', weightKg: 7.0);

        $this->createOrderFixture([
            'status'         => Order::STATUS_PENDING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
        ], listingStatus: 'reserved', weightKg: 9.0);

        // 3. Handover utilization_confirmed (15 kg) pada listing terpisah + guard no double-counting
        $handoverListing = MaterialListing::factory()->create(['owner_id' => $seller->id, 'status' => 'completed', 'weight_kg' => 15.0]);
        HandoverRequest::factory()->create([
            'listing_id'               => $handoverListing->id,
            'owner_id'                 => $seller->id,
            'requester_id'             => $buyer->id,
            'status'                   => 'utilization_confirmed',
            'actual_weight_kg'         => 15.0,
            'approved_at'              => now()->subDays(3),
            'handed_over_at'           => now()->subDays(2),
            'utilization_confirmed_at' => now()->subDay(),
        ]);

        // Cek Impact Summary: 15 kg (handover) + 12 kg (completed order) = 27 kg, count = 2
        $impact = $this->getJson('/api/impact-summary')->assertStatus(200);
        $this->assertEquals(2, $impact->json('data.materials_handed_over.count'));
        $this->assertEquals(27.0, $impact->json('data.materials_handed_over.weight_kg'));
        $this->assertEquals(2, $impact->json('data.materials_utilized.count'));
        $this->assertEquals(27.0, $impact->json('data.materials_utilized.weight_kg'));

        // Cek Activity: muncul untuk buyer & seller tanpa membocorkan alamat/telepon pembeli
        $buyerAct = $this->actingAs($buyer, 'sanctum')->getJson('/api/activities')->assertStatus(200);
        $this->assertStringNotContainsString('6281234567890', $buyerAct->getContent());
        $this->assertStringNotContainsString('Jl. Sangat Rahasia No. 99', $buyerAct->getContent());
        $this->assertTrue(collect($buyerAct->json('data'))->contains(
            fn ($item) => $item['type'] === 'order' && $item['reference_id'] === $completedOrder->id && $item['status'] === 'completed'
        ));

        $sellerAct = $this->actingAs($seller, 'sanctum')->getJson('/api/activities')->assertStatus(200);
        $this->assertStringNotContainsString('6281234567890', $sellerAct->getContent());
        $this->assertTrue(collect($sellerAct->json('data'))->contains(
            fn ($item) => $item['type'] === 'order' && $item['reference_id'] === $completedOrder->id
        ));
    }
}
