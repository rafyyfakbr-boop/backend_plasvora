<?php

namespace Tests\Feature;

use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test: Impact Summary API
 *
 * Memastikan impact summary menghitung angka berdasarkan
 * status aktual database, bukan hardcoded.
 */
class ImpactSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function impact_summary_calculates_from_actual_database_status(): void
    {
        $owner     = User::factory()->create();
        $requester = User::factory()->create();

        // Buat beberapa listing dengan status berbeda
        MaterialListing::factory()->count(3)->create(['owner_id' => $owner->id, 'status' => 'available', 'weight_kg' => 10]);
        MaterialListing::factory()->count(1)->create(['owner_id' => $owner->id, 'status' => 'reserved', 'weight_kg' => 15]);
        MaterialListing::factory()->count(1)->create(['owner_id' => $owner->id, 'status' => 'completed', 'weight_kg' => 20]);
        MaterialListing::factory()->count(1)->create(['owner_id' => $owner->id, 'status' => 'inactive', 'weight_kg' => 5]); // Tidak dihitung

        // Buat handover dengan status berbeda
        $listing1 = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'completed']);
        HandoverRequest::factory()->create([
            'listing_id'              => $listing1->id,
            'owner_id'                => $owner->id,
            'requester_id'            => $requester->id,
            'status'                  => 'utilization_confirmed',
            'actual_weight_kg'        => 25,
            'approved_at'             => now()->subDays(10),
            'handed_over_at'          => now()->subDays(5),
            'utilization_confirmed_at' => now()->subDays(2),
        ]);

        $listing2 = MaterialListing::factory()->create(['owner_id' => $owner->id, 'status' => 'reserved']);
        HandoverRequest::factory()->create([
            'listing_id'   => $listing2->id,
            'owner_id'     => $owner->id,
            'requester_id' => $requester->id,
            'status'       => 'handed_over',
            'actual_weight_kg' => 8,
            'approved_at'  => now()->subDays(3),
            'handed_over_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/impact-summary');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'materials_listed'      => ['count', 'weight_kg', 'definition'],
                    'materials_available'   => ['count', 'weight_kg', 'definition'],
                    'materials_handed_over' => ['count', 'weight_kg', 'definition'],
                    'materials_utilized'    => ['count', 'weight_kg', 'definition'],
                    'active_handovers'      => ['pending', 'approved'],
                    'data_source',
                ],
            ]);

        $data = $response->json('data');

        // Verifikasi bahwa angka dihitung dari database, bukan hardcoded
        // utilization_confirmed: 1 handover dengan 25kg
        $this->assertEquals(1, $data['materials_utilized']['count']);
        $this->assertEquals(25.0, $data['materials_utilized']['weight_kg']);

        // handed_over + utilization_confirmed: 2 handover (8 + 25 = 33kg)
        $this->assertEquals(2, $data['materials_handed_over']['count']);
        $this->assertEquals(33.0, $data['materials_handed_over']['weight_kg']);

        // Listing yang terdaftar: tidak termasuk inactive (5+1+2=6 listing)
        $this->assertEquals(7, $data['materials_listed']['count']); // 3+1+1+1(listed2)+1(listed1) = 7 non-inactive

        // Listing available: 3
        $this->assertEquals(3, $data['materials_available']['count']);
    }

    /** @test */
    public function impact_summary_is_zero_when_database_is_empty(): void
    {
        $response = $this->getJson('/api/impact-summary');

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data'    => [
                'materials_listed'      => ['count' => 0, 'weight_kg' => 0],
                'materials_available'   => ['count' => 0, 'weight_kg' => 0],
                'materials_handed_over' => ['count' => 0, 'weight_kg' => 0],
                'materials_utilized'    => ['count' => 0, 'weight_kg' => 0],
            ],
        ]);
    }

    /** @test */
    public function impact_summary_response_follows_json_contract(): void
    {
        $response = $this->getJson('/api/impact-summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertIsString($response->json('message'));
        $this->assertIsArray($response->json('data'));
    }
}
