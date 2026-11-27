<?php

namespace Tests\Unit\Services;

use App\Services\GeminiVisionService;
use Exception;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiVisionImageMimeTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.gemini.api_key', 'unit_test_key');
        Config::set('services.gemini.model', 'gemini-2.5-flash');
        Config::set('services.gemini.fallback_model', 'gemini-2.5-flash-lite');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function generateRealImageFile(string $format, int $width, int $height, ?string $customExtension = null): string
    {
        $this->assertTrue(extension_loaded('gd'), 'PHP GD extension is required for real image encoding tests.');

        $img = imagecreatetruecolor($width, $height);
        $bg  = imagecolorallocate($img, 24, 128, 72);
        $fg  = imagecolorallocate($img, 240, 245, 240);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        imagefilledellipse($img, (int) ($width / 2), (int) ($height / 2), (int) ($width / 3), (int) ($height / 3), $fg);

        $ext  = $customExtension ?? $format;
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plasvora_mime_' . uniqid('', true) . '.' . $ext;

        $saved = match ($format) {
            'png'  => imagepng($img, $path),
            'webp' => imagewebp($img, $path, 85),
            'jpeg', 'jpg' => imagejpeg($img, $path, 85),
            default => false,
        };
        imagedestroy($img);

        $this->assertTrue($saved, "Failed to generate {$format} fixture.");
        $this->tempFiles[] = $path;

        return $path;
    }

    private function fakeSuccessGeminiPayload(): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'status'             => 'success',
                            'is_plastic'         => true,
                            'material_type'      => 'PLASTIC',
                            'message'            => 'Berhasil.',
                            'object_name'        => 'Botol PET',
                            'estimated_category' => 'PET',
                            'recommendations'    => [[
                                'id'                => 'idea_1',
                                'title'             => 'Pot Tanaman',
                                'difficulty'        => 'Tingkat Mudah',
                                'description'       => 'Potong botol menjadi pot.',
                                'component_ids'     => ['component_main'],
                                'suitable_quantity' => 'single_item',
                                'scale'             => 'household',
                            ]],
                        ]),
                    ]],
                ],
            ]],
        ];
    }

    /**
     * Verifikasi konsistensi inlineData: base64 valid, magic/finfo & getimagesize cocok persis dengan mimeType.
     */
    private function assertInlineDataConsistent(array $inlineData, string $expectedMime, ?int $maxDimension = null): string
    {
        $this->assertSame($expectedMime, $inlineData['mimeType'] ?? null);
        $this->assertNotEmpty($inlineData['data'] ?? null);

        $decoded = base64_decode((string) $inlineData['data'], true);
        $this->assertIsString($decoded);
        $this->assertNotSame('', $decoded);

        $info = @getimagesizefromstring($decoded);
        $this->assertIsArray($info, 'Decoded Gemini inlineData bytes must be a valid image.');
        $this->assertSame($expectedMime, $info['mime']);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $this->assertSame($expectedMime, $finfo->buffer($decoded));

        if ($maxDimension !== null) {
            $this->assertLessThanOrEqual($maxDimension, $info[0]);
            $this->assertLessThanOrEqual($maxDimension, $info[1]);
        }

        return $decoded;
    }

    public function test_1_large_png_is_converted_to_jpeg_with_matching_image_jpeg_mime(): void
    {
        $path = $this->generateRealImageFile('png', 1500, 1000);
        $capturedInline = null;

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) use (&$capturedInline) {
                $capturedInline = $request['contents'][0]['parts'][1]['inlineData'] ?? null;
                return Http::response($this->fakeSuccessGeminiPayload(), 200);
            },
        ]);

        $service = new GeminiVisionService();
        $service->analyzePlasticImage($path, 'PET');

        $this->assertIsArray($capturedInline);
        $decoded = $this->assertInlineDataConsistent($capturedInline, 'image/jpeg', 1200);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $decoded);
    }

    public function test_2_large_webp_is_converted_to_jpeg_with_matching_image_jpeg_mime(): void
    {
        $path = $this->generateRealImageFile('webp', 1400, 900);
        $capturedInline = null;

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) use (&$capturedInline) {
                $capturedInline = $request['contents'][0]['parts'][1]['inlineData'] ?? null;
                return Http::response($this->fakeSuccessGeminiPayload(), 200);
            },
        ]);

        $service = new GeminiVisionService();
        $service->analyzePlasticImage($path, 'HDPE');

        $this->assertIsArray($capturedInline);
        $decoded = $this->assertInlineDataConsistent($capturedInline, 'image/jpeg', 1200);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $decoded);
    }

    public function test_3_small_png_is_not_converted_and_preserves_image_png_mime(): void
    {
        $path = $this->generateRealImageFile('png', 200, 150);
        $originalBytes = file_get_contents($path);
        $capturedInline = null;

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) use (&$capturedInline) {
                $capturedInline = $request['contents'][0]['parts'][1]['inlineData'] ?? null;
                return Http::response($this->fakeSuccessGeminiPayload(), 200);
            },
        ]);

        $service = new GeminiVisionService();
        $service->analyzePlasticImage($path, 'PP');

        $this->assertIsArray($capturedInline);
        $decoded = $this->assertInlineDataConsistent($capturedInline, 'image/png', 200);
        $this->assertSame($originalBytes, $decoded);
    }

    public function test_4_small_webp_is_not_converted_and_preserves_image_webp_mime(): void
    {
        $path = $this->generateRealImageFile('webp', 220, 160);
        $originalBytes = file_get_contents($path);
        $capturedInline = null;

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) use (&$capturedInline) {
                $capturedInline = $request['contents'][0]['parts'][1]['inlineData'] ?? null;
                return Http::response($this->fakeSuccessGeminiPayload(), 200);
            },
        ]);

        $service = new GeminiVisionService();
        $service->analyzePlasticImage($path, 'LDPE');

        $this->assertIsArray($capturedInline);
        $decoded = $this->assertInlineDataConsistent($capturedInline, 'image/webp', 220);
        $this->assertSame($originalBytes, $decoded);
    }

    public function test_5_small_and_large_jpeg_both_send_consistent_image_jpeg_mime(): void
    {
        $smallPath = $this->generateRealImageFile('jpeg', 240, 180);
        $largePath = $this->generateRealImageFile('jpeg', 1600, 1200);
        $capturedInlines = [];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) use (&$capturedInlines) {
                $capturedInlines[] = $request['contents'][0]['parts'][1]['inlineData'] ?? null;
                return Http::response($this->fakeSuccessGeminiPayload(), 200);
            },
        ]);

        $service = new GeminiVisionService();
        $service->analyzePlasticImage($smallPath, 'PET');
        $service->analyzePlasticImage($largePath, 'PET');

        $this->assertCount(2, $capturedInlines);
        $smallDecoded = $this->assertInlineDataConsistent($capturedInlines[0], 'image/jpeg', 240);
        $this->assertSame(file_get_contents($smallPath), $smallDecoded);
        $this->assertInlineDataConsistent($capturedInlines[1], 'image/jpeg', 1200);
    }

    public function test_6_spoofed_client_mime_and_fallback_model_retry_keep_synchronized_inline_data(): void
    {
        // PNG asli dengan ekstensi palsu .jpg dan client MIME palsu image/jpeg
        $pngWithJpgExt = $this->generateRealImageFile('png', 180, 120, 'jpg');
        $uploaded = new UploadedFile($pngWithJpgExt, 'spoofed.jpg', 'image/jpeg', null, true);

        $capturedRequests = [];
        $call = 0;

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) use (&$capturedRequests, &$call) {
                $call++;
                $capturedRequests[] = [
                    'url'        => $request->url(),
                    'inlineData' => $request['contents'][0]['parts'][1]['inlineData'] ?? null,
                ];
                // Simulasikan error 502 pada model primary agar lanjut ke fallback model
                if ($call === 1) {
                    return Http::response(['error' => ['message' => 'Bad Gateway']], 502);
                }
                return Http::response($this->fakeSuccessGeminiPayload(), 200);
            },
        ]);

        $service = new GeminiVisionService();
        $service->analyzePlasticImage($uploaded, 'PET');

        $this->assertCount(2, $capturedRequests);
        $this->assertStringContainsString('gemini-2.5-flash:generateContent', $capturedRequests[0]['url']);
        $this->assertStringContainsString('gemini-2.5-flash-lite:generateContent', $capturedRequests[1]['url']);

        // Kedua model harus menerima image/png sesuai byte PNG asli, bukan header spoofed image/jpeg
        $this->assertInlineDataConsistent($capturedRequests[0]['inlineData'], 'image/png', 180);
        $this->assertInlineDataConsistent($capturedRequests[1]['inlineData'], 'image/png', 180);
    }

    public function test_7_invalid_or_corrupted_image_bytes_fail_safely_without_calling_gemini(): void
    {
        Http::fake();

        $corruptPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plasvora_corrupt_' . uniqid('', true) . '.jpg';
        file_put_contents($corruptPath, 'NOT_A_REAL_IMAGE_BINARY_PAYLOAD');
        $this->tempFiles[] = $corruptPath;

        $service = new GeminiVisionService();

        try {
            $service->analyzePlasticImage($corruptPath, 'PET');
            $this->fail('Expected invalid image bytes to throw an Exception.');
        } catch (Exception $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('tidak valid', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
