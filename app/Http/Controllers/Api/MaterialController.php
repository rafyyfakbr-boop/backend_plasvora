<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialListingRequest;
use App\Http\Requests\UpdateMaterialListingRequest;
use App\Models\MaterialListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        // owner_id SELALU dari user yang login, tidak dari client
        $validated['owner_id'] = Auth::id();

        // Normalisasi price jika is_free
        if ($validated['is_free']) {
            $validated['price'] = 0;
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
     */
    public function show(int $id): JsonResponse
    {
        $material = MaterialListing::with('owner:id,name')->find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Material listing not found',
            ], 404);
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

        $material->update($request->validated());
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
