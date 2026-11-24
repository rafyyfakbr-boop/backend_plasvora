<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGalleryWorkRequest;
use App\Http\Requests\UpdateGalleryWorkRequest;
use App\Models\GalleryWork;
use App\Models\GalleryWorkImage;
use App\Models\GalleryWorkLike;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GalleryWorkController extends Controller
{
    /**
     * GET /api/gallery
     * Daftar publik karya galeri yang sudah disetujui (approved).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        $query = GalleryWork::query()
            ->approved()
            ->with(['author:id,name', 'images'])
            ->withLikedBy($user);

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));
            if ($search !== '') {
                $escaped = addcslashes($search, '%_\\');
                $query->where('title', 'like', "%{$escaped}%");
            }
        }

        if ($request->filled('difficulty')) {
            $difficulty = strtolower(trim((string) $request->input('difficulty')));
            if (in_array($difficulty, GalleryWork::DIFFICULTIES, true)) {
                $query->where('difficulty', $difficulty);
            }
        }

        $sort = strtolower(trim((string) $request->input('sort', 'latest')));
        if ($sort === 'popular') {
            $query->orderByDesc('likes_count')
                ->orderByDesc('published_at')
                ->orderByDesc('id');
        } else {
            $query->orderByDesc('published_at')
                ->orderByDesc('id');
        }

        $perPage = $this->resolvePerPage($request);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())
            ->map(fn (GalleryWork $work) => $this->formatListItem($work, includeModeration: false))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Daftar galeri karya berhasil dimuat.',
            'data'    => $items,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/gallery/{id}
     * Detail karya galeri. Karya non-approved hanya boleh dilihat penulisnya;
     * selain itu kembalikan 404 agar tidak membocorkan keberadaan karya.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user('sanctum');

        $work = GalleryWork::query()
            ->with(['author:id,name', 'images'])
            ->withLikedBy($user)
            ->find($id);

        if (! $work || ! Gate::forUser($user)->allows('view', $work)) {
            return response()->json([
                'success' => false,
                'message' => 'Karya galeri tidak ditemukan.',
            ], 404);
        }

        $isAuthor = $user !== null && (int) $user->id === (int) $work->user_id;

        return response()->json([
            'success' => true,
            'message' => 'Detail karya galeri berhasil dimuat.',
            'data'    => $this->formatDetail($work, includeModeration: $isAuthor),
        ]);
    }

    /**
     * GET /api/me/gallery
     * Daftar seluruh karya milik pengguna terautentikasi (semua status + alasan penolakan).
     */
    public function myWorks(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = $this->resolvePerPage($request);

        $paginator = GalleryWork::query()
            ->where('user_id', $user->id)
            ->with(['author:id,name', 'images'])
            ->withLikedBy($user)
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = collect($paginator->items())
            ->map(fn (GalleryWork $work) => $this->formatListItem($work, includeModeration: true))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Daftar karya Anda berhasil dimuat.',
            'data'    => $items,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * POST /api/gallery
     * Unggah karya baru (multipart/form-data). Karya valid langsung tayang (status 'approved').
     */
    public function store(StoreGalleryWorkRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $uploadedFiles = $request->file('images', []);
        $storedPaths = [];

        try {
            foreach ($uploadedFiles as $file) {
                $storedPaths[] = $file->store('gallery', 'public');
            }

            $work = DB::transaction(function () use ($user, $validated, $storedPaths): GalleryWork {
                $now = now();
                $work = new GalleryWork();
                $work->user_id                = $user->id;
                $work->title                  = $validated['title'];
                $work->difficulty             = $validated['difficulty'];
                $work->materials              = array_values($validated['materials']);
                $work->tools                  = array_values($validated['tools'] ?? []);
                $work->steps                  = array_values($validated['steps']);
                $work->video_links            = array_values($validated['video_links'] ?? []);
                $work->status                 = GalleryWork::STATUS_APPROVED;
                $work->rejection_reason       = null;
                $work->reviewed_at            = null;
                $work->published_at           = $now;
                $work->ownership_confirmed_at = $now;
                $work->likes_count            = 0;
                $work->save();

                foreach ($storedPaths as $index => $path) {
                    $work->images()->create([
                        'path'       => $path,
                        'sort_order' => $index,
                    ]);
                }

                return $work;
            });

            $work->load(['author:id,name', 'images']);
            $work->setAttribute('liked_by_me', false);

            return response()->json([
                'success' => true,
                'message' => 'Karya berhasil diposting dan sekarang tampil di Galeri.',
                'data'    => $this->formatDetail($work, includeModeration: true),
            ], 201);
        } catch (Throwable $e) {
            $this->deleteStoredPaths($storedPaths);
            Log::error('Failed to store gallery work', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan karya galeri. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * PATCH /api/gallery/{id} (juga mendukung POST dengan _method=PATCH untuk multipart)
     * Hanya penulis yang boleh mengubah. Status karya tetap dipertahankan tanpa kembali ke pending.
     */
    public function update(UpdateGalleryWorkRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $workCheck = GalleryWork::with('images')->find($id);
        if (! $workCheck) {
            return response()->json([
                'success' => false,
                'message' => 'Karya galeri tidak ditemukan.',
            ], 404);
        }

        if (! Gate::forUser($user)->allows('update', $workCheck)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin untuk mengubah karya ini.',
            ], 403);
        }

        $removeIds = array_values(array_unique(array_map(
            'intval',
            $validated['remove_image_ids'] ?? []
        )));

        $existingImageIds = $workCheck->images->pluck('id')->map(fn ($v) => (int) $v)->all();
        $invalidRemoveIds = array_diff($removeIds, $existingImageIds);
        if (! empty($invalidRemoveIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Terdapat foto yang tidak valid untuk dihapus.',
                'errors'  => [
                    'remove_image_ids' => ['ID foto yang dipilih bukan milik karya ini.'],
                ],
            ], 422);
        }

        $uploadedFiles = $request->file('images', []);
        $remainingCount = count($existingImageIds) - count($removeIds);
        $finalCount = $remainingCount + count($uploadedFiles);

        $minImages = (int) config('gallery.min_images', 1);
        $maxImages = (int) config('gallery.max_images', 5);

        if ($finalCount < $minImages) {
            return response()->json([
                'success' => false,
                'message' => 'Karya harus memiliki minimal 1 foto.',
                'errors'  => [
                    'images' => ['Karya harus memiliki minimal 1 foto.'],
                ],
            ], 422);
        }

        if ($finalCount > $maxImages) {
            return response()->json([
                'success' => false,
                'message' => 'Total foto karya tidak boleh melebihi 5 foto.',
                'errors'  => [
                    'images' => ['Total foto karya tidak boleh melebihi 5 foto.'],
                ],
            ], 422);
        }

        $newlyStoredPaths = [];
        $pathsToDeleteAfterCommit = [];

        try {
            foreach ($uploadedFiles as $file) {
                $newlyStoredPaths[] = $file->store('gallery', 'public');
            }

            $updatedWork = DB::transaction(function () use (
                $id,
                $user,
                $validated,
                $removeIds,
                $newlyStoredPaths,
                &$pathsToDeleteAfterCommit
            ): ?GalleryWork {
                $work = GalleryWork::where('id', $id)->lockForUpdate()->first();
                if (! $work || (int) $work->user_id !== (int) $user->id) {
                    return null;
                }

                if (array_key_exists('title', $validated)) {
                    $work->title = $validated['title'];
                }
                if (array_key_exists('difficulty', $validated)) {
                    $work->difficulty = $validated['difficulty'];
                }
                if (array_key_exists('materials', $validated)) {
                    $work->materials = array_values($validated['materials']);
                }
                if (! empty($validated['clear_tools'])) {
                    $work->tools = [];
                } elseif (array_key_exists('tools', $validated)) {
                    $work->tools = array_values($validated['tools'] ?? []);
                }
                if (array_key_exists('steps', $validated)) {
                    $work->steps = array_values($validated['steps']);
                }
                if (! empty($validated['clear_video_links'])) {
                    $work->video_links = [];
                } elseif (array_key_exists('video_links', $validated)) {
                    $work->video_links = array_values($validated['video_links'] ?? []);
                }

                // Pertahankan status yang ada: karya tayang tetap tayang tanpa antrean moderasi,
                // dan karya legacy berstatus ditolak tidak otomatis diubah menjadi tayang.
                if ($work->status === GalleryWork::STATUS_APPROVED && $work->published_at === null) {
                    $work->published_at = now();
                }

                $work->save();

                if (! empty($removeIds)) {
                    $imagesToRemove = $work->images()->whereIn('id', $removeIds)->get();
                    foreach ($imagesToRemove as $img) {
                        $rawPath = $img->getRawOriginal('path');
                        if (GalleryWorkImage::isManagedStorageImagePath($rawPath)) {
                            $pathsToDeleteAfterCommit[] = $rawPath;
                        }
                        $img->delete();
                    }
                }

                // Re-index urutan foto yang tersisa lalu tambahkan foto baru di akhir
                $remainingImages = $work->images()->get();
                $order = 0;
                foreach ($remainingImages as $img) {
                    if ($img->sort_order !== $order) {
                        $img->sort_order = $order;
                        $img->save();
                    }
                    $order++;
                }

                foreach ($newlyStoredPaths as $path) {
                    $work->images()->create([
                        'path'       => $path,
                        'sort_order' => $order++,
                    ]);
                }

                return $work;
            });

            if (! $updatedWork) {
                $this->deleteStoredPaths($newlyStoredPaths);
                return response()->json([
                    'success' => false,
                    'message' => 'Karya galeri tidak ditemukan.',
                ], 404);
            }

            $this->deleteStoredPaths($pathsToDeleteAfterCommit);

            $updatedWork->load(['author:id,name', 'images']);
            $updatedWork->setAttribute(
                'liked_by_me',
                $updatedWork->likes()->where('user_id', $user->id)->exists()
            );

            return response()->json([
                'success' => true,
                'message' => 'Karya berhasil diperbarui.',
                'data'    => $this->formatDetail($updatedWork, includeModeration: true),
            ]);
        } catch (Throwable $e) {
            $this->deleteStoredPaths($newlyStoredPaths);
            Log::error('Failed to update gallery work', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui karya galeri. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * DELETE /api/gallery/{id}
     * Soft delete karya dan hapus file gambar terkait dari storage public.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $pathsToDelete = [];

        $status = DB::transaction(function () use ($id, $user, &$pathsToDelete): int {
            $work = GalleryWork::with('images')->where('id', $id)->lockForUpdate()->first();
            if (! $work) {
                return 404;
            }

            if (! Gate::forUser($user)->allows('delete', $work)) {
                return 403;
            }

            foreach ($work->images as $img) {
                $rawPath = $img->getRawOriginal('path');
                if (GalleryWorkImage::isManagedStorageImagePath($rawPath)) {
                    $pathsToDelete[] = $rawPath;
                }
            }

            $work->images()->delete();
            $work->delete();

            return 200;
        });

        if ($status === 404) {
            return response()->json([
                'success' => false,
                'message' => 'Karya galeri tidak ditemukan.',
            ], 404);
        }

        if ($status === 403) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin untuk menghapus karya ini.',
            ], 403);
        }

        $this->deleteStoredPaths($pathsToDelete);

        return response()->json([
            'success' => true,
            'message' => 'Karya galeri berhasil dihapus.',
        ]);
    }

    /**
     * POST /api/gallery/{id}/like
     * Toggle like untuk karya berstatus approved.
     */
    public function toggleLike(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = DB::transaction(function () use ($id, $user): array {
            $work = GalleryWork::where('id', $id)->lockForUpdate()->first();
            if (! $work) {
                return ['status' => 404, 'message' => 'Karya galeri tidak ditemukan.'];
            }

            if (! Gate::forUser($user)->allows('like', $work)) {
                if ((int) $work->user_id === (int) $user->id) {
                    return [
                        'status'  => 422,
                        'message' => 'Hanya karya yang sedang tayang yang dapat disukai.',
                    ];
                }

                return ['status' => 404, 'message' => 'Karya galeri tidak ditemukan.'];
            }

            $existing = GalleryWorkLike::where('gallery_work_id', $work->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                $existing->delete();
                $liked = false;
            } else {
                try {
                    GalleryWorkLike::create([
                        'gallery_work_id' => $work->id,
                        'user_id'         => $user->id,
                    ]);
                    $liked = true;
                } catch (QueryException) {
                    // Unique constraint guard pada kondisi balapan
                    $liked = true;
                }
            }

            $likesCount = GalleryWorkLike::where('gallery_work_id', $work->id)->count();
            $work->likes_count = $likesCount;
            $work->save();

            return [
                'status'      => 200,
                'liked'       => $liked,
                'likes_count' => $likesCount,
            ];
        });

        if ($result['status'] !== 200) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => $result['liked'] ? 'Karya disukai.' : 'Suka dibatalkan.',
            'data'    => [
                'liked'       => $result['liked'],
                'likes_count' => $result['likes_count'],
            ],
        ]);
    }

    private function resolvePerPage(Request $request): int
    {
        $default = (int) config('gallery.default_per_page', 12);
        $max     = (int) config('gallery.max_per_page', 24);
        $requested = (int) $request->input('per_page', $default);

        if ($requested < 1) {
            return $default;
        }

        return min($requested, $max);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(GalleryWork $work, bool $includeModeration): array
    {
        $coverImage = $work->images->first();
        $materials  = is_array($work->materials) ? $work->materials : [];
        $steps      = is_array($work->steps) ? $work->steps : [];

        $payload = [
            'id'              => $work->id,
            'title'           => $work->title,
            'difficulty'      => $work->difficulty,
            'cover_image_url' => $coverImage?->url,
            'likes_count'     => (int) $work->likes_count,
            'liked_by_me'     => (bool) ($work->getAttribute('liked_by_me') ?? false),
            'published_at'    => $work->published_at?->toISOString(),
            'created_at'      => $work->created_at?->toISOString(),
            'author'          => [
                'id'   => $work->author?->id ?? $work->user_id,
                'name' => $work->author?->name ?? 'Pengguna Plasvora',
            ],
            'materials_count' => count($materials),
            'steps_count'     => count($steps),
        ];

        if ($includeModeration) {
            $payload['status'] = $work->status;
            $payload['rejection_reason'] = $work->rejection_reason;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(GalleryWork $work, bool $includeModeration): array
    {
        $images = $work->images->map(fn (GalleryWorkImage $img) => [
            'id'         => $img->id,
            'url'        => $img->url,
            'sort_order' => (int) $img->sort_order,
        ])->values()->all();

        $materials  = is_array($work->materials) ? array_values($work->materials) : [];
        $tools      = is_array($work->tools) ? array_values($work->tools) : [];
        $steps      = is_array($work->steps) ? array_values($work->steps) : [];
        $videoLinks = is_array($work->video_links) ? array_values($work->video_links) : [];

        $payload = [
            'id'              => $work->id,
            'title'           => $work->title,
            'difficulty'      => $work->difficulty,
            'cover_image_url' => $images[0]['url'] ?? null,
            'images'          => $images,
            'materials'       => $materials,
            'tools'           => $tools,
            'steps'           => $steps,
            'video_links'     => $videoLinks,
            'likes_count'     => (int) $work->likes_count,
            'liked_by_me'     => (bool) ($work->getAttribute('liked_by_me') ?? false),
            'published_at'    => $work->published_at?->toISOString(),
            'created_at'      => $work->created_at?->toISOString(),
            'updated_at'      => $work->updated_at?->toISOString(),
            'author'          => [
                'id'   => $work->author?->id ?? $work->user_id,
                'name' => $work->author?->name ?? 'Pengguna Plasvora',
            ],
            'materials_count' => count($materials),
            'steps_count'     => count($steps),
        ];

        if ($includeModeration) {
            $payload['status'] = $work->status;
            $payload['rejection_reason'] = $work->rejection_reason;
        }

        return $payload;
    }

    /**
     * @param list<string> $paths
     */
    private function deleteStoredPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (GalleryWorkImage::isManagedStorageImagePath($path) && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
