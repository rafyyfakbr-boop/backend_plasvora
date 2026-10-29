<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisHistory;
use App\Services\GeminiVisionService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AnalysisController extends Controller
{
    protected GeminiVisionService $geminiVisionService;

    public function __construct(GeminiVisionService $geminiVisionService)
    {
        $this->geminiVisionService = $geminiVisionService;
    }

    /**
     * GET /api/analyses
     * Ambil riwayat analisis milik user yang terautentikasi.
     */
    public function index(): JsonResponse
    {
        $analyses = AnalysisHistory::where('user_id', Auth::id())
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Analysis histories retrieved successfully',
            'data'    => $analyses->items(),
            'meta'    => [
                'current_page' => $analyses->currentPage(),
                'last_page'    => $analyses->lastPage(),
                'per_page'     => $analyses->perPage(),
                'total'        => $analyses->total(),
            ],
        ]);
    }

    /**
     * POST /api/analyses
     * Menganalisis sampah plastik menggunakan Gemini AI Vision jika gambar tersedia,
     * atau fallback rekomendasi berbasis aturan jika hanya input parameter deklaratif.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image'             => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            'image_file'        => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            'declared_category' => ['nullable', Rule::in(['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'])],
            'weight_kg'         => ['nullable', 'numeric', 'min:0.01'],
            'condition'         => ['nullable', Rule::in(['clean', 'slightly_dirty', 'dirty', 'mixed', 'light'])],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ], [
            'image.image'          => 'File harus berupa gambar yang valid.',
            'image.mimes'          => 'Format gambar harus berupa jpeg, jpg, png, atau webp.',
            'image.max'            => 'Ukuran gambar maksimal adalah 10MB.',
            'image_file.image'     => 'File harus berupa gambar yang valid.',
            'image_file.mimes'     => 'Format gambar harus berupa jpeg, jpg, png, atau webp.',
            'image_file.max'       => 'Ukuran gambar maksimal adalah 10MB.',
            'declared_category.in' => 'Kategori plastik yang dideklarasikan tidak valid.',
            'weight_kg.min'        => 'Berat harus lebih dari 0 kg.',
        ]);

        $imageFile = $request->file('image') ?? $request->file('image_file');
        $declaredCategory = $validated['declared_category'] ?? null;
        $condition = $validated['condition'] ?? 'clean';
        if ($condition === 'light') {
            $condition = 'slightly_dirty';
        }
        $weightKg = isset($validated['weight_kg']) ? (float) $validated['weight_kg'] : null;
        $notes = $validated['notes'] ?? null;

        // Jika tidak ada gambar dan tidak ada kategori deklaratif
        if (!$imageFile && !$declaredCategory) {
            return response()->json([
                'success' => false,
                'message' => 'Harap sertakan foto sampah plastik atau pilih kategori plastik.',
                'errors'  => [
                    'image' => ['File gambar atau kategori plastik wajib disediakan.'],
                ],
            ], 422);
        }

        $imagePath = null;
        if ($imageFile && $imageFile->isValid()) {
            $imagePath = $imageFile->store('analyses', 'public');
        }

        // Jalankan Gemini AI jika gambar tersedia
        if ($imageFile) {
            try {
                $aiResult = $this->geminiVisionService->analyzePlasticImage(
                    $imageFile,
                    $declaredCategory,
                    $notes
                );

                // Periksa apakah objek atau material belum dapat dipastikan
                if (($aiResult['status'] ?? '') === 'uncertain_material') {
                    if ($imagePath && \Illuminate\Support\Facades\Storage::disk('public')->exists($imagePath)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($imagePath);
                    }

                    return response()->json([
                        'success' => false,
                        'status'  => 'uncertain_material',
                        'message' => $aiResult['message'] ?? 'Jenis material belum dapat dipastikan. Coba foto objek lebih dekat dengan pencahayaan yang lebih jelas.',
                        'data'    => $aiResult,
                    ], 422);
                }

                // Periksa apakah objek bukan sampah plastik
                if (($aiResult['status'] ?? '') === 'unsupported_material' || empty($aiResult['is_plastic'])) {
                    if ($imagePath && \Illuminate\Support\Facades\Storage::disk('public')->exists($imagePath)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($imagePath);
                    }

                    return response()->json([
                        'success' => false,
                        'status'  => 'unsupported_material',
                        'message' => $aiResult['message'] ?? 'Objek ini tampaknya bukan sampah plastik. Silakan foto sampah plastik untuk mendapatkan rekomendasi upcycling.',
                        'data'    => $aiResult,
                    ], 422);
                }

                $estimatedCategory = $aiResult['estimated_category'];

                $analysis = AnalysisHistory::create([
                    'user_id'            => Auth::id(),
                    'declared_category'  => $declaredCategory ?? $estimatedCategory,
                    'estimated_category' => $estimatedCategory,
                    'weight_kg'          => $weightKg,
                    'condition'          => $condition,
                    'input_image_path'   => $imagePath,
                    'recommendations'    => $aiResult,
                    'is_demo_result'     => false,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Analisis plastik berhasil dilakukan dengan Gemini AI.',
                    'data'    => $analysis,
                ], 201);
            } catch (Exception $e) {
                Log::error('AnalysisController Gemini Vision Error: ' . $e->getMessage());

                // Kembalikan error yang aman dan informatif
                $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 500;
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Gagal melakukan analisis gambar dengan AI.',
                ], $statusCode);
            }
        }

        // Fallback: Mode Analisis Berbasis Aturan (jika tanpa upload gambar)
        $category = $declaredCategory ?? 'OTHER';
        $recommendations = $this->generateRecommendations($category, $condition);

        $analysis = AnalysisHistory::create([
            'user_id'            => Auth::id(),
            'declared_category'  => $category,
            'estimated_category' => $category,
            'weight_kg'          => $weightKg,
            'condition'          => $condition,
            'input_image_path'   => null,
            'recommendations'    => $recommendations,
            'is_demo_result'     => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Analysis completed. Results are rule-based recommendations, not AI.',
            'data'    => $analysis,
        ], 201);
    }

    /**
     * Hasilkan rekomendasi berbasis aturan berdasarkan kategori plastik dan kondisinya.
     *
     * @return array<int, array<string, string>>
     */
    private function generateRecommendations(string $category, ?string $condition): array
    {
        $baseRecommendations = [
            'PET' => [
                [
                    'id'          => 'idea_1',
                    'type'        => 'craft',
                    'title'       => 'Kerajinan Botol Kreatif',
                    'description' => 'Botol PET dapat diubah menjadi pot tanaman, vas bunga, atau wadah penyimpanan.',
                    'difficulty'  => 'Tingkat Mudah',
                ],
                [
                    'id'          => 'idea_2',
                    'type'        => 'recycle',
                    'title'       => 'Daur Ulang di Bank Sampah',
                    'description' => 'PET adalah salah satu plastik yang paling mudah didaur ulang dan bernilai tinggi.',
                    'difficulty'  => 'Tingkat Mudah',
                ],
                [
                    'id'          => 'idea_3',
                    'type'        => 'donate',
                    'title'       => 'Donasikan ke Komunitas Ecobrick',
                    'description' => 'Botol PET cocok untuk ecobrick yang dapat digunakan sebagai bahan bangunan komunitas.',
                    'difficulty'  => 'Tingkat Mudah',
                ],
            ],
            'HDPE' => [
                [
                    'id'          => 'idea_1',
                    'type'        => 'craft',
                    'title'       => 'Wadah Serbaguna',
                    'description' => 'HDPE (botol detergen/shampoo) bisa dijadikan pot tanaman atau tempat penyimpanan.',
                    'difficulty'  => 'Tingkat Mudah',
                ],
                [
                    'id'          => 'idea_2',
                    'type'        => 'recycle',
                    'title'       => 'Daur Ulang Industri',
                    'description' => 'HDPE dapat didaur ulang menjadi pipa, papan kayu plastik, atau kemasan baru.',
                    'difficulty'  => 'Tingkat Menengah',
                ],
            ],
            'PP' => [
                [
                    'id'          => 'idea_1',
                    'type'        => 'craft',
                    'title'       => 'Kerajinan Berbasis PP',
                    'description' => 'Polipropilen cocok untuk kerajinan yang memerlukan ketahanan panas.',
                    'difficulty'  => 'Tingkat Menengah',
                ],
                [
                    'id'          => 'idea_2',
                    'type'        => 'recycle',
                    'title'       => 'Setor ke Pengepul PP',
                    'description' => 'PP didaur ulang menjadi kemasan makanan, ember, atau komponen otomotif.',
                    'difficulty'  => 'Tingkat Menengah',
                ],
            ],
            'LDPE' => [
                [
                    'id'          => 'idea_1',
                    'type'        => 'craft',
                    'title'       => 'Tas Woven Plastik',
                    'description' => 'Film LDPE dapat dipilin dan dianyam menjadi tas atau keranjang kreatif.',
                    'difficulty'  => 'Tingkat Menengah',
                ],
                [
                    'id'          => 'idea_2',
                    'type'        => 'recycle',
                    'title'       => 'Daur Ulang Film Plastik',
                    'description' => 'Film LDPE dapat didaur ulang menjadi kantong belanja atau pipa fleksibel.',
                    'difficulty'  => 'Tingkat Sulit',
                ],
            ],
            'MIXED' => [
                [
                    'id'          => 'idea_1',
                    'type'        => 'sort',
                    'title'       => 'Pilah Terlebih Dahulu',
                    'description' => 'Plastik campuran perlu dipilah berdasarkan jenis sebelum dapat didaur ulang optimal.',
                    'difficulty'  => 'Tingkat Menengah',
                ],
                [
                    'id'          => 'idea_2',
                    'type'        => 'donate',
                    'title'       => 'Donasikan ke Bank Sampah',
                    'description' => 'Bank sampah dapat membantu memilah dan mendaur ulang plastik campuran.',
                    'difficulty'  => 'Tingkat Mudah',
                ],
            ],
            'OTHER' => [
                [
                    'id'          => 'idea_1',
                    'type'        => 'check',
                    'title'       => 'Identifikasi Jenis Plastik',
                    'description' => 'Periksa kode segitiga daur ulang pada kemasan untuk mengetahui jenis plastik yang tepat.',
                    'difficulty'  => 'Tingkat Mudah',
                ],
                [
                    'id'          => 'idea_2',
                    'type'        => 'donate',
                    'title'       => 'Konsultasi Bank Sampah',
                    'description' => 'Bank sampah dapat membantu mengidentifikasi dan mendaur ulang jenis plastik lain.',
                    'difficulty'  => 'Tingkat Mudah',
                ],
            ],
        ];

        $recommendations = $baseRecommendations[$category] ?? $baseRecommendations['OTHER'];

        if ($condition === 'dirty' || $condition === 'mixed') {
            array_unshift($recommendations, [
                'id'          => 'idea_prep',
                'type'        => 'prep',
                'title'       => 'Bersihkan Terlebih Dahulu',
                'description' => 'Material dengan kondisi kotor perlu dicuci dan dikeringkan sebelum didaur ulang atau dijadikan kerajinan.',
                'difficulty'  => 'Tingkat Mudah',
            ]);
        }

        return $recommendations;
    }
}
