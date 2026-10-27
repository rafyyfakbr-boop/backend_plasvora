<?php

namespace Tests\Feature;

use App\Models\MaterialListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test: Material Listings API
 *
 * Menguji endpoint CRUD untuk material listings:
 * GET /api/materials
 * POST /api/materials
 * GET /api/materials/{id}
 * PATCH /api/materials/{id}
 * DELETE /api/materials/{id}
 */
class MaterialListingTest extends TestCase
{
    use RefreshDatabase;

    // ─── GET /api/materials ─────────────────────────────────────────────────

    /** @test */
    public function it_can_retrieve_list_of_available_materials(): void
    {
        MaterialListing::factory()->count(5)->create(['status' => 'available']);
        MaterialListing::factory()->count(2)->create(['status' => 'inactive']); // Tidak muncul

        $response = $this->getJson('/api/materials');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        // Hanya 5 listing available yang muncul
        $this->assertCount(5, $response->json('data'));
    }

    /** @test */
    public function it_can_filter_materials_by_category(): void
    {
        MaterialListing::factory()->count(3)->create(['category' => 'PET', 'status' => 'available']);
        MaterialListing::factory()->count(2)->create(['category' => 'HDPE', 'status' => 'available']);

        $response = $this->getJson('/api/materials?category=PET');

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data'));
        collect($response->json('data'))->each(fn ($item) => $this->assertEquals('PET', $item['category']));
    }

    /** @test */
    public function it_can_search_materials_by_keyword(): void
    {
        $owner = User::factory()->create();
        MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'name'     => 'Botol PET Bersih',
            'status'   => 'available',
        ]);
        MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'name'     => 'Kantong HDPE Bekas',
            'status'   => 'available',
        ]);

        $response = $this->getJson('/api/materials?q=Botol');

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString('Botol', $response->json('data.0.name'));
    }

    // ─── GET /api/materials/{id} ────────────────────────────────────────────

    /** @test */
    public function it_can_retrieve_material_detail(): void
    {
        $listing = MaterialListing::factory()->create(['status' => 'available']);

        $response = $this->getJson("/api/materials/{$listing->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => ['id' => $listing->id],
            ]);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_material(): void
    {
        $response = $this->getJson('/api/materials/99999');

        $response->assertStatus(404)->assertJson(['success' => false]);
    }

    // ─── POST /api/materials ────────────────────────────────────────────────

    /** @test */
    public function authenticated_user_can_create_material_listing(): void
    {
        $user = User::factory()->create();

        $payload = [
            'name'             => 'Botol PET Bersih 2L',
            'category'         => 'PET',
            'weight_kg'        => 10.5,
            'condition'        => 'clean',
            'price'            => 0,
            'is_free'          => true,
            'general_location' => 'Bandung Utara',
            'description'      => 'Botol PET bekas minuman soda, sudah dicuci.',
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/materials', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'name'     => 'Botol PET Bersih 2L',
                    'owner_id' => $user->id,
                ],
            ]);

        // Pastikan owner_id diset dari user terautentikasi
        $this->assertDatabaseHas('material_listings', [
            'name'     => 'Botol PET Bersih 2L',
            'owner_id' => $user->id,
        ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_create_material_listing(): void
    {
        $response = $this->postJson('/api/materials', [
            'name'     => 'Test',
            'category' => 'PET',
        ]);

        $response->assertStatus(401)->assertJson(['success' => false]);
    }

    /** @test */
    public function creating_material_with_invalid_payload_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/materials', [
                'name'      => '',        // Kosong - wajib
                'category'  => 'INVALID', // Tidak valid
                'weight_kg' => -5,        // Negatif - tidak valid
                'is_free'   => true,
                'price'     => 1000,      // Inkonsisten dengan is_free=true
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonStructure(['errors']);
    }

    /** @test */
    public function price_and_is_free_must_be_consistent(): void
    {
        $user = User::factory()->create();

        // is_free=true tapi price > 0 — harus gagal
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/materials', [
                'name'             => 'Test Listing',
                'category'         => 'PET',
                'weight_kg'        => 5.0,
                'condition'        => 'clean',
                'is_free'          => true,
                'price'            => 5000, // Inkonsisten
                'general_location' => 'Jakarta',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.price.0', 'Harga harus 0 jika material ditandai gratis.');
    }

    // ─── PATCH /api/materials/{id} ──────────────────────────────────────────

    /** @test */
    public function owner_can_update_their_listing(): void
    {
        $owner   = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/materials/{$listing->id}", [
                'general_location' => 'Surabaya Barat',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => ['general_location' => 'Surabaya Barat'],
            ]);
    }

    /** @test */
    public function non_owner_cannot_update_listing(): void
    {
        $owner    = User::factory()->create();
        $attacker = User::factory()->create();
        $listing  = MaterialListing::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/materials/{$listing->id}", [
                'general_location' => 'Hacked Location',
            ]);

        $response->assertStatus(403)->assertJson(['success' => false]);
    }

    // ─── DELETE /api/materials/{id} ─────────────────────────────────────────

    /** @test */
    public function owner_can_deactivate_their_listing(): void
    {
        $owner   = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/materials/{$listing->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);

        // Status harus berubah ke inactive, bukan dihapus
        $this->assertDatabaseHas('material_listings', [
            'id'     => $listing->id,
            'status' => 'inactive',
        ]);
    }

    /** @test */
    public function non_owner_cannot_deactivate_listing(): void
    {
        $owner    = User::factory()->create();
        $attacker = User::factory()->create();
        $listing  = MaterialListing::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/materials/{$listing->id}");

        $response->assertStatus(403)->assertJson(['success' => false]);
    }
}
