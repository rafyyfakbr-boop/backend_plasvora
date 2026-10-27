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
}
