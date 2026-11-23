<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAddressTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'label'             => 'Rumah',
            'recipient_name'    => 'Budi Santoso',
            'recipient_phone'   => '0812-3456-7890',
            'destination_id'    => 6157,
            'destination_label' => 'BABAKANKARET, CIANJUR, KAB. CIANJUR, JAWA BARAT, 43211',
            'address_detail'    => 'Jl. Raya Cianjur No. 12 RT 02/RW 04',
            'postal_code'       => '43211',
            'delivery_note'     => 'Dekat masjid',
            'is_default'        => false,
        ], $overrides);
    }

    /** @test */
    public function guest_cannot_access_address_endpoints(): void
    {
        $this->getJson('/api/addresses')->assertStatus(401);
        $this->postJson('/api/addresses', $this->validPayload())->assertStatus(401);
        $this->patchJson('/api/addresses/1', ['label' => 'Kantor'])->assertStatus(401);
        $this->deleteJson('/api/addresses/1')->assertStatus(401);
    }

    /** @test */
    public function authenticated_user_can_list_only_their_own_addresses_ordered_default_first(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        $nonDefault = UserAddress::create([
            'user_id'           => $user->id,
            'label'             => 'Gudang',
            'recipient_name'    => 'Budi',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => 4949,
            'destination_label' => 'BRAGA, SUMUR BANDUNG, KOTA BANDUNG',
            'address_detail'    => 'Jl. Braga 10',
            'is_default'        => false,
        ]);

        $defaultAddress = UserAddress::create([
            'user_id'           => $user->id,
            'label'             => 'Rumah',
            'recipient_name'    => 'Budi',
            'recipient_phone'   => '6281234567890',
            'destination_id'    => 6157,
            'destination_label' => 'BABAKANKARET, CIANJUR',
            'address_detail'    => 'Jl. Cianjur 12',
            'is_default'        => true,
        ]);

        UserAddress::create([
            'user_id'           => $other->id,
            'label'             => 'Alamat Orang Lain',
            'recipient_name'    => 'Andi',
            'recipient_phone'   => '6281399998888',
            'destination_id'    => 4949,
            'destination_label' => 'BRAGA, BANDUNG',
            'address_detail'    => 'Jl. Rahasia 99',
            'is_default'        => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/addresses');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $defaultAddress->id)
            ->assertJsonPath('data.1.id', $nonDefault->id);
    }

    /** @test */
    public function authenticated_user_can_create_address_with_normalized_phone_and_first_becomes_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload([
                'recipient_phone' => '+62 812-3456-7890',
                'is_default'      => false, // Alamat pertama tetap harus otomatis menjadi default
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recipient_phone', '6281234567890')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('user_addresses', [
            'user_id'         => $user->id,
            'recipient_phone' => '6281234567890',
            'is_default'      => true,
        ]);
    }

    /** @test */
    public function creating_address_rejects_invalid_phone_whitespace_detail_and_invalid_destination(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload([
                'recipient_phone' => '12345',
                'address_detail'  => '    ',
                'destination_id'  => 0,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'recipient_phone',
                'address_detail',
                'destination_id',
            ]);
    }

    /** @test */
    public function creating_default_address_unsets_previous_default_while_non_default_preserves_it(): void
    {
        $user = User::factory()->create();

        $firstId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload(['label' => 'Pertama']))
            ->assertStatus(201)
            ->json('data.id');

        // Non-default mempertahankan default sebelumnya
        $secondId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload([
                'label'      => 'Kedua',
                'is_default' => false,
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.is_default', false)
            ->json('data.id');

        $this->assertTrue(UserAddress::find($firstId)->is_default);
        $this->assertFalse(UserAddress::find($secondId)->is_default);

        // Default baru menonaktifkan default lama
        $thirdId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload([
                'label'      => 'Ketiga',
                'is_default' => true,
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        $this->assertFalse(UserAddress::find($firstId)->is_default);
        $this->assertFalse(UserAddress::find($secondId)->is_default);
        $this->assertTrue(UserAddress::find($thirdId)->is_default);
        $this->assertSame(1, UserAddress::where('user_id', $user->id)->where('is_default', true)->count());
    }

    /** @test */
    public function user_can_update_own_address_and_cannot_update_another_users_address(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $addressId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload())
            ->assertStatus(201)
            ->json('data.id');

        // User lain mendapat 404 (tidak membocorkan eksistensi)
        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/addresses/{$addressId}", ['label' => 'Dibajak'])
            ->assertStatus(404);

        // Pemilik dapat update sebagian tanpa mengubah is_default / field lain
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/addresses/{$addressId}", [
                'user_id'         => $other->id, // Harus diabaikan
                'label'           => 'Workshop',
                'recipient_phone' => '081398765432',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.label', 'Workshop')
            ->assertJsonPath('data.recipient_phone', '6281398765432')
            ->assertJsonPath('data.is_default', true);
    }

    /** @test */
    public function unsetting_default_promotes_another_address_and_only_address_stays_default(): void
    {
        $user = User::factory()->create();

        $onlyId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload(['label' => 'Satu-satunya']))
            ->assertStatus(201)
            ->json('data.id');

        // Satu-satunya alamat tidak boleh menjadi non-default
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/addresses/{$onlyId}", ['is_default' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_default', true);

        // Tambah alamat kedua lalu nonaktifkan default pertama -> alamat kedua dipromosikan
        $secondId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload(['label' => 'Kedua', 'is_default' => false]))
            ->assertStatus(201)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/addresses/{$onlyId}", ['is_default' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_default', false);

        $this->assertTrue(UserAddress::find($secondId)->is_default);
        $this->assertSame(1, UserAddress::where('user_id', $user->id)->where('is_default', true)->count());
    }

    /** @test */
    public function user_can_delete_own_address_cannot_delete_others_and_deleting_default_promotes_another(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $firstId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload(['label' => 'Default Lama']))
            ->assertStatus(201)
            ->json('data.id');

        $secondId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/addresses', $this->validPayload(['label' => 'Cadangan', 'is_default' => false]))
            ->assertStatus(201)
            ->json('data.id');

        // User lain tidak boleh menghapus (404)
        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/addresses/{$firstId}")
            ->assertStatus(404);

        $this->assertDatabaseHas('user_addresses', ['id' => $firstId]);

        // Menghapus alamat default mempromosikan alamat cadangan
        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/addresses/{$firstId}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('user_addresses', ['id' => $firstId]);
        $this->assertTrue(UserAddress::find($secondId)->is_default);
        $this->assertSame(1, UserAddress::where('user_id', $owner->id)->where('is_default', true)->count());
    }
}
