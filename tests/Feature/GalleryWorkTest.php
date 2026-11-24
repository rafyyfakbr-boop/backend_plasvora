<?php

namespace Tests\Feature;

use App\Models\GalleryWork;
use App\Models\GalleryWorkImage;
use App\Models\GalleryWorkLike;
use App\Models\User;
use Database\Seeders\GallerySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryWorkTest extends TestCase
{
    use RefreshDatabase;

    private function createWork(User $author, array $overrides = [], int $imageCount = 1): GalleryWork
    {
        $work = new GalleryWork();
        $work->user_id                = $author->id;
        $work->title                  = $overrides['title'] ?? 'Pot Hidroponik Mini PET';
        $work->difficulty             = $overrides['difficulty'] ?? GalleryWork::DIFFICULTY_MUDAH;
        $work->materials              = $overrides['materials'] ?? ['Botol plastik PET 1,5L', 'Kain flanel bekas'];
        $work->tools                  = $overrides['tools'] ?? ['Gunting', 'Cutter'];
        $work->steps                  = $overrides['steps'] ?? ['Cuci botol sampai bersih.', 'Potong botol menjadi dua bagian.'];
        $work->video_links            = $overrides['video_links'] ?? ['https://youtu.be/dQw4w9WgXcQ'];
        $work->status                 = $overrides['status'] ?? GalleryWork::STATUS_APPROVED;
        $work->rejection_reason       = $overrides['rejection_reason'] ?? null;
        $work->reviewed_at            = $overrides['reviewed_at'] ?? ($work->status === GalleryWork::STATUS_PENDING ? null : now());
        $work->published_at           = $overrides['published_at'] ?? ($work->status === GalleryWork::STATUS_APPROVED ? now() : null);
        $work->ownership_confirmed_at = $overrides['ownership_confirmed_at'] ?? now();
        $work->likes_count            = $overrides['likes_count'] ?? 0;
        $work->save();

        for ($i = 0; $i < $imageCount; $i++) {
            $path = "gallery/work_{$work->id}_{$i}.jpg";
            Storage::disk('public')->put($path, 'fake-image-bytes');
            $work->images()->create([
                'path'       => $path,
                'sort_order' => $i,
            ]);
        }

        return $work->fresh(['images', 'author']);
    }

    /** @test */
    public function public_list_returns_only_approved_works_and_hides_author_private_fields(): void
    {
        Storage::fake('public');

        $author = User::factory()->create([
            'name'  => 'Rina Kartika',
            'email' => 'rina@example.com',
            'phone' => '6281234567890',
        ]);

        $approved = $this->createWork($author, [
            'title'  => 'Lampu Tidur Botol PET',
            'status' => GalleryWork::STATUS_APPROVED,
        ]);
        $this->createWork($author, [
            'title'  => 'Karya Pending',
            'status' => GalleryWork::STATUS_PENDING,
        ]);
        $this->createWork($author, [
            'title'            => 'Karya Ditolak',
            'status'           => GalleryWork::STATUS_REJECTED,
            'rejection_reason' => 'Foto buram',
        ]);

        $response = $this->getJson('/api/gallery');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonPath('data.0.author.id', $author->id)
            ->assertJsonPath('data.0.author.name', 'Rina Kartika')
            ->assertJsonPath('data.0.liked_by_me', false);

        $item = $response->json('data.0');
        $this->assertArrayNotHasKey('email', $item['author']);
        $this->assertArrayNotHasKey('phone', $item['author']);
        $this->assertArrayNotHasKey('status', $item);
        $this->assertArrayNotHasKey('rejection_reason', $item);
    }

    /** @test */
    public function public_list_supports_search_difficulty_sort_clamped_per_page_and_optional_auth_liked_by_me(): void
    {
        Storage::fake('public');

        $author = User::factory()->create();
        $viewer = User::factory()->create();

        $olderPopular = $this->createWork($author, [
            'title'        => 'Pot Bunga PET Klasik',
            'difficulty'   => 'mudah',
            'likes_count'  => 10,
            'published_at' => now()->subDays(2),
        ]);
        $newerLessPopular = $this->createWork($author, [
            'title'        => 'Pot Bunga PET Modern',
            'difficulty'   => 'mudah',
            'likes_count'  => 2,
            'published_at' => now()->subHour(),
        ]);
        $this->createWork($author, [
            'title'        => 'Tas Anyaman HDPE',
            'difficulty'   => 'sulit',
            'likes_count'  => 50,
            'published_at' => now(),
        ]);

        GalleryWorkLike::create([
            'gallery_work_id' => $olderPopular->id,
            'user_id'         => $viewer->id,
        ]);

        // Search + difficulty + sort=popular + per_page clamped to 24 + optional bearer auth
        $popular = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/gallery?q=Pot%20Bunga&difficulty=mudah&sort=popular&per_page=100');

        $popular->assertStatus(200)
            ->assertJsonPath('meta.per_page', 24)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $olderPopular->id)
            ->assertJsonPath('data.0.liked_by_me', true)
            ->assertJsonPath('data.1.id', $newerLessPopular->id)
            ->assertJsonPath('data.1.liked_by_me', false);

        // Sort=latest puts newer first
        $latest = $this->getJson('/api/gallery?q=Pot%20Bunga&difficulty=mudah&sort=latest');
        $latest->assertStatus(200)
            ->assertJsonPath('data.0.id', $newerLessPopular->id)
            ->assertJsonPath('data.1.id', $olderPopular->id);
    }

    /** @test */
    public function author_sees_all_own_works_with_status_and_rejection_reason_via_me_gallery(): void
    {
        Storage::fake('public');

        $author = User::factory()->create();
        $other  = User::factory()->create();

        $this->createWork($author, ['title' => 'Approved Work', 'status' => 'approved']);
        $this->createWork($author, ['title' => 'Pending Work', 'status' => 'pending']);
        $this->createWork($author, [
            'title'            => 'Rejected Work',
            'status'           => 'rejected',
            'rejection_reason' => 'Langkah pembuatan kurang jelas.',
        ]);
        $this->createWork($other, ['title' => 'Other Work', 'status' => 'approved']);

        $this->getJson('/api/me/gallery')->assertStatus(401);

        $response = $this->actingAs($author, 'sanctum')->getJson('/api/me/gallery');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 3);

        $byTitle = collect($response->json('data'))->keyBy('title');
        $this->assertEquals('approved', $byTitle['Approved Work']['status']);
        $this->assertEquals('pending', $byTitle['Pending Work']['status']);
        $this->assertEquals('rejected', $byTitle['Rejected Work']['status']);
        $this->assertEquals('Langkah pembuatan kurang jelas.', $byTitle['Rejected Work']['rejection_reason']);
    }

    /** @test */
    public function detail_of_non_approved_work_returns_404_for_guests_and_other_users_but_200_for_author(): void
    {
        Storage::fake('public');

        $author = User::factory()->create();
        $other  = User::factory()->create();

        $pending = $this->createWork($author, ['status' => 'pending']);
        $rejected = $this->createWork($author, [
            'status'           => 'rejected',
            'rejection_reason' => 'Foto tidak asli.',
        ]);

        $this->getJson("/api/gallery/{$pending->id}")->assertStatus(404);
        $this->actingAs($other, 'sanctum')->getJson("/api/gallery/{$pending->id}")->assertStatus(404);
        $this->actingAs($other, 'sanctum')->getJson("/api/gallery/{$rejected->id}")->assertStatus(404);

        $this->actingAs($author, 'sanctum')
            ->getJson("/api/gallery/{$pending->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');

        $this->actingAs($author, 'sanctum')
            ->getJson("/api/gallery/{$rejected->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Foto tidak asli.');
    }

    /** @test */
    public function create_requires_auth_publishes_immediately_and_ignores_client_supplied_protected_fields(): void
    {
        Storage::fake('public');

        $author = User::factory()->create();
        $other  = User::factory()->create();

        // Guest blocked
        $this->postJson('/api/gallery', [])->assertStatus(401);

        $img1 = UploadedFile::fake()->image('cover.jpg', 800, 600);
        $img2 = UploadedFile::fake()->image('angle2.png', 800, 600);

        $response = $this->actingAs($author, 'sanctum')->post('/api/gallery', [
            'title'               => '  Lampu Hias Botol PET  ',
            'difficulty'          => 'menengah',
            'materials'           => ['Botol PET 1,5L', 'Lampu LED USB'],
            'tools'               => ['Cutter', 'Lem tembak'],
            'steps'               => ['Bersihkan botol.', 'Potong pola daun dan pasang LED.'],
            'video_links'         => ['https://www.youtube.com/watch?v=abc123'],
            'ownership_confirmed' => '1',
            'images'              => [$img1, $img2],
            // Client-supplied protected fields must be ignored
            'status'              => 'rejected',
            'likes_count'         => 999,
            'user_id'             => $other->id,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Lampu Hias Botol PET')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.likes_count', 0)
            ->assertJsonPath('data.author.id', $author->id)
            ->assertJsonCount(2, 'data.images');

        $this->assertNotNull($response->json('data.published_at'));

        $work = GalleryWork::with('images')->find($response->json('data.id'));
        $this->assertNotNull($work);
        $this->assertEquals($author->id, $work->user_id);
        $this->assertEquals(GalleryWork::STATUS_APPROVED, $work->status);
        $this->assertNotNull($work->published_at);
        $this->assertNull($work->reviewed_at);
        $this->assertEquals(0, $work->likes_count);
        $this->assertNotNull($work->ownership_confirmed_at);

        foreach ($work->images as $img) {
            Storage::disk('public')->assertExists($img->getRawOriginal('path'));
        }

        // Newly created work immediately appears in public GET /api/gallery
        $this->getJson('/api/gallery')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $work->id)
            ->assertJsonPath('data.0.title', 'Lampu Hias Botol PET');

        // And appears in GET /api/me/gallery with status = approved
        $this->actingAs($author, 'sanctum')
            ->getJson('/api/me/gallery')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $work->id)
            ->assertJsonPath('data.0.status', 'approved');
    }

    /** @test */
    public function create_validation_rejects_invalid_images_fields_and_missing_ownership(): void
    {
        Storage::fake('public');

        $basePayload = fn () => [
            'title'               => 'Pot Hidroponik PET',
            'difficulty'          => 'mudah',
            'materials'           => ['Botol PET'],
            'steps'               => ['Potong botol dan isi air.'],
            'ownership_confirmed' => '1',
            'images'              => [UploadedFile::fake()->image('ok.jpg', 400, 400)],
        ];

        // 1. Missing images
        $u1 = User::factory()->create();
        $p1 = $basePayload();
        unset($p1['images']);
        $this->actingAs($u1, 'sanctum')
            ->post('/api/gallery', $p1, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        // 2. More than 5 images
        $u2 = User::factory()->create();
        $p2 = $basePayload();
        $p2['images'] = array_map(
            fn ($i) => UploadedFile::fake()->image("img{$i}.jpg", 200, 200),
            range(1, 6)
        );
        $this->actingAs($u2, 'sanctum')
            ->post('/api/gallery', $p2, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        // 3. Wrong file type (.txt) & oversized image (>4096 KB)
        $u3 = User::factory()->create();
        $p3 = $basePayload();
        $p3['images'] = [UploadedFile::fake()->create('note.txt', 10, 'text/plain')];
        $this->actingAs($u3, 'sanctum')
            ->post('/api/gallery', $p3, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);

        $u4 = User::factory()->create();
        $p4 = $basePayload();
        $p4['images'] = [UploadedFile::fake()->image('huge.jpg')->size(4200)];
        $this->actingAs($u4, 'sanctum')
            ->post('/api/gallery', $p4, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);

        // 4. Title too short (<3) & too long (>120)
        $u5 = User::factory()->create();
        $p5 = $basePayload();
        $p5['title'] = 'AB';
        $this->actingAs($u5, 'sanctum')
            ->post('/api/gallery', $p5, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        $u6 = User::factory()->create();
        $p6 = $basePayload();
        $p6['title'] = str_repeat('a', 121);
        $this->actingAs($u6, 'sanctum')
            ->post('/api/gallery', $p6, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        // 5. Whitespace materials & steps, >3 video links, missing ownership_confirmed
        $u7 = User::factory()->create();
        $p7 = $basePayload();
        $p7['materials'] = ['   '];
        $p7['steps'] = ['   '];
        $p7['video_links'] = [
            'https://youtu.be/a',
            'https://youtu.be/b',
            'https://youtu.be/c',
            'https://youtu.be/d',
        ];
        unset($p7['ownership_confirmed']);

        $this->actingAs($u7, 'sanctum')
            ->post('/api/gallery', $p7, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['materials.0', 'steps.0', 'video_links', 'ownership_confirmed']);
    }

    /** @test */
    public function video_url_validation_accepts_allowed_https_hosts_and_rejects_unsafe_urls(): void
    {
        Storage::fake('public');

        $acceptedBatches = [
            [
                'https://youtube.com/watch?v=1',
                'https://www.youtube.com/watch?v=2',
                'https://m.youtube.com/shorts/3',
            ],
            [
                'https://youtu.be/abc123',
                'https://vm.tiktok.com/ZM12345/',
                'https://instagram.com/reel/xyz987/',
            ],
        ];

        foreach ($acceptedBatches as $batch) {
            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum')->post('/api/gallery', [
                'title'               => 'Karya Video Valid',
                'difficulty'          => 'mudah',
                'materials'           => ['Botol PET'],
                'steps'               => ['Langkah pertama.'],
                'video_links'         => $batch,
                'ownership_confirmed' => '1',
                'images'              => [UploadedFile::fake()->image('ok.jpg', 200, 200)],
            ], ['Accept' => 'application/json'])->assertStatus(201);
        }

        $rejectedUrls = [
            'http://youtube.com/watch?v=1',
            'https://evilyoutube.com/watch?v=1',
            'https://youtube.com.evil.com/watch?v=1',
            'https://evil.com/?u=youtube.com',
            'https://user@youtube.com/watch?v=1',
            'bukan-tautan-sama-sekali',
        ];

        foreach ($rejectedUrls as $badUrl) {
            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum')->post('/api/gallery', [
                'title'               => 'Karya Video Tidak Valid',
                'difficulty'          => 'mudah',
                'materials'           => ['Botol PET'],
                'steps'               => ['Langkah pertama.'],
                'video_links'         => [$badUrl],
                'ownership_confirmed' => '1',
                'images'              => [UploadedFile::fake()->image('ok.jpg', 200, 200)],
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['video_links.0']);
        }
    }

    /** @test */
    public function update_and_delete_are_author_only_and_preserve_publication_status(): void
    {
        Storage::fake('public');

        $author   = User::factory()->create();
        $attacker = User::factory()->create();

        $approvedWork = $this->createWork($author, ['status' => 'approved'], 2);
        $rejectedWork = $this->createWork($author, [
            'status'           => 'rejected',
            'rejection_reason' => 'Gambar kurang terang',
        ], 1);
        $otherWork = $this->createWork($attacker, ['status' => 'approved'], 1);

        // Non-author forbidden on update & delete
        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/gallery/{$approvedWork->id}", ['title' => 'Dibajak'])
            ->assertStatus(403);

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/gallery/{$approvedWork->id}")
            ->assertStatus(403);

        // Removing all images fails with 422
        $allImageIds = $approvedWork->images->pluck('id')->all();
        $this->actingAs($author, 'sanctum')
            ->patchJson("/api/gallery/{$approvedWork->id}", [
                'remove_image_ids' => $allImageIds,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        // Removing another work's image fails with 422
        $foreignImageId = $otherWork->images->first()->id;
        $this->actingAs($author, 'sanctum')
            ->patchJson("/api/gallery/{$approvedWork->id}", [
                'remove_image_ids' => [$foreignImageId],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['remove_image_ids']);

        // Multipart POST with _method=PATCH removes 1 image, adds 1 image, clears optional tools & video_links, keeps approved status
        $removedImg = $approvedWork->images->first();
        $removedRawPath = $removedImg->getRawOriginal('path');
        $newUpload = UploadedFile::fake()->image('replacement.jpg', 500, 500);

        $updateRes = $this->actingAs($author, 'sanctum')->post("/api/gallery/{$approvedWork->id}", [
            '_method'           => 'PATCH',
            'title'             => 'Judul Baru Setelah Revisi',
            'clear_tools'       => '1',
            'clear_video_links' => '1',
            'remove_image_ids'  => [$removedImg->id],
            'images'            => [$newUpload],
        ], ['Accept' => 'application/json']);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.title', 'Judul Baru Setelah Revisi')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.tools', [])
            ->assertJsonPath('data.video_links', [])
            ->assertJsonCount(2, 'data.images');

        $this->assertNotNull($updateRes->json('data.published_at'));
        Storage::disk('public')->assertMissing($removedRawPath);

        // Edited approved work remains visible in public GET /api/gallery
        $publicIds = collect($this->getJson('/api/gallery')->assertStatus(200)->json('data'))
            ->pluck('id')
            ->all();
        $this->assertContains($approvedWork->id, $publicIds);

        // Editing a legacy rejected work must NOT accidentally convert it to approved
        $author2 = User::factory()->create();
        $rejectedWork2 = $this->createWork($author2, [
            'status'           => 'rejected',
            'rejection_reason' => 'Perbaiki foto',
        ], 1);

        $this->actingAs($author2, 'sanctum')
            ->patchJson("/api/gallery/{$rejectedWork2->id}", [
                'title' => 'Sudah Diperbaiki',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Perbaiki foto');

        $publicIdsAfterRejectedEdit = collect($this->getJson('/api/gallery')->assertStatus(200)->json('data'))
            ->pluck('id')
            ->all();
        $this->assertNotContains($rejectedWork2->id, $publicIdsAfterRejectedEdit);

        // Delete soft-deletes work and deletes stored files
        $storedPaths = $rejectedWork->images->map(fn (GalleryWorkImage $i) => $i->getRawOriginal('path'))->all();
        $this->actingAs($author, 'sanctum')
            ->deleteJson("/api/gallery/{$rejectedWork->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('gallery_works', ['id' => $rejectedWork->id]);
        foreach ($storedPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    /** @test */
    public function legacy_pending_data_migration_publishes_only_active_pending_works(): void
    {
        Storage::fake('public');

        $author = User::factory()->create();

        $activePending = $this->createWork($author, [
            'title'        => 'botol semprot',
            'status'       => GalleryWork::STATUS_PENDING,
            'reviewed_at'  => null,
            'published_at' => null,
        ]);

        $deletedPending = $this->createWork($author, [
            'title'        => 'Karya Pending Dihapus',
            'status'       => GalleryWork::STATUS_PENDING,
            'reviewed_at'  => null,
            'published_at' => null,
        ]);
        $deletedPending->delete();

        $rejected = $this->createWork($author, [
            'title'            => 'Karya Ditolak Legacy',
            'status'           => GalleryWork::STATUS_REJECTED,
            'rejection_reason' => 'Tidak sesuai',
            'reviewed_at'      => now()->subDay(),
            'published_at'     => null,
        ]);

        $reviewedApproved = $this->createWork($author, [
            'title'        => 'Karya Sudah Direview',
            'status'       => GalleryWork::STATUS_APPROVED,
            'reviewed_at'  => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);

        $migration = require database_path('migrations/2026_11_23_000004_publish_legacy_pending_gallery_works.php');

        // Run up(): ONLY active pending rows become approved
        $migration->up();

        $activePending->refresh();
        $this->assertEquals(GalleryWork::STATUS_APPROVED, $activePending->status);
        $this->assertNotNull($activePending->published_at);
        $this->assertNull($activePending->reviewed_at);

        $deletedPendingAfterUp = GalleryWork::withTrashed()->find($deletedPending->id);
        $this->assertNotNull($deletedPendingAfterUp);
        $this->assertEquals(GalleryWork::STATUS_PENDING, $deletedPendingAfterUp->status);
        $this->assertNull($deletedPendingAfterUp->published_at);
        $this->assertNotNull($deletedPendingAfterUp->deleted_at);

        $rejected->refresh();
        $this->assertEquals(GalleryWork::STATUS_REJECTED, $rejected->status);
        $this->assertNull($rejected->published_at);

        $reviewedApproved->refresh();
        $this->assertEquals(GalleryWork::STATUS_APPROVED, $reviewedApproved->status);
        $this->assertNotNull($reviewedApproved->reviewed_at);

        // Active legacy pending work is now visible in public gallery
        $publicTitles = collect($this->getJson('/api/gallery')->assertStatus(200)->json('data'))
            ->pluck('title')
            ->all();
        $this->assertContains('botol semprot', $publicTitles);
        $this->assertNotContains('Karya Ditolak Legacy', $publicTitles);

        // Run down(): reverts only unreviewed active approved rows, leaving reviewed & rejected untouched
        $migration->down();

        $activePending->refresh();
        $this->assertEquals(GalleryWork::STATUS_PENDING, $activePending->status);
        $this->assertNull($activePending->published_at);

        $reviewedApproved->refresh();
        $this->assertEquals(GalleryWork::STATUS_APPROVED, $reviewedApproved->status);

        $rejected->refresh();
        $this->assertEquals(GalleryWork::STATUS_REJECTED, $rejected->status);
    }

    /** @test */
    public function like_toggle_works_only_for_authenticated_users_on_approved_works(): void
    {
        Storage::fake('public');

        $author = User::factory()->create();
        $liker  = User::factory()->create();

        $approved = $this->createWork($author, ['status' => 'approved']);
        $pending  = $this->createWork($author, ['status' => 'pending']);

        // Unauthenticated
        $this->postJson("/api/gallery/{$approved->id}/like")->assertStatus(401);

        // Non-approved rejected (404 for other user, 422 for author)
        $this->actingAs($liker, 'sanctum')->postJson("/api/gallery/{$pending->id}/like")->assertStatus(404);
        $this->actingAs($author, 'sanctum')->postJson("/api/gallery/{$pending->id}/like")->assertStatus(422);

        // First call likes
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/gallery/{$approved->id}/like")
            ->assertStatus(200)
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.likes_count', 1);

        // Second call unlikes
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/gallery/{$approved->id}/like")
            ->assertStatus(200)
            ->assertJsonPath('data.liked', false)
            ->assertJsonPath('data.likes_count', 0);
    }

    /** @test */
    public function artisan_moderation_commands_list_approve_and_reject_works(): void
    {
        Storage::fake('public');

        $author = User::factory()->create(['name' => 'Siti Aminah']);
        $workToApprove = $this->createWork($author, [
            'title'  => 'Rak Buku Galon Bekas',
            'status' => 'pending',
        ]);
        $workToReject = $this->createWork($author, [
            'title'  => 'Karya Belum Jelas',
            'status' => 'pending',
        ]);

        // List pending works
        $this->artisan('gallery:pending')
            ->expectsOutputToContain('Rak Buku Galon Bekas')
            ->expectsOutputToContain('Karya Belum Jelas')
            ->assertSuccessful();

        // Approve
        $this->artisan('gallery:review', [
            'id'       => $workToApprove->id,
            'decision' => 'approve',
        ])->assertSuccessful();

        $workToApprove->refresh();
        $this->assertEquals(GalleryWork::STATUS_APPROVED, $workToApprove->status);
        $this->assertNotNull($workToApprove->published_at);
        $this->assertNotNull($workToApprove->reviewed_at);

        // Reject without reason fails
        $this->artisan('gallery:review', [
            'id'       => $workToReject->id,
            'decision' => 'reject',
        ])->assertFailed();

        // Reject with reason succeeds
        $this->artisan('gallery:review', [
            'id'       => $workToReject->id,
            'decision' => 'reject',
            '--reason' => 'Foto tidak menampilkan hasil akhir dengan jelas.',
        ])->assertSuccessful();

        $workToReject->refresh();
        $this->assertEquals(GalleryWork::STATUS_REJECTED, $workToReject->status);
        $this->assertEquals('Foto tidak menampilkan hasil akhir dengan jelas.', $workToReject->rejection_reason);

        // Invalid decision / nonexistent id fails
        $this->artisan('gallery:review', [
            'id'       => $workToReject->id,
            'decision' => 'archive',
        ])->assertFailed();

        $this->artisan('gallery:review', [
            'id'       => 999999,
            'decision' => 'approve',
        ])->assertFailed();
    }

    /** @test */
    public function gallery_seeder_seeds_three_approved_demo_works_idempotently(): void
    {
        $this->seed(GallerySeeder::class);
        $this->assertEquals(3, GalleryWork::where('status', GalleryWork::STATUS_APPROVED)->count());

        // Running again does not duplicate
        $this->seed(GallerySeeder::class);
        $this->assertEquals(3, GalleryWork::where('status', GalleryWork::STATUS_APPROVED)->count());
    }
}
