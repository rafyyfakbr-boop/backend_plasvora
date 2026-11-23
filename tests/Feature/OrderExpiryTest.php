<?php

namespace Tests\Feature;

use App\Models\MaterialListing;
use App\Models\Order;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.midtrans', [
            'server_key'                    => 'SB-Mid-server-EXPIRY-TEST-001',
            'signature_secret_key'          => 'FAKE-SIGNATURE-SECRET-EXPIRY-001',
            'signature_secret_key_previous' => null,
            'is_production'                 => false,
            'snap_base_url'                 => MidtransService::SANDBOX_SNAP_BASE_URL,
            'api_base_url'                  => MidtransService::SANDBOX_API_BASE_URL,
            'timeout'                       => 15,
            'payment_expiry_minutes'        => 1440,
        ]);
    }

    private function createDueOrder(string $midtransOrderId, array $overrides = []): array
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
            'status'                => 'reserved',
        ]);

        $address = UserAddress::create([
            'user_id'           => $buyer->id,
            'label'             => 'Rumah',
            'recipient_name'    => 'Pembeli Expiry',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => 6157,
            'destination_label' => 'CIANJUR',
            'address_detail'    => 'Jl. Cianjur 2',
            'is_default'        => true,
        ]);

        $order = Order::create(array_merge([
            'order_number'                   => 'PLV-EXP-' . uniqid(),
            'idempotency_key'                => 'idem-exp-' . uniqid(),
            'buyer_id'                       => $buyer->id,
            'seller_id'                      => $seller->id,
            'listing_id'                     => $listing->id,
            'buyer_address_id'               => $address->id,
            'listing_name_snapshot'          => $listing->name,
            'listing_category_snapshot'      => $listing->category,
            'listing_weight_kg_snapshot'     => 5.00,
            'origin_destination_id_snapshot' => 4949,
            'origin_label_snapshot'          => 'BRAGA, BANDUNG',
            'recipient_name_snapshot'        => $address->recipient_name,
            'recipient_phone_snapshot'       => $address->recipient_phone,
            'destination_id_snapshot'        => 6157,
            'destination_label_snapshot'     => 'CIANJUR',
            'address_detail_snapshot'        => 'Jl. Cianjur 2',
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
            'payment_expires_at'             => now()->subMinutes(10),
            'midtrans_order_id'              => $midtransOrderId,
        ], $overrides));

        return compact('listing', 'order');
    }

    public function test_expiry_command_reconciles_settlement_expires_confirmed_orders_and_keeps_ambiguous_reserved(): void
    {
        ['order' => $paidOrder, 'listing' => $paidListing]         = $this->createDueOrder('PLV-MID-EXP-PAID');
        ['order' => $expiredOrder, 'listing' => $expiredListing]   = $this->createDueOrder('PLV-MID-EXP-EXPIRED');
        ['order' => $pendingOrder, 'listing' => $pendingListing]   = $this->createDueOrder('PLV-MID-EXP-PENDING');
        ['order' => $notFoundOrder, 'listing' => $notFoundListing] = $this->createDueOrder('PLV-MID-EXP-404');
        ['order' => $timeoutOrder, 'listing' => $timeoutListing]   = $this->createDueOrder('PLV-MID-EXP-TIMEOUT');
        ['order' => $futureOrder, 'listing' => $futureListing]     = $this->createDueOrder('PLV-MID-EXP-FUTURE', [
            'payment_expires_at' => now()->addHours(12),
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/PLV-MID-EXP-PAID/status' => Http::response([
                'order_id'           => 'PLV-MID-EXP-PAID',
                'transaction_id'     => 'tx-exp-paid',
                'transaction_status' => 'settlement',
                'status_code'        => '200',
                'gross_amount'       => '68000.00',
                'currency'           => 'IDR',
                'payment_type'       => 'qris',
            ], 200),
            'https://api.sandbox.midtrans.com/v2/PLV-MID-EXP-EXPIRED/status' => Http::response([
                'order_id'           => 'PLV-MID-EXP-EXPIRED',
                'transaction_id'     => 'tx-exp-done',
                'transaction_status' => 'expire',
                'status_code'        => '407',
                'gross_amount'       => '68000.00',
                'currency'           => 'IDR',
            ], 200),
            'https://api.sandbox.midtrans.com/v2/PLV-MID-EXP-PENDING/status' => Http::response([
                'order_id'           => 'PLV-MID-EXP-PENDING',
                'transaction_id'     => 'tx-exp-pend',
                'transaction_status' => 'pending',
                'status_code'        => '201',
                'gross_amount'       => '68000.00',
                'currency'           => 'IDR',
                'expiry_time'        => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            ], 200),
            'https://api.sandbox.midtrans.com/v2/PLV-MID-EXP-PENDING/expire' => Http::response([
                'order_id'           => 'PLV-MID-EXP-PENDING',
                'transaction_id'     => 'tx-exp-pend',
                'transaction_status' => 'expire',
                'status_code'        => '407',
                'gross_amount'       => '68000.00',
                'currency'           => 'IDR',
            ], 200),
            'https://api.sandbox.midtrans.com/v2/PLV-MID-EXP-404/status' => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            'https://api.sandbox.midtrans.com/v2/PLV-MID-EXP-TIMEOUT/status' => function () {
                throw new ConnectionException('cURL error 28: timeout');
            },
        ]);

        $this->artisan('orders:expire-unpaid')
            ->assertExitCode(0);

        // 1. Settlement -> menjadi paid, listing tetap reserved
        $this->assertSame(Order::STATUS_PAID, $paidOrder->fresh()->status);
        $this->assertSame('reserved', $paidListing->fresh()->status);

        // 2. Midtrans expire -> menjadi expired, listing dilepas ke available
        $this->assertSame(Order::STATUS_EXPIRED, $expiredOrder->fresh()->status);
        $this->assertSame('available', $expiredListing->fresh()->status);

        // 3. Pending yang expiry-nya lewat -> dipanggil /expire di Midtrans -> expired & listing dilepas
        $this->assertSame(Order::STATUS_EXPIRED, $pendingOrder->fresh()->status);
        $this->assertSame('available', $pendingListing->fresh()->status);

        // 4. 404 setelah Snap page expiry pasti lewat -> expired & listing dilepas
        $this->assertSame(Order::STATUS_EXPIRED, $notFoundOrder->fresh()->status);
        $this->assertSame('available', $notFoundListing->fresh()->status);

        // 5. Timeout / ambigu -> TETAP pending_payment & listing TETAP reserved
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $timeoutOrder->fresh()->status);
        $this->assertSame('reserved', $timeoutListing->fresh()->status);

        // 6. Order belum jatuh tempo tidak disentuh
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $futureOrder->fresh()->status);
        $this->assertSame('reserved', $futureListing->fresh()->status);

        // 7. Aman dijalankan ulang secara idempoten
        $this->artisan('orders:expire-unpaid')->assertExitCode(0);
    }
}
