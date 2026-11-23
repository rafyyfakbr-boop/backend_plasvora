<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RajaOngkirService
{
    protected ?string $apiKey;
    protected string $baseUrl;
    protected string $couriers;
    protected string $priceMode;
    protected int $timeout;
    protected int $connectTimeout;
    protected int $searchCacheTtl;

    public function __construct()
    {
        $this->apiKey         = config('services.rajaongkir.api_key');
        $this->baseUrl        = rtrim((string) config('services.rajaongkir.base_url', 'https://rajaongkir.komerce.id/api/v1'), '/');
        $configuredCouriers   = trim((string) config('services.rajaongkir.couriers', 'jne:jnt'));
        $this->couriers       = $configuredCouriers !== '' ? $configuredCouriers : 'jne:jnt';
        $this->priceMode      = 'lowest';
        $this->timeout        = (int) config('services.rajaongkir.timeout', 15);
        $this->connectTimeout = (int) config('services.rajaongkir.connect_timeout', 5);
        $this->searchCacheTtl = max(0, (int) config('services.rajaongkir.search_cache_ttl', 300));
    }

    /**
     * Cari destinasi domestik Indonesia via RajaOngkir/Komerce Direct Search.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchDestinations(string $search, int $limit = 10, int $offset = 0): array
    {
        $this->ensureConfigured();

        $keyword = trim($search);
        if ($keyword === '') {
            return [];
        }

        $safeLimit  = max(1, min(20, $limit));
        $safeOffset = max(0, $offset);

        $fetcher = function () use ($keyword, $safeLimit, $safeOffset): array {
            try {
                $response = Http::connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->withHeaders([
                        'key'    => $this->apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->get("{$this->baseUrl}/destination/domestic-destination", [
                        'search' => $keyword,
                        'limit'  => $safeLimit,
                        'offset' => $safeOffset,
                    ]);
            } catch (ConnectionException) {
                Log::warning('RajaOngkirService: timeout/koneksi gagal saat mencari destinasi.', [
                    'limit'  => $safeLimit,
                    'offset' => $safeOffset,
                ]);
                throw new RuntimeException(
                    'Waktu tunggu pencarian lokasi pengiriman habis. Silakan coba lagi.',
                    504
                );
            }

            $payload = $this->parseJsonOrFail($response, 'pencarian lokasi pengiriman');
            $items   = $payload['data'] ?? null;

            if (! is_array($items)) {
                return [];
            }

            $normalized = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id    = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
                $label = $this->nullableString($item['label'] ?? null);

                if ($id <= 0 || $label === null) {
                    continue;
                }

                $normalized[] = [
                    'destination_id'   => $id,
                    'label'            => $label,
                    'province_name'    => $this->nullableString($item['province_name'] ?? null),
                    'city_name'        => $this->nullableString($item['city_name'] ?? null),
                    'district_name'    => $this->nullableString($item['district_name'] ?? null),
                    'subdistrict_name' => $this->nullableString($item['subdistrict_name'] ?? null),
                    'postal_code'      => $this->nullableString($item['zip_code'] ?? null),
                ];
            }

            return $normalized;
        };

        if ($this->searchCacheTtl <= 0) {
            return $fetcher();
        }

        $cacheKey = 'rajaongkir:destinations:' . sha1(mb_strtolower($keyword) . "|{$safeLimit}|{$safeOffset}");

        return Cache::remember($cacheKey, $this->searchCacheTtl, $fetcher);
    }

    /**
     * Hitung estimasi ongkir domestik berdasarkan ID asal, tujuan, dan berat (gram) otoritatif.
     * Kurir dan mode harga (lowest) selalu menggunakan konfigurasi server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calculateDomesticCost(
        int $originDestinationId,
        int $destinationId,
        int $weightGrams
    ): array {
        $this->ensureConfigured();

        if ($originDestinationId <= 0) {
            throw new RuntimeException('Lokasi asal pengiriman tidak valid.', 422);
        }

        if ($destinationId <= 0) {
            throw new RuntimeException('Lokasi tujuan pengiriman tidak valid.', 422);
        }

        if ($weightGrams < 1) {
            throw new RuntimeException('Berat material untuk perhitungan ongkir harus minimal 1 gram.', 422);
        }

        try {
            $response = Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->asForm()
                ->withHeaders([
                    'key'    => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->post("{$this->baseUrl}/calculate/domestic-cost", [
                    'origin'      => (string) $originDestinationId,
                    'destination' => (string) $destinationId,
                    'weight'      => (string) $weightGrams,
                    'courier'     => $this->couriers,
                    'price'       => $this->priceMode,
                ]);
        } catch (ConnectionException) {
            Log::warning('RajaOngkirService: timeout/koneksi gagal saat menghitung ongkir.', [
                'origin'       => $originDestinationId,
                'destination'  => $destinationId,
                'weight_grams' => $weightGrams,
                'couriers'     => $this->couriers,
            ]);
            throw new RuntimeException(
                'Waktu tunggu perhitungan ongkos kirim habis. Silakan coba lagi.',
                504
            );
        }

        $payload = $this->parseJsonOrFail($response, 'perhitungan ongkos kirim');
        $items   = $payload['data'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $options = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! isset($item['cost']) || ! is_numeric($item['cost'])) {
                continue;
            }

            $courierCode = strtolower(trim((string) ($item['code'] ?? '')));
            $courierName = trim((string) ($item['name'] ?? ''));
            $service     = trim((string) ($item['service'] ?? ''));

            if ($courierCode === '' || $service === '') {
                continue;
            }

            $options[] = [
                'courier_code' => $courierCode,
                'courier_name' => $courierName !== '' ? $courierName : strtoupper($courierCode),
                'service'      => $service,
                'description'  => $this->nullableString($item['description'] ?? null),
                'cost'         => (int) round((float) $item['cost']),
                'etd'          => $this->normalizeEtd($item['etd'] ?? null),
            ];
        }

        return $options;
    }

    /**
     * Daftar kode kurir terkonfigurasi di server.
     */
    public function configuredCouriers(): string
    {
        return $this->couriers;
    }

    protected function ensureConfigured(): void
    {
        if (empty($this->apiKey) || empty($this->baseUrl)) {
            Log::error('RajaOngkirService: konfigurasi RAJAONGKIR_API_KEY atau RAJAONGKIR_BASE_URL belum lengkap.');
            throw new RuntimeException(
                'Layanan estimasi ongkir belum dikonfigurasi pada server.',
                503
            );
        }
    }

    /**
     * Validasi respons HTTP dan decode JSON tanpa membocorkan kredensial.
     *
     * @return array<string, mixed>
     */
    protected function parseJsonOrFail(Response $response, string $operationLabel): array
    {
        $status  = $response->status();
        $decoded = $response->json();

        if (! $response->successful()) {
            $upstreamMessage = is_array($decoded)
                ? $this->nullableString($decoded['meta']['message'] ?? $decoded['message'] ?? null)
                : null;

            Log::error("RajaOngkirService: gagal saat {$operationLabel}.", [
                'http_status'      => $status,
                'upstream_message' => $upstreamMessage,
            ]);

            if (in_array($status, [401, 403], true)) {
                throw new RuntimeException(
                    'Layanan estimasi ongkir sedang mengalami kendala otorisasi di server.',
                    503
                );
            }

            if ($status === 429) {
                throw new RuntimeException(
                    'Layanan estimasi ongkir sedang sibuk. Silakan coba beberapa saat lagi.',
                    429
                );
            }

            if ($status >= 400 && $status < 500) {
                $detail = $upstreamMessage !== null ? " ({$upstreamMessage})" : '';
                throw new RuntimeException(
                    "Permintaan {$operationLabel} tidak dapat diproses oleh layanan pengiriman{$detail}.",
                    422
                );
            }

            throw new RuntimeException(
                "Layanan {$operationLabel} sedang mengalami gangguan sementara. Silakan coba lagi.",
                502
            );
        }

        if (! is_array($decoded)) {
            Log::error("RajaOngkirService: respons JSON tidak valid saat {$operationLabel}.", [
                'http_status' => $status,
            ]);
            throw new RuntimeException(
                "Format respons layanan {$operationLabel} tidak valid.",
                502
            );
        }

        return $decoded;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function normalizeEtd(mixed $value): ?string
    {
        $str = $this->nullableString($value);
        if ($str === null) {
            return null;
        }

        // Normalisasi placeholder kosong seperti "-" atau "--" menjadi null
        if (preg_match('/^[\-\s]+$/', $str) === 1) {
            return null;
        }

        return $str;
    }
}
