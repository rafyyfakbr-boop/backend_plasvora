<?php

namespace Tests\Feature;

use App\Models\MaterialListing;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SECRET_KEY = 'fake-rajaongkir-secret-do-not-expose';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.rajaongkir.api_key', self::FAKE_SECRET_KEY);
        config()->set('services.rajaongkir.base_url', 'https://rajaongkir.komerce.id/api/v1/');
        config()->set('services.rajaongkir.couriers', 'jne:jnt');
        config()->set('services.rajaongkir.timeout', 15);
        config()->set('services.rajaongkir.connect_timeout', 5);
        config()->set('services.rajaongkir.search_cache_ttl', 0);
    }

    private function createAddress(User $user, int $destinationId = 6157): UserAddress
    {
        return UserAddress::create([
            'user_id'           => $user->id,
            'label'             => 'Rumah Pembeli',
            'recipient_name'    => 'Pembeli Uji',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => $destinationId,
            'destination_label' => 'BABAKANKARET, CIANJUR, KAB. CIANJUR, JAWA BARAT, 43211',
            'address_detail'    => 'Jl. Rahasia Pembeli No. 88',
            'postal_code'       => '43211',
            'is_default'        => true,
        ]);
    }

    // ─── Destination Search ────────────────────────────────────────────────

    /** @test */
    public function guest_cannot_search_destinations(): void
    {
        $this->getJson('/api/shipping/destinations?search=braga')->assertStatus(401);
    }

    /** @test */
    public function destination_search_validates_minimum_query_length(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/shipping/destinations?search=a')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);
    }

    /** @test */
    public function authenticated_user_can_search_destinations_via_rajaongkir_service(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/domestic-destination*' => Http::response([
                'meta' => ['message' => 'Success Get Domestic Destination', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'id'               => 4949,
                        'label'            => 'BRAGA, SUMUR BANDUNG, BANDUNG, JAWA BARAT, 40111',
                        'province_name'    => 'JAWA BARAT',
                        'city_name'        => 'BANDUNG',
                        'district_name'    => 'SUMUR BANDUNG',
                        'subdistrict_name' => 'BRAGA',
                        'zip_code'         => '40111',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/shipping/destinations?search=braga&limit=5&offset=0');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.destination_id', 4949)
            ->assertJsonPath('data.0.postal_code', '40111')
            ->assertJsonPath('data.0.district_name', 'SUMUR BANDUNG');

        Http::assertSent(function (HttpClientRequest $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/destination/domestic-destination')
                && $request->hasHeader('key', self::FAKE_SECRET_KEY)
                && $request['search'] === 'braga';
        });
    }

    // ─── Shipping Estimate ─────────────────────────────────────────────────

    /** @test */
    public function shipping_estimate_requires_authentication(): void
    {
        $this->postJson('/api/shipping/estimate', [
            'listing_id' => 1,
            'address_id' => 1,
        ])->assertStatus(401);
    }

    /** @test */
    public function shipping_estimate_rejects_missing_listing_missing_address_and_other_users_address(): void
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();
        $other  = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'status'                => 'available',
            'weight_kg'             => 2.0,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, SUMUR BANDUNG, BANDUNG',
        ]);

        $otherAddress = $this->createAddress($other);

        // Listing tidak ditemukan -> 422 validation exists
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => 999999,
                'address_id' => $otherAddress->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['listing_id']);

        // Alamat tidak ditemukan -> 404
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => $listing->id,
                'address_id' => 999999,
            ])
            ->assertStatus(404);

        // Alamat milik user lain -> 404 (IDOR protection)
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => $listing->id,
                'address_id' => $otherAddress->id,
            ])
            ->assertStatus(404);
    }

    /** @test */
    public function shipping_estimate_rejects_owners_own_listing_and_listing_without_origin(): void
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();

        $sellerAddress = $this->createAddress($seller);
        $buyerAddress  = $this->createAddress($buyer);

        $listingWithOrigin = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'status'                => 'available',
            'weight_kg'             => 2.0,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, BANDUNG',
        ]);

        $listingWithoutOrigin = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'status'                => 'available',
            'weight_kg'             => 2.0,
            'origin_destination_id' => null,
            'origin_label'          => null,
        ]);

        // Pemilik listing sendiri ditolak
        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => $listingWithOrigin->id,
                'address_id' => $sellerAddress->id,
            ])
            ->assertStatus(422);

        // Listing tanpa origin ditolak
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => $listingWithoutOrigin->id,
                'address_id' => $buyerAddress->id,
            ])
            ->assertStatus(422);
    }

    /** @test */
    public function shipping_estimate_uses_authoritative_db_values_and_server_courier_ignoring_client_overrides(): void
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();

        $listing = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'status'                => 'available',
            'weight_kg'             => 2.5, // 2500 gram otoritatif
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, SUMUR BANDUNG, BANDUNG',
        ]);

        $address = $this->createAddress($buyer, 6157);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Success Calculate Domestic Shipping cost', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'name'        => 'Jalur Nugraha Ekakurir (JNE)',
                        'code'        => 'JNE',
                        'service'     => 'REG',
                        'description' => 'Layanan Reguler',
                        'cost'        => 18000,
                        'etd'         => '2-3 day',
                    ],
                    [
                        'name'        => 'J&T Express',
                        'code'        => 'jnt',
                        'service'     => 'EZ',
                        'description' => 'Reguler',
                        'cost'        => 16000,
                        'etd'         => '-', // Harus dinormalisasi menjadi null
                    ],
                ],
            ], 200),
        ]);

        // Klien mencoba memanipulasi origin, destination, weight, courier, dan price
        $response = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id'  => $listing->id,
                'address_id'  => $address->id,
                'origin'      => 1111,
                'destination' => 2222,
                'weight'      => 100,
                'courier'     => 'pos:tiki',
                'price'       => 'highest',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.listing.weight_grams', 2500)
            ->assertJsonPath('data.listing.origin_destination_id', 4949)
            ->assertJsonPath('data.destination.destination_id', 6157)
            ->assertJsonCount(2, 'data.options')
            ->assertJsonPath('data.options.0.courier_code', 'jne')
            ->assertJsonPath('data.options.0.cost', 18000)
            ->assertJsonPath('data.options.0.etd', '2-3 day')
            ->assertJsonPath('data.options.1.courier_code', 'jnt')
            ->assertJsonPath('data.options.1.etd', null);

        // Pastikan data sensitif penerima tidak terekspos pada respons estimasi
        $this->assertArrayNotHasKey('recipient_phone', $response->json('data.destination'));
        $this->assertArrayNotHasKey('address_detail', $response->json('data.destination'));

        // Pastikan request keluar ke RajaOngkir memakai nilai DB & konfigurasi server
        Http::assertSent(function (HttpClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost'
                && $request->hasHeader('key', self::FAKE_SECRET_KEY)
                && $request['origin'] === '4949'
                && $request['destination'] === '6157'
                && $request['weight'] === '2500'
                && $request['courier'] === 'jne:jnt'
                && $request['price'] === 'lowest';
        });
    }

    /** @test */
    public function shipping_estimate_returns_empty_options_array_when_no_service_is_available(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $address = $this->createAddress($buyer);

        $listing = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'status'                => 'available',
            'weight_kg'             => 50.0,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, BANDUNG',
        ]);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Success', 'code' => 200, 'status' => 'success'],
                'data' => [],
            ], 200),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => $listing->id,
                'address_id' => $address->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.options', []);
    }

    /** @test */
    public function shipping_estimate_maps_upstream_timeout_and_failure_without_exposing_credentials(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->create();
        $address = $this->createAddress($buyer);

        $listing = MaterialListing::factory()->create([
            'owner_id'              => $seller->id,
            'status'                => 'available',
            'weight_kg'             => 2.0,
            'origin_destination_id' => 4949,
            'origin_label'          => 'BRAGA, BANDUNG',
        ]);

        // 1. Timeout -> 504
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $timeoutResponse = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => $listing->id,
                'address_id' => $address->id,
            ]);

        $timeoutResponse->assertStatus(504)->assertJsonPath('success', false);
        $this->assertStringNotContainsString(self::FAKE_SECRET_KEY, $timeoutResponse->getContent());

        // 2. Upstream 401/500 tidak membocorkan API key
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Invalid API key ' . self::FAKE_SECRET_KEY, 'code' => 401],
            ], 401),
        ]);

        $authFailResponse = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipping/estimate', [
                'listing_id' => $listing->id,
                'address_id' => $address->id,
            ]);

        $authFailResponse->assertStatus(503)->assertJsonPath('success', false);
        $this->assertStringNotContainsString(self::FAKE_SECRET_KEY, $authFailResponse->getContent());
    }
}
