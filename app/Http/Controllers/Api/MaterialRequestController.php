<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialRequestRequest;
use App\Http\Requests\UpdateMaterialRequestRequest;
use App\Models\MaterialRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MaterialRequestController extends Controller
{
    /**
     * GET /api/material-requests
     * Ambil daftar kebutuhan material yang aktif.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MaterialRequest::with('requester:id,name')
            ->where('status', 'active');

        if ($request->filled('category')) {
            $query->where('material_category', $request->input('category'));
        }

        if ($request->filled('location')) {
            $query->where('general_location', 'like', '%' . $request->input('location') . '%');
        }

        if ($request->filled('condition')) {
            $query->where('minimum_condition', $request->input('condition'));
        }

        $requests = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Material requests retrieved successfully',
            'data'    => $requests->items(),
            'meta'    => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'per_page'     => $requests->perPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    /**
     * POST /api/material-requests
     * Buat kebutuhan material baru. requester_id diambil dari user terautentikasi.
     */
    public function store(StoreMaterialRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // requester_id SELALU dari user yang login, tidak bisa dipalsukan client
        $validated['requester_id'] = Auth::id();

        $materialRequest = MaterialRequest::create($validated);
        $materialRequest->load('requester:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Material request created successfully',
            'data'    => $materialRequest,
        ], 201);
    }

    /**
     * GET /api/material-requests/{id}
     * Ambil detail satu kebutuhan material.
     */
    public function show(int $id): JsonResponse
    {
        $materialRequest = MaterialRequest::with('requester:id,name')->find($id);

        if (! $materialRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Material request not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Material request retrieved successfully',
            'data'    => $materialRequest,
        ]);
    }

    /**
     * PATCH /api/material-requests/{id}
     * Perbarui kebutuhan material. Hanya pemilik yang boleh mengubah.
     */
    public function update(UpdateMaterialRequestRequest $request, int $id): JsonResponse
    {
        $materialRequest = MaterialRequest::find($id);

        if (! $materialRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Material request not found',
            ], 404);
        }

        // Otorisasi: hanya requester sendiri yang boleh mengubah
        if ($materialRequest->requester_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update this request',
            ], 403);
        }

        // Hanya request yang masih active yang bisa diubah
        if ($materialRequest->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Only active requests can be updated',
            ], 422);
        }

        $materialRequest->update($request->validated());
        $materialRequest->load('requester:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Material request updated successfully',
            'data'    => $materialRequest,
        ]);
    }

    /**
     * DELETE /api/material-requests/{id}
     * Batalkan kebutuhan material (ubah status ke cancelled).
     */
    public function destroy(int $id): JsonResponse
    {
        $materialRequest = MaterialRequest::find($id);

        if (! $materialRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Material request not found',
            ], 404);
        }

        // Otorisasi: hanya requester sendiri yang boleh membatalkan
        if ($materialRequest->requester_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to cancel this request',
            ], 403);
        }

        if ($materialRequest->status === 'fulfilled') {
            return response()->json([
                'success' => false,
                'message' => 'Fulfilled requests cannot be cancelled',
            ], 422);
        }

        $materialRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Material request cancelled successfully',
        ]);
    }
}
