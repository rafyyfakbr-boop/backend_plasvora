<?php

namespace App\Services;

use App\Exceptions\MidtransException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    public const SANDBOX_SNAP_BASE_URL    = 'https://app.sandbox.midtrans.com';
    public const PRODUCTION_SNAP_BASE_URL = 'https://app.midtrans.com';
    public const SANDBOX_API_BASE_URL     = 'https://api.sandbox.midtrans.com';
    public const PRODUCTION_API_BASE_URL  = 'https://api.midtrans.com';
    public const MIN_EXPIRY_MINUTES       = 5;     // Batas minimum page_expiry Midtrans (5 menit)
    public const MAX_EXPIRY_MINUTES       = 10080; // Batas maksimum 7 hari
    public const MIDTRANS_TIMEZONE        = 'Asia/Jakarta'; // +0700 sesuai dokumentasi expiry.start_time Midtrans

    protected ?string $serverKey;
    protected ?string $signatureSecretKey;
    protected ?string $previousSignatureSecretKey;
    protected bool $isProduction;
    protected string $snapBaseUrl;
    protected string $apiBaseUrl;
    protected int $timeout;
    protected int $paymentExpiryMinutes;

    public function __construct()
    {
        $this->serverKey                  = $this->nullableString(config('services.midtrans.server_key'));
        $this->signatureSecretKey         = $this->nullableString(config('services.midtrans.signature_secret_key'));
        $this->previousSignatureSecretKey = $this->nullableString(config('services.midtrans.signature_secret_key_previous'));
        $this->isProduction               = (bool) config('services.midtrans.is_production', false);
        $this->snapBaseUrl                = $this->resolveBaseUrl(
            config('services.midtrans.snap_base_url'),
            self::SANDBOX_SNAP_BASE_URL,
            self::PRODUCTION_SNAP_BASE_URL
        );
        $this->apiBaseUrl                 = $this->resolveBaseUrl(
            config('services.midtrans.api_base_url'),
            self::SANDBOX_API_BASE_URL,
            self::PRODUCTION_API_BASE_URL
        );
        $this->timeout                    = max(1, (int) config('services.midtrans.timeout', 15));
        $configuredExpiry                 = (int) config('services.midtrans.payment_expiry_minutes', 1440);
        $this->paymentExpiryMinutes       = max(self::MIN_EXPIRY_MINUTES, min(self::MAX_EXPIRY_MINUTES, $configuredExpiry));
    }

    /**
     * Durasi kadaluarsa pembayaran terkonfigurasi dalam menit (default 24 jam = 1440 menit).
     */
    public function paymentExpiryMinutes(): int
    {
        return $this->paymentExpiryMinutes;
    }

    /**
     * Buat transaksi Midtrans Snap via server-side HTTP Basic Auth (Server Key).
     *
     * @param  array<string, mixed>  $payload
     * @return array{token: string, redirect_url: string}
     */
    public function createSnapTransaction(array $payload): array
    {
        $this->ensureServerKeyConfigured();

        $midtransOrderId = $this->nullableString($payload['transaction_details']['order_id'] ?? null);
        if (empty($payload) || $midtransOrderId === null) {
            throw new MidtransException(
                'Payload transaksi Midtrans Snap tidak lengkap.',
                422,
                midtransOrderId: $midtransOrderId
            );
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->serverKey, '')
                ->acceptJson()
                ->asJson()
                ->post("{$this->snapBaseUrl}/snap/v1/transactions", $payload);
        } catch (ConnectionException $e) {
            // Timeout pada pembuatan Snap bersifat indeterminate: transaksi mungkin sudah tercatat di Midtrans.
            Log::warning('MidtransService: koneksi/timeout saat membuat transaksi Snap.', [
                'midtrans_order_id'       => $midtransOrderId,
                'is_production'           => $this->isProduction,
                'reconciliation_required' => true,
            ]);

            throw new MidtransException(
                'Waktu tunggu pembuatan sesi pembayaran Midtrans habis. Status transaksi perlu direkonsiliasi.',
                504,
                midtransOrderId: $midtransOrderId,
                isTimeout: true,
                reconciliationRequired: true,
                previous: $e
            );
        }

        $decoded = $this->decodeResponseOrFail($response, 'pembuatan transaksi Snap', $midtransOrderId);

        $token       = $this->nullableString($decoded['token'] ?? null);
        $redirectUrl = $this->nullableString($decoded['redirect_url'] ?? null);

        if ($token === null || $redirectUrl === null || ! filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
            Log::error('MidtransService: respons Snap tidak memiliki token atau redirect_url yang valid.', [
                'midtrans_order_id' => $midtransOrderId,
                'http_status'       => $response->status(),
                'has_token'         => $token !== null,
                'has_redirect_url'  => $redirectUrl !== null,
            ]);

            throw new MidtransException(
                'Respons pembayaran Midtrans tidak lengkap.',
                502,
                midtransOrderId: $midtransOrderId,
                httpStatus: $response->status(),
                reconciliationRequired: true
            );
        }

        return [
            'token'        => $token,
            'redirect_url' => $redirectUrl,
        ];
    }

    /**
     * Batalkan Snap Session aktif sebelum metode pembayaran dipilih (POST /snap/v1/transactions/{token}/cancel).
     *
     * Sesuai dokumentasi resmi Midtrans "Cancel a Snap Session":
     * - Host menggunakan Snap base URL (app.sandbox.midtrans.com / app.midtrans.com).
     * - Header Authorization mengirim Server Key mentah (bukan Basic base64 seperti Core API).
     *
     * @return array{status: 'canceled'|'already_canceled'|'in_progress'|'not_found', canceled_at: ?string}
     */
    public function cancelSnapSession(string $snapToken): array
    {
        $this->ensureServerKeyConfigured();

        $normalizedToken = trim($snapToken);
        if ($normalizedToken === '' || preg_match('/\s|\//', $normalizedToken) === 1) {
            throw new MidtransException(
                'Token sesi pembayaran Midtrans Snap tidak valid.',
                422
            );
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->serverKey,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ])
                ->post("{$this->snapBaseUrl}/snap/v1/transactions/" . rawurlencode($normalizedToken) . '/cancel');
        } catch (ConnectionException $e) {
            Log::warning('MidtransService: koneksi/timeout saat membatalkan Snap session.', [
                'is_production'           => $this->isProduction,
                'reconciliation_required' => true,
            ]);

            throw new MidtransException(
                'Waktu tunggu pembatalan sesi pembayaran Midtrans Snap habis.',
                504,
                isTimeout: true,
                reconciliationRequired: true,
                previous: $e
            );
        }

        $status  = $response->status();
        $decoded = $response->json();

        if ($response->successful()) {
            $canceledAt = is_array($decoded) ? $this->nullableString($decoded['canceled_at'] ?? null) : null;
            if ($canceledAt === null) {
                Log::warning('MidtransService: respons sukses pembatalan Snap session tidak menyertakan canceled_at.', [
                    'http_status' => $status,
                ]);

                throw new MidtransException(
                    'Respons pembatalan sesi pembayaran Midtrans Snap tidak lengkap.',
                    502,
                    httpStatus: $status,
                    reconciliationRequired: true
                );
            }

            return [
                'status'      => 'canceled',
                'canceled_at' => $canceledAt,
            ];
        }

        // Petakan error_messages resmi dari dokumentasi Cancel a Snap Session
        $errorMessages = is_array($decoded) && isset($decoded['error_messages']) && is_array($decoded['error_messages'])
            ? array_map(fn ($msg) => strtolower(trim((string) $msg)), $decoded['error_messages'])
            : [];

        foreach ($errorMessages as $msg) {
            if (str_contains($msg, 'token already canceled')) {
                return [
                    'status'      => 'already_canceled',
                    'canceled_at' => null,
                ];
            }

            if (str_contains($msg, 'transaction is on progress')) {
                return [
                    'status'      => 'in_progress',
                    'canceled_at' => null,
                ];
            }

            if (str_contains($msg, 'token not found')) {
                return [
                    'status'      => 'not_found',
                    'canceled_at' => null,
                ];
            }
        }

        if ($status === 404) {
            return [
                'status'      => 'not_found',
                'canceled_at' => null,
            ];
        }

        Log::error('MidtransService: gagal membatalkan Snap session.', [
            'http_status'   => $status,
            'is_production' => $this->isProduction,
        ]);

        if (in_array($status, [401, 403], true)) {
            throw new MidtransException(
                'Layanan pembayaran Midtrans mengalami kendala otorisasi di server.',
                503,
                httpStatus: $status
            );
        }

        throw new MidtransException(
            'Gagal membatalkan sesi pembayaran Midtrans Snap.',
            $status >= 500 ? 502 : 422,
            httpStatus: $status,
            reconciliationRequired: true
        );
    }

    /**
     * Ambil status transaksi terbaru langsung dari Midtrans Core/Status API.
     *
     * @return array<string, ?string>
     */
    public function getTransactionStatus(string $orderId): array
    {
        $this->ensureServerKeyConfigured();

        $normalizedOrderId = trim($orderId);
        if ($normalizedOrderId === '' || preg_match('/\s|\//', $normalizedOrderId) === 1) {
            throw new MidtransException(
                'ID pesanan Midtrans tidak valid.',
                422,
                midtransOrderId: $normalizedOrderId !== '' ? $normalizedOrderId : null
            );
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->serverKey, '')
                ->acceptJson()
                ->get("{$this->apiBaseUrl}/v2/" . rawurlencode($normalizedOrderId) . '/status');
        } catch (ConnectionException $e) {
            // Timeout tidak membuktikan transaksi tidak ada; caller wajib memperlakukannya sebagai status belum pasti.
            Log::warning('MidtransService: koneksi/timeout saat memeriksa status transaksi.', [
                'midtrans_order_id'       => $normalizedOrderId,
                'is_production'           => $this->isProduction,
                'reconciliation_required' => true,
            ]);

            throw new MidtransException(
                'Waktu tunggu pemeriksaan status pembayaran Midtrans habis.',
                504,
                midtransOrderId: $normalizedOrderId,
                isTimeout: true,
                reconciliationRequired: true,
                previous: $e
            );
        }

        $decoded = $this->decodeResponseOrFail($response, 'pemeriksaan status transaksi', $normalizedOrderId);

        return $this->normalizeStatusResponse($decoded, $normalizedOrderId, $response->status());
    }

    /**
     * Batalkan transaksi pending di Midtrans secara otoritatif (POST /v2/{order_id}/cancel).
     *
     * @return array<string, ?string>
     */
    public function cancelTransaction(string $orderId): array
    {
        return $this->postTransactionAction($orderId, 'cancel', 'pembatalan transaksi');
    }

    /**
     * Kadaluarsakan transaksi pending di Midtrans secara otoritatif (POST /v2/{order_id}/expire).
     *
     * @return array<string, ?string>
     */
    public function expireTransaction(string $orderId): array
    {
        return $this->postTransactionAction($orderId, 'expire', 'kedaluwarsa transaksi');
    }

    /**
     * @return array<string, ?string>
     */
    protected function postTransactionAction(string $orderId, string $action, string $operationLabel): array
    {
        $this->ensureServerKeyConfigured();

        $normalizedOrderId = trim($orderId);
        if ($normalizedOrderId === '' || preg_match('/\s|\//', $normalizedOrderId) === 1) {
            throw new MidtransException(
                'ID pesanan Midtrans tidak valid.',
                422,
                midtransOrderId: $normalizedOrderId !== '' ? $normalizedOrderId : null
            );
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->serverKey, '')
                ->acceptJson()
                ->post("{$this->apiBaseUrl}/v2/" . rawurlencode($normalizedOrderId) . "/{$action}");
        } catch (ConnectionException $e) {
            Log::warning("MidtransService: koneksi/timeout saat {$operationLabel}.", [
                'midtrans_order_id'       => $normalizedOrderId,
                'is_production'           => $this->isProduction,
                'reconciliation_required' => true,
            ]);

            throw new MidtransException(
                "Waktu tunggu {$operationLabel} Midtrans habis.",
                504,
                midtransOrderId: $normalizedOrderId,
                isTimeout: true,
                reconciliationRequired: true,
                previous: $e
            );
        }

        $decoded = $this->decodeResponseOrFail($response, $operationLabel, $normalizedOrderId);

        return $this->normalizeStatusResponse($decoded, $normalizedOrderId, $response->status());
    }

    /**
     * Normalisasi respons Status/Cancel/Expire API Midtrans dan verifikasi signature_key bila disertakan.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, ?string>
     */
    protected function normalizeStatusResponse(array $decoded, string $normalizedOrderId, int $httpStatus): array
    {
        // Jika Midtrans menyertakan signature_key pada respons Status API, verifikasi wajib lulus sebelum dipercaya.
        if (isset($decoded['signature_key']) && is_string($decoded['signature_key']) && trim($decoded['signature_key']) !== '') {
            if (! $this->verifyNotificationSignature($decoded)) {
                Log::error('MidtransService: signature_key pada respons Status API tidak valid.', [
                    'midtrans_order_id' => $normalizedOrderId,
                    'http_status'       => $httpStatus,
                ]);

                throw new MidtransException(
                    'Signature respons status pembayaran Midtrans tidak valid.',
                    502,
                    midtransOrderId: $normalizedOrderId,
                    httpStatus: $httpStatus,
                    reconciliationRequired: true
                );
            }
        }

        $statusCode        = $this->nullableString($decoded['status_code'] ?? null);
        $transactionStatus = $this->nullableString($decoded['transaction_status'] ?? null);

        if ($statusCode === null && $transactionStatus === null) {
            Log::error('MidtransService: respons status transaksi tidak memiliki status_code maupun transaction_status.', [
                'midtrans_order_id' => $normalizedOrderId,
                'http_status'       => $httpStatus,
            ]);

            throw new MidtransException(
                'Format respons status pembayaran Midtrans tidak valid.',
                502,
                midtransOrderId: $normalizedOrderId,
                httpStatus: $httpStatus
            );
        }

        return [
            'order_id'           => $this->nullableString($decoded['order_id'] ?? $normalizedOrderId),
            'transaction_id'     => $this->nullableString($decoded['transaction_id'] ?? null),
            'transaction_status' => $transactionStatus,
            'fraud_status'       => $this->nullableString($decoded['fraud_status'] ?? null),
            'payment_type'       => $this->nullableString($decoded['payment_type'] ?? null),
            'status_code'        => $statusCode,
            'gross_amount'       => $this->nullableString($decoded['gross_amount'] ?? null),
            'currency'           => $this->nullableString($decoded['currency'] ?? null),
            'settlement_time'    => $this->nullableString($decoded['settlement_time'] ?? null),
            'expiry_time'        => $this->nullableString($decoded['expiry_time'] ?? null),
        ];
    }

    /**
     * Verifikasi signature_key notifikasi Midtrans menggunakan Signature Secret Key terpisah.
     *
     * Sesuai dokumentasi Midtrans Access Keys (berlaku 28 Oktober 2026), verifikasi signature
     * wajib memakai Signature Secret Key — tidak pernah fallback diam-diam ke Server Key.
     * Kunci sebelumnya (signature_secret_key_previous) hanya diterima jika dikonfigurasi sementara saat rotasi.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyNotificationSignature(array $payload): bool
    {
        $orderId           = $this->signatureScalarField($payload, 'order_id');
        $statusCode        = $this->signatureScalarField($payload, 'status_code');
        $grossAmount       = $this->signatureScalarField($payload, 'gross_amount');
        $receivedSignature = isset($payload['signature_key']) && is_string($payload['signature_key'])
            ? trim($payload['signature_key'])
            : null;

        if (
            $orderId === null
            || $statusCode === null
            || $grossAmount === null
            || $receivedSignature === null
            || $receivedSignature === ''
        ) {
            return false;
        }

        if ($this->signatureSecretKey === null) {
            Log::error('MidtransService: MIDTRANS_SIGNATURE_SECRET_KEY belum dikonfigurasi; verifikasi webhook ditolak.');

            return false;
        }

        $baseInput = $orderId . $statusCode . $grossAmount;

        $expectedCurrent = hash('sha512', $baseInput . $this->signatureSecretKey);
        if (hash_equals($expectedCurrent, $receivedSignature)) {
            return true;
        }

        // Jendela rotasi terkontrol: hanya aktif jika MIDTRANS_SIGNATURE_SECRET_KEY_PREVIOUS diisi.
        if ($this->previousSignatureSecretKey !== null) {
            $expectedPrevious = hash('sha512', $baseInput . $this->previousSignatureSecretKey);
            if (hash_equals($expectedPrevious, $receivedSignature)) {
                return true;
            }
        }

        return false;
    }

    protected function ensureServerKeyConfigured(): void
    {
        if ($this->serverKey === null) {
            Log::error('MidtransService: MIDTRANS_SERVER_KEY belum dikonfigurasi.');

            throw new MidtransException(
                'Layanan pembayaran Midtrans belum dikonfigurasi pada server.',
                503
            );
        }
    }

    /**
     * Validasi respons HTTP dan decode JSON tanpa membocorkan kredensial.
     *
     * @return array<string, mixed>
     */
    protected function decodeResponseOrFail(Response $response, string $operation, ?string $midtransOrderId): array
    {
        $status  = $response->status();
        $decoded = $response->json();

        if (! $response->successful()) {
            Log::error("MidtransService: gagal saat {$operation}.", [
                'midtrans_order_id' => $midtransOrderId,
                'http_status'       => $status,
                'is_production'     => $this->isProduction,
            ]);

            if (in_array($status, [401, 403], true)) {
                throw new MidtransException(
                    'Layanan pembayaran Midtrans mengalami kendala otorisasi di server.',
                    503,
                    midtransOrderId: $midtransOrderId,
                    httpStatus: $status
                );
            }

            if ($status >= 400 && $status < 500) {
                throw new MidtransException(
                    "Permintaan {$operation} ditolak oleh layanan pembayaran Midtrans.",
                    $status === 404 ? 404 : 422,
                    midtransOrderId: $midtransOrderId,
                    httpStatus: $status
                );
            }

            throw new MidtransException(
                "Layanan {$operation} Midtrans sedang mengalami gangguan sementara.",
                502,
                midtransOrderId: $midtransOrderId,
                httpStatus: $status,
                reconciliationRequired: true
            );
        }

        if (! is_array($decoded)) {
            Log::error("MidtransService: respons JSON tidak valid saat {$operation}.", [
                'midtrans_order_id' => $midtransOrderId,
                'http_status'       => $status,
            ]);

            throw new MidtransException(
                "Format respons layanan {$operation} Midtrans tidak valid.",
                502,
                midtransOrderId: $midtransOrderId,
                httpStatus: $status,
                reconciliationRequired: true
            );
        }

        return $decoded;
    }

    protected function resolveBaseUrl(mixed $configured, string $sandboxUrl, string $productionUrl): string
    {
        $expected   = $this->isProduction ? $productionUrl : $sandboxUrl;
        $normalized = is_string($configured) ? rtrim(trim($configured), '/') : '';

        if ($normalized === '' || $normalized === $sandboxUrl || $normalized === $productionUrl) {
            return $expected;
        }

        // Lindungi agar mode non-produksi tidak pernah diam-diam mengarah ke host produksi Midtrans.
        if (! $this->isProduction && strcasecmp($normalized, $productionUrl) === 0) {
            return $sandboxUrl;
        }

        return $normalized;
    }

    /**
     * Ambil field skalar untuk formula signature (tolak array, object, bool, null, dan string kosong).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signatureScalarField(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        $value = $payload[$key];
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $str = trim((string) $value);

        return $str !== '' ? $str : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
