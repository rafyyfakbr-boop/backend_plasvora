<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OrderOperationException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentWebhookEvent;
use App\Services\MidtransService;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    public function __construct(
        private readonly MidtransService $midtransService,
        private readonly OrderService $orderService
    ) {}

    /**
     * POST /api/webhooks/midtrans
     * Endpoint publik notifikasi pembayaran Midtrans dengan verifikasi SHA-512 & idempotency.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawBody     = (string) $request->getContent();
        $payload     = $request->all();
        $payloadHash = hash('sha256', $rawBody !== '' ? $rawBody : (string) json_encode($payload));

        // Whitelist field minimum yang aman untuk rekonsiliasi & audit (tanpa raw payload / signature_key / PII)
        $whitelisted = [
            'order_id'           => $this->safeScalarString($payload['order_id'] ?? null, 64),
            'transaction_id'     => $this->safeScalarString($payload['transaction_id'] ?? null, 100),
            'transaction_status' => $this->safeScalarString($payload['transaction_status'] ?? null, 50),
            'fraud_status'       => $this->safeScalarString($payload['fraud_status'] ?? null, 30),
            'status_code'        => $this->safeScalarString($payload['status_code'] ?? null, 10),
            'gross_amount'       => $this->safeScalarString($payload['gross_amount'] ?? null, 30),
            'currency'           => $this->safeScalarString($payload['currency'] ?? null, 10),
            'payment_type'       => $this->safeScalarString($payload['payment_type'] ?? null, 50),
        ];

        // 1. Verifikasi signature menggunakan MIDTRANS_SIGNATURE_SECRET_KEY (tidak pernah Server Key)
        $signatureValid = $this->midtransService->verifyNotificationSignature($payload);

        if (! $signatureValid) {
            $this->recordSafeWebhookEvent($payloadHash, null, $whitelisted, false, 'rejected_invalid_signature');

            Log::warning('MidtransWebhook: signature tidak valid ditolak.', [
                'midtrans_order_id' => $whitelisted['order_id'],
                'status_code'       => $whitelisted['status_code'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid notification signature.',
            ], 403);
        }

        // 2. Cek duplikasi payload_hash sebelum memproses ulang
        $existingEvent = PaymentWebhookEvent::where('payload_hash', $payloadHash)->first();
        if ($existingEvent && $existingEvent->processed_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'Duplicate notification ignored.',
            ], 200);
        }

        // 3. Resolusi pesanan internal berdasarkan midtrans_order_id
        $order = $whitelisted['order_id'] !== null
            ? Order::where('midtrans_order_id', $whitelisted['order_id'])->first()
            : null;

        if (! $order) {
            $this->recordSafeWebhookEvent($payloadHash, null, $whitelisted, true, 'ignored_unknown_order', markProcessed: true);

            return response()->json([
                'success' => true,
                'message' => 'Notification recorded; order not found.',
            ], 200);
        }

        // 4. Catat event webhook (tangani race duplicate payload_hash secara aman)
        $event = $this->createOrFetchValidEvent($payloadHash, $order, $whitelisted);
        if ($event->processed_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'Duplicate notification ignored.',
            ], 200);
        }

        // 5. Rekonsiliasi otoritatif (validasi exact gross_amount + IDR + monotonicity + atomic lock)
        try {
            $reconciled = $this->orderService->reconcilePayment(
                $order,
                $whitelisted,
                'midtrans_webhook',
                OrderStatusHistory::ACTOR_MIDTRANS
            );

            $event->update([
                'processing_result' => $reconciled['result'],
                'processed_at'      => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Notification processed successfully.',
            ], 200);
        } catch (OrderOperationException $e) {
            $event->update([
                'processing_result' => 'rejected_validation',
                'processed_at'      => now(),
            ]);

            Log::warning('MidtransWebhook: notifikasi valid ditolak saat rekonsiliasi nominal/mata uang.', [
                'order_id'          => $order->id,
                'midtrans_order_id' => $order->midtrans_order_id,
                'reason'            => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * @param  array<string, ?string>  $whitelisted
     */
    private function createOrFetchValidEvent(string $payloadHash, Order $order, array $whitelisted): PaymentWebhookEvent
    {
        try {
            return PaymentWebhookEvent::firstOrCreate(
                ['payload_hash' => $payloadHash],
                [
                    'order_id'                => $order->id,
                    'provider'                => 'midtrans',
                    'midtrans_order_id'       => $whitelisted['order_id'],
                    'midtrans_transaction_id' => $whitelisted['transaction_id'],
                    'transaction_status'      => $whitelisted['transaction_status'],
                    'fraud_status'            => $whitelisted['fraud_status'],
                    'status_code'             => $whitelisted['status_code'],
                    'gross_amount'            => $whitelisted['gross_amount'],
                    'signature_valid'         => true,
                    'processing_result'       => 'pending',
                ]
            );
        } catch (QueryException) {
            return PaymentWebhookEvent::where('payload_hash', $payloadHash)->firstOrFail();
        }
    }

    /**
     * @param  array<string, ?string>  $whitelisted
     */
    private function recordSafeWebhookEvent(
        string $payloadHash,
        ?Order $order,
        array $whitelisted,
        bool $signatureValid,
        string $result,
        bool $markProcessed = false
    ): void {
        try {
            PaymentWebhookEvent::firstOrCreate(
                ['payload_hash' => $payloadHash],
                [
                    'order_id'                => $order?->id,
                    'provider'                => 'midtrans',
                    'midtrans_order_id'       => $whitelisted['order_id'],
                    'midtrans_transaction_id' => $whitelisted['transaction_id'],
                    'transaction_status'      => $whitelisted['transaction_status'],
                    'fraud_status'            => $whitelisted['fraud_status'],
                    'status_code'             => $whitelisted['status_code'],
                    'gross_amount'            => $whitelisted['gross_amount'],
                    'signature_valid'         => $signatureValid,
                    'processing_result'       => $result,
                    'processed_at'            => $markProcessed ? now() : null,
                ]
            );
        } catch (\Throwable) {
            // Kegagalan pencatatan duplikasi event tidak boleh membocorkan error internal
        }
    }

    private function safeScalarString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }
}
