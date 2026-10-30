<?php

namespace Tests\Feature;

use App\Models\AnalysisHistory;
use App\Models\User;
use App\Services\GeminiVisionService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiVisionAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * Helper membuat dummy JPEG file valid tanpa butuh extension GD.
     */
    private function createDummyJpgFile(string $name = 'test.jpg'): UploadedFile
    {
        // 1x1 minimal valid JPEG binary
        $jpegData = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $tempPath = tempnam(sys_get_temp_dir(), 'test_img_');
        file_put_contents($tempPath, $jpegData);

        return new UploadedFile(
            $tempPath,
            $name,
            'image/jpeg',
            null,
            true // test mode
        );
    }

    /** @test */
    public function analysis_fails_validation_when_no_image_and_no_category_provided(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/analyses', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function analysis_rejects_invalid_image_file(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    /** @test */
    public function analysis_handles_missing_api_key_gracefully(): void
    {
        Config::set('services.gemini.api_key', null);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Layanan AI belum dikonfigurasi pada server.',
            ]);
    }

    /** @test */
    public function analysis_handles_gemini_authentication_error(): void
    {
        Config::set('services.gemini.api_key', 'invalid_key');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 401,
                    'message' => 'API key not valid. Please pass a valid API key.',
                    'status' => 'UNAUTHENTICATED',
                ]
            ], 401),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Autentikasi Gemini API gagal. Periksa konfigurasi API key.',
            ]);
    }

    /** @test */
    public function analysis_handles_gemini_rate_limit_429(): void
    {
        Config::set('services.gemini.api_key', 'test_key');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Resource has been exhausted']
            ], 429),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(429)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function analysis_successfully_processes_image_with_gemini_structured_response(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'             => 'success',
                                    'is_plastic'         => true,
                                    'detected_object'    => 'Tutup botol plastik HDPE bulat',
                                    'material_type'      => 'PLASTIC',
                                    'message'            => 'Identifikasi sampah plastik berhasil.',
                                    'estimated_category' => 'HDPE',
                                    'material_title'     => 'Tutup Botol Minuman HDPE',
                                    'polymer_code'       => 'Tipe #2 (HDPE)',
                                    'melting_point'      => '130° - 145° C',
                                    'avoided_emission'   => '~1.8 kg CO₂/kg',
                                    'qualitative_note'   => 'Tampak material tutup botol buram dan kaku dengan indikasi polietilena berdensitas tinggi.',
                                    'ideas'              => [
                                        [
                                            'id'          => 'idea_1',
                                            'title'       => 'Tatakan Gelas Komposit',
                                            'difficulty'  => 'Tingkat Mudah',
                                            'description' => 'Disusun dan direkatkan menjadi tatakan gelas warna-warni.',
                                            'why_it_fits' => 'Bentuk pipih dan bundar dari tutup botol sangat pas untuk alas cangkir.',
                                            'tools'       => 'Lem serbaguna, gabus alas',
                                            'steps'       => '1. Bersihkan tutup botol. 2. Susun melingkar. 3. Rekatkan pada alas gabus.',
                                            'safety_notes'=> 'Gunakan lem di ruangan berventilasi cukup.',
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('bottle_cap.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image'             => $file,
                'weight_kg'         => 2.5,
                'condition'         => 'clean',
                'declared_category' => 'HDPE',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Analisis plastik berhasil dilakukan dengan Gemini AI.',
                'data'    => [
                    'user_id'            => $user->id,
                    'estimated_category' => 'HDPE',
                    'is_demo_result'     => false,
                ],
            ]);

        $historyId = $response->json('data.id');
        $this->assertDatabaseHas('analysis_histories', [
            'id'                 => $historyId,
            'user_id'            => $user->id,
            'estimated_category' => 'HDPE',
            'is_demo_result'     => 0,
        ]);

        $history = AnalysisHistory::find($historyId);
        $this->assertNotNull($history->input_image_path);
        Storage::disk('public')->assertExists($history->input_image_path);

        $this->assertIsArray($history->recommendations);
        $this->assertEquals('Tutup Botol Minuman HDPE', $history->recommendations['material_title']);
        $this->assertEquals('Tipe #2 (HDPE)', $history->recommendations['polymer_code']);
    }

    /** @test */
    public function analysis_handles_non_plastic_object_as_unsupported_material(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'             => 'unsupported_material',
                                    'is_plastic'         => false,
                                    'detected_object'    => 'Kaleng wafer biskuit logam',
                                    'material_type'      => 'NON_PLASTIC',
                                    'message'            => 'Objek ini tampaknya bukan sampah plastik. Silakan foto sampah plastik untuk mendapatkan rekomendasi upcycling.',
                                    'estimated_category' => 'OTHER',
                                    'material_title'     => 'Kaleng Logam Tinplate',
                                    'polymer_code'       => '-',
                                    'melting_point'      => '-',
                                    'avoided_emission'   => '-',
                                    'qualitative_note'   => 'Objek berbahan logam timah/baja, bukan polimer plastik.',
                                    'ideas'              => [],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('metal_can.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
                'notes' => 'kaleng astor',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'status'  => 'unsupported_material',
                'message' => 'Objek ini tampaknya bukan sampah plastik. Silakan foto sampah plastik untuk mendapatkan rekomendasi upcycling.',
            ]);

        // Pastikan tidak ada riwayat yang disimpan di database
        $this->assertDatabaseCount('analysis_histories', 0);
    }

    /** @test */
    public function analysis_handles_uncertain_material_object(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'             => 'uncertain_material',
                                    'is_plastic'         => false,
                                    'detected_object'    => 'Objek buram tidak jelas',
                                    'material_type'      => 'UNCERTAIN',
                                    'message'            => 'Jenis material belum dapat dipastikan. Coba foto objek lebih dekat dengan pencahayaan yang lebih jelas.',
                                    'estimated_category' => 'OTHER',
                                    'material_title'     => 'Material Belum Pasti',
                                    'polymer_code'       => '-',
                                    'melting_point'      => '-',
                                    'avoided_emission'   => '-',
                                    'qualitative_note'   => 'Foto terlalu gelap dan jauh.',
                                    'ideas'              => [],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('blurry.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'status'  => 'uncertain_material',
                'message' => 'Jenis material belum dapat dipastikan. Coba foto objek lebih dekat dengan pencahayaan yang lebih jelas.',
            ]);

        $this->assertDatabaseCount('analysis_histories', 0);
    }

    /** @test */
    public function analysis_handles_recitation_filter_gracefully(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content'      => [],
                    'finishReason' => 'RECITATION',
                    'index'        => 0,
                    'finishMessage'=> 'The generated content was filtered because it may contain material that resembles existing copyrighted works.',
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('branded_can.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
                'notes' => 'kaleng astor',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertStringContainsString('merek', $response->json('message'));
        $this->assertDatabaseCount('analysis_histories', 0);
    }

    /** @test */
    public function analysis_handles_safety_filter_gracefully(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content'      => [],
                    'finishReason' => 'SAFETY',
                    'index'        => 0,
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('unsafe.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertStringContainsString('keselamatan', $response->json('message'));
        $this->assertDatabaseCount('analysis_histories', 0);
    }

    /** @test */
    public function analysis_handles_invalid_json_returned_by_ai(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Bukan format JSON yang valid.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('bottle.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
                'message' => 'Format respon AI tidak valid.',
            ]);

        $this->assertDatabaseCount('analysis_histories', 0);
    }

    /** @test */
    public function analysis_successfully_identifies_multi_component_bottle_and_cap(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'                 => 'success',
                                    'is_plastic'             => true,
                                    'object_name'            => 'Botol air mineral plastik',
                                    'object_description'     => 'Botol plastik transparan bening dengan tutup ulir berwarna biru.',
                                    'identification_summary' => 'Objek terdiri dari 2 komponen: badan botol (PET) dan tutup botol (HDPE/PP) sesuai catatan pengguna.',
                                    'components'             => [
                                        [
                                            'id'                   => 'component_body',
                                            'part_name'            => 'Badan botol',
                                            'material_type'        => 'PET',
                                            'resin_code'           => '#1',
                                            'confidence'           => 'medium',
                                            'identification_basis' => 'visual_estimate',
                                            'candidate_materials'  => [],
                                            'verification_note'    => 'Periksa simbol daur ulang segitiga angka 1 (PET) pada bagian bawah botol.',
                                            'melting_point'        => '250° - 260° C',
                                        ],
                                        [
                                            'id'                   => 'component_cap',
                                            'part_name'            => 'Tutup botol',
                                            'material_type'        => 'uncertain',
                                            'resin_code'           => null,
                                            'confidence'           => 'low',
                                            'identification_basis' => 'visual_estimate',
                                            'candidate_materials'  => ['HDPE (#2)', 'PP (#5)'],
                                            'verification_note'    => 'Periksa kode daur ulang timbul pada bagian dalam tutup botol.',
                                            'melting_point'        => '130° - 165° C',
                                        ],
                                    ],
                                    'recommendations' => [
                                        [
                                            'id'                => 'idea_1',
                                            'title'             => 'Pot Tanaman Mandiri & Hidroponik Sederhana',
                                            'difficulty'        => 'Tingkat Mudah',
                                            'description'       => 'Potong bagian atas botol dan balikkan sebagai wadah tanaman bersumbu.',
                                            'component_ids'     => ['component_body'],
                                            'why_it_fits'       => 'Bentuk silinder dan sifat kedap air botol PET sangat cocok untuk menampung air.',
                                            'suitable_quantity' => 'single_item',
                                            'scale'             => 'household',
                                            'tools'             => 'Gunting/cutter, sumbu kain flanel',
                                            'steps'             => '1. Potong botol menjadi 2 bagian. 2. Masukkan sumbu kain. 3. Isi media tanam.',
                                            'safety_notes'      => 'Hati-hati saat memotong tepi botol plastik.',
                                        ],
                                        [
                                            'id'                => 'idea_2',
                                            'title'             => 'Aksesori Tatakan Gelas Mini',
                                            'difficulty'        => 'Tingkat Mudah',
                                            'description'       => 'Pemanfaatan tutup botol sebagai pengalas kaki meja atau kerajinan tangan sederhana.',
                                            'component_ids'     => ['component_cap'],
                                            'why_it_fits'       => 'Ketebalan dan kekuatan tutup botol HDPE/PP tahan tekanan.',
                                            'suitable_quantity' => 'single_item',
                                            'scale'             => 'household',
                                            'tools'             => 'Perekat dua sisi atau lem serbaguna',
                                            'steps'             => '1. Bersihkan tutup botol. 2. Pasang pada bagian bawah benda.',
                                            'safety_notes'      => 'Gunakan lem secukupnya.',
                                        ],
                                    ],
                                    'detected_object'    => 'Botol air mineral plastik',
                                    'material_type'      => 'PLASTIC',
                                    'message'            => 'Identifikasi sampah plastik berhasil.',
                                    'estimated_category' => 'MIXED',
                                    'material_title'     => 'Botol Air Mineral Plastik (Multi-komponen: PET, HDPE/PP)',
                                    'polymer_code'       => 'Badan: Tipe #1 | Tutup: Tipe #2/#5',
                                    'melting_point'      => 'Badan: 250°-260°C | Tutup: 130°-165°C',
                                    'avoided_emission'   => '~2.0 kg CO₂/kg (Estimasi)',
                                    'qualitative_note'   => 'Objek multi-komponen berhasil diidentifikasi terpisah.',
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('bottle_with_cap.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image'     => $file,
                'weight_kg' => 0.05,
                'condition' => 'clean',
                'notes'     => 'botol dan tutup botol plastik',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'user_id' => $user->id,
                    'notes'   => 'botol dan tutup botol plastik',
                ],
            ]);

        $historyId = $response->json('data.id');
        $history = AnalysisHistory::find($historyId);

        $this->assertNotNull($history);
        $this->assertEquals('botol dan tutup botol plastik', $history->notes);

        $recs = $history->recommendations;
        $this->assertArrayHasKey('components', $recs);
        $this->assertCount(2, $recs['components']);

        // Komponen 1: Badan botol
        $this->assertEquals('component_body', $recs['components'][0]['id']);
        $this->assertEquals('Badan botol', $recs['components'][0]['part_name']);
        $this->assertEquals('PET', $recs['components'][0]['material_type']);
        $this->assertEquals('#1', $recs['components'][0]['resin_code']);
        $this->assertEquals('medium', $recs['components'][0]['confidence']);

        // Komponen 2: Tutup botol
        $this->assertEquals('component_cap', $recs['components'][1]['id']);
        $this->assertEquals('Tutup botol', $recs['components'][1]['part_name']);
        $this->assertEquals('uncertain', $recs['components'][1]['material_type']);
        $this->assertContains('HDPE (#2)', $recs['components'][1]['candidate_materials']);
        $this->assertContains('PP (#5)', $recs['components'][1]['candidate_materials']);

        // Rekomendasi
        $this->assertArrayHasKey('recommendations', $recs);
        $this->assertCount(2, $recs['recommendations']);
        $this->assertEquals(['component_body'], $recs['recommendations'][0]['component_ids']);
        $this->assertEquals('single_item', $recs['recommendations'][0]['suitable_quantity']);
        $this->assertEquals('household', $recs['recommendations'][0]['scale']);
    }

    /** @test */
    public function analysis_identifies_cap_only_without_inventing_bottle_body(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'                 => 'success',
                                    'is_plastic'             => true,
                                    'object_name'            => 'Tutup botol plastik',
                                    'object_description'     => 'Satu buah tutup botol plastik bundar berwarna merah.',
                                    'identification_summary' => 'Hanya satu buah tutup botol yang tampak pada foto. Tidak terdapat badan botol.',
                                    'components'             => [
                                        [
                                            'id'                   => 'component_cap',
                                            'part_name'            => 'Tutup botol',
                                            'material_type'        => 'uncertain',
                                            'resin_code'           => null,
                                            'confidence'           => 'low',
                                            'identification_basis' => 'visual_estimate',
                                            'candidate_materials'  => ['HDPE (#2)', 'PP (#5)'],
                                            'verification_note'    => 'Periksa kode embos bagian dalam.',
                                            'melting_point'        => '130° - 165° C',
                                        ],
                                    ],
                                    'recommendations' => [
                                        [
                                            'id'                => 'idea_1',
                                            'title'             => 'Pion Mainan Papan & Kerajinan',
                                            'difficulty'        => 'Tingkat Mudah',
                                            'description'       => 'Dapat dijadikan pion permainan atau penanda barang.',
                                            'component_ids'     => ['component_cap'],
                                            'suitable_quantity' => 'single_item',
                                            'scale'             => 'household',
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('single_cap.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
                'notes' => 'tutup botol plastik',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $recs = $response->json('data.recommendations');

        $this->assertCount(1, $recs['components']);
        $this->assertEquals('Tutup botol', $recs['components'][0]['part_name']);
        $this->assertStringNotContainsString('Badan botol', json_encode($recs['components']));
    }

    /** @test */
    public function analysis_identifies_capless_bottle_body_without_inventing_cap(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'                 => 'success',
                                    'is_plastic'             => true,
                                    'object_name'            => 'Badan botol plastik tanpa tutup',
                                    'object_description'     => 'Badan botol air mineral plastik bening dengan leher terbuka tanpa tutup.',
                                    'identification_summary' => 'Badan botol terlihat tanpa penutup.',
                                    'components'             => [
                                        [
                                            'id'                   => 'component_body',
                                            'part_name'            => 'Badan botol',
                                            'material_type'        => 'PET',
                                            'resin_code'           => '#1',
                                            'confidence'           => 'medium',
                                            'identification_basis' => 'visual_estimate',
                                            'candidate_materials'  => [],
                                            'verification_note'    => 'Periksa simbol segitiga angka 1 di dasar botol.',
                                            'melting_point'        => '250° - 260° C',
                                        ],
                                    ],
                                    'recommendations' => [
                                        [
                                            'id'                => 'idea_1',
                                            'title'             => 'Corong Tuang Praktis',
                                            'difficulty'        => 'Tingkat Mudah',
                                            'description'       => 'Potong bagian atas untuk dijadikan corong.',
                                            'component_ids'     => ['component_body'],
                                            'suitable_quantity' => 'single_item',
                                            'scale'             => 'household',
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('bottle_no_cap.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
                'notes' => 'botol plastik tanpa tutup',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $recs = $response->json('data.recommendations');

        $this->assertCount(1, $recs['components']);
        $this->assertEquals('Badan botol', $recs['components'][0]['part_name']);
        $this->assertStringNotContainsString('Tutup botol', json_encode($recs['components']));
    }

    /** @test */
    public function analysis_identifies_bottle_with_label_components(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'                 => 'success',
                                    'is_plastic'             => true,
                                    'object_name'            => 'Botol plastik dengan label',
                                    'object_description'     => 'Botol bening dengan label plastik tipis melingkar.',
                                    'identification_summary' => 'Teridentifikasi badan botol dan label plastik terpisah.',
                                    'components'             => [
                                        [
                                            'id'                   => 'component_body',
                                            'part_name'            => 'Badan botol',
                                            'material_type'        => 'PET',
                                            'resin_code'           => '#1',
                                            'confidence'           => 'medium',
                                            'identification_basis' => 'visual_estimate',
                                            'candidate_materials'  => [],
                                            'verification_note'    => 'Periksa kode di bawah botol.',
                                        ],
                                        [
                                            'id'                   => 'component_label',
                                            'part_name'            => 'Label kemasan',
                                            'material_type'        => 'uncertain',
                                            'resin_code'           => null,
                                            'confidence'           => 'low',
                                            'identification_basis' => 'visual_estimate',
                                            'candidate_materials'  => ['PP film', 'PVC film', 'PET film'],
                                            'verification_note'    => 'Lepas label sebelum didaur ulang.',
                                        ],
                                    ],
                                    'recommendations' => [
                                        [
                                            'id'                => 'idea_1',
                                            'title'             => 'Wadah Penyimpanan Transparan',
                                            'difficulty'        => 'Tingkat Mudah',
                                            'description'       => 'Lepaskan label plastik dan bersihkan badan botol.',
                                            'component_ids'     => ['component_body'],
                                            'suitable_quantity' => 'single_item',
                                            'scale'             => 'household',
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('bottle_with_label.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
                'notes' => 'botol dengan label',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $recs = $response->json('data.recommendations');

        $this->assertCount(2, $recs['components']);
        $this->assertEquals('Label kemasan', $recs['components'][1]['part_name']);
        $this->assertEquals('uncertain', $recs['components'][1]['material_type']);
    }

    /** @test */
    public function analysis_handles_conflicting_notes_and_photo_gracefully(): void
    {
        Config::set('services.gemini.api_key', 'valid_test_key');

        $geminiResponsePayload = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'status'                 => 'success',
                                    'is_plastic'             => true,
                                    'object_name'            => 'Tutup botol plastik',
                                    'object_description'     => 'Hanya terlihat satu buah tutup botol plastik pada foto.',
                                    'identification_summary' => 'Catatan pengguna menyebut "botol air mineral lengkap", namun visual foto hanya memperlihatkan tutup botol. Harap verifikasi objek Anda.',
                                    'components'             => [
                                        [
                                            'id'                   => 'component_cap',
                                            'part_name'            => 'Tutup botol',
                                            'material_type'        => 'uncertain',
                                            'resin_code'           => null,
                                            'confidence'           => 'low',
                                            'identification_basis' => 'visual_estimate',
                                            'candidate_materials'  => ['HDPE (#2)', 'PP (#5)'],
                                            'verification_note'    => 'Verifikasi bagian botol yang tidak tampak pada foto.',
                                        ],
                                    ],
                                    'recommendations' => [
                                        [
                                            'id'                => 'idea_1',
                                            'title'             => 'Pemanfaatan Tutup Botol',
                                            'difficulty'        => 'Tingkat Mudah',
                                            'description'       => 'Gunakan tutup botol untuk keperluan kerajinan.',
                                            'component_ids'     => ['component_cap'],
                                            'suitable_quantity' => 'single_item',
                                            'scale'             => 'household',
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($geminiResponsePayload, 200),
        ]);

        $user = User::factory()->create();
        $file = $this->createDummyJpgFile('conflict_cap.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/analyses', [
                'image' => $file,
                'notes' => 'botol air mineral lengkap',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $recs = $response->json('data.recommendations');

        // Tidak mengarang badan botol
        $this->assertCount(1, $recs['components']);
        $this->assertStringContainsString('Catatan pengguna', $recs['identification_summary']);
    }

    /** @test */
    public function analysis_history_allows_reading_legacy_records_without_crashing(): void
    {
        $user = User::factory()->create();

        // Data riwayat versi lama (tanpa struktur components modern)
        $legacyAnalysis = AnalysisHistory::create([
            'user_id'            => $user->id,
            'declared_category'  => 'PET',
            'estimated_category' => 'PET',
            'weight_kg'          => 1.0,
            'condition'          => 'clean',
            'notes'              => null,
            'input_image_path'   => null,
            'recommendations'    => [
                'material_title'   => 'Botol PET Lama',
                'polymer_code'     => 'Tipe #1',
                'melting_point'    => '250° - 260° C',
                'avoided_emission' => '~2.1 kg CO₂/kg',
                'qualitative_note' => 'Riwayat lama.',
                'ideas'            => [
                    [
                        'id'          => 'idea_1',
                        'title'       => 'Pot Tanaman',
                        'difficulty'  => 'Tingkat Mudah',
                        'description' => 'Ide versi lama.',
                    ],
                ],
            ],
            'is_demo_result'     => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/analyses');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $this->assertEquals($legacyAnalysis->id, $items[0]['id']);
        $this->assertEquals('Botol PET Lama', $items[0]['recommendations']['material_title']);
    }
}
