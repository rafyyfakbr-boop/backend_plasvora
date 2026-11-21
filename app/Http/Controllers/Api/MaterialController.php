<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialListingRequest;
use App\Http\Requests\UpdateMaterialListingRequest;
use App\Models\MaterialListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    /**
     * GET /api/materials
     * Ambil daftar listing material yang tersedia dengan filter dan pencarian.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MaterialListing::with('owner:id,name')
            ->where('status', 'available');

        // ─── Filter pencarian ───────────────────────────────────────────────────
        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('general_location', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('condition')) {
            $query->where('condition', $request->input('condition'));
        }

        if ($request->filled('min_weight')) {
            $query->where('weight_kg', '>=', (float) $request->input('min_weight'));
        }

        if ($request->filled('max_weight')) {
            $query->where('weight_kg', '<=', (float) $request->input('max_weight'));
        }

        if ($request->filled('location')) {
            $query->where('general_location', 'like', '%' . $request->input('location') . '%');
        }

        $materials = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Materials retrieved successfully',
            'data'    => $materials->items(),
            'meta'    => [
                'current_page' => $materials->currentPage(),
                'last_page'    => $materials->lastPage(),
                'per_page'     => $materials->perPage(),
                'total'        => $materials->total(),
            ],
        ]);
    }

    /**
     * POST /api/materials
     * Buat listing material baru. Owner diambil dari user yang terautentikasi.
     */
    public function store(StoreMaterialListingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['image']);

        // owner_id SELALU dari user yang login, tidak dari client
        $validated['owner_id'] = Auth::id();

        // Normalisasi price jika is_free
        if ($validated['is_free']) {
            $validated['price'] = 0;
        }

        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $validated['image_url'] = $request->file('image')->store('materials', 'public');
        }

        $material = MaterialListing::create($validated);
        $material->load('owner:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Material listing created successfully',
            'data'    => $material,
        ], 201);
    }

    /**
     * GET /api/materials/{id}
     * Ambil detail satu listing material.
     * Nomor WhatsApp penjual hanya ditampilkan jika penjual mengaktifkan whatsapp_public.
     */
    public function show(int $id): JsonResponse
    {
        $material = MaterialListing::with('owner:id,name,phone,whatsapp_public')->find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Material listing not found',
            ], 404);
        }

        if ($material->owner) {
            $isPublic = (bool) $material->owner->whatsapp_public;
            $material->owner->phone = $isPublic ? $material->owner->phone : null;
            $material->owner->makeHidden('whatsapp_public');
        }

        return response()->json([
            'success' => true,
            'message' => 'Material listing retrieved successfully',
            'data'    => $material,
        ]);
    }

    /**
     * PATCH /api/materials/{id}
     * Perbarui listing. Hanya pemilik listing yang boleh mengubah.
     */
    public function update(UpdateMaterialListingRequest $request, int $id): JsonResponse
    {
        $material = MaterialListing::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Material listing not found',
            ], 404);
        }

        // Otorisasi: hanya pemilik listing yang boleh mengubah
        if ($material->owner_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update this listing',
            ], 403);
        }

        $validated = $request->validated();
        unset($validated['image']);

        if (array_key_exists('is_free', $validated) && $validated['is_free']) {
            $validated['price'] = 0;
        }

        $oldRawImagePath = $material->getRawOriginal('image_url');
        $replacedImage = false;

        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $validated['image_url'] = $request->file('image')->store('materials', 'public');
            $replacedImage = true;
        } elseif (array_key_exists('image_url', $validated) && $validated['image_url'] !== $oldRawImagePath) {
            $replacedImage = true;
        }

        $material->update($validated);

        // Hanya hapus file lama jika benar-benar milik disk public/materials aplikasi
        if (
            $replacedImage
            && MaterialListing::isManagedStorageImagePath($oldRawImagePath)
            && Storage::disk('public')->exists($oldRawImagePath)
        ) {
            Storage::disk('public')->delete($oldRawImagePath);
        }

        $material->load('owner:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Material listing updated successfully',
            'data'    => $material,
        ]);
    }

    /**
     * DELETE /api/materials/{id}
     * Menonaktifkan listing (soft delete by status change, bukan hapus data).
     * Riwayat handover yang terkait tetap dipertahankan.
     */
    public function destroy(int $id): JsonResponse
    {
        $material = MaterialListing::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Material listing not found',
            ], 404);
        }

        // Otorisasi: hanya pemilik listing yang boleh menonaktifkan
        if ($material->owner_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete this listing',
            ], 403);
        }

        // Nonaktifkan listing, bukan hapus — riwayat transaksi tetap ada
        $material->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Material listing deactivated successfully',
        ]);
    }
}
