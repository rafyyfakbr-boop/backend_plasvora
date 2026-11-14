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
    }
}

