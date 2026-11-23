<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Konfigurasi Named Rate Limiters untuk keamanan endpoint Plasvora.
     */
    protected function configureRateLimiting(): void
    {
        // Response callback standar JSON untuk HTTP 429 Plasvora
        $throttleResponse = function (Request $request, array $headers) {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.',
            ], 429, $headers);
        };

        // 1. Rate limiter login (SEC-001)
        // Lapisan 1: 5 permintaan/menit per kombinasi email ter-hash dan IP
        // Lapisan 2: 60 permintaan/menit per IP
        RateLimiter::for('login', function (Request $request) use ($throttleResponse) {
            $email = (string) $request->input('email', '');
            $emailHash = hash('sha256', mb_strtolower(trim($email)));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)
                    ->by('login:' . $emailHash . '|' . $ip)
                    ->response($throttleResponse),
                Limit::perMinute(60)
                    ->by('login:ip:' . $ip)
                    ->response($throttleResponse),
            ];
        });

        // 2. Rate limiter registrasi (SEC-001)
        // Batas 1: 3 permintaan/menit per IP
        // Batas 2: 20 permintaan/jam per IP
        RateLimiter::for('register', function (Request $request) use ($throttleResponse) {
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(3)
                    ->by('register:ip:' . $ip)
                    ->response($throttleResponse),
                Limit::perHour(20)
                    ->by('register:ip:hour:' . $ip)
                    ->response($throttleResponse),
            ];
        });

        // 3. Rate limiter analisis plastik (SEC-007)
        // Batas 1: 3 permintaan/menit per pengguna terautentikasi Sanctum
        // Batas 2: 20 permintaan/hari per pengguna terautentikasi Sanctum
        RateLimiter::for('analyses', function (Request $request) use ($throttleResponse) {
            $userId = (string) ($request->user()?->id ?: $request->ip());

            return [
                Limit::perMinute(3)
                    ->by('analyses:user:' . $userId)
                    ->response($throttleResponse),
                Limit::perDay(20)
                    ->by('analyses:user:day:' . $userId)
                    ->response($throttleResponse),
            ];
        });

        // 4. Rate limiter pencarian destinasi RajaOngkir (Stage 2)
        // Batas: 30 permintaan/menit per pengguna terautentikasi (fallback IP)
        RateLimiter::for('shipping-search', function (Request $request) use ($throttleResponse) {
            $key = $request->user()?->id
                ? 'shipping-search:user:' . $request->user()->id
                : 'shipping-search:ip:' . $request->ip();

            return Limit::perMinute(30)
                ->by($key)
                ->response($throttleResponse);
        });

        // 5. Rate limiter estimasi ongkir RajaOngkir (Stage 2)
        // Batas: 20 permintaan/menit per pengguna terautentikasi (fallback IP)
        RateLimiter::for('shipping-estimate', function (Request $request) use ($throttleResponse) {
            $key = $request->user()?->id
                ? 'shipping-estimate:user:' . $request->user()->id
                : 'shipping-estimate:ip:' . $request->ip();

            return Limit::perMinute(20)
                ->by($key)
                ->response($throttleResponse);
        });

        // 6. Rate limiter checkout pesanan (Stage 3)
        // Batas: 10 permintaan/menit per pengguna terautentikasi (fallback IP)
        RateLimiter::for('checkout', function (Request $request) use ($throttleResponse) {
            $key = $request->user()?->id
                ? 'checkout:user:' . $request->user()->id
                : 'checkout:ip:' . $request->ip();

            return Limit::perMinute(10)
                ->by($key)
                ->response($throttleResponse);
        });

        // 7. Rate limiter sinkronisasi & retry status pembayaran Midtrans (Stage 3)
        // Batas: 20 permintaan/menit per pengguna terautentikasi (fallback IP)
        RateLimiter::for('payment-status', function (Request $request) use ($throttleResponse) {
            $key = $request->user()?->id
                ? 'payment-status:user:' . $request->user()->id
                : 'payment-status:ip:' . $request->ip();

            return Limit::perMinute(20)
                ->by($key)
                ->response($throttleResponse);
        });

        // 8. Rate limiter webhook Midtrans publik (Stage 3)
        // Burst allowance memadai per IP agar retry sah Midtrans tidak tertolak
        RateLimiter::for('midtrans-webhook', function (Request $request) use ($throttleResponse) {
            return Limit::perMinute(120)
                ->by('midtrans-webhook:ip:' . $request->ip())
                ->response($throttleResponse);
        });
    }
}

