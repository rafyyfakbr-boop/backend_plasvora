<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\GeminiVisionService;
use Illuminate\Support\Facades\Log;

echo "=== MEMULAI TEST LIVE GEMINI API ===\n";

$service = app(GeminiVisionService::class);
$imagePath = 'C:/Project/Plasvora_app/assets/images/hasil_analisis_caps.jpg';

if (!file_exists($imagePath)) {
    echo "File gambar sample tidak ditemukan di: $imagePath\n";
    exit(1);
}

try {
    echo "Mengirim request gambar hasil_analisis_caps.jpg ke Gemini...\n";
    $result = $service->analyzePlasticImage($imagePath, 'HDPE', 'Tutup botol plastik');
    echo "=== PANGGILAN GEMINI LIVE BERHASIL! ===\n";
    echo "Kategori Estimasi: " . ($result['estimated_category'] ?? 'N/A') . "\n";
    echo "Judul Material: " . ($result['material_title'] ?? 'N/A') . "\n";
    echo "Kode Polimer: " . ($result['polymer_code'] ?? 'N/A') . "\n";
    echo "Titik Leleh: " . ($result['melting_point'] ?? 'N/A') . "\n";
    echo "Emisi Tercegah: " . ($result['avoided_emission'] ?? 'N/A') . "\n";
    echo "Catatan Kualitatif: " . ($result['qualitative_note'] ?? 'N/A') . "\n";
    echo "Jumlah Ide Upcycling: " . count($result['ideas'] ?? []) . "\n";
    foreach ($result['ideas'] as $idx => $idea) {
        echo " - Ide " . ($idx + 1) . ": " . $idea['title'] . " (" . $idea['difficulty'] . ")\n";
    }
} catch (\Throwable $e) {
    echo "=== PANGGILAN GEMINI LIVE GAGAL ===\n";
    echo "Error (" . $e->getCode() . "): " . $e->getMessage() . "\n";
}

