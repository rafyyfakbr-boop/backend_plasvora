<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AnalysisController extends Controller
{
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
     * Simpan riwayat analisis dan kembalikan rekomendasi berbasis aturan.
     *
     * CATATAN: Ini adalah sistem rekomendasi berbasis aturan (rule-based).
     * Bukan AI/ML nyata. Hasil ditandai dengan is_demo_result = true.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'declared_category' => ['required', Rule::in(['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'])],
            'weight_kg'         => ['nullable', 'numeric', 'min:0.01'],
            'condition'         => ['nullable', Rule::in(['clean', 'slightly_dirty', 'dirty', 'mixed'])],
        ], [
            'declared_category.required' => 'Kategori plastik wajib dipilih.',
            'declared_category.in'       => 'Kategori tidak valid.',
            'weight_kg.min'              => 'Berat harus lebih dari 0.',
        ]);

        // Hasilkan rekomendasi berbasis aturan
        $recommendations = $this->generateRecommendations(
            $validated['declared_category'],
            $validated['condition'] ?? null
        );

        $analysis = AnalysisHistory::create([
            'user_id'            => Auth::id(),
            'declared_category'  => $validated['declared_category'],
            'estimated_category' => $validated['declared_category'], // Demo: sama dengan declared
            'weight_kg'          => $validated['weight_kg'] ?? null,
            'condition'          => $validated['condition'] ?? null,
            'input_image_path'   => null,
            'recommendations'    => $recommendations,
            'is_demo_result'     => true, // Selalu true karena ini rule-based
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Analysis completed. Results are rule-based recommendations, not AI.',
            'data'    => $analysis,
        ], 201);
    }

    /**
     * Hasilkan rekomendasi berbasis aturan berdasarkan kategori plastik dan kondisinya.
     * Ini adalah fungsi deterministik, bukan AI/ML.
     *
     * @return array<int, array<string, string>>
     */
    private function generateRecommendations(string $category, ?string $condition): array
    {
        $recommendations = [];

        $baseRecommendations = [
            'PET' => [
                [
                    'type'        => 'craft',
                    'title'       => 'Kerajinan Botol Kreatif',
                    'description' => 'Botol PET dapat diubah menjadi pot tanaman, vas bunga, atau wadah penyimpanan.',
                    'difficulty'  => 'mudah',
                ],
                [
                    'type'        => 'recycle',
                    'title'       => 'Daur Ulang di Bank Sampah',
                    'description' => 'PET adalah salah satu plastik yang paling mudah didaur ulang dan bernilai tinggi.',
                    'difficulty'  => 'mudah',
                ],
                [
                    'type'        => 'donate',
                    'title'       => 'Donasikan ke Komunitas Ecobrick',
                    'description' => 'Botol PET cocok untuk ecobrick yang dapat digunakan sebagai bahan bangunan komunitas.',
                    'difficulty'  => 'mudah',
                ],
            ],
            'HDPE' => [
                [
                    'type'        => 'craft',
                    'title'       => 'Wadah Serbaguna',
                    'description' => 'HDPE (botol detergen/shampoo) bisa dijadikan pot tanaman atau tempat penyimpanan.',
                    'difficulty'  => 'mudah',
                ],
                [
                    'type'        => 'recycle',
                    'title'       => 'Daur Ulang Industri',
                    'description' => 'HDPE dapat didaur ulang menjadi pipa, papan kayu plastik, atau kemasan baru.',
                    'difficulty'  => 'sedang',
                ],
            ],
            'PP' => [
                [
                    'type'        => 'craft',
                    'title'       => 'Kerajinan Berbasis PP',
                    'description' => 'Polipropilen cocok untuk kerajinan yang memerlukan ketahanan panas.',
                    'difficulty'  => 'sedang',
                ],
                [
                    'type'        => 'recycle',
                    'title'       => 'Setor ke Pengepul PP',
                    'description' => 'PP didaur ulang menjadi kemasan makanan, ember, atau komponen otomotif.',
                    'difficulty'  => 'sedang',
                ],
            ],
            'LDPE' => [
                [
                    'type'        => 'craft',
                    'title'       => 'Tas Woven Plastik',
                    'description' => 'Film LDPE dapat dipilin dan dianyam menjadi tas atau keranjang kreatif.',
                    'difficulty'  => 'sedang',
                ],
                [
                    'type'        => 'recycle',
                    'title'       => 'Daur Ulang Film Plastik',
                    'description' => 'Film LDPE dapat didaur ulang menjadi kantong belanja atau pipa fleksibel.',
                    'difficulty'  => 'sulit',
                ],
            ],
            'MIXED' => [
                [
                    'type'        => 'sort',
                    'title'       => 'Pilah Terlebih Dahulu',
                    'description' => 'Plastik campuran perlu dipilah berdasarkan jenis sebelum dapat didaur ulang optimal.',
                    'difficulty'  => 'sedang',
                ],
                [
                    'type'        => 'donate',
                    'title'       => 'Donasikan ke Bank Sampah',
                    'description' => 'Bank sampah dapat membantu memilah dan mendaur ulang plastik campuran.',
                    'difficulty'  => 'mudah',
                ],
            ],
            'OTHER' => [
                [
                    'type'        => 'check',
                    'title'       => 'Identifikasi Jenis Plastik',
                    'description' => 'Periksa kode segitiga daur ulang pada kemasan untuk mengetahui jenis plastik yang tepat.',
                    'difficulty'  => 'mudah',
                ],
                [
                    'type'        => 'donate',
                    'title'       => 'Konsultasi Bank Sampah',
                    'description' => 'Bank sampah dapat membantu mengidentifikasi dan mendaur ulang jenis plastik lain.',
                    'difficulty'  => 'mudah',
                ],
            ],
        ];

        $recommendations = $baseRecommendations[$category] ?? $baseRecommendations['OTHER'];

        // Tambahkan catatan jika kondisi kotor
        if ($condition === 'dirty' || $condition === 'mixed') {
            array_unshift($recommendations, [
                'type'        => 'prep',
                'title'       => 'Bersihkan Terlebih Dahulu',
                'description' => 'Material dengan kondisi kotor perlu dicuci dan dikeringkan sebelum didaur ulang atau dijadikan kerajinan.',
                'difficulty'  => 'mudah',
            ]);
        }

        return $recommendations;
    }
}
