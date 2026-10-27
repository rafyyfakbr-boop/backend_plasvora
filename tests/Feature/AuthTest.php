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
            'name'                  => 'Siswa RPL Plasvora',
            'email'                 => 'siswa@plasvora.test',
            'password'              => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Registration successful',
                'data'    => [
                    'user' => [
                        'name'  => 'Siswa RPL Plasvora',
                        'email' => 'siswa@plasvora.test',
                    ],
                ],
            ]);

        // Pastikan token dikembalikan dan password TIDAK ada di response
        $this->assertArrayHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('password', $response->json('data.user'));

        // Pastikan password di database ter-hash
        $user = User::where('email', 'siswa@plasvora.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('Rahasia123', $user->password));
    }

    /** @test */
    public function user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'email'    => 'pelajar@plasvora.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'pelajar@plasvora.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
                'data'    => [
                    'user' => [
                        'id'    => $user->id,
                        'email' => 'pelajar@plasvora.test',
                    ],
                ],
            ]);

        $this->assertArrayHasKey('token', $response->json('data'));
    }

    /** @test */
    public function login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email'    => 'pelajar@plasvora.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'pelajar@plasvora.test',
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
                'data'    => [
                    'id'    => $user->id,
                    'name'  => $user->name,
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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
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
}
