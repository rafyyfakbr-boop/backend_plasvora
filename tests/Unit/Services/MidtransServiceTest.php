<?php

namespace Tests\Unit\Services;

use App\Exceptions\MidtransException;
use App\Services\MidtransService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransServiceTest extends TestCase
{
    private const FAKE_SERVER_KEY        = 'SB-Mid-server-FAKE-TEST-KEY-001';
    private const FAKE_SIGNATURE_KEY     = 'FAKE-SIGNATURE-SECRET-CURRENT-001';
    private const FAKE_PREV_SIGNATURE    = 'FAKE-SIGNATURE-SECRET-PREVIOUS-001';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.midtrans', [
            'server_key'                    => self::FAKE_SERVER_KEY,
            'signature_secret_key'          => self::FAKE_SIGNATURE_KEY,
            'signature_secret_key_previous' => null,
            'is_production'                 => false,
            'snap_base_url'                 => MidtransService::SANDBOX_SNAP_BASE_URL,
            'api_base_url'                  => MidtransService::SANDBOX_API_BASE_URL,
            'timeout'                       => 15,
        ]);
    }

    private function sampleSnapPayload(): array
    {
        return [
            'transaction_details' => [
                'order_id'     => 'PLV-ORDER-20261122-0001',
                'gross_amount' => 68000,
            ],
            'customer_details' => [
                'first_name' => 'Pembeli Uji',
                'email'      => 'buyer@example.com',
            ],
        ];
    }

    // ─── Signature Verification ───────────────────────────────────────────

    public function test_valid_notification_signature_with_current_signature_secret_key(): void
    {
        $service = new MidtransService();

        $orderId     = 'PLV-ORDER-20261122-0001';
        $statusCode  = '200';
        $grossAmount = '68000.00';
        $signature   = hash('sha512', $orderId . $statusCode . $grossAmount . self::FAKE_SIGNATURE_KEY);

        $this->assertTrue($service->verifyNotificationSignature([
            'order_id'      => $orderId,
            'status_code'   => $statusCode,
            'gross_amount'  => $grossAmount,
            'signature_key' => $signature,
        ]));
    }

    public function test_invalid_notification_signature_is_rejected(): void
    {
        $service = new MidtransService();

        $this->assertFalse($service->verifyNotificationSignature([
            'order_id'      => 'PLV-ORDER-20261122-0001',
            'status_code'   => '200',
            'gross_amount'  => '68000.00',
            'signature_key' => str_repeat('a', 128),
        ]));
    }

    public function test_missing_or_malformed_signature_fields_are_rejected(): void
    {
        $service = new MidtransService();

        $validSig = hash('sha512', 'PLV-1' . '200' . '50000.00' . self::FAKE_SIGNATURE_KEY);

        $this->assertFalse($service->verifyNotificationSignature([]));
        $this->assertFalse($service->verifyNotificationSignature([
            'status_code'   => '200',
            'gross_amount'  => '50000.00',
            'signature_key' => $validSig,
        ]));
        $this->assertFalse($service->verifyNotificationSignature([
            'order_id'      => 'PLV-1',
            'gross_amount'  => '50000.00',
            'signature_key' => $validSig,
        ]));
        $this->assertFalse($service->verifyNotificationSignature([
            'order_id'      => 'PLV-1',
            'status_code'   => '200',
            'signature_key' => $validSig,
        ]));
        $this->assertFalse($service->verifyNotificationSignature([
            'order_id'     => 'PLV-1',
            'status_code'  => '200',
            'gross_amount' => '50000.00',
        ]));
        $this->assertFalse($service->verifyNotificationSignature([
            'order_id'      => ['nested'],
            'status_code'   => '200',
            'gross_amount'  => '50000.00',
            'signature_key' => $validSig,
        ]));
    }

    public function test_signature_secret_key_is_used_instead_of_server_key_and_never_falls_back(): void
    {
        $orderId     = 'PLV-ORDER-20261122-0002';
        $statusCode  = '200';
        $grossAmount = '75000.00';

        // Signature dibuat dengan Server Key harus ditolak
        $signedWithServerKey = hash('sha512', $orderId . $statusCode . $grossAmount . self::FAKE_SERVER_KEY);
        $service = new MidtransService();

        $this->assertFalse($service->verifyNotificationSignature([
            'order_id'      => $orderId,
            'status_code'   => $statusCode,
            'gross_amount'  => $grossAmount,
            'signature_key' => $signedWithServerKey,
        ]));

        // Bahkan jika Signature Secret Key kosong, tidak boleh fallback diam-diam ke Server Key
        config()->set('services.midtrans.signature_secret_key', null);
        $serviceWithoutSigKey = new MidtransService();

        $this->assertFalse($serviceWithoutSigKey->verifyNotificationSignature([
            'order_id'      => $orderId,
            'status_code'   => $statusCode,
            'gross_amount'  => $grossAmount,
            'signature_key' => $signedWithServerKey,
        ]));
    }

    public function test_previous_signature_secret_key_is_accepted_only_when_configured(): void
    {
        $orderId     = 'PLV-ORDER-20261122-0003';
        $statusCode  = '200';
        $grossAmount = '90000.00';

        $signedWithPrevious = hash('sha512', $orderId . $statusCode . $grossAmount . self::FAKE_PREV_SIGNATURE);
        $payload = [
            'order_id'      => $orderId,
            'status_code'   => $statusCode,
            'gross_amount'  => $grossAmount,
            'signature_key' => $signedWithPrevious,
        ];

        // Ditolak saat previous key belum dikonfigurasi
        $serviceWithoutPrevious = new MidtransService();
        $this->assertFalse($serviceWithoutPrevious->verifyNotificationSignature($payload));

        // Diterima saat jendela rotasi mengonfigurasi previous key
        config()->set('services.midtrans.signature_secret_key_previous', self::FAKE_PREV_SIGNATURE);
        $serviceWithPrevious = new MidtransService();
        $this->assertTrue($serviceWithPrevious->verifyNotificationSignature($payload));
    }

    // ─── Snap Transaction Creation ────────────────────────────────────────

    public function test_snap_request_uses_sandbox_endpoint_by_default_basic_auth_expected_json_and_normalizes_response(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token'        => 'snap-token-abc-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-abc-123',
                'extra_field'  => 'ignored',
            ], 201),
        ]);

        $service = new MidtransService();
        $result  = $service->createSnapTransaction($this->sampleSnapPayload());

        $this->assertSame([
            'token'        => 'snap-token-abc-123',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-abc-123',
        ], $result);

        $expectedAuth = 'Basic ' . base64_encode(self::FAKE_SERVER_KEY . ':');

        Http::assertSent(function (HttpClientRequest $request) use ($expectedAuth): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
                && $request->hasHeader('Authorization', $expectedAuth)
                && $request->hasHeader('Accept', 'application/json')
                && $request->isJson()
                && $request['transaction_details']['order_id'] === 'PLV-ORDER-20261122-0001'
                && $request['transaction_details']['gross_amount'] === 68000;
        });
    }

    public function test_snap_never_silently_uses_production_when_production_flag_is_false(): void
    {
        // Meskipun snap_base_url salah terset ke host produksi, is_production=false wajib memaksa sandbox
        config()->set('services.midtrans.is_production', false);
        config()->set('services.midtrans.snap_base_url', MidtransService::PRODUCTION_SNAP_BASE_URL);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token'        => 'sandbox-safe-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-safe-token',
            ], 201),
        ]);

        $service = new MidtransService();
        $result  = $service->createSnapTransaction($this->sampleSnapPayload());

        $this->assertSame('sandbox-safe-token', $result['token']);
        Http::assertSent(fn (HttpClientRequest $r) => $r->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions');
    }

    public function test_missing_snap_token_or_redirect_url_is_handled_safely(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'only-token-no-url',
            ], 201),
        ]);

        $service = new MidtransService();

        try {
            $service->createSnapTransaction($this->sampleSnapPayload());
            $this->fail('Expected MidtransException when redirect_url is missing.');
        } catch (MidtransException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame('PLV-ORDER-20261122-0001', $e->midtransOrderId);
            $this->assertTrue($e->reconciliationRequired);
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }
    }

    public function test_missing_snap_token_is_also_rejected(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/missing-token',
            ], 201),
        ]);

        $service = new MidtransService();

        $this->expectException(MidtransException::class);
        $service->createSnapTransaction($this->sampleSnapPayload());
    }

    // ─── Status API ───────────────────────────────────────────────────────

    public function test_status_api_request_uses_configured_server_key_and_normalizes_response(): void
    {
        $orderId = 'PLV-ORDER-20261122-0001';

        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$orderId}/status" => Http::response([
                'order_id'           => $orderId,
                'transaction_id'     => 'tx-998877',
                'transaction_status' => 'settlement',
                'fraud_status'       => 'accept',
                'payment_type'       => 'qris',
                'status_code'        => '200',
                'gross_amount'       => '68000.00',
                'currency'           => 'IDR',
                'settlement_time'    => '2026-11-22 13:15:00',
                'expiry_time'        => '2026-11-23 13:00:00',
                'masked_card'        => '4111-xxxx-xxxx-1111',
            ], 200),
        ]);

        $service = new MidtransService();
        $status  = $service->getTransactionStatus($orderId);

        $this->assertSame($orderId, $status['order_id']);
        $this->assertSame('tx-998877', $status['transaction_id']);
        $this->assertSame('settlement', $status['transaction_status']);
        $this->assertSame('accept', $status['fraud_status']);
        $this->assertSame('qris', $status['payment_type']);
        $this->assertSame('200', $status['status_code']);
        $this->assertSame('68000.00', $status['gross_amount']);
        $this->assertArrayNotHasKey('masked_card', $status);

        $expectedAuth = 'Basic ' . base64_encode(self::FAKE_SERVER_KEY . ':');

        Http::assertSent(function (HttpClientRequest $request) use ($orderId, $expectedAuth): bool {
            return $request->method() === 'GET'
                && $request->url() === "https://api.sandbox.midtrans.com/v2/{$orderId}/status"
                && $request->hasHeader('Authorization', $expectedAuth)
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    // ─── Error & Timeout Handling ─────────────────────────────────────────

    public function test_http_failure_is_not_silently_treated_as_successful_payment_and_hides_credentials(): void
    {
        $orderId = 'PLV-ORDER-20261122-0001';

        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$orderId}/status" => Http::response([
                'status_code'    => '401',
                'status_message' => 'Unauthorized server key ' . self::FAKE_SERVER_KEY,
            ], 401),
        ]);

        $service = new MidtransService();

        try {
            $service->getTransactionStatus($orderId);
            $this->fail('Expected MidtransException on HTTP 401.');
        } catch (MidtransException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertSame(401, $e->httpStatus);
            $this->assertFalse($e->isTimeout);
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }
    }

    public function test_network_timeout_is_not_treated_as_proof_that_transaction_does_not_exist(): void
    {
        $orderId = 'PLV-ORDER-20261122-0001';

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds');
        });

        $service = new MidtransService();

        // 1. Timeout pada createSnapTransaction menjaga konteks order_id untuk rekonsiliasi Batch 2
        try {
            $service->createSnapTransaction($this->sampleSnapPayload());
            $this->fail('Expected MidtransException on Snap timeout.');
        } catch (MidtransException $e) {
            $this->assertSame(504, $e->getCode());
            $this->assertSame($orderId, $e->midtransOrderId);
            $this->assertTrue($e->isTimeout);
            $this->assertTrue($e->reconciliationRequired);
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }

        // 2. Timeout pada getTransactionStatus juga tidak mengembalikan "tidak ada" / false
        try {
            $service->getTransactionStatus($orderId);
            $this->fail('Expected MidtransException on Status API timeout.');
        } catch (MidtransException $e) {
            $this->assertSame(504, $e->getCode());
            $this->assertSame($orderId, $e->midtransOrderId);
            $this->assertTrue($e->isTimeout);
            $this->assertTrue($e->reconciliationRequired);
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }
    }

    public function test_missing_server_key_configuration_fails_fast_with_503(): void
    {
        config()->set('services.midtrans.server_key', null);
        $service = new MidtransService();

        try {
            $service->createSnapTransaction($this->sampleSnapPayload());
            $this->fail('Expected MidtransException when server key is missing.');
        } catch (MidtransException $e) {
            $this->assertSame(503, $e->getCode());
        }
    }

    // ─── Cancel a Snap Session (POST /snap/v1/transactions/{token}/cancel) ─

    public function test_cancel_snap_session_uses_snap_host_raw_server_key_authorization_and_handles_success(): void
    {
        $token = 'snap-session-tok-123';

        Http::fake([
            "https://app.sandbox.midtrans.com/snap/v1/transactions/{$token}/cancel" => Http::response([
                'canceled_at' => '2026-11-22T08:10:00.000Z',
            ], 200),
        ]);

        $service = new MidtransService();
        $result  = $service->cancelSnapSession($token);

        $this->assertSame([
            'status'      => 'canceled',
            'canceled_at' => '2026-11-22T08:10:00.000Z',
        ], $result);

        Http::assertSent(function (HttpClientRequest $request) use ($token): bool {
            $authHeader = $request->header('Authorization')[0] ?? '';

            return $request->method() === 'POST'
                && $request->url() === "https://app.sandbox.midtrans.com/snap/v1/transactions/{$token}/cancel"
                && $authHeader === self::FAKE_SERVER_KEY
                && ! str_starts_with($authHeader, 'Basic ')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_cancel_snap_session_switches_to_production_snap_host_when_production_enabled(): void
    {
        config()->set('services.midtrans.is_production', true);
        $token = 'prod-snap-tok-456';

        Http::fake([
            "https://app.midtrans.com/snap/v1/transactions/{$token}/cancel" => Http::response([
                'canceled_at' => '2026-11-22T08:12:00.000Z',
            ], 200),
        ]);

        $service = new MidtransService();
        $result  = $service->cancelSnapSession($token);

        $this->assertSame('canceled', $result['status']);
        Http::assertSent(fn (HttpClientRequest $r) => $r->url() === "https://app.midtrans.com/snap/v1/transactions/{$token}/cancel");
    }

    public function test_cancel_snap_session_maps_already_canceled_in_progress_not_found_and_timeout(): void
    {
        $service = new MidtransService();

        // 1. Token already canceled
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions/tok-already/cancel' => Http::response([
                'error_messages' => ['token already canceled'],
            ], 400),
            // 2. Transaction is on progress
            'https://app.sandbox.midtrans.com/snap/v1/transactions/tok-progress/cancel' => Http::response([
                'error_messages' => ['Transaction is on progress'],
            ], 400),
            // 3. Token not found
            'https://app.sandbox.midtrans.com/snap/v1/transactions/tok-missing/cancel' => Http::response([
                'error_messages' => ['token not found'],
            ], 404),
        ]);

        $this->assertSame('already_canceled', $service->cancelSnapSession('tok-already')['status']);
        $this->assertSame('in_progress', $service->cancelSnapSession('tok-progress')['status']);
        $this->assertSame('not_found', $service->cancelSnapSession('tok-missing')['status']);

        // 4. Network timeout -> MidtransException(504, isTimeout: true, reconciliationRequired: true)
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Snap cancel timed out');
        });

        try {
            $service->cancelSnapSession('tok-timeout');
            $this->fail('Expected MidtransException on Snap cancel timeout.');
        } catch (MidtransException $e) {
            $this->assertSame(504, $e->getCode());
            $this->assertTrue($e->isTimeout);
            $this->assertTrue($e->reconciliationRequired);
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }
    }
}
