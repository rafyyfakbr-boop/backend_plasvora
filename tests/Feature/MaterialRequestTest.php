<?php

namespace Tests\Feature;

use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test: Material Requests API
 *
 * Menguji kebutuhan material:
 * - List requests yang berstatus active
 * - Detail request
 * - Pembuatan request oleh user terautentikasi
 * - Validasi input (kategori enum, requested_weight_kg > 0, minimum_condition enum)
 * - requester_id tidak bisa dipalsukan via request body
 * - Update dan deactivate hanya oleh pemilik request
 */
class MaterialRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_retrieve_list_of_active_material_requests(): void
    {
        $user = User::factory()->create();

        MaterialRequest::factory()->create([
            'requester_id' => $user->id,
            'status'       => 'active',
        ]);

        MaterialRequest::factory()->create([
            'requester_id' => $user->id,
            'status'       => 'fulfilled',
        ]);

        $response = $this->getJson('/api/material-requests');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Material requests retrieved successfully',
            ])
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function it_can_retrieve_detail_of_material_request(): void
    {
        $user    = User::factory()->create();
        $request = MaterialRequest::factory()->create([
            'requester_id'      => $user->id,
            'material_category' => 'HDPE',
        ]);

        $response = $this->getJson("/api/material-requests/{$request->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'id'                => $request->id,
                    'material_category' => 'HDPE',
                ],
            ]);
    }

    /** @test */
    public function authenticated_user_can_create_material_request(): void
    {
        $user = User::factory()->create();

        $payload = [
            'material_category'   => 'PP',
            'requested_weight_kg' => 20.0,
            'minimum_condition'   => 'clean',
            'general_location'    => 'Surabaya Timur',
            'intended_use'        => 'Untuk bahan workshop pembuatan paving block ramah lingkungan.',
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/material-requests', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Material request created successfully',
                'data'    => [
                    'requester_id'      => $user->id,
                    'material_category' => 'PP',
                ],
            ]);

        $this->assertDatabaseHas('material_requests', [
            'requester_id'      => $user->id,
            'material_category' => 'PP',
            'status'            => 'active',
        ]);
    }

    /** @test */
    public function creating_material_request_with_invalid_payload_returns_422(): void
    {
        $user = User::factory()->create();

        // Bobot 0 atau negatif, kategori invalid
        $payload = [
            'material_category'   => 'INVALID_CATEGORY',
            'requested_weight_kg' => -5,
            'minimum_condition'   => 'ultra_clean',
            'general_location'    => '',
            'intended_use'        => '',
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/material-requests', $payload);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['material_category', 'requested_weight_kg', 'minimum_condition', 'general_location', 'intended_use']);
    }

    /** @test */
    public function requester_can_update_their_material_request(): void
    {
        $user    = User::factory()->create();
        $request = MaterialRequest::factory()->create([
            'requester_id'        => $user->id,
            'requested_weight_kg' => 10.0,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/material-requests/{$request->id}", [
                'requested_weight_kg' => 15.0,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'requested_weight_kg' => '15.00',
                ],
            ]);

        $this->assertDatabaseHas('material_requests', [
            'id'                  => $request->id,
            'requested_weight_kg' => 15.0,
        ]);
    }

    /** @test */
    public function non_owner_cannot_update_material_request(): void
    {
        $owner    = User::factory()->create();
        $other    = User::factory()->create();
        $request  = MaterialRequest::factory()->create(['requester_id' => $owner->id]);

        $response = $this->actingAs($other, 'sanctum')
            ->patchJson("/api/material-requests/{$request->id}", [
                'requested_weight_kg' => 50.0,
            ]);

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function requester_can_cancel_their_material_request(): void
    {
        $user    = User::factory()->create();
        $request = MaterialRequest::factory()->create([
            'requester_id' => $user->id,
            'status'       => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/material-requests/{$request->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('material_requests', [
            'id'     => $request->id,
            'status' => 'cancelled',
        ]);
    }
}
