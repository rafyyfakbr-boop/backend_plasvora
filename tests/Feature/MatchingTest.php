<?php

namespace Tests\Feature;

use App\Models\MaterialListing;
use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test: Smart Matching API
 *
 * Menguji bahwa matching mengembalikan alasan kecocokan
 * yang valid dan sesuai dengan data database nyata.
 */
class MatchingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function matching_returns_match_reasons_for_request(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();

        // Buat listing yang cocok untuk request di bawah
        MaterialListing::factory()->create([
            'owner_id'         => $owner->id,
            'category'         => 'PET',
            'weight_kg'        => 30,
            'condition'        => 'clean',
            'general_location' => 'Bandung Utara',
            'status'           => 'available',
        ]);

        // Buat request yang membutuhkan PET
        $request = MaterialRequest::factory()->create([
            'requester_id'        => $requester->id,
            'material_category'   => 'PET',
            'requested_weight_kg' => 20,
            'minimum_condition'   => 'slightly_dirty',
            'general_location'    => 'Bandung Selatan',
            'status'              => 'active',
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson("/api/material-requests/{$request->id}/matches");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'request',
                    'matches',
                    'total',
                    'note',
                ],
            ]);

        // Harus ada setidaknya 1 match
        $this->assertGreaterThan(0, $response->json('data.total'));

        // Match harus memiliki match_reasons
        $firstMatch = $response->json('data.matches.0');
        $this->assertArrayHasKey('match_reasons', $firstMatch);
        $this->assertArrayHasKey('match_reasons_detail', $firstMatch);
        $this->assertArrayHasKey('match_score', $firstMatch);
        $this->assertArrayHasKey('score_explanation', $firstMatch);

        // category_match harus ada
        $this->assertContains('category_match', $firstMatch['match_reasons']);
    }

    /** @test */
    public function matching_does_not_return_unavailable_listings(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();

        // Buat listing yang kategori cocok TAPI tidak available
        MaterialListing::factory()->create([
            'owner_id'  => $owner->id,
            'category'  => 'HDPE',
            'weight_kg' => 20,
            'condition' => 'clean',
            'status'    => 'reserved', // TIDAK available
        ]);

        $request = MaterialRequest::factory()->create([
            'requester_id'        => $requester->id,
            'material_category'   => 'HDPE',
            'requested_weight_kg' => 10,
            'minimum_condition'   => 'clean',
            'status'              => 'active',
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson("/api/material-requests/{$request->id}/matches");

        $response->assertStatus(200)->assertJson(['success' => true]);

        // Tidak boleh ada match — listing reserved tidak dihitung
        $this->assertEquals(0, $response->json('data.total'));
    }

    /** @test */
    public function matching_returns_404_for_nonexistent_request(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/material-requests/99999/matches');

        $response->assertStatus(404)->assertJson(['success' => false]);
    }

    /** @test */
    public function matching_returns_listings_matching_for_a_listing(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id'  => $owner->id,
            'category'  => 'PP',
            'weight_kg' => 15,
            'condition' => 'clean',
            'status'    => 'available',
        ]);

        // Buat request yang cocok
        MaterialRequest::factory()->create([
            'requester_id'        => $requester->id,
            'material_category'   => 'PP',
            'requested_weight_kg' => 10,
            'minimum_condition'   => 'dirty',
            'status'              => 'active',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/materials/{$listing->id}/matches");

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertGreaterThan(0, $response->json('data.total'));
    }
}
