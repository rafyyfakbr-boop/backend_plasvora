<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature Test: Authentication & User Profile
 *
 * Menguji:
 * - Register user baru (password ter-hash, tidak boleh ada field password dalam JSON response)
 * - Login dengan kredensial valid menghasilkan Sanctum Bearer token
 * - Login dengan kredensial salah menghasilkan 401
 * - Endpoint /api/me mengembalikan data profil pengguna yang login
 * - Logout mencabut token
 * - Endpoint terproteksi menolak request tanpa token (401)
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_register_with_valid_data(): void
    {
        $payload = [
            'name' => 'Siswa RPL Plasvora',
            'email' => 'siswa@plasvora.test',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'user' => [
                        'name' => 'Siswa RPL Plasvora',
                        'email' => 'siswa@plasvora.test',
                    ],
                ],
            ]);

        // Pastikan token dikembalikan dan password TIDAK ada di response
        $this->assertArrayHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('password', $response->json('data.user'));

        // Pastikan password di database ter-hash (bukan plaintext)
        $user = User::where('email', 'siswa@plasvora.test')->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('Rahasia123', $user->password);
        $this->assertTrue(Hash::check('Rahasia123', $user->password));
    }

    /** @test */
    public function user_can_login_after_successful_registration(): void
    {
        $payload = [
            'name' => 'Akun Baru Plasvora',
            'email' => 'akunbaru@plasvora.test',
            'password' => 'KataSandiKuat99',
            'password_confirmation' => 'KataSandiKuat99',
        ];

        $registerResponse = $this->postJson('/api/register', $payload);
        $registerResponse->assertStatus(201);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'akunbaru@plasvora.test',
            'password' => 'KataSandiKuat99',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'email' => 'akunbaru@plasvora.test',
                    ],
                    'token_type' => 'Bearer',
                ],
            ]);

        $this->assertArrayHasKey('token', $loginResponse->json('data'));
        $this->assertArrayNotHasKey('password', $loginResponse->json('data.user'));
    }

    /** @test */
    public function user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'pelajar@plasvora.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'pelajar@plasvora.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => 'pelajar@plasvora.test',
                    ],
                ],
            ]);

        $this->assertArrayHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
    }

    /** @test */
    public function login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'pelajar@plasvora.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'pelajar@plasvora.test',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Email atau password tidak valid',
            ]);
    }

    /** @test */
    public function authenticated_user_can_view_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);

        // Password tidak pernah terekspos
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    /** @test */
    public function authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);

        // Token sudah terhapus di database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    /** @test */
    public function unauthenticated_access_to_protected_route_is_rejected(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated. Please login first.',
            ]);
    }

    /** @test */
    public function login_rate_limiting_restricts_after_five_attempts_for_same_email_and_ip(): void
    {
        User::factory()->create([
            'email' => 'limit_test@plasvora.test',
            'password' => Hash::make('password123'),
        ]);

        // 5 percobaan pertama gagal menghasilkan 401
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'limit_test@plasvora.test',
                'password' => 'wrongpassword',
            ]);
            $response->assertStatus(401);
        }

        // Percobaan ke-6 harus ditolak oleh RateLimiter dengan HTTP 429
        $throttledResponse = $this->postJson('/api/login', [
            'email' => 'limit_test@plasvora.test',
            'password' => 'password123', // Kredensial benar sekalipun harus tetap diblokir
        ]);

        $throttledResponse->assertStatus(429)
            ->assertJson([
                'success' => false,
                'message' => 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.',
            ]);

        $this->assertTrue($throttledResponse->headers->has('Retry-After'));
    }

    /** @test */
    public function login_rate_limiting_normalizes_email_case_and_whitespace(): void
    {
        User::factory()->create([
            'email' => 'normalize@plasvora.test',
            'password' => Hash::make('password123'),
        ]);

        // 5 percobaan dengan variasi spasi dan huruf kapital
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/login', [
                'email' => '  NORMALIZE@plasvora.test  ',
                'password' => 'wrongpassword',
            ])->assertStatus(401);
        }

        // Percobaan ke-6 dengan email canonical tetap terkena limit
        $this->postJson('/api/login', [
            'email' => 'normalize@plasvora.test',
            'password' => 'wrongpassword',
        ])->assertStatus(429);
    }

    /** @test */
    public function registration_rate_limiting_restricts_fourth_request_in_a_minute(): void
    {
        // 3 registrasi pertama berhasil (201)
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/register', [
                'name' => "User Reg {$i}",
                'email' => "user_reg_{$i}@plasvora.test",
                'password' => 'PasswordAman123',
                'password_confirmation' => 'PasswordAman123',
            ])->assertStatus(201);
        }

        // Permintaan ke-4 dalam menit yang sama harus diblokir (429)
        $throttled = $this->postJson('/api/register', [
            'name' => 'User Reg 4',
            'email' => 'user_reg_4@plasvora.test',
            'password' => 'PasswordAman123',
            'password_confirmation' => 'PasswordAman123',
        ]);

        $throttled->assertStatus(429)
            ->assertJson([
                'success' => false,
                'message' => 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.',
            ]);

        // Pastikan akun ke-4 TIDAK dibuat di database
        $this->assertDatabaseMissing('users', [
            'email' => 'user_reg_4@plasvora.test',
        ]);
    }

    /** @test */
    public function login_rate_limiting_restricts_sixty_first_attempt_per_ip(): void
    {
        // 60 percobaan login pertama dengan email berbeda dari IP yang sama
        // Penggunaan email berbeda memastikan batas 5/menit per hash(email)+IP tidak terpicu
        for ($i = 1; $i <= 60; $i++) {
            $this->postJson('/api/login', [
                'email' => "unique_ip_user_{$i}@plasvora.test",
                'password' => 'wrongpassword',
            ])->assertStatus(401);
        }

        // Percobaan ke-61 dari IP yang sama harus diblokir oleh limit 60/menit per IP (429)
        $throttled = $this->postJson('/api/login', [
            'email' => 'unique_ip_user_61@plasvora.test',
            'password' => 'wrongpassword',
        ]);

        $throttled->assertStatus(429)
            ->assertJson([
                'success' => false,
                'message' => 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.',
            ]);

        $this->assertTrue($throttled->headers->has('Retry-After'));
    }

    /** @test */
    public function registration_rate_limiting_restricts_twenty_first_attempt_per_hour(): void
    {
        try {
            // Lakukan 20 registrasi bertahap dengan memajukan waktu 65 detik tiap 3 request
            // agar tidak terblokir oleh limit per menit (3/menit per IP) namun tetap dalam 1 jam yang sama
            for ($batch = 0; $batch < 6; $batch++) {
                for ($i = 1; $i <= 3; $i++) {
                    $index = ($batch * 3) + $i;
                    $this->postJson('/api/register', [
                        'name' => "User Hourly {$index}",
                        'email' => "hourly_user_{$index}@plasvora.test",
                        'password' => 'PasswordAman123',
                        'password_confirmation' => 'PasswordAman123',
                    ])->assertStatus(201);
                }
                $this->travel(65)->seconds();
            }

            // 2 request tambahan untuk mencapai tepat 20 registrasi dalam 1 jam
            for ($i = 19; $i <= 20; $i++) {
                $this->postJson('/api/register', [
                    'name' => "User Hourly {$i}",
                    'email' => "hourly_user_{$i}@plasvora.test",
                    'password' => 'PasswordAman123',
                    'password_confirmation' => 'PasswordAman123',
                ])->assertStatus(201);
            }

            // Permintaan ke-21 dalam 1 jam yang sama harus diblokir oleh limit 20/jam per IP (429)
            $throttled = $this->postJson('/api/register', [
                'name' => 'User Hourly 21',
                'email' => 'hourly_user_21@plasvora.test',
                'password' => 'PasswordAman123',
                'password_confirmation' => 'PasswordAman123',
            ]);

            $throttled->assertStatus(429)
                ->assertJson([
                    'success' => false,
                    'message' => 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.',
                ]);

            // Pastikan akun ke-21 tidak dibuat di database
            $this->assertDatabaseMissing('users', [
                'email' => 'hourly_user_21@plasvora.test',
            ]);

            // Pastikan tepat 20 user batch ini yang tersimpan
            $this->assertEquals(20, User::where('email', 'like', 'hourly_user_%')->count());
        } finally {
            $this->travelBack();
        }
    }
}
