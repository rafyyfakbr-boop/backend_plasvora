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
                                            'description' => 'Dileburkan dan dicetak menjadi tatakan gelas warna-warni.',
                                            'tools'       => 'Panggangan mini, cetakan silikon',
                                            'steps'       => '1. Cacah kecil tutup botol. 2. Masukkan ke cetakan. 3. Panaskan pada suhu 140°C.',
                                            'safety_notes'=> 'Gunakan sarung tangan anti panas dan masker.',
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
