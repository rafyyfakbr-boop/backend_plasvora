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
    protected ?string $fallbackModel;
    protected int $timeout;
    protected int $connectTimeout;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey         = config('services.gemini.api_key');
        $this->model          = config('services.gemini.model', 'gemini-3.5-flash');
        $this->fallbackModel  = config('services.gemini.fallback_model', 'gemini-3.5-flash-lite');
        $this->timeout        = (int) config('services.gemini.timeout', 20);
        $this->connectTimeout = (int) config('services.gemini.connect_timeout', 5);
    }

    /** Ukuran maksimum gambar (bytes) sebelum dikompresi (3 MB). */
    protected int $maxImageBytes = 3 * 1024 * 1024;

    /** Resolusi maksimum panjang sisi gambar (piksel). */
    protected int $maxImageDimension = 1200;

    /** Jumlah maksimum percobaan ulang untuk error transient (503/network). */
    protected int $maxRetries = 3;

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
        @set_time_limit(120);

        if (empty($this->apiKey)) {
            Log::error('GeminiVisionService: GEMINI_API_KEY is not configured.');
            throw new Exception('Layanan AI belum dikonfigurasi pada server.', 500);
        }

        // Siapkan MIME type dan base64 image data (dengan kompresi jika perlu)
        if ($image instanceof UploadedFile) {
            $sourcePath = $image->getRealPath();
            $mimeType   = $image->getMimeType() ?: 'image/jpeg';
        } else {
            if (!file_exists($image)) {
                throw new Exception('File gambar tidak ditemukan di sistem.', 400);
            }
            $sourcePath = $image;
            $mimeType   = mime_content_type($image) ?: 'image/jpeg';
        }

        $imageBytes = file_get_contents($sourcePath);
        if ($imageBytes === false) {
            throw new Exception('Gagal membaca file gambar.', 400);
        }

        // Kompres gambar jika ukurannya melebihi batas atau resolusinya terlalu besar
        $imageBytes = $this->compressImageIfNeeded($imageBytes, $mimeType);
        $imageB64   = base64_encode($imageBytes);
        $imageSizeKb = (int) (strlen($imageBytes) / 1024);

        $systemInstruction = <<<TEXT
Anda adalah Senior Environmental & Polymer Vision Analyst untuk Plasvora, platform pengelolaan dan upcycling sampah plastik sirkular.
Tugas Anda adalah menganalisis gambar yang dikirimkan pengguna dengan cermat, objektif, berbasis sains polimer, dan realistis.

ALUR KERJA MULTI-KOMPONEN & CONTEXT-AWARE:

PENDEKATAN IDENTIFIKASI:
Ubah pendekatan dari SATU OBJEK = SATU MATERIAL menjadi:
SATU FOTO → IDENTIFIKASI OBJEK UTUH → PEMECAHAN KOMPONEN FISIK NYATA → IDENTIFIKASI MATERIAL SETIAP KOMPONEN SECARA TERPISAH.

TAHAP 1 — IDENTIFIKASI VISUAL OBJEK UTUH & KOMPONEN:
1. Objek Utama: Identifikasi nama objek utuh secara deskriptif netral (contoh: 'Botol air mineral plastik', 'Gelas cup minuman berpembungkus', 'Wadah jerigen detergen'). DILARANG mengutip merek dagang komersial (RECITATION filter).
2. Pemecahan Komponen Fisik:
   - Amati seluruh bagian objek yang BENAR-BENAR TERLIHAT pada gambar (misal: 'Badan botol', 'Tutup botol', 'Label kemasan', 'Sedotan').
   - DILARANG mengarang komponen yang TIDAK TERLIHAT di foto. Contoh: jika foto hanya menampilkan tutup botol, HANYA kenali tutup botol sebagai satu-satunya komponen! Jangan mengarang badan botol yang tidak ada di foto. Jika foto hanya botol tanpa tutup, jangan buat komponen tutup!
   - JANGAN menggabungkan komponen yang berbeda polimer menjadi satu material tunggal.
3. Polimer Setiap Komponen:
   - Badan botol air mineral bening: Dugaan utama PET (#1). Jika simbol kode resin tidak terbaca di foto, gunakan confidence = 'medium', basis = 'visual_estimate', verification_note = 'Periksa simbol daur ulang segitiga angka 1 (PET) pada dasar botol.'
   - Tutup botol plastik kaku: Material kandidat HDPE (#2) atau PP (#5). JANGAN menetapkan salah satunya secara pasti jika kode fisik tidak terlihat! Gunakan material_type = 'uncertain', candidate_materials = ['HDPE (#2)', 'PP (#5)'], confidence = 'low', verification_note = 'Periksa kode embos pada bagian dalam tutup botol.'
   - Label botol (jika terlihat): Kenali sebagai komponen terpisah jika terlihat jelas (biasanya plastik film PP, PVC, atau PET film tipis). Jika tidak yakin, tandai uncertain.
4. Pemanfaatan Catatan Pengguna:
   - Catatan pengguna adalah konteks pendukung untuk membedakan komponen (contoh: "botol dan tutup botol plastik" menandakan objek terdiri dari 2 bagian).
   - Analisis foto dan catatan BERSAMA:
     * Jika catatan pengguna dan visual foto konsisten, gunakan sebagai konteks penguat.
     * Jika catatan menyebut komponen yang TIDAK ADA di foto (contoh: catatan "botol air mineral lengkap" tapi foto hanya tutup botol), JANGAN mengarang badan botol! Identifikasi hanya apa yang tampak di foto, catat ketidaksinkronan di identification_summary dan minta pengguna melakukan verifikasi.

TAHAP 2 — PENENTUAN STATUS & KELAYAKAN:
- Jika material_type == 'NON_PLASTIC' (logam, kaca, kardus, dsb.):
  * status = 'unsupported_material', is_plastic = false, ideas = [], recommendations = []
- Jika material_type == 'UNCERTAIN' (foto buram/gelap/tidak dapat diidentifikasi):
  * status = 'uncertain_material', is_plastic = false, ideas = [], recommendations = []
- Jika material_type == 'PLASTIC':
  * status = 'success', is_plastic = true

TAHAP 3 — REKOMENDASI UPCYCLING REALISTIS & SESUAI KOMPONEN (MAKSIMAL 3 IDE TERBAIK):
1. Keterikatan Komponen: Setiap rekomendasi WAJIB menyertakan 'component_ids' yang merujuk pada komponen mana yang dipakai (contoh: ['component_body'] untuk pot tanaman, ['component_cap'] untuk kerajinan tutup botol). JANGAN merekomendasikan ide tutup botol tapi dilabeli untuk badan botol!
2. Pertimbangan Kuantitas (suitable_quantity):
   - 'single_item': untuk 1 benda bekas saja (contoh: 1 botol menjadi pot tanaman sukulen mandiri, corong air, wadah alat tulis).
   - 'small_batch': untuk kumpulan kecil beberapa buah (contoh: beberapa tutup botol untuk tatakan gelas/coaster, gantungan kunci).
   - 'bulk': untuk material dalam jumlah besar yang membutuhkan pengumpulan massal.
   REALISME: Jika pengguna hanya memiliki 1 botol, UTAMAKAN ide 'single_item' dan 'household'. DILARANG menampilkan furnitur daur ulang atau ubin komposit sebagai rekomendasi utama untuk 1 botol bekas!
3. Skala Pengolahan (scale):
   - 'household': skala rumah tangga / DIY mandiri dengan alat sederhana.
   - 'community': skala komunitas / bank sampah.
   - 'industrial': skala pabrik / industri yang butuh mesin peleburan.
4. Keselamatan Kerja:
   - DILARANG menyarankan pembakaran plastik atau pelelehan sendiri di oven dapur rumah karena mengeluarkan gas karsinogenik beracun.
   - Sertakan alat (tools), langkah praktis (steps), dan catatan keselamatan (safety_notes).
TEXT;

        $promptText = "Lakukan analisis menyeluruh terhadap sampah plastik pada gambar ini dengan pendekatan multi-komponen.";
        if ($declaredCategory) {
            $promptText .= " Kategori awal yang dipilih pengguna: {$declaredCategory} (jadikan referensi pendukung visual).";
        }
        if ($notes) {
            $promptText .= " Catatan tambahan pengguna: \"{$notes}\". Integrasikan catatan ini dengan bukti visual foto nyata. Bedakan komponen jika pengguna menyebutkan bagian-bagian fisik, namun jangan mengarang komponen yang tidak tampak pada gambar.";
        }

        // Schema terstruktur OpenAPI untuk response Gemini
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'status' => [
                    'type' => 'STRING',
                    'enum' => ['success', 'unsupported_material', 'uncertain_material'],
                    'description' => 'success jika plastik teranalisis, unsupported_material jika bukan plastik, uncertain_material jika objek buram/tidak pasti.',
                ],
                'is_plastic' => [
                    'type' => 'BOOLEAN',
                    'description' => 'True jika sampah plastik, False jika bukan plastik atau tidak pasti.',
                ],
                'object_name' => [
                    'type' => 'STRING',
                    'description' => 'Nama objek utuh yang teridentifikasi, contoh: Botol air mineral plastik, Gelas cup plastik bening, Tutup botol plastik.',
                ],
                'object_description' => [
                    'type' => 'STRING',
                    'description' => 'Deskripsi fisik netral objek yang terlihat tanpa merek komersial.',
                ],
                'identification_summary' => [
                    'type' => 'STRING',
                    'description' => 'Ringkasan identifikasi visual dan catatan kecocokan dengan input pengguna.',
                ],
                'components' => [
                    'type' => 'ARRAY',
                    'description' => 'Daftar komponen fisik yang benar-benar terlihat pada objek.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'id' => ['type' => 'STRING', 'description' => 'ID komponen, misal: component_body, component_cap, component_label.'],
                            'part_name' => ['type' => 'STRING', 'description' => 'Nama bagian fisik, misal: Badan botol, Tutup botol, Label plastik.'],
                            'material_type' => ['type' => 'STRING', 'description' => 'Jenis polimer material (PET, HDPE, PP, LDPE, uncertain, MIXED, OTHER).'],
                            'resin_code' => ['type' => 'STRING', 'description' => 'Kode resin daur ulang (misal: #1, #2, #5, atau strip - jika belum pasti).'],
                            'confidence' => ['type' => 'STRING', 'enum' => ['high', 'medium', 'low', 'uncertain'], 'description' => 'Tingkat keyakinan identifikasi.'],
                            'identification_basis' => ['type' => 'STRING', 'description' => 'Dasar identifikasi (visual_estimate, resin_code_visible, user_note_context, uncertain).'],
                            'candidate_materials' => [
                                'type' => 'ARRAY',
                                'items' => ['type' => 'STRING'],
                                'description' => 'Daftar polimer kandidat jika belum pasti, misal [\"HDPE (#2)\", \"PP (#5)\"].',
                            ],
                            'verification_note' => ['type' => 'STRING', 'description' => 'Petunjuk verifikasi fisik oleh pengguna (misal: periksa kode di bawah botol).'],
                            'melting_point' => ['type' => 'STRING', 'description' => 'Titik leleh polimer komponen ini, misal: 250° - 260° C.'],
                        ],
                        'required' => ['id', 'part_name', 'material_type', 'confidence', 'verification_note'],
                    ],
                ],
                'recommendations' => [
                    'type' => 'ARRAY',
                    'description' => 'Daftar maksimal 3 rekomendasi upcycling yang relevan dan realistis sesuai komponen dan jumlah bahan.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'id' => ['type' => 'STRING', 'description' => 'ID ide, misal: idea_1.'],
                            'title' => ['type' => 'STRING', 'description' => 'Judul ide pemanfaatan/upcycling.'],
                            'difficulty' => ['type' => 'STRING', 'enum' => ['Tingkat Mudah', 'Tingkat Menengah', 'Tingkat Sulit']],
                            'description' => ['type' => 'STRING', 'description' => 'Deskripsi ide.'],
                            'component_ids' => [
                                'type' => 'ARRAY',
                                'items' => ['type' => 'STRING'],
                                'description' => 'ID komponen yang digunakan ide ini, misal [\"component_body\"].',
                            ],
                            'why_it_fits' => ['type' => 'STRING', 'description' => 'Alasan ide cocok dengan komponen spesifik objek.'],
                            'suitable_quantity' => ['type' => 'STRING', 'enum' => ['single_item', 'small_batch', 'bulk']],
                            'scale' => ['type' => 'STRING', 'enum' => ['household', 'community', 'industrial']],
                            'tools' => ['type' => 'STRING', 'description' => 'Alat dan bahan yang dibutuhkan.'],
                            'steps' => ['type' => 'STRING', 'description' => 'Langkah pembuatan praktis.'],
                            'safety_notes' => ['type' => 'STRING', 'description' => 'Catatan keselamatan kerja.'],
                        ],
                        'required' => ['id', 'title', 'difficulty', 'description', 'component_ids', 'suitable_quantity', 'scale'],
                    ],
                ],
                // Field kompatibilitas backward untuk klien & pengujian lama
                'detected_object' => ['type' => 'STRING', 'description' => 'Nama objek umum.'],
                'material_type' => ['type' => 'STRING', 'enum' => ['PLASTIC', 'NON_PLASTIC', 'UNCERTAIN']],
                'message' => ['type' => 'STRING'],
                'estimated_category' => ['type' => 'STRING', 'enum' => ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED']],
                'material_title' => ['type' => 'STRING'],
                'polymer_code' => ['type' => 'STRING'],
                'melting_point' => ['type' => 'STRING'],
                'avoided_emission' => ['type' => 'STRING'],
                'qualitative_note' => ['type' => 'STRING'],
                'ideas' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'id' => ['type' => 'STRING'],
                            'title' => ['type' => 'STRING'],
                            'difficulty' => ['type' => 'STRING', 'enum' => ['Tingkat Mudah', 'Tingkat Menengah', 'Tingkat Sulit']],
                            'description' => ['type' => 'STRING'],
                            'why_it_fits' => ['type' => 'STRING'],
                            'tools' => ['type' => 'STRING'],
                            'steps' => ['type' => 'STRING'],
                            'safety_notes' => ['type' => 'STRING'],
                        ],
                        'required' => ['id', 'title', 'difficulty', 'description'],
                    ],
                ],
            ],
            'required' => [
                'status',
                'is_plastic',
                'material_type',
                'message',
            ],
        ];

        // Format raw REST Google GenAI API (standard wire format)
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
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data'     => $imageB64,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema,
            ],
        ];

        // Siapkan daftar model yang akan dicoba (primary model lalu fallback jika ada)
        $modelsToTry = array_values(array_filter(array_unique([
            $this->model,
            $this->fallbackModel,
        ])));

        $response      = null;
        $lastException = null;

        foreach ($modelsToTry as $currentModel) {
            $endpoint = "{$this->baseUrl}/models/{$currentModel}:generateContent";

            Log::info('GeminiVisionService: Sending request', [
                'model'        => $currentModel,
                'image_kb'     => $imageSizeKb,
                'mime_type'    => $mimeType,
                'has_category' => !empty($declaredCategory),
            ]);

            $attempt     = 0;
            $maxAttempts = 2; // Coba 2 kali per model
            $retryDelays = [1, 2];

            while ($attempt < $maxAttempts) {
                $attempt++;

                try {
                    $response = Http::withHeaders([
                        'Content-Type'   => 'application/json',
                        'x-goog-api-key' => $this->apiKey,
                    ])
                    ->withOptions([
                        'expect' => false,
                        'curl'   => [
                            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        ],
                    ])
                    ->connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->post($endpoint, $requestBody);
                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    Log::warning("GeminiVisionService Connection Exception ({$currentModel} attempt {$attempt}/{$maxAttempts}): " . $e->getMessage());
                    $lastException = new Exception('Koneksi ke Gemini API terputus atau timeout saat menunggu respon. Periksa koneksi internet Anda.', 504);

                    if ($attempt < $maxAttempts) {
                        $delay = $retryDelays[$attempt - 1] ?? 1;
                        Log::info("GeminiVisionService: Retry {$currentModel} #{$attempt} setelah {$delay}s...");
                        sleep($delay);
                        continue;
                    }

                    // Model ini habis percobaan timeout, lanjut coba model fallback jika tersedia
                    break;
                } catch (\Throwable $e) {
                    Log::error('GeminiVisionService Request Exception: ' . $e->getMessage());
                    throw new Exception('Terjadi kesalahan jaringan saat menghubungi layanan AI.', 500);
                }

                // ---- Evaluasi respons HTTP ----

                if ($response->status() === 400) {
                    $errData = $response->json();
                    $msg = $errData['error']['message'] ?? 'Permintaan analisis tidak valid.';
                    Log::error('GeminiVisionService Bad Request 400: ' . $response->body());
                    throw new Exception('Format permintaan analisis ditolak oleh AI: ' . $msg, 400);
                }

                if ($response->status() === 401 || $response->status() === 403) {
                    Log::error('GeminiVisionService Auth Error (' . $response->status() . '): ' . $response->body());
                    throw new Exception('Autentikasi Gemini API gagal. Periksa konfigurasi API key.', 401);
                }

                if ($response->status() === 429) {
                    Log::warning('GeminiVisionService Rate Limit 429: ' . $response->body());
                    throw new Exception('Batas permintaan Gemini API tercapai (Rate Limit / Quota Exceeded). Coba beberapa saat lagi.', 429);
                }

                if ($response->status() === 503) {
                    Log::warning("GeminiVisionService Unavailable 503 ({$currentModel} attempt {$attempt}/{$maxAttempts}).");
                    $lastException = new Exception('Model AI saat ini sedang mengalami lonjakan beban tinggi (High Demand). Silakan coba sesaat lagi.', 503);

                    if ($attempt < $maxAttempts) {
                        $delay = $retryDelays[$attempt - 1] ?? 1;
                        Log::info("GeminiVisionService: Retry {$currentModel} #{$attempt} setelah {$delay}s karena 503...");
                        sleep($delay);
                        continue;
                    }

                    // Model ini 503, lanjut coba model fallback
                    break;
                }

                if ($response->failed()) {
                    Log::error('GeminiVisionService Server Error (' . $response->status() . '): ' . $response->body());
                    $lastException = new Exception('Layanan Gemini mengembalikan kesalahan server (Status: ' . $response->status() . ').', 502);
                    break;
                }

                // Respons sukses (200 OK)
                Log::info("GeminiVisionService: Request succeeded with {$currentModel} on attempt {$attempt}.");
                break 2; // Keluar dari kedua loop (while dan foreach)
            }
        }

        if (!$response || $response->failed()) {
            if ($lastException) {
                throw $lastException;
            }
            throw new Exception('Gagal menghubungi Gemini API setelah mencoba model cadangan.', 504);
        }

        $responseData = $response->json();
        $candidate = $responseData['candidates'][0] ?? null;

        if (!$candidate) {
            $blockReason = $responseData['promptFeedback']['blockReason'] ?? null;
            if ($blockReason) {
                Log::warning("GeminiVisionService: Prompt blocked with reason: {$blockReason}");
                throw new Exception("Permintaan analisis disaring oleh kebijakan AI ({$blockReason}).", 422);
            }
            Log::error('GeminiVisionService Empty Candidates: ' . json_encode($responseData));
            throw new Exception('Layanan AI tidak mengembalikan kandidat hasil analisis.', 502);
        }

        $finishReason = $candidate['finishReason'] ?? 'STOP';
        if ($finishReason === 'RECITATION') {
            Log::warning("GeminiVisionService: Recitation Filter triggered: " . json_encode($candidate));
            throw new Exception('Foto mengandung teks merek atau materi berhak cipta yang disaring oleh filter AI. Coba ambil foto dari sudut lain tanpa menyorot label merek secara frontal.', 422);
        }

        if ($finishReason === 'SAFETY') {
            Log::warning("GeminiVisionService: Safety Filter triggered: " . json_encode($candidate));
            throw new Exception('Gambar tidak dapat diproses karena terdeteksi oleh kebijakan keselamatan AI. Harap gunakan foto sampah plastik yang wajar.', 422);
        }

        if ($finishReason === 'BLOCKLIST' || $finishReason === 'PROHIBITED_CONTENT') {
            Log::warning("GeminiVisionService: Prohibited Content: " . json_encode($candidate));
            throw new Exception('Konten gambar tidak diizinkan untuk diproses oleh sistem AI.', 422);
        }

        $rawText = $candidate['content']['parts'][0]['text'] ?? null;

        if (empty($rawText)) {
            // Coba ambil dari 'text' di 'candidates' jika struktur berbeda
            $rawText = $candidate['text'] ?? null;
        }

        if (empty($rawText)) {
            Log::error('GeminiVisionService Empty Text Parts: ' . json_encode($responseData));
            throw new Exception('Layanan AI tidak mengembalikan teks konten analisis.', 502);
        }

        $parsed = json_decode($rawText, true);
        if (!is_array($parsed)) {
            Log::error('GeminiVisionService Invalid JSON: ' . $rawText);
            throw new Exception('Format respon AI tidak valid.', 502);
        }

        return $this->validateAndNormalizeAiResponse($parsed, $declaredCategory);
    }

    /**
     * Mengompresi gambar jika ukurannya melebihi batas atau dimensinya terlalu besar.
     * Membutuhkan ekstensi PHP GD.
     *
     * @param string $imageBytes Data gambar mentah
     * @param string $mimeType   MIME type gambar
     * @return string Data gambar (sudah dikompresi atau asli jika tidak perlu)
     */
    protected function compressImageIfNeeded(string $imageBytes, string $mimeType): string
    {
        $originalSize = strlen($imageBytes);

        // Lewati kompresi jika GD tidak tersedia atau bukan gambar yang didukung
        if (!extension_loaded('gd')) {
            Log::warning('GeminiVisionService: Ekstensi GD tidak tersedia, kompresi gambar dilewati.');
            return $imageBytes;
        }

        $supportedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $supportedMimes, true)) {
            return $imageBytes;
        }

        $gdImage = @imagecreatefromstring($imageBytes);
        if ($gdImage === false) {
            Log::warning('GeminiVisionService: Gagal membuat GD image, kompresi dilewati.');
            return $imageBytes;
        }

        $width  = imagesx($gdImage);
        $height = imagesy($gdImage);

        $needsResize = $width > $this->maxImageDimension || $height > $this->maxImageDimension;
        $needsCompression = $originalSize > $this->maxImageBytes;

        if (!$needsResize && !$needsCompression) {
            imagedestroy($gdImage);
            return $imageBytes;
        }

        // Hitung dimensi baru dengan mempertahankan aspect ratio
        if ($needsResize) {
            $ratio     = min($this->maxImageDimension / $width, $this->maxImageDimension / $height);
            $newWidth  = (int) ($width * $ratio);
            $newHeight = (int) ($height * $ratio);

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            // Pertahankan transparansi untuk PNG
            if ($mimeType === 'image/png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
            }

            imagecopyresampled($resized, $gdImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($gdImage);
            $gdImage = $resized;
        }

        // Encode ke JPEG (output selalu JPEG untuk konsistensi ukuran)
        ob_start();
        imagejpeg($gdImage, null, 80);
        $compressed = ob_get_clean();
        imagedestroy($gdImage);

        $compressedSize = strlen($compressed);
        Log::info('GeminiVisionService: Image compressed', [
            'original_kb'   => (int) ($originalSize / 1024),
            'compressed_kb' => (int) ($compressedSize / 1024),
        ]);

        return $compressed;
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
        $status       = trim((string) ($data['status'] ?? 'success'));
        $materialType = strtoupper(trim((string) ($data['material_type'] ?? 'PLASTIC')));
        $isPlastic    = isset($data['is_plastic']) ? (bool) $data['is_plastic'] : ($materialType === 'PLASTIC');
        $detectedObj  = trim((string) ($data['detected_object'] ?? 'Objek Sampah'));

        // Kasus 1: Objek atau jenis material tidak pasti / buram / gambar kurang jelas
        if ($status === 'uncertain_material' || $materialType === 'UNCERTAIN') {
            $msg = trim((string) ($data['message'] ?? ''));
            if (empty($msg) || str_contains(strtolower($msg), 'berhasil')) {
                $msg = 'Jenis material belum dapat dipastikan. Coba foto objek lebih dekat dengan pencahayaan yang lebih jelas.';
            }

            return [
                'status'             => 'uncertain_material',
                'is_plastic'         => false,
                'detected_object'    => $detectedObj ?: 'Objek tidak jelas',
                'material_type'      => 'UNCERTAIN',
                'message'            => $msg,
                'estimated_category' => 'OTHER',
                'material_title'     => 'Material Belum Pasti',
                'polymer_code'       => '-',
                'melting_point'      => '-',
                'avoided_emission'   => '-',
                'qualitative_note'   => trim((string) ($data['qualitative_note'] ?? 'Karakteristik visual objek pada gambar belum cukup jelas untuk menentukan jenis polimer.')),
                'ideas'              => [],
                'is_ai_generated'    => true,
            ];
        }

        // Kasus 2: Objek yang jelas BUKAN sampah plastik (logam, kaca, kardus, dsb.)
        if ($status === 'unsupported_material' || $materialType === 'NON_PLASTIC' || !$isPlastic) {
            $msg = trim((string) ($data['message'] ?? ''));
            if (empty($msg) || str_contains(strtolower($msg), 'berhasil')) {
                $msg = 'Objek ini tampaknya bukan sampah plastik. Silakan foto sampah plastik untuk mendapatkan rekomendasi upcycling.';
            }

            return [
                'status'             => 'unsupported_material',
                'is_plastic'         => false,
                'detected_object'    => $detectedObj ?: 'Objek bukan plastik',
                'material_type'      => 'NON_PLASTIC',
                'message'            => $msg,
                'estimated_category' => 'OTHER',
                'material_title'     => trim((string) ($data['material_title'] ?? 'Bukan Sampah Plastik')),
                'polymer_code'       => '-',
                'melting_point'      => '-',
                'avoided_emission'   => '-',
                'qualitative_note'   => trim((string) ($data['qualitative_note'] ?? 'Objek visual teridentifikasi sebagai material non-plastik.')),
                'ideas'              => [], // Wajib kosong untuk non-plastik
                'is_ai_generated'    => true,
            ];
        }

        // Kasus 3: Objek plastik valid
        $allowedCategories = ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'];

        $objectName = trim((string) ($data['object_name'] ?? $detectedObj));
        if (empty($objectName) || $objectName === 'Objek Sampah') {
            $objectName = $detectedObj ?: 'Sampah Plastik';
        }
        $objectDesc = trim((string) ($data['object_description'] ?? ''));
        $identSummary = trim((string) ($data['identification_summary'] ?? ''));

        // Normalisasi Komponen Material (Multi-Component)
        $rawComponents = $data['components'] ?? [];
        $normalizedComponents = [];

        if (is_array($rawComponents) && !empty($rawComponents)) {
            foreach ($rawComponents as $idx => $comp) {
                if (!is_array($comp)) {
                    continue;
                }
                $compId = trim((string) ($comp['id'] ?? 'component_' . ($idx + 1)));
                $partName = trim((string) ($comp['part_name'] ?? 'Bagian ' . ($idx + 1)));
                $matType = trim((string) ($comp['material_type'] ?? 'uncertain'));
                $resinCode = isset($comp['resin_code']) ? trim((string) $comp['resin_code']) : null;
                if ($resinCode === '' || $resinCode === '-' || strtolower((string)$resinCode) === 'null') {
                    $resinCode = null;
                }
                $conf = strtolower(trim((string) ($comp['confidence'] ?? 'medium')));
                if (!in_array($conf, ['high', 'medium', 'low', 'uncertain'], true)) {
                    $conf = 'medium';
                }
                $basis = trim((string) ($comp['identification_basis'] ?? 'visual_estimate'));
                $candidates = [];
                if (isset($comp['candidate_materials']) && is_array($comp['candidate_materials'])) {
                    foreach ($comp['candidate_materials'] as $c) {
                        if (!empty($c)) {
                            $candidates[] = trim((string) $c);
                        }
                    }
                }
                $verifNote = trim((string) ($comp['verification_note'] ?? 'Periksa kode daur ulang segitiga pada bagian ini.'));
                $meltingPt = trim((string) ($comp['melting_point'] ?? ''));
                if (empty($meltingPt)) {
                    $meltingPt = $this->resolveDefaultMeltingPoint($matType);
                }

                $normalizedComponents[] = [
                    'id'                   => $compId,
                    'part_name'            => $partName,
                    'material_type'        => $matType,
                    'resin_code'           => $resinCode,
                    'confidence'           => $conf,
                    'identification_basis' => $basis,
                    'candidate_materials'  => $candidates,
                    'verification_note'    => $verifNote,
                    'melting_point'        => $meltingPt,
                ];
            }
        }

        // Tentukan perkiraan kategori polimer makro
        $rawCat = strtoupper(trim((string) ($data['estimated_category'] ?? '')));
        if (empty($rawCat)) {
            if (count($normalizedComponents) > 1) {
                $rawCat = 'MIXED';
            } elseif (count($normalizedComponents) === 1) {
                $rawCat = strtoupper($normalizedComponents[0]['material_type']);
            }
        }

        $estimatedCategory = 'OTHER';
        if (in_array($rawCat, $allowedCategories, true)) {
            $estimatedCategory = $rawCat;
        } elseif (str_contains($rawCat, 'HDPE')) {
            $estimatedCategory = 'HDPE';
        } elseif (str_contains($rawCat, 'PET')) {
            $estimatedCategory = 'PET';
        } elseif (str_contains($rawCat, 'PP')) {
            $estimatedCategory = 'PP';
        } elseif (str_contains($rawCat, 'LDPE')) {
            $estimatedCategory = 'LDPE';
        } elseif (str_contains($rawCat, 'MIXED')) {
            $estimatedCategory = 'MIXED';
        }

        // Jika komponen belum ada sama sekali, buat komponen default tunggal
        if (empty($normalizedComponents)) {
            $compMelting = trim((string) ($data['melting_point'] ?? $this->resolveDefaultMeltingPoint($estimatedCategory)));
            $normalizedComponents[] = [
                'id'                   => 'component_main',
                'part_name'            => $detectedObj,
                'material_type'        => $estimatedCategory,
                'resin_code'           => trim((string) ($data['polymer_code'] ?? "#" . ($estimatedCategory === 'PET' ? '1' : ($estimatedCategory === 'HDPE' ? '2' : ($estimatedCategory === 'PP' ? '5' : '4'))))),
                'confidence'           => 'medium',
                'identification_basis' => 'visual_estimate',
                'candidate_materials'  => [],
                'verification_note'    => 'Periksa kode daur ulang segitiga angka pada fisik benda.',
                'melting_point'        => $compMelting,
            ];
        }

        $materialTitle   = trim((string) ($data['material_title'] ?? "Material Plastik {$estimatedCategory}"));
        $polymerCode     = trim((string) ($data['polymer_code'] ?? "Tipe Polimer {$estimatedCategory}"));
        $meltingPoint    = trim((string) ($data['melting_point'] ?? '130° - 260° C'));
        $avoidedEmission = trim((string) ($data['avoided_emission'] ?? '~1.8 kg CO₂/kg (Estimasi)'));
        if (!str_contains($avoidedEmission, 'Estimasi') && !str_contains($avoidedEmission, '-')) {
            $avoidedEmission .= ' (Estimasi)';
        }
        $qualitativeNote = trim((string) ($data['qualitative_note'] ?? ($identSummary ?: 'Hasil identifikasi visual objek plastik oleh Gemini AI.')));

        // Kumpulkan ID semua komponen yang valid
        $allComponentIds = array_column($normalizedComponents, 'id');

        // Normalisasi Rekomendasi Upcycling (maksimal 3)
        $rawIdeas = $data['recommendations'] ?? $data['ideas'] ?? [];
        if (!is_array($rawIdeas) || empty($rawIdeas)) {
            $primaryCompId = $allComponentIds[0] ?? 'component_main';
            $rawIdeas = [
                [
                    'id'                => 'idea_1',
                    'title'             => 'Wadah Serbaguna & Pot Mini',
                    'difficulty'        => 'Tingkat Mudah',
                    'description'       => 'Dapat dibersihkan dan dialihfungsikan secara langsung menjadi wadah penyimpanan atau pot tanaman.',
                    'component_ids'     => [$primaryCompId],
                    'why_it_fits'       => 'Bentuk fisik wadah yang berongga sangat cocok untuk menampung media tanam atau barang kecil.',
                    'suitable_quantity' => 'single_item',
                    'scale'             => 'household',
                    'tools'             => 'Gunting, kuas, cat akrilik',
                    'steps'             => '1. Bersihkan wadah plastik. 2. Buat lubang drainase jika untuk tanaman. 3. Hias bagian luar.',
                    'safety_notes'      => 'Hati-hati saat membuat lubang atau memotong plastik kaku.',
                ],
            ];
        }

        $normalizedIdeas = [];
        foreach ($rawIdeas as $index => $idea) {
            if (!is_array($idea)) {
                continue;
            }
            if (count($normalizedIdeas) >= 3) {
                break; // Batasi maksimal 3 rekomendasi terbaik
            }

            $difficulty = trim((string) ($idea['difficulty'] ?? 'Tingkat Menengah'));
            if (!str_contains($difficulty, 'Tingkat')) {
                $difficulty = 'Tingkat ' . ucfirst($difficulty);
            }

            $ideaCompIds = [];
            if (isset($idea['component_ids']) && is_array($idea['component_ids'])) {
                foreach ($idea['component_ids'] as $cid) {
                    if (!empty($cid)) {
                        $ideaCompIds[] = trim((string) $cid);
                    }
                }
            }
            if (empty($ideaCompIds)) {
                $ideaCompIds = $allComponentIds;
            }

            $quantity = strtolower(trim((string) ($idea['suitable_quantity'] ?? 'single_item')));
            if (!in_array($quantity, ['single_item', 'small_batch', 'bulk'], true)) {
                $quantity = 'single_item';
            }

            $scale = strtolower(trim((string) ($idea['scale'] ?? 'household')));
            if (!in_array($scale, ['household', 'community', 'industrial'], true)) {
                $scale = 'household';
            }

            $normalizedIdeas[] = [
                'id'                => (string) ($idea['id'] ?? 'idea_' . ($index + 1)),
                'title'             => (string) ($idea['title'] ?? 'Kerajinan Upcycling'),
                'difficulty'        => $difficulty,
                'description'       => (string) ($idea['description'] ?? 'Pemanfaatan ramah lingkungan berbasis bentuk objek.'),
                'component_ids'     => $ideaCompIds,
                'why_it_fits'       => isset($idea['why_it_fits']) ? (string) $idea['why_it_fits'] : null,
                'suitable_quantity' => $quantity,
                'scale'             => $scale,
                'tools'             => isset($idea['tools']) ? (string) $idea['tools'] : null,
                'steps'             => isset($idea['steps']) ? (string) $idea['steps'] : null,
                'safety_notes'      => isset($idea['safety_notes']) ? (string) $idea['safety_notes'] : null,
            ];
        }

        return [
            'status'                 => 'success',
            'is_plastic'             => true,
            'object_name'            => $objectName,
            'object_description'     => $objectDesc ?: $objectName,
            'identification_summary' => $identSummary ?: $qualitativeNote,
            'components'             => $normalizedComponents,
            'recommendations'        => $normalizedIdeas,
            // Kompatibilitas backward
            'detected_object'        => $detectedObj ?: $objectName,
            'material_type'          => 'PLASTIC',
            'message'                => 'Identifikasi sampah plastik berhasil.',
            'estimated_category'     => $estimatedCategory,
            'material_title'         => $materialTitle,
            'polymer_code'           => $polymerCode,
            'melting_point'          => $meltingPoint,
            'avoided_emission'       => $avoidedEmission,
            'qualitative_note'       => $qualitativeNote,
            'ideas'                  => $normalizedIdeas,
            'is_ai_generated'        => true,
        ];
    }

    /**
     * Helper resolusi titik leleh default berbasis jenis polimer.
     */
    protected function resolveDefaultMeltingPoint(string $polymer): string
    {
        $p = strtoupper($polymer);
        return match (true) {
            str_contains($p, 'PET')  => '250° - 260° C',
            str_contains($p, 'HDPE') => '130° - 145° C',
            str_contains($p, 'PP')   => '160° - 170° C',
            str_contains($p, 'LDPE') => '105° - 115° C',
            str_contains($p, 'PVC')  => '100° - 260° C',
            str_contains($p, 'PS')   => '210° - 249° C',
            default                  => '130° - 165° C',
        };
    }
}
