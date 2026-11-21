<?php

namespace Tests\Feature;

use App\Models\MaterialListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    // ─── Stage 1: Seller Phone, Origin Metadata & Image Upload ──────────────

    /** @test */
    public function index_hides_owner_phone_while_show_exposes_owner_phone_only_when_whatsapp_public_is_true(): void
    {
        $privateSeller = User::factory()->create([
            'phone'           => '6281234567890',
            'whatsapp_public' => false,
        ]);
        $publicSeller = User::factory()->create([
            'phone'           => '6281398765432',
            'whatsapp_public' => true,
        ]);

        $privateListing = MaterialListing::factory()->create([
            'owner_id' => $privateSeller->id,
            'status'   => 'available',
        ]);
        $publicListing = MaterialListing::factory()->create([
            'owner_id' => $publicSeller->id,
            'status'   => 'available',
        ]);

        // Index tidak pernah mengekspos nomor telepon penjual (bahkan jika whatsapp_public=true)
        $indexResponse = $this->getJson('/api/materials');
        $indexResponse->assertStatus(200);
        foreach ($indexResponse->json('data') as $item) {
            $this->assertArrayNotHasKey('phone', $item['owner']);
            $this->assertArrayNotHasKey('whatsapp_public', $item['owner']);
        }

        // Detail menyembunyikan nomor telepon ketika whatsapp_public=false, nama tetap ada
        $privateDetail = $this->getJson("/api/materials/{$privateListing->id}");
        $privateDetail->assertStatus(200)
            ->assertJsonPath('data.owner.name', $privateSeller->name)
            ->assertJsonPath('data.owner.phone', null);
        $this->assertArrayNotHasKey('whatsapp_public', $privateDetail->json('data.owner'));

        // Detail menampilkan nomor telepon ketika whatsapp_public=true
        $publicDetail = $this->getJson("/api/materials/{$publicListing->id}");
        $publicDetail->assertStatus(200)
            ->assertJsonPath('data.owner.name', $publicSeller->name)
            ->assertJsonPath('data.owner.phone', '6281398765432');
        $this->assertArrayNotHasKey('whatsapp_public', $publicDetail->json('data.owner'));
    }

    /** @test */
    public function authenticated_user_can_create_listing_with_image_upload_and_origin_fields(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('botol_pet.jpg', 800, 600);

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/materials', [
                'name'                  => 'Lot Botol PET Bersih',
                'category'              => 'PET',
                'weight_kg'             => 12.5,
                'condition'             => 'clean',
                'is_free'               => '0',
                'price'                 => 37500,
                'general_location'      => 'Bandung',
                'origin_destination_id' => 17485,
                'origin_label'          => 'Braga, Sumur Bandung, Bandung',
                'description'           => 'Lot bersih siap angkut.',
                'image'                 => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.origin_destination_id', 17485)
            ->assertJsonPath('data.origin_label', 'Braga, Sumur Bandung, Bandung');

        $listing = MaterialListing::find($response->json('data.id'));
        $rawPath = $listing->getRawOriginal('image_url');
        $this->assertStringStartsWith('materials/', $rawPath);
        Storage::disk('public')->assertExists($rawPath);
        $this->assertStringContainsString('/storage/materials/', $response->json('data.image_url'));
    }

    /** @test */
    public function replacing_uploaded_listing_image_deletes_old_managed_file_only(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('materials', 'public');

        $listing = MaterialListing::factory()->create([
            'owner_id'  => $owner->id,
            'image_url' => $oldPath,
        ]);

        $newFile = UploadedFile::fake()->image('new.png');
        $response = $this->actingAs($owner, 'sanctum')
            ->post("/api/materials/{$listing->id}", [
                '_method' => 'PATCH',
                'image'   => $newFile,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(200)->assertJsonPath('success', true);
        Storage::disk('public')->assertMissing($oldPath);

        $listing->refresh();
        $newRawPath = $listing->getRawOriginal('image_url');
        $this->assertNotEquals($oldPath, $newRawPath);
        Storage::disk('public')->assertExists($newRawPath);
    }

    /** @test */
    public function updating_listing_preserves_compatibility_with_external_image_url(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $listing = MaterialListing::factory()->create([
            'owner_id'  => $owner->id,
            'image_url' => 'https://example.com/legacy-pet.jpg',
        ]);

        $detail = $this->getJson("/api/materials/{$listing->id}");
        $detail->assertStatus(200)
            ->assertJsonPath('data.image_url', 'https://example.com/legacy-pet.jpg');

        $newFile = UploadedFile::fake()->image('replacement.jpg');
        $update = $this->actingAs($owner, 'sanctum')
            ->post("/api/materials/{$listing->id}", [
                '_method' => 'PATCH',
                'image'   => $newFile,
            ], ['Accept' => 'application/json']);

        $update->assertStatus(200)->assertJsonPath('success', true);
    }
}
