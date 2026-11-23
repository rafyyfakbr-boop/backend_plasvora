<?php

namespace Tests\Feature;

use App\Models\MaterialListing;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_RAJAONGKIR_KEY = 'fake-rajaongkir-key-checkout-test';
    private const FAKE_SERVER_KEY     = 'SB-Mid-server-FAKE-CHECKOUT-001';
    private const FAKE_SIG_KEY        = 'FAKE-SIGNATURE-SECRET-CHECKOUT-001';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('services.rajaongkir', [
            'api_key'          => self::FAKE_RAJAONGKIR_KEY,
            'base_url'         => 'https://rajaongkir.komerce.id/api/v1/',
            'couriers'         => 'jne:jnt',
            'price_mode'       => 'lowest',
            'timeout'          => 15,
            'connect_timeout'  => 5,
            'search_cache_ttl' => 0,
        ]);

        config()->set('services.midtrans', [
            'server_key'                    => self::FAKE_SERVER_KEY,
            'signature_secret_key'          => self::FAKE_SIG_KEY,
            'signature_secret_key_previous' => null,
            'is_production'                 => false,
            'snap_base_url'                 => MidtransService::SANDBOX_SNAP_BASE_URL,
            'api_base_url'                  => MidtransService::SANDBOX_API_BASE_URL,
            'timeout'                       => 15,
            'payment_expiry_minutes'        => 1440,
        ]);
    }

    private function createBuyerAddress(User $buyer, int $destinationId = 6157): UserAddress
    {
        return UserAddress::create([
            'user_id'           => $buyer->id,
            'label'             => 'Rumah',
            'recipient_name'    => 'Budi Pembeli',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => $destinationId,
            'destination_label' => 'BABAKANKARET, CIANJUR, KAB. CIANJUR, JAWA BARAT, 43211',
            'address_detail'    => 'Jl. Raya Cianjur No. 12 RT 01/RW 02',
            'postal_code'       => '43211',
            'delivery_note'     => 'Pagar hijau',
            'is_default'        => true,
        ]);
    }

    private function createPaidListing(User $seller, array $overrides = []): MaterialListing
    {
        return MaterialListing::factory()->create(array_merge([
            'owner_id'              => $seller->id,
            'name'                  => 'Botol PET Bening Bersih 10kg',
            'category'              => 'PET',
            'weight_kg'             => 10.00,
            'price'                 => 50000.00,
            'is_free'               => false,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, SUMUR BANDUNG, KOTA BANDUNG, JAWA BARAT, 40111',
            'image_url'             => 'materials/pet-lot.jpg',
            'status'                => 'available',
        ], $overrides));
    }

    private function fakeRajaOngkirAndSnapSuccess(): void
    {
        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Success', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'name'        => 'Jalur Nugraha Ekakurir (JNE)',
                        'code'        => 'jne',
                        'service'     => 'REG',
                        'description' => 'Layanan Reguler',
                        'cost'        => 18000,
                        'etd'         => '2-3 day',
                    ],
                    [
                        'name'        => 'J&T Express',
                        'code'        => 'jnt',
                        'service'     => 'EZ',
                        'description' => 'Reguler',
                        'cost'        => 16000,
                        'etd'         => '2-3 day',
                    ],
                ],
            ], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token'        => 'snap-token-001',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-001',
            ], 201),
        ]);
    }

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson('/api/orders', [
            'listing_id'      => 1,
            'address_id'      => 1,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'guest-idem-001',
        ])->assertStatus(401);
    }

    public function test_checkout_validation_rejects_self_purchase_free_unavailable_missing_origin_other_address_and_fractional_price(): void
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();
        $other  = User::factory()->create();

        $sellerAddress = $this->createBuyerAddress($seller);
        $buyerAddress  = $this->createBuyerAddress($buyer);
        $otherAddress  = $this->createBuyerAddress($other);

        $paidListing        = $this->createPaidListing($seller);
        $freeListing        = $this->createPaidListing($seller, ['is_free' => true, 'price' => 0]);
        $reservedListing    = $this->createPaidListing($seller, ['status' => 'reserved']);
        $noOriginListing    = $this->createPaidListing($seller, ['origin_destination_id' => null, 'origin_label' => null]);
        $fractionalListing  = $this->createPaidListing($seller, ['price' => 50000.50]);

        // 1. Self-purchase ditolak (422)
        $this->actingAs($seller, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $paidListing->id,
            'address_id'      => $sellerAddress->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'self-buy-001',
        ])->assertStatus(422);

        // 2. Listing gratis ditolak (422)
        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $freeListing->id,
            'address_id'      => $buyerAddress->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'free-buy-001',
        ])->assertStatus(422);

        // 3. Listing tidak available ditolak (409)
        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $reservedListing->id,
            'address_id'      => $buyerAddress->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'reserved-buy-001',
        ])->assertStatus(409);

        // 4. Listing tanpa origin ditolak (422)
        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $noOriginListing->id,
            'address_id'      => $buyerAddress->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'no-origin-001',
        ])->assertStatus(422);

        // 5. Alamat milik user lain ditolak (404 IDOR protection)
        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $paidListing->id,
            'address_id'      => $otherAddress->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'other-addr-001',
        ])->assertStatus(404);

        // 6. Harga pecahan Rupiah (50000.50) ditolak (422)
        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $fractionalListing->id,
            'address_id'      => $buyerAddress->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'frac-price-001',
        ])->assertStatus(422);

        // 7. Client-supplied price/shipping/total ditolak pada validasi (422)
        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $paidListing->id,
            'address_id'      => $buyerAddress->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'tamper-001',
            'item_price'      => 1,
            'shipping_cost'   => 0,
            'total_amount'    => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['item_price', 'shipping_cost', 'total_amount']);

        $this->assertSame(0, Order::count());
    }

    public function test_invalid_courier_service_selection_is_rejected(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $listing = $this->createPaidListing($seller);
        $address = $this->createBuyerAddress($buyer);

        $this->fakeRajaOngkirAndSnapSuccess();

        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'YES_TIDAK_ADA',
            'idempotency_key' => 'bad-courier-001',
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame('available', $listing->fresh()->status);
    }

    public function test_successful_checkout_creates_immutable_snapshots_authoritative_total_valid_snap_payload_and_reserves_listing(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $listing = $this->createPaidListing($seller, ['price' => 50000.00, 'weight_kg' => 2.50]);
        $address = $this->createBuyerAddress($buyer);

        $this->fakeRajaOngkirAndSnapSuccess();

        $response = $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'buyer_note'      => 'Mohon dikemas rapi',
            'idempotency_key' => 'checkout-ok-001',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Order::STATUS_PENDING_PAYMENT)
            ->assertJsonPath('data.payment_status', Order::PAYMENT_UNPAID)
            ->assertJsonPath('data.item_price', 50000)
            ->assertJsonPath('data.shipping_cost', 18000)
            ->assertJsonPath('data.platform_fee', 0)
            ->assertJsonPath('data.total_amount', 68000)
            ->assertJsonPath('data.payment.snap_token', 'snap-token-001')
            ->assertJsonPath('data.payment.redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-001');

        // Listing langsung menjadi reserved
        $this->assertSame('reserved', $listing->fresh()->status);

        // History awal tercatat
        $order = Order::firstOrFail();
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());

        // Verifikasi payload Snap memiliki sum(item_details) === gross_amount serta expiry.start_time +0700 & durasi konsisten
        $expectedStartTimeWib = $order->created_at->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s O');
        $this->assertStringEndsWith(' +0700', $expectedStartTimeWib);
        $this->assertSame(
            $order->created_at->copy()->addMinutes(1440)->timestamp,
            $order->payment_expires_at->timestamp
        );

        Http::assertSent(function (HttpClientRequest $req) use ($order, $expectedStartTimeWib): bool {
            if ($req->url() !== 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return false;
            }

            $gross = $req['transaction_details']['gross_amount'] ?? 0;
            $items = $req['item_details'] ?? [];
            $sum   = collect($items)->sum(fn ($i) => ($i['price'] ?? 0) * ($i['quantity'] ?? 0));

            return $req['transaction_details']['order_id'] === $order->midtrans_order_id
                && $gross === 68000
                && $sum === 68000
                && ($req['page_expiry']['unit'] ?? null) === 'minutes'
                && ($req['page_expiry']['duration'] ?? null) === 1440
                && ($req['expiry']['unit'] ?? null) === 'minutes'
                && ($req['expiry']['duration'] ?? null) === 1440
                && ($req['expiry']['start_time'] ?? null) === $expectedStartTimeWib;
        });

        // Snapshot alamat tetap utuh meskipun buyer mengubah alamat di Address Book
        $address->update(['address_detail' => 'Jl. Baru Berubah Total']);
        $order->refresh();
        $this->assertSame('Jl. Raya Cianjur No. 12 RT 01/RW 02', $order->address_detail_snapshot);
    }

    public function test_second_buyer_cannot_reserve_same_listing_and_idempotency_works_for_same_buyer(): void
    {
        $seller  = User::factory()->create();
        $buyerA  = User::factory()->create();
        $buyerB  = User::factory()->create();
        $listing = $this->createPaidListing($seller);
        $addrA   = $this->createBuyerAddress($buyerA);
        $addrB   = $this->createBuyerAddress($buyerB);

        $this->fakeRajaOngkirAndSnapSuccess();

        // Pembeli A checkout pertama kali -> 201
        $first = $this->actingAs($buyerA, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $addrA->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'idem-A-001',
        ])->assertStatus(201);

        $orderId = $first->json('data.id');

        // Pembeli A replay dengan idempotency_key & payload identik -> 200 dengan order yang sama (tanpa duplikasi)
        $replay = $this->actingAs($buyerA, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $addrA->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'idem-A-001',
        ])->assertStatus(200);

        $this->assertSame($orderId, $replay->json('data.id'));
        $this->assertSame(1, Order::count());

        // Pembeli A memakai idempotency_key yang sama dengan payload berbeda -> 409 Conflict
        $this->actingAs($buyerA, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $addrA->id,
            'courier_code'    => 'jnt',
            'courier_service' => 'EZ',
            'idempotency_key' => 'idem-A-001',
        ])->assertStatus(409);

        // Pembeli B mencoba membeli listing yang sama -> 409 Conflict
        $this->actingAs($buyerB, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $addrB->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'idem-B-001',
        ])->assertStatus(409);

        $this->assertSame(1, Order::count());
    }

    public function test_rajaongkir_timeout_creates_no_order_and_keeps_listing_available(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $listing = $this->createPaidListing($seller);
        $address = $this->createBuyerAddress($buyer);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: RajaOngkir timed out');
        });

        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'ro-timeout-001',
        ])->assertStatus(504);

        $this->assertSame(0, Order::count());
        $this->assertSame('available', $listing->fresh()->status);
    }

    public function test_snap_permanent_failure_cancels_order_and_releases_listing(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $listing = $this->createPaidListing($seller);
        $address = $this->createBuyerAddress($buyer);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'data' => [[
                    'name'        => 'JNE',
                    'code'        => 'jne',
                    'service'     => 'REG',
                    'description' => 'Reguler',
                    'cost'        => 18000,
                    'etd'         => '2-3 day',
                ]],
            ], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'error_messages' => ['Access denied due to unauthorized transaction'],
            ], 401),
        ]);

        $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'snap-fail-401',
        ])->assertStatus(503);

        $order = Order::firstOrFail();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(Order::PAYMENT_FAILED, $order->payment_status);
        $this->assertSame('available', $listing->fresh()->status);
    }

    public function test_snap_timeout_preserves_reservation_and_retry_payment_reuses_same_order_and_midtrans_id(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $listing = $this->createPaidListing($seller);
        $address = $this->createBuyerAddress($buyer);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'data' => [[
                    'name'        => 'JNE',
                    'code'        => 'jne',
                    'service'     => 'REG',
                    'description' => 'Reguler',
                    'cost'        => 18000,
                    'etd'         => '2-3 day',
                ]],
            ], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => function () {
                throw new ConnectionException('cURL error 28: Midtrans Snap timed out');
            },
        ]);

        $checkout = $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'snap-timeout-001',
        ]);

        $checkout->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.reconciliation_required', true);

        $order = Order::firstOrFail();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame('reserved', $listing->fresh()->status);
        $this->assertNull($order->midtrans_redirect_url);

        $originalMidtransOrderId = $order->midtrans_order_id;

        // Sekarang pembeli memanggil retry-payment: Midtrans status 404 -> buat Snap baru dengan midtrans_order_id SAMA
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$originalMidtransOrderId}/status" => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token'        => 'snap-retry-token-999',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-retry-token-999',
            ], 201),
        ]);

        $retry = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/retry-payment")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.payment.midtrans_order_id', $originalMidtransOrderId)
            ->assertJsonPath('data.payment.redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-retry-token-999');

        $this->assertSame(1, Order::count());
        $this->assertSame($originalMidtransOrderId, $order->fresh()->midtrans_order_id);
        $this->assertSame('reserved', $listing->fresh()->status);

        // Retry kedua saat Snap URL sudah valid tidak memanggil Snap lagi (idempoten)
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/retry-payment")
            ->assertStatus(200)
            ->assertJsonPath('data.payment.snap_token', 'snap-retry-token-999');

        Http::assertNothingSent();
    }

    public function test_refresh_payment_handles_404_safely_and_cancel_enforces_midtrans_authoritative_check(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $listing = $this->createPaidListing($seller);
        $address = $this->createBuyerAddress($buyer);

        $this->fakeRajaOngkirAndSnapSuccess();

        $orderId = $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'refresh-cancel-001',
        ])->assertStatus(201)->json('data.id');

        $order = Order::findOrFail($orderId);

        // 1. Refresh saat GET Status 404 (pembeli belum pilih metode bayar) -> tetap pending & reserved
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$order->midtrans_order_id}/status" => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/refresh-payment")
            ->assertStatus(200)
            ->assertJsonPath('meta.provider_synced', false)
            ->assertJsonPath('data.status', Order::STATUS_PENDING_PAYMENT);

        $this->assertSame('reserved', $listing->fresh()->status);

        // 2. Buyer membatalkan saat Snap aktif & GET Status 404 -> panggil POST /snap/v1/transactions/{token}/cancel
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$order->midtrans_order_id}/status" => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token-001/cancel' => Http::response([
                'canceled_at' => '2026-11-22T08:15:00.000Z',
            ], 200),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Berubah pikiran'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED)
            ->assertJsonPath('data.payment_status', Order::PAYMENT_CANCELLED);

        $this->assertSame('available', $listing->fresh()->status);

        // Pastikan header Authorization Snap Cancel memakai raw Server Key (bukan Basic)
        Http::assertSent(function (HttpClientRequest $req): bool {
            if ($req->url() !== 'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token-001/cancel') {
                return false;
            }
            $auth = $req->header('Authorization')[0] ?? '';

            return $auth === self::FAKE_SERVER_KEY && ! str_starts_with($auth, 'Basic ');
        });

        // Idempoten: cancel ulang pada pesanan yang sudah cancelled tetap 200 tanpa history tambahan
        $historyBefore = OrderStatusHistory::where('order_id', $order->id)->count();
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED);
        $this->assertSame($historyBefore, OrderStatusHistory::where('order_id', $order->id)->count());

        // 3. Checkout ulang pada listing yang sudah dilepas lalu uji Core API cancel untuk transaksi pending
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeRajaOngkirAndSnapSuccess();

        $secondOrderId = $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'refresh-cancel-002',
        ])->assertStatus(201)->json('data.id');

        $secondOrder = Order::findOrFail($secondOrderId);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$secondOrder->midtrans_order_id}/status" => Http::response([
                'order_id'           => $secondOrder->midtrans_order_id,
                'transaction_id'     => 'tx-pending-01',
                'transaction_status' => 'pending',
                'status_code'        => '201',
                'gross_amount'       => '68000.00',
                'currency'           => 'IDR',
                'payment_type'       => 'bank_transfer',
            ], 200),
            "https://api.sandbox.midtrans.com/v2/{$secondOrder->midtrans_order_id}/cancel" => Http::response([
                'order_id'           => $secondOrder->midtrans_order_id,
                'transaction_id'     => 'tx-pending-01',
                'transaction_status' => 'cancel',
                'status_code'        => '200',
                'gross_amount'       => '68000.00',
                'currency'           => 'IDR',
                'payment_type'       => 'bank_transfer',
            ], 200),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$secondOrder->id}/cancel", ['reason' => 'Ganti alamat'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED)
            ->assertJsonPath('data.payment_status', Order::PAYMENT_CANCELLED);

        $this->assertSame('available', $listing->fresh()->status);
    }

    public function test_snap_session_cancel_edge_cases_already_canceled_in_progress_not_found_timeout_and_paid_guard(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $address = $this->createBuyerAddress($buyer);

        // 1. Token already canceled -> order dibatalkan & listing dilepas atomik
        $listing1 = $this->createPaidListing($seller);
        $this->fakeRajaOngkirAndSnapSuccess();
        $order1Id = $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing1->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'snap-edge-already',
        ])->assertStatus(201)->json('data.id');
        $order1 = Order::findOrFail($order1Id);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$order1->midtrans_order_id}/status" => Http::response(['status_code' => '404'], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token-001/cancel' => Http::response([
                'error_messages' => ['token already canceled'],
            ], 400),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order1->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED);
        $this->assertSame('available', $listing1->fresh()->status);

        // 2. Transaction is on progress -> JANGAN lepas listing, cek GET Status & rekonsiliasi ke paid -> 409
        $listing2 = $this->createPaidListing($seller);
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeRajaOngkirAndSnapSuccess();
        $order2Id = $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing2->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'snap-edge-progress',
        ])->assertStatus(201)->json('data.id');
        $order2 = Order::findOrFail($order2Id);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$order2->midtrans_order_id}/status" => Http::sequence()
                ->push(['status_code' => '404'], 200) // Cek awal sebelum Snap cancel
                ->push([
                    'order_id'           => $order2->midtrans_order_id,
                    'transaction_id'     => 'tx-inprog-paid',
                    'transaction_status' => 'settlement',
                    'status_code'        => '200',
                    'gross_amount'       => '68000.00',
                    'currency'           => 'IDR',
                    'payment_type'       => 'qris',
                ], 200), // Cek rekonsiliasi setelah "Transaction is on progress"
            'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token-001/cancel' => Http::response([
                'error_messages' => ['Transaction is on progress'],
            ], 400),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order2->id}/cancel")
            ->assertStatus(409);

        $this->assertSame(Order::STATUS_PAID, $order2->fresh()->status);
        $this->assertSame('reserved', $listing2->fresh()->status);

        // Order yang sudah paid / shipped / completed tidak boleh bisa dibatalkan (409)
        $this->actingAs($buyer, 'sanctum')->postJson("/api/orders/{$order2->id}/cancel")->assertStatus(409);
        $order2->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()]);
        $this->actingAs($buyer, 'sanctum')->postJson("/api/orders/{$order2->id}/cancel")->assertStatus(409);
        $order2->update(['status' => Order::STATUS_COMPLETED, 'completed_at' => now()]);
        $this->actingAs($buyer, 'sanctum')->postJson("/api/orders/{$order2->id}/cancel")->assertStatus(409);

        // 3. Token not found sebelum expiry & timeout -> JANGAN lepas listing
        $listing3 = $this->createPaidListing($seller);
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeRajaOngkirAndSnapSuccess();
        $order3Id = $this->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
            'listing_id'      => $listing3->id,
            'address_id'      => $address->id,
            'courier_code'    => 'jne',
            'courier_service' => 'REG',
            'idempotency_key' => 'snap-edge-missing',
        ])->assertStatus(201)->json('data.id');
        $order3 = Order::findOrFail($order3Id);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$order3->midtrans_order_id}/status" => Http::response(['status_code' => '404'], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token-001/cancel' => Http::response([
                'error_messages' => ['token not found'],
            ], 404),
        ]);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/orders/{$order3->id}/cancel")->assertStatus(409);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order3->fresh()->status);
        $this->assertSame('reserved', $listing3->fresh()->status);

        // 4. Timeout pada Snap cancel -> 504 & listing tetap reserved
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$order3->midtrans_order_id}/status" => Http::response(['status_code' => '404'], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token-001/cancel' => function () {
                throw new ConnectionException('cURL error 28: Snap cancel timeout');
            },
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/orders/{$order3->id}/cancel")
            ->assertStatus(504)
            ->assertJsonPath('data.reconciliation_required', true);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order3->fresh()->status);
        $this->assertSame('reserved', $listing3->fresh()->status);
    }
}
