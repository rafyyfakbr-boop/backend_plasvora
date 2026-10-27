<?php

namespace Tests\Feature;

use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test: Handover Request Workflow
 *
 * Menguji alur lengkap handover request:
 * - Pembuatan handover
 * - Transisi status yang valid
 * - Penolakan transisi status yang ilegal
 * - Otorisasi per peran (owner vs requester)
 * - Privasi (hanya pihak yang terlibat yang bisa lihat)
 */
class HandoverRequestTest extends TestCase
{
    use RefreshDatabase;

    // ─── POST /api/handover-requests ────────────────────────────────────────

    /** @test */
    public function requester_can_submit_handover_request_for_available_listing(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $listing   = MaterialListing::factory()->create([
            'owner_id'  => $owner->id,
            'status'    => 'available',
            'weight_kg' => 20,
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id'          => $listing->id,
                'requested_weight_kg' => 10,
                'note'                => 'Untuk kerajinan ecobrick.',
            ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $this->assertDatabaseHas('handover_requests', [
            'listing_id'   => $listing->id,
            'owner_id'     => $owner->id,
            'requester_id' => $requester->id,
            'status'       => 'pending',
        ]);
    }

    /** @test */
    public function cannot_request_handover_for_unavailable_listing(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $listing   = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'reserved', // Tidak available
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id'          => $listing->id,
                'requested_weight_kg' => 5,
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** @test */
    public function owner_cannot_request_their_own_listing(): void
    {
        $owner   = User::factory()->create();
        $listing = MaterialListing::factory()->create([
            'owner_id'  => $owner->id,
            'status'    => 'available',
            'weight_kg' => 10,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id'          => $listing->id,
                'requested_weight_kg' => 5,
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** @test */
    public function requested_weight_cannot_exceed_available_weight(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $listing   = MaterialListing::factory()->create([
            'owner_id'  => $owner->id,
            'status'    => 'available',
            'weight_kg' => 5,
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id'          => $listing->id,
                'requested_weight_kg' => 100, // Melebihi available weight
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    // ─── PATCH /api/handover-requests/{id}/status ───────────────────────────

    /** @test */
    public function owner_can_approve_pending_handover(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $listing   = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover  = HandoverRequest::factory()->create([
            'listing_id'          => $listing->id,
            'owner_id'            => $owner->id,
            'requester_id'        => $requester->id,
            'requested_weight_kg' => 10,
            'status'              => 'pending',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => ['status' => 'approved'],
            ]);

        $this->assertDatabaseHas('handover_requests', [
            'id'     => $handover->id,
            'status' => 'approved',
        ]);
    }

    /** @test */
    public function requester_cannot_approve_handover(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $listing   = MaterialListing::factory()->create(['owner_id' => $owner->id]);
        $handover  = HandoverRequest::factory()->create([
            'listing_id'   => $listing->id,
            'owner_id'     => $owner->id,
            'requester_id' => $requester->id,
            'status'       => 'pending',
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertStatus(403)->assertJson(['success' => false]);
    }

    /** @test */
    public function illegal_status_transition_is_rejected(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $listing   = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover  = HandoverRequest::factory()->create([
            'listing_id'   => $listing->id,
            'owner_id'     => $owner->id,
            'requester_id' => $requester->id,
            'status'       => 'pending',
        ]);

        // Tidak bisa langsung dari pending ke utilization_confirmed
        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'utilization_confirmed',
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        // Status tidak berubah
        $this->assertDatabaseHas('handover_requests', [
            'id'     => $handover->id,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function requester_can_confirm_utilization_after_handover(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $listing   = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'reserved']);
        $handover  = HandoverRequest::factory()->create([
            'listing_id'      => $listing->id,
            'owner_id'        => $owner->id,
            'requester_id'    => $requester->id,
            'status'          => 'handed_over',
            'actual_weight_kg' => 10,
            'approved_at'     => now()->subDays(3),
            'handed_over_at'  => now()->subDay(),
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'utilization_confirmed',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => ['status' => 'utilization_confirmed'],
            ]);
    }

    // ─── GET /api/handover-requests ─────────────────────────────────────────

    /** @test */
    public function user_only_sees_handovers_they_are_involved_in(): void
    {
        $owner        = User::factory()->create();
        $requester    = User::factory()->create();
        $outsider     = User::factory()->create(); // Tidak terlibat
        $listing      = MaterialListing::factory()->create(['owner_id' => $owner->id]);

        HandoverRequest::factory()->create([
            'listing_id'   => $listing->id,
            'owner_id'     => $owner->id,
            'requester_id' => $requester->id,
            'status'       => 'pending',
        ]);

        // Outsider tidak boleh melihat handover ini
        $response = $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/handover-requests');

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(0, $response->json('data'));

        // Owner boleh melihat
        $ownerResponse = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/handover-requests');
        $this->assertCount(1, $ownerResponse->json('data'));
    }

    /** @test */
    public function outsider_cannot_view_handover_detail(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();
        $outsider  = User::factory()->create();
        $listing   = MaterialListing::factory()->create(['owner_id' => $owner->id]);
        $handover  = HandoverRequest::factory()->create([
            'listing_id'   => $listing->id,
            'owner_id'     => $owner->id,
            'requester_id' => $requester->id,
        ]);

        $response = $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/handover-requests/{$handover->id}");

        $response->assertStatus(403)->assertJson(['success' => false]);
    }
}
