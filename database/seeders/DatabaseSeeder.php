<?php

namespace Database\Seeders;

use App\Models\AnalysisHistory;
use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Data ini merupakan data DEMO untuk keperluan pengembangan dan presentasi.
     * Jangan gunakan credential ini di lingkungan produksi.
     */
    public function run(): void
    {
        // ─── Akun Demo ────────────────────────────────────────────────────────
        // Akun demo yang dapat digunakan untuk pengujian:
        // admin@plasvora.test / password
        // pemilik@plasvora.test / password
        // peminta@plasvora.test / password

        $admin = User::firstOrCreate(
            ['email' => 'admin@plasvora.test'],
            [
                'name'     => 'Admin Plasvora',
                'password' => Hash::make('password'),
            ]
        );

        $owner = User::firstOrCreate(
            ['email' => 'pemilik@plasvora.test'],
            [
                'name'     => 'Budi Santoso',
                'password' => Hash::make('password'),
            ]
        );

        $requester = User::firstOrCreate(
            ['email' => 'peminta@plasvora.test'],
            [
                'name'     => 'Sari Dewi',
                'password' => Hash::make('password'),
            ]
        );

        // ─── Listing Material Demo ────────────────────────────────────────────
        $listing1 = MaterialListing::firstOrCreate(
            ['owner_id' => $owner->id, 'name' => 'Botol PET Bekas Minuman'],
            [
                'category'         => 'PET',
                'weight_kg'        => 25.50,
                'condition'        => 'clean',
                'price'            => 0,
                'is_free'          => true,
                'general_location' => 'Bandung Utara',
                'description'      => 'Botol PET bekas minuman sudah dicuci bersih. Dikumpulkan selama 3 bulan dari kantin sekolah. Cocok untuk kerajinan atau daur ulang.',
                'status'           => 'available',
            ]
        );

        $listing2 = MaterialListing::firstOrCreate(
            ['owner_id' => $owner->id, 'name' => 'Kantong HDPE Campuran'],
            [
                'category'         => 'HDPE',
                'weight_kg'        => 15.00,
                'condition'        => 'slightly_dirty',
                'price'            => 2000,
                'is_free'          => false,
                'general_location' => 'Jakarta Selatan',
                'description'      => 'Kantong plastik HDPE dari toko kelontong. Perlu dicuci sebelum digunakan.',
                'status'           => 'available',
            ]
        );

        $listing3 = MaterialListing::firstOrCreate(
            ['owner_id' => $admin->id, 'name' => 'Ember PP Rusak'],
            [
                'category'         => 'PP',
                'weight_kg'        => 8.00,
                'condition'        => 'dirty',
                'price'            => 0,
                'is_free'          => true,
                'general_location' => 'Yogyakarta',
                'description'      => 'Ember polipropilen yang sudah tidak terpakai. Perlu pembersihan menyeluruh.',
                'status'           => 'available',
            ]
        );

        $listing4 = MaterialListing::firstOrCreate(
            ['owner_id' => $admin->id, 'name' => 'Plastik LDPE Film Bening'],
            [
                'category'         => 'LDPE',
                'weight_kg'        => 10.00,
                'condition'        => 'clean',
                'price'            => 1500,
                'is_free'          => false,
                'general_location' => 'Surabaya Barat',
                'description'      => 'Film plastik LDPE transparan dari sisa pengemasan produk. Kondisi bersih dan kering.',
                'status'           => 'reserved',
            ]
        );

        $listing5 = MaterialListing::firstOrCreate(
            ['owner_id' => $owner->id, 'name' => 'Campuran Plastik Rumah Tangga'],
            [
                'category'         => 'MIXED',
                'weight_kg'        => 30.00,
                'condition'        => 'mixed',
                'price'            => 0,
                'is_free'          => true,
                'general_location' => 'Semarang Tengah',
                'description'      => 'Campuran berbagai jenis plastik dari sampah rumah tangga yang sudah dipilah dari sampah organik.',
                'status'           => 'completed',
            ]
        );

        // ─── Material Request Demo ─────────────────────────────────────────────
        $request1 = MaterialRequest::firstOrCreate(
            ['requester_id' => $requester->id, 'intended_use' => 'Kerajinan ecobrick untuk pagar sekolah'],
            [
                'material_category'   => 'PET',
                'requested_weight_kg' => 20.00,
                'minimum_condition'   => 'slightly_dirty',
                'general_location'    => 'Bandung',
                'status'              => 'active',
            ]
        );

        $request2 = MaterialRequest::firstOrCreate(
            ['requester_id' => $admin->id, 'intended_use' => 'Bahan baku pot tanaman komunitas'],
            [
                'material_category'   => 'PP',
                'requested_weight_kg' => 5.00,
                'minimum_condition'   => 'clean',
                'general_location'    => 'Yogyakarta',
                'status'              => 'active',
            ]
        );

        $request3 = MaterialRequest::firstOrCreate(
            ['requester_id' => $requester->id, 'intended_use' => 'Riset material daur ulang kampus'],
            [
                'material_category'   => 'HDPE',
                'requested_weight_kg' => 10.00,
                'minimum_condition'   => 'clean',
                'general_location'    => 'Jakarta',
                'status'              => 'fulfilled',
            ]
        );

        // ─── Handover Request Demo ─────────────────────────────────────────────
        // Handover pending
        HandoverRequest::firstOrCreate(
            [
                'listing_id'   => $listing2->id,
                'requester_id' => $requester->id,
            ],
            [
                'owner_id'            => $owner->id,
                'requested_weight_kg' => 10.00,
                'agreed_weight_kg'    => null,
                'actual_weight_kg'    => null,
                'note'                => 'Saya membutuhkan plastik HDPE untuk proyek kerajinan sekolah kami.',
                'status'              => 'pending',
            ]
        );

        // Handover yang sudah dikonfirmasi pemanfaatannya
        HandoverRequest::firstOrCreate(
            [
                'listing_id'   => $listing5->id,
                'requester_id' => $admin->id,
            ],
            [
                'owner_id'                => $owner->id,
                'requested_weight_kg'     => 30.00,
                'agreed_weight_kg'        => 30.00,
                'actual_weight_kg'        => 28.50,
                'note'                    => 'Material telah digunakan untuk program ecobrick komunitas.',
                'status'                  => 'utilization_confirmed',
                'approved_at'             => now()->subDays(20),
                'handed_over_at'          => now()->subDays(12),
                'utilization_confirmed_at' => now()->subDays(3),
            ]
        );

        // ─── Analysis History Demo ─────────────────────────────────────────────
        // Catatan: is_demo_result = true menunjukkan ini adalah hasil simulasi, bukan AI nyata.
        AnalysisHistory::firstOrCreate(
            ['user_id' => $requester->id, 'declared_category' => 'PET'],
            [
                'estimated_category' => 'PET',
                'weight_kg'          => 2.5,
                'condition'          => 'clean',
                'input_image_path'   => null,
                'recommendations'    => [
                    [
                        'type'        => 'craft',
                        'title'       => 'Botol Bunga Kreatif',
                        'description' => 'Botol PET bersih dapat diubah menjadi pot bunga atau vas dekoratif.',
                        'difficulty'  => 'mudah',
                    ],
                    [
                        'type'        => 'recycle',
                        'title'       => 'Setor ke Bank Sampah',
                        'description' => 'PET bersih diterima di bank sampah dengan harga yang baik.',
                        'difficulty'  => 'mudah',
                    ],
                ],
                'is_demo_result' => true,
            ]
        );

        $this->command->info('[DEMO DATA] Seeder selesai. Akun demo:');
        $this->command->info('  admin@plasvora.test / password');
        $this->command->info('  pemilik@plasvora.test / password');
        $this->command->info('  peminta@plasvora.test / password');
    }
}
