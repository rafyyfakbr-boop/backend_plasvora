<?php

namespace Tests\Unit\Services;

use App\Models\AnalysisHistory;
use App\Models\MaterialListing;
use App\Models\MaterialRequest;
use App\Services\GeminiVisionService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

class GeminiVisionCategoryContractTest extends TestCase
{
    public function test_analysis_category_allowlist_matches_domain_models_and_rejects_numeric_codes(): void
    {
        $expected = ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'];

        $this->assertSame($expected, AnalysisHistory::CATEGORIES);
        $this->assertSame($expected, MaterialListing::CATEGORIES);
        $this->assertSame($expected, MaterialRequest::CATEGORIES);

        $rules = [
            'declared_category' => ['nullable', 'string', Rule::in(AnalysisHistory::CATEGORIES)],
        ];

        // Every canonical category and null must pass
        foreach (array_merge([null], AnalysisHistory::CATEGORIES) as $valid) {
            $validator = Validator::make(['declared_category' => $valid], $rules);
            $this->assertFalse($validator->fails(), 'Expected valid category: ' . var_export($valid, true));
        }

        // Legacy numeric codes ('1'..'7') and unsupported values must fail
        foreach (['1', '2', '3', '4', '5', '6', '7', 'PVC', 'PS', 'INVALID'] as $invalid) {
            $validator = Validator::make(['declared_category' => $invalid], $rules);
            $this->assertTrue($validator->fails(), 'Expected invalid category to fail: ' . $invalid);
        }
    }

    public function test_gemini_vision_service_forwards_canonical_declared_category_and_schema_enum(): void
    {
        Config::set('services.gemini.api_key', 'unit_test_key');

        $jpegData = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $tempPath = tempnam(sys_get_temp_dir(), 'plasvora_cat_');
        file_put_contents($tempPath, $jpegData);

        try {
            $currentCategory = null;
            $capturedPrompt = null;
            $capturedSchemaEnum = null;

            Http::fake([
                'https://generativelanguage.googleapis.com/*' => function ($request) use (&$currentCategory, &$capturedPrompt, &$capturedSchemaEnum) {
                    $capturedPrompt = $request['contents'][0]['parts'][0]['text'] ?? '';
                    $capturedSchemaEnum = $request['generationConfig']['responseSchema']['properties']['estimated_category']['enum'] ?? null;

                    return Http::response([
                        'candidates' => [[
                            'content' => [
                                'parts' => [[
                                    'text' => json_encode([
                                        'status'             => 'success',
                                        'is_plastic'         => true,
                                        'material_type'      => 'PLASTIC',
                                        'message'            => 'OK',
                                        'object_name'        => "Plastik {$currentCategory}",
                                        'estimated_category' => $currentCategory,
                                        'recommendations'    => [[
                                            'id'                => 'idea_1',
                                            'title'             => 'Ide Daur Ulang',
                                            'difficulty'        => 'Tingkat Mudah',
                                            'description'       => 'Pemanfaatan sederhana.',
                                            'component_ids'     => ['component_main'],
                                            'suitable_quantity' => 'single_item',
                                            'scale'             => 'household',
                                        ]],
                                    ]),
                                ]],
                            ],
                        ]],
                    ], 200);
                },
            ]);

            foreach (AnalysisHistory::CATEGORIES as $category) {
                $currentCategory = $category;
                $capturedPrompt = null;
                $capturedSchemaEnum = null;

                $service = new GeminiVisionService();
                $result = $service->analyzePlasticImage($tempPath, $category, 'catatan uji');

                $this->assertStringContainsString("Kategori awal yang dipilih pengguna: {$category}", (string) $capturedPrompt);
                $this->assertSame(AnalysisHistory::CATEGORIES, $capturedSchemaEnum);
                $this->assertSame($category, $result['estimated_category']);
            }
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
