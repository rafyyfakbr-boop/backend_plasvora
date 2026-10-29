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
}
