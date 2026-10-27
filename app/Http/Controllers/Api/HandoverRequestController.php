<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHandoverRequestRequest;
use App\Http\Requests\UpdateHandoverStatusRequest;
use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HandoverRequestController extends Controller
{
    /**
     * GET /api/handover-requests
     * Tampilkan handover requests yang melibatkan user yang login.
     * (Sebagai owner ATAU sebagai requester)
     */
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $query = HandoverRequest::with([
            'listing:id,name,category,weight_kg,general_location',
            'owner:id,name',
            'requester:id,name',
        ])->where(function ($q) use ($userId) {
            $q->where('owner_id', $userId)
                ->orWhere('requester_id', $userId);
        });

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $handovers = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Handover requests retrieved successfully',
            'data'    => $handovers->items(),
            'meta'    => [
                'current_page' => $handovers->currentPage(),
                'last_page'    => $handovers->lastPage(),
                'per_page'     => $handovers->perPage(),
                'total'        => $handovers->total(),
            ],
        ]);
    }

    /**
     * POST /api/handover-requests
     * Ajukan permintaan handover untuk sebuah listing.
     */
    public function store(StoreHandoverRequestRequest $request): JsonResponse
    {
        $listingId = $request->input('listing_id');

        // Ambil listing dan pastikan tersedia
        $listing = MaterialListing::find($listingId);

        if (! $listing || $listing->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'Material listing is not available for handover',
            ], 422);
        }

        // Pengguna tidak bisa meminta listing milik sendiri
        if ($listing->owner_id === Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot request handover for your own listing',
            ], 422);
        }

        // Cek apakah sudah ada pending request dari user ini untuk listing ini
        $existingRequest = HandoverRequest::where('listing_id', $listingId)
            ->where('requester_id', Auth::id())
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existingRequest) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending or approved handover request for this listing',
            ], 422);
        }

        $requestedWeight = (float) $request->input('requested_weight_kg');

        if ($requestedWeight > (float) $listing->weight_kg) {
            return response()->json([
                'success' => false,
                'message' => 'Requested weight exceeds available weight (' . $listing->weight_kg . ' kg)',
            ], 422);
        }

        $handover = HandoverRequest::create([
            'listing_id'          => $listingId,
            'owner_id'            => $listing->owner_id,
            'requester_id'        => Auth::id(),
            'requested_weight_kg' => $requestedWeight,
            'note'                => $request->input('note'),
            'status'              => 'pending',
        ]);

        $handover->load([
            'listing:id,name,category',
            'owner:id,name',
            'requester:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Handover request submitted successfully',
            'data'    => $handover,
        ], 201);
    }

    /**
     * GET /api/handover-requests/{id}
     * Ambil detail handover. Hanya yang terlibat (owner/requester) yang bisa lihat.
     */
    public function show(int $id): JsonResponse
    {
        $handover = HandoverRequest::with([
            'listing:id,name,category,weight_kg,general_location',
            'owner:id,name',
            'requester:id,name',
        ])->find($id);

        if (! $handover) {
            return response()->json([
                'success' => false,
                'message' => 'Handover request not found',
            ], 404);
        }

        $userId = Auth::id();

        // Hanya owner atau requester yang boleh melihat detail handover
        if ($handover->owner_id !== $userId && $handover->requester_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view this handover request',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Handover request retrieved successfully',
            'data'    => $handover,
        ]);
    }

    /**
     * PATCH /api/handover-requests/{id}/status
     * Ubah status handover sesuai aturan transisi.
     */
    public function updateStatus(UpdateHandoverStatusRequest $request, int $id): JsonResponse
    {
        $handover = HandoverRequest::with(['listing'])->find($id);

        if (! $handover) {
            return response()->json([
                'success' => false,
                'message' => 'Handover request not found',
            ], 404);
        }

        $userId    = Auth::id();
        $newStatus = $request->input('status');

        // ─── Pastikan user terlibat dalam handover ini ────────────────────────
        $isOwner     = $handover->owner_id === $userId;
        $isRequester = $handover->requester_id === $userId;

        if (! $isOwner && ! $isRequester) {
            return response()->json([
                'success' => false,
                'message' => 'You are not involved in this handover request',
            ], 403);
        }

        // ─── Validasi transisi status di server (State Machine) ───────────────
        if (! $handover->canTransitionTo($newStatus)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$handover->status}' to '{$newStatus}'",
                'allowed_transitions' => HandoverRequest::ALLOWED_TRANSITIONS[$handover->status] ?? [],
            ], 422);
        }

        // ─── Validasi peran aktor untuk transisi yang diizinkan ───────────────
        $authError = $this->checkTransitionAuthorization($handover, $newStatus, $userId);
        if ($authError) {
            return response()->json([
                'success' => false,
                'message' => $authError,
            ], 403);
        }

        // ─── Jalankan perubahan status secara atomik ────────────────────────────
        DB::transaction(function () use ($handover, $newStatus, $request) {
            $updates = ['status' => $newStatus];

            if ($newStatus === 'approved') {
                $updates['approved_at']      = now();
                $updates['agreed_weight_kg'] = $request->input('agreed_weight_kg', $handover->requested_weight_kg);
                // Listing menjadi reserved
                $handover->listing->update(['status' => 'reserved']);
            }

            if ($newStatus === 'handed_over') {
                $updates['handed_over_at']  = now();
                $updates['actual_weight_kg'] = $request->input('actual_weight_kg', $handover->agreed_weight_kg);
            }

            if ($newStatus === 'utilization_confirmed') {
                $updates['utilization_confirmed_at'] = now();
                // Listing menjadi completed
                $handover->listing->update(['status' => 'completed']);
            }

            if ($newStatus === 'rejected' || $newStatus === 'cancelled') {
                // Jika listing masih reserved karena handover ini, kembalikan ke available
                if ($handover->status === 'approved' && $handover->listing->status === 'reserved') {
                    $handover->listing->update(['status' => 'available']);
                }
            }

            if ($request->filled('note')) {
                $updates['note'] = $request->input('note');
            }

            $handover->update($updates);
        });

        $handover->refresh()->load([
            'listing:id,name,category,status',
            'owner:id,name',
            'requester:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Handover status updated to '{$newStatus}' successfully",
            'data'    => $handover,
        ]);
    }

    /**
     * Periksa apakah user yang request boleh melakukan transisi status tersebut.
     * Mengembalikan string error jika tidak boleh, null jika boleh.
     */
    private function checkTransitionAuthorization(HandoverRequest $handover, string $newStatus, int $userId): ?string
    {
        $isOwner     = $handover->owner_id === $userId;
        $isRequester = $handover->requester_id === $userId;

        if (! $isOwner && ! $isRequester) {
            return 'You are not involved in this handover request';
        }

        // approved/rejected hanya oleh pemilik listing
        if (in_array($newStatus, ['approved', 'rejected']) && ! $isOwner) {
            return 'Only the listing owner can approve or reject handover requests';
        }

        // handed_over bisa oleh owner (mencatat penyerahan)
        if ($newStatus === 'handed_over' && ! $isOwner) {
            return 'Only the listing owner can confirm the handover';
        }

        // utilization_confirmed hanya oleh requester (penerima)
        if ($newStatus === 'utilization_confirmed' && ! $isRequester) {
            return 'Only the requester can confirm utilization';
        }

        // cancelled — requester boleh cancel jika masih pending
        if ($newStatus === 'cancelled' && ! $isRequester && ! $isOwner) {
            return 'You are not authorized to cancel this request';
        }

        return null;
    }
}
