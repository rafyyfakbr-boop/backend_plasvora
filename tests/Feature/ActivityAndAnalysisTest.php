<?php

namespace Tests\Feature;

use App\Models\AnalysisHistory;
use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test: Activity History & Analysis Recommendation API
 */
class ActivityAndAnalysisTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function activity_history_returns_timeline_for_authenticated_user(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        // User membuat listing
        MaterialListing::factory()->create([
            'owner_id' => $user->id,
            'name'     => 'Botol PET Daur Ulang',
        ]);

        // User membuat material request
        MaterialRequest::factory()->create([
            'requester_id'      => $user->id,
            'material_category' => 'HDPE',
        ]);

        // Orang lain membuat listing yang tidak melibatkan user
        MaterialListing::factory()->create([
            'owner_id' => $other->id,
            'name'     => 'Listing Orang Lain',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/activities');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Activity history retrieved successfully',
            ]);

        // Harus ada 2 aktivitas milik user, dan tidak ada aktivitas milik orang lain
        $activities = $response->json('data');
        $this->assertCount(2, $activities);
    }

    /** @test */
    public function analysis_history_stores_rule_based_recommendation_with_demo_flag(): void
    {
        $user = User::factory()->create();

        $payload = [
            'declared_category' => 'PET',
            'weight_kg'         => 12.5,
            'condition'         => 'clean',
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/analyses', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Analysis completed. Results are rule-based recommendations, not AI.',
                'data'    => [
                    'is_demo_result' => true,
                    'user_id'        => $user->id,
                ],
            ]);

        // Memastikan ada rekomendasi pemanfaatan dalam format JSON
        $data = $response->json('data');
        $this->assertNotEmpty($data['recommendations']);
        $this->assertEquals('PET', $data['declared_category']);
    }

    /** @test */
    public function user_can_view_their_own_analyses_only(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        AnalysisHistory::create([
            'user_id'           => $user->id,
            'declared_category' => 'HDPE',
            'weight_kg'         => 5.0,
            'is_demo_result'    => true,
        ]);

        AnalysisHistory::create([
            'user_id'           => $other->id,
            'declared_category' => 'PP',
            'weight_kg'         => 8.0,
            'is_demo_result'    => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/analyses');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function analysis_rate_limiting_restricts_fourth_request_in_a_minute(): void
    {
        $user = User::factory()->create();

        $payload = [
            'declared_category' => 'PET',
            'weight_kg'         => 1.5,
            'condition'         => 'clean',
        ];

        // 3 analisis pertama berhasil (201)
        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/analyses', $payload)
                ->assertStatus(201);
        }

        // Permintaan ke-4 dalam menit yang sama harus diblokir oleh RateLimiter (429)
        $throttled = $this->actingAs($user, 'sanctum')
            ->postJson('/api/analyses', $payload);

        $throttled->assertStatus(429)
            ->assertJson([
                'success' => false,
                'message' => 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.',
            ]);

        // Pastikan hanya 3 riwayat analisis yang tersimpan di database
        $this->assertEquals(3, AnalysisHistory::where('user_id', $user->id)->count());
    }

    /** @test */
    public function analysis_rate_limiting_is_isolated_per_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $payload = [
            'declared_category' => 'HDPE',
            'weight_kg'         => 2.0,
            'condition'         => 'clean',
        ];

        // User A menghabiskan kuota per menit (3 request)
        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($userA, 'sanctum')
                ->postJson('/api/analyses', $payload)
                ->assertStatus(201);
        }

        // User A terkena pembatasan 429
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/analyses', $payload)
            ->assertStatus(429);

        // User B yang berbeda harus TETAP DAPAT menganalisis (kuota independen)
        $this->actingAs($userB, 'sanctum')
            ->postJson('/api/analyses', $payload)
            ->assertStatus(201);
    }

    /** @test */
    public function analysis_rate_limiting_restricts_twenty_first_request_per_day(): void
    {
        $user = User::factory()->create();
        $payload = [
            'declared_category' => 'PET',
            'weight_kg'         => 1.0,
            'condition'         => 'clean',
        ];

        try {
            // Lakukan 20 analisis bertahap dengan jeda waktu 65 detik tiap 3 request
            // agar tidak terhalang limit 3/menit per user namun tetap dalam 1 hari yang sama
            for ($batch = 0; $batch < 6; $batch++) {
                for ($i = 1; $i <= 3; $i++) {
                    $this->actingAs($user, 'sanctum')
                        ->postJson('/api/analyses', $payload)
                        ->assertStatus(201);
                }
                $this->travel(65)->seconds();
            }

            // 2 request tambahan untuk mencapai kuota 20 per hari
            for ($i = 19; $i <= 20; $i++) {
                $this->actingAs($user, 'sanctum')
                    ->postJson('/api/analyses', $payload)
                    ->assertStatus(201);
            }

            // Permintaan ke-21 dalam hari yang sama harus diblokir oleh limit 20/hari per user (429)
            $throttled = $this->actingAs($user, 'sanctum')
                ->postJson('/api/analyses', $payload);

            $throttled->assertStatus(429)
                ->assertJson([
                    'success' => false,
                    'message' => 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.',
                ]);

            // Pastikan tepat 20 riwayat tersimpan untuk user ini
            $this->assertEquals(20, AnalysisHistory::where('user_id', $user->id)->count());

            // User lain harus tetap dapat melakukan analisis (kuota harian independen per user)
            $otherUser = User::factory()->create();
            $this->actingAs($otherUser, 'sanctum')
                ->postJson('/api/analyses', $payload)
                ->assertStatus(201);
        } finally {
            $this->travelBack();
        }
    }

    /** @test */
    public function user_can_view_specific_analysis_detail(): void
    {
        $user = User::factory()->create();

        $analysis = AnalysisHistory::create([
            'user_id'            => $user->id,
            'declared_category'  => 'PET',
            'estimated_category' => 'PET',
            'weight_kg'          => 2.5,
            'condition'          => 'clean',
            'recommendations'    => [
                'polymer_name'   => 'Polyethylene Terephthalate',
                'object_name'    => 'Botol Air Mineral',
                'material_title' => 'Botol PET Bersih',
            ],
            'is_demo_result'     => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/analyses/{$analysis->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'id'                 => $analysis->id,
                    'declared_category'  => 'PET',
                    'estimated_category' => 'PET',
                ],
            ]);
    }

    /** @test */
    public function user_cannot_view_other_users_analysis_detail(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        $analysis = AnalysisHistory::create([
            'user_id'            => $other->id,
            'declared_category'  => 'PP',
            'estimated_category' => 'PP',
            'is_demo_result'     => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/analyses/{$analysis->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Riwayat analisis tidak ditemukan.',
            ]);
    }

    /** @test */
    public function activity_history_includes_plastic_analysis_entries(): void
    {
        $user = User::factory()->create();

        AnalysisHistory::create([
            'user_id'            => $user->id,
            'declared_category'  => 'PET',
            'estimated_category' => 'PET',
            'weight_kg'          => 3.0,
            'recommendations'    => [
                'object_name' => 'Botol Plastik Bekas',
            ],
            'is_demo_result'     => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/activities');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $activities = collect($response->json('data'));
        $analysisActivity = $activities->firstWhere('type', 'plastic_analysis');

        $this->assertNotNull($analysisActivity);
        $this->assertEquals('Analisis plastik selesai', $analysisActivity['action']);
        $this->assertEquals('completed', $analysisActivity['status']);
    }
}
