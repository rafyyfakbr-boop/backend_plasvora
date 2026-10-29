<?php

namespace App\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiVisionService
{
    protected ?string $apiKey;
    protected string $model;
    protected int $timeout;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey  = config('services.gemini.api_key');
        $this->model   = config('services.gemini.model', 'gemini-3.8-flash');
        $this->timeout = (int) config('services.gemini.timeout', 30);
    }

    /**
     * Menganalisis gambar sampah plastik menggunakan Google Gemini API.
     *
     * @param UploadedFile|string $image File upload atau path gambar lokal
     * @param string|null $declaredCategory Kategori awal yang dipilih user (opsional)
     * @param string|null $notes Catatan tambahan dari user (opsional)
     * @return array
     *
     * @throws Exception
     */
    public function analyzePlasticImage(UploadedFile|string $image, ?string $declaredCategory = null, ?string $notes = null): array
    {
        if (empty($this->apiKey)) {
            Log::error('GeminiVisionService: GEMINI_API_KEY is not configured.');
            throw new Exception('Layanan AI belum dikonfigurasi pada server.', 500);
        }

        // Siapkan MIME type dan base64 image data
        if ($image instanceof UploadedFile) {
            $mimeType  = $image->getMimeType() ?: 'image/jpeg';
            $imageB64  = base64_encode(file_get_contents($image->getRealPath()));
        } else {
            if (!file_exists($image)) {
                throw new Exception('File gambar tidak ditemukan di sistem.', 400);
            }
            $mimeType  = mime_content_type($image) ?: 'image/jpeg';
            $imageB64  = base64_encode(file_get_contents($image));
        }

        $systemInstruction = <<<TEXT
Anda adalah Senior Environmental & Polymer Vision Analyst untuk Plasvora, platform pengelolaan dan upcycling sampah plastik.
Tugas Anda adalah menganalisis gambar sampah plastik yang dikirimkan pengguna dengan cermat, objektif, dan realistis.

Prinsip analisis:
1. Identifikasi jenis plastik berdasarkan karakteristik visual nyata (transparansi, kekakuan, bentuk tutup, kode segitiga jika terlihat, tekstur).
2. Pilihan kategori polimer yang valid HANYA: 'PET', 'HDPE', 'PP', 'LDPE', 'OTHER', atau 'MIXED'.
3. Jangan mengarang kode resin atau jenis material jika foto buram, tidak jelas, atau bukan plastik.
   - Jika foto tidak cukup jelas atau bukan objek plastik yang jelas, gunakan kategori 'OTHER' atau 'MIXED' dan nyatakan ketidakpastian serta anjurkan konfirmasi manual pada deskripsi/catatan.
4. Estimasi melting_point dan avoided_emission yang ilmiah dan realistis sesuai polimer.
5. Berikan 2 sampai 4 ide upcycling (daur ulang kreatif) yang realistis beserta tingkat kesulitan ('Tingkat Mudah', 'Tingkat Menengah', atau 'Tingkat Sulit'), alat/bahan yang dibutuhkan, langkah pembuatan, dan catatan keselamatan kerja.
TEXT;

        $promptText = "Analisis objek sampah plastik pada gambar ini.";
        if ($declaredCategory) {
            $promptText .= " Kategori awal yang dilaporkan pengguna: {$declaredCategory}. Verifikasi apakah sesuai.";
        }
        if ($notes) {
            $promptText .= " Catatan tambahan pengguna: {$notes}.";
        }

        // Schema terstruktur (OpenAPI format)
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'estimated_category' => [
                    'type' => 'STRING',
                    'description' => 'Jenis polimer plastik yang teridentifikasi. Harus salah satu dari: PET, HDPE, PP, LDPE, OTHER, MIXED.',
                ],
                'material_title' => [
                    'type' => 'STRING',
                    'description' => 'Nama judul deskriptif material, contoh: Botol Plastik PET Bening, Tutup Botol HDPE.',
                ],
                'polymer_code' => [
                    'type' => 'STRING',
                    'description' => 'Kode daur ulang plastik, contoh: Tipe #1 (PET), Tipe #2 (HDPE), Tipe #5 (PP).',
                ],
                'melting_point' => [
                    'type' => 'STRING',
                    'description' => 'Titik leleh material, contoh: 250° - 260° C.',
                ],
                'avoided_emission' => [
                    'type' => 'STRING',
                    'description' => 'Perkiraan emisi karbon yang dapat dicegah, contoh: ~2.1 kg CO₂/kg.',
                ],
                'qualitative_note' => [
                    'type' => 'STRING',
                    'description' => 'Deskripsi visual objek, bukti visual perkiraan, tingkat keyakinan, dan anjuran konfirmasi manual.',
                ],
                'ideas' => [
                    'type' => 'ARRAY',
                    'description' => 'Daftar ide upcycling / daur ulang kreatif yang relevan.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'id' => ['type' => 'STRING', 'description' => 'ID unik ide, contoh: idea_1.'],
                            'title' => ['type' => 'STRING', 'description' => 'Judul ide kerajinan/upcycling.'],
                            'difficulty' => ['type' => 'STRING', 'description' => 'Tingkat Mudah, Tingkat Menengah, atau Tingkat Sulit.'],
                            'description' => ['type' => 'STRING', 'description' => 'Penjelasan ide upcycling.'],
                            'tools' => ['type' => 'STRING', 'description' => 'Alat dan bahan yang dibutuhkan.'],
                            'steps' => ['type' => 'STRING', 'description' => 'Langkah-langkah pembuatan.'],
                            'safety_notes' => ['type' => 'STRING', 'description' => 'Catatan keselamatan saat pengerjaan.'],
                        ],
                        'required' => ['id', 'title', 'difficulty', 'description'],
                    ],
                ],
            ],
            'required' => [
                'estimated_category',
                'material_title',
                'polymer_code',
                'melting_point',
                'avoided_emission',
                'qualitative_note',
                'ideas',
            ],
        ];

        $requestBody = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => $promptText],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data'      => $imageB64,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'       => 0.2,
                'response_mime_type' => 'application/json',
                'response_schema'    => $schema,
            ],
        ];

        $endpoint = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->timeout)
            ->post($endpoint, $requestBody);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('GeminiVisionService Connection Exception: ' . $e->getMessage());
            throw new Exception('Gagal menghubungi Gemini API (Koneksi timeout atau jaringan bermasalah).', 504);
        } catch (\Throwable $e) {
            Log::error('GeminiVisionService Request Exception: ' . $e->getMessage());
            throw new Exception('Terjadi kesalahan saat menghubungi layanan AI.', 500);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            Log::error('GeminiVisionService Auth Error: ' . $response->body());
            throw new Exception('Autentikasi Gemini API gagal. Periksa konfigurasi API key.', 401);
        }

        if ($response->status() === 429) {
            Log::warning('GeminiVisionService Rate Limit: ' . $response->body());
            throw new Exception('Batas permintaan Gemini API tercapai (Rate Limit / Quota Exceeded). Coba beberapa saat lagi.', 429);
        }

        if ($response->failed()) {
            Log::error('GeminiVisionService Server Error (' . $response->status() . '): ' . $response->body());
            throw new Exception('Gemini API mengembalikan kesalahan server (Status: ' . $response->status() . ').', 502);
        }

        $responseData = $response->json();
        $rawText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($rawText)) {
            Log::error('GeminiVisionService Empty Parts: ' . json_encode($responseData));
            throw new Exception('Layanan AI tidak mengembalikan konten hasil analisis.', 502);
        }

        $parsed = json_decode($rawText, true);
        if (!is_array($parsed)) {
            Log::error('GeminiVisionService Invalid JSON: ' . $rawText);
            throw new Exception('Format respon AI tidak valid.', 502);
        }

        return $this->validateAndNormalizeAiResponse($parsed, $declaredCategory);
    }

    /**
     * Memvalidasi dan menormalkan data hasil parsing AI.
     *
     * @param array $data
     * @param string|null $declaredCategory
     * @return array
     *
     * @throws Exception
     */
    public function validateAndNormalizeAiResponse(array $data, ?string $declaredCategory = null): array
    {
        $allowedCategories = ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'];

        $estimatedCategory = strtoupper(trim((string) ($data['estimated_category'] ?? 'OTHER')));
        if (!in_array($estimatedCategory, $allowedCategories, true)) {
            $estimatedCategory = 'OTHER';
        }

        $materialTitle = trim((string) ($data['material_title'] ?? "Material Plastik {$estimatedCategory}"));
        $polymerCode   = trim((string) ($data['polymer_code'] ?? "Tipe Polimer {$estimatedCategory}"));
        $meltingPoint  = trim((string) ($data['melting_point'] ?? '130° - 260° C'));
        $avoidedEmission = trim((string) ($data['avoided_emission'] ?? '~1.8 kg CO₂/kg'));
        $qualitativeNote = trim((string) ($data['qualitative_note'] ?? 'Hasil identifikasi visual objek plastik oleh Gemini AI.'));

        $rawIdeas = $data['ideas'] ?? [];
        if (!is_array($rawIdeas) || empty($rawIdeas)) {
            $rawIdeas = [
                [
                    'id'          => 'idea_1',
                    'title'       => 'Pemanfaatan Wadah dan Kerajinan',
                    'difficulty'  => 'Tingkat Mudah',
                    'description' => 'Dapat dibersihkan dan dialihfungsikan menjadi pot tanaman atau wadah penyimpanan.',
                ],
            ];
        }

        $normalizedIdeas = [];
        foreach ($rawIdeas as $index => $idea) {
            if (!is_array($idea)) {
                continue;
            }
            $difficulty = trim((string) ($idea['difficulty'] ?? 'Tingkat Menengah'));
            if (!str_contains($difficulty, 'Tingkat')) {
                $difficulty = 'Tingkat ' . ucfirst($difficulty);
            }

            $normalizedIdeas[] = [
                'id'           => (string) ($idea['id'] ?? 'idea_' . ($index + 1)),
                'title'        => (string) ($idea['title'] ?? 'Kerajinan Upcycling'),
                'difficulty'   => $difficulty,
                'description'  => (string) ($idea['description'] ?? 'Pemanfaatan ramah lingkungan.'),
                'tools'        => isset($idea['tools']) ? (string) $idea['tools'] : null,
                'steps'        => isset($idea['steps']) ? (string) $idea['steps'] : null,
                'safety_notes' => isset($idea['safety_notes']) ? (string) $idea['safety_notes'] : null,
            ];
        }

        return [
            'estimated_category' => $estimatedCategory,
            'material_title'     => $materialTitle,
            'polymer_code'       => $polymerCode,
            'melting_point'      => $meltingPoint,
            'avoided_emission'   => $avoidedEmission,
            'qualitative_note'   => $qualitativeNote,
            'ideas'              => $normalizedIdeas,
            'is_ai_generated'    => true,
        ];
    }
}

