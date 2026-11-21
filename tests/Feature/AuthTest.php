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

    // ─── Stage 1: Phone Number Registration & Profile Update ──────────────

    /** @test */
    public function user_can_register_with_optional_indonesian_phone_normalized(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Penjual Plasvora',
            'email' => 'penjual_phone@plasvora.test',
            'phone' => '0812-3456-7890',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.phone', '6281234567890');

        $this->assertDatabaseHas('users', [
            'email' => 'penjual_phone@plasvora.test',
            'phone' => '6281234567890',
        ]);
    }

    /** @test */
    public function registration_rejects_invalid_phone_format(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Invalid Phone User',
            'email' => 'invalid_phone@plasvora.test',
            'phone' => '12345',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    /** @test */
    public function authenticated_user_can_update_phone_via_patch_me(): void
    {
        $user = User::factory()->create(['phone' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/me', [
                'phone' => '+62 813 9876 5432',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'phone' => '6281398765432',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => '6281398765432',
        ]);
    }

    /** @test */
    public function patch_me_rejects_invalid_phone_and_allows_clearing_phone(): void
    {
        $user = User::factory()->create(['phone' => '6281234567890']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/me', ['phone' => 'bukan-nomor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/me', ['phone' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.phone', null);
    }

    // ─── Stage 1.1: WhatsApp Contact Privacy ──────────────────────────────

    /** @test */
    public function existing_and_newly_registered_users_default_to_whatsapp_public_false(): void
    {
        $existing = User::factory()->create();
        $existing->refresh();
        $this->assertFalse($existing->whatsapp_public);

        $this->actingAs($existing, 'sanctum')
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('data.whatsapp_public', false);

        // Bahkan jika klien mengirim whatsapp_public=true saat register, tetap harus false
        $registerResponse = $this->postJson('/api/register', [
            'name' => 'Privasi Default',
            'email' => 'privasi_default@plasvora.test',
            'phone' => '081234567890',
            'whatsapp_public' => true,
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);

        $registerResponse->assertStatus(201)
            ->assertJsonPath('data.user.whatsapp_public', false);

        $this->assertDatabaseHas('users', [
            'email' => 'privasi_default@plasvora.test',
            'whatsapp_public' => false,
        ]);
    }

    /** @test */
    public function authenticated_user_can_enable_and_disable_whatsapp_visibility(): void
    {
        $user = User::factory()->create([
            'phone' => '6281234567890',
            'whatsapp_public' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/me', ['whatsapp_public' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.whatsapp_public', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'whatsapp_public' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/me', ['whatsapp_public' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.whatsapp_public', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'whatsapp_public' => false,
        ]);
    }

    /** @test */
    public function user_cannot_enable_whatsapp_visibility_without_phone_and_clearing_phone_disables_it(): void
    {
        $userWithoutPhone = User::factory()->create([
            'phone' => null,
            'whatsapp_public' => false,
        ]);

        $this->actingAs($userWithoutPhone, 'sanctum')
            ->patchJson('/api/me', ['whatsapp_public' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_public']);

        $userWithPhone = User::factory()->create([
            'phone' => '6281234567890',
            'whatsapp_public' => true,
        ]);

        $this->actingAs($userWithPhone, 'sanctum')
            ->patchJson('/api/me', ['phone' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.whatsapp_public', false);

        $this->assertDatabaseHas('users', [
            'id' => $userWithPhone->id,
            'phone' => null,
            'whatsapp_public' => false,
        ]);
    }

    /** @test */
    public function user_cannot_update_another_users_whatsapp_visibility(): void
    {
        $actor = User::factory()->create([
            'phone' => '6281234567890',
            'whatsapp_public' => false,
        ]);
        $otherUser = User::factory()->create([
            'phone' => '6281999888777',
            'whatsapp_public' => false,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson('/api/me', [
                'id' => $otherUser->id,
                'user_id' => $otherUser->id,
                'whatsapp_public' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $actor->id)
            ->assertJsonPath('data.whatsapp_public', true);

        // User lain tetap tidak terpengaruh
        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'whatsapp_public' => false,
        ]);
    }
}
