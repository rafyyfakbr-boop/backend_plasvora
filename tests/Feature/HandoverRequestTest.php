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
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'available',
            'weight_kg' => 20,
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id' => $listing->id,
                'requested_weight_kg' => 10,
                'note' => 'Untuk kerajinan ecobrick.',
            ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $this->assertDatabaseHas('handover_requests', [
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function cannot_request_handover_for_unavailable_listing(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'reserved', // Tidak available
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id' => $listing->id,
                'requested_weight_kg' => 5,
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** @test */
    public function owner_cannot_request_their_own_listing(): void
    {
        $owner = User::factory()->create();
        $listing = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'available',
            'weight_kg' => 10,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id' => $listing->id,
                'requested_weight_kg' => 5,
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** @test */
    public function requested_weight_cannot_exceed_available_weight(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'available',
            'weight_kg' => 5,
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/handover-requests', [
                'listing_id' => $listing->id,
                'requested_weight_kg' => 100, // Melebihi available weight
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    // ─── PATCH /api/handover-requests/{id}/status ───────────────────────────

    /** @test */
    public function owner_can_approve_pending_handover(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'requested_weight_kg' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'approved'],
            ]);

        $this->assertDatabaseHas('handover_requests', [
            'id' => $handover->id,
            'status' => 'approved',
        ]);
    }

    /** @test */
    public function requester_cannot_approve_handover(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id]);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
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
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);

        // Tidak bisa langsung dari pending ke utilization_confirmed
        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'utilization_confirmed',
            ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        // Status tidak berubah
        $this->assertDatabaseHas('handover_requests', [
            'id' => $handover->id,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function status_update_rejects_pending_as_target_at_validation_layer(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'approved',
        ]);

        // 'pending' bukan target status yang valid — harus ditolak oleh validasi (422)
        // sebelum mencapai state machine di controller
        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'pending',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('errors.status.0', fn ($msg) => str_contains($msg, 'Status tidak valid'));

        // Status tidak berubah
        $this->assertDatabaseHas('handover_requests', [
            'id' => $handover->id,
            'status' => 'approved',
        ]);
    }

    /** @test */
    public function requester_can_confirm_utilization_after_handover(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'reserved']);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'handed_over',
            'actual_weight_kg' => 10,
            'approved_at' => now()->subDays(3),
            'handed_over_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'utilization_confirmed',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'utilization_confirmed'],
            ]);
    }

    // ─── GET /api/handover-requests ─────────────────────────────────────────

    /** @test */
    public function user_only_sees_handovers_they_are_involved_in(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $outsider = User::factory()->create(); // Tidak terlibat
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id]);

        HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
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
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $outsider = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id]);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
        ]);

        $response = $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/handover-requests/{$handover->id}");

        $response->assertStatus(403)->assertJson(['success' => false]);
    }

    // ─── SEC-003: CONCURRENCY & RACE CONDITION DEFENSE TESTS ────────────────

    /** @test */
    public function only_one_handover_can_be_approved_for_the_same_material(): void
    {
        $owner = User::factory()->create();
        $requesterA = User::factory()->create();
        $requesterB = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'available',
            'weight_kg' => 20,
        ]);

        $handoverA = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requesterA->id,
            'requested_weight_kg' => 10,
            'status' => 'pending',
        ]);

        $handoverB = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requesterB->id,
            'requested_weight_kg' => 10,
            'status' => 'pending',
        ]);

        // 1. Pemilik menyetujui Handover A
        $responseA = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handoverA->id}/status", [
                'status' => 'approved',
            ]);

        $responseA->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'approved'],
            ]);

        // 2. Pastikan Handover A berstatus approved dan listing menjadi reserved
        $this->assertEquals('approved', $handoverA->fresh()->status);
        $this->assertEquals('reserved', $listing->fresh()->status);

        // 3. Pemilik mencoba menyetujui Handover B untuk material yang sama
        $responseB = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handoverB->id}/status", [
                'status' => 'approved',
            ]);

        // 4. Pastikan Handover B ditolak dengan HTTP 409 Conflict
        $responseB->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Material listing is no longer available for handover',
            ]);

        // 5. Pastikan Handover B TETAP pending dan listing TETAP reserved
        $this->assertEquals('pending', $handoverB->fresh()->status);
        $this->assertEquals('reserved', $listing->fresh()->status);

        // 6. Verifikasi hanya 1 handover yang berstatus approved untuk material tersebut
        $this->assertEquals(1, HandoverRequest::where('listing_id', $listing->id)->where('status', 'approved')->count());
    }

    /** @test */
    public function repeated_approval_on_the_same_handover_is_rejected(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'available',
        ]);

        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);

        // Persetujuan pertama sukses
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'approved',
            ])
            ->assertStatus(200);

        // Persetujuan kedua pada request yang sudah approved harus ditolak oleh state machine (422)
        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertEquals('approved', $handover->fresh()->status);
        $this->assertEquals('reserved', $listing->fresh()->status);
    }

    /** @test */
    public function approval_is_rejected_when_material_is_not_available(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();

        // Listing sengaja dibuat dengan status 'reserved' (sudah tidak available)
        $listing = MaterialListing::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'reserved',
        ]);

        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Material listing is no longer available for handover',
            ]);

        // Status handover tidak berubah menjadi approved
        $this->assertEquals('pending', $handover->fresh()->status);
    }

    /** @test */
    public function authorization_restricts_actions_based_on_actor_role(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $outsider = User::factory()->create();

        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);

        // 1. Outsider tidak boleh mengubah status apapun (403)
        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", ['status' => 'approved'])
            ->assertStatus(403);

        // 2. Requester tidak boleh menolak (reject) permintaan (hanya owner)
        $this->actingAs($requester, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", ['status' => 'rejected'])
            ->assertStatus(403);

        // 3. Owner tidak boleh konfirmasi pemanfaatan (hanya requester)
        // Set ke handed_over terlebih dahulu
        $handover->update(['status' => 'handed_over']);
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", ['status' => 'utilization_confirmed'])
            ->assertStatus(403);
    }

    /** @test */
    public function full_state_machine_transitions_and_material_synchronization(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();

        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);

        // 1. pending -> approved: material menjadi reserved
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", ['status' => 'approved'])
            ->assertStatus(200);
        $this->assertEquals('reserved', $listing->fresh()->status);

        // 2. approved -> handed_over: material tetap reserved
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", ['status' => 'handed_over'])
            ->assertStatus(200);
        $this->assertEquals('reserved', $listing->fresh()->status);

        // 3. handed_over -> utilization_confirmed: material menjadi completed
        $this->actingAs($requester, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover->id}/status", ['status' => 'utilization_confirmed'])
            ->assertStatus(200);
        $this->assertEquals('completed', $listing->fresh()->status);

        // 4. Skenario approved -> cancelled: material kembali available
        $listing2 = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover2 = HandoverRequest::factory()->create([
            'listing_id' => $listing2->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover2->id}/status", ['status' => 'approved'])
            ->assertStatus(200);
        $this->assertEquals('reserved', $listing2->fresh()->status);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/handover-requests/{$handover2->id}/status", ['status' => 'cancelled'])
            ->assertStatus(200);
        $this->assertEquals('available', $listing2->fresh()->status);
    }

    /** @test */
    public function transaction_rolls_back_atomically_if_failure_occurs_during_update(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $listing = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'available']);
        $handover = HandoverRequest::factory()->create([
            'listing_id' => $listing->id,
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);

        // Pasang event listener sementara yang memicu exception saat saving HandoverRequest
        HandoverRequest::saving(function () {
            static $thrown = false;
            if (! $thrown) {
                $thrown = true;
                throw new \Exception('Simulated database write failure');
            }
        });

        try {
            $this->actingAs($owner, 'sanctum')
                ->patchJson("/api/handover-requests/{$handover->id}/status", [
                    'status' => 'approved',
                ]);
        } catch (\Exception $e) {
            // Exception diharapkan terjadi untuk menguji rollback
        }

        // Pastikan perubahan pada listing (reserved) ikut di-rollback dan tetap 'available'
        $this->assertEquals('available', $listing->fresh()->status);
        $this->assertEquals('pending', $handover->fresh()->status);
    }
}
