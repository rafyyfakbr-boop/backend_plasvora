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
Anda adalah Senior Environmental & Polymer Vision Analyst untuk Plasvora, platform pengelolaan dan upcycling sampah plastik.
Tugas Anda adalah menganalisis gambar yang dikirimkan pengguna dengan cermat, objektif, dan realistis.

ALUR ANALISIS 3 TAHAP:

TAHAP 1 — IDENTIFIKASI VISUAL OBJEK & CAKUPAN MATERIAL (PLASTIK ATAU BUKAN):
1. Amati bentuk fisik geometris objek, rongga wadah, ketebalan dinding, keterbukaan wadah, leher/tutup, dan tekstur visual nyata.
2. Tentukan kategori makro material (material_type):
   - 'PLASTIC': Jika objek memiliki bukti visual yang jelas merupakan sampah plastik (contoh: gelas cup plastik bening, botol plastik minuman, wadah deterjen HDPE, kantong kresek LDPE, dsb.).
   - 'NON_PLASTIC': Jika objek kemungkinan BUKAN plastik (contoh: kaleng biskuit/makanan/minuman berbahan logam atau baja tinplate/aluminium, botol/stoples kaca, kardus kemasan, kertas, kain, keramik).
   - 'UNCERTAIN': Jika foto terlalu buram, gelap, objek terlalu jauh, tertutup, atau tidak dapat diidentifikasi dengan yakin.
3. ATURAN HAK CIPTA & MEREK DAGANG:
   - Dilarang keras mengutip teks slogan promosi, lirik, atau merek dagang berhak cipta dari label kemasan untuk menghindari filter keselamatan AI (RECITATION).
   - Sebutkan objek HANYA berdasarkan deskripsi fisik netral (contoh: 'wadah silinder kaleng wafer logam', 'gelas cup plastik minuman dingin', 'botol plastik bening berulir').
4. Catatan pengguna hanya berfungsi sebagai petunjuk konteks awal. JANGAN menganggapnya sebagai fakta pasti jika bertentangan dengan visual foto nyata.

TAHAP 2 — PENENTUAN STATUS & KELAYAKAN:
- Jika material_type == 'NON_PLASTIC':
  * Set status = 'unsupported_material'
  * Set is_plastic = false
  * Set message = 'Objek ini tampaknya bukan sampah plastik. Silakan foto sampah plastik untuk mendapatkan rekomendasi upcycling.'
  * Set estimated_category = 'OTHER'
  * Set ideas = [] (array kosong, DILARANG memberikan rekomendasi daur ulang plastik untuk objek non-plastik).
- Jika material_type == 'UNCERTAIN':
  * Set status = 'uncertain_material'
  * Set is_plastic = false
  * Set message = 'Jenis material belum dapat dipastikan. Coba foto objek lebih dekat dengan pencahayaan yang lebih jelas.'
  * Set estimated_category = 'OTHER'
  * Set ideas = [] (array kosong).
- Jika material_type == 'PLASTIC':
  * Set status = 'success'
  * Set is_plastic = true
  * Set message = 'Identifikasi sampah plastik berhasil.'
  * Perkirakan jenis polimer secara ilmiah dan realistis. Pilihan estimated_category HANYA salah satu dari: 'PET', 'HDPE', 'PP', 'LDPE', 'OTHER', atau 'MIXED'.

TAHAP 3 — REKOMENDASI UPCYCLING KHUSUS OBJEK (HANYA JIKA is_plastic == true):
1. Hasilkan 1 sampai 4 ide upcycling yang SPESIFIK memanfaatkan bentuk fisik dan bagian objek dalam foto:
   - Jika objek adalah GELAS CUP PLASTIK: manfaatkan rongga wadah terbuka dan bibir cup (contoh: pot mini tanaman sukulen berlubang drainase, wadah sortir alat tulis/klip meja, wadah pembibitan benih hidroponik). JANGAN merekomendasikan kerajinan leher botol untuk gelas cup!
   - Jika objek adalah BOTOL PLASTIK: manfaatkan badan silinder, leher botol, atau tutup botol.
   - Jika objek adalah TUTUP BOTOL: manfaatkan bentuk kancing pipih/ulir.
2. KESELAMATAN & REALISME:
   - DILARANG merekomendasikan proses pelelehan plastik di dalam oven dapur rumah atau pembakaran langsung tanpa fasilitas pengaman karena mengeluarkan asap toksik beracun.
   - Utamakan metode mekanis praktis: pembersihan, pemotongan dengan gunting/cutter secara hati-hati, pembuatan lubang drainase, dan pengecatan/penghiasan.
3. Setiap ide harus memuat:
   - id: identifier unik (contoh: idea_1)
   - title: judul kerajinan praktis
   - difficulty: 'Tingkat Mudah', 'Tingkat Menengah', atau 'Tingkat Sulit'
   - description: deskripsi singkat dan jelas
   - why_it_fits: penjelasan mengapa bentuk fisik objek di foto cocok untuk ide ini
   - tools: alat dan bahan pendukung yang mudah didapat
   - steps: langkah-langkah pembuatan yang realistis
   - safety_notes: catatan keselamatan kerja (contoh: hati-hati menggunakan pisau cutter saat memotong plastik kaku).
TEXT;

        $promptText = "Analisis objek sampah pada gambar ini sesuai instruksi 3 tahap.";
        if ($declaredCategory) {
            $promptText .= " Kategori awal yang dilaporkan pengguna: {$declaredCategory} (gunakan sebagai referensi pendukung visual).";
        }
        if ($notes) {
            $promptText .= " Catatan tambahan pengguna: {$notes} (verifikasi terhadap bentuk fisik nyata).";
        }

        // Schema terstruktur OpenAPI (dengan key camelCase untuk raw REST wire format)
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'status' => [
                    'type' => 'STRING',
                    'enum' => ['success', 'unsupported_material', 'uncertain_material'],
                    'description' => 'success jika sampah plastik teranalisis, unsupported_material jika objek bukan plastik, uncertain_material jika objek buram/tidak pasti.',
                ],
                'is_plastic' => [
                    'type' => 'BOOLEAN',
                    'description' => 'True jika objek sampah plastik, False jika bukan plastik atau tidak pasti.',
                ],
                'detected_object' => [
                    'type' => 'STRING',
                    'description' => 'Deskripsi fisik netral objek yang terlihat tanpa mengutip merek komersial. Contoh: Gelas cup plastik bening, Wadah silinder kaleng logam, Botol minuman berulir.',
                ],
                'material_type' => [
                    'type' => 'STRING',
                    'enum' => ['PLASTIC', 'NON_PLASTIC', 'UNCERTAIN'],
                    'description' => 'Klasifikasi material makro: PLASTIC, NON_PLASTIC, atau UNCERTAIN.',
                ],
                'message' => [
                    'type' => 'STRING',
                    'description' => 'Pesan hasil klasifikasi untuk pengguna.',
                ],
                'estimated_category' => [
                    'type' => 'STRING',
                    'enum' => ['PET', 'HDPE', 'PP', 'LDPE', 'OTHER', 'MIXED'],
                    'description' => 'Jenis polimer jika plastik (PET, HDPE, PP, LDPE, OTHER, MIXED). Jika bukan plastik, pilih OTHER.',
                ],
                'material_title' => [
                    'type' => 'STRING',
                    'description' => 'Nama judul deskriptif material, contoh: Gelas Plastik Bening Polipropilena (PP), atau Kaleng Logam Tinplate.',
                ],
                'polymer_code' => [
                    'type' => 'STRING',
                    'description' => 'Kode daur ulang plastik, contoh: Tipe #5 (PP). Beri tanda strip (-) jika bukan plastik.',
                ],
                'melting_point' => [
                    'type' => 'STRING',
                    'description' => 'Titik leleh material, contoh: 160° - 165° C. Beri tanda strip (-) jika bukan plastik.',
                ],
                'avoided_emission' => [
                    'type' => 'STRING',
                    'description' => 'Perkiraan emisi karbon yang dapat dicegah, contoh: ~1.7 kg CO₂/kg. Beri tanda strip (-) jika bukan plastik.',
                ],
                'qualitative_note' => [
                    'type' => 'STRING',
                    'description' => 'Deskripsi visual objek, bukti visual, tingkat keyakinan, dan anjuran verifikasi stempel daur ulang fisik.',
                ],
                'ideas' => [
                    'type' => 'ARRAY',
                    'description' => 'Daftar 1 sampai 4 ide upcycling khusus objek ini. WAJIB KOSONG [] jika is_plastic=false.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'id' => ['type' => 'STRING', 'description' => 'ID unik ide, contoh: idea_1.'],
                            'title' => ['type' => 'STRING', 'description' => 'Judul ide kerajinan/upcycling.'],
                            'difficulty' => ['type' => 'STRING', 'enum' => ['Tingkat Mudah', 'Tingkat Menengah', 'Tingkat Sulit']],
                            'description' => ['type' => 'STRING', 'description' => 'Penjelasan ide upcycling.'],
                            'why_it_fits' => ['type' => 'STRING', 'description' => 'Mengapa bentuk fisik objek cocok untuk ide ini.'],
                            'tools' => ['type' => 'STRING', 'description' => 'Alat dan bahan yang dibutuhkan.'],
                            'steps' => ['type' => 'STRING', 'description' => 'Langkah-langkah pembuatan praktis.'],
                            'safety_notes' => ['type' => 'STRING', 'description' => 'Catatan keselamatan saat pengerjaan.'],
                        ],
                        'required' => ['id', 'title', 'difficulty', 'description', 'why_it_fits', 'steps'],
                    ],
                ],
            ],
            'required' => [
                'status',
                'is_plastic',
                'detected_object',
                'material_type',
                'message',
                'estimated_category',
                'material_title',
                'polymer_code',
                'melting_point',
                'avoided_emission',
                'qualitative_note',
                'ideas',
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

        $rawCat = strtoupper(trim((string) ($data['estimated_category'] ?? 'OTHER')));
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

        $materialTitle   = trim((string) ($data['material_title'] ?? "Material Plastik {$estimatedCategory}"));
        $polymerCode     = trim((string) ($data['polymer_code'] ?? "Tipe Polimer {$estimatedCategory}"));
        $meltingPoint    = trim((string) ($data['melting_point'] ?? '130° - 260° C'));
        $avoidedEmission = trim((string) ($data['avoided_emission'] ?? '~1.8 kg CO₂/kg'));
        $qualitativeNote = trim((string) ($data['qualitative_note'] ?? 'Hasil identifikasi visual objek plastik oleh Gemini AI.'));

        $rawIdeas = $data['ideas'] ?? [];
        if (!is_array($rawIdeas) || empty($rawIdeas)) {
            $rawIdeas = [
                [
                    'id'          => 'idea_1',
                    'title'       => 'Wadah Serbaguna & Pot Mini',
                    'difficulty'  => 'Tingkat Mudah',
                    'description' => 'Dapat dibersihkan dan dialihfungsikan secara langsung menjadi wadah penyimpanan atau pot tanaman.',
                    'why_it_fits' => 'Bentuk fisik wadah yang berongga sangat cocok untuk menampung media tanam atau barang kecil.',
                    'tools'       => 'Gunting, kuas, cat akrilik',
                    'steps'       => '1. Bersihkan wadah plastik. 2. Buat lubang drainase jika untuk tanaman. 3. Hias bagian luar.',
                    'safety_notes'=> 'Hati-hati saat membuat lubang atau memotong plastik.',
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
                'description'  => (string) ($idea['description'] ?? 'Pemanfaatan ramah lingkungan berbasis bentuk objek.'),
                'why_it_fits'  => isset($idea['why_it_fits']) ? (string) $idea['why_it_fits'] : null,
                'tools'        => isset($idea['tools']) ? (string) $idea['tools'] : null,
                'steps'        => isset($idea['steps']) ? (string) $idea['steps'] : null,
                'safety_notes' => isset($idea['safety_notes']) ? (string) $idea['safety_notes'] : null,
            ];
        }

        return [
            'status'             => 'success',
            'is_plastic'         => true,
            'detected_object'    => $detectedObj,
            'material_type'      => 'PLASTIC',
            'message'            => 'Identifikasi sampah plastik berhasil.',
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
