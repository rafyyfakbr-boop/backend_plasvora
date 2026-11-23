<?php

namespace Tests\Feature;

use App\Models\MaterialListing;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SERVER_KEY = 'SB-Mid-server-WEBHOOK-TEST-001';
    private const FAKE_SIG_KEY    = 'FAKE-SIGNATURE-SECRET-WEBHOOK-001';

    protected function setUp(): void
    {
        parent::setUp();

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

    private function createOrderFixture(array $orderOverrides = [], string $listingStatus = 'reserved'): array
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'price'                 => 50000.00,
            'is_free'               => false,
            'weight_kg'             => 5.00,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, BANDUNG',
            'status'                => $listingStatus,
        ]);

        $address = UserAddress::create([
            'user_id'           => $buyer->id,
            'label'             => 'Rumah',
            'recipient_name'    => 'Pembeli Webhook',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => 6157,
            'destination_label' => 'CIANJUR',
            'address_detail'    => 'Jl. Cianjur 1',
            'is_default'        => true,
        ]);

        $order = Order::create(array_merge([
            'order_number'                   => 'PLV-20261122-WH01',
            'idempotency_key'                => 'idem-wh-001',
            'buyer_id'                       => $buyer->id,
            'seller_id'                      => $seller->id,
            'listing_id'                     => $listing->id,
            'buyer_address_id'               => $address->id,
            'listing_name_snapshot'          => $listing->name,
            'listing_category_snapshot'      => $listing->category,
            'listing_weight_kg_snapshot'     => $listing->weight_kg,
            'origin_destination_id_snapshot' => 4949,
            'origin_label_snapshot'          => 'BRAGA, BANDUNG',
            'recipient_name_snapshot'        => $address->recipient_name,
            'recipient_phone_snapshot'       => $address->recipient_phone,
            'destination_id_snapshot'        => 6157,
            'destination_label_snapshot'     => 'CIANJUR',
            'address_detail_snapshot'        => 'Jl. Cianjur 1',
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
            'midtrans_order_id'              => 'PLV-MID-WH-0001',
        ], $orderOverrides));

        return compact('seller', 'buyer', 'listing', 'order');
    }

    private function signedPayload(string $midtransOrderId, string $statusCode, string $grossAmount, array $extra = [], ?string $secret = null): array
    {
        $key = $secret ?? self::FAKE_SIG_KEY;

        return array_merge([
            'order_id'           => $midtransOrderId,
            'transaction_id'     => 'tx-mid-001',
            'status_code'        => $statusCode,
            'gross_amount'       => $grossAmount,
            'currency'           => 'IDR',
            'payment_type'       => 'qris',
            'transaction_status' => 'settlement',
            'signature_key'      => hash('sha512', $midtransOrderId . $statusCode . $grossAmount . $key),
        ], $extra);
    }

    public function test_invalid_signature_missing_fields_and_server_key_signed_notifications_are_rejected(): void
    {
        ['order' => $order] = $this->createOrderFixture();

        // 1. Invalid signature -> 403
        $this->postJson('/api/webhooks/midtrans', [
            'order_id'           => $order->midtrans_order_id,
            'status_code'        => '200',
            'gross_amount'       => '68000.00',
            'transaction_status' => 'settlement',
            'signature_key'      => str_repeat('0', 128),
        ])->assertStatus(403);

        // 2. Missing signature fields -> 403
        $this->postJson('/api/webhooks/midtrans', [
            'order_id'           => $order->midtrans_order_id,
            'transaction_status' => 'settlement',
        ])->assertStatus(403);

        // 3. Signed with Server Key instead of Signature Secret Key -> 403
        $this->postJson(
            '/api/webhooks/midtrans',
            $this->signedPayload($order->midtrans_order_id, '200', '68000.00', [], self::FAKE_SERVER_KEY)
        )->assertStatus(403);

        $order->refresh();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(Order::PAYMENT_UNPAID, $order->payment_status);
        $this->assertTrue(PaymentWebhookEvent::where('signature_valid', false)->exists());
    }

    public function test_valid_settlement_marks_paid_without_calling_external_api_and_supports_absent_fraud_status(): void
    {
        Http::fake(); // Pastikan webhook tidak pernah memanggil API eksternal (GET Status / RajaOngkir)

        ['order' => $order, 'listing' => $listing] = $this->createOrderFixture();

        // Settlement QRIS tanpa field fraud_status (opsional pada metode low-risk Midtrans)
        $payload = $this->signedPayload($order->midtrans_order_id, '200', '68000.00', [
            'transaction_status' => 'settlement',
            'payment_type'       => 'qris',
            'masked_card'        => '4111-xxxx-xxxx-1111', // Tidak boleh tersimpan
        ]);

        $this->postJson('/api/webhooks/midtrans', $payload)->assertStatus(200);

        Http::assertNothingSent();

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('reserved', $listing->fresh()->status);

        // Pastikan raw payload & signature_key tidak tersimpan
        $event = PaymentWebhookEvent::where('order_id', $order->id)->firstOrFail();
        $this->assertTrue($event->signature_valid);
        $this->assertSame('marked_paid', $event->processing_result);
        $this->assertArrayNotHasKey('payload', $event->getAttributes());
        $this->assertArrayNotHasKey('signature_key', $event->getAttributes());
    }

    public function test_capture_accept_marks_paid_while_capture_challenge_stays_pending(): void
    {
        ['order' => $orderChallenge] = $this->createOrderFixture([
            'order_number'      => 'PLV-20261122-CH01',
            'idempotency_key'   => 'idem-ch-01',
            'midtrans_order_id' => 'PLV-MID-CH-01',
        ]);

        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $orderChallenge->midtrans_order_id,
            '200',
            '68000.00',
            ['transaction_status' => 'capture', 'fraud_status' => 'challenge', 'payment_type' => 'credit_card']
        ))->assertStatus(200);

        $orderChallenge->refresh();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $orderChallenge->status);
        $this->assertSame(Order::PAYMENT_PENDING, $orderChallenge->payment_status);

        // Kemudian capture accept datang -> menjadi paid
        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $orderChallenge->midtrans_order_id,
            '200',
            '68000.00',
            ['transaction_status' => 'capture', 'fraud_status' => 'accept', 'payment_type' => 'credit_card', 'transaction_id' => 'tx-cc-02']
        ))->assertStatus(200);

        $orderChallenge->refresh();
        $this->assertSame(Order::STATUS_PAID, $orderChallenge->status);
        $this->assertSame(Order::PAYMENT_PAID, $orderChallenge->payment_status);
    }

    public function test_wrong_gross_amount_fractional_amount_and_non_idr_currency_are_rejected(): void
    {
        ['order' => $order] = $this->createOrderFixture();

        // Nominal berbeda -> 422
        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $order->midtrans_order_id,
            '200',
            '67000.00'
        ))->assertStatus(422);

        // Pecahan Rupiah -> 422 (tidak pernah dianggap 68000)
        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $order->midtrans_order_id,
            '200',
            '68000.50'
        ))->assertStatus(422);

        // Non-IDR currency -> 422
        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $order->midtrans_order_id,
            '200',
            '68000.00',
            ['currency' => 'USD']
        ))->assertStatus(422);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(Order::PAYMENT_UNPAID, $order->fresh()->payment_status);
    }

    public function test_unknown_order_id_duplicate_delivery_and_out_of_order_pending_are_handled_safely(): void
    {
        ['order' => $order, 'listing' => $listing] = $this->createOrderFixture();

        // 1. Unknown Midtrans order ID dengan signature sah -> 200 tanpa mengubah listing
        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            'PLV-MID-UNKNOWN-999',
            '200',
            '68000.00'
        ))->assertStatus(200);

        $this->assertSame('reserved', $listing->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', [
            'midtrans_order_id' => 'PLV-MID-UNKNOWN-999',
            'processing_result' => 'ignored_unknown_order',
        ]);

        // 2. Settlement sukses lalu duplikat identik -> idempoten (hanya 1 history paid)
        $settlementPayload = $this->signedPayload($order->midtrans_order_id, '200', '68000.00');
        $this->postJson('/api/webhooks/midtrans', $settlementPayload)->assertStatus(200);
        $this->postJson('/api/webhooks/midtrans', $settlementPayload)->assertStatus(200);

        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->where('to_status', Order::STATUS_PAID)->count());

        // 3. Out-of-order pending webhook tidak boleh men-downgrade order yang sudah paid
        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $order->midtrans_order_id,
            '201',
            '68000.00',
            ['transaction_status' => 'pending']
        ))->assertStatus(200);

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
    }

    public function test_expire_releases_unpaid_listing_while_cancel_and_refund_never_relist_shipped_listing(): void
    {
        // 1. Expire pada order pending_payment merilis listing ke available
        ['order' => $unpaidOrder, 'listing' => $unpaidListing] = $this->createOrderFixture();

        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $unpaidOrder->midtrans_order_id,
            '407',
            '68000.00',
            ['transaction_status' => 'expire']
        ))->assertStatus(200);

        $this->assertSame(Order::STATUS_EXPIRED, $unpaidOrder->fresh()->status);
        $this->assertSame('available', $unpaidListing->fresh()->status);

        // 2. Cancel/deny pada order yang sudah shipped tidak boleh merilis listing
        ['order' => $shippedOrder, 'listing' => $shippedListing] = $this->createOrderFixture([
            'order_number'      => 'PLV-20261122-SH01',
            'idempotency_key'   => 'idem-sh-01',
            'midtrans_order_id' => 'PLV-MID-SH-01',
            'status'            => Order::STATUS_SHIPPED,
            'payment_status'    => Order::PAYMENT_PAID,
            'paid_at'           => now()->subHour(),
            'shipped_at'        => now()->subMinutes(10),
            'tracking_number'   => 'JNE1234567890',
        ]);

        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $shippedOrder->midtrans_order_id,
            '200',
            '68000.00',
            ['transaction_status' => 'cancel', 'transaction_id' => 'tx-late-cancel']
        ))->assertStatus(200);

        $this->assertSame(Order::STATUS_SHIPPED, $shippedOrder->fresh()->status);
        $this->assertSame('reserved', $shippedListing->fresh()->status);

        // 3. Refund pada order shipped mencatat payment_status=refunded & flag audit tanpa relist
        $this->postJson('/api/webhooks/midtrans', $this->signedPayload(
            $shippedOrder->midtrans_order_id,
            '200',
            '68000.00',
            ['transaction_status' => 'refund', 'transaction_id' => 'tx-refund-01']
        ))->assertStatus(200);

        $shippedOrder->refresh();
        $this->assertSame(Order::STATUS_SHIPPED, $shippedOrder->status);
        $this->assertSame(Order::PAYMENT_REFUNDED, $shippedOrder->payment_status);
        $this->assertSame('reserved', $shippedListing->fresh()->status);
    }
}
