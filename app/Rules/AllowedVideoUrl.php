<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedVideoUrl implements ValidationRule
{
    /**
     * Validasi ketat URL video eksternal:
     * - Wajib skema https
     * - Tidak boleh mengandung kredensial (user@host)
     * - Host hasil parse harus sama persis atau merupakan subdomain sah dari daftar config('gallery.allowed_video_hosts')
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Tautan video harus berupa teks URL yang valid.');
            return;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 500) {
            $fail('Tautan video harus berupa URL HTTPS yang valid (maksimal 500 karakter).');
            return;
        }

        if (preg_match('/\s/', $trimmed) === 1 || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            $fail('Format tautan video tidak valid.');
            return;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            $fail('Format tautan video tidak valid.');
            return;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            $fail('Tautan video wajib menggunakan protokol HTTPS.');
            return;
        }

        // Tolak URL dengan kredensial tertanam (mis. https://user@youtube.com)
        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('Tautan video tidak boleh mengandung kredensial pengguna.');
            return;
        }

        // Cek defensif terhadap karakter '@' pada segmen authority
        $afterScheme = substr($trimmed, strlen('https://'));
        $authority = explode('/', explode('?', explode('#', $afterScheme)[0])[0])[0];
        if (str_contains($authority, '@')) {
            $fail('Tautan video tidak boleh mengandung kredensial pengguna.');
            return;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || str_ends_with($host, '.')) {
            $fail('Host tautan video tidak valid.');
            return;
        }

        $allowedHosts = config('gallery.allowed_video_hosts', [
            'youtube.com',
            'youtu.be',
            'tiktok.com',
            'instagram.com',
        ]);

        foreach ($allowedHosts as $allowed) {
            $normalizedAllowed = strtolower(trim((string) $allowed));
            if ($normalizedAllowed === '') {
                continue;
            }
            if ($host === $normalizedAllowed || str_ends_with($host, '.' . $normalizedAllowed)) {
                return;
            }
        }

        $fail('Tautan video hanya diperbolehkan dari YouTube, TikTok, atau Instagram.');
    }
}
